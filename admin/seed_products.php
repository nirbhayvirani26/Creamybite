<?php
// ============================================================
// Creamy Bite – Authentic Flavor Products Seeder & Table Migration
// Visit: /admin/seed_products.php to upload all authentic Creamy Bite tubs
// ============================================================
// Admins only. This script OVERWRITES every product's price, description and
// image, so an unauthenticated visitor reaching it could rewrite the whole
// catalogue. It previously had no check whatsoever.
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/../config.php';

$error = null;
$count = 0;

// Destructive work only runs on an explicit, token-carrying POST — never on
// a plain page visit.
$seedRequested = ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'run_seed');
if ($seedRequested) {
    csrfCheck();
}

if ($seedRequested):
try {
    require_once __DIR__ . '/../db.php';

    // 1. Auto-create/repair categories table structure
    $pdo->exec("CREATE TABLE IF NOT EXISTS `categories` (
      `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      `name`       VARCHAR(100) NOT NULL,
      `icon`       VARCHAR(50)  NOT NULL DEFAULT '🍦',
      `sort_order` INT          NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    try { $pdo->exec("ALTER TABLE `categories` ADD COLUMN `icon` VARCHAR(50) NOT NULL DEFAULT '🍦'"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE `categories` ADD COLUMN `sort_order` INT NOT NULL DEFAULT 0"); } catch (Exception $e) {}

    // 2. Auto-create/repair products table structure
    $pdo->exec("CREATE TABLE IF NOT EXISTS `products` (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    try { $pdo->exec("ALTER TABLE `products` ADD COLUMN `track_stock` TINYINT(1) NOT NULL DEFAULT 0"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE `products` ADD COLUMN `total_stock` INT NOT NULL DEFAULT 0"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE `products` ADD COLUMN `stock_qty` INT NOT NULL DEFAULT 0"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE `products` ADD COLUMN `wholesale_price` DECIMAL(8,2) NOT NULL DEFAULT 0.00 AFTER `price`"); } catch (Exception $e) {}

    // 3. Seed categories
    $cats = [
        ['name' => 'Royal Flavours',   'icon' => '👑', 'sort_order' => 1],
        ['name' => 'Nutty Delights',   'icon' => '🌰', 'sort_order' => 2],
        ['name' => 'Fruit Delights',   'icon' => '🍈', 'sort_order' => 3],
        ['name' => 'Classics',         'icon' => '🍦', 'sort_order' => 4],
        ['name' => 'Exotic Speciality','icon' => '🍃', 'sort_order' => 5],
    ];

    foreach ($cats as $c) {
        $check = $pdo->prepare("SELECT COUNT(*) FROM categories WHERE name = :name");
        $check->execute(['name' => $c['name']]);
        if ((int)$check->fetchColumn() === 0) {
            $pdo->prepare("INSERT INTO categories (name, icon, sort_order) VALUES (:name, :icon, :sort_order)")->execute($c);
        } else {
            $pdo->prepare("UPDATE categories SET icon = :icon, sort_order = :sort_order WHERE name = :name")->execute($c);
        }
    }

    // 4. Seed authentic 13 Creamy Bite products
    $flavorProducts = [
        ['name' => 'Rajbhog Ice Cream Tub',         'emoji' => '👑', 'description' => 'Rich saffron ice cream loaded with almonds, cashews, pistachios & cardamom.', 'price' => 6.99, 'wholesale_price' => 4.50, 'category' => 'Royal Flavours',    'image' => 'Rajbhog.jpg',             'available' => 1, 'track_stock' => 1, 'total_stock' => 40, 'stock_qty' => 40],
        ['name' => 'Kesar Pista Ice Cream Tub',     'emoji' => '🌾', 'description' => 'Royal saffron and roasted pistachio blended into rich cream.',                'price' => 6.99, 'wholesale_price' => 4.50, 'category' => 'Royal Flavours',    'image' => 'Kesar-Pista.jpg',         'available' => 1, 'track_stock' => 1, 'total_stock' => 45, 'stock_qty' => 45],
        ['name' => 'Afghan Meva Ice Cream Tub',     'emoji' => '🇦🇫', 'description' => 'Exotic Afghan dry fruits, figs, raisins, and premium nuts.',                   'price' => 7.49, 'wholesale_price' => 5.00, 'category' => 'Royal Flavours',    'image' => 'Afghan-Meva.jpg',         'available' => 1, 'track_stock' => 1, 'total_stock' => 30, 'stock_qty' => 30],
        ['name' => 'Roasted Almond Ice Cream Tub',   'emoji' => '🌰', 'description' => 'Crunchy slow-roasted California almonds folded into rich cream.',             'price' => 6.49, 'wholesale_price' => 4.25, 'category' => 'Nutty Delights',    'image' => 'Roasted-Almond.jpg',      'available' => 1, 'track_stock' => 1, 'total_stock' => 50, 'stock_qty' => 50],
        ['name' => 'American Dry Fruits Tub',       'emoji' => '🥜', 'description' => 'Loaded with chopped almonds, cashews, raisins & tutti frutti morsels.',       'price' => 6.99, 'wholesale_price' => 4.50, 'category' => 'Nutty Delights',    'image' => 'American-Dry-Fruits.jpg', 'available' => 1, 'track_stock' => 1, 'total_stock' => 35, 'stock_qty' => 35],
        ['name' => 'Kaju Draksh Ice Cream Tub',     'emoji' => '🍇', 'description' => 'Premium cashew nuts and golden raisins folded into rich cream.',              'price' => 6.49, 'wholesale_price' => 4.25, 'category' => 'Nutty Delights',    'image' => 'Kaju-Draksh.jpg',         'available' => 1, 'track_stock' => 1, 'total_stock' => 40, 'stock_qty' => 40],
        ['name' => 'Fresh Sitafal (Custard Apple)', 'emoji' => '🍈', 'description' => 'Crafted with real fresh custard apple pulp for a sweet fruity sensation.',    'price' => 6.99, 'wholesale_price' => 4.50, 'category' => 'Fruit Delights',    'image' => 'Fresh-Sitafal.jpg',       'available' => 1, 'track_stock' => 1, 'total_stock' => 25, 'stock_qty' => 25],
        ['name' => 'Naked Coconut Ice Cream Tub',   'emoji' => '🥥', 'description' => 'Refreshing tender coconut flesh and pure coconut milk ice cream.',             'price' => 6.49, 'wholesale_price' => 4.25, 'category' => 'Fruit Delights',    'image' => 'Naked-Coconut.jpg',       'available' => 1, 'track_stock' => 1, 'total_stock' => 30, 'stock_qty' => 30],
        ['name' => 'Kaju Gulkand Ice Cream Tub',    'emoji' => '🌹', 'description' => 'Rich cashew nut ice cream infused with sweet damask rose petal preserves.',     'price' => 6.99, 'wholesale_price' => 4.50, 'category' => 'Royal Flavours',    'image' => 'Kaju-Gulkand.jpg',        'available' => 1, 'track_stock' => 1, 'total_stock' => 30, 'stock_qty' => 30],
        ['name' => 'Mava Malai Ice Cream Tub',      'emoji' => '🥛', 'description' => 'Traditional rich mawa and thickened malai cream with cardamom hint.',          'price' => 5.99, 'wholesale_price' => 3.99, 'category' => 'Classics',          'image' => 'Mava-Malai.jpg',          'available' => 1, 'track_stock' => 1, 'total_stock' => 50, 'stock_qty' => 50],
        ['name' => 'Cookies & Cream Tub',           'emoji' => '🍪', 'description' => 'Velvety vanilla ice cream swirled with crunchy chocolate cookie crumbles.',     'price' => 5.99, 'wholesale_price' => 3.99, 'category' => 'Classics',          'image' => 'Cookies-&-Cream.jpg',     'available' => 1, 'track_stock' => 1, 'total_stock' => 45, 'stock_qty' => 45],
        ['name' => 'Choco Chips Ice Cream Tub',     'emoji' => '🍫', 'description' => 'Creamy chocolate ice cream studded with rich dark chocolate chips.',            'price' => 5.99, 'wholesale_price' => 3.99, 'category' => 'Classics',          'image' => 'Choco-Chips.jpg',         'available' => 1, 'track_stock' => 1, 'total_stock' => 40, 'stock_qty' => 40],
        ['name' => 'Pan Masala Ice Cream Tub',      'emoji' => '🍃', 'description' => 'Authentic Meetha Paan infused ice cream with fennel seeds & sweet spices.',     'price' => 6.49, 'wholesale_price' => 4.25, 'category' => 'Exotic Speciality', 'image' => 'Pan-Masala.jpg',         'available' => 1, 'track_stock' => 1, 'total_stock' => 30, 'stock_qty' => 30],
    ];

    foreach ($flavorProducts as $p) {
        $check = $pdo->prepare("SELECT COUNT(*) FROM products WHERE name = :name");
        $check->execute(['name' => $p['name']]);
        if ((int)$check->fetchColumn() === 0) {
            $stmt = $pdo->prepare("INSERT INTO products (name, emoji, description, price, wholesale_price, category, image, available, track_stock, total_stock, stock_qty) VALUES (:name, :emoji, :description, :price, :wholesale_price, :category, :image, :available, :track_stock, :total_stock, :stock_qty)");
            $stmt->execute($p);
            $count++;
        } else {
            $stmt = $pdo->prepare("UPDATE products SET image = :image, category = :category, description = :description, price = :price, wholesale_price = :wholesale_price WHERE name = :name");
            $stmt->execute(['image' => $p['image'], 'category' => $p['category'], 'description' => $p['description'], 'price' => $p['price'], 'wholesale_price' => $p['wholesale_price'], 'name' => $p['name']]);
            $count++;
        }
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}
endif;   // $seedRequested
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Seed Authentic Creamy Bite Products</title>
    <link rel="stylesheet" href="../style.css">
    <link rel="stylesheet" href="setup.css">
<link rel="stylesheet" href="../modal.css">
<script src="../modal.js" defer></script>
</head>
<body class="admin-wrapper su-page" >
<div class="su-wrap-md glass-panel" >
    <h2 class="su-h-tight">🍨 Creamy Bite Database Seeder</h2>

    <?php if (!$seedRequested): ?>
    <div class="su-warn-box">
        <strong>⚠️ This overwrites live product data.</strong><br>
        For every one of the 13 built-in flavours it will reset the
        <strong>price, wholesale price, description, category and image</strong>,
        replacing whatever you have edited in the admin panel. Products you added
        yourself are left alone.
    </div>
    <form method="POST" action="seed_products.php">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="run_seed">
        <button class="btn-primary su-btn" 
                data-confirm="This resets the 13 built-in flavours to their default prices, descriptions and images. Anything you have edited on those products will be overwritten. Products you added yourself are not touched."
                data-confirm-title="Reset built-in products?"
                data-confirm-tone="danger"
                data-confirm-ok="Yes, reset them">
            Run seeder
        </button>
        <a href="index.php?tab=products" class="btn-secondary su-btn-next" >Cancel</a>
    </form>
    <?php else: ?>

    <?php if ($error): ?>
        <p class="su-err">❌ Could not connect to database:</p>
        <div style="background:#fee2e2; border:1px solid #fecaca; color:#991b1b; padding:12px; border-radius:8px; font-family:monospace; font-size:13px; margin-bottom:16px;">
            <?= htmlspecialchars($error) ?>
        </div>
        <p style="color:var(--text-secondary); font-size:14px; line-height:1.5;">
            <strong>How to fix:</strong> Open <code>config.php</code> on your server and update <code>DB_NAME</code>, <code>DB_USER</code>, and <code>DB_PASS</code> with your Hostinger database credentials.
        </p>
    <?php else: ?>
        <p style="color:#10b981; font-weight:700;">✅ <?= $count ?> authentic Creamy Bite ice cream tubs & categories loaded into your database!</p>
        <div style="margin-top:20px; display:flex; gap:12px;">
            <a href="index.php?tab=products" class="btn-primary">Back to Products</a>
            <a href="../order.php" class="btn-secondary">View Store Menu</a>
        </div>
    <?php endif; ?>

    <?php endif;   // $seedRequested ?>
</div>
</body>
</html>
