<?php
// ============================================================
//  Creamy Bite – Setup v3: Add postcode + delivery_charge to orders
//  Visit: /admin/setup_stock_v3.php once to run
// ============================================================
require_once '../config.php';
require_once '../db.php';

$results = [];
$migrations = [
    ['table' => 'orders',   'col' => 'postcode',        'sql' => "ALTER TABLE `orders` ADD COLUMN `postcode` VARCHAR(10) NOT NULL DEFAULT '' AFTER `address`"],
    ['table' => 'orders',   'col' => 'delivery_charge', 'sql' => "ALTER TABLE `orders` ADD COLUMN `delivery_charge` DECIMAL(6,2) NOT NULL DEFAULT 0.00 AFTER `discount_amount`"],
];

foreach ($migrations as $m) {
    try {
        $check = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $check->execute([$m['table'], $m['col']]);
        if ((int)$check->fetchColumn()) {
            $results[] = ['col' => $m['col'], 'status' => 'already exists ✓', 'ok' => true];
        } else {
            $pdo->exec($m['sql']);
            $results[] = ['col' => $m['col'], 'status' => '✅ added', 'ok' => true];
        }
    } catch (PDOException $e) {
        $results[] = ['col' => $m['col'], 'status' => '❌ ' . $e->getMessage(), 'ok' => false];
    }
}

$allOk = array_reduce($results, fn($c, $r) => $c && $r['ok'], true);
?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><title>Setup v3</title><link rel="stylesheet" href="../style.css"></head>
<body class="admin-wrapper" style="padding:40px 20px;">
<div style="max-width:500px; margin:0 auto;">
    <div class="glass-panel" style="padding:32px;">
        <h1 style="font-size:20px; margin:0 0 20px;">⚙️ Setup v3 – Delivery Columns</h1>
        <table style="width:100%; border-collapse:collapse; font-size:13px; margin-bottom:20px;">
            <?php foreach ($results as $r): ?>
            <tr style="border-bottom:1px solid var(--border-light);">
                <td style="padding:8px; font-family:monospace; font-weight:700;"><?= htmlspecialchars($r['col']) ?></td>
                <td style="padding:8px; color:<?= $r['ok'] ? '#10b981' : '#ef4444' ?>;"><?= htmlspecialchars($r['status']) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <p style="color:<?= $allOk ? '#10b981' : '#ef4444' ?>; font-weight:700;"><?= $allOk ? '✅ All done! Delivery columns ready.' : '❌ Some errors occurred.' ?></p>
        <a href="index.php" class="btn-primary" style="display:inline-flex; margin-top:12px;"><i class="fa-solid fa-arrow-left"></i> Back to Admin</a>
    </div>
</div>
</body></html>
