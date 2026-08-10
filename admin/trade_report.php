<?php
// ============================================================
//  Creamy Bite – Admin: Trade customer report
//  URL: /admin/trade_report.php?id=7
//
//  Everything you would want before ringing a wholesale partner: what they
//  are worth, what they actually buy, how often they order, and whether they
//  owe anything. Read-only — nothing here changes the account.
// ============================================================
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_permissions.php';
adminRequire('trade');
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

$tradeId = (int)($_GET['id'] ?? 0);
if ($tradeId <= 0) {
    header('Location: index.php?tab=trade');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM trade_users WHERE id = :id");
$stmt->execute(['id' => $tradeId]);
$account = $stmt->fetch();

if (!$account) {
    header('Location: index.php?tab=trade');
    exit;
}

// ── Orders ───────────────────────────────────────────────────
// Scoped by trade_user_id alone. Matching on phone or email would fold in
// other customers' orders whenever a detail happens to coincide.
$orders = [];
try {
    $o = $pdo->prepare("SELECT * FROM orders WHERE trade_user_id = :id ORDER BY created_at ASC");
    $o->execute(['id' => $tradeId]);
    $orders = $o->fetchAll();
} catch (PDOException $e) {
    error_log('Trade report orders failed: ' . $e->getMessage());
}

$orderCount  = count($orders);
$totalValue  = 0.0;   // everything they have ever ordered
$totalPaid   = 0.0;
$outstanding = 0.0;
$firstOrder  = null;
$lastOrder   = null;

foreach ($orders as $ord) {
    $amount      = (float)$ord['total_price'];
    $totalValue += $amount;
    if (($ord['payment_status'] ?? 'Unpaid') === 'Unpaid') {
        $outstanding += $amount;
    } else {
        $totalPaid += $amount;
    }
    $ts = strtotime($ord['created_at']);
    if ($firstOrder === null || $ts < $firstOrder) { $firstOrder = $ts; }
    if ($lastOrder  === null || $ts > $lastOrder)  { $lastOrder  = $ts; }
}

$avgOrder = $orderCount > 0 ? $totalValue / $orderCount : 0.0;

// ── Order frequency ──────────────────────────────────────────
// Measured across the span between the first and last order, not since the
// account was created: an account opened months before its first order would
// otherwise look far less active than it is.
//
// A span shorter than a week is not a rate. Three orders placed on one
// afternoon would read as "3 orders per month, 0.5 days between orders",
// which is a number with no meaning presented as if it were a fact — so
// below that threshold the figures are withheld rather than invented.
const CBTR_MIN_SPAN_DAYS = 7;

$daysBetween    = null;
$ordersPerMonth = null;
$spanDays       = null;
if ($orderCount > 1 && $firstOrder && $lastOrder && $lastOrder > $firstOrder) {
    $spanDays = max(1, (int)round(($lastOrder - $firstOrder) / 86400));
    if ($spanDays >= CBTR_MIN_SPAN_DAYS) {
        $daysBetween    = round($spanDays / max(1, $orderCount - 1), 1);
        $ordersPerMonth = round($orderCount / ($spanDays / 30.44), 1);
    }
}

$daysSinceLast = $lastOrder ? (int)floor((time() - $lastOrder) / 86400) : null;

/** "1 day" / "3 days" — plural agreement, so the page never reads "1 days". */
function cbtrDays(int $n): string
{
    return $n . ' day' . ($n === 1 ? '' : 's');
}

// ── Top products, all time ───────────────────────────────────
// Items live in orders.items_json, so this is totalled in PHP rather than
// with GROUP BY. Sizes are kept separate — "500ml" and "1L" of the same
// flavour are different things to restock.
$productTotals = [];
foreach ($orders as $ord) {
    $items = json_decode($ord['items_json'] ?? '[]', true) ?: [];
    foreach ($items as $item) {
        $name = trim((string)($item['name'] ?? ''));
        if ($name === '') { continue; }
        if (!empty($item['variant_name'])) {
            $name .= ' — ' . trim((string)$item['variant_name']);
        }
        $qty  = (int)($item['quantity'] ?? 0);
        $line = (float)($item['price'] ?? 0) * $qty;

        if (!isset($productTotals[$name])) {
            $productTotals[$name] = ['name' => $name, 'qty' => 0, 'value' => 0.0, 'orders' => 0];
        }
        $productTotals[$name]['qty']    += $qty;
        $productTotals[$name]['value']  += $line;
        $productTotals[$name]['orders'] += 1;
    }
}
uasort($productTotals, fn($a, $b) => $b['qty'] <=> $a['qty']);
$topProducts = array_slice(array_values($productTotals), 0, 15);
$topQty      = $topProducts ? max(array_column($topProducts, 'qty')) : 0;
// Share is of EVERYTHING they have bought, not just the rows shown, so the
// percentages describe the whole basket rather than the top fifteen.
$total       = array_sum(array_column($productTotals, 'qty'));

// ── Invoices raised against this partner ─────────────────────
$invoices     = [];
$invoiceTotal = 0.0;
$invoiceDue   = 0.0;
try {
    $iv = $pdo->prepare(
        "SELECT id, invoice_number, issue_date, status, total, amount_paid
           FROM invoices WHERE trade_user_id = :id AND status <> 'void'
          ORDER BY issue_date DESC, id DESC"
    );
    $iv->execute(['id' => $tradeId]);
    $invoices = $iv->fetchAll();
    foreach ($invoices as $row) {
        $invoiceTotal += (float)$row['total'];
        $invoiceDue   += (float)$row['total'] - (float)$row['amount_paid'];
    }
} catch (PDOException $e) {
    error_log('Trade report invoices failed: ' . $e->getMessage());
}

/** Human-facing trade customer number, matching the partner's own account page. */
$customerNo = 'TC-' . str_pad((string)$tradeId, 5, '0', STR_PAD_LEFT);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($account['business_name']) ?> – Trade Report</title>
<?php require __DIR__ . '/../includes/favicon.php'; ?>
<link rel="stylesheet" href="<?= cbAsset('../assets/css/style.css') ?>">
<link rel="stylesheet" href="<?= cbAsset('../assets/css/responsive.css') ?>">
<link rel="stylesheet" href="<?= cbAsset('assets/css/admin.css') ?>">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="admin-wrapper has-sidebar cbtr-page">

<?php
// Same sidebar as every other admin page.
$cbSidebarCurrent = 'trade';
require __DIR__ . '/_sidebar.php';
?>

<div class="admin-shell">
<div class="container cbtr-shell">

    <div class="cbtr-head no-print">
        <div>
            <a href="index.php?tab=trade" class="cbtr-back-link">
                <i class="fa-solid fa-arrow-left"></i> All trade accounts
            </a>
            <h1 class="cbtr-title">
                <span><i class="fa-solid fa-shop" aria-hidden="true"></i></span> <?= htmlspecialchars($account['business_name']) ?>
            </h1>
            <div class="cbtr-subtitle">
                <?= htmlspecialchars($customerNo) ?>
                &middot; <?= htmlspecialchars($account['contact_name']) ?>
                &middot; <?= htmlspecialchars($account['phone']) ?>
                <?php if (!empty($account['vat_number'])): ?>
                &middot; VAT <?= htmlspecialchars($account['vat_number']) ?>
                <?php endif; ?>
            </div>
        </div>
        <button type="button" onclick="window.print()" class="btn-secondary cbtr-print-btn">
            <i class="fa-solid fa-print"></i> Print
        </button>
    </div>

    <?php if ($orderCount === 0): ?>
    <div class="cbtr-empty">
        <div class="cbtr-empty-icon"><i class="fa-solid fa-inbox" aria-hidden="true"></i></div>
        <h2 class="cbtr-empty-title">No orders yet</h2>
        <p class="cbtr-empty-text">
            This account was approved but has not placed an order, so there is
            nothing to report on yet.
        </p>
    </div>
    <?php else: ?>

    <!-- ── Headline numbers ─────────────────────────── -->
    <div class="cbtr-stat-grid">
        <div class="cbtr-stat">
            <div class="cbtr-stat-label">Lifetime value</div>
            <div class="cbtr-stat-value">£<?= number_format($totalValue, 2) ?></div>
            <div class="cbtr-stat-note"><?= $orderCount ?> order<?= $orderCount === 1 ? '' : 's' ?></div>
        </div>
        <div class="cbtr-stat">
            <div class="cbtr-stat-label">Received</div>
            <div class="cbtr-stat-value cbtr-pos">£<?= number_format($totalPaid, 2) ?></div>
            <div class="cbtr-stat-note">paid orders</div>
        </div>
        <div class="cbtr-stat<?= $outstanding > 0.001 ? ' is-owing' : '' ?>">
            <div class="cbtr-stat-label">Outstanding</div>
            <div class="cbtr-stat-value"><?= $outstanding > 0.001 ? '£' . number_format($outstanding, 2) : '£0.00' ?></div>
            <div class="cbtr-stat-note"><?= $outstanding > 0.001 ? 'unpaid orders' : 'all settled' ?></div>
        </div>
        <div class="cbtr-stat">
            <div class="cbtr-stat-label">Average order</div>
            <div class="cbtr-stat-value">£<?= number_format($avgOrder, 2) ?></div>
            <div class="cbtr-stat-note">per order</div>
        </div>
    </div>

    <!-- ── Ordering pattern ─────────────────────────── -->
    <div class="cbtr-card">
        <h2 class="cbtr-card-title">Order frequency</h2>
        <div class="cbtr-freq-grid">
            <div>
                <div class="cbtr-freq-value<?= $ordersPerMonth === null ? ' cbtr-freq-none' : '' ?>">
                    <?= $ordersPerMonth !== null ? htmlspecialchars((string)$ordersPerMonth) : '—' ?>
                </div>
                <div class="cbtr-freq-label">orders per month</div>
            </div>
            <div>
                <div class="cbtr-freq-value<?= $daysBetween === null ? ' cbtr-freq-none' : '' ?>">
                    <?= $daysBetween !== null ? htmlspecialchars((string)$daysBetween) : '—' ?>
                </div>
                <div class="cbtr-freq-label">days between orders</div>
            </div>
            <div>
                <div class="cbtr-freq-value"><?= $firstOrder ? date('d M Y', $firstOrder) : '—' ?></div>
                <div class="cbtr-freq-label">first order</div>
            </div>
            <div>
                <div class="cbtr-freq-value<?= ($daysSinceLast !== null && $daysSinceLast > 60) ? ' cbtr-stale' : '' ?>">
                    <?= $lastOrder ? date('d M Y', $lastOrder) : '—' ?>
                </div>
                <div class="cbtr-freq-label">
                    last order<?= $daysSinceLast !== null ? ' (' . ($daysSinceLast === 0 ? 'today' : cbtrDays($daysSinceLast) . ' ago') . ')' : '' ?>
                </div>
            </div>
        </div>
        <?php if ($ordersPerMonth === null): ?>
        <p class="cbtr-note">
            <?php if ($orderCount < 2): ?>
                A rate needs at least two orders to measure between.
            <?php else: ?>
                All <?= $orderCount ?> orders fall within <?= cbtrDays((int)$spanDays) ?>,
                which is too short a span to read a rate from — it will appear
                once this account has been ordering for a week or more.
            <?php endif; ?>
        </p>
        <?php elseif ($daysSinceLast !== null && $daysSinceLast > 60): ?>
        <p class="cbtr-note cbtr-warn">
            No order in <?= $daysSinceLast ?> days, against a usual gap of about
            <?= $daysBetween ?> days — worth a call.
        </p>
        <?php endif; ?>
    </div>

    <!-- ── What they actually buy ───────────────────── -->
    <div class="cbtr-card">
        <h2 class="cbtr-card-title">Top products of all time</h2>
        <?php if (empty($topProducts)): ?>
        <p class="cbtr-note">No line items recorded against these orders.</p>
        <?php else: ?>
        <table class="cbtr-table">
            <thead>
                <tr>
                    <th class="cbtr-col-rank">#</th>
                    <th>Product</th>
                    <th class="cbtr-col-num">Units</th>
                    <th class="cbtr-col-share">Share</th>
                    <th class="cbtr-col-num">Value</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($topProducts as $i => $p): ?>
                <tr>
                    <td class="cbtr-rank"><?= $i + 1 ?></td>
                    <td class="cbtr-product-name"><?= htmlspecialchars($p['name']) ?></td>
                    <td class="cbtr-col-num"><strong><?= (int)$p['qty'] ?></strong></td>
                    <td class="cbtr-col-share">
                        <!-- Bar width is data, so it rides on data-pct and is
                             applied by the script at the foot of the page.
                             The figure is stated as well as drawn: a bar shows
                             the shape, the number is what you write down. -->
                        <div class="cbtr-bar-track">
                            <div class="cbtr-bar-fill" data-pct="<?= $topQty > 0 ? round($p['qty'] / $topQty * 100, 1) : 0 ?>"></div>
                        </div>
                        <span class="cbtr-share-pct"><?= $total > 0 ? number_format($p['qty'] / $total * 100, 0) : '0' ?>%</span>
                    </td>
                    <td class="cbtr-col-num">£<?= number_format($p['value'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

    <!-- ── Invoices ─────────────────────────────────── -->
    <?php if (!empty($invoices)): ?>
    <div class="cbtr-card">
        <h2 class="cbtr-card-title">
            Invoices
            <span class="cbtr-card-sub">
                £<?= number_format($invoiceTotal, 2) ?> raised,
                £<?= number_format($invoiceDue, 2) ?> still due
            </span>
        </h2>
        <table class="cbtr-table">
            <thead>
                <tr>
                    <th>Invoice</th>
                    <th>Date</th>
                    <th>Status</th>
                    <th class="cbtr-col-num">Total</th>
                    <th class="cbtr-col-num">Balance</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($invoices as $row):
                    $bal = (float)$row['total'] - (float)$row['amount_paid'];
                ?>
                <tr>
                    <td>
                        <a href="invoice_edit.php?id=<?= (int)$row['id'] ?>" class="cbtr-link">
                            <?= htmlspecialchars($row['invoice_number']) ?>
                        </a>
                    </td>
                    <td><?= date('d M Y', strtotime($row['issue_date'])) ?></td>
                    <td><?= htmlspecialchars(ucwords(str_replace('_', ' ', $row['status']))) ?></td>
                    <td class="cbtr-col-num">£<?= number_format((float)$row['total'], 2) ?></td>
                    <td class="cbtr-col-num<?= $bal > 0.001 ? ' cbtr-due' : ' cbtr-pos' ?>">
                        £<?= number_format($bal, 2) ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <!-- ── Every order ──────────────────────────────── -->
    <div class="cbtr-card">
        <h2 class="cbtr-card-title">Order history</h2>
        <table class="cbtr-table">
            <thead>
                <tr>
                    <th>Order</th>
                    <th>Date</th>
                    <th>Items</th>
                    <th>Payment</th>
                    <th class="cbtr-col-num">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (array_reverse($orders) as $ord):
                    $items    = json_decode($ord['items_json'] ?? '[]', true) ?: [];
                    $itemQty  = array_sum(array_map(fn($i) => (int)($i['quantity'] ?? 0), $items));
                    $payState = $ord['payment_status'] ?? 'Unpaid';
                ?>
                <tr>
                    <td class="cbtr-order-code"><?= htmlspecialchars($ord['order_code']) ?></td>
                    <td><?= date('d M Y', strtotime($ord['created_at'])) ?></td>
                    <td><?= $itemQty ?> item<?= $itemQty === 1 ? '' : 's' ?></td>
                    <td>
                        <span class="cbtr-pay <?= $payState === 'Unpaid' ? 'is-unpaid' : 'is-paid' ?>">
                            <?= htmlspecialchars($payState) ?>
                        </span>
                    </td>
                    <td class="cbtr-col-num">£<?= number_format((float)$ord['total_price'], 2) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php endif; ?>
</div>

<script>
// Share bars: the percentage is data, so it arrives on data-pct rather than
// being written into the markup as a style.
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.cbtr-bar-fill[data-pct]').forEach(function (el) {
        var pct = parseFloat(el.getAttribute('data-pct'));
        el.style.width = (isNaN(pct) ? 0 : Math.max(0, Math.min(100, pct))) + '%';
    });
});
</script>
</div><!-- /admin-shell -->
</body>
</html>
