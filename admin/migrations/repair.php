<?php
/**
 * Repair — the two things that stop orders, fixable in a click.
 *
 * Both of these are already possible elsewhere: the switches live on Delivery
 * & Offers, and stock lives on the Stock page, size by size. This page exists
 * because when a shop is not taking orders the owner wants one screen that
 * says what is wrong and a button that fixes it, not a tour of the admin
 * panel with a checklist.
 *
 * It changes NOTHING on load. Every change is a button press, each one
 * CSRF-protected, and every one reports exactly what it did.
 *
 * Owner only. These settings decide whether the shop trades at all, which is
 * not a thing to hand to a staff account along with the gallery.
 */

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_permissions.php';
if (!adminIsOwner()) {
    http_response_code(403);
    exit('Owner only.');
}

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/store_settings.php';

$done = [];
$fail = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrfCheck(false)) {
    $action = (string)($_POST['action'] ?? '');

    // ── Start taking orders again, all four channels ─────────
    if ($action === 'reopen') {
        try {
            $pdo->exec(
                "UPDATE `store_settings`
                    SET `delivery_open` = 1, `collection_open` = 1,
                        `trade_delivery_open` = 1, `trade_collection_open` = 1
                  ORDER BY `id` LIMIT 1"
            );
            $done[] = 'All four ordering switches are on — public delivery and collection, trade delivery and collection.';
        } catch (Throwable $e) {
            $fail[] = 'Could not change the switches: ' . $e->getMessage();
        }
    }

    // ── Opening stock, every size the same ───────────────────
    if ($action === 'stock') {
        $qty = (int)($_POST['qty'] ?? -1);
        if ($qty < 0 || $qty > 100000) {
            $fail[] = 'Enter how many of each size you have, as a whole number.';
        } else {
            try {
                $pdo->beginTransaction();

                // Sizes that are on sale get the figure; sizes switched off
                // stay at nothing, so the shop never claims stock it cannot
                // actually sell.
                $pdo->prepare(
                    "UPDATE `product_variants`
                        SET `total_stock` = :q, `stock_qty` = :q2,
                            `damage_stock` = 0, `sold_offline` = 0, `sold_online` = 0
                      WHERE `available` = 1"
                )->execute(['q' => $qty, 'q2' => $qty]);

                $pdo->exec(
                    "UPDATE `product_variants`
                        SET `total_stock` = 0, `stock_qty` = 0, `damage_stock` = 0,
                            `sold_offline` = 0, `sold_online` = 0
                      WHERE `available` = 0"
                );

                // Each flavour's own figures become the sum of its sizes.
                $pdo->exec(
                    "UPDATE `products` p
                        SET p.`total_stock`  = (SELECT COALESCE(SUM(v.`total_stock`),0)  FROM `product_variants` v WHERE v.`product_id` = p.`id`),
                            p.`stock_qty`    = (SELECT COALESCE(SUM(v.`stock_qty`),0)    FROM `product_variants` v WHERE v.`product_id` = p.`id`),
                            p.`damage_stock` = (SELECT COALESCE(SUM(v.`damage_stock`),0) FROM `product_variants` v WHERE v.`product_id` = p.`id`),
                            p.`sold_offline` = (SELECT COALESCE(SUM(v.`sold_offline`),0) FROM `product_variants` v WHERE v.`product_id` = p.`id`),
                            p.`sold_online`  = (SELECT COALESCE(SUM(v.`sold_online`),0)  FROM `product_variants` v WHERE v.`product_id` = p.`id`)
                      WHERE EXISTS (SELECT 1 FROM `product_variants` v2 WHERE v2.`product_id` = p.`id`)"
                );

                $pdo->exec("UPDATE `products` SET `track_stock` = 1");
                $pdo->commit();

                $n = (int)$pdo->query("SELECT COUNT(*) FROM product_variants WHERE available = 1")->fetchColumn();
                $done[] = $n . ' size(s) set to ' . $qty . ' each. Sizes not on sale were left at nothing.';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                $fail[] = 'Could not set the stock: ' . $e->getMessage();
            }
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fail[] = 'That form had expired. Reload this page and try again.';
}

// ── What is true right now ───────────────────────────────────
$state = [];
foreach (['delivery', 'collection'] as $m) {
    foreach ([['Public', false], ['Trade', true]] as [$who, $isTrade]) {
        $state[] = [$who . ' ' . $m, cbOrderingOpen($m, $isTrade)];
    }
}
try {
    $onSale   = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE available = 1")->fetchColumn();
    $sellable = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE available = 1 AND (track_stock = 0 OR stock_qty > 0)")->fetchColumn();
    $sizes    = (int)$pdo->query("SELECT COUNT(*) FROM product_variants WHERE available = 1")->fetchColumn();
} catch (Throwable $e) {
    $onSale = $sellable = $sizes = 0;
}

$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Repair – <?= $h(SHOP_NAME) ?> Admin</title>
    <?php require __DIR__ . '/../../includes/favicon.php'; ?>
    <link rel="stylesheet" href="<?= cbAsset('../../assets/css/style.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?= cbAsset('../assets/css/admin.css') ?>">
    <link rel="stylesheet" href="<?= cbAsset('../assets/css/setup.css') ?>">
</head>
<body class="admin-wrapper">
<div class="su-wrap">

    <h1 class="su-title"><i class="fa-solid fa-wrench" aria-hidden="true"></i> Repair</h1>
    <p class="su-sub">The two things that stop orders. Nothing changes until you press a button.</p>

    <?php foreach ($done as $m): ?>
    <div class="su-box su-box-ok"><i class="fa-solid fa-circle-check" aria-hidden="true"></i> <?= $h($m) ?></div>
    <?php endforeach; ?>
    <?php foreach ($fail as $m): ?>
    <div class="su-box su-box-err"><i class="fa-solid fa-circle-xmark" aria-hidden="true"></i> <?= $h($m) ?></div>
    <?php endforeach; ?>

    <!-- ── 1. Taking orders ───────────────────────────────── -->
    <section class="su-panel">
        <h2 class="su-h2">1. Is the shop taking orders?</h2>
        <table class="su-table">
            <?php foreach ($state as [$label, $open]): ?>
            <tr>
                <td><?= $h($label) ?></td>
                <td><?= $open
                    ? '<strong class="su-ok">taking orders</strong>'
                    : '<strong class="su-bad">SWITCHED OFF</strong>' ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        <?php $anyClosed = in_array(false, array_column($state, 1), true); ?>
        <?php if ($anyClosed): ?>
        <p class="su-note">A switched-off channel refuses every order on it, however much stock there is.</p>
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="reopen">
            <button class="btn-primary"><i class="fa-solid fa-play" aria-hidden="true"></i> Start taking orders on all four</button>
        </form>
        <?php else: ?>
        <p class="su-note su-ok">All four are on. Nothing to do here.</p>
        <?php endif; ?>
    </section>

    <!-- ── 2. Stock ───────────────────────────────────────── -->
    <section class="su-panel">
        <h2 class="su-h2">2. Is there anything to sell?</h2>
        <p class="su-note">
            <strong><?= $sellable ?> of <?= $onSale ?></strong> products on sale have stock,
            across <strong><?= $sizes ?></strong> size(s).
            <?php if ($sellable === 0): ?>
            <br><span class="su-bad">Everything is out of stock, so no order can complete — trade or retail.</span>
            <?php endif; ?>
        </p>
        <form method="post" class="su-form-row">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="stock">
            <label for="qty">How many of EACH size do you have?</label>
            <input type="number" id="qty" name="qty" min="0" max="100000" required
                   placeholder="e.g. 50" class="su-input">
            <button class="btn-primary"><i class="fa-solid fa-boxes-stacked" aria-hidden="true"></i> Set stock</button>
        </form>
        <p class="su-note">
            Every size on sale gets the same figure — adjust the odd one afterwards on the Stock page.
            Sizes you have switched off stay at nothing.
        </p>
    </section>

    <p class="su-note">
        <a href="health_check.php">Run the health check</a> &nbsp;·&nbsp;
        <a href="update_db.php">Run the database update</a> &nbsp;·&nbsp;
        <a href="../index.php">Back to the admin panel</a>
    </p>
</div>
</body>
</html>
