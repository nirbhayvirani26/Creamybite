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

// ── Tables and columns the order path writes to ──────────────
$needTables = ['orders', 'products', 'product_variants', 'trade_users', 'trade_carts',
               'invoices', 'invoice_items', 'invoice_payments', 'invoice_settings', 'sales_reps'];
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
foreach ($needCols as [$t, $c]) {
    $q = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $q->execute([$t, $c]);
    $ok = (int)$q->fetchColumn() > 0;
    cbCheck('Columns', $t . '.' . $c, $ok, $ok ? 'present' : 'MISSING',
        $ok ? '' : 'Run update_db.php on this server.');
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

// ── Card payments ────────────────────────────────────────────
// The commonest cause of "Pay Online is not loading" is a secret key that
// works on the machine it was pasted into and nowhere else, because
// includes/secrets.php is the one file people skip when uploading — it holds
// the database password, so overwriting it feels dangerous.
//
// Balance::retrieve() is a read: it proves the key is accepted without
// creating a payment, a customer or anything else on the Stripe account.
if (!defined('STRIPE_SECRET_KEY') || STRIPE_SECRET_KEY === '') {
    cbCheck('Card payments', 'Stripe secret key', false, 'not configured',
        'Add it to includes/secrets.php.');
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
                ? 'This server has an old or revoked key. Roll it at dashboard.stripe.com, put it in includes/secrets.php, and upload THAT FILE — it is the one most often skipped.'
                : 'Check the key in includes/secrets.php.');
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

$failures = array_values(array_filter($checks, fn($c) => !$c['ok']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Health Check</title>
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

        <a href="../index.php" class="btn-secondary su-btn-back">
            <i class="fa-solid fa-arrow-left"></i> Back to Admin
        </a>
    </div>
</div>
</body>
</html>
