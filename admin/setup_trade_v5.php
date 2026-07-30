<?php
// ============================================================
//  Creamy Bite – Setup v5: Trade Passwords & Order Tracking
//  Visit: /admin/setup_trade_v5.php once to update DB schema
// ============================================================
session_start();
if (empty($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}
require_once '../config.php';
require_once '../db.php';

$results = [];

$migrations = [
    [
        'table' => 'trade_users',
        'col'   => 'raw_password',
        'sql'   => "ALTER TABLE `trade_users` ADD COLUMN `raw_password` VARCHAR(255) NOT NULL DEFAULT '' AFTER `password`"
    ],
    [
        'table' => 'orders',
        'col'   => 'trade_user_id',
        'sql'   => "ALTER TABLE `orders` ADD COLUMN `trade_user_id` INT NOT NULL DEFAULT 0 AFTER `id`"
    ],
    [
        'table' => 'orders',
        'col'   => 'trade_business_name',
        'sql'   => "ALTER TABLE `orders` ADD COLUMN `trade_business_name` VARCHAR(180) NOT NULL DEFAULT '' AFTER `trade_user_id`"
    ],
    [
        'table' => 'product_variants',
        'col'   => 'wholesale_price',
        'sql'   => "ALTER TABLE `product_variants` ADD COLUMN `wholesale_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `price`"
    ],
];

foreach ($migrations as $m) {
    try {
        $check = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $check->execute([$m['table'], $m['col']]);
        if ((int)$check->fetchColumn() > 0) {
            $results[] = ['table' => $m['table'], 'col' => $m['col'], 'status' => 'already exists ✓', 'ok' => true];
        } else {
            $pdo->exec($m['sql']);
            $results[] = ['table' => $m['table'], 'col' => $m['col'], 'status' => '✅ column added', 'ok' => true];
        }
    } catch (PDOException $e) {
        $results[] = ['table' => $m['table'], 'col' => $m['col'], 'status' => '❌ ' . $e->getMessage(), 'ok' => false];
    }
}

$allOk = array_reduce($results, fn($c, $r) => $c && $r['ok'], true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Setup v5 – Trade & B2B Database Migration</title>
    <link rel="stylesheet" href="../style.css">
</head>
<body class="admin-wrapper" style="padding:40px 20px; background:#fff7ef;">
<div style="max-width:560px; margin:0 auto;">
    <div class="glass-panel" style="padding:32px; border-radius:16px; box-shadow:0 10px 30px rgba(0,0,0,0.08);">
        <h1 style="font-size:22px; margin:0 0 16px; color:#5C1D24;">⚙️ Setup v5 – Trade & B2B Columns</h1>
        <p style="font-size:14px; color:#666; margin-bottom:20px;">Adding raw_password to trade_users and trade_user_id / trade_business_name to orders.</p>
        <table style="width:100%; border-collapse:collapse; font-size:13px; margin-bottom:20px;">
            <?php foreach ($results as $r): ?>
            <tr style="border-bottom:1px solid #eee;">
                <td style="padding:10px 8px; font-weight:700; color:#5C1D24;"><?= htmlspecialchars($r['table']) ?></td>
                <td style="padding:10px 8px; font-family:monospace;"><?= htmlspecialchars($r['col']) ?></td>
                <td style="padding:10px 8px; color:<?= $r['ok'] ? '#10b981' : '#ef4444' ?>; font-weight:700;"><?= htmlspecialchars($r['status']) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <p style="color:<?= $allOk ? '#10b981' : '#ef4444' ?>; font-weight:700; font-size:15px; margin-bottom:20px;">
            <?= $allOk ? '✅ Setup v5 Complete! All database columns ready.' : '❌ Some migration errors occurred.' ?>
        </p>
        <a href="index.php?tab=trade" class="btn-primary" style="display:inline-flex; align-items:center; gap:8px; padding:10px 20px;">
            <i class="fa-solid fa-arrow-left"></i> Back to Admin Dashboard
        </a>
    </div>
</div>
</body>
</html>
