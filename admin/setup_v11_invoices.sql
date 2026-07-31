-- ============================================================
--  Creamy Bite – Setup v11: Invoicing
--
--  Modelled on the real Creamy Bite invoice (INV0225):
--    - a "bill from" and "bill to" block, both fully editable, so a past
--      invoice keeps the details that were correct on the day even if the
--      shop address or the customer's address later changes
--    - line items with DESCRIPTION / RATE / QTY / AMOUNT, where the
--      quantity may be a decimal with a unit ("2.25 Litre") and the rate
--      may carry a note ("£2.85 (don't have 1L Tub)")
--    - free-text payment instructions (bank details)
--    - TOTAL and BALANCE DUE, so part payments are supported
-- ============================================================

CREATE TABLE IF NOT EXISTS `invoices` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `invoice_number` VARCHAR(40)  NOT NULL,
  `order_id`       INT UNSIGNED NULL,          -- set when raised from an order
  `trade_user_id`  INT UNSIGNED NOT NULL DEFAULT 0,

  `status`         ENUM('draft','sent','part_paid','paid','void') NOT NULL DEFAULT 'draft',
  `issue_date`     DATE         NOT NULL,
  `due_terms`      VARCHAR(60)  NOT NULL DEFAULT 'On Receipt',
  `due_date`       DATE         NULL,
  `currency`       VARCHAR(8)   NOT NULL DEFAULT 'GBP',

  -- Bill from (snapshot at issue time)
  `from_name`      VARCHAR(180) NOT NULL DEFAULT '',
  `from_address`   TEXT         NOT NULL,
  `from_phone`     VARCHAR(40)  NOT NULL DEFAULT '',
  `from_email`     VARCHAR(180) NOT NULL DEFAULT '',
  `from_website`   VARCHAR(180) NOT NULL DEFAULT '',

  -- Bill to (snapshot at issue time)
  `to_name`        VARCHAR(180) NOT NULL DEFAULT '',
  `to_address`     TEXT         NOT NULL,
  `to_email`       VARCHAR(180) NOT NULL DEFAULT '',
  `to_phone`       VARCHAR(40)  NOT NULL DEFAULT '',
  `to_vat_number`  VARCHAR(50)  NOT NULL DEFAULT '',

  `payment_instructions` TEXT NOT NULL,
  `notes`                TEXT NOT NULL,

  -- Money. Every figure is stored, never recomputed on display, so a
  -- historical invoice always shows the numbers it was issued with.
  `subtotal`       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `discount`       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS `invoice_items` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `invoice_id`  INT UNSIGNED NOT NULL,

  `description` VARCHAR(255)  NOT NULL,
  -- rate_note holds the parenthetical seen on real invoices, e.g.
  -- "don't have 1L Tub" printed beside the rate.
  `rate`        DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `rate_note`   VARCHAR(120)  NOT NULL DEFAULT '',
  -- qty is decimal and qty_unit optional so "2.25 Litre" prints correctly
  -- while still multiplying out to the right amount.
  `qty`         DECIMAL(10,3) NOT NULL DEFAULT 1.000,
  `qty_unit`    VARCHAR(30)   NOT NULL DEFAULT '',
  `amount`      DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `sort_order`  INT           NOT NULL DEFAULT 0,

  PRIMARY KEY (`id`),
  KEY `idx_invoice` (`invoice_id`, `sort_order`),
  CONSTRAINT `fk_invoice_item`
    FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Payments recorded against an invoice, so part payments and the running
-- balance are auditable rather than a single overwritten number.
CREATE TABLE IF NOT EXISTS `invoice_payments` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- Shop-level invoice defaults, editable in the admin panel. One row.
CREATE TABLE IF NOT EXISTS `invoice_settings` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed the defaults from the real invoice letterhead.
INSERT INTO `invoice_settings`
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
ON DUPLICATE KEY UPDATE `id` = `id`;
