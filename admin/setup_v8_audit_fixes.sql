-- ============================================================
--  Creamy Bite – Setup v8: schema gaps found by the code audit
--  Each of these is a column the PHP code uses but the database
--  never had, causing a silent failure in the admin panel.
-- ============================================================

-- ── orders.stock_deducted ────────────────────────────────────
--  admin/update_order.php:66 SELECTs this column when an order is
--  marked Delivered. Without it the SELECT throws, the handler
--  reports failure even though the status saved and the customer
--  email already went out, and stock is never deducted.
--  DEFAULT 0 so existing orders count as not-yet-deducted.
ALTER TABLE `orders`
  ADD COLUMN `stock_deducted` TINYINT(1) NOT NULL DEFAULT 0 AFTER `status`;

-- ── promo_codes.description ──────────────────────────────────
--  promo_handler.php:123 INSERTs it and :144 UPDATEs it. Creating a
--  promo failed with a misleading "Code already exists" message;
--  editing one threw an uncaught exception.
--  DEFAULT '' because the form allows a blank description.
ALTER TABLE `promo_codes`
  ADD COLUMN `description` VARCHAR(255) NOT NULL DEFAULT '' AFTER `code`;

-- ── gallery: image/title -> filename/caption ─────────────────
--  Every PHP file (gallery.php, admin/gallery_handler.php,
--  admin/index.php) reads filename/caption; the table shipped with
--  image/title. Uploads wrote the file to disk then threw on the
--  INSERT, leaving orphaned files and an empty table.
--  Renaming the columns is correct here because the code is
--  unanimous and the table has no rows.
ALTER TABLE `gallery`
  CHANGE `image` `filename` VARCHAR(255) NOT NULL,
  CHANGE `title` `caption`  VARCHAR(150) NOT NULL DEFAULT '';
