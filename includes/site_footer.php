<?php
// ============================================================
//  Creamy Bite – Shared site footer
//
//  The footer was copied into five pages. Adding a link meant editing all
//  five and hoping none were missed — which is how a site ends up with a
//  Privacy link on three pages and not the other two.
//
//  Links are built from SITE_BASE rather than a relative path, because this
//  is included from the site root (index.php) and from pages/ one level
//  down, and "pages/order.php" cannot be correct in both.
// ============================================================
$cbBase = defined('SITE_BASE') ? SITE_BASE : '';
?>
<footer class="footer-enhanced">
    <div class="container">
        <div class="footer-top">
            <div class="footer-brand">
                <a href="<?= $cbBase ?>/index.php"><img src="<?= $cbBase ?>/assets/images/logo.png" alt="<?= SHOP_NAME ?>" class="footer-logo-img"></a>
                <p>Handcrafted ice cream and cocoa drinks made fresh daily with the finest ingredients.</p>
                <div class="footer-social">
                    <a href="<?= SHOP_INSTAGRAM ?>" target="_blank" rel="noopener noreferrer" aria-label="Instagram"><i class="fa-brands fa-instagram"></i></a>
                    <a href="<?= SHOP_FACEBOOK ?>" target="_blank" rel="noopener noreferrer" aria-label="Facebook"><i class="fa-brands fa-facebook"></i></a>
                    <a href="https://wa.me/447497779997" target="_blank" rel="noopener noreferrer" aria-label="WhatsApp"><i class="fa-brands fa-whatsapp"></i></a>
                </div>
            </div>

            <div class="footer-col">
                <h4>Pages</h4>
                <ul>
                    <li><a href="<?= $cbBase ?>/index.php">Home</a></li>
                    <li><a href="<?= $cbBase ?>/pages/order.php">Order</a></li>
                    <li><a href="<?= $cbBase ?>/pages/gallery.php">Gallery</a></li>
                    <li><a href="<?= $cbBase ?>/pages/about.php">About Us</a></li>
                </ul>
            </div>

            <div class="footer-col">
                <h4>Customer Help</h4>
                <ul>
                    <li><a href="<?= $cbBase ?>/pages/faq.php">FAQs</a></li>
                    <li><a href="<?= $cbBase ?>/pages/shipping.php">Shipping &amp; Delivery</a></li>
                    <li><a href="<?= $cbBase ?>/pages/returns.php">Returns &amp; Refunds</a></li>
                    <li><a href="<?= $cbBase ?>/pages/privacy.php">Privacy Policy</a></li>
                    <li><a href="<?= $cbBase ?>/pages/cookies.php">Cookie Policy</a></li>
                    <li><a href="<?= $cbBase ?>/pages/terms.php">Terms &amp; Conditions</a></li>
                </ul>
            </div>

            <?php // The printable documents. Grouped separately from the policy
                  // pages because these are things a customer downloads and keeps,
                  // not things they read once. ?>
            <div class="footer-col">
                <h4>Downloads</h4>
                <ul>
                    <li><a href="<?= $cbBase ?>/pages/catalogue.php">Product Catalogue <small>(trade)</small></a></li>
                    <li><a href="<?= $cbBase ?>/pages/allergens.php">Allergens &amp; Nutrition</a></li>
                    <li><a href="<?= $cbBase ?>/pages/storage.php">Storage Instructions</a></li>
                </ul>
            </div>

            <div class="footer-col">
                <h4>B2B Trade</h4>
                <ul>
                    <li><a href="<?= $cbBase ?>/pages/trade_register.php">Apply for Trade</a></li>
                    <li><a href="<?= $cbBase ?>/pages/trade_login.php">Trade Login</a></li>
                    <li><a href="<?= $cbBase ?>/admin/login.php">Admin Login</a></li>
                </ul>
            </div>
        </div>

        <div class="footer-bottom-bar">
            <a href="<?= $cbBase ?>/index.php"><img src="<?= $cbBase ?>/assets/images/logo.png" alt="<?= SHOP_NAME ?>" class="footer-logo-img cbhm-footer-logo-sm"></a>
            <span class="footer-copy-text">
                &copy; <?= date('Y') ?> <?= SHOP_NAME ?> &mdash; Made with ❤️
            </span>
        </div>
    </div>
</footer>
