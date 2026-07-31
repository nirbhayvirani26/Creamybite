<?php
// ============================================================
//  Sweet Scoops – Site Configuration
//  Real credentials live in secrets.php — never inline them here.
// ============================================================

// Be safe to include more than once.
//
// require_once de-duplicates by resolved PATH STRING. macOS is
// case-insensitive, so browsing /Orders/ instead of /orders/ makes __DIR__
// report a different spelling than a relative require resolves to — PHP then
// treats them as two different files and runs this one twice, producing a
// "Constant X already defined" warning for every define() below.
//
// This guard makes that harmless whatever the include paths do.
if (defined('CB_CONFIG_LOADED')) {
    return;
}
define('CB_CONFIG_LOADED', true);

$secrets = require __DIR__ . '/secrets.php';

define('SHOP_NAME',      'Creamy Bite');
define('SHOP_TAGLINE',   'Every Bite Tells a Story 🍦');
define('SHOP_PHONE',     '+44 7497 779997');
define('SHOP_INSTAGRAM', 'https://www.instagram.com/creamybiteicecream');
define('SHOP_FACEBOOK',  'https://www.facebook.com/share/17oFEAg77U/?mibextid=wwXIfr');
define('ADMIN_EMAIL',    'princevir2610@gmail.com');

// Admin login credentials
define('ADMIN_USERNAME', $secrets['admin']['username']);
define('ADMIN_PASSWORD', $secrets['admin']['password']);

// Base URL (no trailing slash)
define('SITE_URL', 'https://creamybite.com');   // Your live domain

// Order code prefix
define('ORDER_PREFIX', 'SCO');

// VAT charged to trade customers who have a VAT number on their account.
// Retail customers are never charged this — shelf prices are inclusive.
define('TRADE_VAT_RATE', 0.20);   // 20%

// ── Auto-Detect Environment (Local MAMP vs Live Server) ────────
//  CLI has no HTTP_HOST. Treat that as LOCAL, not live — otherwise any
//  command-line script silently connects to the production database.
$host = $_SERVER['HTTP_HOST'] ?? '';
$isCli = (PHP_SAPI === 'cli');
$isLocal = $isCli
    || in_array($host, ['localhost:8888', 'localhost', '127.0.0.1', '127.0.0.1:8888'], true)
    || str_starts_with($host, 'localhost:')
    || str_starts_with($host, '127.0.0.1:')
    || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == '8888');

$db = $isLocal ? $secrets['db_local'] : $secrets['db_live'];

define('IS_LOCAL', $isLocal);
define('DB_HOST', $db['host']);
define('DB_PORT', $db['port']);
define('DB_NAME', $db['name']);
define('DB_USER', $db['user']);
define('DB_PASS', $db['pass']);

// ── Stripe Payment Keys ──────────────────────────────────────
define('STRIPE_PUBLISHABLE_KEY', $secrets['stripe']['publishable']);
define('STRIPE_SECRET_KEY',      $secrets['stripe']['secret']);
define('STRIPE_CURRENCY',        $secrets['stripe']['currency']);   // UK pounds

// ── Outgoing email ───────────────────────────────────────────
define('SMTP_HOST', $secrets['smtp']['host']);
define('SMTP_USER', $secrets['smtp']['user']);
define('SMTP_PASS', $secrets['smtp']['pass']);
define('SMTP_PORT', $secrets['smtp']['port']);

unset($secrets, $db, $host, $isCli, $isLocal);
