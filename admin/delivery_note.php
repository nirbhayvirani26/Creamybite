<?php
// ============================================================
// Creamy Bite – B2B Trade Delivery Note & Invoice
// URL: /admin/delivery_note.php?code=SCO-123456
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$isAdmin     = !empty($_SESSION['admin_logged_in']);
$isTradeUser = !empty($_SESSION['trade_user']);

if (!$isAdmin && !$isTradeUser) {
    header('Location: ../trade_login.php');
    exit;
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

$code = trim($_GET['code'] ?? '');
if (empty($code)) {
    die('Order code required.');
}

$stmt = $pdo->prepare("SELECT * FROM orders WHERE order_code = :code LIMIT 1");
$stmt->execute(['code' => $code]);
$order = $stmt->fetch();

if (!$order) {
    die('Order not found.');
}

// Who delivered it, if it has been marked Delivered against a named person.
//
// Looked up separately rather than joined so the note still prints on a
// server whose sales_reps table has not been created yet — a delivery note
// that will not print is worse than one missing a name.
$deliveredBy = '';
if (!empty($order['sales_rep_id'])) {
    try {
        $repStmt = $pdo->prepare("SELECT name FROM sales_reps WHERE id = :id");
        $repStmt->execute(['id' => (int)$order['sales_rep_id']]);
        $deliveredBy = (string)($repStmt->fetchColumn() ?: '');
    } catch (PDOException $e) {
        $deliveredBy = '';
    }
}

// Security: trade users can only view their OWN orders.
//
// This used to also accept a matching customer_email or phone. Those are
// values the partner controls — editing their profile phone number to match
// another customer's order was enough to print that customer's delivery note,
// with their name and full address on it. Ownership is trade_user_id alone.
if (!$isAdmin) {
    if (!$isTradeUser) {
        die('Access denied.');
    }
    $tu      = $_SESSION['trade_user'];
    $ownerId = (int)($order['trade_user_id'] ?? 0);
    if ($ownerId <= 0 || $ownerId !== (int)$tu['id']) {
        die('Access denied: You do not have permission to view this delivery note.');
    }
}

$items = json_decode($order['items_json'], true) ?? [];
$isTrade = !empty($order['trade_business_name']) || strpos($order['notes'], 'TRADE B2B ORDER') !== false;

$tradeStoreName = $order['trade_business_name'] ?: 'B2B Trade Customer';
if (empty($order['trade_business_name']) && preg_match('/Store:\s*([^\]]+)/i', $order['notes'], $m)) {
    $tradeStoreName = trim($m[1]);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Delivery Note & Invoice – <?= htmlspecialchars($order['order_code']) ?></title>
    <!-- Shared .cbdn-* classes come from admin.css; this page's own print rules
         follow in delivery-note.css, so they win any equal-specificity tie. -->
    <link rel="stylesheet" href="assets/css/admin.css">
    <link rel="stylesheet" href="assets/css/delivery-note.css">
</head>
<body>

<div class="no-print cbdn-print-bar">
    <button onclick="window.print()" class="cbdn-print-btn">
        🖨️ Print Delivery Note / Invoice
    </button>
</div>

<div class="dn-container">
    <div class="dn-header">
        <div>
            <?php
            // Logo, with the shop name as the fallback if the file is missing —
            // same approach as the invoice (admin/invoice_view.php).
            $dnLogo = __DIR__ . '/../assets/images/logo.png';
            if (is_file($dnLogo)):
            ?>
            <img src="../assets/images/logo.png" alt="<?= htmlspecialchars(SHOP_NAME) ?>" class="dn-logo-img">
            <?php else: ?>
            <div class="dn-logo"><?= htmlspecialchars(SHOP_NAME) ?></div>
            <?php endif; ?>
            <div class="cbdn-company-tagline">Authentic Artisanal Ice Cream Wholesale</div>
            <div class="cbdn-muted-note">Unit E5 Phoenix Business Centre, HA1 2SP | Tel: +44 7497 779997</div>
        </div>
        <div class="cbdn-align-right">
            <h1 class="cbdn-doc-title">B2B DELIVERY NOTE</h1>
            <div class="dn-badge"><?= $isTrade ? 'TRADE WHOLESALE' : 'RETAIL ORDER' ?></div>
            <div class="cbdn-doc-code">
                <?= htmlspecialchars($order['order_code']) ?>
            </div>
            <div class="cbdn-doc-date">
                Date: <?= date('d F Y - H:i', strtotime($order['created_at'])) ?>
            </div>
        </div>
    </div>

    <div class="dn-grid">
        <div class="dn-card">
            <h3>DELIVER TO (RETAIL STORE)</h3>
            <strong class="cbdn-store-name">
                🏬 <?= htmlspecialchars($tradeStoreName) ?>
            </strong>
            <div class="cbdn-contact-line">Contact Person: <?= htmlspecialchars($order['customer_name']) ?></div>
            <div class="cbdn-contact-phone">Phone: <?= htmlspecialchars($order['phone']) ?></div>
            <?php if (!empty($order['customer_email'])): ?>
            <div class="cbdn-contact-email">Email: <?= htmlspecialchars($order['customer_email']) ?></div>
            <?php endif; ?>
            <div class="cbdn-address-block">
                <strong>Store Address:</strong><br>
                <?= htmlspecialchars($order['address']) ?><br>
                <strong class="cbdn-postcode"><?= htmlspecialchars($order['postcode']) ?></strong>
            </div>
        </div>

        <div class="dn-card">
            <h3>ORDER & PAYMENT DETAILS</h3>
            <div class="cbdn-field">
                <span class="cbdn-field-label">Payment Status:</span><br>
                <?php if ($order['payment_status'] === 'Paid'): ?>
                <strong class="cbdn-pay-badge cbdn-pay-badge-paid">✅ PAID ONLINE</strong>
                <?php elseif ($order['payment_status'] === 'Cash'): ?>
                <strong class="cbdn-pay-badge cbdn-pay-badge-pending">💵 CASH ON DELIVERY</strong>
                <?php else: ?>
                <strong class="cbdn-pay-badge cbdn-pay-badge-pending">⏳ INVOICE PENDING (PAY ON DELIVERY)</strong>
                <?php endif; ?>
            </div>
            <div class="cbdn-notes-block">
                <strong class="cbdn-notes-label">⏱️ Opening Times & Instructions:</strong>
                <div class="cbdn-notes-box">
                    <?= !empty($order['notes']) ? htmlspecialchars($order['notes']) : 'Standard store delivery' ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Items Table -->
    <table class="dn-table">
        <thead>
            <tr>
                <th class="cbdn-col-index">#</th>
                <th>Item / Flavour Description</th>
                <th class="cbdn-col-qty">Qty Tubs</th>
                <th class="cbdn-col-price">Trade Price</th>
                <th class="cbdn-col-subtotal">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $i = 1;
            foreach ($items as $item):
                $lineTotal = (float)$item['price'] * (int)$item['quantity'];
            ?>
            <tr>
                <td><?= $i++ ?></td>
                <td>
                    <strong class="cbdn-item-name"><?= htmlspecialchars($item['emoji'] ?? '🍦') ?> <?= htmlspecialchars($item['name']) ?></strong>
                    <?php if (!empty($item['variant_name'])): ?>
                    <div class="cbdn-muted-note"><?= htmlspecialchars($item['variant_name']) ?></div>
                    <?php endif; ?>
                </td>
                <td class="cbdn-qty-cell"><?= (int)$item['quantity'] ?></td>
                <td class="cbdn-align-right">£<?= number_format((float)$item['price'], 2) ?></td>
                <td class="cbdn-amount-cell">£<?= number_format($lineTotal, 2) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="dn-totals">
        <div class="cbdn-row-split cbdn-total-row">
            <span>Subtotal:</span>
            <span>£<?= number_format((float)$order['total_price'] - (float)($order['delivery_charge'] ?? 0), 2) ?></span>
        </div>
        <div class="cbdn-row-split cbdn-total-row">
            <span>Delivery:</span>
            <span><?= (float)($order['delivery_charge'] ?? 0) > 0 ? '£' . number_format((float)$order['delivery_charge'], 2) : 'FREE' ?></span>
        </div>
        <div class="grand-total cbdn-row-split">
            <span>TOTAL DUE:</span>
            <span>£<?= number_format((float)$order['total_price'], 2) ?></span>
        </div>
    </div>

    <!-- Signatures Block -->
    <div class="dn-signatures">
        <div>
            <div class="cbdn-sig-label">DRIVER / DELIVERED BY</div>
            <?php // Printed into the box when the order has been marked
                  // Delivered against a named person, so the note leaving the
                  // warehouse already says who is taking it. Still leaves room
                  // for the signature underneath. ?>
            <div class="sig-box<?= !empty($deliveredBy) ? ' cbdn-sig-filled' : '' ?>">
                <?= !empty($deliveredBy) ? htmlspecialchars($deliveredBy) : '' ?>
            </div>
            <div class="cbdn-sig-hint">
                <?= !empty($deliveredBy) ? 'Signature &amp; Date' : 'Print Name, Signature &amp; Date' ?>
            </div>
        </div>
        <div>
            <div class="cbdn-sig-label">RECEIVED BY (STORE ACCEPTANCE)</div>
            <div class="sig-box"></div>
            <div class="cbdn-sig-hint">Print Name & Signature</div>
        </div>
    </div>
</div>

</body>
</html>
