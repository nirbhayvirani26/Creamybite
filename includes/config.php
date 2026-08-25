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

// ── Time ────────────────────────────────────────────────────
//
// Everything this shop records is a UK local time — when an order came in,
// when it was delivered, which day it belongs to on a VAT return. Without
// this line PHP falls back to the server default, which is UTC on Hostinger
// and on MAMP, so every order was stamped an hour behind the clock on the
// wall for the seven months a year Britain is on BST.
//
// It has to be the SAME zone MySQL uses, because both write timestamps:
// created_at comes from PHP date(), while delivered_at, printed_at, sent_at
// and the VAT submitted_at come from SQL NOW(). Two zones meant those two
// sets of times disagreed with each other by an hour, so an order delivered
// five minutes after it arrived looked like it took an hour and five.
// includes/db.php pins the database session to match.
define('CB_TIMEZONE', 'Europe/London');
date_default_timezone_set(CB_TIMEZONE);

// cbAsset(): adds ?v=<file mtime> to stylesheet and script URLs so a browser
// cannot serve a stale copy after an upload. Loaded here because every page
// reaches config.php, so the helper is always defined wherever it is used.
require_once __DIR__ . '/asset.php';

// cbJsAttr(): a value safely embedded in an inline onclick/onchange. Loaded
// here for the same reason as cbAsset() above — every page reaches config.php,
// so the helper is defined wherever a template needs it.
require_once __DIR__ . '/html.php';

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

// cbEnv() reads the .env at the project root. Required HERE rather than being
// left to secrets.php, because the $DB_LIVE block below calls cbEnv() on every
// single page load and only worked by accident — secrets.php happened to pull
// env.php in first. Anything that changed that ordering took the whole site
// down with "call to undefined function cbEnv()".
require_once __DIR__ . '/env.php';

// Credentials. .env is the source of truth; includes/secrets.php is only the
// bridge that turns it into this array.
//
// The bridge is OPTIONAL. It is gitignored and untracked — deliberately, since
// it once held real Stripe keys — but this line used to be a hard `require`,
// so a copy of the site taken from the repository had no secrets.php and
// fataled on the first page load, with a blank white screen and nothing in it
// to say why. build_upload_zip.sh even refuses to build without the file. Only
// the developer's own Mac, where an untracked copy still sits on disk, could
// produce a working deploy.
//
// So: use secrets.php when it is there (a server upgraded from an older
// release may still have one holding real values), and otherwise read the same
// keys straight from .env, which is where they belong now. Either way nothing
// secret is in the repository, and a fresh checkout starts.
$secretsFile = __DIR__ . '/secrets.php';
$secrets = is_readable($secretsFile) ? require $secretsFile : [
    'admin' => [
        'username' => cbEnv('ADMIN_USERNAME', ''),
        'password' => cbEnv('ADMIN_PASSWORD', ''),
    ],
    'stripe' => [
        'publishable' => cbEnv('STRIPE_PUBLISHABLE_KEY', ''),
        'secret'      => cbEnv('STRIPE_SECRET_KEY', ''),
        'currency'    => cbEnv('STRIPE_CURRENCY', 'gbp'),
    ],
    'smtp' => [
        'host' => cbEnv('SMTP_HOST', 'smtp.gmail.com'),
        'user' => cbEnv('SMTP_USER', ''),
        'pass' => cbEnv('SMTP_PASS', ''),
        'port' => (int)cbEnv('SMTP_PORT', '587'),
    ],
];

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

// The shop's own address, one line per line, as it should appear on a printed
// receipt. Taken from the same address already on every invoice
// (invoice_settings.from_address) so the two cannot say different things.
//
// It lives here rather than being read from the database because a till
// receipt has to print even when the counter PC can barely reach the site,
// and because the receipt header is fixed shop identity, not per-document
// data. Keep the lines short — a 72mm thermal receipt fits about 32
// monospaced characters, and anything longer is clipped, not wrapped.
// "Phoenix Business Centre", not "Phoenix House". The building was written two
// different ways: the website, the emails, the order confirmation and every
// policy page said Phoenix Business Centre, while this line and the invoice
// settings said Phoenix House — so a customer who collected an order held a
// receipt naming a different building from the one the site had sent them to.
// Confirmed with the owner: Phoenix Business Centre is the real one. The street
// stays, because a receipt and an invoice want the full postal address.
//
// 31 characters on the longest line, inside the ~32 a 72mm thermal receipt
// prints before it clips.
define('SHOP_ADDRESS',   "Unit E5 Phoenix Business Centre\nRosslyn Cres, Harrow\nHA1 2SP, London, UK");
define('SHOP_INSTAGRAM', 'https://www.instagram.com/creamybiteicecream');
define('SHOP_FACEBOOK',  'https://www.facebook.com/share/17oFEAg77U/?mibextid=wwXIfr');

// The WhatsApp link was written out by hand as wa.me/447497779997 in both the
// footer and the About page, so changing SHOP_PHONE above would have left two
// copies of the old number pointing at a chat nobody reads. Built from
// SHOP_PHONE instead: wa.me wants digits only, no plus and no spaces.
define('SHOP_WHATSAPP',  'https://wa.me/' . preg_replace('/\D+/', '', SHOP_PHONE));

// TikTok. EMPTY until the shop has an account to point at.
//
// The About page carried a "Follow on TikTok" button with href="#", so it was
// a real button, in the real row of social buttons, that took a customer
// nowhere. Every place that renders these now skips a network whose URL is
// blank — so filling this in makes the button appear, and leaving it empty
// means no dead button. Same rule for the two above if either ever changes.
define('SHOP_TIKTOK',    '');
// Where order alerts and staff notifications are sent. Internal — the shop's
// own mailbox, not an address printed for customers.
//
// This was a personal Gmail account used while the site was being built, and
// it was never meant to survive going live. Order alerts landing in someone's
// private inbox is a nuisance on its own, but three customer-facing emails —
// the order confirmation, the payment receipt and the delivery note — were
// also printing this constant as the shop's contact address, so real customers
// were being handed that private inbox. Those three now use SHOP_EMAIL below,
// which is the address the rest of the site gives out.
define('ADMIN_EMAIL',    'hello@creamybite.com');

// The address customers are told to write to. Kept separate from ADMIN_EMAIL
// because the two are different jobs: the FAQ, the policy pages, the
// catalogue and the allergen sheet were all printing the personal Gmail,
// while every invoice sent out carried hello@creamybite.com. A customer who
// saw both had no idea which one reaches the shop.
//
// The shop runs TWO customer-facing mailboxes on purpose, confirmed with the
// owner, and they are not interchangeable:
//
//   orders@creamybite.com  — this constant. The website, the FAQ and every
//                            policy page. Anything to do with an order.
//   hello@creamybite.com   — invoice_settings.from_email, edited in the admin
//                            panel under Invoices. Billing paperwork only.
//
// So do not "fix" one to match the other. The comment that used to sit here
// said this constant was hello@creamybite.com "taken from invoice_settings",
// which contradicted the line directly beneath it and would have sent someone
// tidying up in exactly the wrong direction.
define('SHOP_EMAIL',     'orders@creamybite.com');

// Admin login credentials
define('ADMIN_USERNAME', $secrets['admin']['username']);
define('ADMIN_PASSWORD', $secrets['admin']['password']);

// Base URL (no trailing slash)
// The address the shop actually answers on. Order emails build their links
// from this, so getting it wrong sends staff to a domain that does not serve
// the admin panel — the "view this order" and "print delivery note" buttons
// in those emails simply fail.
define('SITE_URL', 'https://creamybite.com');

// Order code prefix. Every order code customers ever see begins with this, so
// it has to match what checkout_handler.php actually mints. It read 'SCO'
// while the handler hardcoded 'CB-', which meant the constant described a
// format the shop has never used — and the policy pages quote this format back
// to customers when asking them to find their order number.
define('ORDER_PREFIX', 'CB');

// VAT charged to trade customers who have a VAT number on their account.
// Retail customers are never charged this — shelf prices are inclusive.
define('TRADE_VAT_RATE', 0.20);   // 20%

// The delivery figures — the charge, the radius, the minimum basket — are no
// longer written here. They are settings the owner changes from the admin
// panel, so they live in the database and are defined further down this file,
// once the database login is known. See the "Delivery figures" block below.

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

// ════════════════════════════════════════════════════════════
//  Delivery figures — the owner's own settings
// ════════════════════════════════════════════════════════════
//
// These five used to be typed in above as literals, which meant changing the
// delivery charge was a code edit and an upload. They now come from one row in
// the database that the admin panel writes to, and they are still defined as
// the SAME FIVE CONSTANTS, so every place that already reads them keeps
// working untouched: pricing.php, checkout_handler.php, stripe_intent.php,
// pages/checkout.php, pages/catalogue.php, pages/faq.php, pages/shipping.php
// and pages/terms.php.
//
// It has to happen HERE, after DB_HOST..DB_PASS are defined above, because
// that is what includes/store_settings.php connects with — and it opens its
// own connection rather than using includes/db.php, because db.php requires
// THIS file on its line 6. Requiring db.php from config.php would be a loop,
// and four of the files listed above never load db.php at all.
//
// If the database cannot be reached, or has not had the migration run on it
// yet, cbStoreSettings() hands back the figures the shop has always used and
// the site carries on. Nothing here can white-screen a page.
require_once __DIR__ . '/store_settings.php';
$storeSettings = cbStoreSettings();

// Smallest basket we will deliver. Collection has no minimum — the cost this
// covers is the driver, not the ice cream. Enforced in three places that must
// agree: the checkout page (to warn early), stripe_intent.php (so a card is
// never charged for a basket the handler will reject) and checkout_handler.php
// (the only one that actually decides).
define('MIN_DELIVERY_ORDER',    $storeSettings['min_delivery_order']);

// How far we drive, and what it costs.
define('FREE_DELIVERY_MILES',   $storeSettings['free_delivery_miles']);    // free inside this radius
define('DELIVERY_RADIUS_MILES', $storeSettings['delivery_radius_miles']);  // furthest we will drive at all
define('DELIVERY_CHARGE',       $storeSettings['delivery_charge']);        // charged between the two

// Every distance in this app is straight-line (Haversine) between two
// coordinates, not an actual driving route — postcodes.io gives coordinates,
// not routes. A real road network is never a straight line, so this factor
// scales the straight-line figure up to a realistic driving-distance estimate
// before it is compared against the radius above or shown to a customer.
// 1.3 is a standard urban "circuity factor" (driving distance is typically
// 25-35% longer than straight-line in a town/city road grid) — checked
// against a real route (HA1 2SP to NW3 3RA: 7.55mi straight-line, 9.84mi
// actually driven, a 1.30x ratio) rather than picked arbitrarily.
define('DELIVERY_DISTANCE_FACTOR', $storeSettings['delivery_distance_factor']);

// The rest of the row — free delivery over £X, the standing cart message — is
// read through cbStoreSettings() where it is needed. It is deliberately NOT
// given constants of its own: one way of reading a setting is enough.

// ── Stripe Payment Keys ──────────────────────────────────────
define('STRIPE_PUBLISHABLE_KEY', $secrets['stripe']['publishable']);
define('STRIPE_SECRET_KEY',      $secrets['stripe']['secret']);
define('STRIPE_CURRENCY',        $secrets['stripe']['currency']);   // UK pounds

// ── Outgoing email ───────────────────────────────────────────
define('SMTP_HOST', $secrets['smtp']['host']);
define('SMTP_USER', $secrets['smtp']['user']);
define('SMTP_PASS', $secrets['smtp']['pass']);
define('SMTP_PORT', $secrets['smtp']['port']);

unset($secrets, $db, $host, $isCli, $isLocal, $storeSettings);
