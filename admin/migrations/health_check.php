<?php
// ============================================================
//  Creamy Bite – Health check  (READ ONLY)
//  URL: /admin/migrations/health_check.php
//
//  For "it works on my Mac but not on the server". Checks the things that
//  differ between the two — files that did not overwrite, constants and
//  functions a half-uploaded set of files leaves undefined, tables and
//  columns a skipped migration leaves missing — and names whichever is
//  actually absent.
//
//  Writes nothing. Every check is a read.
// ============================================================
require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';

// Loaded defensively: a fatal in one of these is itself the answer, so the
// page must survive long enough to say so.
$loadErrors = [];
foreach (['pricing.php', 'invoice.php', 'trade_cart.php', 'stock.php', 'mailer.php'] as $f) {
    try {
        require_once __DIR__ . '/../../includes/' . $f;
    } catch (Throwable $e) {
        $loadErrors[] = $f . ': ' . $e->getMessage();
    }
}

$checks = [];

/** Record one check. $ok false means this is a reason something is broken. */
function cbCheck(string $group, string $what, bool $ok, string $detail = '', string $fix = ''): void
{
    $GLOBALS['checks'][] = compact('group', 'what', 'ok', 'detail', 'fix');
}

// ── Files: is each one the version that carries the newest change? ──
// A marker string is cheaper and more honest than a version number nobody
// remembers to bump: it is present only if the file really contains the code.
$fileMarkers = [
    'includes/config.php'      => ['MIN_DELIVERY_ORDER',        'the £20 delivery minimum'],
    'includes/pricing.php'     => ['function tradeIsLoggedIn',  'trade delivery being free'],
    'includes/invoice.php'     => ['function invoicePublicUrl', 'customer invoice links'],
    'includes/mailer.php'      => ['function cbSendMail',       'sending invoices by email'],
    'pages/checkout.php'       => ['minOrderNotice',            'the live minimum-order check'],
    'checkout_handler.php'     => ['MIN_DELIVERY_ORDER',        'the server-side minimum'],
    'admin/handlers/invoice_handler.php' => ['send_email',      'the invoice send button'],
    'admin/handlers/update_order.php'    => ['invoiceSettleFromPaidOrder', 'invoices settling when an order is paid'],
    'stripe_intent.php'        => ['fallback_to_later',      'falling back to Pay Later when cards are down'],
];
foreach ($fileMarkers as $rel => [$marker, $whatItDoes]) {
    $path = __DIR__ . '/../../' . $rel;
    if (!is_file($path)) {
        cbCheck('Files', $rel, false, 'file is missing entirely', 'Re-upload the site.');
        continue;
    }
    $has = str_contains((string)file_get_contents($path), $marker);
    cbCheck(
        'Files',
        $rel,
        $has,
        $has ? 'current (' . date('d M H:i', (int)filemtime($path)) . ')'
             : 'OLD version — does not contain ' . $whatItDoes,
        $has ? '' : 'This file did not overwrite. Upload it again, then restart PHP in hPanel.'
    );
}

// ── Link paths ───────────────────────────────────────────────
// Shared partials are included from pages at different depths, so links in
// them are built from SITE_BASE rather than written relative. SITE_BASE is
// worked out by subtracting DOCUMENT_ROOT from the project directory, and it
// is the one value that differs between a Mac (where the site lives in
// /orders) and the server (where it is the domain root).
//
// When a "Back to the shop" link 404s on live and works locally, this is
// almost always why — so the figure is printed rather than left to be
// guessed at from the outside.
$cbBaseVal = defined('SITE_BASE') ? SITE_BASE : null;
cbCheck('Paths', 'SITE_BASE', $cbBaseVal !== null,
    $cbBaseVal === null ? 'not defined' : ($cbBaseVal === '' ? '(empty — site is the domain root)' : $cbBaseVal),
    $cbBaseVal === null ? 'includes/config.php is the old version — upload it again.' : '');

cbCheck('Paths', 'DOCUMENT_ROOT', !empty($_SERVER['DOCUMENT_ROOT']),
    $_SERVER['DOCUMENT_ROOT'] ?? '(not set)', '');

cbCheck('Paths', 'project directory', true, dirname(__DIR__, 2), '');

// The link every "Back to the shop" button uses. Resolved to a real file so
// a wrong SITE_BASE shows up here as a missing file rather than as a 404 the
// customer finds first.
$cbHomeHref = ($cbBaseVal ?? '') . '/index.php';
$cbHomeFile = dirname(__DIR__, 2) . '/index.php';
cbCheck('Paths', 'home link', is_file($cbHomeFile),
    'links point at ' . $cbHomeHref . ($cbHomeFile && is_file($cbHomeFile) ? ' — target file present' : ' — TARGET MISSING'),
    is_file($cbHomeFile) ? '' : 'index.php is not in the site root. Check the zip was extracted so index.php sits directly in public_html.');

// ── Constants and functions the checkout depends on ──────────
foreach (['MIN_DELIVERY_ORDER', 'TRADE_VAT_RATE', 'SITE_URL', 'SHOP_PHONE'] as $c) {
    cbCheck('Settings', $c, defined($c),
        defined($c) ? (string)constant($c) : 'not defined',
        defined($c) ? '' : 'includes/config.php is the old version — upload it again.');
}
foreach ([
    'computeOrderTotals'  => 'includes/pricing.php',
    'tradeIsLoggedIn'     => 'includes/pricing.php',
    'validatedPromoRow'   => 'includes/pricing.php',
    'tradeCartClear'      => 'includes/trade_cart.php',
    'tradeCartRestore'    => 'includes/trade_cart.php',
    'stockShortages'      => 'includes/stock.php',
    'invoicePublicUrl'    => 'includes/invoice.php',
    'cbSendMail'          => 'includes/mailer.php',
] as $fn => $from) {
    cbCheck('Functions', $fn . '()', function_exists($fn),
        function_exists($fn) ? 'available' : 'MISSING',
        function_exists($fn) ? '' : 'Upload ' . $from . ' again.');
}

// ── Tables and columns, read FROM THE MIGRATION ──────────────
//
// This list used to be typed out by hand, and it went stale the moment
// anything new was built: Documents, Production and Traceability were all
// added without it, so it cheerfully reported a clean bill of health on a
// server missing three tables. A hand-kept copy of a list that lives
// somewhere else is a list that will be wrong.
//
// So it is read out of update_db.php instead — the file that actually creates
// them. Whatever the migration knows how to build, this page knows to look
// for, and neither can drift from the other again.
$cbMigration = @file_get_contents(__DIR__ . '/update_db.php') ?: '';

$needTables = [];
if (preg_match('/\$tables\s*=\s*\[(.*?)\n\];/s', $cbMigration, $m)
    && preg_match_all("/^\s*'([a-z_]+)'\s*=>\s*\"CREATE TABLE/m", $m[1], $t)) {
    $needTables = $t[1];
}

$needColsAuto = [];
if (preg_match('/\$columns\s*=\s*\[(.*?)\n\];/s', $cbMigration, $m2)
    && preg_match_all("/\[\s*'([a-z_]+)'\s*,\s*'([a-z_]+)'/", $m2[1], $c2, PREG_SET_ORDER)) {
    foreach ($c2 as $pair) { $needColsAuto[] = [$pair[1], $pair[2]]; }
}

// If the migration could not be read, fall back to the core order path rather
// than reporting nothing at all.
if (!$needTables) {
    $needTables = ['orders', 'products', 'product_variants', 'trade_users', 'trade_carts',
                   'invoices', 'invoice_items', 'invoice_payments', 'invoice_settings', 'sales_reps'];
    cbCheck('Tables', 'read migration list', false, 'could not read update_db.php',
            'Checking the core tables only — upload admin/migrations/update_db.php.');
}
foreach ($needTables as $t) {
    $q = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $q->execute([$t]);
    $ok = (int)$q->fetchColumn() > 0;
    cbCheck('Tables', $t, $ok, $ok ? 'present' : 'MISSING',
        $ok ? '' : 'Run update_db.php on this server.');
}

$needCols = [
    ['orders',   'trade_user_id'], ['orders', 'trade_business_name'], ['orders', 'vat_amount'],
    ['orders',   'vat_number'],    ['orders', 'stock_deducted'],
    ['invoices', 'sales_rep_id'],  ['invoices', 'commission_percent'],
    ['invoices', 'public_token'],  ['invoices', 'sent_at'],
    ['products', 'wholesale_price'], ['products', 'trade_only'],
];
// Everything the migration adds, on top of the order-path ones named above.
foreach ($needColsAuto as $pair) {
    if (!in_array($pair, $needCols, true)) { $needCols[] = $pair; }
}
foreach ($needCols as [$t, $c]) {
    $q = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $q->execute([$t, $c]);
    $ok = (int)$q->fetchColumn() > 0;
    cbCheck('Columns', $t . '.' . $c, $ok, $ok ? 'present' : 'MISSING',
        $ok ? '' : 'Run update_db.php on this server.');
}

// ── VAT ──────────────────────────────────────────────────────
//
// Two separate things decide VAT, and nothing made them agree.
//
// Checkout charges TRADE_VAT_RATE to any trade customer who has typed a VAT
// number into their own profile (includes/pricing.php, tradeVatApplies()).
// The accounting module charges nothing and files nothing unless
// vat_settings.is_registered is set — includes/accounting.php says as much:
// "An unregistered business must not". Whether VAT is due depends on whether
// the SHOP is registered, never on whether the customer happens to have a
// number, so with the shipped default of is_registered = 0 the site collects
// 20% it does not account for. Charging VAT while unregistered is not lawful,
// and it had no warning anywhere.
//
// Both directions are worth saying out loud, so this cannot go unnoticed
// again in either state.
try {
    require_once __DIR__ . '/../../includes/accounting.php';
    $vatOn  = acctVatEnabled($pdo);
    $vatSet = acctSettings($pdo);
    $vatNo  = trim((string)($vatSet['vat_number'] ?? ''));

    // Is the shop actually taking VAT off anyone right now?
    $vatCharged = (float)$pdo->query(
        "SELECT COALESCE(SUM(vat_amount), 0) FROM orders WHERE vat_amount > 0"
    )->fetchColumn();

    if (!$vatOn && $vatCharged > 0) {
        cbCheck('VAT', 'registration vs what is being charged', false,
            'VAT of £' . number_format($vatCharged, 2) . ' has been charged on orders, but this business is marked NOT VAT registered',
            'If the shop IS VAT registered, turn it on: Admin → VAT & Accounting → Settings, and put the VAT number in. '
          . 'If it is NOT registered, it must stop charging the 20% — trade customers are being charged VAT that is not owed.');
    } elseif ($vatOn && $vatNo === '') {
        cbCheck('VAT', 'VAT number', false,
            'marked VAT registered, but no VAT number is set',
            'A VAT invoice has to show the number. Add it in Admin → VAT & Accounting → Settings.');
    } else {
        cbCheck('VAT', 'registration', true,
            $vatOn
                ? 'registered' . ($vatNo !== '' ? ' — ' . htmlspecialchars($vatNo) : '')
                : 'not registered, and no VAT is being charged — consistent');
    }
} catch (Throwable $e) {
    cbCheck('VAT', 'registration', false, 'could not check', $e->getMessage());
}

// ── Trade ────────────────────────────────────────────────────
//
// Its own section because trade is the part with the most moving pieces and
// the least visible failure: a wholesale customer who cannot check out simply
// goes away, and nothing on the owner's screen says why.
require_once __DIR__ . '/../../includes/store_settings.php';

// The four switches on the Delivery & Offers page. Without these columns the
// trade toggles have nowhere to save to.
foreach (['trade_delivery_open', 'trade_collection_open',
          'trade_delivery_closed_note', 'trade_collection_closed_note'] as $col) {
    $q = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'store_settings' AND COLUMN_NAME = ?");
    $q->execute([$col]);
    $ok = (int)$q->fetchColumn() > 0;
    cbCheck('Trade', 'store_settings.' . $col, $ok, $ok ? 'present' : 'MISSING',
        $ok ? '' : 'Run update_db.php — the trade open/closed switches cannot save without this.');
}

// Whether the FIX is on this server, not merely the columns. The switches
// saved and displayed correctly for weeks while the checkout ignored them
// entirely, because the checkout read the public columns for everybody. This
// function only exists in the corrected includes/store_settings.php, so its
// presence is the honest test of whether trade customers are really governed
// by their own switches.
$audienceFix = function_exists('cbOrderingAudience');
cbCheck('Trade', 'trade switches reach the checkout', $audienceFix,
    $audienceFix ? 'yes — trade reads its own switches' : 'NO — trade is gated by the PUBLIC switches',
    $audienceFix ? '' : 'Upload includes/store_settings.php. Until you do, switching public delivery off blocks trade orders too.');

// What the switches are actually set to, so the answer to "why can nobody
// order?" is on the same screen as the question.
if ($audienceFix) {
    foreach (['delivery', 'collection'] as $method) {
        foreach ([['public', false], ['trade', true]] as [$who, $isTrade]) {
            $open = cbOrderingOpen($method, $isTrade);
            cbCheck('Trade', ucfirst($who) . ' ' . $method, $open,
                $open ? 'taking orders' : 'SWITCHED OFF',
                $open ? '' : 'Turn it back on at Delivery & Offers if this is not deliberate.');
        }
    }
}

// Stock blocks orders regardless of any switch, and reads as "checkout is
// broken" rather than as an empty freezer.
try {
    $sellable = (int)$pdo->query(
        "SELECT COUNT(*) FROM products WHERE available = 1 AND (track_stock = 0 OR stock_qty > 0)"
    )->fetchColumn();
    $onSale = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE available = 1")->fetchColumn();
    cbCheck('Trade', 'products actually sellable', $sellable > 0,
        $sellable . ' of ' . $onSale . ' on sale have stock',
        $sellable > 0 ? '' : 'Every product is out of stock, so no order can complete — trade or retail. Set stock on the Stock page.');
} catch (Throwable $e) {
    cbCheck('Trade', 'products actually sellable', false, 'could not check', $e->getMessage());
}

// ── Secrets ──────────────────────────────────────────────────
// The commonest cause of "payments broke again after I uploaded" was
// includes/secrets.php shipping with the developer's keys and overwriting the
// server's. Secrets now live in .env, which never ships — so the first thing
// to check on a server is whether it has one.
require_once __DIR__ . '/../../includes/env.php';
if (cbEnvLoaded()) {
    cbCheck('Secrets', '.env file', true, 'present — keys are safe from being overwritten by an upload');
} else {
    cbCheck('Secrets', '.env file', false, 'MISSING from the site root',
        'Copy .env.example to .env on this server and fill in the values. Without it there are no Stripe keys and no mail password.');
}
foreach (['STRIPE_SECRET_KEY', 'STRIPE_PUBLISHABLE_KEY', 'SMTP_PASS', 'ADMIN_PASSWORD'] as $k) {
    $v = cbEnv($k, '');
    cbCheck('Secrets', $k, $v !== '',
        $v !== '' ? 'set (' . strlen($v) . ' chars)' : 'EMPTY',
        $v !== '' ? '' : 'Add ' . $k . ' to .env on this server.');
}

// ── Outgoing email: what customers see in the From line ──────
//
// Every message the shop sends goes out FROM the SMTP login in .env, not from
// SHOP_EMAIL and not from the invoice settings. That is correct and must stay
// that way — a From address that does not match the authenticated account
// fails SPF/DMARC and gets rewritten or binned by the receiving mail server.
//
// But it means the SMTP login IS the shop's public email address, and nothing
// anywhere said so. A personal Gmail in that field puts a personal Gmail on
// every order confirmation the shop sends.
$cbSmtpUser = defined('SMTP_USER') ? trim((string)SMTP_USER) : '';
$cbShopHost = '';
if (defined('SHOP_EMAIL') && str_contains(SHOP_EMAIL, '@')) {
    $cbShopHost = strtolower(substr(strrchr(SHOP_EMAIL, '@'), 1));
}

if ($cbSmtpUser === '') {
    cbCheck('Outgoing email', 'the address customers see', false,
        'no SMTP_USER — nothing can be sent at all',
        'Put the shop mailbox in SMTP_USER in this server\'s .env, then restart PHP in hPanel.');
} else {
    $cbSmtpHost   = strtolower(substr(strrchr($cbSmtpUser, '@') ?: '@', 1));
    $cbFreeMail   = in_array($cbSmtpHost, ['gmail.com','googlemail.com','outlook.com','hotmail.com','yahoo.com','yahoo.co.uk','live.com','icloud.com'], true);
    $cbOwnDomain  = ($cbShopHost !== '' && $cbSmtpHost === $cbShopHost);

    cbCheck('Outgoing email', 'the address customers see', $cbOwnDomain,
        'every email the shop sends arrives From: ' . $cbSmtpUser
        . ($cbOwnDomain ? ' — your own domain' : ($cbFreeMail ? ' — a personal mailbox, not your domain' : ' — not your domain')),
        $cbOwnDomain ? ''
            : 'Customers see this on every order confirmation, receipt and invoice, and replies go here. '
            . 'Use a mailbox on ' . ($cbShopHost !== '' ? $cbShopHost : 'your own domain')
            . ' as SMTP_USER in .env, then restart PHP. Do not just change SHOP_EMAIL — the From line follows the '
            . 'SMTP login, because a mismatch fails SPF and the message gets rewritten or junked.');

    // Where a reply actually lands, said plainly. Three addresses are in play
    // and it is not obvious which does what.
    cbCheck('Outgoing email', 'where replies go', true,
        'customer replies → ' . (defined('SHOP_EMAIL') ? SHOP_EMAIL : '?')
        . ' · enquiries reply straight to the customer · order alerts → '
        . (defined('ADMIN_EMAIL') ? ADMIN_EMAIL : '?'));
}

// ── Card payments ────────────────────────────────────────────
// The commonest cause of "Pay Online is not loading" is a secret key that
// works on the machine it was pasted into and nowhere else.
//
// The advice below used to say to put the key in includes/secrets.php and
// upload that file. That was the old arrangement and it is now exactly the
// wrong thing to do: keys live in .env, secrets.php only reads it, and .env
// deliberately never ships. Someone following the old wording during a live
// outage would edit and upload a file that holds no keys, see nothing change,
// and have no idea why. It says .env now, and says to restart PHP, which is
// the step that actually makes a changed key take effect.
//
// Balance::retrieve() is a read: it proves the key is accepted without
// creating a payment, a customer or anything else on the Stripe account.
if (!defined('STRIPE_SECRET_KEY') || STRIPE_SECRET_KEY === '') {
    cbCheck('Card payments', 'Stripe secret key', false, 'not configured',
        'Add STRIPE_SECRET_KEY to the .env file in the site root, then restart PHP in hPanel.');
} elseif (!is_file(__DIR__ . '/../../vendor/autoload.php')) {
    cbCheck('Card payments', 'Stripe library', false, 'vendor/ is missing on this server',
        'Upload the whole site, including the vendor folder.');
} else {
    require_once __DIR__ . '/../../vendor/autoload.php';
    $keyTail = substr((string)STRIPE_SECRET_KEY, -4);
    try {
        \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);
        \Stripe\Balance::retrieve();
        cbCheck('Card payments', 'Stripe secret key', true, 'accepted (ending ' . $keyTail . ')');
    } catch (Throwable $e) {
        $msg = preg_replace('/sk_live_[A-Za-z0-9]+/', 'sk_live_…', $e->getMessage());
        $expired = str_contains($msg, 'Expired') || str_contains($msg, 'Invalid API Key');
        cbCheck('Card payments', 'Stripe secret key', false,
            'REJECTED (ending ' . $keyTail . ') — ' . substr($msg, 0, 140),
            $expired
                ? 'This server has an old or revoked key. Roll it at dashboard.stripe.com, put the new one in STRIPE_SECRET_KEY in this server\'s .env, then restart PHP in hPanel. Do not upload anything — .env never ships, so each server keeps its own.'
                : 'Check STRIPE_SECRET_KEY in this server\'s .env file, then restart PHP in hPanel.');
    }
}

// ── Can an order actually be written? ────────────────────────
// The surest test short of placing one: start a transaction, insert an order
// the way checkout_handler does, then roll it back. A column the code writes
// but the table lacks shows up here and nowhere else.
try {
    $pdo->beginTransaction();
    $pdo->prepare(
        "INSERT INTO orders (order_code, trade_user_id, trade_business_name, customer_name,
                             customer_email, phone, address, postcode, items_json, total_price,
                             delivery_charge, vat_amount, vat_number, payment_status,
                             payment_method, status, notes)
         VALUES ('HEALTHCHK', 0, '', 'Health Check', 'x@example.com', '0', 'a', 'b',
                 '[]', 0.00, 0.00, 0.00, '', 'Unpaid', 'later', 'Pending', '')"
    )->execute();
    $pdo->rollBack();
    cbCheck('Order path', 'writing an order', true, 'works (rolled back, nothing saved)');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) { $pdo->rollBack(); }
    cbCheck('Order path', 'writing an order', false, $e->getMessage(),
        'This is why orders fail. Run update_db.php, then reload this page.');
}

// ── Your shop right now ──────────────────────────────────────
//
// Everything above asks "does the code work". This section asks a different
// question: "is anything wrong with the actual shop today". They are not the
// same, and the gap between them is where real faults hide.
//
// A worked example, and the reason this section exists: the Invoices tab on a
// trade partner's account showed "Being prepared" instead of a button whenever
// an invoice had no customer link yet. The page returned 200. Nothing was
// logged. Every check above passed. A customer simply could not open an
// invoice they were being asked to pay, and nothing anywhere said so.
//
// These read live data and report conditions a customer or the owner would
// notice. Each one is worded as what to DO, because a number on its own is
// not something anybody can act on.
$cbShopWarn = static function (string $what, int $count, string $goodWord, string $badWord, string $fix): void {
    cbCheck('Your shop right now', $what, $count === 0,
        $count === 0 ? $goodWord : $count . ' ' . $badWord,
        $count === 0 ? '' : $fix);
};

// 1. Invoices a customer can see listed but cannot open. THE bug this section
//    was written for. pages/trade_profile.php mints the link on demand now, so
//    this should stay at zero; if it ever does not, invoices are unreachable.
try {
    $n = (int)$pdo->query(
        "SELECT COUNT(*) FROM invoices
          WHERE status IN ('sent','part_paid','paid')
            AND (public_token IS NULL OR public_token = '')"
    )->fetchColumn();
    $cbShopWarn('invoices customers can open', $n,
        'every issued invoice has a working customer link',
        'issued invoice(s) have no customer link',
        'Open the Invoices tab on the customer\'s account once and the link is created, '
      . 'or email the invoice from the invoice page.');
} catch (Throwable $e) {
    cbCheck('Your shop right now', 'invoices customers can open', false, 'could not check', $e->getMessage());
}

// 2. Orders that look like a trade partner's but are not attached to them.
//    Invisible in that partner's account, absent from their trade report, and
//    counted as retail in the revenue split.
try {
    $n = (int)$pdo->query(
        "SELECT COUNT(*) FROM orders o
           JOIN trade_users t ON LOWER(TRIM(t.email)) = LOWER(TRIM(o.customer_email))
          WHERE o.trade_user_id = 0 AND t.status = 'approved' AND o.customer_email <> ''"
    )->fetchColumn();
    $cbShopWarn('orders attached to the right account', $n,
        'every order that matches a trade partner is attached to them',
        'order(s) match a trade partner but are not attached',
        'Admin → Orders. Those orders show a note naming the partner — set the Trade account picker and Save.');
} catch (Throwable $e) {
    cbCheck('Your shop right now', 'orders attached to the right account', false, 'could not check', $e->getMessage());
}

// 3. Product photos that are named on the product but missing from the server.
//    A broken image on the menu is the most visible fault a shop can have.
try {
    $missing = 0; $firstMissing = '';
    $dir = dirname(__DIR__, 2) . '/assets/images/products/';
    foreach ($pdo->query("SELECT name, image FROM products WHERE image <> ''")->fetchAll() as $p) {
        if (!is_file($dir . $p['image'])) {
            $missing++;
            if ($firstMissing === '') { $firstMissing = $p['name']; }
        }
    }
    $cbShopWarn('product photos', $missing,
        'every product photo is on the server',
        'product(s) point at a photo that is not on the server, starting with "' . $firstMissing . '"',
        'Admin → Products → edit the product and upload the photo again.');
} catch (Throwable $e) {
    cbCheck('Your shop right now', 'product photos', false, 'could not check', $e->getMessage());
}

// 4. Allergens. A legal duty, and the public allergen sheet says "please ask"
//    for anything not confirmed.
try {
    $n = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE allergen_reviewed_at IS NULL")->fetchColumn();
    $cbShopWarn('allergen information', $n,
        'every product has had its allergens confirmed',
        'product(s) have no confirmed allergens — the public sheet shows "please ask" for each',
        'Admin → allergens_bulk.php. This is a legal duty, not a nicety.');
} catch (Throwable $e) {
    cbCheck('Your shop right now', 'allergen information', false, 'could not check', $e->getMessage());
}

// 5. Trade applications nobody has answered.
try {
    $n = (int)$pdo->query("SELECT COUNT(*) FROM trade_users WHERE status = 'pending'")->fetchColumn();
    $cbShopWarn('trade applications', $n,
        'no applications waiting',
        'trade application(s) waiting for an answer — they cannot order until approved',
        'Admin → Trade.');
} catch (Throwable $e) {
    cbCheck('Your shop right now', 'trade applications', false, 'could not check', $e->getMessage());
}

// 6. Customers who wrote in and have not been answered.
try {
    $n = (int)$pdo->query("SELECT COUNT(*) FROM inquiries WHERE is_read = 0")->fetchColumn();
    $cbShopWarn('customer enquiries', $n,
        'no unread enquiries',
        'unread enquiry(ies) — someone is waiting to hear back',
        'Admin → Inquiries.');
} catch (Throwable $e) {
    cbCheck('Your shop right now', 'customer enquiries', false, 'could not check', $e->getMessage());
}

// ── What the site has actually been complaining about ────────
//
// The single most useful thing on this page once the shop is live. PHP writes
// every warning and fatal to a log that nobody ever opens, because reading it
// means finding the hosting control panel. Real faults hit by real customers
// sit in there unread.
//
// Read-only, newest last, and capped — this is a window on the log, not a
// substitute for it.
$cbLogPath  = (string)@ini_get('error_log');
$cbLogLines = [];
$cbLogNote  = '';
if ($cbLogPath === '' || $cbLogPath === 'syslog') {
    $cbLogNote = 'PHP is not writing to a file this page can read'
               . ($cbLogPath === 'syslog' ? ' (it is going to the system log).' : '.')
               . ' Ask the host to set error_log to a file inside the site, or look in hPanel → Error Log.';
} elseif (!is_readable($cbLogPath)) {
    $cbLogNote = 'The log is at ' . $cbLogPath . ' but this page cannot read it. Open it in hPanel → File Manager.';
} else {
    $cbLogSize = (int)@filesize($cbLogPath);
    $fh = @fopen($cbLogPath, 'r');
    if ($fh) {
        // Only the tail. A log can be tens of megabytes and reading it whole
        // would take the page down with the very problem it is reporting on.
        if ($cbLogSize > 60000) { @fseek($fh, -60000, SEEK_END); @fgets($fh); }
        while (($line = fgets($fh)) !== false) {
            $line = rtrim($line);
            if ($line !== '') { $cbLogLines[] = $line; }
        }
        @fclose($fh);
        $cbLogLines = array_slice($cbLogLines, -40);
        if (!$cbLogLines) { $cbLogNote = 'The log is empty — nothing has gone wrong.'; }
    }
}

$failures = array_values(array_filter($checks, fn($c) => !$c['ok']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Health Check</title>
    <?php require __DIR__ . '/../../includes/favicon.php'; ?>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/setup.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body class="admin-wrapper su-page-warm">
<div class="su-wrap">
    <div class="glass-panel su-card">
        <h1 class="su-h1">🩺 Health Check</h1>
        <p class="su-lead">Read-only. Nothing here changes anything.</p>

        <p class="su-env <?= IS_LOCAL ? 'su-env-local' : 'su-env-live' ?>">
            <?= IS_LOCAL ? '💻 LOCAL' : '🌍 LIVE' ?> &mdash; <?= htmlspecialchars(DB_NAME) ?>
            &middot; PHP <?= PHP_VERSION ?>
        </p>

        <?php if ($loadErrors): ?>
        <div class="su-failbox">
            <h2 class="su-failbox-h">A required file failed to load</h2>
            <ul class="su-failbox-list">
                <?php foreach ($loadErrors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <?php if ($failures): ?>
        <div class="su-failbox">
            <h2 class="su-failbox-h"><?= count($failures) ?> problem(s) found</h2>
            <ul class="su-failbox-list">
                <?php foreach ($failures as $f): ?>
                <li>
                    <strong><?= htmlspecialchars($f['what']) ?></strong> — <?= htmlspecialchars($f['detail']) ?>
                    <?= $f['fix'] !== '' ? '<br>' . htmlspecialchars($f['fix']) : '' ?>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php else: ?>
        <p class="su-result su-ok">
            ✅ Everything the order path needs is present on this server.
            If orders still fail, the cause is in the browser — open the checkout,
            press F12, and read the Console tab.
        </p>
        <?php endif; ?>

        <?php
        // Groups come from the checks themselves, in the order they were
        // registered. This used to be a hardcoded list, which meant adding a
        // new group ran its checks and then silently threw the results away —
        // the page looked fine and simply told you less than it knew.
        $groups = [];
        foreach ($checks as $c) {
            if (!in_array($c['group'], $groups, true)) { $groups[] = $c['group']; }
        }
        ?>
        <?php foreach ($groups as $group): ?>
        <h2 class="cbtr-card-title"><?= $group ?></h2>
        <table class="su-table">
            <?php foreach ($checks as $c): if ($c['group'] !== $group) continue; ?>
            <tr class="su-row">
                <td class="su-cell-mono"><?= htmlspecialchars($c['what']) ?></td>
                <td class="su-cell-state <?= $c['ok'] ? 'su-ok' : 'su-err' ?>">
                    <?= $c['ok'] ? '✓' : '✗' ?> <?= htmlspecialchars($c['detail']) ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php endforeach; ?>

        <?php // What the site itself has been complaining about. ?>
        <h2 class="cbtr-card-title">What the site has been complaining about</h2>
        <p class="su-loghint">
            Every warning and error PHP has written, newest at the bottom. This is where a
            fault a customer hit shows up — and it is worth a look after any change, or any
            time something is reported as not working.
        </p>
        <?php if ($cbLogNote !== ''): ?>
        <p class="su-loghint su-loghint-note"><?= htmlspecialchars($cbLogNote) ?></p>
        <?php endif; ?>
        <?php if ($cbLogLines): ?>
        <div class="su-log"><?php foreach ($cbLogLines as $line): ?><div class="su-log-line<?=
            preg_match('/Fatal|Uncaught|Parse error/i', $line) ? ' is-fatal'
            : (preg_match('/Warning|Deprecated|Notice/i', $line) ? ' is-warn' : '') ?>"><?=
            htmlspecialchars($line) ?></div><?php endforeach; ?></div>
        <p class="su-loghint">
            Showing the last <?= count($cbLogLines) ?> line(s) of <?= htmlspecialchars($cbLogPath) ?>.
        </p>
        <?php endif; ?>

        <a href="../index.php" class="btn-secondary su-btn-back">
            <i class="fa-solid fa-arrow-left"></i> Back to Admin
        </a>
    </div>
</div>
</body>
</html>
