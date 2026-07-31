-- ============================================================
-- Creamy Bite – Complete Database Schema & Initial Products
-- Import this SQL file into phpMyAdmin / MySQL
-- Database: creamybite (or u167013900_creamybite)
-- ============================================================

CREATE DATABASE IF NOT EXISTS `creamybite` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `creamybite`;

-- 1. PRODUCTS TABLE
CREATE TABLE IF NOT EXISTS `products` (
  `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`         VARCHAR(150) NOT NULL,
  `emoji`        VARCHAR(20)  NOT NULL DEFAULT '🍦',
  `description`  TEXT,
  `price`        DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  `category`     VARCHAR(100) NOT NULL DEFAULT 'Scoops',
  `image`        VARCHAR(255) NOT NULL DEFAULT '',
  `available`    TINYINT(1)   NOT NULL DEFAULT 1,
  `track_stock`  TINYINT(1)   NOT NULL DEFAULT 0,
  `total_stock`  INT          NOT NULL DEFAULT 0,
  `stock_qty`    INT          NOT NULL DEFAULT 0,
  `damage_stock` INT          NOT NULL DEFAULT 0,
  `sold_offline` INT          NOT NULL DEFAULT 0,
  `sold_online`  INT          NOT NULL DEFAULT 0,
  `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. ORDERS TABLE
CREATE TABLE IF NOT EXISTS `orders` (
  `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `order_code`      VARCHAR(30)  NOT NULL UNIQUE,
  `customer_name`   VARCHAR(150) NOT NULL,
  `customer_email`  VARCHAR(150) NOT NULL DEFAULT '',
  `phone`           VARCHAR(30)  NOT NULL,
  `address`         TEXT         NOT NULL,
  `notes`           TEXT,
  `postcode`        VARCHAR(10)  NOT NULL DEFAULT '',
  `items_json`      LONGTEXT     NOT NULL,
  `total_price`     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `discount_amount` DECIMAL(8,2)  NOT NULL DEFAULT 0.00,
  `delivery_charge` DECIMAL(6,2)  NOT NULL DEFAULT 0.00,
  `payment_status`  ENUM('Unpaid', 'Paid', 'Cash') NOT NULL DEFAULT 'Unpaid',
  `payment_method`  VARCHAR(50)  NOT NULL DEFAULT 'later',
  `status`          VARCHAR(50)  NOT NULL DEFAULT 'Pending',
  `promo_code`      VARCHAR(50)  DEFAULT NULL,
  `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. CATEGORIES TABLE
CREATE TABLE IF NOT EXISTS `categories` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`       VARCHAR(100) NOT NULL,
  `icon`       VARCHAR(50)  NOT NULL DEFAULT '🍦',
  `sort_order` INT          NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. GALLERY TABLE
CREATE TABLE IF NOT EXISTS `gallery` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `title`      VARCHAR(150) NOT NULL,
  `image`      VARCHAR(255) NOT NULL,
  `sort_order` INT          NOT NULL DEFAULT 0,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. PROMO CODES TABLE
CREATE TABLE IF NOT EXISTS `promo_codes` (
  `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `code`           VARCHAR(50)  NOT NULL UNIQUE,
  `discount_type`  ENUM('percentage', 'fixed') NOT NULL DEFAULT 'percentage',
  `discount_value` DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  `min_order`      DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  `max_uses`       INT          DEFAULT NULL,
  `uses_count`     INT          NOT NULL DEFAULT 0,
  `active`         TINYINT(1)   NOT NULL DEFAULT 1,
  `expires_at`     DATE         DEFAULT NULL,
  `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. INQUIRIES TABLE
CREATE TABLE IF NOT EXISTS `inquiries` (
  `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `name`       VARCHAR(120) NOT NULL,
  `email`      VARCHAR(180) NOT NULL,
  `phone`      VARCHAR(30)  NOT NULL DEFAULT '',
  `message`    TEXT         NOT NULL,
  `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `is_read`    TINYINT(1)   NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. CATEGORIES SEED DATA
INSERT INTO `categories` (`name`, `icon`, `sort_order`) VALUES
('Royal Flavours', '👑', 1),
('Nutty Delights', '🌰', 2),
('Fruit Delights', '🍈', 3),
('Classics', '🍦', 4),
('Exotic Speciality', '🍃', 5);

-- 8. AUTHENTIC CREAMY BITE ICE CREAM PRODUCTS
INSERT INTO `products` (`name`, `emoji`, `description`, `price`, `category`, `image`, `available`, `track_stock`, `total_stock`, `stock_qty`) VALUES
('Rajbhog Ice Cream Tub', '👑', 'Rich saffron ice cream loaded with almonds, cashews, pistachios & cardamom.', 6.99, 'Royal Flavours', 'Rajbhog.jpg', 1, 1, 40, 40),
('Kesar Pista Ice Cream Tub', '🌾', 'Royal saffron and roasted pistachio blended into rich cream.', 6.99, 'Royal Flavours', 'Kesar-Pista.jpg', 1, 1, 45, 45),
('Afghan Meva Ice Cream Tub', '🇦🇫', 'Exotic Afghan dry fruits, figs, raisins, and premium nuts.', 7.49, 'Royal Flavours', 'Afghan-Meva.jpg', 1, 1, 30, 30),
('Roasted Almond Ice Cream Tub', '🌰', 'Crunchy slow-roasted California almonds folded into rich cream.', 6.49, 'Nutty Delights', 'Roasted-Almond.jpg', 1, 1, 50, 50),
('American Dry Fruits Tub', '🥜', 'Loaded with chopped almonds, cashews, raisins & tutti frutti morsels.', 6.99, 'Nutty Delights', 'American-Dry-Fruits.jpg', 1, 1, 35, 35),
('Kaju Draksh Ice Cream Tub', '🍇', 'Premium cashew nuts and golden raisins folded into rich cream.', 6.49, 'Nutty Delights', 'Kaju-Draksh.jpg', 1, 1, 40, 40),
('Fresh Sitafal (Custard Apple)', '🍈', 'Crafted with real fresh custard apple pulp for a sweet fruity sensation.', 6.99, 'Fruit Delights', 'Fresh-Sitafal.jpg', 1, 1, 25, 25),
('Naked Coconut Ice Cream Tub', '🥥', 'Refreshing tender coconut flesh and pure coconut milk ice cream.', 6.49, 'Fruit Delights', 'Naked-Coconut.jpg', 1, 1, 30, 30),
('Kaju Gulkand Ice Cream Tub', '🌹', 'Rich cashew nut ice cream infused with sweet damask rose petal preserves.', 6.99, 'Royal Flavours', 'Kaju-Gulkand.jpg', 1, 1, 30, 30),
('Mava Malai Ice Cream Tub', '🥛', 'Traditional rich mawa and thickened malai cream with cardamom hint.', 5.99, 'Classics', 'Mava-Malai.jpg', 1, 1, 50, 50),
('Cookies & Cream Tub', '🍪', 'Velvety vanilla ice cream swirled with crunchy chocolate cookie crumbles.', 5.99, 'Classics', 'Cookies-&-Cream.jpg', 1, 1, 45, 45),
('Choco Chips Ice Cream Tub', '🍫', 'Creamy chocolate ice cream studded with rich dark chocolate chips.', 5.99, 'Classics', 'Choco-Chips.jpg', 1, 1, 40, 40),
('Pan Masala Ice Cream Tub', '🍃', 'Authentic Meetha Paan infused ice cream with fennel seeds & sweet spices.', 6.49, 'Exotic Speciality', 'Pan-Masala.jpg', 1, 1, 30, 30);
