<?php
// ============================================================
//  Creamy Bite – Trade Invoice (printable)
//  URL: /trade_invoice.php?code=SCO12345
//
//  Scoped to the logged-in trade account: the order is looked up by
//  order_code AND trade_user_id, so changing the ?code= in the address
//  bar cannot surface another partner's invoice.
// ============================================================
require_once __DIR__ . '/../includes/session.php';

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

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
    <?php // Private, login-gated invoice — must never be indexed. ?>
    <meta name="robots" content="noindex, nofollow">
    <?php require __DIR__ . '/../includes/favicon.php'; ?>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?= cbAsset('../assets/css/trade-invoice.css') ?>">
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
                <?= SHOP_EMAIL ?>
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
