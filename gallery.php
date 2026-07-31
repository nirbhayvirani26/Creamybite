<?php
// ============================================================
//  Creamy Bite – Gallery Page
// ============================================================
session_start();
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';

$gallery = [];
try {
    $gallery = $pdo->query("SELECT * FROM gallery ORDER BY sort_order ASC, created_at DESC")->fetchAll();
} catch (PDOException $e) { }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gallery – <?= SHOP_NAME ?></title>
    <meta name="description" content="Browse our gallery of handcrafted ice cream and cocoa drinks at <?= SHOP_NAME ?>.">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="responsive.css">
    <link rel="stylesheet" href="animations.css">
    <link rel="stylesheet" href="components.css">
    <link rel="stylesheet" href="modal.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>

<!-- ══ Navbar ══════════════════════════════════════════════ -->
<header class="navbar">
    <div class="container nav-container-centered">
        <nav class="nav-left">
            <ul class="nav-links">
                <li><a href="index.php">Home</a></li>
                <li><a href="order.php">Order</a></li>
                <li><a href="gallery.php" class="active">Gallery</a></li>
                <li><a href="about.php">About Us</a></li>
            </ul>
        </nav>

        <a href="index.php" class="logo logo-center">
            <img src="assets/images/logo.png" alt="<?= SHOP_NAME ?>" class="logo-img">
        </a>

        <div class="nav-actions nav-right">
            <?php include __DIR__ . '/trade_nav_button.php'; ?>
            <a href="order.php" class="btn-primary cb-gal-cta" >
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
            <li><a href="index.php">Home</a></li>
            <li><a href="order.php">Order</a></li>
            <li><a href="gallery.php" class="active">Gallery</a></li>
            <li><a href="about.php">About Us</a></li>
        </ul>
        <div class="mobile-nav-actions">
            <a href="order.php" class="btn-primary cb-gal-drawer-cta" >
                <i class="fa-solid fa-bolt"></i> Order Now
            </a>
        </div>
    </div>
</div>

<!-- ══ Gallery Hero ════════════════════════════════════════ -->
<section class="about-hero">
    <div class="container cb-gal-layer" >
        <div class="about-hero-eyebrow">📸 Our Gallery</div>
        <h1>A Feast for the Eyes 🍦</h1>
        <p>A glimpse into our world of handcrafted ice cream and cocoa drinks, captured beautifully.</p>
    </div>
</section>

<!-- ══ Gallery Grid ════════════════════════════════════════ -->
<section class="gallery-page">
    <div class="container">

        <?php if (empty($gallery)): ?>
        <div class="gallery-empty">
            <div class="gallery-empty-icon">📷</div>
            <p class="cb-gal-lead">No photos yet — check back soon!</p>
            <a href="order.php" class="btn-primary">
                <i class="fa-solid fa-arrow-right"></i> Browse Our Menu
            </a>
        </div>
        <?php else: ?>
        <div class="gallery-grid" id="galleryGrid">
            <?php foreach ($gallery as $img): ?>
            <div class="gallery-item" onclick="openLightbox('assets/images/gallery/<?= htmlspecialchars($img['filename']) ?>', '<?= addslashes(htmlspecialchars($img['caption'])) ?>')">
                <img
                    src="assets/images/gallery/<?= htmlspecialchars($img['filename']) ?>"
                    alt="<?= htmlspecialchars($img['caption'] ?: 'Creamy Bite') ?>"
                    loading="lazy">
                <div class="gallery-item-overlay">
                    <?php if (!empty($img['caption'])): ?>
                    <span class="gallery-item-caption"><?= htmlspecialchars($img['caption']) ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

    </div>
</section>

<!-- ══ Lightbox ════════════════════════════════════════════ -->
<div class="lightbox-overlay" id="lightbox" onclick="closeLightbox()">
    <div class="lightbox-inner" onclick="event.stopPropagation()">
        <button class="lightbox-close" onclick="closeLightbox()">
            <i class="fa-solid fa-xmark"></i>
        </button>
        <img src="" alt="" id="lightboxImg">
        <p class="lightbox-caption" id="lightboxCaption"></p>
    </div>
</div>

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
            <a href="index.php"><img src="assets/images/logo.png" alt="<?= SHOP_NAME ?>" class="footer-logo-img cb-gal-logo" ></a>
            <span class="footer-copy-text">&copy; <?= date('Y') ?> CreamyBite.com &mdash; Made with ❤️</span>
        </div>
    </div>
</footer>

<script>
function openLightbox(src, caption) {
    document.getElementById('lightboxImg').src = src;
    document.getElementById('lightboxCaption').textContent = caption || '';
    document.getElementById('lightbox').classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeLightbox() {
    document.getElementById('lightbox').classList.remove('open');
    document.body.style.overflow = '';
    setTimeout(() => { document.getElementById('lightboxImg').src = ''; }, 350);
}
// Close on Escape
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') { closeLightbox(); closeMobileMenu(); }
});
// ── Mobile nav ──────────────────────────────────────────────
const ham = document.getElementById('navHamburger');
const drawer = document.getElementById('mobileDrawer');
const drawerClose = document.getElementById('mobileDrawerClose');
function openMobileMenu()  { ham.classList.add('open'); drawer.classList.add('open'); document.body.style.overflow='hidden'; }
function closeMobileMenu() { ham.classList.remove('open'); drawer.classList.remove('open'); document.body.style.overflow=''; }
ham.addEventListener('click', openMobileMenu);
drawerClose.addEventListener('click', closeMobileMenu);
drawer.addEventListener('click', e => { if (e.target === drawer) closeMobileMenu(); });
</script>
<script src="modal.js" defer></script>
<script src="animations.js" defer></script>
</body>
</html>
