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

        <?php foreach (['Files', 'Settings', 'Functions', 'Tables', 'Columns', 'Order path'] as $group): ?>
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
