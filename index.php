<?php
// ============================================================
//  Creamy Bite – Home / Landing Page
// ============================================================
session_start();
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/config.php';

// Load a few featured products for the flavour showcase
$featured = [];
try {
    // The home page is public, so never feature a trade-only product here.
    $stmt = $pdo->query("SELECT * FROM products WHERE available = 1 AND trade_only = 0 ORDER BY id ASC LIMIT 6");
    $featured = $stmt->fetchAll();
} catch (PDOException $e) { }

// Customer reviews for the testimonials strip.
//
// Approved only, and the section hides itself entirely when there are none —
// an empty "What our customers say" heading looks worse than no section at
// all. Nothing is seeded: inventing testimonials to fill the space is banned
// under the Digital Markets, Competition and Consumers Act 2024, so this
// stays empty until real ones are approved in the admin panel.
$testimonials = [];
try {
    $testimonials = $pdo->query(
        "SELECT * FROM testimonials WHERE approved = 1
         ORDER BY featured DESC, sort_order ASC, created_at DESC LIMIT 6"
    )->fetchAll();
} catch (PDOException $e) {
    // Table does not exist until the migration has run. Show nothing rather
    // than taking the home page down over a missing testimonials table.
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Creamy Bite – Handcrafted Ice Cream &amp; Cocoa Drinks</title>
    <meta name="description" content="Creamy Bite serves handcrafted ice cream and rich cocoa drinks made fresh daily with the finest ingredients. Discover our story and explore our flavours.">
<?php $cbSeoLocalBusiness = true; ?>
<?php require __DIR__ . '/includes/seo_head.php'; ?>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="assets/css/responsive.css">
    <link rel="stylesheet" href="assets/css/animations.css">
    <link rel="stylesheet" href="assets/css/components.css">
    <link rel="stylesheet" href="assets/css/modal.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>

<!-- ══ Navbar ══════════════════════════════════════════════ -->
<header class="navbar">
    <div class="container nav-container-centered">
        <nav class="nav-left">
            <ul class="nav-links">
                <li><a href="index.php" class="active">Home</a></li>
                <li><a href="pages/order.php">Order</a></li>
                <li><a href="pages/gallery.php">Gallery</a></li>
                <li><a href="pages/about.php">About Us</a></li>
            </ul>
        </nav>

        <a href="index.php" class="logo logo-center">
            <img src="assets/images/logo.png" alt="<?= SHOP_NAME ?>" class="logo-img">
        </a>

        <div class="nav-actions nav-right">
            <?php include __DIR__ . '/includes/trade_nav_button.php'; ?>
            <a href="pages/order.php" class="btn-primary cbhm-btn-nav">
                <i class="fa-solid fa-bolt"></i> Order Now
            </a>
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
            <li><a href="index.php" class="active">Home</a></li>
            <li><a href="pages/order.php">Order</a></li>
            <li><a href="pages/gallery.php">Gallery</a></li>
            <li><a href="pages/about.php">About Us</a></li>
        </ul>
        <div class="mobile-nav-actions">
            <a href="pages/order.php" class="btn-primary cbhm-btn-drawer">
                <i class="fa-solid fa-bolt"></i> Order Now
            </a>
        </div>
    </div>
</div>

<!-- ══ Hero ════════════════════════════════════════════════ -->
<section class="landing-hero">
    <div class="container landing-hero-inner">
        <div class="landing-eyebrow">✨ Freshly Made Every Day</div>
        <h1 class="landing-title">Every Scoop is a<br>Sweet Adventure!</h1>
        <p class="landing-subtitle">
            At Creamy Bite, we handcraft every flavour with the finest ingredients.
            From classic vanilla scoops to rich Belgian cocoa drinks — pure joy in every bite. 🍦
        </p>
        <div class="landing-cta-group">
            <a href="pages/order.php" class="btn-hero-primary">
                🍦 Explore Our Menu
            </a>
            <a href="pages/about.php" class="btn-hero-secondary">
                Our Story
            </a>
        </div>
    </div>
</section>

<!-- ══ Stats Strip ═════════════════════════════════════════ -->
<div class="hero-stats">
    <div class="container cbhm-stats-row">
        <div class="hero-stat">
            <div class="hero-stat-value">10+</div>
            <div class="hero-stat-label">Unique Flavours</div>
        </div>
        <div class="hero-stat">
            <div class="hero-stat-value">100%</div>
            <div class="hero-stat-label">Fresh Ingredients</div>
        </div>
        <div class="hero-stat">
            <div class="hero-stat-value">Daily</div>
            <div class="hero-stat-label">Made Fresh</div>
        </div>
        <div class="hero-stat">
            <div class="hero-stat-value">Free</div>
            <div class="hero-stat-label">Local Delivery</div>
        </div>
    </div>
</div>

<!-- ══ Ice Cream Flavours Showcase ════════════════════════ -->
<section class="flavours-section">
    <div class="container">
        <div class="section-header">
            <span class="section-label">Our Flavours</span>
            <h2 class="section-title">A Taste for Every Mood 🍨</h2>
            <p class="section-subtitle">From indulgent chocolate to refreshing sorbets — handcrafted with love for every craving.</p>
        </div>
        <div class="flavour-cards-grid">
            <?php foreach ($featured as $p):
                $imgSrc = !empty($p['image']) ? 'assets/images/products/' . htmlspecialchars($p['image']) : '';
            ?>
            <div class="flavour-card">
                <div class="flavour-card-img">
                    <?php if ($imgSrc): ?>
                    <img src="<?= $imgSrc ?>" alt="<?= htmlspecialchars($p['name']) ?>" loading="lazy">
                    <?php else: ?>
                    <?= htmlspecialchars($p['emoji'] ?? '🍦') ?>
                    <?php endif; ?>
                </div>
                <div class="flavour-card-body">
                    <div class="flavour-card-name"><?= htmlspecialchars($p['name']) ?></div>
                    <p class="flavour-card-desc"><?= htmlspecialchars($p['description']) ?></p>
                </div>
            </div>
            <?php endforeach; ?>

            <?php if (empty($featured)): ?>
            <!-- Placeholder flavour cards if no products yet -->
            <?php
            $placeholders = [
                ['🍦','Classic Vanilla','Rich, creamy vanilla made with real vanilla beans and fresh dairy.'],
                ['🍫','Dark Chocolate','Indulgent Belgian dark chocolate with a velvety smooth texture.'],
                ['🍓','Strawberry Dream','Fresh-picked strawberries blended into a light fruity ice cream.'],
                ['🥭','Mango Sorbet','Dairy-free tropical mango sorbet made with 100% real Alphonso mangoes.'],
                ['🌈','Rainbow Sherbet','A vibrant swirl of raspberry, lime, and orange sherbet.'],
                ['☕','Cocoa Classic','Rich Belgian cocoa blended to perfection — smooth and creamy.'],
            ];
            foreach ($placeholders as $ph): ?>
            <div class="flavour-card">
                <div class="flavour-card-img"><?= $ph[0] ?></div>
                <div class="flavour-card-body">
                    <div class="flavour-card-name"><?= $ph[1] ?></div>
                    <p class="flavour-card-desc"><?= $ph[2] ?></p>
                </div>
            </div>
            <?php endforeach; endif; ?>
        </div>

        <div class="cbhm-flavours-cta">
            <a href="pages/order.php" class="btn-primary cbhm-btn-wide">
                <i class="fa-solid fa-arrow-right"></i> See Full Menu & Order
            </a>
        </div>
    </div>
</section>

<!-- ══ Our Story ════════════════════════════════════════════ -->
<section class="story-section">
    <div class="container">
        <div class="story-grid">
            <div class="story-img-block">
                <img src="assets/images/story_artisan.jpg" alt="Creamy Bite Handcrafted Ice Cream Story" loading="lazy">
            </div>
            <div class="story-content">
                <span class="section-label">Our Story</span>
                <h2>Born from a Love of<br>Real Flavours</h2>
                <p>
                    Creamy Bite was born from a simple belief — that ice cream should be something special.
                    Not mass-produced, not full of artificial flavours, but handcrafted with care, using
                    the finest ingredients we can find.
                </p>
                <p>
                    Every scoop, every cocoa drink, every flavour we create starts the same way:
                    with real ingredients, proper techniques, and a genuine passion for making
                    people smile. We churn our ice cream slowly, blend our cocoas carefully,
                    and never take shortcuts with quality.
                </p>
                <p>
                    Whether you're treating yourself or sharing with family, we want every
                    bite to bring a smile to your face.
                </p>
                <div class="story-highlights">
                    <div class="story-highlight">
                        <span class="story-highlight-icon">🌱</span>
                        <div>
                            <strong>100% Natural</strong>
                            <div class="cbhm-highlight-sub">Real ingredients only</div>
                        </div>
                    </div>
                    <div class="story-highlight">
                        <span class="story-highlight-icon">🥛</span>
                        <div>
                            <strong>Fresh Daily</strong>
                            <div class="cbhm-highlight-sub">Handcrafted in-house</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ══ Reviews ══════════════════════════════════════════════ -->
<?php if (!empty($testimonials)):
    $cbAvg = array_sum(array_map(static fn($r) => (int)$r['rating'], $testimonials)) / count($testimonials);
?>
<section class="cbrev-section" id="reviews">
    <div class="container">
        <div class="cbrev-head">
            <span class="section-label">Kind Words</span>
            <h2 class="section-title">What Our Customers Say 💬</h2>
            <div class="cbrev-summary">
                <div class="cbrev-stars" aria-label="<?= number_format($cbAvg, 1) ?> out of 5">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                        <i class="fa-<?= $i <= round($cbAvg) ? 'solid' : 'regular' ?> fa-star"></i>
                    <?php endfor; ?>
                </div>
                <span class="cbrev-summary-text">
                    <strong><?= number_format($cbAvg, 1) ?></strong>
                    from <?= count($testimonials) ?> review<?= count($testimonials) === 1 ? '' : 's' ?>
                </span>
            </div>
        </div>

        <div class="cbrev-grid">
            <?php foreach ($testimonials as $r): ?>
            <article class="cbrev-card">
                <div class="cbrev-stars cbrev-stars-sm" aria-label="<?= (int)$r['rating'] ?> out of 5">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                        <i class="fa-<?= $i <= (int)$r['rating'] ? 'solid' : 'regular' ?> fa-star"></i>
                    <?php endfor; ?>
                </div>
                <blockquote class="cbrev-body"><?= nl2br(htmlspecialchars($r['body'])) ?></blockquote>
                <footer class="cbrev-meta">
                    <strong><?= htmlspecialchars($r['customer_name']) ?></strong>
                    <?php
                        // Town and flavour are both optional, so build the byline
                        // from whatever is actually there rather than printing
                        // stray separators around empty values.
                        $bits = [];
                        if (trim((string)$r['location']) !== '')     { $bits[] = $r['location']; }
                        if (trim((string)$r['product_name']) !== '') { $bits[] = 'on ' . $r['product_name']; }
                    ?>
                    <?php if ($bits): ?>
                    <span class="cbrev-sub"><?= htmlspecialchars(implode(' · ', $bits)) ?></span>
                    <?php endif; ?>
                </footer>
            </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- ══ CTA Banner ═══════════════════════════════════════════ -->
<section class="landing-cta-section">
    <div class="container cbhm-cta-inner">
        <span class="section-label cbhm-label-on-dark">Treat Yourself Today</span>
        <h2>Ready for a Scoop of Happiness?</h2>
        <p>Browse our menu, pick your favourites, and get them delivered fresh to your doorstep.</p>
        <a href="pages/order.php" class="btn-cta-white">
            <i class="fa-solid fa-bag-shopping"></i> Order Online Now
        </a>
    </div>
</section>

<!-- ══ B2B Trade Callout ═════════════════════════════════════ -->
<section class="cbhm-trade-band">
    <div class="container cbhm-trade-band-inner">
        <div>
            <span class="cbhm-trade-pill">🏪 B2B Wholesale</span>
            <h3 class="cbhm-trade-heading">Are you a Retailer, Shop, or Cafe Owner?</h3>
            <p class="cbhm-trade-copy">
                Sell Creamy Bite in your store! Get discounted wholesale pricing, bulk ice cream tubs, and dedicated trade support.
            </p>
        </div>
        <div class="cbhm-trade-actions">
            <a href="pages/trade_register.php" class="btn-primary cbhm-btn-trade">
                <i class="fa-solid fa-store"></i> Apply for Trade Account
            </a>
            <a href="pages/trade_login.php" class="btn-secondary cbhm-btn-trade-alt">
                <i class="fa-solid fa-right-to-bracket"></i> Trade Login
            </a>
        </div>
    </div>
</section>

<!-- ══ Footer ══════════════════════════════════════════════ -->
<?php // One shared footer — it used to be copied into five pages, so adding a
      // link meant editing all five and hoping none were missed. ?>
<?php require __DIR__ . '/includes/site_footer.php'; ?>

<script>
// ── Mobile nav ──────────────────────────────────────────────
const ham = document.getElementById('navHamburger');
const drawer = document.getElementById('mobileDrawer');
const drawerClose = document.getElementById('mobileDrawerClose');
function openMobileMenu()  { ham.classList.add('open'); drawer.classList.add('open'); document.body.style.overflow='hidden'; }
function closeMobileMenu() { ham.classList.remove('open'); drawer.classList.remove('open'); document.body.style.overflow=''; }
ham.addEventListener('click', openMobileMenu);
drawerClose.addEventListener('click', closeMobileMenu);
drawer.addEventListener('click', e => { if (e.target === drawer) closeMobileMenu(); });
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeMobileMenu(); });
</script>
<script src="assets/js/modal.js" defer></script>
<script src="assets/js/animations.js" defer></script>
</body>
</html>
