<?php
// ============================================================
//  Creamy Bite – Site Configuration
//
//  DATABASE LOGINS ARE IN THIS FILE, just below, so they are easy to find
//  and change when you move hosts.
//
//  The other secrets (Stripe keys, the Gmail app password, the admin panel
//  password) are still in secrets.php, which .htaccess blocks from being
//  served over the web. Keep this file out of any public repo or ZIP.
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

// ════════════════════════════════════════════════════════════
//  DATABASE LOGINS  —  edit these
// ════════════════════════════════════════════════════════════

//  On your Mac (MAMP). Port 8889 is MAMP's MySQL, not the usual 3306.
$DB_LOCAL = [
    'host' => 'localhost',
    'port' => '8889',
    'name' => 'creamybite',
    'user' => 'root',
    'pass' => 'root',
];

//  On the live server (Hostinger). Copy these from hPanel > Databases.
$DB_LIVE = [
    'host' => 'localhost',
    'port' => '3306',
    'name' => 'u167013900_creamybite',
    'user' => 'u167013900_creamyuser',
    'pass' => 'Creamyorder@2026*',
];

// ════════════════════════════════════════════════════════════

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
// The address the shop actually answers on. Order emails build their links
// from this, so getting it wrong sends staff to a domain that does not serve
// the admin panel — the "view this order" and "print delivery note" buttons
// in those emails simply fail.
define('SITE_URL', 'https://orders.creamybite.com');

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

$db = $isLocal ? $DB_LOCAL : $DB_LIVE;

// ── URL path to the project root ─────────────────────────────
//  "/orders" under MAMP, "" when the site is the domain root.
//
//  Shared partials (the header/nav) are included from pages at DIFFERENT
//  depths — index.php at the root and pages/*.php one level down — so a
//  relative "trade_profile.php" in a partial would resolve to a different
//  place depending on who included it. Links in shared markup are built
//  from this instead, which is correct from any depth.
$docRoot  = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
$projRoot = str_replace('\\', '/', dirname(__DIR__));   // includes/ -> project root
define('SITE_BASE', ($docRoot !== '' && str_starts_with($projRoot, $docRoot))
    ? rtrim(substr($projRoot, strlen($docRoot)), '/')
    : '');

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
