<?php
// ============================================================
//  Creamy Bite – update_db.php
//  Brings the LIVE database schema up to parity with local:
//  every table/column added by setup_v6 .. setup_v12 in one place.
//  Safe to run more than once — every step checks first and is
//  skipped if already present.
//  Visit: /admin/update_db.php
// ============================================================
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

$results = [];

function cb_column_exists(PDO $pdo, string $table, string $col): bool {
    $q = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $q->execute([$table, $col]);
    return (int)$q->fetchColumn() > 0;
}

function cb_table_exists(PDO $pdo, string $table): bool {
    $q = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $q->execute([$table]);
    return (int)$q->fetchColumn() > 0;
}

// ── 1. Tables that must exist (CREATE TABLE IF NOT EXISTS is safe on its own) ──
$tables = [
    'product_variants' => "CREATE TABLE IF NOT EXISTS `product_variants` (
        `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
        `product_id`      INT UNSIGNED  NOT NULL,
        `name`            VARCHAR(100)  NOT NULL,
        `price`           DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        `wholesale_price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        `available`       TINYINT(1)    NOT NULL DEFAULT 1,
        `sort_order`      INT           NOT NULL DEFAULT 0,
        PRIMARY KEY (`id`),
        KEY `idx_product` (`product_id`, `sort_order`),
        CONSTRAINT `fk_variant_product`
          FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

    'trade_users' => "CREATE TABLE IF NOT EXISTS `trade_users` (
        `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `business_name` VARCHAR(180) NOT NULL,
        `contact_name`  VARCHAR(150) NOT NULL,
        `email`         VARCHAR(180) NOT NULL,
        `password`      VARCHAR(255) NOT NULL,
        `raw_password`  VARCHAR(255) NOT NULL DEFAULT '',
        `phone`         VARCHAR(30)  NOT NULL,
        `address`       TEXT         NOT NULL,
        `postcode`      VARCHAR(10)  NOT NULL DEFAULT '',
        `vat_number`    VARCHAR(50)  NOT NULL DEFAULT '',
        `status`        ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
        `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_email` (`email`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

    'trade_carts' => "CREATE TABLE IF NOT EXISTS `trade_carts` (
        `trade_user_id` INT UNSIGNED NOT NULL,
        `cart_json`     LONGTEXT     NOT NULL,
        `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`trade_user_id`),
        CONSTRAINT `fk_trade_cart_user`
          FOREIGN KEY (`trade_user_id`) REFERENCES `trade_users` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

    'invoices' => "CREATE TABLE IF NOT EXISTS `invoices` (
        `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `invoice_number` VARCHAR(40)  NOT NULL,
        `order_id`       INT UNSIGNED NULL,
        `trade_user_id`  INT UNSIGNED NOT NULL DEFAULT 0,
        `status`         ENUM('draft','sent','part_paid','paid','void') NOT NULL DEFAULT 'draft',
        `issue_date`     DATE         NOT NULL,
        `due_terms`      VARCHAR(60)  NOT NULL DEFAULT 'On Receipt',
        `due_date`       DATE         NULL,
        `currency`       VARCHAR(8)   NOT NULL DEFAULT 'GBP',
        `from_name`      VARCHAR(180) NOT NULL DEFAULT '',
        `from_address`   TEXT         NOT NULL,
        `from_phone`     VARCHAR(40)  NOT NULL DEFAULT '',
        `from_email`     VARCHAR(180) NOT NULL DEFAULT '',
        `from_website`   VARCHAR(180) NOT NULL DEFAULT '',
        `to_name`        VARCHAR(180) NOT NULL DEFAULT '',
        `to_address`     TEXT         NOT NULL,
        `to_email`       VARCHAR(180) NOT NULL DEFAULT '',
        `to_phone`       VARCHAR(40)  NOT NULL DEFAULT '',
        `to_vat_number`  VARCHAR(50)  NOT NULL DEFAULT '',
        `payment_instructions` TEXT NOT NULL,
        `notes`                TEXT NOT NULL,
        `subtotal`       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        `discount`       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        `discount_type`  ENUM('fixed','percent') NOT NULL DEFAULT 'fixed',
        `discount_value` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        `delivery`       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        `vat_rate`       DECIMAL(5,4)  NOT NULL DEFAULT 0.0000,
        `vat_amount`     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        `total`          DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        `amount_paid`    DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_invoice_number` (`invoice_number`),
        KEY `idx_order`  (`order_id`),
        KEY `idx_status` (`status`, `issue_date`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

    'invoice_items' => "CREATE TABLE IF NOT EXISTS `invoice_items` (
        `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `invoice_id`  INT UNSIGNED NOT NULL,
        `description` VARCHAR(255)  NOT NULL,
        `rate`        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        `rate_note`   VARCHAR(120)  NOT NULL DEFAULT '',
        `qty`         DECIMAL(10,3) NOT NULL DEFAULT 1.000,
        `qty_unit`    VARCHAR(30)   NOT NULL DEFAULT '',
        `amount`      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        `sort_order`  INT           NOT NULL DEFAULT 0,
        PRIMARY KEY (`id`),
        KEY `idx_invoice` (`invoice_id`, `sort_order`),
        CONSTRAINT `fk_invoice_item`
          FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

    'invoice_payments' => "CREATE TABLE IF NOT EXISTS `invoice_payments` (
        `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `invoice_id` INT UNSIGNED NOT NULL,
        `paid_on`    DATE          NOT NULL,
        `amount`     DECIMAL(10,2) NOT NULL,
        `method`     VARCHAR(40)   NOT NULL DEFAULT 'Bank Transfer',
        `reference`  VARCHAR(120)  NOT NULL DEFAULT '',
        `created_at` DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_invoice_payment` (`invoice_id`),
        CONSTRAINT `fk_invoice_payment`
          FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

    'invoice_settings' => "CREATE TABLE IF NOT EXISTS `invoice_settings` (
        `id`                   TINYINT UNSIGNED NOT NULL DEFAULT 1,
        `number_prefix`        VARCHAR(12)  NOT NULL DEFAULT 'INV',
        `number_padding`       TINYINT      NOT NULL DEFAULT 4,
        `next_number`          INT UNSIGNED NOT NULL DEFAULT 1,
        `from_name`            VARCHAR(180) NOT NULL DEFAULT '',
        `from_address`         TEXT         NOT NULL,
        `from_phone`           VARCHAR(40)  NOT NULL DEFAULT '',
        `from_email`           VARCHAR(180) NOT NULL DEFAULT '',
        `from_website`         VARCHAR(180) NOT NULL DEFAULT '',
        `payment_instructions` TEXT         NOT NULL,
        `default_terms`        VARCHAR(60)  NOT NULL DEFAULT 'On Receipt',
        `default_vat_rate`     DECIMAL(5,4) NOT NULL DEFAULT 0.0000,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",
];

foreach ($tables as $name => $sql) {
    try {
        $existed = cb_table_exists($pdo, $name);
        $pdo->exec($sql);
        $results[] = ['table' => $name, 'col' => '(table)', 'status' => $existed ? 'already exists ✓' : '✅ table created', 'ok' => true];
    } catch (PDOException $e) {
        $results[] = ['table' => $name, 'col' => '(table)', 'status' => '❌ ' . $e->getMessage(), 'ok' => false];
    }
}

// ── 2. Columns added on top of the base schema.sql tables ──
$columns = [
    ['products',    'wholesale_price', "ALTER TABLE `products` ADD COLUMN `wholesale_price` DECIMAL(8,2) NOT NULL DEFAULT 0.00 AFTER `price`"],
    ['products',    'badge',           "ALTER TABLE `products` ADD COLUMN `badge` VARCHAR(50) NOT NULL DEFAULT '' AFTER `image`"],
    ['products',    'trade_only',      "ALTER TABLE `products` ADD COLUMN `trade_only` TINYINT(1) NOT NULL DEFAULT 0 AFTER `available`"],
    ['products',    'nuts_allergy',    "ALTER TABLE `products` ADD COLUMN `nuts_allergy` TINYINT(1) NOT NULL DEFAULT 0 AFTER `available`"],
    ['orders',      'trade_user_id',       "ALTER TABLE `orders` ADD COLUMN `trade_user_id` INT NOT NULL DEFAULT 0 AFTER `id`"],
    ['orders',      'trade_business_name', "ALTER TABLE `orders` ADD COLUMN `trade_business_name` VARCHAR(180) NOT NULL DEFAULT '' AFTER `trade_user_id`"],
    ['orders',      'vat_amount',      "ALTER TABLE `orders` ADD COLUMN `vat_amount` DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER `delivery_charge`"],
    ['orders',      'vat_number',      "ALTER TABLE `orders` ADD COLUMN `vat_number` VARCHAR(50) NOT NULL DEFAULT '' AFTER `vat_amount`"],
    ['orders',      'stock_deducted',  "ALTER TABLE `orders` ADD COLUMN `stock_deducted` TINYINT(1) NOT NULL DEFAULT 0 AFTER `status`"],
    ['promo_codes', 'description',     "ALTER TABLE `promo_codes` ADD COLUMN `description` VARCHAR(255) NOT NULL DEFAULT '' AFTER `code`"],
];

foreach ($columns as [$table, $col, $sql]) {
    try {
        if (cb_column_exists($pdo, $table, $col)) {
            $results[] = ['table' => $table, 'col' => $col, 'status' => 'already exists ✓', 'ok' => true];
        } else {
            $pdo->exec($sql);
            $results[] = ['table' => $table, 'col' => $col, 'status' => '✅ column added', 'ok' => true];
        }
    } catch (PDOException $e) {
        $results[] = ['table' => $table, 'col' => $col, 'status' => '❌ ' . $e->getMessage(), 'ok' => false];
    }
}

// ── 3. gallery: image/title -> filename/caption rename ──
try {
    if (cb_column_exists($pdo, 'gallery', 'filename')) {
        $results[] = ['table' => 'gallery', 'col' => 'filename/caption', 'status' => 'already exists ✓', 'ok' => true];
    } elseif (cb_column_exists($pdo, 'gallery', 'image')) {
        $pdo->exec("ALTER TABLE `gallery` CHANGE `image` `filename` VARCHAR(255) NOT NULL, CHANGE `title` `caption` VARCHAR(150) NOT NULL DEFAULT ''");
        $results[] = ['table' => 'gallery', 'col' => 'filename/caption', 'status' => '✅ columns renamed', 'ok' => true];
    } else {
        $results[] = ['table' => 'gallery', 'col' => 'filename/caption', 'status' => '⚠️ neither image nor filename column found — check manually', 'ok' => false];
    }
} catch (PDOException $e) {
    $results[] = ['table' => 'gallery', 'col' => 'filename/caption', 'status' => '❌ ' . $e->getMessage(), 'ok' => false];
}

// ── 4. Seed the one invoice_settings row if missing ──
try {
    $pdo->exec("INSERT INTO `invoice_settings`
        (`id`, `number_prefix`, `number_padding`, `next_number`,
         `from_name`, `from_address`, `from_phone`, `from_email`, `from_website`,
         `payment_instructions`, `default_terms`, `default_vat_rate`)
        VALUES
        (1, 'INV', 4, 226,
         'Creamy Bite',
         'Unit E5 Phoenix House, Rosslyn Cres\nHarrow HA1 2SP\nLondon, UK',
         '+44 7459 814068',
         'hello@creamybite.com',
         'https://creamybite.com',
         'HNMP Ltd T/A Creamy Bite\nAccount Number: 70992323\nSort Code: 04-00-03',
         'On Receipt',
         0.0000)
        ON DUPLICATE KEY UPDATE `id` = `id`");
    $results[] = ['table' => 'invoice_settings', 'col' => '(seed row)', 'status' => '✅ present / seeded', 'ok' => true];
} catch (PDOException $e) {
    $results[] = ['table' => 'invoice_settings', 'col' => '(seed row)', 'status' => '❌ ' . $e->getMessage(), 'ok' => false];
}

$allOk = array_reduce($results, fn($c, $r) => $c && $r['ok'], true);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Update DB – Schema Parity (v6&ndash;v12)</title>
    <link rel="stylesheet" href="../style.css">
    <link rel="stylesheet" href="setup.css">
    <link rel="stylesheet" href="../modal.css">
    <script src="../modal.js" defer></script>
</head>
<body class="admin-wrapper su-page-warm">
<div class="su-wrap">
    <div class="glass-panel su-card">
        <h1 class="su-h1">⚙️ Update DB – Bring Schema Up To Date</h1>
        <p class="su-lead">Environment: <strong><?= IS_LOCAL ? 'LOCAL' : 'LIVE' ?></strong> (<?= htmlspecialchars(DB_NAME) ?>). Applies every table/column from setup v6&ndash;v12 that is still missing. Safe to run again.</p>
        <table class="su-table">
            <?php foreach ($results as $r): ?>
            <tr style="border-bottom:1px solid #eee;">
                <td style="padding:10px 8px; font-weight:700; color:#5C1D24;"><?= htmlspecialchars($r['table']) ?></td>
                <td style="padding:10px 8px; font-family:monospace;"><?= htmlspecialchars($r['col']) ?></td>
                <td style="padding:10px 8px; color:<?= $r['ok'] ? '#10b981' : '#ef4444' ?>; font-weight:700;"><?= htmlspecialchars($r['status']) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <p style="color:<?= $allOk ? '#10b981' : '#ef4444' ?>; font-weight:700; font-size:15px; margin-bottom:20px;">
            <?= $allOk ? '✅ Database schema is fully up to date.' : '❌ Some steps failed — check the messages above.' ?>
        </p>
        <a href="index.php" class="btn-primary" style="display:inline-flex; align-items:center; gap:8px; padding:10px 20px;">
            <i class="fa-solid fa-arrow-left"></i> Back to Admin Dashboard
        </a>
    </div>
</div>
</body>
</html>
