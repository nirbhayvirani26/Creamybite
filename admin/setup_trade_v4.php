<?php
// ============================================================
// Creamy Bite – Setup v4: Trade B2B Accounts & Wholesale Prices
// Visit: /admin/setup_trade_v4.php to migrate live database
// ============================================================
session_start();
require_once __DIR__ . '/../config.php';

$results = [];

try {
    require_once __DIR__ . '/../db.php';

    // 1. Create trade_users table
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `trade_users` (
          `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
          `business_name` VARCHAR(180) NOT NULL,
          `contact_name`  VARCHAR(150) NOT NULL,
          `email`         VARCHAR(180) NOT NULL UNIQUE,
          `password`      VARCHAR(255) NOT NULL,
          `phone`         VARCHAR(30)  NOT NULL,
          `address`       TEXT         NOT NULL,
          `postcode`      VARCHAR(10)  NOT NULL DEFAULT '',
          `vat_number`    VARCHAR(50)  NOT NULL DEFAULT '',
          `status`        ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
          `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $results[] = ['item' => 'trade_users Table', 'status' => '✅ Created / Verified', 'ok' => true];
    } catch (PDOException $e) {
        $results[] = ['item' => 'trade_users Table', 'status' => '❌ ' . $e->getMessage(), 'ok' => false];
    }

    // 2. Add wholesale_price column to products table
    try {
        $check = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'wholesale_price'");
        $check->execute();
        if ((int)$check->fetchColumn() === 0) {
            $pdo->exec("ALTER TABLE `products` ADD COLUMN `wholesale_price` DECIMAL(8,2) NOT NULL DEFAULT 0.00 AFTER `price`");
            $results[] = ['item' => 'products.wholesale_price Column', 'status' => '✅ Column Added', 'ok' => true];
        } else {
            $results[] = ['item' => 'products.wholesale_price Column', 'status' => 'already exists ✓', 'ok' => true];
        }
    } catch (PDOException $e) {
        $results[] = ['item' => 'products.wholesale_price Column', 'status' => '❌ ' . $e->getMessage(), 'ok' => false];
    }

} catch (Throwable $e) {
    $error = $e->getMessage();
}

$allOk = empty($error) && array_reduce($results, fn($c, $r) => $c && $r['ok'], true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Setup v4 – Trade B2B & Wholesale Setup</title>
    <link rel="stylesheet" href="../style.css">
</head>
<body class="admin-wrapper" style="padding:40px 20px;">
<div style="max-width:550px; margin:0 auto;" class="glass-panel">
    <h2 style="margin-top:0;">⚙️ Setup v4 – Trade B2B Migration</h2>
    <?php if (isset($error)): ?>
        <p style="color:#ef4444; font-weight:700;">❌ Database connection error:</p>
        <div style="background:#fee2e2; color:#991b1b; padding:12px; border-radius:8px; font-family:monospace; font-size:13px;">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php else: ?>
        <table style="width:100%; border-collapse:collapse; font-size:13px; margin:16px 0;">
            <?php foreach ($results as $r): ?>
            <tr style="border-bottom:1px solid var(--border-light);">
                <td style="padding:10px 8px; font-weight:700;"><?= htmlspecialchars($r['item']) ?></td>
                <td style="padding:10px 8px; color:<?= $r['ok'] ? '#10b981' : '#ef4444' ?>;"><?= htmlspecialchars($r['status']) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <p style="color:<?= $allOk ? '#10b981' : '#ef4444' ?>; font-weight:700;">
            <?= $allOk ? '✅ Setup Complete! Trade Accounts and Wholesale Prices are ready.' : '❌ Some migration errors occurred.' ?>
        </p>
        <div style="margin-top:20px; display:flex; gap:12px;">
            <a href="index.php?tab=trade" class="btn-primary">Go to Admin Trade Tab</a>
            <a href="../trade_register" class="btn-secondary">View Trade Registration Form</a>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
