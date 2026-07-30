<?php
// ============================================================
//  Creamy Bite – Home / Landing Page
// ============================================================
session_start();
require_once 'db.php';
require_once 'config.php';

// Load a few featured products for the flavour showcase
$featured = [];
try {
    $stmt = $pdo->query("SELECT * FROM products WHERE available = 1 ORDER BY id ASC LIMIT 6");
    $featured = $stmt->fetchAll();
} catch (PDOException $e) { }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Creamy Bite – Handcrafted Ice Cream &amp; Cocoa Drinks</title>
    <meta name="description" content="Creamy Bite serves handcrafted ice cream and rich cocoa drinks made fresh daily with the finest ingredients. Discover our story and explore our flavours.">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="responsive.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>

<!-- ══ Navbar ══════════════════════════════════════════════ -->
<header class="navbar">
    <div class="container nav-container-centered">
        <nav class="nav-left">
            <ul class="nav-links">
                <li><a href="index.php" class="active">Home</a></li>
                <li><a href="order.php">Order</a></li>
                <li><a href="gallery.php">Gallery</a></li>
                <li><a href="about.php">About Us</a></li>
            </ul>
        </nav>

        <a href="index.php" class="logo logo-center">
            <img src="assets/images/logo.png" alt="<?= SHOP_NAME ?>" class="logo-img">
        </a>

        <div class="nav-actions nav-right">
            <a href="order.php" class="btn-primary" style="padding:10px 20px; font-size:14px;">
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
            <li><a href="order.php">Order</a></li>
            <li><a href="gallery.php">Gallery</a></li>
            <li><a href="about.php">About Us</a></li>
        </ul>
        <div class="mobile-nav-actions">
            <a href="order.php" class="btn-primary" style="justify-content:center; display:flex;">
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
            <a href="order.php" class="btn-hero-primary">
                🍦 Explore Our Menu
            </a>
            <a href="about.php" class="btn-hero-secondary">
                Our Story
            </a>
        </div>
    </div>
</section>

<!-- ══ Stats Strip ═════════════════════════════════════════ -->
<div class="hero-stats">
    <div class="container" style="display:flex;">
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

        <div style="text-align:center; margin-top:40px;">
            <a href="order.php" class="btn-primary" style="font-size:15px; padding:14px 32px;">
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
                            <div style="font-size:12px; color:var(--text-muted);">Real ingredients only</div>
                        </div>
                    </div>
                    <div class="story-highlight">
                        <span class="story-highlight-icon">🥛</span>
                        <div>
                            <strong>Fresh Daily</strong>
                            <div style="font-size:12px; color:var(--text-muted);">Handcrafted in-house</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ══ CTA Banner ═══════════════════════════════════════════ -->
<section class="landing-cta-section">
    <div class="container" style="text-align:center;">
        <span class="section-label" style="background:rgba(255,255,255,0.2); color:white;">Treat Yourself Today</span>
        <h2>Ready for a Scoop of Happiness?</h2>
        <p>Browse our menu, pick your favourites, and get them delivered fresh to your doorstep.</p>
        <a href="order.php" class="btn-cta-white">
            <i class="fa-solid fa-bag-shopping"></i> Order Online Now
        </a>
    </div>
</section>

<!-- ══ B2B Trade Callout ═════════════════════════════════════ -->
<section style="background:var(--color-primary-bg); border-top:1px solid rgba(199,136,41,0.15); border-bottom:1px solid rgba(199,136,41,0.15); padding:32px 0;">
    <div class="container" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:20px;">
        <div>
            <span style="font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.12em; color:var(--color-primary); background:rgba(92,29,36,0.08); padding:4px 12px; border-radius:14px;">🏪 B2B Wholesale</span>
            <h3 style="font-size:20px; margin:8px 0 4px; color:var(--text-primary);">Are you a Retailer, Shop, or Cafe Owner?</h3>
            <p style="font-size:14px; color:var(--text-secondary); margin:0; max-width:520px;">
                Sell Creamy Bite in your store! Get discounted wholesale pricing, bulk ice cream tubs, and dedicated trade support.
            </p>
        </div>
        <div style="display:flex; gap:12px; flex-wrap:wrap;">
            <a href="trade_register.php" class="btn-primary" style="padding:12px 24px; font-size:14px;">
                <i class="fa-solid fa-store"></i> Apply for Trade Account
            </a>
            <a href="trade_login.php" class="btn-secondary" style="padding:12px 20px; font-size:14px;">
                <i class="fa-solid fa-right-to-bracket"></i> Trade Login
            </a>
        </div>
    </div>
</section>

<!-- ══ Footer ══════════════════════════════════════════════ -->
<footer class="footer-enhanced">
    <div class="container">
        <div class="footer-top">
            <div class="footer-brand">
                <a href="index.php"><img src="assets/images/logo.png" alt="<?= SHOP_NAME ?>" class="footer-logo-img"></a>
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
                    <li><a href="index.php">Home</a></li>
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
                    <li><a href="trade_register.php">Apply for Trade</a></li>
                    <li><a href="trade_login.php">Trade Login</a></li>
                    <li><a href="admin/login.php">Admin Login</a></li>
                </ul>
            </div>
        </div>
        <div class="footer-bottom-bar">
            <a href="index.php"><img src="assets/images/logo.png" alt="<?= SHOP_NAME ?>" class="footer-logo-img" style="height:32px;"></a>
            <span class="footer-copy-text">&copy; <?= date('Y') ?> CreamyBite.com &mdash; Made with ❤️</span>
        </div>
    </div>
</footer>

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
</body>
</html>
