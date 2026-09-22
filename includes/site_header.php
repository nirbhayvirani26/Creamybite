<?php
// ============================================================
//  Creamy Bite – Shared site header
//
//  Was copied into nine pages, and the three things that actually change
//  per page — which nav link is "active", the path prefix (bare from
//  index.php, ../ from everything under pages/), and the button on the
//  right (Order Now on most pages, View Cart on the menu, Apply for
//  Trade / Trade Login on the account pages, Place Order + Logout once
//  signed in) — drifted a little further apart every time one of them
//  changed and the other eight were not updated to match.
//
//  It also turned out three pages (trade_login, trade_register,
//  trade_profile) had a hamburger button with no drawer behind it, and
//  six more (the policy pages) had no mobile menu at all — both fixed
//  here just by every page now getting the same, complete thing.
//
//  Usage, from a file in pages/ (or index.php itself):
//      $cbNavActive = 'order';   // 'home' | 'order' | 'gallery' | 'about' | '' (default)
//      ob_start();
    
//      $cbNavRight = ob_get_clean();
//      require __DIR__ . '/../includes/site_header.php';
//
//  Optional:
//      $cbNavDrawerRight – separate content for the mobile drawer, when it
//                          needs to differ from $cbNavRight (order.php's
//                          drawer offers both View Cart AND Order Now,
//                          where the cramped desktop bar has room for only
//                          one). Defaults to $cbNavRight.
//      $cbNavShowTrade   – set false to skip includes/trade_nav_button.php
//                          (the three account pages already show a Trade
//                          Login / Apply for Trade / Logout button of
//                          their own, so showing the trade-account pill
//                          too would be redundant).
// ============================================================
$cbBase           = defined('SITE_BASE') ? SITE_BASE : '';
$cbNavActive       = $cbNavActive       ?? '';
$cbNavRight        = $cbNavRight        ?? '';
$cbNavDrawerRight  = $cbNavDrawerRight  ?? $cbNavRight;
$cbNavShowTrade    = $cbNavShowTrade    ?? true;

/** class="active" on the one link that matches the current page, nothing on the rest. */
function cbNavActiveAttr(string $key, string $current): string
{
    return $key === $current ? ' class="active"' : '';
}
?>
<header class="navbar">
    <div class="container nav-container-centered">
        <nav class="nav-left">
            <ul class="nav-links">
                <li><a href="<?= $cbBase ?>/"<?= cbNavActiveAttr('home', $cbNavActive) ?>>Home</a></li>
                <li><a href="<?= $cbBase ?>/pages/order.php"<?= cbNavActiveAttr('order', $cbNavActive) ?>>Order</a></li>
                <li><a href="<?= $cbBase ?>/pages/gallery.php"<?= cbNavActiveAttr('gallery', $cbNavActive) ?>>Gallery</a></li>
                <li><a href="<?= $cbBase ?>/pages/about.php"<?= cbNavActiveAttr('about', $cbNavActive) ?>>About Us</a></li>
                <li><a href="<?= $cbBase ?>/pages/about.php#contact">Contact</a></li>
            </ul>
        </nav>

        <a href="<?= $cbBase ?>/" class="logo logo-center">
            <img src="<?= $cbBase ?>/assets/images/logo.png" alt="<?= SHOP_NAME ?>" class="logo-img">
        </a>

        <div class="nav-actions nav-right">
            <?php // Tap-to-call and WhatsApp. Icon-only up here because the bar
                  // already carries a trade pill, a page CTA and the hamburger;
                  // the labelled versions live in the drawer, where there is room.
                  // Hidden below 768px by responsive.css for the same reason. ?>
            <a href="tel:<?= preg_replace('/[^0-9+]/', '', SHOP_PHONE) ?>"
               class="nav-contact-btn" title="Call <?= htmlspecialchars(SHOP_PHONE) ?>"
               aria-label="Call <?= htmlspecialchars(SHOP_PHONE) ?>">
                <i class="fa-solid fa-phone"></i>
            </a>
            <a href="<?= SHOP_WHATSAPP ?>" class="nav-contact-btn nav-contact-wa"
               target="_blank" rel="noopener" title="Message us on WhatsApp"
               aria-label="Message us on WhatsApp">
                <i class="fa-brands fa-whatsapp"></i>
            </a>
            <?php if ($cbNavShowTrade): ?>
            <?php include __DIR__ . '/trade_nav_button.php'; ?>
            <?php endif; ?>
            <?= $cbNavRight ?>
            <button class="nav-hamburger" id="navHamburger" aria-label="Open menu"><span></span><span></span><span></span></button>
        </div>
    </div>
</header>

<div class="mobile-drawer" id="mobileDrawer">
    <div class="mobile-nav-panel">
        <button class="mobile-drawer-close" id="mobileDrawerClose" aria-label="Close menu">
            <i class="fa-solid fa-xmark"></i>
        </button>
        <ul class="mobile-nav-links">
            <li><a href="<?= $cbBase ?>/index.php"<?= cbNavActiveAttr('home', $cbNavActive) ?>>Home</a></li>
            <li><a href="<?= $cbBase ?>/pages/order.php"<?= cbNavActiveAttr('order', $cbNavActive) ?>>Order</a></li>
            <li><a href="<?= $cbBase ?>/pages/gallery.php"<?= cbNavActiveAttr('gallery', $cbNavActive) ?>>Gallery</a></li>
            <li><a href="<?= $cbBase ?>/pages/about.php"<?= cbNavActiveAttr('about', $cbNavActive) ?>>About Us</a></li>
            <li><a href="<?= $cbBase ?>/pages/about.php#contact">Contact</a></li>
        </ul>
        <div class="mobile-nav-actions">
            <?php // First in the drawer, not last: on a phone, calling the shop is
                  // more often the intent than anything a page-specific CTA offers. ?>
            <a href="tel:<?= preg_replace('/[^0-9+]/', '', SHOP_PHONE) ?>" class="btn-secondary mobile-contact-btn">
                <i class="fa-solid fa-phone"></i> Call <?= htmlspecialchars(SHOP_PHONE) ?>
            </a>
            <a href="<?= SHOP_WHATSAPP ?>" class="btn-secondary mobile-contact-btn mobile-contact-wa"
               target="_blank" rel="noopener">
                <i class="fa-brands fa-whatsapp"></i> WhatsApp
            </a>
            <a href="<?= $cbBase ?>/pages/about.php#contact" class="btn-secondary mobile-contact-btn">
                <i class="fa-solid fa-envelope"></i> Contact us
            </a>
            <?= $cbNavDrawerRight ?>
        </div>
    </div>
</div>

<script>
// ── Mobile nav ──────────────────────────────────────────────
// One copy for every page now, instead of nine that could each drift out
// of sync. Function declarations (not const arrow functions) so a later,
// page-specific script — order.php's cart/variant-picker Escape handler,
// gallery.php's lightbox Escape handler — can still call closeMobileMenu()
// regardless of script tag order, since declarations are hoisted.
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
