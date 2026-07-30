<?php
// ============================================================
//  Sweet Scoops – Site Configuration
// ============================================================

define('SHOP_NAME',      'Creamy Bite');
define('SHOP_TAGLINE',   'Every Bite Tells a Story 🍦');
define('SHOP_PHONE',     '+44 7497 779997');
define('SHOP_INSTAGRAM', 'https://www.instagram.com/creamybiteicecream');
define('SHOP_FACEBOOK',  'https://www.facebook.com/share/17oFEAg77U/?mibextid=wwXIfr');
define('ADMIN_EMAIL',    'princevir2610@gmail.com');

// Admin login credentials (change these!)
define('ADMIN_USERNAME', 'creamybite');
define('ADMIN_PASSWORD', 'Creamy@2025');

// Base URL (no trailing slash)
define('SITE_URL', 'https://creamybite.com');   // Your live domain

// Order code prefix
define('ORDER_PREFIX', 'SCO');

// ── Auto-Detect Environment (Local MAMP vs Live Server) ────────
$isLocal = in_array($_SERVER['HTTP_HOST'] ?? '', ['localhost:8888', 'localhost', '127.0.0.1', '127.0.0.1:8888']) 
           || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == '8888');

if ($isLocal) {
    // ── LOCAL / MAMP Credentials ──────────────────────────────
    define('DB_HOST', 'localhost');
    define('DB_PORT', '3306');
    define('DB_NAME', 'creamybite');
    define('DB_USER', 'root');
    define('DB_PASS', 'root');
} else {
    // ── LIVE Database (Hostinger / cPanel) ───────────────────
    define('DB_HOST', 'localhost');
    define('DB_PORT', '3306');
    define('DB_NAME', 'u167013900_creamybite');
    define('DB_USER', 'u167013900_creamyuser');
    define('DB_PASS', 'Creamyorder@2026*');
}

// ── Stripe Payment Keys ──────────────────────────────────────
// Get these from: https://dashboard.stripe.com/apikeys
// Use TEST keys (pk_test_... / sk_test_...) while testing
// Switch to LIVE keys (pk_live_... / sk_live_...) when going live
define('STRIPE_PUBLISHABLE_KEY', 'pk_live_51RysDwDbn3uR0O34YSMPrCJKe7slhss8OZhvIcm2ZekhH7iCLL8LTRFAjbI4XD8D13BcDmQtxK8N2kQB5gSzNB7D006ejAv8YD');   // ⚠️ Replace with your key
define('STRIPE_SECRET_KEY',      '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');   // ⚠️ Replace with your key
define('STRIPE_CURRENCY',        'gbp');                  // UK pounds

