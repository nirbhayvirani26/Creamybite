<?php
// ============================================================
// Creamy Bite – B2B Trade Delivery Note & Invoice
// URL: /admin/delivery_note.php?code=SCO-123456
// ============================================================
session_start();
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
    <!-- This page carried no stylesheet link; the .cbdn-* classes extracted from the
         former inline styles live in admin.css. Linked before the <style> block below
         so the page's own rules keep winning any equal-specificity tie. -->
    <link rel="stylesheet" href="assets/css/admin.css">
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif;
            color: #1f2937;
            margin: 0;
            padding: 40px;
            background: #fff;
            font-size: 14px;
            line-height: 1.5;
        }
        .dn-container {
            max-width: 800px;
            margin: 0 auto;
            border: 1px solid #e5e7eb;
            padding: 40px;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.04);
        }
        .dn-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 2px solid #5C1D24;
            padding-bottom: 20px;
            margin-bottom: 24px;
        }
        .dn-logo {
            font-size: 26px;
            font-weight: 900;
            color: #5C1D24;
            letter-spacing: -0.5px;
        }
        .dn-badge {
            background: #5C1D24;
            color: #fff;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            display: inline-block;
            margin-top: 4px;
        }
        .dn-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            margin-bottom: 28px;
        }
        .dn-card {
            background: #f9fafb;
            border: 1px solid #f3f4f6;
            padding: 16px;
            border-radius: 8px;
        }
        .dn-card h3 {
            margin: 0 0 10px;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #6b7280;
        }
        .dn-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 28px;
        }
        .dn-table th {
            background: #f3f4f6;
            color: #374151;
            text-align: left;
            padding: 10px 14px;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .dn-table td {
            padding: 12px 14px;
            border-bottom: 1px solid #e5e7eb;
        }
        .dn-totals {
            width: 260px;
            margin-left: auto;
            margin-bottom: 36px;
        }
        .dn-totals row {
            display: flex;
            justify-content: space-between;
            padding: 6px 0;
            font-size: 14px;
        }
        .dn-totals .grand-total {
            font-weight: 800;
            font-size: 18px;
            color: #5C1D24;
            border-top: 2px solid #5C1D24;
            padding-top: 10px;
            margin-top: 6px;
        }
        .dn-signatures {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 40px;
            margin-top: 40px;
            padding-top: 24px;
            border-top: 1px dashed #d1d5db;
        }
        .sig-box {
            border-bottom: 1px solid #9ca3af;
            height: 40px;
            margin-top: 10px;
        }
        @media print {
            body { padding: 0; background: #fff; }
            .dn-container { border: none; box-shadow: none; padding: 0; }
            .no-print { display: none !important; }
        }
    </style>
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
            <div class="dn-logo">Creamy Bite 🍦</div>
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
            <div class="sig-box"></div>
            <div class="cbdn-sig-hint">Signature & Date</div>
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
