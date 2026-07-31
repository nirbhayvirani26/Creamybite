<?php
// ============================================================
//  Creamy Bite – Trade Invoice (printable)
//  URL: /trade_invoice.php?code=SCO12345
//
//  Scoped to the logged-in trade account: the order is looked up by
//  order_code AND trade_user_id, so changing the ?code= in the address
//  bar cannot surface another partner's invoice.
// ============================================================
session_start();

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';

if (empty($_SESSION['trade_user'])) {
    header('Location: trade_login.php');
    exit;
}

$userId = (int)($_SESSION['trade_user']['id'] ?? 0);
$code   = trim($_GET['code'] ?? '');

if ($code === '') {
    header('Location: trade_profile.php?tab=invoices');
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM orders WHERE order_code = :code AND trade_user_id = :uid LIMIT 1");
$stmt->execute(['code' => $code, 'uid' => $userId]);
$order = $stmt->fetch();

if (!$order) {
    http_response_code(404);
    $notFound = true;
} else {
    $notFound = false;
}

// Account details for the "billed to" block, read fresh so an edited
// address shows on newly opened invoices.
$acct = $pdo->prepare("SELECT * FROM trade_users WHERE id = :id");
$acct->execute(['id' => $userId]);
$account = $acct->fetch() ?: [];

$items    = $notFound ? [] : (json_decode($order['items_json'] ?? '', true) ?? []);
$lineSum  = 0.0;
foreach ($items as $it) {
    $lineSum += (float)($it['price'] ?? 0) * (int)($it['quantity'] ?? 0);
}

$discount = $notFound ? 0.0 : (float)($order['discount_amount'] ?? 0);
$delivery = $notFound ? 0.0 : (float)($order['delivery_charge'] ?? 0);
$vat      = $notFound ? 0.0 : (float)($order['vat_amount'] ?? 0);
$total    = $notFound ? 0.0 : (float)$order['total_price'];
$customerNo = 'TC-' . str_pad((string)$userId, 5, '0', STR_PAD_LEFT);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invoice <?= htmlspecialchars($code) ?> – <?= SHOP_NAME ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: #f4f5f7; color: #1f2937; margin: 0; padding: 32px 16px;
        }
        .sheet {
            max-width: 820px; margin: 0 auto; background: #fff; padding: 44px;
            border-radius: 14px; box-shadow: 0 10px 30px rgba(0,0,0,0.08);
        }
        .head { display: flex; justify-content: space-between; gap: 24px; flex-wrap: wrap;
                border-bottom: 2px solid #5C1D24; padding-bottom: 22px; margin-bottom: 26px; }
        .brand { font-size: 24px; font-weight: 800; color: #5C1D24; margin: 0 0 4px; }
        .muted { color: #6b7280; font-size: 13px; }
        .label { font-size: 11px; text-transform: uppercase; letter-spacing: .1em;
                 color: #9ca3af; font-weight: 700; margin-bottom: 4px; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px,1fr));
                gap: 24px; margin-bottom: 30px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 22px; }
        th { text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .08em;
             color: #6b7280; border-bottom: 2px solid #e5e7eb; padding: 10px 8px; }
        td { padding: 12px 8px; border-bottom: 1px solid #f3f4f6; font-size: 14px; }
        .num { text-align: right; white-space: nowrap; }
        .totals { margin-left: auto; width: 100%; max-width: 320px; }
        .totals td { border: none; padding: 7px 8px; font-size: 14px; }
        .totals .grand td { border-top: 2px solid #5C1D24; font-size: 18px;
                            font-weight: 800; color: #5C1D24; padding-top: 12px; }
        .pill { display: inline-block; padding: 5px 14px; border-radius: 20px;
                font-size: 12px; font-weight: 800; letter-spacing: .04em; }
        .paid   { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
        .unpaid { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
        .actions { max-width: 820px; margin: 0 auto 18px; display: flex; gap: 10px; }
        .btn { border: none; border-radius: 8px; padding: 10px 18px; font-size: 13px;
               font-weight: 700; cursor: pointer; text-decoration: none;
               display: inline-flex; align-items: center; gap: 7px; }
        .btn-print { background: #5C1D24; color: #fff; }
        .btn-back  { background: #fff; color: #5C1D24; border: 1px solid #e5e7eb; }
        @media print {
            body { background: #fff; padding: 0; }
            .sheet { box-shadow: none; padding: 0; max-width: none; }
            .actions { display: none; }
        }
    
    /* extracted from inline style attributes */
    .r{ text-align:right }
    .empty-cell{ text-align:center; padding:24px }
    .addr-lines{ margin-top:4px; line-height:1.6 }
    .foot-note{ margin-top:34px; border-top:1px solid #f3f4f6; padding-top:16px }
    .meta-lines{ line-height:1.9 }
    .who-name{ font-weight:700; font-size:15px }
    .doc-word{ font-size:26px; font-weight:800; letter-spacing:.04em; color:#1f2937 }
    .mono{ font-family:monospace }
    .inv-num{ font-family:monospace; font-size:17px; font-weight:800; color:#5C1D24; margin:4px 0 8px }
    .pos{ color:#047857 }
</style>
</head>
<body>

<div class="actions">
    <a href="trade_profile.php?tab=invoices" class="btn btn-back"><i class="fa-solid fa-arrow-left"></i> Back to Invoices</a>
    <?php if (!$notFound): ?>
    <button class="btn btn-print" onclick="window.print()"><i class="fa-solid fa-print"></i> Print / Save as PDF</button>
    <?php endif; ?>
</div>

<div class="sheet">
<?php if ($notFound): ?>
    <h1 class="brand">Invoice not found</h1>
    <p class="muted">
        No invoice with the reference <strong><?= htmlspecialchars($code) ?></strong> exists on your account.
    </p>
<?php else: ?>

    <div class="head">
        <div>
            <h1 class="brand"><?= SHOP_NAME ?></h1>
            <div class="muted">
                <?= SHOP_TAGLINE ?><br>
                <?= SHOP_PHONE ?><br>
                <?= ADMIN_EMAIL ?>
            </div>
        </div>
        <div class="r">
            <div class="doc-word">INVOICE</div>
            <div class="inv-num">
                <?= htmlspecialchars($order['order_code']) ?>
            </div>
            <?php $isPaid = ($order['payment_status'] ?? 'Unpaid') !== 'Unpaid'; ?>
            <span class="pill <?= $isPaid ? 'paid' : 'unpaid' ?>"><?= $isPaid ? 'PAID' : 'UNPAID' ?></span>
        </div>
    </div>

    <div class="grid">
        <div>
            <div class="label">Billed To</div>
            <div class="who-name"><?= htmlspecialchars($account['business_name'] ?? '') ?></div>
            <div class="muted addr-lines" >
                <?= nl2br(htmlspecialchars($account['address'] ?? '')) ?><br>
                <strong><?= htmlspecialchars($account['postcode'] ?? '') ?></strong><br>
                <?= htmlspecialchars($account['contact_name'] ?? '') ?><br>
                <?= htmlspecialchars($account['phone'] ?? '') ?>
            </div>
        </div>
        <div>
            <div class="label">Details</div>
            <div class="muted meta-lines" >
                Invoice Date: <strong><?= date('d M Y', strtotime($order['created_at'])) ?></strong><br>
                Customer No: <strong class="mono"><?= htmlspecialchars($customerNo) ?></strong><br>
                <?php if (!empty($account['vat_number'])): ?>
                VAT Number: <strong><?= htmlspecialchars($account['vat_number']) ?></strong><br>
                <?php endif; ?>
                Order Status: <strong><?= htmlspecialchars($order['status'] ?? '') ?></strong>
            </div>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Item</th>
                <th class="num">Unit Price</th>
                <th class="num">Qty</th>
                <th class="num">Amount</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($items)): ?>
            <tr><td colspan="4" class="muted empty-cell" >No line items recorded.</td></tr>
        <?php else: foreach ($items as $it):
            $unit = (float)($it['price'] ?? 0);
            $qty  = (int)($it['quantity'] ?? 0);
        ?>
            <tr>
                <td>
                    <strong><?= htmlspecialchars($it['name'] ?? 'Item') ?></strong>
                    <?php if (!empty($it['variant_name'])): ?>
                    <span class="muted">– <?= htmlspecialchars($it['variant_name']) ?></span>
                    <?php endif; ?>
                </td>
                <td class="num">£<?= number_format($unit, 2) ?></td>
                <td class="num"><?= $qty ?></td>
                <td class="num">£<?= number_format($unit * $qty, 2) ?></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>

    <table class="totals">
        <tr>
            <td class="muted">Subtotal</td>
            <td class="num">£<?= number_format($lineSum, 2) ?></td>
        </tr>
        <?php if ($discount > 0): ?>
        <tr>
            <td class="muted">Discount<?= !empty($order['promo_code']) ? ' (' . htmlspecialchars($order['promo_code']) . ')' : '' ?></td>
            <td class="num pos" >− £<?= number_format($discount, 2) ?></td>
        </tr>
        <?php endif; ?>
        <?php if ($delivery > 0): ?>
        <tr>
            <td class="muted">Delivery</td>
            <td class="num">£<?= number_format($delivery, 2) ?></td>
        </tr>
        <?php endif; ?>
        <?php if ($vat > 0): ?>
        <tr>
            <td class="muted">VAT @ <?= (int)(TRADE_VAT_RATE * 100) ?>%</td>
            <td class="num">£<?= number_format($vat, 2) ?></td>
        </tr>
        <?php endif; ?>
        <tr class="grand">
            <td>Total</td>
            <td class="num">£<?= number_format($total, 2) ?></td>
        </tr>
    </table>

    <p class="muted foot-note" >
        Thank you for your business. Please quote invoice
        <strong><?= htmlspecialchars($order['order_code']) ?></strong> with any payment or query.
    </p>

<?php endif; ?>
</div>

</body>
</html>
