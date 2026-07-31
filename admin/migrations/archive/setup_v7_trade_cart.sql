-- ============================================================
--  Creamy Bite – Setup v7: persistent cart for trade customers
--  A trade customer's basket survives logout, a closed browser and an
--  expired PHP session. It is emptied only when they place the order,
--  remove the items, or clear the cart themselves.
--  One row per trade user; the basket itself is stored as JSON in the
--  same shape as $_SESSION['cart'].
-- ============================================================

CREATE TABLE IF NOT EXISTS `trade_carts` (
  `trade_user_id` INT UNSIGNED NOT NULL,
  `cart_json`     LONGTEXT     NOT NULL,
  `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`trade_user_id`),
  CONSTRAINT `fk_trade_cart_user`
    FOREIGN KEY (`trade_user_id`) REFERENCES `trade_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
