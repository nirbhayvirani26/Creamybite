-- ============================================================
--  Creamy Bite – Setup v6: full schema reconciliation
--  Brings a database up to what the PHP code actually expects.
--  Safe to run on a fresh local DB. On a database where some of
--  these already exist, run only the statements you need — MySQL
--  has no ADD COLUMN IF NOT EXISTS.
-- ============================================================

-- ── products: columns written by admin/product_form.php ──────
--    wholesale_price is also covered by admin/setup_trade_v4.php.
--    badge and nuts_allergy have no setup script at all.
ALTER TABLE `products`
  ADD COLUMN `wholesale_price` DECIMAL(8,2)  NOT NULL DEFAULT 0.00 AFTER `price`,
  ADD COLUMN `badge`           VARCHAR(50)   NOT NULL DEFAULT ''   AFTER `image`,
  ADD COLUMN `nuts_allergy`    TINYINT(1)    NOT NULL DEFAULT 0    AFTER `available`;

-- ── product_variants: no CREATE TABLE existed anywhere ───────
--    Column list derived from every reference in
--    admin/variant_handler.php, admin/product_form.php,
--    cart_handler.php and order.php.
CREATE TABLE IF NOT EXISTS `product_variants` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── trade_users: from setup_trade_v4.php, plus raw_password ──
--    (raw_password comes from setup_trade_v5.php)
CREATE TABLE IF NOT EXISTS `trade_users` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── orders: trade linkage, from setup_trade_v5.php ───────────
ALTER TABLE `orders`
  ADD COLUMN `trade_user_id`       INT          NOT NULL DEFAULT 0  AFTER `id`,
  ADD COLUMN `trade_business_name` VARCHAR(180) NOT NULL DEFAULT '' AFTER `trade_user_id`;
