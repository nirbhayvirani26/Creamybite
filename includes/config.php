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

//  On the live server (Hostinger). These come from .env so that uploading
//  code can never overwrite the server's own database login — the same
//  mistake that kept replacing the Stripe key. The values below are only the
//  fallback for a server that has not been given a .env yet.
//  No fallback password. A default here would ship in every upload, which is
//  the whole problem this is solving — and a package containing the live
//  database password is worse than one that simply will not start until the
//  server has been given its own .env.
$DB_LIVE = [
    'host' => cbEnv('DB_LIVE_HOST', 'localhost'),
    'port' => cbEnv('DB_LIVE_PORT', '3306'),
    'name' => cbEnv('DB_LIVE_NAME', ''),
    'user' => cbEnv('DB_LIVE_USER', ''),
    'pass' => cbEnv('DB_LIVE_PASS', ''),
];

// ════════════════════════════════════════════════════════════

define('SHOP_NAME',      'Creamy Bite');
define('SHOP_TAGLINE',   'Every Bite Tells a Story');
define('SHOP_PHONE',     '+44 7497 779997');
define('SHOP_INSTAGRAM', 'https://www.instagram.com/creamybiteicecream');
define('SHOP_FACEBOOK',  'https://www.facebook.com/share/17oFEAg77U/?mibextid=wwXIfr');
// Where order alerts and staff notifications are sent. Internal — this is a
// personal mailbox and is not meant to be printed for customers.
define('ADMIN_EMAIL',    'princevir2610@gmail.com');

// The address customers are told to write to. Kept separate from ADMIN_EMAIL
// because the two are different jobs: the FAQ, the policy pages, the
// catalogue and the allergen sheet were all printing the personal Gmail,
// while every invoice sent out carried hello@creamybite.com. A customer who
// saw both had no idea which one reaches the shop.
//
// hello@creamybite.com is taken from invoice_settings, which is the address
// already going out on real paperwork.
define('SHOP_EMAIL',     'hello@creamybite.com');

// Admin login credentials
define('ADMIN_USERNAME', $secrets['admin']['username']);
define('ADMIN_PASSWORD', $secrets['admin']['password']);

// Base URL (no trailing slash)
// The address the shop actually answers on. Order emails build their links
// from this, so getting it wrong sends staff to a domain that does not serve
// the admin panel — the "view this order" and "print delivery note" buttons
// in those emails simply fail.
define('SITE_URL', 'https://orders.creamybite.com');

// Order code prefix. Every order code customers ever see begins with this, so
// it has to match what checkout_handler.php actually mints. It read 'SCO'
// while the handler hardcoded 'CB-', which meant the constant described a
// format the shop has never used — and the policy pages quote this format back
// to customers when asking them to find their order number.
define('ORDER_PREFIX', 'CB');

// VAT charged to trade customers who have a VAT number on their account.
// Retail customers are never charged this — shelf prices are inclusive.
define('TRADE_VAT_RATE', 0.20);   // 20%

// Smallest basket we will deliver. Collection has no minimum — the cost this
// covers is the driver, not the ice cream.
//
// Defined once because it is enforced in three places that must agree: the
// checkout page (to warn early), stripe_intent.php (so a card is never
// charged for a basket the handler will reject) and checkout_handler.php (the
// only one that actually decides). When these were three separate literals,
// changing the figure in one left the other two quietly enforcing the old one.
define('MIN_DELIVERY_ORDER', 20.00);

// How far we drive, and what it costs. Same reasoning as the figure above:
// these were written out by hand in pricing.php, checkout_handler.php, the
// checkout page's JavaScript, shipping.php and terms.php. Five copies of a
// number that a shop changes when fuel prices move — and the customer-facing
// pages are the copies nobody thinks to update, so the site would promise one
// price and charge another.
define('FREE_DELIVERY_MILES',   3.0);    // free inside this radius
define('DELIVERY_RADIUS_MILES', 6.0);    // furthest we will drive at all
define('DELIVERY_CHARGE',       1.99);   // charged between the two

// Every distance in this app is straight-line (Haversine) between two
// coordinates, not an actual driving route — postcodes.io gives coordinates,
// not routes. A real road network is never a straight line, so this factor
// scales the straight-line figure up to a realistic driving-distance estimate
// before it is compared against the radius above or shown to a customer.
// 1.3 is a standard urban "circuity factor" (driving distance is typically
// 25-35% longer than straight-line in a town/city road grid) — checked
// against a real route (HA1 2SP to NW3 3RA: 7.55mi straight-line, 9.84mi
// actually driven, a 1.30x ratio) rather than picked arbitrarily.
define('DELIVERY_DISTANCE_FACTOR', 1.3);

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
