<?php
// ============================================================
//  Creamy Bite – Expenses
//
//  The missing half of the VAT return. Without expenses recorded, Box 4 is
//  always zero, the business appears to owe HMRC more than it does, and the
//  profit figure counts no costs at all.
//
//  Saving an expense posts a balanced journal entry at the same time, in one
//  transaction: cost and input VAT debited, the money source credited. An
//  expense that reaches the VAT return but not the ledger would make the two
//  disagree, and reconciling that later is far harder than getting it right
//  on the way in.
// ============================================================
require_once __DIR__ . '/_layout.php';

$msg = ''; $err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    csrfCheck();

    $date     = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['expense_date'] ?? '') ? $_POST['expense_date'] : '';
    $supplier = trim($_POST['supplier_name'] ?? '');
    $category = trim($_POST['category'] ?? 'Miscellaneous');
    $desc     = trim($_POST['description'] ?? '');
    $net      = round((float)($_POST['net'] ?? 0), 2);
    $rateId   = (int)($_POST['vat_rate_id'] ?? 0);
    $recover  = isset($_POST['recoverable']) ? 1 : 0;
    $method   = trim($_POST['payment_method'] ?? 'Bank Transfer');
    $ref      = trim($_POST['reference'] ?? '');
    $notes    = trim($_POST['notes'] ?? '');

    // The VAT is computed from the chosen rate, never taken from the form.
    // A posted vat_amount is a number the user can set to anything, and the
    // one figure on this page that must not be arbitrary is the one that ends
    // up in Box 4.
    $rate = 0.0;
    foreach (acctRates($pdo, false) as $r) {
        if ((int)$r['id'] === $rateId) { $rate = (float)$r['rate']; break; }
    }
    $vat = acctVatOnNet($net, $rate);

    if ($date === '')                    { $err = 'Pick the date on the receipt.'; }
    elseif ($net <= 0)                   { $err = 'Enter the amount before VAT.'; }
    elseif (!in_array($category, acctExpenseCategoryList(), true)) { $err = 'Pick a category.'; }

    // Receipt upload. Optional, but the extension comes from the verified
    // mime type rather than the filename — the same rule the product and
    // gallery uploads use, for the same reason.
    $receipt = '';
    if (!$err && !empty($_FILES['receipt']['name'])) {
        $f = $_FILES['receipt'];
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime  = finfo_file($finfo, $f['tmp_name']);
        finfo_close($finfo);

        if (!isset($allowed[$mime]))        { $err = 'Receipts must be a JPG, PNG, WebP or PDF.'; }
        elseif ($f['size'] > 8*1024*1024)   { $err = 'That receipt is over 8MB.'; }
        else {
            $dir = dirname(__DIR__, 2) . '/assets/receipts/';
            if (!is_dir($dir)) { mkdir($dir, 0755, true); }
            $receipt = 'receipt_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
            if (!move_uploaded_file($f['tmp_name'], $dir . $receipt)) {
                $err = 'Could not save that receipt.';
                $receipt = '';
            }
        }
    }

    if (!$err) {
        try {
            $pdo->beginTransaction();

            $st = $pdo->prepare(
                "INSERT INTO expenses (expense_date, supplier_name, category, description, net,
                                       vat_rate_id, vat_amount, recoverable, payment_method, reference, notes, receipt_file)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)"
            );
            $st->execute([$date, $supplier, $category, $desc, $net, $rateId, $vat, $recover, $method, $ref, $notes, $receipt]);
            $expenseId = (int)$pdo->lastInsertId();

            // Double entry. Non-recoverable VAT is added to the cost rather
            // than sitting in the input-VAT account, because that is what it
            // is — a cost the business cannot reclaim.
            $costAccount = acctCategoryAccount($category);
            $moneyOut    = ($method === 'Cash') ? '1000' : '1010';

            $lines = [];
            if ($recover && $vat > 0) {
                $lines[] = ['code' => $costAccount, 'debit' => $net,  'credit' => 0, 'description' => $category];
                $lines[] = ['code' => '2210',       'debit' => $vat,  'credit' => 0, 'description' => 'Input VAT'];
            } else {
                $lines[] = ['code' => $costAccount, 'debit' => round($net + $vat, 2), 'credit' => 0,
                            'description' => $category . ($vat > 0 ? ' (incl. non-recoverable VAT)' : '')];
            }
            $lines[] = ['code' => $moneyOut, 'debit' => 0, 'credit' => round($net + $vat, 2),
                        'description' => $supplier !== '' ? $supplier : 'Expense'];

            acctPostJournal($pdo, $date, ($supplier !== '' ? $supplier . ' — ' : '') . $category,
                            $lines, 'expense', $expenseId, $ref, adminStaffName());

            $pdo->commit();
            acctAudit($pdo, 'expense.create', 'expenses', $expenseId, null,
                json_encode(['net' => $net, 'vat' => $vat, 'category' => $category]));

            $msg = 'Expense saved' . ($vat > 0 ? ' — ' . acctMoney($vat) . ' input VAT recorded.' : '.');
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

$sql = "SELECT * FROM expenses WHERE expense_date BETWEEN :f AND :t";
$args = ['f' => $from, 't' => $to];
if ($catFilt !== '') { $sql .= " AND category = :c"; $args['c'] = $catFilt; }
$sql .= " ORDER BY expense_date DESC, id DESC";

$rows = [];
try { $st = $pdo->prepare($sql); $st->execute($args); $rows = $st->fetchAll(PDO::FETCH_ASSOC); }
catch (PDOException $e) { }

$totNet = array_sum(array_map(static fn($r) => (float)$r['net'], $rows));
$totVat = array_sum(array_map(static fn($r) => (float)$r['vat_amount'], $rows));

$rates = acctRates($pdo);
acctPageStart('expenses', 'Expenses', acctPeriodLabel(['from' => $from, 'to' => $to]));
?>

<?php if ($msg): ?><div class="cbac-banner is-ok"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="cbac-banner is-warn"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($err) ?></div><?php endif; ?>

<div class="cbac-panel">
    <h2 class="cbac-panel-title">Record an expense</h2>
    <form method="post" enctype="multipart/form-data" class="cbac-form-grid">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="add">

        <div class="form-group">
            <label class="form-label" for="ed">Date *</label>
            <input type="date" id="ed" name="expense_date" class="form-control" required value="<?= date('Y-m-d') ?>">
        </div>
        <div class="form-group">
            <label class="form-label" for="sn">Supplier</label>
            <input type="text" id="sn" name="supplier_name" class="form-control" maxlength="180">
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
            <label class="form-label" for="rc">Receipt</label>
            <input type="file" id="rc" name="receipt" class="form-control" accept="image/jpeg,image/png,image/webp,application/pdf">
        </div>
        <div class="form-group cbac-span-2">
            <label class="form-label" for="ds">Description</label>
            <input type="text" id="ds" name="description" class="form-control" maxlength="255">
        </div>
        <div class="form-group cbac-span-2">
            <label class="cbac-switch cbac-switch-inline">
                <input type="checkbox" name="recoverable" value="1" checked>
                <span>
                    <strong>VAT is reclaimable</strong>
                    <small>Untick for entertainment and most cars — VAT is charged but cannot be reclaimed,
                    so it belongs in the cost rather than in Box 4.</small>
                </span>
            </label>
        </div>

        <div class="cbac-actions cbac-span-2">
            <button type="submit" class="btn-primary"><i class="fa-solid fa-plus"></i> Save expense</button>
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
        <button type="button" class="btn-sm" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
    </form>

    <table class="cbac-table">
        <thead>
            <tr><th>Date</th><th>Supplier</th><th>Category</th><th>Description</th>
                <th class="r">Net</th><th class="r">VAT</th><th class="r">Gross</th><th>Paid by</th><th>Receipt</th></tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= htmlspecialchars(date('j M Y', strtotime($r['expense_date']))) ?></td>
                <td><?= htmlspecialchars($r['supplier_name'] ?: '—') ?></td>
                <td><?= htmlspecialchars($r['category']) ?></td>
                <td><?= htmlspecialchars($r['description'] ?: '—') ?></td>
                <td class="r"><?= acctMoney((float)$r['net']) ?></td>
                <td class="r">
                    <?= acctMoney((float)$r['vat_amount']) ?>
                    <?php if (!$r['recoverable'] && (float)$r['vat_amount'] > 0): ?>
                        <small class="cbac-blocked">not reclaimable</small>
                    <?php endif; ?>
                </td>
                <td class="r"><?= acctMoney((float)$r['net'] + (float)$r['vat_amount']) ?></td>
                <td><?= htmlspecialchars($r['payment_method']) ?></td>
                <td>
                    <?php if ($r['receipt_file']): ?>
                    <a href="../../assets/receipts/<?= htmlspecialchars($r['receipt_file']) ?>" target="_blank" rel="noopener">view</a>
                    <?php else: ?>—<?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
            <tr><td colspan="9" class="cbac-empty">No expenses in this period.</td></tr>
        <?php endif; ?>
        </tbody>
        <?php if ($rows): ?>
        <tfoot>
            <tr class="is-total">
                <td colspan="4">Total (<?= count($rows) ?>)</td>
                <td class="r"><?= acctMoney($totNet) ?></td>
                <td class="r"><?= acctMoney($totVat) ?></td>
                <td class="r"><?= acctMoney($totNet + $totVat) ?></td>
                <td colspan="2"></td>
            </tr>
        </tfoot>
        <?php endif; ?>
    </table>
</div>

<?php acctPageEnd(); ?>
