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

require_once '../config.php';
require_once '../db.php';

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

// Security: Trade users can only view their own orders
if (!$isAdmin && $isTradeUser) {
    $tu = $_SESSION['trade_user'];
    $allowed = ((int)($order['trade_user_id'] ?? 0) === (int)$tu['id']) ||
               (strtolower($order['customer_email']) === strtolower($tu['email'])) ||
               ($order['phone'] === $tu['phone']);
    if (!$allowed) {
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

<div class="no-print" style="max-width:800px; margin:0 auto 20px; text-align:right;">
    <button onclick="window.print()" style="background:#5C1D24; color:white; border:none; padding:10px 20px; font-weight:700; border-radius:8px; cursor:pointer; font-size:14px;">
        🖨️ Print Delivery Note / Invoice
    </button>
</div>

<div class="dn-container">
    <div class="dn-header">
        <div>
            <div class="dn-logo">Creamy Bite 🍦</div>
            <div style="font-size:13px; color:#6b7280; margin-top:2px;">Authentic Artisanal Ice Cream Wholesale</div>
            <div style="font-size:12px; color:#6b7280;">Unit E5 Phoenix Business Centre, HA1 2SP | Tel: +44 7497 779997</div>
        </div>
        <div style="text-align:right;">
            <h1 style="margin:0; font-size:24px; color:#111827;">B2B DELIVERY NOTE</h1>
            <div class="dn-badge"><?= $isTrade ? 'TRADE WHOLESALE' : 'RETAIL ORDER' ?></div>
            <div style="margin-top:8px; font-weight:800; font-family:monospace; font-size:15px; color:#5C1D24;">
                <?= htmlspecialchars($order['order_code']) ?>
            </div>
            <div style="font-size:12px; color:#6b7280; margin-top:2px;">
                Date: <?= date('d F Y - H:i', strtotime($order['created_at'])) ?>
            </div>
        </div>
    </div>

    <div class="dn-grid">
        <div class="dn-card">
            <h3>DELIVER TO (RETAIL STORE)</h3>
            <strong style="font-size:16px; color:#111827; display:block; margin-bottom:4px;">
                🏬 <?= htmlspecialchars($tradeStoreName) ?>
            </strong>
            <div style="font-weight:600; color:#374151;">Contact Person: <?= htmlspecialchars($order['customer_name']) ?></div>
            <div style="color:#4b5563; font-size:13px; margin-top:2px;">Phone: <?= htmlspecialchars($order['phone']) ?></div>
            <?php if (!empty($order['customer_email'])): ?>
            <div style="color:#4b5563; font-size:13px;">Email: <?= htmlspecialchars($order['customer_email']) ?></div>
            <?php endif; ?>
            <div style="margin-top:8px; padding-top:8px; border-top:1px solid #e5e7eb; font-size:13px; color:#111827;">
                <strong>Store Address:</strong><br>
                <?= htmlspecialchars($order['address']) ?><br>
                <strong style="color:#5C1D24; font-family:monospace;"><?= htmlspecialchars($order['postcode']) ?></strong>
            </div>
        </div>

        <div class="dn-card">
            <h3>ORDER & PAYMENT DETAILS</h3>
            <div style="margin-bottom:8px;">
                <span style="color:#6b7280; font-size:12px;">Payment Status:</span><br>
                <?php if ($order['payment_status'] === 'Paid'): ?>
                <strong style="color:#047857; background:#ecfdf5; padding:3px 10px; border-radius:12px; font-size:13px; display:inline-block;">✅ PAID ONLINE</strong>
                <?php elseif ($order['payment_status'] === 'Cash'): ?>
                <strong style="color:#b45309; background:#fffbeb; padding:3px 10px; border-radius:12px; font-size:13px; display:inline-block;">💵 CASH ON DELIVERY</strong>
                <?php else: ?>
                <strong style="color:#b45309; background:#fffbeb; padding:3px 10px; border-radius:12px; font-size:13px; display:inline-block;">⏳ INVOICE PENDING (PAY ON DELIVERY)</strong>
                <?php endif; ?>
            </div>
            <div style="margin-top:12px;">
                <strong style="color:#111827; font-size:13px; display:block; margin-bottom:4px;">⏱️ Opening Times & Instructions:</strong>
                <div style="background:#fff; border:1px solid #e5e7eb; padding:8px 12px; border-radius:6px; font-size:12px; color:#374151; white-space:pre-wrap; font-family:monospace;">
                    <?= !empty($order['notes']) ? htmlspecialchars($order['notes']) : 'Standard store delivery' ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Items Table -->
    <table class="dn-table">
        <thead>
            <tr>
                <th style="width:40px;">#</th>
                <th>Item / Flavour Description</th>
                <th style="text-align:center; width:80px;">Qty Tubs</th>
                <th style="text-align:right; width:100px;">Trade Price</th>
                <th style="text-align:right; width:110px;">Subtotal</th>
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
                    <strong style="font-size:14px; color:#111827;"><?= htmlspecialchars($item['emoji'] ?? '🍦') ?> <?= htmlspecialchars($item['name']) ?></strong>
                    <?php if (!empty($item['variant_name'])): ?>
                    <div style="font-size:12px; color:#6b7280;"><?= htmlspecialchars($item['variant_name']) ?></div>
                    <?php endif; ?>
                </td>
                <td style="text-align:center; font-weight:800; font-size:15px; color:#5C1D24;"><?= (int)$item['quantity'] ?></td>
                <td style="text-align:right;">£<?= number_format((float)$item['price'], 2) ?></td>
                <td style="text-align:right; font-weight:700;">£<?= number_format($lineTotal, 2) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="dn-totals">
        <div style="display:flex; justify-content:space-between; padding:4px 0; color:#4b5563;">
            <span>Subtotal:</span>
            <span>£<?= number_format((float)$order['total_price'] - (float)($order['delivery_charge'] ?? 0), 2) ?></span>
        </div>
        <div style="display:flex; justify-content:space-between; padding:4px 0; color:#4b5563;">
            <span>Delivery:</span>
            <span><?= (float)($order['delivery_charge'] ?? 0) > 0 ? '£' . number_format((float)$order['delivery_charge'], 2) : 'FREE' ?></span>
        </div>
        <div class="grand-total" style="display:flex; justify-content:space-between;">
            <span>TOTAL DUE:</span>
            <span>£<?= number_format((float)$order['total_price'], 2) ?></span>
        </div>
    </div>

    <!-- Signatures Block -->
    <div class="dn-signatures">
        <div>
            <div style="font-weight:700; font-size:12px; color:#374151;">DRIVER / DELIVERED BY</div>
            <div class="sig-box"></div>
            <div style="font-size:11px; color:#9ca3af; margin-top:4px;">Signature & Date</div>
        </div>
        <div>
            <div style="font-weight:700; font-size:12px; color:#374151;">RECEIVED BY (STORE ACCEPTANCE)</div>
            <div class="sig-box"></div>
            <div style="font-size:11px; color:#9ca3af; margin-top:4px;">Print Name & Signature</div>
        </div>
    </div>
</div>

</body>
</html>
