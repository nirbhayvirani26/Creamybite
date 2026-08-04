<?php
// ============================================================
//  Creamy Bite – update_db.php
//  Brings the LIVE database schema up to parity with local:
//  every table/column added by setup_v6 .. setup_v12 in one place.
//  Safe to run more than once — every step checks first and is
//  skipped if already present.
//
//  Visit: /admin/migrations/update_db.php
//  (admin/update_db.php is the old address and now only redirects here —
//   that stale URL is why a live database once went un-migrated.)
// ============================================================
require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';

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

/**
 * The exact column type of an existing column, e.g. "int unsigned".
 *
 * InnoDB refuses a foreign key unless the two columns match exactly, and that
 * includes signedness. Hard-coding `INT UNSIGNED` for a child column works
 * only while the parent happens to agree: point it at a database whose
 * products.id is a plain signed INT and CREATE TABLE dies with
 * "1215 Cannot add foreign key constraint" — which is easy to do, because
 * a table imported from an older dump can differ from the schema on disk.
 * Reading the parent's real type keeps the child in step with whatever the
 * database actually has.
 */
function cb_column_type(PDO $pdo, string $table, string $col, string $fallback): string {
    $q = $pdo->prepare("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $q->execute([$table, $col]);
    $type = $q->fetchColumn();
    return $type ? strtoupper((string)$type) : $fallback;
}

// Match product_variants.product_id to whatever products.id really is.
$productIdType = cb_column_type($pdo, 'products', 'id', 'INT UNSIGNED');

// ── 1. Tables that must exist (CREATE TABLE IF NOT EXISTS is safe on its own) ──
$tables = [
    'product_variants' => "CREATE TABLE IF NOT EXISTS `product_variants` (
        `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
        `product_id`      {$productIdType}  NOT NULL,
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

    // Agents and sales reps who bring in trade orders. Kept as their own
    // table rather than free text on the invoice so the same person is
    // spelled one way everywhere and their sales can actually be totalled.
    'sales_reps' => "CREATE TABLE IF NOT EXISTS `sales_reps` (
        `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `name`       VARCHAR(150) NOT NULL,
        `phone`      VARCHAR(40)  NOT NULL DEFAULT '',
        `email`      VARCHAR(180) NOT NULL DEFAULT '',
        `active`     TINYINT(1)   NOT NULL DEFAULT 1,
        `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_rep_name` (`name`)
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

    // Customer reviews shown on the public reviews page.
    //
    // `approved` defaults to 0 and nothing appears on the site until someone
    // ticks it in admin. A review box that publishes instantly is a review box
    // that publishes spam within a week.
    //
    // `source` records where a review came from, because a testimonial the shop
    // types in itself and one a customer submitted are not the same thing, and
    // the page says which is which. Inventing reviews is illegal under the
    // Digital Markets, Competition and Consumers Act 2024 — this column is what
    // keeps that line visible rather than a matter of memory.
    'testimonials' => "CREATE TABLE IF NOT EXISTS `testimonials` (
        `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `customer_name` VARCHAR(120) NOT NULL,
        `location`      VARCHAR(120) NOT NULL DEFAULT '',
        `rating`        TINYINT UNSIGNED NOT NULL DEFAULT 5,
        `body`          TEXT         NOT NULL,
        `product_name`  VARCHAR(150) NOT NULL DEFAULT '',
        `source`        ENUM('website','google','facebook','instagram','in_person') NOT NULL DEFAULT 'website',
        `approved`      TINYINT(1)   NOT NULL DEFAULT 0,
        `featured`      TINYINT(1)   NOT NULL DEFAULT 0,
        `sort_order`    INT          NOT NULL DEFAULT 0,
        `submitter_ip`  VARCHAR(45)  NOT NULL DEFAULT '',
        `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_live` (`approved`, `featured`, `sort_order`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",
];

foreach ($tables as $name => $sql) {
    try {
        $existed = cb_table_exists($pdo, $name);
        $pdo->exec($sql);
        $results[] = ['table' => $name, 'col' => '(table)', 'status' => $existed ? 'already exists ✓' : '✅ table created', 'ok' => true];
    } catch (PDOException $e) {
        // A rejected foreign key must not cost us the whole table. The code
        // reads and writes these tables by id regardless of whether the
        // constraint exists, so a table without its FK still works, while no
        // table at all takes the site down with a "Network error" that says
        // nothing about the real cause. Retry without the constraint and say
        // plainly what was dropped.
        // Matches the whole clause including any trailing ON DELETE/UPDATE
        // action. Stopping at the closing bracket of REFERENCES would leave a
        // stray "ON DELETE CASCADE" behind and turn a recoverable error into
        // a syntax error.
        $withoutFk = preg_replace(
            '/,\s*CONSTRAINT\s+`[^`]+`\s+FOREIGN\s+KEY\s*\([^)]*\)\s*REFERENCES\s*`[^`]+`\s*\([^)]*\)'
            . '(?:\s+ON\s+(?:DELETE|UPDATE)\s+(?:CASCADE|RESTRICT|SET\s+NULL|NO\s+ACTION|SET\s+DEFAULT))*/is',
            '',
            $sql
        );

        if ($withoutFk !== null && $withoutFk !== $sql) {
            try {
                $pdo->exec($withoutFk);
                $results[] = [
                    'table'  => $name,
                    'col'    => '(table)',
                    'status' => '⚠️ created WITHOUT its foreign key — parent column type differs (' . $e->getMessage() . ')',
                    'ok'     => false,
                ];
                continue;
            } catch (PDOException $e2) {
                $e = $e2;
            }
        }
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

    // The Stripe payment this order was paid with.
    //
    // Without it there is no link at all between an order and the money: the
    // intent id lived only in the session and was thrown away the moment the
    // customer closed the tab. To refund someone you had to find their payment
    // in the Stripe dashboard by matching the amount and the timestamp by eye,
    // on a list where several £24.99 orders in one afternoon look identical.
    // Refunding the wrong customer that way is a single misread row.
    ['orders', 'stripe_payment_intent', "ALTER TABLE `orders` ADD COLUMN `stripe_payment_intent` VARCHAR(64) NOT NULL DEFAULT '' AFTER `payment_method`"],

    // Who actually delivered the order, captured when it is marked Delivered.
    //
    // Stored as an id rather than a typed-in name so the reports can group by
    // person: free text produces "Raj", "raj" and "Raj " as three different
    // drivers. 0 means nobody recorded — every order placed before this
    // existed, which the reports have to show as unattributed rather than
    // silently drop.
    //
    // delivered_at is separate from created_at because "how many did Raj
    // deliver last week" is a question about the delivery date, not the day
    // the customer ordered.
    ['orders', 'sales_rep_id',  "ALTER TABLE `orders` ADD COLUMN `sales_rep_id` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `status`"],
    ['orders', 'delivered_at',  "ALTER TABLE `orders` ADD COLUMN `delivered_at` DATETIME NULL DEFAULT NULL AFTER `sales_rep_id`"],
    ['promo_codes', 'description',     "ALTER TABLE `promo_codes` ADD COLUMN `description` VARCHAR(255) NOT NULL DEFAULT '' AFTER `code`"],

    // Who sold it, and what they earn on it. commission_percent is stored on
    // the invoice rather than on the rep because the rate is agreed per deal;
    // reading it off the rep would silently rewrite history the moment
    // someone edited their standard rate.
    ['invoices', 'sales_rep_id',       "ALTER TABLE `invoices` ADD COLUMN `sales_rep_id` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `trade_user_id`"],
    ['invoices', 'commission_percent', "ALTER TABLE `invoices` ADD COLUMN `commission_percent` DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER `sales_rep_id`"],

    // Lets a customer open their own invoice without an account. The token is
    // the only thing protecting it, so it is long and random rather than the
    // invoice id — sequential ids would let anyone walk the whole ledger.
    ['invoices', 'public_token', "ALTER TABLE `invoices` ADD COLUMN `public_token` VARCHAR(64) NOT NULL DEFAULT '' AFTER `invoice_number`"],
    ['invoices', 'sent_at',      "ALTER TABLE `invoices` ADD COLUMN `sent_at` DATETIME NULL DEFAULT NULL AFTER `status`"],

    // ── Product specification: what goes on the catalogue and the
    //    allergen / nutrition sheets ──────────────────────────────
    //
    // Trade buyers order by the case, not the tub, so a catalogue without a
    // case size is not something a wholesaler can order from.
    ['products',         'case_size', "ALTER TABLE `products` ADD COLUMN `case_size` VARCHAR(60) NOT NULL DEFAULT '' AFTER `wholesale_price`"],
    ['product_variants', 'case_size', "ALTER TABLE `product_variants` ADD COLUMN `case_size` VARCHAR(60) NOT NULL DEFAULT '' AFTER `wholesale_price`"],

    // How many units are in a case. This is the NUMBER, not the label — the
    // trade cart does arithmetic with it, so it cannot live in the free-text
    // case_size field ("6 × 1L" is for printing, this is for counting).
    //
    // 0 means the item is sold as singles and no case rule applies. Trade
    // customers buy by the case, so a variant with case_qty 8 goes into a
    // trade basket 8 at a time and comes out 8 at a time.
    ['products',         'case_qty', "ALTER TABLE `products` ADD COLUMN `case_qty` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `case_size`"],
    ['product_variants', 'case_qty', "ALTER TABLE `product_variants` ADD COLUMN `case_qty` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `case_size`"],

    // Allergens are stored as a comma-separated list of slugs from the 14
    // named in assimilated Regulation (EU) 1169/2011 — the set UK food
    // businesses are legally required to declare.
    //
    // `allergen_reviewed_at` is the important one, and it is NULL until a
    // human has actually checked the product. Without it, "no allergens
    // recorded" and "confirmed free from allergens" look identical in the
    // database, and an allergen sheet generated from a blank row would tell a
    // customer with a nut allergy that Roasted Almond ice cream is safe. The
    // sheet refuses to make a free-from claim while this is NULL.
    ['products', 'ingredients',          "ALTER TABLE `products` ADD COLUMN `ingredients` TEXT NULL AFTER `description`"],
    ['products', 'allergens',            "ALTER TABLE `products` ADD COLUMN `allergens` VARCHAR(255) NOT NULL DEFAULT '' AFTER `nuts_allergy`"],
    ['products', 'allergen_notes',       "ALTER TABLE `products` ADD COLUMN `allergen_notes` VARCHAR(255) NOT NULL DEFAULT '' AFTER `allergens`"],
    ['products', 'allergen_reviewed_at', "ALTER TABLE `products` ADD COLUMN `allergen_reviewed_at` DATETIME NULL DEFAULT NULL AFTER `allergen_notes`"],

    // Storage: per product, because a tub and a wholesale pack differ. Blank
    // falls back to the shop-wide default rather than printing nothing.
    ['products', 'storage_instructions', "ALTER TABLE `products` ADD COLUMN `storage_instructions` VARCHAR(255) NOT NULL DEFAULT '' AFTER `allergen_reviewed_at`"],
    ['products', 'shelf_life',           "ALTER TABLE `products` ADD COLUMN `shelf_life` VARCHAR(60) NOT NULL DEFAULT '' AFTER `storage_instructions`"],

    // Nutrition, per the declared basis (100 ml for a frozen dessert).
    // Every figure is NULL rather than 0 by default: 0 g of sugar is a claim,
    // and an unfilled field must never be printed as one.
    ['products', 'nutrition_basis', "ALTER TABLE `products` ADD COLUMN `nutrition_basis` VARCHAR(20) NOT NULL DEFAULT '100 ml' AFTER `shelf_life`"],
    ['products', 'energy_kj',       "ALTER TABLE `products` ADD COLUMN `energy_kj`     DECIMAL(8,1) NULL DEFAULT NULL AFTER `nutrition_basis`"],
    ['products', 'energy_kcal',     "ALTER TABLE `products` ADD COLUMN `energy_kcal`   DECIMAL(8,1) NULL DEFAULT NULL AFTER `energy_kj`"],
    ['products', 'fat_g',           "ALTER TABLE `products` ADD COLUMN `fat_g`         DECIMAL(6,2) NULL DEFAULT NULL AFTER `energy_kcal`"],
    ['products', 'saturates_g',     "ALTER TABLE `products` ADD COLUMN `saturates_g`   DECIMAL(6,2) NULL DEFAULT NULL AFTER `fat_g`"],
    ['products', 'carbs_g',         "ALTER TABLE `products` ADD COLUMN `carbs_g`       DECIMAL(6,2) NULL DEFAULT NULL AFTER `saturates_g`"],
    ['products', 'sugars_g',        "ALTER TABLE `products` ADD COLUMN `sugars_g`      DECIMAL(6,2) NULL DEFAULT NULL AFTER `carbs_g`"],
    ['products', 'fibre_g',         "ALTER TABLE `products` ADD COLUMN `fibre_g`       DECIMAL(6,2) NULL DEFAULT NULL AFTER `sugars_g`"],
    ['products', 'protein_g',       "ALTER TABLE `products` ADD COLUMN `protein_g`     DECIMAL(6,2) NULL DEFAULT NULL AFTER `fibre_g`"],
    ['products', 'salt_g',          "ALTER TABLE `products` ADD COLUMN `salt_g`        DECIMAL(6,3) NULL DEFAULT NULL AFTER `protein_g`"],
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

// ── 2b. Carry the old nuts_allergy flag into the allergen list ──
//
// nuts_allergy was a single yes/no. The allergen sheet needs the named
// allergen, so anything already flagged becomes 'nuts' in the new column.
// Runs only where allergens is still empty, so it cannot overwrite a list
// someone has since filled in properly, and it is safe to run repeatedly.
//
// Note this migrates the flag, NOT the truth: a product that was never
// flagged stays empty and unreviewed, which is the honest outcome. Nothing
// here decides that a product is allergen-free — only a person can do that,
// in the product editor.
try {
    if (cb_column_exists($pdo, 'products', 'allergens') && cb_column_exists($pdo, 'products', 'nuts_allergy')) {
        $moved = $pdo->exec("UPDATE `products` SET `allergens` = 'nuts' WHERE `nuts_allergy` = 1 AND `allergens` = ''");
        $results[] = [
            'table'  => 'products',
            'col'    => 'nuts_allergy → allergens',
            'status' => $moved > 0 ? "✅ {$moved} product(s) carried over" : 'nothing to carry over ✓',
            'ok'     => true,
        ];
    }
} catch (PDOException $e) {
    $results[] = ['table' => 'products', 'col' => 'nuts_allergy → allergens', 'status' => '❌ ' . $e->getMessage(), 'ok' => false];
}

// ── 2c. Seed the standard case quantities ──────────────────
//
// The shop sells 500ml eight to a case and 1L six to a case. Matching on the
// size name rather than asking for it to be typed in thirteen times, and only
// where case_qty is still 0, so a size someone has already set by hand is
// never overwritten and the step is safe to re-run.
//
// Anything that is not a 500ml or a 1L keeps case_qty 0 and stays sold as
// singles until someone sets it in the product editor — guessing a case size
// for an unknown pack would put wrong quantities in real trade orders.
try {
    if (cb_column_exists($pdo, 'product_variants', 'case_qty')) {
        $seeded = 0;
        foreach ([['500ml', 8], ['1l', 6]] as [$needle, $qty]) {
            $st = $pdo->prepare(
                "UPDATE `product_variants`
                    SET `case_qty` = :q,
                        `case_size` = CONCAT(:q2, ' × ', `name`)
                  WHERE `case_qty` = 0
                    AND REPLACE(LOWER(`name`), ' ', '') LIKE :n"
            );
            $st->execute(['q' => $qty, 'q2' => $qty, 'n' => '%' . $needle . '%']);
            $seeded += $st->rowCount();
        }
        $results[] = [
            'table'  => 'product_variants',
            'col'    => 'case_qty (500ml→8, 1L→6)',
            'status' => $seeded > 0 ? "✅ {$seeded} size(s) set" : 'nothing to set ✓',
            'ok'     => true,
        ];
    }
} catch (PDOException $e) {
    $results[] = ['table' => 'product_variants', 'col' => 'case_qty', 'status' => '❌ ' . $e->getMessage(), 'ok' => false];
}

// ── 2d. Give every flavour a 500ml and a 1L ────────────────
//
// Every flavour is sold in both sizes, so every product needs both — and the
// case rule (500ml eights, 1L sixes) only reaches a product that actually has
// the sizes on it.
//
// Created UNPRICED and UNAVAILABLE on purpose. Nobody can tell from the data
// what a 1L of each flavour should cost: the products' own prices are neither
// the 500ml nor the 1L figure (Rajbhog is £6.99 as a product, £5.99 as a
// 500ml and £7.99 as a 1L), so any price invented here would be wrong money
// on real orders. available = 0 means a size cannot be ordered until someone
// has set its price and ticked it on, which is the safe direction to fail.
//
// Only ever adds what is missing, so a size that already exists — with its
// real prices — is never touched, and the step is safe to run again.
try {
    if (cb_table_exists($pdo, 'product_variants') && cb_column_exists($pdo, 'product_variants', 'case_qty')) {
        $sizes = [
            ['name' => '500ml', 'case' => 8, 'sort' => 1],
            ['name' => '1L',    'case' => 6, 'sort' => 2],
        ];

        $allProducts = $pdo->query("SELECT id FROM products")->fetchAll(PDO::FETCH_COLUMN);
        $ins = $pdo->prepare(
            "INSERT INTO product_variants (product_id, name, price, wholesale_price, case_qty, case_size, available, sort_order)
             VALUES (:pid, :name, 0.00, 0.00, :cq, :cs, 0, :so)"
        );
        // Matched loosely — "500 ml", "500ML" and "500ml" are the same size,
        // and a duplicate here would put two 500ml lines on one product.
        $has = $pdo->prepare(
            "SELECT COUNT(*) FROM product_variants
              WHERE product_id = :pid AND REPLACE(LOWER(name), ' ', '') = :n"
        );

        $added = 0;
        foreach ($allProducts as $pid) {
            foreach ($sizes as $s) {
                $has->execute(['pid' => $pid, 'n' => strtolower($s['name'])]);
                if ((int)$has->fetchColumn() > 0) {
                    continue;
                }
                $ins->execute([
                    'pid'  => $pid,
                    'name' => $s['name'],
                    'cq'   => $s['case'],
                    'cs'   => $s['case'] . ' × ' . $s['name'],
                    'so'   => $s['sort'],
                ]);
                $added++;
            }
        }

        $results[] = [
            'table'  => 'product_variants',
            'col'    => '500ml + 1L for every flavour',
            'status' => $added > 0
                ? "✅ {$added} size(s) created — unpriced and switched OFF until you set prices in Products → edit → Sizes"
                : 'every product already has both sizes ✓',
            'ok'     => true,
        ];
    }
} catch (PDOException $e) {
    $results[] = ['table' => 'product_variants', 'col' => '500ml + 1L', 'status' => '❌ ' . $e->getMessage(), 'ok' => false];
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

$failures = array_values(array_filter($results, fn($r) => !$r['ok']));
$allOk    = ($failures === []);

// The whole point of this page is telling you whether the database is ready.
// A red row buried among nineteen green ones is not that, so failures are
// repeated at the top where they cannot be scrolled past.
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Update DB – Schema Parity (v6&ndash;v12)</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/setup.css">
    <link rel="stylesheet" href="../../assets/css/modal.css">
    <script src="../../assets/js/modal.js" defer></script>
</head>
<body class="admin-wrapper su-page-warm">
<div class="su-wrap">
    <div class="glass-panel su-card">
        <h1 class="su-h1">⚙️ Update DB – Bring Schema Up To Date</h1>
        <p class="su-lead">Applies every table/column that is still missing. Safe to run again.</p>

        <?php
        // The page states which copy of itself is running.
        //
        // "I uploaded it but the new rows are not there" has been impossible to
        // answer without this: a stale file and a correctly-updated one looked
        // identical apart from rows you had to know to look for. The date is
        // the file's own mtime, so it cannot claim to be newer than it is.
        $selfDate = @filemtime(__FILE__);
        $stepCount = count($results);
        ?>
        <p class="su-build">
            This file was last changed <strong><?= $selfDate ? date('d M Y, H:i', $selfDate) : 'unknown' ?></strong>
            and is checking <strong><?= $stepCount ?></strong> things.
            <?php if ($stepCount < 24): ?>
            <br><span class="su-build-warn">
                An older copy — the current one checks 24. If you have just uploaded,
                PHP may still be running the previous version: restart PHP in hPanel,
                or wait two minutes and reload.
            </span>
            <?php endif; ?>
        </p>

        <!-- Which database this touched, stated loudly: running the updater
             against the wrong one and seeing all-green is indistinguishable
             from success unless the environment is impossible to overlook. -->
        <p class="su-env <?= IS_LOCAL ? 'su-env-local' : 'su-env-live' ?>">
            <?= IS_LOCAL ? '💻 LOCAL database' : '🌍 LIVE database' ?>
            &mdash; <?= htmlspecialchars(DB_NAME) ?> on <?= htmlspecialchars(DB_HOST) ?>:<?= htmlspecialchars(DB_PORT) ?>
        </p>

        <?php if ($failures): ?>
        <div class="su-failbox">
            <h2 class="su-failbox-h"><?= count($failures) ?> step<?= count($failures) === 1 ? '' : 's' ?> failed &mdash; the database is NOT up to date</h2>
            <ul class="su-failbox-list">
                <?php foreach ($failures as $f): ?>
                <li><strong><?= htmlspecialchars($f['table']) ?></strong>
                    <?= htmlspecialchars($f['col']) ?>: <?= htmlspecialchars($f['status']) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <table class="su-table">
            <?php foreach ($results as $r): ?>
            <tr class="su-row">
                <td class="su-cell-name"><?= htmlspecialchars($r['table']) ?></td>
                <td class="su-cell-mono"><?= htmlspecialchars($r['col']) ?></td>
                <td class="su-cell-state <?= $r['ok'] ? 'su-ok' : 'su-err' ?>"><?= htmlspecialchars($r['status']) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <p class="su-result <?= $allOk ? 'su-ok' : 'su-err' ?>">
            <?= $allOk ? '✅ Database schema is fully up to date.' : '❌ Some steps failed — check the messages above.' ?>
        </p>
        <a href="../index.php" class="btn-primary su-btn-back">
            <i class="fa-solid fa-arrow-left"></i> Back to Admin Dashboard
        </a>
    </div>
</div>
</body>
</html>
