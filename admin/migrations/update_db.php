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

/**
 * Turn one step's status message into the HTML the results table prints.
 *
 * Every message is escaped before it reaches the page — several of them end in
 * a raw MySQL error — so an <i> tag written straight into a status string
 * would appear on screen as literal markup. The messages therefore carry a
 * plain-text marker, [ok] / [warn] / [err], and the icon is substituted here
 * AFTER escaping, leaving the rest of the text escaped exactly as before.
 *
 * The marker is replaced wherever it appears, not just at the start: the
 * gallery step appends its warning to the end of an otherwise successful
 * message.
 *
 * The icon itself is decorative and hidden from assistive technology; the word
 * beside it is what gets announced, because a green tick and a red cross carry
 * their meaning through colour and shape alone.
 */
function cb_status_html(string $status): string {
    $marks = [
        '[ok]'   => ['fa-circle-check',         'su-mark-ok',   'Done'],
        '[warn]' => ['fa-triangle-exclamation', 'su-mark-warn', 'Warning'],
        '[err]'  => ['fa-circle-xmark',         'su-mark-err',  'Failed'],
    ];

    $out = htmlspecialchars($status);
    foreach ($marks as $token => [$icon, $cls, $label]) {
        $out = str_replace(
            $token,
            '<i class="fa-solid ' . $icon . ' su-mark ' . $cls . '" aria-hidden="true"></i>'
            . '<span class="su-sr-only">' . $label . ':</span>',
            $out
        );
    }
    return $out;
}

// Match product_variants.product_id to whatever products.id really is.
$productIdType = cb_column_type($pdo, 'products', 'id', 'INT UNSIGNED');

// ── 1. Tables that must exist (CREATE TABLE IF NOT EXISTS is safe on its own) ──
$tables = [
    'production_runs' => "CREATE TABLE IF NOT EXISTS `production_runs` (
        `id`               INT AUTO_INCREMENT PRIMARY KEY,
        `batch_code`       VARCHAR(40)   NOT NULL,
        `product_id`       INT           NULL,
        `variant_id`       INT           NULL,
        `product_name`     VARCHAR(180)  NOT NULL,
        `variant_name`     VARCHAR(100)  NULL,
        `produced_on`      DATE          NOT NULL,
        `started_at`       TIME          NULL,
        `finished_at`      TIME          NULL,
        `planned_qty`      INT           NOT NULL DEFAULT 0,
        `output_qty`       INT           NOT NULL DEFAULT 0,
        `reject_qty`       INT           NOT NULL DEFAULT 0,
        `unit_label`       VARCHAR(40)   NOT NULL DEFAULT 'tubs',
        `best_before`      DATE          NULL,
        `mix_temp_c`       DECIMAL(5,1)  NULL,
        `pasteurise_temp_c` DECIMAL(5,1) NULL,
        `pasteurise_mins`  INT           NULL,
        `operator`         VARCHAR(120)  NULL,
        `status`           ENUM('planned','in_progress','completed','on_hold','scrapped') NOT NULL DEFAULT 'in_progress',
        `materials_used`   TEXT          NULL,
        `changes_made`     TEXT          NULL,
        `problems`         TEXT          NULL,
        `notes`            TEXT          NULL,
        `stock_added`      TINYINT(1)    NOT NULL DEFAULT 0,
        `created_by`       VARCHAR(120)  NULL,
        `created_at`       DATETIME      NOT NULL,
        `updated_at`       DATETIME      NULL,
        UNIQUE KEY `batch` (`batch_code`),
        KEY `on_date` (`produced_on`),
        KEY `prod` (`product_id`),
        KEY `state` (`status`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    'documents' => "CREATE TABLE IF NOT EXISTS `documents` (
        `id`            INT AUTO_INCREMENT PRIMARY KEY,
        `title`         VARCHAR(180)  NOT NULL,
        `category`      VARCHAR(60)   NOT NULL DEFAULT 'General',
        `description`   VARCHAR(500)  NULL,
        `stored_name`   VARCHAR(255)  NOT NULL,
        `original_name` VARCHAR(255)  NOT NULL,
        `mime`          VARCHAR(100)  NOT NULL,
        `size_bytes`    INT UNSIGNED  NOT NULL DEFAULT 0,
        `review_due`    DATE          NULL,
        `sort_order`    INT           NOT NULL DEFAULT 0,
        `uploaded_by`   VARCHAR(120)  NULL,
        `created_at`    DATETIME      NOT NULL,
        KEY `cat` (`category`),
        KEY `sort` (`sort_order`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

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

    // Named staff logins, separate from the one shared owner account
    // (ADMIN_USERNAME/ADMIN_PASSWORD in .env). The owner is never a row in
    // this table — it exists purely so individual staff can be given their
    // own login and switched off without touching the owner credential.
    'staff' => "CREATE TABLE IF NOT EXISTS `staff` (
        `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `username`      VARCHAR(60)  NOT NULL,
        `password_hash` VARCHAR(255) NOT NULL,
        `name`          VARCHAR(150) NOT NULL,
        `active`        TINYINT(1)   NOT NULL DEFAULT 1,
        `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_staff_username` (`username`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

    // One row per admin sidebar section a staff member has been granted.
    // `section_key` reuses the same tab strings as $adminNav/$validTabs in
    // admin/index.php — no separate taxonomy. 'staff' is never a legal value
    // here in practice: staff management is owner-only, enforced in code
    // (admin/_permissions.php), not by whether this table happens to hold
    // that row.
    'staff_permissions' => "CREATE TABLE IF NOT EXISTS `staff_permissions` (
        `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `staff_id`     INT UNSIGNED NOT NULL,
        `section_key`  VARCHAR(30)  NOT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_staff_section` (`staff_id`, `section_key`),
        CONSTRAINT `fk_staff_permissions_staff`
          FOREIGN KEY (`staff_id`) REFERENCES `staff` (`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

    // Optional override for the one shared owner login (ADMIN_USERNAME/ADMIN_PASSWORD
    // in .env). A missing row, or a NULL password_hash, means "no override — the
    // .env ADMIN_PASSWORD is authoritative", checked in admin/login.php and
    // admin/handlers/staff_handler.php. Not seeded — every column has a safe
    // default, so the first password change does the insert via upsert.
    // Home page banner slider, managed from Admin → Banners.
    //
    // starts_on / ends_on let a promotion be written now and appear on its own
    // — a "Bank Holiday offer" banner is worth nothing if somebody has to
    // remember to switch it on that morning and off again the day after. NULL
    // on either side means "no limit that end", so a permanent banner needs
    // neither date.
    'banners' => "CREATE TABLE IF NOT EXISTS `banners` (
        `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `image`         VARCHAR(255) NOT NULL DEFAULT '',
        `headline`      VARCHAR(120) NOT NULL DEFAULT '',
        `subtext`       VARCHAR(255) NOT NULL DEFAULT '',
        `text_position` ENUM('left','right') NOT NULL DEFAULT 'left',
        `link_url`   VARCHAR(255) NOT NULL DEFAULT '',
        `link_text`  VARCHAR(60)  NOT NULL DEFAULT '',
        `sort_order` INT          NOT NULL DEFAULT 0,
        `active`     TINYINT(1)   NOT NULL DEFAULT 1,
        `starts_on`  DATE         NULL DEFAULT NULL,
        `ends_on`    DATE         NULL DEFAULT NULL,
        `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_live` (`active`, `sort_order`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

    'owner_settings' => "CREATE TABLE IF NOT EXISTS `owner_settings` (
        `id`            TINYINT UNSIGNED NOT NULL DEFAULT 1,
        `password_hash` VARCHAR(255) NULL DEFAULT NULL,
        `updated_at`    DATETIME     NULL DEFAULT NULL,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

    // One row per Stripe refund actually issued. Append-only audit trail —
    // never updated or deleted, same convention as journal_entries in the
    // accounting module below. The authoritative "how much is left to
    // refund" check is always a live call to Stripe (see
    // admin/handlers/refund_order.php); this table is for the admin UI and
    // the record, not the ceiling.
    'order_refunds' => "CREATE TABLE IF NOT EXISTS `order_refunds` (
        `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `order_id`          INT NOT NULL,
        `amount`            DECIMAL(10,2) NOT NULL,
        `reason`            VARCHAR(255) NOT NULL DEFAULT '',
        `stripe_refund_id`  VARCHAR(64) NOT NULL DEFAULT '',
        `created_by`        VARCHAR(150) NOT NULL DEFAULT '',
        `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_order_refunds_order` (`order_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

    // ════════════════════════════════════════════════════════
    //  VAT & Accounting
    // ════════════════════════════════════════════════════════
    //
    // One settings row. `is_registered` is the switch the whole module turns
    // on: an unregistered business must not charge VAT, must not file a
    // return, and must not have one prepared for it. Defaulting it to 0 means
    // installing this module changes nothing until somebody says otherwise.
    'vat_settings' => "CREATE TABLE IF NOT EXISTS `vat_settings` (
        `id`              TINYINT UNSIGNED NOT NULL DEFAULT 1,
        `is_registered`   TINYINT(1)   NOT NULL DEFAULT 0,
        `business_name`   VARCHAR(180) NOT NULL DEFAULT '',
        `vat_number`      VARCHAR(20)  NOT NULL DEFAULT '',
        `scheme`          ENUM('standard','flat_rate','cash','annual') NOT NULL DEFAULT 'standard',
        `flat_rate_pct`   DECIMAL(5,2) NOT NULL DEFAULT 0.00,
        `frequency`       ENUM('monthly','quarterly','annual') NOT NULL DEFAULT 'quarterly',
        `period_start`    DATE         NULL DEFAULT NULL,
        `default_rate_id` INT UNSIGNED NOT NULL DEFAULT 0,
        `updated_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

    // VAT rates are rows, not an enum, because HMRC changes them (5% VAT on
    // hospitality moved three times between 2020 and 2022) and a business
    // needs the OLD rate to stay attached to old transactions. `active`
    // retires a rate without deleting it.
    //
    // `kind` separates a 0% rate that counts in Box 6 (zero-rated) from one
    // that does not (outside scope) — they are both "0%" and they are not the
    // same thing on a VAT return.
    'vat_rates' => "CREATE TABLE IF NOT EXISTS `vat_rates` (
        `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `label`      VARCHAR(60)  NOT NULL,
        `rate`       DECIMAL(6,4) NOT NULL DEFAULT 0.0000,
        `kind`       ENUM('standard','reduced','zero','exempt','outside_scope') NOT NULL DEFAULT 'standard',
        `active`     TINYINT(1)   NOT NULL DEFAULT 1,
        `sort_order` INT          NOT NULL DEFAULT 0,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_label` (`label`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

    'suppliers' => "CREATE TABLE IF NOT EXISTS `suppliers` (
        `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `name`       VARCHAR(180) NOT NULL,
        `vat_number` VARCHAR(20)  NOT NULL DEFAULT '',
        `email`      VARCHAR(180) NOT NULL DEFAULT '',
        `phone`      VARCHAR(40)  NOT NULL DEFAULT '',
        `address`    TEXT         NULL,
        `active`     TINYINT(1)   NOT NULL DEFAULT 1,
        `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_supplier_name` (`name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

    // Money is stored as the net and the VAT, never as gross-and-a-rate.
    // Recomputing VAT from a gross figure and a percentage reintroduces a
    // rounding difference on every read, and those pennies are exactly what
    // makes a VAT return fail to reconcile.
    //
    // `recoverable` is separate from the VAT amount because input VAT on
    // entertainment and most cars is charged but cannot be reclaimed — it
    // belongs in the cost, not in Box 4.
    'purchase_invoices' => "CREATE TABLE IF NOT EXISTS `purchase_invoices` (
        `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `supplier_id`    INT UNSIGNED NOT NULL DEFAULT 0,
        `invoice_number` VARCHAR(80)  NOT NULL DEFAULT '',
        `invoice_date`   DATE         NOT NULL,
        `due_date`       DATE         NULL DEFAULT NULL,
        `category`       VARCHAR(60)  NOT NULL DEFAULT '',
        `net`            DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `vat_rate_id`    INT UNSIGNED NOT NULL DEFAULT 0,
        `vat_amount`     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `recoverable`    TINYINT(1)   NOT NULL DEFAULT 1,
        `paid_amount`    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `paid_on`        DATE         NULL DEFAULT NULL,
        `payment_method` VARCHAR(40)  NOT NULL DEFAULT '',
        `reference`      VARCHAR(120) NOT NULL DEFAULT '',
        `notes`          TEXT         NULL,
        `receipt_file`   VARCHAR(255) NOT NULL DEFAULT '',
        `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_date` (`invoice_date`),
        KEY `idx_supplier` (`supplier_id`),
        UNIQUE KEY `uq_supplier_invoice` (`supplier_id`, `invoice_number`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

    'expenses' => "CREATE TABLE IF NOT EXISTS `expenses` (
        `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `expense_date`   DATE         NOT NULL,
        `supplier_id`    INT UNSIGNED NOT NULL DEFAULT 0,
        `supplier_name`  VARCHAR(180) NOT NULL DEFAULT '',
        `category`       VARCHAR(60)  NOT NULL DEFAULT 'Miscellaneous',
        `description`    VARCHAR(255) NOT NULL DEFAULT '',
        `net`            DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `vat_rate_id`    INT UNSIGNED NOT NULL DEFAULT 0,
        `vat_amount`     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `recoverable`    TINYINT(1)   NOT NULL DEFAULT 1,
        `payment_method` VARCHAR(40)  NOT NULL DEFAULT 'Bank Transfer',
        `reference`      VARCHAR(120) NOT NULL DEFAULT '',
        `notes`          TEXT         NULL,
        `receipt_file`   VARCHAR(255) NOT NULL DEFAULT '',
        `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_date` (`expense_date`),
        KEY `idx_category` (`category`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

    // Chart of accounts. `code` follows the usual ranges (1000 assets, 2000
    // liabilities, 3000 capital, 4000 income, 5000+ costs) so reports can sort
    // and group without a hardcoded list of names.
    'ledger_accounts' => "CREATE TABLE IF NOT EXISTS `ledger_accounts` (
        `id`       INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `code`     VARCHAR(10)  NOT NULL,
        `name`     VARCHAR(120) NOT NULL,
        `type`     ENUM('asset','liability','equity','income','expense') NOT NULL,
        `vat_role` ENUM('none','output_vat','input_vat') NOT NULL DEFAULT 'none',
        `system`   TINYINT(1)   NOT NULL DEFAULT 0,
        `active`   TINYINT(1)   NOT NULL DEFAULT 1,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_code` (`code`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

    // A journal entry is the header; the lines carry the debits and credits.
    // Nothing is ever deleted — `reversed_by` points at the entry that undoes
    // it, so the history stays intact and an auditor can follow it.
    'journal_entries' => "CREATE TABLE IF NOT EXISTS `journal_entries` (
        `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `entry_date`  DATE         NOT NULL,
        `reference`   VARCHAR(120) NOT NULL DEFAULT '',
        `memo`        VARCHAR(255) NOT NULL DEFAULT '',
        `source`      VARCHAR(40)  NOT NULL DEFAULT 'manual',
        `source_id`   INT UNSIGNED NOT NULL DEFAULT 0,
        `reversed_by` INT UNSIGNED NOT NULL DEFAULT 0,
        `created_by`  VARCHAR(120) NOT NULL DEFAULT '',
        `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_date` (`entry_date`),
        KEY `idx_source` (`source`, `source_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

    'journal_lines' => "CREATE TABLE IF NOT EXISTS `journal_lines` (
        `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `entry_id`    INT UNSIGNED NOT NULL,
        `account_id`  INT UNSIGNED NOT NULL,
        `debit`       DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `credit`      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `description` VARCHAR(255) NOT NULL DEFAULT '',
        PRIMARY KEY (`id`),
        KEY `idx_entry` (`entry_id`),
        KEY `idx_account` (`account_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

    // A filed return is a snapshot. The nine boxes are stored as they stood
    // when it was filed, NOT recomputed on view — a later edit to an old
    // invoice must never silently change a return that has already gone to
    // HMRC.
    'vat_returns' => "CREATE TABLE IF NOT EXISTS `vat_returns` (
        `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `period_from`  DATE NOT NULL,
        `period_to`    DATE NOT NULL,
        `status`       ENUM('draft','submitted') NOT NULL DEFAULT 'draft',
        `box1` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `box2` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `box3` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `box4` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `box5` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `box6` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `box7` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `box8` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `box9` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `submitted_at` DATETIME NULL DEFAULT NULL,
        `submitted_by` VARCHAR(120) NOT NULL DEFAULT '',
        `notes`        TEXT NULL,
        `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_period` (`period_from`, `period_to`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

    // Every adjustment carries a reason. HMRC expects an audit trail for any
    // figure that differs from the accounting records, so the note is NOT
    // optional and the UI refuses to save without one.
    'vat_adjustments' => "CREATE TABLE IF NOT EXISTS `vat_adjustments` (
        `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `return_id`  INT UNSIGNED NOT NULL,
        `box`        TINYINT UNSIGNED NOT NULL,
        `amount`     DECIMAL(12,2) NOT NULL DEFAULT 0.00,
        `reason`     VARCHAR(255) NOT NULL,
        `created_by` VARCHAR(120) NOT NULL DEFAULT '',
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_return` (`return_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

    'accounting_audit' => "CREATE TABLE IF NOT EXISTS `accounting_audit` (
        `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `occurred_at` DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `user_name`  VARCHAR(120) NOT NULL DEFAULT '',
        `action`     VARCHAR(80)  NOT NULL,
        `entity`     VARCHAR(60)  NOT NULL DEFAULT '',
        `entity_id`  INT UNSIGNED NOT NULL DEFAULT 0,
        `old_value`  TEXT NULL,
        `new_value`  TEXT NULL,
        `ip_address` VARCHAR(45)  NOT NULL DEFAULT '',
        PRIMARY KEY (`id`),
        KEY `idx_when` (`occurred_at`),
        KEY `idx_entity` (`entity`, `entity_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

    // ── The shop's own dials, editable from the admin panel ──
    //
    // ONE row, always id = 1, so reading it is a single lookup on the primary
    // key rather than a scan — and so there can never be two rows quietly
    // disagreeing about what the delivery charge is. The seed below fills it
    // with exactly the figures config.php used to hard-code, so nothing about
    // the shop changes the moment this runs.
    'store_settings' => "CREATE TABLE IF NOT EXISTS `store_settings` (
        `id`                       TINYINT UNSIGNED NOT NULL DEFAULT 1,
        `delivery_charge`          DECIMAL(6,2) NOT NULL DEFAULT 1.99,
        `free_delivery_miles`      DECIMAL(5,2) NOT NULL DEFAULT 3.00,
        `delivery_radius_miles`    DECIMAL(5,2) NOT NULL DEFAULT 6.00,
        `delivery_distance_factor` DECIMAL(4,2) NOT NULL DEFAULT 1.30,
        `min_delivery_order`       DECIMAL(8,2) NOT NULL DEFAULT 20.00,
        `free_delivery_over`       DECIMAL(8,2) NULL DEFAULT NULL,
        `cart_message`             VARCHAR(255) NULL DEFAULT NULL,
        `cart_message_active`      TINYINT(1)   NOT NULL DEFAULT 0,
        `delivery_open`            TINYINT(1)   NOT NULL DEFAULT 1,
        `collection_open`          TINYINT(1)   NOT NULL DEFAULT 1,
        `delivery_closed_note`     VARCHAR(255) NULL DEFAULT NULL,
        `collection_closed_note`   VARCHAR(255) NULL DEFAULT NULL,
        `updated_at`               DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        `updated_by`               VARCHAR(120) NOT NULL DEFAULT '',
        PRIMARY KEY (`id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",

    // ── Offers: BOGO, money off, free delivery and the rest ──
    //
    // `scope` and `scope_value` say WHAT the offer is on — everything, one
    // category, or one product. `applies_to` says WHO gets it: retail
    // customers, trade partners, or both. Trade prices are already the
    // discount, so an offer left on the default never touches a wholesale
    // basket by accident.
    //
    // Dates are DATE, not DATETIME: an offer runs for whole days, and a
    // shopkeeper setting "ends 31 December" means the end of that day, not
    // midnight at the start of it.
    'offers' => "CREATE TABLE IF NOT EXISTS `offers` (
        `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `name`         VARCHAR(120) NOT NULL,
        `type`         ENUM('bogo','buy_x_get_y','percent_off','fixed_off','free_delivery','free_item_over') NOT NULL DEFAULT 'percent_off',
        `scope`        ENUM('all','category','product') NOT NULL DEFAULT 'all',
        `scope_value`  VARCHAR(120) NULL DEFAULT NULL,
        `buy_qty`      INT          NULL DEFAULT NULL,
        `get_qty`      INT          NULL DEFAULT NULL,
        `amount`       DECIMAL(8,2) NULL DEFAULT NULL,
        `min_spend`    DECIMAL(8,2) NULL DEFAULT NULL,
        `cart_message` VARCHAR(255) NULL DEFAULT NULL,
        `badge_text`   VARCHAR(40)  NULL DEFAULT NULL,
        `applies_to`   ENUM('retail','trade','both') NOT NULL DEFAULT 'retail',
        `active`       TINYINT(1)   NOT NULL DEFAULT 0,
        `starts_on`    DATE         NULL DEFAULT NULL,
        `ends_on`      DATE         NULL DEFAULT NULL,
        `sort_order`   INT          NOT NULL DEFAULT 0,
        `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_live` (`active`, `sort_order`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",
];

foreach ($tables as $name => $sql) {
    try {
        $existed = cb_table_exists($pdo, $name);
        $pdo->exec($sql);
        $results[] = ['table' => $name, 'col' => '(table)', 'status' => $existed ? 'already exists ✓' : '[ok] table created', 'ok' => true];
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
                    'status' => '[warn] created WITHOUT its foreign key — parent column type differs (' . $e->getMessage() . ')',
                    'ok'     => false,
                ];
                continue;
            } catch (PDOException $e2) {
                $e = $e2;
            }
        }
        $results[] = ['table' => $name, 'col' => '(table)', 'status' => '[err] ' . $e->getMessage(), 'ok' => false];
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

    // ── Thermal receipt printing ───────────────────────────────
    //
    // The counter PC keeps an admin page open, asks the site every few
    // seconds which orders still need a receipt, and prints them. These two
    // columns are the whole record of that.
    //
    // printed_at NULL is the queue: "no receipt has ever come out for this
    // order". It is the ONLY thing standing between a dropped network
    // connection and a lost order, so it must default to NULL for every
    // existing row — an order the shop never printed has to look unprinted.
    //
    // print_count is separate because it counts reprints, and a reprint must
    // not disturb printed_at. The owner needs to be able to tell a receipt
    // that was handed over once from one that has been run off four times
    // because the printer was jamming.
    ['orders', 'printed_at',  "ALTER TABLE `orders` ADD COLUMN `printed_at` DATETIME NULL DEFAULT NULL AFTER `stock_deducted`"],
    ['orders', 'print_count', "ALTER TABLE `orders` ADD COLUMN `print_count` INT NOT NULL DEFAULT 0 AFTER `printed_at`"],
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

    // ── "Open for orders" / "Closed now" — the two switches ──────
    //
    // The owner needs to stop taking DELIVERY orders without stopping
    // COLLECTION, and the other way round, by hand and with immediate effect:
    // "we are closing early tonight, stop the deliveries". Two independent
    // flags, not a rota — a schedule cannot answer the phone when the van
    // breaks down.
    //
    // DEFAULT 1 on both, and that is a safety decision rather than a
    // preference. An existing row gets 1 the moment the column is added, so
    // running this migration on a busy shop cannot shut its doors. The whole
    // feature is built to FAIL OPEN: an order the shop has to ring up and
    // apologise for is a bad evening, whereas a shop that silently refuses
    // every order because of a schema change is a lost one.
    //
    // The notes are NULL, not '', so "the owner has not written anything" is
    // distinguishable from "the owner deliberately wrote nothing". A NULL note
    // gets the plain-English wording from cbOrderingClosedNote(), which also
    // knows whether the OTHER channel is still running — the point being not
    // to tell someone to come and collect when collection is shut too.
    //
    // These are also in the CREATE TABLE above, for a database that has never
    // seen store_settings. These four lines are for the one that has: the live
    // shop's table already exists, and CREATE TABLE IF NOT EXISTS will not add
    // a column to it.
    ['store_settings', 'delivery_open',          "ALTER TABLE `store_settings` ADD COLUMN `delivery_open`          TINYINT(1)   NOT NULL DEFAULT 1 AFTER `cart_message_active`"],
    ['store_settings', 'collection_open',        "ALTER TABLE `store_settings` ADD COLUMN `collection_open`        TINYINT(1)   NOT NULL DEFAULT 1 AFTER `delivery_open`"],
    ['store_settings', 'delivery_closed_note',   "ALTER TABLE `store_settings` ADD COLUMN `delivery_closed_note`   VARCHAR(255) NULL DEFAULT NULL AFTER `collection_open`"],
    ['store_settings', 'collection_closed_note', "ALTER TABLE `store_settings` ADD COLUMN `collection_closed_note` VARCHAR(255) NULL DEFAULT NULL AFTER `delivery_closed_note`"],

    // The trade half of the same pair. These were being added by an ALTER TABLE
    // that ran on a page load, which works locally but needs ALTER rights at
    // request time — shared hosting does not always grant that, and a failure
    // there is silent: the switches simply never save. They belong here with
    // every other schema change, applied when the owner runs the migration.

    ['store_settings', 'trade_delivery_open',          "ALTER TABLE `store_settings` ADD COLUMN `trade_delivery_open`          TINYINT(1)   NOT NULL DEFAULT 1  AFTER `collection_closed_note`"],
    ['store_settings', 'trade_collection_open',        "ALTER TABLE `store_settings` ADD COLUMN `trade_collection_open`        TINYINT(1)   NOT NULL DEFAULT 1  AFTER `trade_delivery_open`"],
    ['store_settings', 'trade_delivery_closed_note',   "ALTER TABLE `store_settings` ADD COLUMN `trade_delivery_closed_note`   VARCHAR(255) NOT NULL DEFAULT '' AFTER `trade_collection_open`"],
    ['store_settings', 'trade_collection_closed_note', "ALTER TABLE `store_settings` ADD COLUMN `trade_collection_closed_note` VARCHAR(255) NOT NULL DEFAULT '' AFTER `trade_delivery_closed_note`"],

    // Which side of the banner photo the caption sits on. Some banner photos
    // have their product on the left, some on the right, and a caption
    // pinned to one side forever will eventually sit on top of the product
    // instead of the empty space beside it.
    ['banners', 'text_position', "ALTER TABLE `banners` ADD COLUMN `text_position` ENUM('left','right') NOT NULL DEFAULT 'left' AFTER `subtext`"],

    // Stock held per size, not per flavour.
    //
    // Until now product_variants had no stock of its own, so every size drew
    // down the same product-level pool one unit at a time: a 500ml and a 1L
    // cost the flavour exactly one unit each. Sell twenty 1L tubs and the
    // screen said twenty gone, whichever sizes actually left the freezer — so
    // the count on the shelf and the count on the screen could never agree,
    // and one size could sell out while the system happily took more orders.
    //
    // The counters are the same five the products table already uses, so the
    // invariant reads identically at both levels:
    //   total_stock = stock_qty + sold_online + sold_offline + damage_stock
    //
    // Whether stock is tracked at all stays a product-level switch
    // (products.track_stock). One flavour is either counted or it is not;
    // a per-size toggle would only add a second place to look when something
    // sells that should not have.
    ['product_variants', 'total_stock',  "ALTER TABLE `product_variants` ADD COLUMN `total_stock`  INT NOT NULL DEFAULT 0 AFTER `available`"],
    ['product_variants', 'stock_qty',    "ALTER TABLE `product_variants` ADD COLUMN `stock_qty`    INT NOT NULL DEFAULT 0 AFTER `total_stock`"],
    ['product_variants', 'damage_stock', "ALTER TABLE `product_variants` ADD COLUMN `damage_stock` INT NOT NULL DEFAULT 0 AFTER `stock_qty`"],
    ['product_variants', 'sold_offline', "ALTER TABLE `product_variants` ADD COLUMN `sold_offline` INT NOT NULL DEFAULT 0 AFTER `damage_stock`"],
    ['product_variants', 'sold_online',  "ALTER TABLE `product_variants` ADD COLUMN `sold_online`  INT NOT NULL DEFAULT 0 AFTER `sold_offline`"],
];

foreach ($columns as [$table, $col, $sql]) {
    try {
        if (cb_column_exists($pdo, $table, $col)) {
            $results[] = ['table' => $table, 'col' => $col, 'status' => 'already exists ✓', 'ok' => true];
        } else {
            $pdo->exec($sql);
            $results[] = ['table' => $table, 'col' => $col, 'status' => '[ok] column added', 'ok' => true];
        }
    } catch (PDOException $e) {
        $results[] = ['table' => $table, 'col' => $col, 'status' => '[err] ' . $e->getMessage(), 'ok' => false];
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
            'status' => $moved > 0 ? "[ok] {$moved} product(s) carried over" : 'nothing to carry over ✓',
            'ok'     => true,
        ];
    }
} catch (PDOException $e) {
    $results[] = ['table' => 'products', 'col' => 'nuts_allergy → allergens', 'status' => '[err] ' . $e->getMessage(), 'ok' => false];
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
            'status' => $seeded > 0 ? "[ok] {$seeded} size(s) set" : 'nothing to set ✓',
            'ok'     => true,
        ];
    }
} catch (PDOException $e) {
    $results[] = ['table' => 'product_variants', 'col' => 'case_qty', 'status' => '[err] ' . $e->getMessage(), 'ok' => false];
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
                ? "[ok] {$added} size(s) created — unpriced and switched OFF until you set prices in Products → edit → Sizes"
                : 'every product already has both sizes ✓',
            'ok'     => true,
        ];
    }
} catch (PDOException $e) {
    $results[] = ['table' => 'product_variants', 'col' => '500ml + 1L', 'status' => '[err] ' . $e->getMessage(), 'ok' => false];
}

// ── 2d2. Move each flavour's opening stock onto one of its sizes ──
//
// The product-level figure has to go somewhere, and nothing in the data says
// how it splits: "45 Kesar Pista" was counted the same whether it left as a
// 500ml or a 1L, so the split it stands for was never recorded. Inventing one
// — half each, say — would put numbers on the screen that nobody counted and
// that the freezer will not match.
//
// So the whole balance moves onto ONE size, intact. Nothing is created and
// nothing is lost: the flavour's total is the same after this step as before,
// it is simply attributed to a size now. The owner then counts the freezer
// once and sets the real figure per size on the Stock page, which is the only
// way this can become true rather than plausible.
//
// The receiving size is the first one that is actually on sale, so the shop
// keeps selling through the change rather than reading sold out on Monday
// morning. Unpriced sizes seeded switched-off are skipped for that reason.
//
// Runs once by construction: it only fires where the sizes hold no stock at
// all, so the second run finds the balance already moved and leaves it be.
try {
    if (cb_table_exists($pdo, 'product_variants') && cb_column_exists($pdo, 'product_variants', 'total_stock')) {

        $pending = $pdo->query(
            "SELECT p.id, p.name, p.total_stock, p.stock_qty, p.damage_stock,
                    p.sold_offline, p.sold_online
               FROM products p
              WHERE p.total_stock > 0
                AND EXISTS (SELECT 1 FROM product_variants v WHERE v.product_id = p.id)
                AND (SELECT COALESCE(SUM(v.total_stock), 0)
                       FROM product_variants v WHERE v.product_id = p.id) = 0"
        )->fetchAll(PDO::FETCH_ASSOC);

        // On sale first, then whatever comes first — a flavour whose sizes are
        // all switched off still needs its balance parked somewhere rather
        // than silently dropped.
        $pick = $pdo->prepare(
            "SELECT id FROM product_variants
              WHERE product_id = :pid
              ORDER BY available DESC, sort_order ASC, id ASC
              LIMIT 1"
        );
        $move = $pdo->prepare(
            "UPDATE product_variants
                SET total_stock  = :total, stock_qty    = :qty,
                    damage_stock = :dmg,   sold_offline = :off,
                    sold_online  = :on
              WHERE id = :vid"
        );

        $moved = [];
        foreach ($pending as $p) {
            $pick->execute(['pid' => (int)$p['id']]);
            $vid = (int)$pick->fetchColumn();
            if ($vid <= 0) { continue; }

            $move->execute([
                'total' => (int)$p['total_stock'],
                'qty'   => (int)$p['stock_qty'],
                'dmg'   => (int)$p['damage_stock'],
                'off'   => (int)$p['sold_offline'],
                'on'    => (int)$p['sold_online'],
                'vid'   => $vid,
            ]);
            $moved[] = $p['name'];
        }

        $results[] = [
            'table'  => 'product_variants',
            'col'    => 'opening stock per size',
            'status' => $moved
                ? '[warn] opening stock moved onto one size for ' . count($moved) . ' flavour(s): '
                  . implode(', ', array_slice($moved, 0, 6))
                  . (count($moved) > 6 ? ' and ' . (count($moved) - 6) . ' more' : '')
                  . ' — count the freezer and set the real figure for each size on the Stock page'
                : 'stock already held per size ✓',
            'ok'     => true,
        ];
    }
} catch (PDOException $e) {
    $results[] = ['table' => 'product_variants', 'col' => 'opening stock per size', 'status' => '[err] ' . $e->getMessage(), 'ok' => false];
}

// ── 2d. Rescue gallery photos saved to the wrong folder ────
//
// gallery_handler.php sits in admin/handlers/, and its upload path used a
// single ../ — which reaches admin/, not the site root. Every photo uploaded
// before that was fixed went into admin/assets/images/gallery/, a folder
// nothing on the site reads. The database rows are correct; only the files
// are in the wrong place.
//
// Moving them here rather than leaving it as a File Manager chore, because
// the rows already point at these exact filenames: put the files where the
// gallery page looks and the photos simply appear, with nothing re-uploaded.
//
// Safe to run repeatedly — it does nothing once the stray folder is gone, and
// it never overwrites a file that already exists at the destination.
try {
    $strayDir = dirname(__DIR__) . '/assets/images/gallery';          // admin/assets/...
    $realDir  = dirname(__DIR__, 2) . '/assets/images/gallery';        // site root

    if (!is_dir($strayDir)) {
        $results[] = ['table' => 'gallery', 'col' => 'misplaced photos', 'status' => 'nothing misplaced ✓', 'ok' => true];
    } else {
        if (!is_dir($realDir)) {
            mkdir($realDir, 0755, true);
        }

        $moved = $skipped = $failed = 0;
        foreach (scandir($strayDir) as $f) {
            if ($f === '.' || $f === '..' || !is_file($strayDir . '/' . $f)) {
                continue;
            }
            // Images only. A stray .htaccess belongs to whichever folder it is
            // already in and must not be dragged across.
            if (!preg_match('/\.(jpe?g|png|webp|gif)$/i', $f)) {
                continue;
            }
            if (file_exists($realDir . '/' . $f)) {
                $skipped++;                       // already there; leave both alone
            } elseif (@rename($strayDir . '/' . $f, $realDir . '/' . $f)) {
                $moved++;
            } else {
                $failed++;
            }
        }

        // Tidy the folder away, but only once it holds nothing but the
        // .htaccess that was created alongside it. Anything else in there is
        // something this step did not put there and must not delete.
        $leftovers = array_values(array_diff(scandir($strayDir), ['.', '..']));
        if ($leftovers === ['.htaccess']) {
            @unlink($strayDir . '/.htaccess');
            $leftovers = [];
        }
        $folderGone = false;
        if (!$leftovers) {
            // rmdir() refuses a directory that is not empty, which is what
            // makes walking up safe: admin/assets also holds css/ on a real
            // install, so that last call fails harmlessly and the stylesheets
            // are never touched. Each is silenced for exactly that reason.
            @rmdir($strayDir);                     // admin/assets/images/gallery
            @rmdir(dirname($strayDir));            // admin/assets/images
            @rmdir(dirname($strayDir, 2));         // admin/assets — kept when css/ lives there
            $folderGone = !is_dir($strayDir);
        }

        $msg = $moved > 0
            ? "[ok] {$moved} photo(s) moved into assets/images/gallery — they will show on the gallery page now"
            : 'no photos needed moving ✓';
        if ($skipped) { $msg .= " ({$skipped} already in place)"; }
        if ($failed)  { $msg .= " — [warn] {$failed} could not be moved, check folder permissions"; }
        if (!$folderGone && ($moved || $skipped)) {
            $msg .= ' — admin/assets still holds other files, delete it by hand if you want it gone';
        }

        $results[] = ['table' => 'gallery', 'col' => 'misplaced photos', 'status' => $msg, 'ok' => ($failed === 0)];
    }
} catch (Throwable $e) {
    $results[] = ['table' => 'gallery', 'col' => 'misplaced photos', 'status' => '[err] ' . $e->getMessage(), 'ok' => false];
}

// ── 2e. Seed VAT rates and the chart of accounts ───────────
//
// INSERT IGNORE against a unique key, so this is safe to re-run and never
// disturbs a rate or account someone has since edited.
//
// The rates are the UK set as at 2026: 20% standard, 5% reduced, 0% zero-rated,
// plus Exempt and Outside Scope. Zero-rated and Exempt are both "no VAT
// charged" and are NOT interchangeable — zero-rated sales belong in Box 6 and
// exempt ones do not, which is why `kind` exists alongside `rate`.
try {
    if (cb_table_exists($pdo, 'vat_rates')) {
        $seedRates = [
            ['Standard 20%',   0.2000, 'standard',      1],
            ['Reduced 5%',     0.0500, 'reduced',       2],
            ['Zero rated 0%',  0.0000, 'zero',          3],
            ['Exempt',         0.0000, 'exempt',        4],
            ['Outside scope',  0.0000, 'outside_scope', 5],
        ];
        $st = $pdo->prepare("INSERT IGNORE INTO vat_rates (label, rate, kind, sort_order) VALUES (?,?,?,?)");
        $n = 0;
        foreach ($seedRates as $r) { $st->execute($r); $n += $st->rowCount(); }
        $results[] = ['table' => 'vat_rates', 'col' => '(seed)',
                      'status' => $n > 0 ? "[ok] {$n} rate(s) added" : 'already seeded ✓', 'ok' => true];
    }
} catch (PDOException $e) {
    $results[] = ['table' => 'vat_rates', 'col' => '(seed)', 'status' => '[err] ' . $e->getMessage(), 'ok' => false];
}

try {
    if (cb_table_exists($pdo, 'ledger_accounts')) {
        // Codes follow the usual ranges so reports can group by the first
        // digit: 1 asset, 2 liability, 3 equity, 4 income, 5+ cost.
        // `system` marks the accounts the automatic postings depend on —
        // those must not be deleted or the ledger stops balancing.
        $accounts = [
            ['1000','Cash','asset','none',1],
            ['1010','Bank','asset','none',1],
            ['1100','Accounts Receivable','asset','none',1],
            ['1200','Inventory','asset','none',1],
            ['1400','Equipment','asset','none',0],
            ['1450','Accumulated Depreciation','asset','none',0],
            ['2000','Accounts Payable','liability','none',1],
            ['2200','Sales VAT (output)','liability','output_vat',1],
            ['2210','Purchase VAT (input)','asset','input_vat',1],
            ['3000','Capital','equity','none',0],
            ['3100','Owner Drawings','equity','none',0],
            ['3200','Retained Earnings','equity','none',0],
            ['4000','Sales','income','none',1],
            ['4100','Delivery Income','income','none',1],
            ['5000','Purchases','expense','none',1],
            ['5010','Cost of Goods Sold','expense','none',0],
            ['6000','Utilities','expense','none',0],
            ['6010','Rent','expense','none',0],
            ['6020','Insurance','expense','none',0],
            ['6030','Vehicle & Fuel','expense','none',0],
            ['6040','Office Supplies','expense','none',0],
            ['6050','Cleaning','expense','none',0],
            ['6060','Packaging','expense','none',0],
            ['6070','Ingredients','expense','none',0],
            ['6080','Marketing','expense','none',0],
            ['6090','Repairs','expense','none',0],
            ['6100','Professional Fees','expense','none',0],
            ['6110','Payroll','expense','none',0],
            ['6120','Depreciation','expense','none',0],
            ['6900','Miscellaneous','expense','none',0],
        ];
        $st = $pdo->prepare("INSERT IGNORE INTO ledger_accounts (code, name, type, vat_role, system) VALUES (?,?,?,?,?)");
        $n = 0;
        foreach ($accounts as $a) { $st->execute($a); $n += $st->rowCount(); }
        $results[] = ['table' => 'ledger_accounts', 'col' => '(chart of accounts)',
                      'status' => $n > 0 ? "[ok] {$n} account(s) added" : 'already seeded ✓', 'ok' => true];
    }
} catch (PDOException $e) {
    $results[] = ['table' => 'ledger_accounts', 'col' => '(chart of accounts)', 'status' => '[err] ' . $e->getMessage(), 'ok' => false];
}

// The single settings row. is_registered stays 0 — installing this module
// must not start treating a business as VAT registered on its behalf.
try {
    if (cb_table_exists($pdo, 'vat_settings')) {
        $st = $pdo->prepare("INSERT IGNORE INTO vat_settings (id, business_name, period_start) VALUES (1, ?, ?)");
        $st->execute([SHOP_NAME, date('Y-04-01')]);
        $results[] = ['table' => 'vat_settings', 'col' => '(seed row)',
                      'status' => $st->rowCount() > 0 ? '[ok] created (VAT registration OFF until you set it)' : 'already present ✓',
                      'ok' => true];
    }
} catch (PDOException $e) {
    $results[] = ['table' => 'vat_settings', 'col' => '(seed row)', 'status' => '[err] ' . $e->getMessage(), 'ok' => false];
}

// ── 2e2. Seed the one store_settings row ───────────────────
//
// Seeded from the delivery figures the site is running RIGHT NOW — the
// constants config.php has already defined by the time this file runs. When
// the row does not exist yet those constants are the built-in defaults, which
// are the numbers config.php used to hard-code, so running this migration
// cannot change what a customer is charged. It only moves where the numbers
// are kept.
//
// INSERT IGNORE, so a second run — or a run after the owner has edited the
// delivery charge in the admin panel — leaves their figures completely alone.
try {
    if (cb_table_exists($pdo, 'store_settings')) {
        $st = $pdo->prepare(
            "INSERT IGNORE INTO `store_settings`
                (`id`, `delivery_charge`, `free_delivery_miles`, `delivery_radius_miles`,
                 `delivery_distance_factor`, `min_delivery_order`, `updated_by`)
             VALUES (1, ?, ?, ?, ?, ?, 'set up by the database updater')"
        );
        $st->execute([
            DELIVERY_CHARGE,
            FREE_DELIVERY_MILES,
            DELIVERY_RADIUS_MILES,
            DELIVERY_DISTANCE_FACTOR,
            MIN_DELIVERY_ORDER,
        ]);
        $results[] = [
            'table'  => 'store_settings',
            'col'    => '(seed row)',
            'status' => $st->rowCount() > 0
                ? '[ok] created with the delivery figures the shop is already using'
                : 'already present ✓',
            'ok'     => true,
        ];
    }
} catch (PDOException $e) {
    $results[] = ['table' => 'store_settings', 'col' => '(seed row)', 'status' => '[err] ' . $e->getMessage(), 'ok' => false];
}

// ── 2f. Add "Bank" as a recognised order payment status ────
//
// payment_status started as a plain Unpaid/Paid/Cash enum. A bank-transfer
// order had nowhere honest to go except "Cash" (paid, not online), which
// hid it from a refund needing to know it can't call Stripe. Widening the
// enum keeps payment_status the single source of truth instead of adding a
// second flag that could disagree with it.
//
// 'Refunded' and 'Part-refunded' added for the same reason. A fully refunded
// order used to keep reading "Paid", so the orders list showed money the shop
// no longer has exactly like money it does — and there was no way to filter
// for refunds or to see one at a glance.
try {
    $col = $pdo->query("SHOW COLUMNS FROM `orders` LIKE 'payment_status'")->fetch();
    $wanted = "ENUM('Unpaid','Paid','Cash','Bank','Part-refunded','Refunded')";
    if ($col && str_contains($col['Type'], "'Refunded'")) {
        $results[] = ['table' => 'orders', 'col' => 'payment_status', 'status' => 'already includes Refunded ✓', 'ok' => true];
    } else {
        $pdo->exec("ALTER TABLE `orders` MODIFY COLUMN `payment_status` {$wanted} NOT NULL DEFAULT 'Unpaid'");
        $results[] = ['table' => 'orders', 'col' => 'payment_status',
                      'status' => '[ok] enum widened — Bank, Part-refunded, Refunded', 'ok' => true];
    }
} catch (PDOException $e) {
    $results[] = ['table' => 'orders', 'col' => 'payment_status', 'status' => '[err] ' . $e->getMessage(), 'ok' => false];
}

// ── 2g. Backfill the refund status on orders already refunded ──
//
// Refunds recorded before the enum existed left their order reading "Paid".
// Re-derived from order_refunds so the list is honest about money that has
// gone back, rather than only being right for refunds issued from now on.
try {
    if (cb_table_exists($pdo, 'order_refunds')) {
        $fixed = $pdo->exec(
            "UPDATE orders o
                JOIN (SELECT order_id, SUM(amount) AS refunded
                        FROM order_refunds GROUP BY order_id) r ON r.order_id = o.id
                 SET o.payment_status = IF(r.refunded >= o.total_price - 0.005, 'Refunded', 'Part-refunded')
               WHERE o.payment_status IN ('Paid','Cash','Bank')
                 AND r.refunded > 0"
        );
        $results[] = ['table' => 'orders', 'col' => 'refund status backfill',
                      'status' => $fixed > 0 ? "[ok] {$fixed} order(s) corrected" : 'nothing to correct ✓', 'ok' => true];
    }
} catch (PDOException $e) {
    $results[] = ['table' => 'orders', 'col' => 'refund status backfill', 'status' => '[err] ' . $e->getMessage(), 'ok' => false];
}

// ── 3. gallery: image/title -> filename/caption rename ──
try {
    if (cb_column_exists($pdo, 'gallery', 'filename')) {
        $results[] = ['table' => 'gallery', 'col' => 'filename/caption', 'status' => 'already exists ✓', 'ok' => true];
    } elseif (cb_column_exists($pdo, 'gallery', 'image')) {
        $pdo->exec("ALTER TABLE `gallery` CHANGE `image` `filename` VARCHAR(255) NOT NULL, CHANGE `title` `caption` VARCHAR(150) NOT NULL DEFAULT ''");
        $results[] = ['table' => 'gallery', 'col' => 'filename/caption', 'status' => '[ok] columns renamed', 'ok' => true];
    } else {
        $results[] = ['table' => 'gallery', 'col' => 'filename/caption', 'status' => '[warn] neither image nor filename column found — check manually', 'ok' => false];
    }
} catch (PDOException $e) {
    $results[] = ['table' => 'gallery', 'col' => 'filename/caption', 'status' => '[err] ' . $e->getMessage(), 'ok' => false];
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
    $results[] = ['table' => 'invoice_settings', 'col' => '(seed row)', 'status' => '[ok] present / seeded', 'ok' => true];
} catch (PDOException $e) {
    $results[] = ['table' => 'invoice_settings', 'col' => '(seed row)', 'status' => '[err] ' . $e->getMessage(), 'ok' => false];
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
    <?php require __DIR__ . '/../../includes/favicon.php'; ?>
    <!-- Font Awesome: the status markers and the back button are icons, and
         this page never linked it, so they rendered as empty boxes. -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/setup.css">
    <link rel="stylesheet" href="../../assets/css/modal.css">
    <script src="../../assets/js/modal.js" defer></script>
</head>
<body class="admin-wrapper su-page-warm">
<div class="su-wrap">
    <div class="glass-panel su-card">
        <h1 class="su-h1"><i class="fa-solid fa-gear" aria-hidden="true"></i> Update DB – Bring Schema Up To Date</h1>
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
            <?php if ($stepCount < 79): ?>
            <br><span class="su-build-warn">
                An older copy — the current one checks 79. If you have just uploaded,
                PHP may still be running the previous version: restart PHP in hPanel,
                or wait two minutes and reload.
            </span>
            <?php endif; ?>
        </p>

        <!-- Which database this touched, stated loudly: running the updater
             against the wrong one and seeing all-green is indistinguishable
             from success unless the environment is impossible to overlook. -->
        <p class="su-env <?= IS_LOCAL ? 'su-env-local' : 'su-env-live' ?>">
            <?= IS_LOCAL
                ? '<i class="fa-solid fa-laptop" aria-hidden="true"></i> LOCAL database'
                : '<i class="fa-solid fa-globe" aria-hidden="true"></i> LIVE database' ?>
            &mdash; <?= htmlspecialchars(DB_NAME) ?> on <?= htmlspecialchars(DB_HOST) ?>:<?= htmlspecialchars(DB_PORT) ?>
        </p>

        <?php if ($failures): ?>
        <div class="su-failbox">
            <h2 class="su-failbox-h"><?= count($failures) ?> step<?= count($failures) === 1 ? '' : 's' ?> failed &mdash; the database is NOT up to date</h2>
            <ul class="su-failbox-list">
                <?php foreach ($failures as $f): ?>
                <li><strong><?= htmlspecialchars($f['table']) ?></strong>
                    <?= htmlspecialchars($f['col']) ?>: <?= cb_status_html($f['status']) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <table class="su-table">
            <?php foreach ($results as $r): ?>
            <tr class="su-row">
                <td class="su-cell-name"><?= htmlspecialchars($r['table']) ?></td>
                <td class="su-cell-mono"><?= htmlspecialchars($r['col']) ?></td>
                <td class="su-cell-state <?= $r['ok'] ? 'su-ok' : 'su-err' ?>"><?= cb_status_html($r['status']) ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <p class="su-result <?= $allOk ? 'su-ok' : 'su-err' ?>">
            <?= $allOk
                ? '<i class="fa-solid fa-circle-check su-mark su-mark-ok" aria-hidden="true"></i> Database schema is fully up to date.'
                : '<i class="fa-solid fa-circle-xmark su-mark su-mark-err" aria-hidden="true"></i> Some steps failed &mdash; check the messages above.' ?>
        </p>
        <a href="../index.php" class="btn-primary su-btn-back">
            <i class="fa-solid fa-arrow-left"></i> Back to Admin Dashboard
        </a>
    </div>
</div>
</body>
</html>
