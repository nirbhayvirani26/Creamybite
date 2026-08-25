<?php
// ============================================================
//  Creamy Bite – Customer invoice view
//  URL: /invoice.php?t=<token>
//
//  What a customer opens from their email or WhatsApp message. No login:
//  the token in the address is what grants access, which is why it is 48
//  random hex characters rather than the invoice id — sequential ids would
//  let anyone walk the ledger by changing a number.
//
//  Draft and void invoices are refused even with a valid token. A draft is
//  not a document the shop has committed to, and a void one has been
//  withdrawn; neither should be presentable as a bill.
// ============================================================
require_once __DIR__ . '/includes/session.php';

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/invoice.php';

$token = trim((string)($_GET['t'] ?? ''));

$inv = null;
if ($token !== '' && preg_match('/^[a-f0-9]{16,64}$/i', $token)) {
    try {
        $q = $pdo->prepare("SELECT id FROM invoices WHERE public_token = :t AND status NOT IN ('draft','void') LIMIT 1");
        $q->execute(['t' => $token]);
        if ($id = $q->fetchColumn()) {
            $inv = loadInvoice($pdo, (int)$id);
        }
    } catch (PDOException $e) {
        error_log('Public invoice lookup failed: ' . $e->getMessage());
    }
}

if (!$inv) {
    http_response_code(404);
    $symbol = '';
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <?php // Token-gated page — must never be indexed. ?>
        <meta name="robots" content="noindex, nofollow">
        <title>Invoice not found – <?= SHOP_NAME ?></title>
        <link rel="stylesheet" href="<?= cbAsset('assets/css/style.css') ?>">
        <link rel="stylesheet" href="<?= cbAsset('assets/css/components.css') ?>">
    </head>
    <body>
        <div class="cbinv-missing">
            <h1>We could not find that invoice</h1>
            <p>
                The link may be incomplete, or the invoice may have been
                withdrawn. Please check the link in your email, or call us on
                <strong><?= htmlspecialchars(SHOP_PHONE) ?></strong>.
            </p>
            <a href="<?= htmlspecialchars(SITE_BASE) ?>/index.php" class="btn-primary">Back to the shop</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

$symbol  = $inv['currency'] === 'GBP' ? '£' : '';
$balance = (float)$inv['balance_due'];

$salesRepName = '';
if (!empty($inv['sales_rep_id'])) {
    try {
        $r = $pdo->prepare("SELECT name FROM sales_reps WHERE id = :id");
        $r->execute(['id' => (int)$inv['sales_rep_id']]);
        $salesRepName = (string)($r->fetchColumn() ?: '');
    } catch (PDOException $e) {
        // A missing reps table must not stop a customer reading their bill.
    }
}

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
<?php // Token-gated page — must never be indexed. ?>
<meta name="robots" content="noindex, nofollow">
<title>Invoice <?= htmlspecialchars($inv['invoice_number']) ?> – <?= SHOP_NAME ?></title>
<link rel="stylesheet" href="<?= cbAsset('admin/assets/css/invoice-print.css') ?>">
</head>
<body>

<div class="bar no-print">
    <button class="btn btn-primary" onclick="window.print()">Save as PDF / Print</button>
    <span class="cbinv-bar-note">Choose “Save as PDF” in the print window.</span>
</div>

<div class="sheet">

    <div class="top">
        <div>
            <?php $logoPath = __DIR__ . '/assets/images/logo.png'; if (is_file($logoPath)): ?>
            <img src="assets/images/logo.png" alt="<?= htmlspecialchars($inv['from_name']) ?>" class="brand-logo">
            <?php else: ?>
            <div class="brand"><?= htmlspecialchars($inv['from_name']) ?></div>
            <?php endif; ?>
            <div class="from">
                <?= invLines($inv['from_address']) ?><br>
                <?php if ($inv['from_phone'] !== ''): ?><?= htmlspecialchars($inv['from_phone']) ?><br><?php endif; ?>
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
                        <?= $inv['due_date'] ? date('d/m/Y', strtotime($inv['due_date'])) : htmlspecialchars($inv['due_terms']) ?>
                    </td>
                </tr>
                <tr class="balance-row">
                    <td class="k">Balance Due</td>
                    <td class="v"><?= $symbol ?><?= number_format($balance, 2) ?></td>
                </tr>
            </table>
        </div>
    </div>

    <div class="billto">
        <div class="cap">Bill To</div>
        <div class="who"><?= htmlspecialchars($inv['to_name']) ?></div>
        <div class="addr">
            <?= invLines($inv['to_address']) ?>
            <?php if ($inv['to_phone'] !== ''): ?><br><?= htmlspecialchars($inv['to_phone']) ?><?php endif; ?>
            <?php if ($inv['to_vat_number'] !== ''): ?><br>VAT: <?= htmlspecialchars($inv['to_vat_number']) ?><?php endif; ?>
        </div>
        <?php if ($salesRepName !== ''): ?>
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
                <th class="r col-num">Rate</th>
                <th class="r col-num">Qty</th>
                <th class="r col-num">Amount</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($inv['items'] as $it): ?>
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
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="foot">
        <div class="pay">
            <?php if (trim($inv['payment_instructions']) !== ''): ?>
            <div class="cap cap-tight">Payment Instructions</div>
            <?= invLines($inv['payment_instructions']) ?>
            <?php endif; ?>
        </div>
        <div class="totals">
            <table>
                <tr><td>Subtotal</td><td class="r"><?= $symbol ?><?= number_format((float)$inv['subtotal'], 2) ?></td></tr>
                <?php if ((float)$inv['discount'] > 0): ?>
                <tr><td>Discount</td><td class="r pos">−<?= $symbol ?><?= number_format((float)$inv['discount'], 2) ?></td></tr>
                <?php endif; ?>
                <?php if ((float)$inv['delivery'] > 0): ?>
                <tr><td>Delivery</td><td class="r"><?= $symbol ?><?= number_format((float)$inv['delivery'], 2) ?></td></tr>
                <?php endif; ?>
                <?php if ((float)$inv['vat_amount'] > 0): ?>
                <tr><td>VAT</td><td class="r"><?= $symbol ?><?= number_format((float)$inv['vat_amount'], 2) ?></td></tr>
                <?php endif; ?>
                <tr class="grand"><td>Total</td><td class="r"><?= $symbol ?><?= number_format((float)$inv['total'], 2) ?></td></tr>
                <?php if ((float)$inv['amount_paid'] > 0): ?>
                <tr><td>Paid</td><td class="r">−<?= $symbol ?><?= number_format((float)$inv['amount_paid'], 2) ?></td></tr>
                <?php endif; ?>
                <tr class="due"><td>Balance Due</td><td class="r"><?= $symbol ?><?= number_format($balance, 2) ?></td></tr>
            </table>
        </div>
    </div>

    <?php if (trim($inv['notes']) !== ''): ?>
    <div class="notes"><?= invLines($inv['notes']) ?></div>
    <?php endif; ?>
</div>

</body>
</html>
