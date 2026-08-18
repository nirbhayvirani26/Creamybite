<?php
/**
 * Traceability — which batch went to which customer.
 *
 * Two directions, because a recall needs both:
 *
 *   ASSIGN   link the batches that supplied each line of each order. This is
 *            the daily work, and nothing below it is possible without it.
 *   RECALL   given a batch, every customer holding it, with phone and email —
 *            the list the calls are made from, and the one an Environmental
 *            Health Officer asks to see.
 *
 * Retail and trade orders are filtered apart because a recall is handled
 * differently for each: a wholesale customer has to be told to pull stock from
 * their own shelves, a retail customer has to be told not to eat it.
 */

require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_permissions.php';
adminRequire('traceability');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/traceability.php';
require_once __DIR__ . '/../includes/production.php';

$pageTitle = 'Traceability & Recall';
$pageSub   = 'Which batch went to which customer';

$ready = cbTraceReady($pdo);
$view  = (string)($_GET['view'] ?? 'assign');
if (!in_array($view, ['assign', 'recall'], true)) { $view = 'assign'; }

// ── Filters ──────────────────────────────────────────────────
$fType   = (string)($_GET['type']   ?? 'all');    // all | retail | trade
$fState  = (string)($_GET['state']  ?? 'all');    // all | none | partial | traced
$fFrom   = (string)($_GET['from']   ?? '');
$fTo     = (string)($_GET['to']     ?? '');
$fSearch = trim((string)($_GET['q'] ?? ''));
$fBatch  = trim((string)($_GET['batch'] ?? ''));

if (!in_array($fType,  ['all','retail','trade'], true))          { $fType  = 'all'; }
if (!in_array($fState, ['all','none','partial','traced'], true)) { $fState = 'all'; }
if ($fFrom !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fFrom)) { $fFrom = ''; }
if ($fTo   !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fTo))   { $fTo   = ''; }

// ── Orders for the assign view ───────────────────────────────
$orders = [];
if ($ready && $view === 'assign') {
    $where = ["o.status <> 'Cancelled'"];
    $args  = [];

    // trade_user_id is 0 on a retail order — the column exists on every row,
    // so this is a filter rather than a join.
    if ($fType === 'trade')  { $where[] = 'o.trade_user_id > 0'; }
    if ($fType === 'retail') { $where[] = 'o.trade_user_id = 0'; }
    if ($fFrom !== '') { $where[] = 'DATE(o.created_at) >= :from'; $args['from'] = $fFrom; }
    if ($fTo   !== '') { $where[] = 'DATE(o.created_at) <= :to';   $args['to']   = $fTo; }
    if ($fSearch !== '') {
        $where[] = '(o.order_code LIKE :q OR o.customer_name LIKE :q2 OR o.customer_email LIKE :q3 OR o.trade_business_name LIKE :q4)';
        $like = '%' . $fSearch . '%';
        $args['q'] = $args['q2'] = $args['q3'] = $args['q4'] = $like;
    }

    try {
        $st = $pdo->prepare(
            "SELECT o.id, o.order_code, o.customer_name, o.customer_email, o.phone,
                    o.postcode, o.status, o.created_at, o.items_json,
                    o.trade_user_id, o.trade_business_name
               FROM orders o
              WHERE " . implode(' AND ', $where) . "
              ORDER BY o.created_at DESC, o.id DESC
              LIMIT 300"
        );
        $st->execute($args);
        $orders = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('traceability order list failed: ' . $e->getMessage());
        $orders = [];
    }
}

// Decode every order's lines and attach what is already assigned, then work
// out how completely each order is traced so the list can be filtered by it.
$assignments = $ready ? cbTraceAssignmentsFor($pdo, array_column($orders, 'id')) : [];
$rows = [];
$countNone = $countPartial = $countTraced = 0;

foreach ($orders as $o) {
    $lines = cbTraceOrderLines($o);
    if (!$lines) { continue; }

    $mine = $assignments[(int)$o['id']] ?? [];
    $states = [];
    foreach ($lines as $i => $l) {
        $got = $mine[$l['cart_key']] ?? [];
        $st  = cbTraceLineStatus((int)$l['qty'], $got);
        $lines[$i]['assigned'] = $got;
        $lines[$i]['status']   = $st;
        $states[] = $st['state'];
    }

    // The order is only as traced as its worst line.
    $orderState = 'traced';
    if (in_array('none', $states, true) && count(array_unique($states)) === 1) { $orderState = 'none'; }
    elseif (in_array('none', $states, true) || in_array('partial', $states, true)) { $orderState = 'partial'; }
    elseif (in_array('over', $states, true)) { $orderState = 'over'; }

    if     ($orderState === 'none')    { $countNone++; }
    elseif ($orderState === 'partial') { $countPartial++; }
    else                                { $countTraced++; }

    if ($fState !== 'all') {
        if ($fState === 'traced'  && $orderState !== 'traced')  { continue; }
        if ($fState === 'none'    && $orderState !== 'none')    { continue; }
        if ($fState === 'partial' && $orderState !== 'partial') { continue; }
    }

    $rows[] = ['order' => $o, 'lines' => $lines, 'state' => $orderState];
}

// ── Recall view ──────────────────────────────────────────────
$batches   = ($ready && $view === 'recall') ? cbTraceBatchList($pdo) : [];
$recallHits = ($ready && $view === 'recall' && $fBatch !== '') ? cbTraceForward($pdo, $fBatch) : [];
$recallQty  = 0;
$recallTrade = 0;
foreach ($recallHits as $r) {
    $recallQty += (int)$r['qty'];
    if ((int)$r['trade_user_id'] > 0) { $recallTrade++; }
}

$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$qs = function (array $over = []) use ($view, $fType, $fState, $fFrom, $fTo, $fSearch, $fBatch) {
    return 'traceability.php?' . http_build_query(array_merge([
        'view' => $view, 'type' => $fType, 'state' => $fState,
        'from' => $fFrom, 'to' => $fTo, 'q' => $fSearch, 'batch' => $fBatch,
    ], $over));
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $h($pageTitle) ?> – <?= $h(SHOP_NAME) ?> Admin</title>
    <?php require __DIR__ . '/../includes/favicon.php'; ?>
    <link rel="stylesheet" href="<?= cbAsset('../assets/css/style.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?= cbAsset('assets/css/admin.css') ?>">
    <link rel="stylesheet" href="<?= cbAsset('../assets/css/modal.css') ?>">
</head>
<?php include __DIR__ . '/_csrf_js.php'; ?>
<body class="admin-wrapper has-sidebar">

<?php
$cbSidebarCurrent = 'traceability';
require __DIR__ . '/_sidebar.php';
?>

<div class="admin-shell">
<header class="admin-topbar cbat-toggle-only">
    <?php /* Below 980px the sidebar slides off-canvas, and this button is the
             only thing that brings it back. Without it a phone lands on this
             page with no navigation at all — the sidebar is there, translated
             off-screen, with nothing to open it. */ ?>
    <button class="sb-toggle" id="sbToggle" aria-label="Open menu" aria-controls="adminSidebar" aria-expanded="false">
        <i class="fa-solid fa-bars"></i>
    </button>
</header>

<div class="cbtr-wrap">

    <header class="cbtr-head">
        <h1 class="cbtr-title"><i class="fa-solid fa-diagram-project" aria-hidden="true"></i> <?= $h($pageTitle) ?></h1>
        <p class="cbtr-sub"><?= $h($pageSub) ?></p>
    </header>

    <?php if (!$ready): ?>
    <div class="cbi-stock-setup-warning">
        <div class="cbi-stock-warning-body">
            <i class="fa-solid fa-triangle-exclamation cbi-stock-warning-icon"></i>
            <div><strong class="cbi-stock-warning-title">Not set up on this server yet</strong><br>
            Run the database update once — <a href="migrations/update_db.php">admin/migrations/update_db.php</a> — then come back.</div>
        </div>
    </div>
    <?php else: ?>

    <div class="cbtr-tabs">
        <a href="<?= $h($qs(['view' => 'assign'])) ?>" class="cbtr-tab<?= $view === 'assign' ? ' is-on' : '' ?>">
            <i class="fa-solid fa-link" aria-hidden="true"></i> Assign batches
        </a>
        <a href="<?= $h($qs(['view' => 'recall'])) ?>" class="cbtr-tab<?= $view === 'recall' ? ' is-on' : '' ?>">
            <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i> Recall &amp; trace
        </a>
    </div>

    <?php if ($view === 'assign'): ?>
    <!-- ═══════════ ASSIGN BATCHES ═══════════ -->

    <div class="cbtr-stats">
        <div class="cbtr-stat is-bad">
            <div class="cbtr-stat-n"><?= $countNone ?></div><div class="cbtr-stat-l">Not traced</div>
        </div>
        <div class="cbtr-stat is-warn">
            <div class="cbtr-stat-n"><?= $countPartial ?></div><div class="cbtr-stat-l">Part traced</div>
        </div>
        <div class="cbtr-stat is-good">
            <div class="cbtr-stat-n"><?= $countTraced ?></div><div class="cbtr-stat-l">Fully traced</div>
        </div>
    </div>

    <form method="get" action="traceability.php" class="cbtr-filters glass-panel">
        <input type="hidden" name="view" value="assign">
        <div class="cbtr-filter">
            <label class="cbtr-flabel" for="fType">Customer</label>
            <select name="type" id="fType" class="cbtr-input">
                <option value="all"    <?= $fType === 'all'    ? 'selected' : '' ?>>All orders</option>
                <option value="retail" <?= $fType === 'retail' ? 'selected' : '' ?>>Retail only</option>
                <option value="trade"  <?= $fType === 'trade'  ? 'selected' : '' ?>>Trade / wholesale only</option>
            </select>
        </div>
        <div class="cbtr-filter">
            <label class="cbtr-flabel" for="fState">Traced?</label>
            <select name="state" id="fState" class="cbtr-input">
                <option value="all"     <?= $fState === 'all'     ? 'selected' : '' ?>>Any</option>
                <option value="none"    <?= $fState === 'none'    ? 'selected' : '' ?>>Not traced yet</option>
                <option value="partial" <?= $fState === 'partial' ? 'selected' : '' ?>>Part traced</option>
                <option value="traced"  <?= $fState === 'traced'  ? 'selected' : '' ?>>Fully traced</option>
            </select>
        </div>
        <div class="cbtr-filter">
            <label class="cbtr-flabel" for="fFrom">From</label>
            <input type="date" name="from" id="fFrom" class="cbtr-input" value="<?= $h($fFrom) ?>">
        </div>
        <div class="cbtr-filter">
            <label class="cbtr-flabel" for="fTo">To</label>
            <input type="date" name="to" id="fTo" class="cbtr-input" value="<?= $h($fTo) ?>">
        </div>
        <div class="cbtr-filter cbtr-grow">
            <label class="cbtr-flabel" for="fQ">Search</label>
            <input type="text" name="q" id="fQ" class="cbtr-input" value="<?= $h($fSearch) ?>"
                   placeholder="order code, customer name, email or business">
        </div>
        <div class="cbtr-filter">
            <button class="btn-primary"><i class="fa-solid fa-filter" aria-hidden="true"></i> Apply</button>
        </div>
        <?php if ($fType !== 'all' || $fState !== 'all' || $fFrom || $fTo || $fSearch !== ''): ?>
        <div class="cbtr-filter">
            <a href="traceability.php?view=assign" class="btn-sm btn-sm-outline">Clear</a>
        </div>
        <?php endif; ?>
    </form>

    <?php if (!$rows): ?>
    <div class="cbi-empty-state">
        <div class="cbi-empty-icon"><i class="fa-solid fa-diagram-project" aria-hidden="true"></i></div>
        <p class="cbi-empty-text">No orders match those filters.</p>
    </div>
    <?php else: ?>

    <div class="cbtr-orders">
    <?php foreach ($rows as $row):
        $o = $row['order'];
        $isTrade = (int)$o['trade_user_id'] > 0;
    ?>
        <article class="cbtr-order glass-panel is-<?= $h($row['state']) ?>">
            <header class="cbtr-order-head">
                <div>
                    <span class="cbtr-ordercode"><?= $h($o['order_code'] ?: ('#' . $o['id'])) ?></span>
                    <span class="cbtr-chan <?= $isTrade ? 'is-trade' : 'is-retail' ?>">
                        <?= $isTrade ? 'Trade' : 'Retail' ?>
                    </span>
                    <span class="cbtr-state-pill is-<?= $h($row['state']) ?>">
                        <?php
                        echo $row['state'] === 'traced' ? 'Fully traced'
                           : ($row['state'] === 'partial' ? 'Part traced'
                           : ($row['state'] === 'over' ? 'Over-assigned' : 'Not traced'));
                        ?>
                    </span>
                </div>
                <div class="cbtr-order-when"><?= $h(date('j M Y, H:i', strtotime((string)$o['created_at']))) ?></div>
            </header>

            <div class="cbtr-cust">
                <strong><?= $h($isTrade && $o['trade_business_name'] ? $o['trade_business_name'] : $o['customer_name']) ?></strong>
                <?php if ($o['phone']): ?><span><i class="fa-solid fa-phone" aria-hidden="true"></i> <?= $h($o['phone']) ?></span><?php endif; ?>
                <?php if ($o['customer_email']): ?><span><i class="fa-solid fa-envelope" aria-hidden="true"></i> <?= $h($o['customer_email']) ?></span><?php endif; ?>
                <?php if ($o['postcode']): ?><span><i class="fa-solid fa-location-dot" aria-hidden="true"></i> <?= $h($o['postcode']) ?></span><?php endif; ?>
            </div>

            <table class="cbtr-lines">
                <thead><tr>
                    <th>Item</th><th class="cbtr-c">Sold</th><th class="cbtr-c">Traced</th>
                    <th>Batches</th><th class="cbtr-c">Add</th>
                </tr></thead>
                <tbody>
                <?php foreach ($row['lines'] as $l): $st = $l['status']; ?>
                    <tr class="cbtr-line is-<?= $h($st['state']) ?>">
                        <td>
                            <div class="cbtr-item"><?= $h($l['product_name']) ?></div>
                            <?php if ($l['variant_name']): ?>
                            <div class="cbtr-size"><?= $h($l['variant_name']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="cbtr-c"><strong><?= (int)$l['qty'] ?></strong></td>
                        <td class="cbtr-c">
                            <span class="cbtr-count is-<?= $h($st['state']) ?>"><?= (int)$st['assigned'] ?></span>
                            <?php if ($st['short'] > 0): ?>
                            <div class="cbtr-short"><?= (int)$st['short'] ?> missing</div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if (!$l['assigned']): ?>
                            <span class="cbtr-nobatch">—</span>
                            <?php else: foreach ($l['assigned'] as $a): ?>
                            <span class="cbtr-batchchip">
                                <strong><?= $h($a['external_batch'] ?: $a['batch_code']) ?></strong>
                                <span class="cbtr-chipqty">×<?= (int)$a['qty'] ?></span>
                                <button type="button" class="cbtr-chipx" data-unassign="<?= (int)$a['id'] ?>"
                                        title="Remove this link" aria-label="Remove batch link">&times;</button>
                            </span>
                            <?php endforeach; endif; ?>
                        </td>
                        <td class="cbtr-c">
                            <button type="button" class="btn-sm btn-primary cbtr-assign"
                                    data-order="<?= (int)$o['id'] ?>"
                                    data-key="<?= $h($l['cart_key']) ?>"
                                    data-product="<?= (int)$l['product_id'] ?>"
                                    data-variant="<?= (int)$l['variant_id'] ?>"
                                    data-label="<?= $h($l['product_name'] . ($l['variant_name'] ? ' — ' . $l['variant_name'] : '')) ?>"
                                    data-short="<?= (int)($st['short'] > 0 ? $st['short'] : $l['qty']) ?>">
                                <i class="fa-solid fa-plus" aria-hidden="true"></i> Batch
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </article>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <?php if ($view === 'recall'): ?>
    <!-- ═══════════ RECALL & TRACE ═══════════ -->

    <div class="cbtr-note">
        <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
        <span><strong>Pick a batch to see everyone who received it.</strong>
        This is the list to work through if a batch has to be withdrawn or recalled —
        it carries the phone number and email for each customer, and separates trade
        from retail, because a wholesaler has to pull stock from their own shelves and
        a retail customer has to be told not to eat it. Print it for your records:
        Article 19 of assimilated Regulation (EC) No 178/2002 expects you to show who
        you contacted and when.</span>
    </div>

    <form method="get" action="traceability.php" class="cbtr-filters glass-panel">
        <input type="hidden" name="view" value="recall">
        <div class="cbtr-filter cbtr-grow">
            <label class="cbtr-flabel" for="fBatch">Batch number</label>
            <input type="text" name="batch" id="fBatch" class="cbtr-input cbtr-mono"
                   list="cbtrBatchList" value="<?= $h($fBatch) ?>"
                   placeholder="type or pick — e.g. AD26081801 or PR-260818-01">
            <datalist id="cbtrBatchList">
                <?php foreach ($batches as $b): ?>
                <?php if ($b['external_batch']): ?>
                <option value="<?= $h($b['external_batch']) ?>"><?= $h($b['product_name'] . ' · ' . date('j M Y', strtotime((string)$b['produced_on']))) ?></option>
                <?php endif; ?>
                <option value="<?= $h($b['batch_code']) ?>"><?= $h($b['product_name'] . ' · ' . date('j M Y', strtotime((string)$b['produced_on']))) ?></option>
                <?php endforeach; ?>
            </datalist>
        </div>
        <div class="cbtr-filter">
            <button class="btn-primary"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i> Trace it</button>
        </div>
    </form>

    <?php if ($fBatch !== ''): ?>
        <?php if (!$recallHits): ?>
        <div class="cbi-empty-state">
            <div class="cbi-empty-icon"><i class="fa-solid fa-circle-check" aria-hidden="true"></i></div>
            <p class="cbi-empty-text">
                Nothing from batch <strong><?= $h($fBatch) ?></strong> has been linked to any order.<br>
                <span class="cbtr-muted">That means either none of it was sold, or the orders it went out on
                have not had their batches assigned yet. Check the Assign tab before concluding it was never shipped.</span>
            </p>
        </div>
        <?php else: ?>

        <div class="cbtr-recall-head glass-panel">
            <div>
                <div class="cbtr-recall-batch"><?= $h($fBatch) ?></div>
                <div class="cbtr-muted"><?= $h($recallHits[0]['product_name']) ?><?php
                    if ($recallHits[0]['variant_name']): ?> · <?= $h($recallHits[0]['variant_name']) ?><?php endif; ?>
                    <?php if ($recallHits[0]['produced_on']): ?>
                    · made <?= $h(date('j M Y', strtotime((string)$recallHits[0]['produced_on']))) ?><?php endif; ?>
                    <?php if ($recallHits[0]['best_before']): ?>
                    · best before <?= $h(date('j M Y', strtotime((string)$recallHits[0]['best_before']))) ?><?php endif; ?>
                </div>
            </div>
            <div class="cbtr-recall-figs">
                <div><strong><?= count($recallHits) ?></strong><span>orders affected</span></div>
                <div><strong><?= $recallQty ?></strong><span>units in circulation</span></div>
                <div><strong><?= $recallTrade ?></strong><span>trade customers</span></div>
            </div>
            <div>
                <button type="button" class="btn-primary" onclick="window.print()">
                    <i class="fa-solid fa-print" aria-hidden="true"></i> Print
                </button>
                <a class="btn-sm btn-sm-outline" href="traceability_export.php?batch=<?= urlencode($fBatch) ?>">
                    <i class="fa-solid fa-file-csv" aria-hidden="true"></i> CSV
                </a>
            </div>
        </div>

        <div class="table-wrapper">
            <table class="data-table cbtr-recall-table">
                <thead><tr>
                    <th>Order</th><th>Type</th><th>Customer</th><th>Phone</th><th>Email</th>
                    <th>Postcode</th><th class="cbtr-c">Units</th><th>Ordered</th><th>Status</th>
                    <th class="cbtr-c">Contacted</th>
                </tr></thead>
                <tbody>
                <?php foreach ($recallHits as $r): $t = (int)$r['trade_user_id'] > 0; ?>
                    <tr>
                        <td class="cbtr-mono"><?= $h($r['order_code'] ?: ('#' . $r['order_id'])) ?></td>
                        <td><span class="cbtr-chan <?= $t ? 'is-trade' : 'is-retail' ?>"><?= $t ? 'Trade' : 'Retail' ?></span></td>
                        <td><?= $h($t && $r['trade_business_name'] ? $r['trade_business_name'] : $r['customer_name']) ?></td>
                        <td class="cbtr-mono"><?= $h($r['phone']) ?></td>
                        <td class="cbtr-small"><?= $h($r['customer_email']) ?></td>
                        <td class="cbtr-mono"><?= $h($r['postcode']) ?></td>
                        <td class="cbtr-c"><strong><?= (int)$r['qty'] ?></strong></td>
                        <td class="cbtr-small"><?= $h(date('j M Y', strtotime((string)$r['created_at']))) ?></td>
                        <td class="cbtr-small"><?= $h($r['status']) ?></td>
                        <td class="cbtr-c cbtr-tick">☐</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="cbtr-signoff">
            <p class="cbtr-small"><strong>Recall record.</strong> Tick each customer as they are contacted, then sign and
            date below and file this with your food safety records.</p>
            <div class="cbtr-siglines">
                <div>Contacted by (name)</div><div>Signature</div><div>Date &amp; time completed</div>
            </div>
        </div>
        <?php endif; ?>
    <?php endif; ?>

    <h2 class="cbtr-h2">Every batch, and how much of it went out</h2>
    <div class="table-wrapper">
        <table class="data-table">
            <thead><tr>
                <th>Batch (on tub)</th><th>Internal</th><th>Product</th><th>Made</th>
                <th>Best before</th><th class="cbtr-c">Made</th><th class="cbtr-c">Shipped</th>
                <th class="cbtr-c">Orders</th><th></th>
            </tr></thead>
            <tbody>
            <?php if (!$batches): ?>
                <tr><td colspan="9" class="cbtr-muted">No production runs recorded yet.</td></tr>
            <?php else: foreach ($batches as $b): ?>
                <tr>
                    <td class="cbtr-mono"><strong><?= $h($b['external_batch'] ?: '—') ?></strong></td>
                    <td class="cbtr-mono cbtr-small"><?= $h($b['batch_code']) ?></td>
                    <td><?= $h($b['product_name']) ?><?php if ($b['variant_name']): ?>
                        <span class="cbtr-size"><?= $h($b['variant_name']) ?></span><?php endif; ?></td>
                    <td class="cbtr-small"><?= $h(date('j M Y', strtotime((string)$b['produced_on']))) ?></td>
                    <td class="cbtr-small"><?= $b['best_before'] ? $h(date('j M Y', strtotime((string)$b['best_before']))) : '—' ?></td>
                    <td class="cbtr-c"><?= (int)$b['output_qty'] ?></td>
                    <td class="cbtr-c"><strong><?= (int)$b['shipped'] ?></strong></td>
                    <td class="cbtr-c"><?= (int)$b['orders'] ?></td>
                    <td><a class="btn-sm btn-sm-outline"
                           href="<?= $h($qs(['view' => 'recall', 'batch' => $b['external_batch'] ?: $b['batch_code']])) ?>">Trace</a></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php endif; /* ready */ ?>
</div>
</div><!-- /admin-shell -->

<!-- Assign a batch to one line -->
<div id="cbtrModal" class="cbtr-modal" hidden>
    <div class="cbtr-modal-box">
        <button type="button" class="cbtr-modal-x" id="cbtrClose" aria-label="Close">&times;</button>
        <h3 class="cbtr-modal-title">Which batch supplied this?</h3>
        <p class="cbtr-modal-sub" id="cbtrItem"></p>

        <div class="cbtr-field">
            <label class="cbtr-flabel" for="cbtrRun">Batch</label>
            <select id="cbtrRun" class="cbtr-input"><option value="">Loading…</option></select>
        </div>
        <div class="cbtr-field">
            <label class="cbtr-flabel" for="cbtrQty">How many tubs from this batch</label>
            <input type="number" id="cbtrQty" class="cbtr-input" min="1" value="1">
            <small class="cbtr-hint" id="cbtrHint"></small>
        </div>
        <div class="cbtr-field">
            <label class="cbtr-flabel" for="cbtrNotes">Note (optional)</label>
            <input type="text" id="cbtrNotes" class="cbtr-input" maxlength="255" placeholder="e.g. split delivery, second pallet">
        </div>

        <div class="cbtr-modal-msg" id="cbtrMsg"></div>
        <div class="cbtr-modal-actions">
            <button type="button" class="btn-primary" id="cbtrSave"><i class="fa-solid fa-check"></i> Link batch</button>
            <button type="button" class="btn-secondary" id="cbtrCancel">Cancel</button>
        </div>
    </div>
</div>

<script src="<?= cbAsset('../assets/js/modal.js') ?>"></script>
<script src="<?= cbAsset('assets/js/traceability.js') ?>"></script>
</body>
</html>
