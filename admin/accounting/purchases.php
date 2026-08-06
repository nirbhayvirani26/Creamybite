<?php
// ============================================================
//  Creamy Bite – Purchases (supplier bills)
//
//  Mirrors expenses.php exactly — same VAT-computed-server-side rule, same
//  receipt upload, same balanced journal entry in one transaction — because
//  acctPurchaseTotals() already treats purchase_invoices and expenses as
//  equivalent for the VAT return, so the two entry points should behave
//  equivalently too.
//
//  Two things expenses.php does not need: a supplier (found or created by
//  name — no separate supplier-management screen, that is out of scope here)
//  and a paid-now/owing choice, since a bill can genuinely be entered before
//  it is paid. There is no "mark as paid later" action yet — a bill entered
//  as owing stays owing until the whole record is a superseding entry; that
//  is the next piece of work, same honesty the old placeholder page had.
// ============================================================
require_once __DIR__ . '/_layout.php';

$msg = ''; $err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    csrfCheck();

    $date      = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['invoice_date'] ?? '') ? $_POST['invoice_date'] : '';
    $dueDate   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['due_date'] ?? '') ? $_POST['due_date'] : null;
    $supplier  = trim($_POST['supplier_name'] ?? '');
    $invNumber = trim($_POST['invoice_number'] ?? '');
    $category  = trim($_POST['category'] ?? 'Miscellaneous');
    $net       = round((float)($_POST['net'] ?? 0), 2);
    $rateId    = (int)($_POST['vat_rate_id'] ?? 0);
    $recover   = isset($_POST['recoverable']) ? 1 : 0;
    $paidNow   = isset($_POST['paid_now']);
    $method    = trim($_POST['payment_method'] ?? 'Bank Transfer');
    $ref       = trim($_POST['reference'] ?? '');
    $notes     = trim($_POST['notes'] ?? '');

    // The VAT is computed from the chosen rate, never taken from the form —
    // same reason as expenses.php: the one figure that ends up in Box 4 must
    // not be a number the user can set to anything.
    $rate = 0.0;
    foreach (acctRates($pdo, false) as $r) {
        if ((int)$r['id'] === $rateId) { $rate = (float)$r['rate']; break; }
    }
    $vat = acctVatOnNet($net, $rate);

    if ($date === '')                    { $err = 'Pick the date on the bill.'; }
    elseif ($supplier === '')            { $err = 'Enter the supplier name.'; }
    elseif ($net <= 0)                   { $err = 'Enter the amount before VAT.'; }
    elseif (!in_array($category, acctExpenseCategoryList(), true)) { $err = 'Pick a category.'; }

    // Receipt upload — identical rule to expenses.php: the extension comes
    // from the verified mime type, never the filename.
    $receipt = '';
    if (!$err && !empty($_FILES['receipt']['name'])) {
        $f = $_FILES['receipt'];
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $f['tmp_name']);
        finfo_close($finfo);

        if (!isset($allowed[$mime]))        { $err = 'Bills must be a JPG, PNG, WebP or PDF.'; }
        elseif ($f['size'] > 8*1024*1024)   { $err = 'That file is over 8MB.'; }
        else {
            $dir = dirname(__DIR__, 2) . '/assets/receipts/';
            if (!is_dir($dir)) { mkdir($dir, 0755, true); }
            $receipt = 'purchase_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
            if (!move_uploaded_file($f['tmp_name'], $dir . $receipt)) {
                $err = 'Could not save that file.';
                $receipt = '';
            }
        }
    }

    if (!$err) {
        try {
            $pdo->beginTransaction();

            // Find or create the supplier by name — same shape as
            // add_delivery_rep in admin/handlers/update_order.php: try the
            // insert, and a duplicate name just means reuse the existing row.
            $supplierId = 0;
            try {
                $ins = $pdo->prepare("INSERT INTO suppliers (name) VALUES (:n)");
                $ins->execute(['n' => mb_substr($supplier, 0, 180)]);
                $supplierId = (int)$pdo->lastInsertId();
            } catch (PDOException $e) {
                if (str_contains($e->getMessage(), 'Duplicate')) {
                    $find = $pdo->prepare("SELECT id FROM suppliers WHERE name = :n");
                    $find->execute(['n' => $supplier]);
                    $supplierId = (int)$find->fetchColumn();
                } else {
                    throw $e;
                }
            }

            $gross      = round($net + $vat, 2);
            $paidAmount = $paidNow ? $gross : 0.00;
            $paidOn     = $paidNow ? $date : null;

            $st = $pdo->prepare(
                "INSERT INTO purchase_invoices
                    (supplier_id, invoice_number, invoice_date, due_date, category, net,
                     vat_rate_id, vat_amount, recoverable, paid_amount, paid_on, payment_method, reference, notes, receipt_file)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
            );
            $st->execute([
                $supplierId, $invNumber, $date, $dueDate, $category, $net,
                $rateId, $vat, $recover, $paidAmount, $paidOn, $method, $ref, $notes, $receipt,
            ]);
            $purchaseId = (int)$pdo->lastInsertId();

            // Double entry. A bill paid now credits Cash/Bank exactly like an
            // expense; one left owing credits Accounts Payable (2000) instead
            // — the money hasn't left yet, so it isn't taken from either.
            $costAccount = acctCategoryAccount($category);
            $creditCode  = $paidNow ? (($method === 'Cash') ? '1000' : '1010') : '2000';

            $lines = [];
            if ($recover && $vat > 0) {
                $lines[] = ['code' => $costAccount, 'debit' => $net, 'credit' => 0, 'description' => $category];
                $lines[] = ['code' => '2210',       'debit' => $vat, 'credit' => 0, 'description' => 'Input VAT'];
            } else {
                $lines[] = ['code' => $costAccount, 'debit' => $gross, 'credit' => 0,
                            'description' => $category . ($vat > 0 ? ' (incl. non-recoverable VAT)' : '')];
            }
            $lines[] = ['code' => $creditCode, 'debit' => 0, 'credit' => $gross,
                        'description' => $supplier . ($invNumber !== '' ? ' — ' . $invNumber : '')];

            acctPostJournal($pdo, $date, $supplier . ' — ' . $category, $lines, 'purchase', $purchaseId, $ref, adminStaffName());

            $pdo->commit();
            acctAudit($pdo, 'purchase.create', 'purchase_invoices', $purchaseId, null,
                json_encode(['net' => $net, 'vat' => $vat, 'category' => $category, 'paid_now' => $paidNow]));

            $msg = 'Purchase saved' . ($vat > 0 ? ' — ' . acctMoney($vat) . ' input VAT recorded.' : '.')
                 . ($paidNow ? '' : ' Recorded as owing — ' . acctMoney($gross) . ' outstanding.');
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $err = 'Could not save: ' . $e->getMessage();
        }
    }
}

// ── Listing ─────────────────────────────────────────────────
$period  = acctPeriodFor($pdo);
$from    = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : $period['from'];
$to      = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '')   ? $_GET['to']   : $period['to'];
$catFilt = trim($_GET['category'] ?? '');

$sql = "SELECT p.*, s.name AS supplier_name FROM purchase_invoices p
        LEFT JOIN suppliers s ON s.id = p.supplier_id
        WHERE p.invoice_date BETWEEN :f AND :t";
$args = ['f' => $from, 't' => $to];
if ($catFilt !== '') { $sql .= " AND p.category = :c"; $args['c'] = $catFilt; }
$sql .= " ORDER BY p.invoice_date DESC, p.id DESC";

$rows = [];
try { $st = $pdo->prepare($sql); $st->execute($args); $rows = $st->fetchAll(PDO::FETCH_ASSOC); }
catch (PDOException $e) { }

$totNet = array_sum(array_map(static fn($r) => (float)$r['net'], $rows));
$totVat = array_sum(array_map(static fn($r) => (float)$r['vat_amount'], $rows));
$totOwing = array_sum(array_map(
    static fn($r) => max(0, (float)$r['net'] + (float)$r['vat_amount'] - (float)$r['paid_amount']), $rows
));

$rates = acctRates($pdo);
acctPageStart('purchases', 'Purchases', acctPeriodLabel(['from' => $from, 'to' => $to]));
?>

<?php if ($msg): ?><div class="cbac-banner is-ok"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="cbac-banner is-warn"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($err) ?></div><?php endif; ?>

<div class="cbac-panel">
    <h2 class="cbac-panel-title">Record a purchase</h2>
    <form method="post" enctype="multipart/form-data" class="cbac-form-grid">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="add">

        <div class="form-group">
            <label class="form-label" for="pd">Bill date *</label>
            <input type="date" id="pd" name="invoice_date" class="form-control" required value="<?= date('Y-m-d') ?>">
        </div>
        <div class="form-group">
            <label class="form-label" for="sn">Supplier *</label>
            <input type="text" id="sn" name="supplier_name" class="form-control" maxlength="180" required>
        </div>
        <div class="form-group">
            <label class="form-label" for="inv">Invoice number</label>
            <input type="text" id="inv" name="invoice_number" class="form-control" maxlength="80">
        </div>
        <div class="form-group">
            <label class="form-label" for="dd">Due date</label>
            <input type="date" id="dd" name="due_date" class="form-control">
        </div>
        <div class="form-group">
            <label class="form-label" for="cat">Category *</label>
            <select id="cat" name="category" class="form-control" required>
                <?php foreach (acctExpenseCategories() as $group => $items): ?>
                <optgroup label="<?= htmlspecialchars($group) ?>">
                    <?php foreach ($items as $c): ?>
                    <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
                    <?php endforeach; ?>
                </optgroup>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label" for="net">Amount before VAT *</label>
            <input type="number" id="net" name="net" class="form-control" step="0.01" min="0.01" required>
        </div>
        <div class="form-group">
            <label class="form-label" for="vr">VAT rate</label>
            <select id="vr" name="vat_rate_id" class="form-control">
                <option value="0">No VAT</option>
                <?php foreach ($rates as $r): ?>
                <option value="<?= (int)$r['id'] ?>"><?= htmlspecialchars($r['label']) ?></option>
                <?php endforeach; ?>
            </select>
            <small class="cbac-hint">The VAT is worked out from this — it is not typed in.</small>
        </div>
        <div class="form-group">
            <label class="form-label" for="pm">Paid by</label>
            <select id="pm" name="payment_method" class="form-control">
                <?php foreach (['Bank Transfer','Card','Cash','Direct Debit','Online'] as $m): ?>
                <option value="<?= $m ?>"><?= $m ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label" for="ref">Reference</label>
            <input type="text" id="ref" name="reference" class="form-control" maxlength="120">
        </div>
        <div class="form-group">
            <label class="form-label" for="rc">Bill / receipt</label>
            <input type="file" id="rc" name="receipt" class="form-control" accept="image/jpeg,image/png,image/webp,application/pdf">
        </div>
        <div class="form-group cbac-span-2">
            <label class="form-label" for="ds">Notes</label>
            <input type="text" id="ds" name="notes" class="form-control" maxlength="255">
        </div>
        <div class="form-group">
            <label class="cbac-switch cbac-switch-inline">
                <input type="checkbox" name="recoverable" value="1" checked>
                <span>
                    <strong>VAT is reclaimable</strong>
                    <small>Untick for entertainment and most cars — VAT is charged but cannot be reclaimed.</small>
                </span>
            </label>
        </div>
        <div class="form-group">
            <label class="cbac-switch cbac-switch-inline">
                <input type="checkbox" name="paid_now" value="1" checked>
                <span>
                    <strong>Paid in full now</strong>
                    <small>Untick to record this as a bill you owe — it posts to Accounts Payable instead of Cash/Bank.</small>
                </span>
            </label>
        </div>

        <div class="cbac-actions cbac-span-2">
            <button type="submit" class="btn-primary"><i class="fa-solid fa-plus"></i> Save purchase</button>
        </div>
    </form>
</div>

<div class="cbac-panel">
    <form method="get" class="cbac-filter-bar cbac-noprint">
        <div class="form-group"><label class="form-label" for="ff">From</label>
            <input type="date" id="ff" name="from" class="form-control" value="<?= htmlspecialchars($from) ?>"></div>
        <div class="form-group"><label class="form-label" for="tt">To</label>
            <input type="date" id="tt" name="to" class="form-control" value="<?= htmlspecialchars($to) ?>"></div>
        <div class="form-group"><label class="form-label" for="cf">Category</label>
            <select id="cf" name="category" class="form-control">
                <option value="">All</option>
                <?php foreach (acctExpenseCategoryList() as $c): ?>
                <option value="<?= htmlspecialchars($c) ?>" <?= $catFilt === $c ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
                <?php endforeach; ?>
            </select></div>
        <button type="submit" class="btn-sm"><i class="fa-solid fa-filter"></i> Filter</button>
        <span class="cbac-spacer"></span>
        <a class="btn-sm" href="export.php?type=purchases&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?><?= $catFilt !== '' ? '&category=' . urlencode($catFilt) : '' ?>"><i class="fa-solid fa-file-csv"></i> CSV</a>
        <button type="button" class="btn-sm" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
    </form>

    <table class="cbac-table">
        <thead>
            <tr><th>Date</th><th>Supplier</th><th>Invoice #</th><th>Category</th>
                <th class="r">Net</th><th class="r">VAT</th><th class="r">Gross</th><th>Status</th><th>File</th></tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r):
            $gross  = (float)$r['net'] + (float)$r['vat_amount'];
            $owing  = round($gross - (float)$r['paid_amount'], 2);
        ?>
            <tr>
                <td><?= htmlspecialchars(date('j M Y', strtotime($r['invoice_date']))) ?></td>
                <td><?= htmlspecialchars($r['supplier_name'] ?: '—') ?></td>
                <td><?= htmlspecialchars($r['invoice_number'] ?: '—') ?></td>
                <td><?= htmlspecialchars($r['category']) ?></td>
                <td class="r"><?= acctMoney((float)$r['net']) ?></td>
                <td class="r">
                    <?= acctMoney((float)$r['vat_amount']) ?>
                    <?php if (!$r['recoverable'] && (float)$r['vat_amount'] > 0): ?>
                        <small class="cbac-blocked">not reclaimable</small>
                    <?php endif; ?>
                </td>
                <td class="r"><?= acctMoney($gross) ?></td>
                <td>
                    <?php if ($owing <= 0.005): ?>
                        <span class="cbac-badge is-ok">Paid</span>
                    <?php else: ?>
                        <span class="cbac-badge is-warn">Owing <?= acctMoney($owing) ?></span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($r['receipt_file']): ?>
                    <a href="../../assets/receipts/<?= htmlspecialchars($r['receipt_file']) ?>" target="_blank" rel="noopener">view</a>
                    <?php else: ?>—<?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
            <tr><td colspan="9" class="cbac-empty">No purchases in this period.</td></tr>
        <?php endif; ?>
        </tbody>
        <?php if ($rows): ?>
        <tfoot>
            <tr class="is-total">
                <td colspan="4">Total (<?= count($rows) ?>)</td>
                <td class="r"><?= acctMoney($totNet) ?></td>
                <td class="r"><?= acctMoney($totVat) ?></td>
                <td class="r"><?= acctMoney($totNet + $totVat) ?></td>
                <td colspan="2"><?= $totOwing > 0 ? acctMoney($totOwing) . ' owing' : '' ?></td>
            </tr>
        </tfoot>
        <?php endif; ?>
    </table>
</div>

<?php acctPageEnd(); ?>
