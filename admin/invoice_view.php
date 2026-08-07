<?php
// ============================================================
//  Creamy Bite – Invoice (print view)
//  URL: /admin/invoice_view.php?id=12
//
//  Layout follows the shop's real invoice (INV0225):
//    letterhead left, INVOICE / number / date / due / balance right,
//    BILL TO block, then DESCRIPTION | RATE | QTY | AMOUNT,
//    payment instructions bottom-left, TOTAL and BALANCE DUE bottom-right.
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/_permissions.php';
adminRequire('invoices');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/invoice.php';

$invoiceId = (int)($_GET['id'] ?? 0);
$inv = $invoiceId > 0 ? loadInvoice($pdo, $invoiceId) : null;

if (!$inv) {
    http_response_code(404);
    echo '<p class="nf-body">Invoice not found. <a href="index.php?tab=invoices">Back to invoices</a></p>';
    exit;
}

[$stLabel, $stBg, $stFg, $stBd] = invoiceStatusLabel($inv['status']);

// The rep's name, resolved once. Looked up rather than stored on the invoice
// so a corrected spelling shows on every document that person sold.
$salesRepName = '';
if (!empty($inv['sales_rep_id'])) {
    try {
        $r = $pdo->prepare("SELECT name FROM sales_reps WHERE id = :id");
        $r->execute(['id' => (int)$inv['sales_rep_id']]);
        $salesRepName = (string)($r->fetchColumn() ?: '');
    } catch (PDOException $e) {
        error_log('Sales rep lookup failed: ' . $e->getMessage());
    }
}
$symbol  = $inv['currency'] === 'GBP' ? '£' : '';
$balance = (float)$inv['balance_due'];

/** Print a multi-line stored field as HTML. */
function invLines(string $text): string
{
    return nl2br(htmlspecialchars(trim($text)));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Invoice <?= htmlspecialchars($inv['invoice_number']) ?> – <?= SHOP_NAME ?></title>
<?php require __DIR__ . '/../includes/favicon.php'; ?>
<link rel="stylesheet" href="<?= cbAsset('assets/css/invoice-print.css') ?>">
</head>
<body>

<div class="bar">
    <a href="index.php?tab=invoices" class="btn btn-plain">&larr; Back to Invoices</a>
    <a href="invoice_edit.php?id=<?= (int)$inv['id'] ?>" class="btn btn-plain">Edit</a>
    <button class="btn btn-primary" onclick="window.print()">Print / Save as PDF</button>
</div>

<div class="sheet">

    <div class="top">
        <div>
            <?php
            // Logo, with the business name as the fallback if the file is
            // missing. print-color-adjust keeps it from dropping out when the
            // browser strips backgrounds for printing.
            $logoPath = __DIR__ . '/../assets/images/logo.png';
            if (is_file($logoPath)):
            ?>
            <img src="../assets/images/logo.png" alt="<?= htmlspecialchars($inv['from_name']) ?>" class="brand-logo">
            <?php else: ?>
            <div class="brand"><?= htmlspecialchars($inv['from_name']) ?></div>
            <?php endif; ?>
            <div class="from">
                <?= invLines($inv['from_address']) ?><br>
                <?php if ($inv['from_phone'] !== ''): ?><?= htmlspecialchars($inv['from_phone']) ?><br><?php endif; ?>
                <?php if ($inv['from_website'] !== ''): ?><?= htmlspecialchars($inv['from_website']) ?><br><?php endif; ?>
                <?php if ($inv['from_email'] !== ''): ?><?= htmlspecialchars($inv['from_email']) ?><?php endif; ?>
            </div>
        </div>

        <div class="doc">
            <h1>INVOICE</h1>
            <div class="num"><?= htmlspecialchars($inv['invoice_number']) ?></div>

            <table class="meta">
                <tr>
                    <td class="k">Date</td>
                    <td class="v"><?= date('d/m/Y', strtotime($inv['issue_date'])) ?></td>
                </tr>
                <tr>
                    <td class="k">Due</td>
                    <td class="v">
                        <?= $inv['due_date']
                              ? date('d/m/Y', strtotime($inv['due_date']))
                              : htmlspecialchars($inv['due_terms']) ?>
                    </td>
                </tr>
                <tr class="balance-row">
                    <td class="k">Balance Due</td>
                    <td class="v"><?= htmlspecialchars($inv['currency']) ?> <?= $symbol ?><?= number_format($balance, 2) ?></td>
                </tr>
            </table>

            <!-- Screen only. DRAFT / SENT / PART PAID are internal bookkeeping,
                 and a customer handed a document stamped "DRAFT" reasonably
                 doubts whether it is a real invoice. -->
            <span class="pill no-print <?= invoiceStatusClass($inv['status']) ?>">
                <?= $stLabel ?>
            </span>
        </div>
    </div>

    <div class="billto">
        <div class="cap">Bill To</div>
        <div class="who"><?= htmlspecialchars($inv['to_name']) ?></div>
        <div class="addr">
            <?= invLines($inv['to_address']) ?>
            <?php if ($inv['to_phone'] !== ''): ?><br><?= htmlspecialchars($inv['to_phone']) ?><?php endif; ?>
            <?php if ($inv['to_email'] !== ''): ?><br><?= htmlspecialchars($inv['to_email']) ?><?php endif; ?>
            <?php if ($inv['to_vat_number'] !== ''): ?><br>VAT: <?= htmlspecialchars($inv['to_vat_number']) ?><?php endif; ?>
        </div>
        <?php if ($salesRepName !== ''): ?>
        <!-- The rep's NAME is on the customer's copy so they know who served
             them. What that rep earns is not — see invoice_edit.php. -->
        <div class="soldby">
            <span class="soldby-cap">Sold by</span>
            <strong><?= htmlspecialchars($salesRepName) ?></strong>
        </div>
        <?php endif; ?>
    </div>

    <table class="items">
        <thead>
            <tr>
                <th class="col-desc">Description</th>
                <th class="r col-num" >Rate</th>
                <th class="r col-num" >Qty</th>
                <th class="r col-num" >Amount</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($inv['items'])): ?>
            <tr><td colspan="4" class="empty-cell">No line items yet.</td></tr>
        <?php else: foreach ($inv['items'] as $it): ?>
            <tr>
                <td><?= htmlspecialchars($it['description']) ?></td>
                <td class="r">
                    <?= $symbol ?><?= number_format((float)$it['rate'], 2) ?>
                    <?php if ($it['rate_note'] !== ''): ?>
                    <span class="ratenote">(<?= htmlspecialchars($it['rate_note']) ?>)</span>
                    <?php endif; ?>
                </td>
                <td class="r"><?= htmlspecialchars(formatInvoiceQty((float)$it['qty'], $it['qty_unit'])) ?></td>
                <td class="r"><?= $symbol ?><?= number_format((float)$it['amount'], 2) ?></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>

    <div class="foot">
        <div class="pay">
            <?php if (trim($inv['payment_instructions']) !== ''): ?>
            <div class="cap cap-tight" >Payment Instructions</div>
            <?= invLines($inv['payment_instructions']) ?>
            <?php endif; ?>

            <?php if (!empty($inv['payments'])): ?>
            <div class="cap cap-gap" >Payments Received</div>
            <?php foreach ($inv['payments'] as $p): ?>
                <?= date('d/m/Y', strtotime($p['paid_on'])) ?> —
                <?= $symbol ?><?= number_format((float)$p['amount'], 2) ?>
                (<?= htmlspecialchars($p['method']) ?><?= $p['reference'] !== '' ? ', ' . htmlspecialchars($p['reference']) : '' ?>)<br>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="totals">
            <table>
                <tr>
                    <td>Subtotal</td>
                    <td class="r"><?= $symbol ?><?= number_format((float)$inv['subtotal'], 2) ?></td>
                </tr>
                <?php if ((float)$inv['discount'] > 0): ?>
                <tr>
                    <td>
                        Discount<?= ($inv['discount_type'] ?? 'fixed') === 'percent'
                            ? ' (' . rtrim(rtrim(number_format((float)$inv['discount_value'], 2), '0'), '.') . '%)'
                            : '' ?>
                    </td>
                    <td class="r pos" >&minus; <?= $symbol ?><?= number_format((float)$inv['discount'], 2) ?></td>
                </tr>
                <?php endif; ?>
                <?php if ((float)$inv['delivery'] > 0): ?>
                <tr>
                    <td>Delivery</td>
                    <td class="r"><?= $symbol ?><?= number_format((float)$inv['delivery'], 2) ?></td>
                </tr>
                <?php endif; ?>
                <?php if ((float)$inv['vat_amount'] > 0): ?>
                <tr>
                    <td>VAT @ <?= rtrim(rtrim(number_format((float)$inv['vat_rate'] * 100, 2), '0'), '.') ?>%</td>
                    <td class="r"><?= $symbol ?><?= number_format((float)$inv['vat_amount'], 2) ?></td>
                </tr>
                <?php endif; ?>
                <tr class="grand">
                    <td>Total</td>
                    <td class="r"><?= $symbol ?><?= number_format((float)$inv['total'], 2) ?></td>
                </tr>
                <?php if ((float)$inv['amount_paid'] > 0): ?>
                <tr>
                    <td>Paid</td>
                    <td class="r pos" >&minus; <?= $symbol ?><?= number_format((float)$inv['amount_paid'], 2) ?></td>
                </tr>
                <?php endif; ?>
                <tr class="due">
                    <td>Balance Due</td>
                    <td class="r"><?= htmlspecialchars($inv['currency']) ?> <?= $symbol ?><?= number_format($balance, 2) ?></td>
                </tr>
            </table>
        </div>
    </div>

    <?php if (trim($inv['notes']) !== ''): ?>
    <div class="notes"><?= invLines($inv['notes']) ?></div>
    <?php endif; ?>

</div>
</body>
</html>
