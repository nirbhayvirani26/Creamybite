-- ============================================================
--  Creamy Bite – Setup v10: trade-only products
--
--  A product flagged trade_only is visible and orderable only to a
--  logged-in trade partner. It never appears on the retail storefront
--  or the home page, and cart_handler.php refuses to add it for a
--  retail visitor even if the product id is posted directly.
-- ============================================================

ALTER TABLE `products`
  ADD COLUMN `trade_only` TINYINT(1) NOT NULL DEFAULT 0 AFTER `available`;
