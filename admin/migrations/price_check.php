<?php
// ============================================================
//  Creamy Bite – Price diagnostic  (READ ONLY)
//  URL: /admin/migrations/price_check.php
//
//  Answers one question: when the shop shows a price that does not match the
//  database, which of the two is wrong and why. It writes nothing at all.
//
//  It prints, side by side:
//    · what the database actually holds
//    · what the order page WOULD show a retail customer
//    · what it WOULD show a trade customer
//    · whether this server is still running cached copies of the PHP files
//
//  That last one matters more than it sounds: with OPcache enabled, uploading
//  a corrected file does not necessarily change what runs. The old code keeps
//  executing until the cache notices, which looks exactly like "I fixed it and
//  nothing happened".
// ============================================================
require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';

// ── Is this server serving stale code? ───────────────────────
$opcache   = function_exists('opcache_get_status') ? @opcache_get_status(false) : null;
$opcacheOn = is_array($opcache) && !empty($opcache['opcache_enabled']);
$orderFile = realpath(__DIR__ . '/../../pages/order.php');
$orderMTime = $orderFile ? filemtime($orderFile) : 0;

// Does the deployed order.php contain the size-first pricing fix?
$orderSrc  = $orderFile ? (string)file_get_contents($orderFile) : '';
$hasFix    = str_contains($orderSrc, 'Sizes decide the price whenever a product has them');

$products = $pdo->query(
    "SELECT id, name, price, wholesale_price, available FROM products ORDER BY name"
)->fetchAll();

$variantsBy = [];
try {
    foreach ($pdo->query("SELECT * FROM product_variants ORDER BY product_id, sort_order, id") as $v) {
        $variantsBy[$v['product_id']][] = $v;
    }
} catch (PDOException $e) {
    $variantsBy = [];
}

/**
 * What the order page would print on a product card, for one kind of visitor.
 * Mirrors pages/order.php exactly — if this and the real page disagree, the
 * deployed page is not the one this file was written against.
 */
function cbCardPrice(array $product, array $variants, bool $isTrade): string
{
    if ($variants) {
        $prices = [];
        foreach ($variants as $v) {
            if (!$v['available']) { continue; }
            $p = (float)$v['price'];
            if ($isTrade && (float)$v['wholesale_price'] > 0) {
                $p = (float)$v['wholesale_price'];
            }
            $prices[] = $p;
        }
        if (!$prices) { return '— no size on sale —'; }
        $min = min($prices); $max = max($prices);
        return ($isTrade ? 'Trade ' : 'From ') . '£' . number_format($min, 2)
             . ($min < $max ? ' up to £' . number_format($max, 2) : '');
    }
    $w = (float)($product['wholesale_price'] ?? 0);
    if ($isTrade && $w > 0) {
        return 'Trade £' . number_format($w, 2);
    }
    return '£' . number_format((float)$product['price'], 2);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Price Check</title>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/setup.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body class="admin-wrapper su-page-warm">
<div class="su-wrap">
    <div class="glass-panel su-card">
        <h1 class="su-h1">🔍 Price Check</h1>
        <p class="su-lead">Read-only. Nothing on this page changes anything.</p>

        <p class="su-env <?= IS_LOCAL ? 'su-env-local' : 'su-env-live' ?>">
            <?= IS_LOCAL ? '💻 LOCAL database' : '🌍 LIVE database' ?>
            &mdash; <?= htmlspecialchars(DB_NAME) ?>
        </p>

        <?php if (!$hasFix): ?>
        <div class="su-failbox">
            <h2 class="su-failbox-h">This server is running the OLD order page</h2>
            <p class="cbtr-note">
                <code>pages/order.php</code> here does not contain the size-first
                pricing fix, so a product with sizes still shows its product-level
                trade price — a figure no size actually sells at. Upload
                <code>pages/order.php</code> again.
            </p>
        </div>
        <?php endif; ?>

        <?php if ($opcacheOn): ?>
        <div class="su-failbox">
            <h2 class="su-failbox-h">PHP is caching code on this server</h2>
            <p class="cbtr-note">
                OPcache is on. Uploading a file does not always change what runs —
                the previous version can keep executing until the cache notices,
                which looks exactly like an upload that did nothing. If a change
                you uploaded has not appeared, wait a couple of minutes, or
                restart PHP from hPanel, then reload.
            </p>
        </div>
        <?php endif; ?>

        <table class="su-table">
            <tr class="su-row">
                <td class="su-cell-name"><strong>order.php last changed</strong></td>
                <td class="su-cell-mono" colspan="2">
                    <?= $orderMTime ? date('d M Y H:i', $orderMTime) : 'not found' ?>
                    &middot; size-first fix present: <?= $hasFix ? 'yes ✓' : 'NO ✗' ?>
                </td>
            </tr>
        </table>

        <h2 class="cbtr-card-title">Every product</h2>
        <table class="cbtr-table">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>What the database holds</th>
                    <th>A retail customer sees</th>
                    <th>A trade customer sees</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($products as $p):
                $vs = $variantsBy[$p['id']] ?? [];
            ?>
                <tr>
                    <td class="cbtr-product-name">
                        <?= htmlspecialchars($p['name']) ?>
                        <?= $p['available'] ? '' : ' <small>(hidden)</small>' ?>
                    </td>
                    <td>
                        <small>
                            product: retail £<?= number_format((float)$p['price'], 2) ?>,
                            trade £<?= number_format((float)$p['wholesale_price'], 2) ?>
                            <?php if ($vs): ?>
                            <br><?= count($vs) ?> size(s):
                            <?php foreach ($vs as $v): ?>
                                <br>&nbsp;&nbsp;<?= htmlspecialchars($v['name']) ?>:
                                retail £<?= number_format((float)$v['price'], 2) ?>,
                                trade £<?= number_format((float)$v['wholesale_price'], 2) ?>
                                <?= $v['available'] ? '' : '(hidden)' ?>
                            <?php endforeach; ?>
                            <?php else: ?>
                            <br>no sizes
                            <?php endif; ?>
                        </small>
                    </td>
                    <td><?= htmlspecialchars(cbCardPrice($p, $vs, false)) ?></td>
                    <td><strong><?= htmlspecialchars(cbCardPrice($p, $vs, true)) ?></strong></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <p class="cbtr-note">
            If the shop shows something other than the last column while logged in
            as a trade partner, the page is not running this code — see the
            warnings above. If the last column itself is wrong, the database
            numbers in the middle column are what need correcting, in
            Products &rarr; the product &rarr; Sizes.
        </p>

        <a href="../index.php?tab=products" class="btn-secondary su-btn-back">
            <i class="fa-solid fa-arrow-left"></i> Back to Products
        </a>
    </div>
</div>
</body>
</html>
