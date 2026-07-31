<?php
// ============================================================
//  Creamy Bite – Order / Product Page (with Variant Support)
// ============================================================
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';

require_once __DIR__ . '/../includes/trade_cart.php';
// A revoked trade account must stop seeing wholesale prices immediately.
tradeSessionRevalidate($pdo);

// Load categories
$categories = [];
try {
    $cats = $pdo->query("SELECT * FROM categories ORDER BY sort_order ASC, name ASC")->fetchAll();
    foreach ($cats as $c) { $categories[] = $c; }
} catch (PDOException $e) { }

// Load available products & categories
$products = [];
try {
    $catCheck = (int)$pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn();
    if ($catCheck === 0) {
        $cats = [
            ['name' => 'Royal Flavours',   'icon' => '👑', 'sort_order' => 1],
            ['name' => 'Nutty Delights',   'icon' => '🌰', 'sort_order' => 2],
            ['name' => 'Fruit Delights',   'icon' => '🍈', 'sort_order' => 3],
            ['name' => 'Classics',         'icon' => '🍦', 'sort_order' => 4],
            ['name' => 'Exotic Speciality','icon' => '🍃', 'sort_order' => 5],
        ];
        foreach ($cats as $c) {
            $pdo->prepare("INSERT INTO categories (name, icon, sort_order) VALUES (:name, :icon, :sort_order)")->execute($c);
        }
    }

    // First-run seeding only.
    //
    // This used to reseed whenever 'Rajbhog Ice Cream Tub' was missing, and
    // inserted all 13 rows without checking which already existed — so a
    // single missing product duplicated the entire catalogue on the next page
    // load. Seed only into a genuinely empty products table, and skip any name
    // that is already there.
    $productCount = (int)$pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
    if ($productCount === 0) {
        $flavorProducts = [
            ['name' => 'Rajbhog Ice Cream Tub',         'emoji' => '👑', 'description' => 'Rich saffron ice cream loaded with almonds, cashews, pistachios & cardamom.', 'price' => 6.99, 'category' => 'Royal Flavours',    'image' => 'Rajbhog.jpg',             'available' => 1, 'track_stock' => 1, 'total_stock' => 40, 'stock_qty' => 40],
            ['name' => 'Kesar Pista Ice Cream Tub',     'emoji' => '🌾', 'description' => 'Royal saffron and roasted pistachio blended into rich cream.',                'price' => 6.99, 'category' => 'Royal Flavours',    'image' => 'Kesar-Pista.jpg',         'available' => 1, 'track_stock' => 1, 'total_stock' => 45, 'stock_qty' => 45],
            ['name' => 'Afghan Meva Ice Cream Tub',     'emoji' => '🇦🇫', 'description' => 'Exotic Afghan dry fruits, figs, raisins, and premium nuts.',                   'price' => 7.49, 'category' => 'Royal Flavours',    'image' => 'Afghan-Meva.jpg',         'available' => 1, 'track_stock' => 1, 'total_stock' => 30, 'stock_qty' => 30],
            ['name' => 'Roasted Almond Ice Cream Tub',   'emoji' => '🌰', 'description' => 'Crunchy slow-roasted California almonds folded into rich cream.',             'price' => 6.49, 'category' => 'Nutty Delights',    'image' => 'Roasted-Almond.jpg',      'available' => 1, 'track_stock' => 1, 'total_stock' => 50, 'stock_qty' => 50],
            ['name' => 'American Dry Fruits Tub',       'emoji' => '🥜', 'description' => 'Loaded with chopped almonds, cashews, raisins & tutti frutti morsels.',       'price' => 6.99, 'category' => 'Nutty Delights',    'image' => 'American-Dry-Fruits.jpg', 'available' => 1, 'track_stock' => 1, 'total_stock' => 35, 'stock_qty' => 35],
            ['name' => 'Kaju Draksh Ice Cream Tub',     'emoji' => '🍇', 'description' => 'Premium cashew nuts and golden raisins folded into rich cream.',              'price' => 6.49, 'category' => 'Nutty Delights',    'image' => 'Kaju-Draksh.jpg',         'available' => 1, 'track_stock' => 1, 'total_stock' => 40, 'stock_qty' => 40],
            ['name' => 'Fresh Sitafal (Custard Apple)', 'emoji' => '🍈', 'description' => 'Crafted with real fresh custard apple pulp for a sweet fruity sensation.',    'price' => 6.99, 'category' => 'Fruit Delights',    'image' => 'Fresh-Sitafal.jpg',       'available' => 1, 'track_stock' => 1, 'total_stock' => 25, 'stock_qty' => 25],
            ['name' => 'Naked Coconut Ice Cream Tub',   'emoji' => '🥥', 'description' => 'Refreshing tender coconut flesh and pure coconut milk ice cream.',             'price' => 6.49, 'category' => 'Fruit Delights',    'image' => 'Naked-Coconut.jpg',       'available' => 1, 'track_stock' => 1, 'total_stock' => 30, 'stock_qty' => 30],
            ['name' => 'Kaju Gulkand Ice Cream Tub',    'emoji' => '🌹', 'description' => 'Rich cashew nut ice cream infused with sweet damask rose petal preserves.',     'price' => 6.99, 'category' => 'Royal Flavours',    'image' => 'Kaju-Gulkand.jpg',        'available' => 1, 'track_stock' => 1, 'total_stock' => 30, 'stock_qty' => 30],
            ['name' => 'Mava Malai Ice Cream Tub',      'emoji' => '🥛', 'description' => 'Traditional rich mawa and thickened malai cream with cardamom hint.',          'price' => 5.99, 'category' => 'Classics',          'image' => 'Mava-Malai.jpg',          'available' => 1, 'track_stock' => 1, 'total_stock' => 50, 'stock_qty' => 50],
            ['name' => 'Cookies & Cream Tub',           'emoji' => '🍪', 'description' => 'Velvety vanilla ice cream swirled with crunchy chocolate cookie crumbles.',     'price' => 5.99, 'category' => 'Classics',          'image' => 'Cookies-&-Cream.jpg',     'available' => 1, 'track_stock' => 1, 'total_stock' => 45, 'stock_qty' => 45],
            ['name' => 'Choco Chips Ice Cream Tub',     'emoji' => '🍫', 'description' => 'Creamy chocolate ice cream studded with rich dark chocolate chips.',            'price' => 5.99, 'category' => 'Classics',          'image' => 'Choco-Chips.jpg',         'available' => 1, 'track_stock' => 1, 'total_stock' => 40, 'stock_qty' => 40],
            ['name' => 'Pan Masala Ice Cream Tub',      'emoji' => '🍃', 'description' => 'Authentic Meetha Paan infused ice cream with fennel seeds & sweet spices.',     'price' => 6.49, 'category' => 'Exotic Speciality', 'image' => 'Pan-Masala.jpg',         'available' => 1, 'track_stock' => 1, 'total_stock' => 30, 'stock_qty' => 30],
        ];
        $existsStmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE name = :name");
        $insertStmt = $pdo->prepare("INSERT INTO products (name, emoji, description, price, category, image, available, track_stock, total_stock, stock_qty) VALUES (:name, :emoji, :description, :price, :category, :image, :available, :track_stock, :total_stock, :stock_qty)");
        foreach ($flavorProducts as $fp) {
            $existsStmt->execute(['name' => $fp['name']]);
            if ((int)$existsStmt->fetchColumn() === 0) {
                $insertStmt->execute($fp);
            }
        }
    }

    // The old "purge products not in this hardcoded list" block used to run
    // here on EVERY page load. It deleted any product the admin added, the
    // moment a customer opened this page — and with the product_variants
    // foreign key it took their variants and trade prices too. A public
    // storefront page must never delete catalogue data; removing products is
    // the admin panel's job.

    // Trade-only products are hidden from retail visitors entirely.
    $tradeOnlyFilter = !empty($_SESSION['trade_user']) ? '' : ' AND trade_only = 0';
    $stmt = $pdo->query("SELECT * FROM products WHERE available = 1{$tradeOnlyFilter} ORDER BY id ASC");
    while ($row = $stmt->fetch()) { $products[$row['id']] = $row; }
} catch (PDOException $e) { }

// Load ALL variants for available products in one query
$variantsByProduct = [];
if (!empty($products)) {
    $ids = implode(',', array_keys($products));
    try {
        $vRows = $pdo->query("SELECT * FROM product_variants WHERE product_id IN ($ids) AND available = 1 ORDER BY sort_order ASC, id ASC")->fetchAll();
        $isTradeUser = !empty($_SESSION['trade_user']);
        foreach ($vRows as $v) {
            if ($isTradeUser && (float)($v['wholesale_price'] ?? 0) > 0) {
                $v['price'] = (float)$v['wholesale_price'];
            }
            $variantsByProduct[$v['product_id']][] = $v;
        }
    } catch (PDOException $e) { }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order – <?= SHOP_NAME ?></title>
    <meta name="description" content="Browse and order handcrafted ice cream and cocoa drinks at <?= SHOP_NAME ?>. Fresh flavours made daily, delivered to your door.">
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/responsive.css">
    <link rel="stylesheet" href="../assets/css/animations.css">
    <link rel="stylesheet" href="../assets/css/components.css">
    <link rel="stylesheet" href="../assets/css/modal.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>

<!-- ══ Navbar ══════════════════════════════════════════════ -->
<header class="navbar">
    <div class="container nav-container-centered">
        <nav class="nav-left">
            <ul class="nav-links">
                <li><a href="../index.php">Home</a></li>
                <li><a href="order.php" class="active">Order</a></li>
                <li><a href="gallery.php">Gallery</a></li>
                <li><a href="about.php">About Us</a></li>
            </ul>
        </nav>

        <a href="../index.php" class="logo logo-center">
            <img src="../assets/images/logo.png" alt="<?= SHOP_NAME ?>" class="logo-img">
        </a>

        <div class="nav-actions nav-right">
            <?php include __DIR__ . '/../includes/trade_nav_button.php'; ?>
            <button class="btn-view-cart" id="cartToggle" onclick="openCart()" aria-label="View cart">
                <i class="fa-solid fa-bag-shopping"></i> <span class="cart-btn-text">View Cart</span>
                <span class="cart-badge" id="cartBadge">0</span>
            </button>
            <button class="nav-hamburger" id="navHamburger" aria-label="Open menu"><span></span><span></span><span></span></button>
        </div>
    </div>
</header>

<!-- ══ Mobile Nav Drawer ══════════════════════════════════ -->
<div class="mobile-drawer" id="mobileDrawer">
    <div class="mobile-nav-panel">
        <button class="mobile-drawer-close" id="mobileDrawerClose" aria-label="Close menu">
            <i class="fa-solid fa-xmark"></i>
        </button>
        <ul class="mobile-nav-links">
            <li><a href="../index.php">Home</a></li>
            <li><a href="order.php" class="active">Order</a></li>
            <li><a href="gallery.php">Gallery</a></li>
            <li><a href="about.php">About Us</a></li>
        </ul>
        <div class="mobile-nav-actions">
            <button class="btn-view-cart cbor-drawer-action-btn" onclick="closeMobileMenu(); openCart();">
                <i class="fa-solid fa-bag-shopping"></i> View Cart
            </button>
            <a href="checkout.php" class="btn-primary cbor-drawer-action-btn">
                <i class="fa-solid fa-bolt"></i> Order Now
            </a>
        </div>
    </div>
</div>

<!-- ══ Variant Picker Modal ════════════════════════════════ -->
<div class="variant-picker-overlay" id="variantPicker">
    <div class="variant-picker-card" id="variantPickerCard">
        <button class="variant-picker-close" onclick="closeVariantPicker()" aria-label="Close">
            <i class="fa-solid fa-xmark"></i>
        </button>
        <div id="variantPickerImg"></div>
        <div class="variant-picker-title" id="variantPickerTitle"></div>
        <div class="variant-picker-label">Choose your size / option:</div>
        <div class="variant-pills" id="variantPills"></div>
    </div>
</div>

<!-- ══ Products / Menu ═════════════════════════════════════ -->
<section class="products-section cbor-menu-section" id="menu">
    <div class="container">
        <?php
        $cartNotice = $_SESSION['cart_notice'] ?? null;
        unset($_SESSION['cart_notice']);
        if ($cartNotice):
        ?>
        <div class="cbor-notice-warn">
            <i class="fa-solid fa-circle-info"></i> <?= htmlspecialchars($cartNotice) ?>
        </div>
        <?php endif; ?>
        <?php
        $isTradeUser = !empty($_SESSION['trade_user']);
        if ($isTradeUser):
        ?>
        <div class="cbor-trade-banner">
            <div>
                <i class="fa-solid fa-store cbor-trade-banner-icon"></i>
                <strong>Trade Wholesale Active:</strong> <?= htmlspecialchars($_SESSION['trade_user']['business_name']) ?>
                <span class="cbor-trade-banner-note">(Wholesale pricing applied)</span>
            </div>
            <div class="cbor-trade-banner-actions">
                <a href="trade_profile.php" class="btn-secondary cbor-trade-banner-btn">
                    <i class="fa-solid fa-user"></i> My Account
                </a>
                <a href="trade_logout.php" class="btn-secondary cbor-trade-banner-btn">
                    <i class="fa-solid fa-right-from-bracket"></i> Logout Trade
                </a>
            </div>
        </div>
        <?php else: ?>
        <div class="cbor-trade-cta">
            <div>
                <i class="fa-solid fa-store cbor-trade-cta-icon"></i>
                <strong>Are you a Shop, Cafe, or Restaurant owner?</strong> Get wholesale pricing on bulk tubs.
            </div>
            <div class="cbor-trade-cta-links">
                <a href="trade_register.php" class="cbor-trade-cta-link">Apply for Trade &rarr;</a>
                <span class="cbor-trade-cta-sep">|</span>
                <a href="trade_login.php" class="cbor-trade-cta-link">Trade Login &rarr;</a>
            </div>
        </div>
        <?php endif; ?>

        <div class="section-header">
            <span class="section-label">Our Menu</span>
            <h1 class="section-title">Pick Your Favourite 🍨</h1>
            <p class="section-subtitle">Every product is handcrafted fresh daily with premium ingredients. No preservatives, just pure deliciousness.</p>
        </div>

        <!-- Category filter tabs -->
        <div class="category-tabs" id="catTabs">
            <button class="cat-tab active" data-cat="all">🍦 All</button>
            <?php foreach ($categories as $cat): ?>
            <button class="cat-tab" data-cat="<?= htmlspecialchars($cat['name']) ?>"><?= htmlspecialchars($cat['name']) ?></button>
            <?php endforeach; ?>
        </div>

        <!-- Products Grid -->
        <div class="products-grid" id="productsGrid">
            <?php foreach ($products as $product):
                $hasVariants  = !empty($variantsByProduct[$product['id']]);
                $variants     = $hasVariants ? $variantsByProduct[$product['id']] : [];
                $variantsJson = htmlspecialchars(json_encode($variants), ENT_QUOTES);

                // Price display
                if ($hasVariants) {
                    $prices   = array_column($variants, 'price');
                    $minPrice = min($prices);
                    $maxPrice = max($prices);
                } else {
                    $minPrice = $maxPrice = $product['price'];
                }

                $badgeClass = '';
                $badgeLabel = $product['badge'] ?? '';
                if ($badgeLabel === 'New')          $badgeClass = 'badge-new';
                elseif ($badgeLabel === 'Hot')      $badgeClass = 'badge-hot';
                elseif ($badgeLabel === 'Best Seller') $badgeClass = 'badge-best-seller';

                $imgSrc = !empty($product['image']) ? '../assets/images/products/' . htmlspecialchars($product['image']) : '';

                $isOutOfStock = ($product['track_stock'] ?? 0) && ($product['stock_qty'] ?? 1) <= 0;
                $stockLow     = ($product['track_stock'] ?? 0) && ($product['stock_qty'] ?? 0) > 0 && ($product['stock_qty'] ?? 0) <= 5;
            ?>
            <article class="product-card glass-panel<?php if ($isOutOfStock): ?> out-of-stock<?php endif; ?>"
                     data-category="<?= htmlspecialchars($product['category']) ?>"
                     id="pcard-<?= $product['id'] ?>"
                     <?php if ($isOutOfStock): ?>data-available="0"<?php endif; ?>>

                <?php if (!empty($product['badge'])): ?>
                <span class="product-badge <?= $badgeClass ?>"><?= htmlspecialchars($product['badge']) ?></span>
                <?php endif; ?>

                <!-- Product Image -->
                <div class="product-image-wrap">
                    <?php if ($imgSrc): ?>
                    <img src="<?= $imgSrc ?>" alt="<?= htmlspecialchars($product['name']) ?>" loading="lazy" onerror="this.style.display='none'; if(this.nextElementSibling) this.nextElementSibling.style.display='flex';">
                    <span class="product-image-placeholder cbor-hidden"><?= htmlspecialchars($product['emoji'] ?? '🍦') ?></span>
                    <?php else: ?>
                    <span class="product-image-placeholder"><?= htmlspecialchars($product['emoji'] ?? '🍦') ?></span>
                    <?php endif; ?>
                </div>

                <div class="product-body">
                    <span class="product-cat"><?= htmlspecialchars($product['category']) ?></span>
                    <h3 class="product-name"><?= htmlspecialchars($product['name']) ?></h3>
                    <p class="product-desc"><?= htmlspecialchars($product['description']) ?></p>

                    <?php if (!empty($product['nuts_allergy'])): ?>
                    <div class="allergy-tag">
                        <i class="fa-solid fa-triangle-exclamation"></i> Contains Nuts
                    </div>
                    <?php endif; ?>

                    <div class="product-footer">
                        <div>
                            <?php
                            $wPrice = (float)($product['wholesale_price'] ?? 0);
                            if ($isTradeUser && $wPrice > 0):
                            ?>
                            <div class="product-price-from cbor-price-trade-label">Trade Wholesale</div>
                            <div class="product-price">£<?= number_format($wPrice, 2) ?> <s class="cbor-price-strike">£<?= number_format($product['price'], 2) ?></s></div>
                            <?php elseif ($hasVariants): ?>
                            <div class="product-price-from">From</div>
                            <div class="product-price">£<?= number_format($minPrice, 2) ?></div>
                            <?php if ($minPrice < $maxPrice): ?>
                            <div class="product-price-range">up to £<?= number_format($maxPrice, 2) ?></div>
                            <?php endif; ?>
                            <?php else: ?>
                            <div class="product-price">£<?= number_format($product['price'], 2) ?></div>
                            <?php endif; ?>
                            <?php if ($stockLow && !$isOutOfStock): ?>
                            <div class="cbor-stock-low">⚠️ Only <?= (int)$product['stock_qty'] ?> left!</div>
                            <?php endif; ?>
                            <!-- In-cart indicator -->
                            <div class="in-cart-indicator" id="incart-<?= $product['id'] ?>">
                                <i class="fa-solid fa-check"></i>
                                <span id="incart-qty-<?= $product['id'] ?>">0</span> in cart
                            </div>
                        </div>
                        <?php if ($isOutOfStock): ?>
                        <button class="btn-add-cart cbor-btn-oos" disabled
                            title="Out of Stock">Out of Stock</button>
                        <?php else: ?>
                        <button class="btn-add-cart"
                            onclick="handleAddToCart(
                                <?= $product['id'] ?>,
                                '<?= addslashes($product['name']) ?>',
                                '<?= addslashes($product['emoji'] ?? '🍦') ?>',
                                '<?= addslashes($imgSrc) ?>',
                                <?= $hasVariants ? 'true' : 'false' ?>,
                                '<?= $variantsJson ?>'
                            )"
                            aria-label="Add <?= htmlspecialchars($product['name']) ?> to cart"
                            id="addbtn-<?= $product['id'] ?>"
                            title="<?= $hasVariants ? 'Choose size' : 'Add to cart' ?>">
                            <i class="fa-solid fa-<?= $hasVariants ? 'caret-down' : 'plus' ?>"></i>
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ══ Cart Sidebar ════════════════════════════════════════ -->
<div class="cart-overlay" id="cartOverlay" onclick="closeCart()"></div>
<aside class="cart-sidebar" id="cartSidebar">
    <div class="cart-header">
        <h2><i class="fa-solid fa-bag-shopping cbor-cart-title-icon"></i> Your Order</h2>
        <button class="btn-close-cart" onclick="closeCart()" aria-label="Close cart">
            <i class="fa-solid fa-xmark"></i>
        </button>
    </div>

    <div class="cart-items" id="cartItems">
        <div class="cart-empty">
            <div class="cart-empty-icon">🛒</div>
            <p>Your cart is empty.<br>Add something delicious!</p>
        </div>
    </div>

    <div class="cart-footer cbor-hidden" id="cartFooter">
        <div class="cart-total-row">
            <span class="cart-total-label">Total</span>
            <span class="cart-total-amount" id="cartTotal">£0.00</span>
        </div>
        <a href="checkout.php" class="btn-primary btn-checkout">
            <i class="fa-solid fa-arrow-right"></i> Proceed to Checkout
        </a>
        <button class="btn-clear-cart" onclick="clearCart()">
            <i class="fa-solid fa-trash"></i> Clear Cart
        </button>
    </div>
</aside>

<!-- ══ Toast ════════════════════════════════════════════════ -->
<div class="toast" id="toast">
    <span class="toast-icon">🍦</span>
    <div>
        <div class="toast-text" id="toastText">Added to cart!</div>
        <div class="toast-sub" id="toastSub"></div>
    </div>
</div>


<!-- ══ Footer ══════════════════════════════════════════════ -->
<footer class="footer-enhanced">
    <div class="container">
        <div class="footer-top">
            <div class="footer-brand">
                <a href="../index.php" class="footer-logo-link"><img src="../assets/images/logo.png" alt="<?= SHOP_NAME ?>" class="footer-logo-img"></a>
                <p>Handcrafted ice cream and cocoa drinks made fresh daily with the finest ingredients.</p>
                <div class="footer-social">
                    <a href="https://www.instagram.com/creamybiteicecream" target="_blank" rel="noopener noreferrer" aria-label="Instagram"><i class="fa-brands fa-instagram"></i></a>
                    <a href="https://www.facebook.com/share/17oFEAg77U/?mibextid=wwXIfr" target="_blank" rel="noopener noreferrer" aria-label="Facebook"><i class="fa-brands fa-facebook"></i></a>
                    <a href="#" aria-label="TikTok"><i class="fa-brands fa-tiktok"></i></a>
                    <a href="https://wa.me/447497779997" target="_blank" rel="noopener noreferrer" aria-label="WhatsApp"><i class="fa-brands fa-whatsapp"></i></a>
                </div>
            </div>
            <div class="footer-col">
                <h4>Pages</h4>
                <ul>
                    <li><a href="../index.php">Home</a></li>
                    <li><a href="order.php">Order</a></li>
                    <li><a href="gallery.php">Gallery</a></li>
                    <li><a href="about.php">About Us</a></li>
                </ul>
            </div>
            <div class="footer-col">
                <h4>Order</h4>
                <ul>
                    <li><a href="order.php">Browse Menu</a></li>
                    <li><a href="checkout.php">Checkout</a></li>
                </ul>
            </div>
            <div class="footer-col">
                <h4>B2B Trade</h4>
                <ul>
                    <li><a href="trade_register.php">Apply for Trade Account</a></li>
                    <li><a href="trade_login.php">Trade Partner Login</a></li>
                    <li><a href="../admin/login.php">Admin Login</a></li>
                </ul>
            </div>
        </div>
        <div class="footer-bottom-bar">
            <a href="../index.php"><img src="../assets/images/logo.png" alt="<?= SHOP_NAME ?>" class="footer-logo-img cbor-footer-logo-sm"></a>
            <span class="footer-copy-text">&copy; <?= date('Y') ?> CreamyBite.com &mdash; Made with ❤️</span>
        </div>
    </div>
</footer>

<!-- ══ Scripts ══════════════════════════════════════════════ -->
<script>
// ── Cart state ──────────────────────────────────────────────
let cartState = { items: [], cart_count: 0, cart_total: '0.00' };

// ── Open / Close cart ────────────────────────────────────────
function openCart()  { document.getElementById('cartSidebar').classList.add('open'); document.getElementById('cartOverlay').classList.add('open'); document.body.style.overflow = 'hidden'; }
function closeCart() { document.getElementById('cartSidebar').classList.remove('open'); document.getElementById('cartOverlay').classList.remove('open'); document.body.style.overflow = ''; }

// ── Variant Picker ───────────────────────────────────────────
let currentPickerData = null;

function handleAddToCart(productId, name, emoji, imgSrc, hasVariants, variantsJson) {
    if (!hasVariants) {
        addToCart(productId, 0, name, emoji);
        return;
    }
    // Parse variants
    let variants;
    try { variants = JSON.parse(variantsJson); } catch(e) { variants = []; }
    if (!variants.length) { addToCart(productId, 0, name, emoji); return; }

    openVariantPicker(productId, name, emoji, imgSrc, variants);
}

function openVariantPicker(productId, name, emoji, imgSrc, variants) {
    currentPickerData = { productId, name, emoji, imgSrc, variants };

    // Set image/emoji
    const imgEl = document.getElementById('variantPickerImg');
    if (imgSrc) {
        imgEl.innerHTML = `<img class="variant-picker-img" src="${escHtml(imgSrc)}" alt="${escHtml(name)}">`;
    } else {
        imgEl.innerHTML = `<span class="variant-picker-emoji">${escHtml(emoji)}</span>`;
    }

    document.getElementById('variantPickerTitle').textContent = name;

    // Build pills
    const pillsEl = document.getElementById('variantPills');
    pillsEl.innerHTML = '';
    variants.forEach(v => {
        const btn = document.createElement('button');
        btn.className = 'variant-pill';
        btn.setAttribute('data-variant-id', v.id);
        btn.innerHTML = `<span class="variant-pill-name">${escHtml(v.name)}</span><span class="variant-pill-price">£${parseFloat(v.price).toFixed(2)}</span>`;
        btn.addEventListener('click', () => {
            // Highlight selected
            pillsEl.querySelectorAll('.variant-pill').forEach(p => p.classList.remove('selected'));
            btn.classList.add('selected');
            // Short delay then add and close
            setTimeout(() => {
                addToCart(productId, v.id, name, emoji, v.name, v.price);
                closeVariantPicker();
            }, 180);
        });
        pillsEl.appendChild(btn);
    });

    document.getElementById('variantPicker').classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closeVariantPicker() {
    document.getElementById('variantPicker').classList.remove('open');
    document.body.style.overflow = '';
    currentPickerData = null;
}

// Close on backdrop click
document.getElementById('variantPicker').addEventListener('click', function(e) {
    if (e.target === this) closeVariantPicker();
});
// Close on Escape
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') { closeCart(); closeMobileMenu(); closeVariantPicker(); }
});

// ── Add to Cart ──────────────────────────────────────────────
function addToCart(productId, variantId, name, emoji, variantName, variantPrice) {
    variantId = variantId || 0;
    let body = 'action=add&product_id=' + productId;
    if (variantId > 0) body += '&variant_id=' + variantId;

    fetch('../cart_handler.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body,
    })
    .then(r => r.json())
    .then(data => {
        if (data.success !== false) {
            cartState = data;
            renderCart();
            updateInCartIndicators();
            const label = variantName ? name + ' (' + variantName + ')' : name;
            showToast('Added to Order!', label + ' added to your cart');
            bumpBadge();

            if (!sessionStorage.getItem('delivery_popup_shown')) {
                sessionStorage.setItem('delivery_popup_shown', '1');
                setTimeout(showDeliveryRadiusPopup, 600);
            }
        } else {
            // The server refuses for real reasons — sold out, trade-only,
            // withdrawn. This branch used to be missing entirely, so the
            // click simply did nothing and the customer had no idea why.
            showToast('Cannot add this item', data.message || 'That item is not available right now.');
        }
    })
    .catch(() => showToast('⚠️ Error', 'Could not add item, please try again.'));
}

// ── Remove item ──────────────────────────────────────────────
function removeItem(cartKey) {
    fetch('../cart_handler.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=remove&cart_key=' + encodeURIComponent(cartKey),
    })
    .then(r => r.json()).then(data => { cartState = data; renderCart(); updateBadge(); updateInCartIndicators(); });
}

// ── Update quantity ───────────────────────────────────────────
function updateQty(cartKey, qty) {
    fetch('../cart_handler.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=update&cart_key=' + encodeURIComponent(cartKey) + '&quantity=' + qty,
    })
    .then(r => r.json()).then(data => { cartState = data; renderCart(); updateBadge(); updateInCartIndicators(); });
}

// ── Clear cart ───────────────────────────────────────────────
function clearCart() {
    fetch('../cart_handler.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=clear',
    })
    .then(r => r.json()).then(data => { cartState = data; renderCart(); updateBadge(); updateInCartIndicators(); });
}

// ── Render cart sidebar ──────────────────────────────────────
function renderCart() {
    const itemsEl  = document.getElementById('cartItems');
    const footerEl = document.getElementById('cartFooter');
    const totalEl  = document.getElementById('cartTotal');

    if (!cartState.items || cartState.items.length === 0) {
        itemsEl.innerHTML = `<div class="cart-empty"><div class="cart-empty-icon">🛒</div><p>Your cart is empty.<br>Add something delicious!</p></div>`;
        footerEl.style.display = 'none';
        return;
    }


    let html = '';
    cartState.items.forEach(item => {
        const subtotal  = (item.price * item.quantity).toFixed(2);
        const cartKey   = item.cart_key || item.product_id;
        const safeKey   = encodeURIComponent(cartKey);
        const imgHtml   = item.image
            ? `<img class="cart-item-img" src="../assets/images/products/${escHtml(item.image)}" alt="${escHtml(item.name)}">`
            : `<div class="cart-item-img-placeholder">${escHtml(item.emoji)}</div>`;
        const variantLabel = item.variant_name ? `<span style="font-size:11px;color:var(--text-muted);font-weight:500;">${escHtml(item.variant_name)}</span>` : '';
        html += `
        <div class="cart-item">
            ${imgHtml}
            <div class="cart-item-info">
                <div class="cart-item-name">${escHtml(item.name)}${variantLabel ? '<br>' + variantLabel : ''}</div>
                <div class="cart-item-price">£${subtotal}</div>
            </div>
            <div class="qty-controls">
                <button class="qty-btn" onclick="updateQty('${cartKey}', ${item.quantity - 1})">−</button>
                <span class="qty-value">${item.quantity}</span>
                <button class="qty-btn" onclick="updateQty('${cartKey}', ${item.quantity + 1})">+</button>
            </div>
            <button class="btn-remove-item" onclick="removeItem('${cartKey}')" title="Remove">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>`;
    });

    itemsEl.innerHTML = html;
    totalEl.textContent = '£' + cartState.cart_total;
    footerEl.style.display = 'block';
} // ← end renderCart

// ── Update in-cart indicators on product cards ───────────────

function updateInCartIndicators() {
    // Sum quantity per product_id (across all variants)
    const qtyMap = {};
    if (cartState.items) {
        cartState.items.forEach(item => {
            const pid = item.product_id;
            qtyMap[pid] = (qtyMap[pid] || 0) + item.quantity;
        });
    }
    document.querySelectorAll('[id^="incart-"]').forEach(el => {
        const pid  = el.id.replace('incart-', '');
        const qty  = qtyMap[pid] || 0;
        const qtyEl = document.getElementById('incart-qty-' + pid);
        if (qty > 0) {
            el.classList.add('visible');
            if (qtyEl) qtyEl.textContent = qty;
        } else {
            el.classList.remove('visible');
        }
    });
}

// ── Badge helpers ────────────────────────────────────────────
function updateBadge() {
    document.getElementById('cartBadge').textContent = cartState.cart_count || 0;
}
function bumpBadge() {
    const badge = document.getElementById('cartBadge');
    badge.textContent = cartState.cart_count;
    badge.classList.add('bump');
    setTimeout(() => badge.classList.remove('bump'), 400);
}

// ── Toast ────────────────────────────────────────────────────
let toastTimeout;
function showToast(title, sub) {
    const toast = document.getElementById('toast');
    document.getElementById('toastText').textContent = title;
    document.getElementById('toastSub').textContent  = sub || '';
    toast.classList.add('show');
    clearTimeout(toastTimeout);
    toastTimeout = setTimeout(() => toast.classList.remove('show'), 3000);
}

// ── Category filter ──────────────────────────────────────────
document.querySelectorAll('.cat-tab').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.cat-tab').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        const cat = btn.dataset.cat;
        document.querySelectorAll('.product-card').forEach(card => {
            const show = cat === 'all' || card.dataset.category === cat;
            card.style.display = show ? 'flex' : 'none';
            if (show) { card.style.opacity = '0'; setTimeout(() => card.style.opacity = '1', 20); }
        });
    });
});

// ── Escape HTML helper ───────────────────────────────────────
function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Mobile nav ──────────────────────────────────────────────
const ham = document.getElementById('navHamburger');
const drawer = document.getElementById('mobileDrawer');
const drawerClose = document.getElementById('mobileDrawerClose');
function openMobileMenu()  { ham.classList.add('open'); drawer.classList.add('open'); document.body.style.overflow='hidden'; }
function closeMobileMenu() { ham.classList.remove('open'); drawer.classList.remove('open'); document.body.style.overflow=''; }
ham.addEventListener('click', openMobileMenu);
drawerClose.addEventListener('click', closeMobileMenu);
drawer.addEventListener('click', e => { if (e.target === drawer) closeMobileMenu(); });

// ── Load cart on page start ──────────────────────────────────
fetch('../cart_handler.php?action=get')
    .then(r => r.json())
    .then(data => { cartState = data; renderCart(); updateBadge(); updateInCartIndicators(); });

function showDeliveryRadiusPopup() {
    const pop = document.getElementById('deliveryRadiusPopup');
    if (pop) pop.style.display = 'block';
}
function closeDeliveryPopup() {
    const pop = document.getElementById('deliveryRadiusPopup');
    if (pop) pop.style.display = 'none';
}
</script>

<!-- ── Delivery 6-Mile Radius Notification Pop-up ────────────── -->
<div id="deliveryRadiusPopup" class="cbor-delivery-popup">
    <div class="cbor-delivery-popup-row">
        <div class="cbor-delivery-popup-emoji">🚚</div>
        <div class="cbor-delivery-popup-body">
            <strong class="cbor-delivery-popup-title">
                📍 Harrow Delivery Area Notice
            </strong>
            <p class="cbor-delivery-popup-text">
                We deliver fresh handcrafted ice cream within a <strong>6-mile radius of Harrow (HA1 4EX / HA1 2SP)</strong>.<br>
                <span class="cbor-delivery-popup-highlight">🎉 Free delivery under 3 miles!</span>
            </p>
            <div class="cbor-delivery-popup-foot">
                <i class="fa-solid fa-store cbor-delivery-popup-foot-icon"></i> Warehouse collection is also available!
            </div>
        </div>
        <button onclick="closeDeliveryPopup()" class="cbor-delivery-popup-close">
            <i class="fa-solid fa-xmark"></i>
        </button>
    </div>
</div>
<script src="../assets/js/modal.js" defer></script>
<script src="../assets/js/animations.js" defer></script>
</body>
</html>
