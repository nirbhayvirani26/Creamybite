<?php
// ============================================================
//  Creamy Bite – Downloadable product catalogue
//
//  Generated from the live products table every time it is opened, so it
//  cannot go stale the way a PDF emailed out in March does.
//
//  Trade pricing appears only for a signed-in trade customer. A retail
//  visitor sees the retail column alone — publishing wholesale prices on an
//  open page undercuts the shop's own trade partners, who are buying on the
//  understanding that their price is not the public one.
//
//  Case sizes come from the variant where one is set and fall back to the
//  product. That order matters: a 5L catering tub and a 500ml retail tub
//  case very differently, so the size-level figure has to win.
// ============================================================
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/pricing.php';
require_once __DIR__ . '/../includes/product_spec.php';

$isTrade = tradeIsLoggedIn();

// The catalogue is a wholesale document — case sizes and trade pricing — so
// it is for signed-in trade customers only. A retail visitor is sent to the
// trade login rather than shown a stripped-down version: publishing case
// quantities and wholesale prices openly undercuts the partners who are
// buying on the understanding that their price is not the public one.
if (!$isTrade) {
    header('Location: ' . SITE_BASE . '/pages/trade_login.php?next=' . urlencode(SITE_BASE . '/pages/catalogue.php'));
    exit;
}

$sql = "SELECT * FROM products WHERE available = 1 ORDER BY category ASC, name ASC";
$products = $pdo->query($sql)->fetchAll();

// One query for every variant rather than one per product.
$variants = [];
if ($products) {
    $vRows = $pdo->query("SELECT * FROM product_variants WHERE available = 1 ORDER BY sort_order ASC, id ASC");
    foreach ($vRows as $v) {
        $variants[(int)$v['product_id']][] = $v;
    }
}

$base  = SITE_BASE;
$vatTx = $isTrade
    ? 'Trade prices are shown excluding VAT. VAT at ' . (int)(TRADE_VAT_RATE * 100)
      . '% is added at checkout for VAT-registered accounts.'
    : 'Retail prices include VAT where applicable.';

// ── Build the table ─────────────────────────────────────────
ob_start();
?>
<div class="cbdoc-intro">
    <strong><?= $isTrade ? 'Wholesale price list' : 'Product range' ?>.</strong>
    <?= htmlspecialchars($vatTx) ?>
    <?php if (!$isTrade): ?>
        Trade customers see wholesale pricing and case quantities here once signed in —
        <a href="<?= $base ?>/pages/trade_register.php">apply for a trade account</a>.
    <?php endif; ?>
    Allergen and nutrition information is published separately, on the
    <a href="<?= $base ?>/pages/allergens.php">allergen &amp; nutrition sheet</a>.
</div>

<?php if (empty($products)): ?>
    <p>There are no products to list at the moment. Please call us on
       <?= htmlspecialchars(SHOP_PHONE) ?>.</p>
<?php else: ?>

<table class="cbdoc-table">
    <thead>
        <tr>
            <th>Product</th>
            <th>Size</th>
            <th>Case size</th>
            <th class="cbdoc-num">Retail</th>
            <?php if ($isTrade): ?>
            <th class="cbdoc-num">Trade</th>
            <?php endif; ?>
        </tr>
    </thead>
    <tbody>
    <?php
    $lastCategory = null;
    foreach ($products as $p):
        $pid  = (int)$p['id'];
        $rows = $variants[$pid] ?? [];

        if ($p['category'] !== $lastCategory):
            $lastCategory = $p['category'];
    ?>
        <tr class="cbdoc-cat-row">
            <td colspan="<?= $isTrade ? 5 : 4 ?>"><?= htmlspecialchars($p['category']) ?></td>
        </tr>
    <?php endif; ?>

    <?php if (empty($rows)): ?>
        <?php // No sizes defined — the product's own price is the only one. ?>
        <tr>
            <td class="cbdoc-prod"><?= htmlspecialchars($p['name']) ?></td>
            <td class="cbdoc-muted">—</td>
            <td><?= cbCaseSize($p) !== '' ? htmlspecialchars(cbCaseSize($p)) : '<span class="cbdoc-missing">not set</span>' ?></td>
            <td class="cbdoc-num">£<?= number_format((float)$p['price'], 2) ?></td>
            <?php if ($isTrade): ?>
            <td class="cbdoc-num">
                <?= (float)$p['wholesale_price'] > 0
                        ? '£' . number_format((float)$p['wholesale_price'], 2)
                        : '<span class="cbdoc-missing">on request</span>' ?>
            </td>
            <?php endif; ?>
        </tr>
    <?php else: ?>
        <?php foreach ($rows as $i => $v): ?>
        <tr>
            <td class="cbdoc-prod">
                <?= $i === 0 ? htmlspecialchars($p['name']) : '' ?>
            </td>
            <td class="cbdoc-variant-name"><?= htmlspecialchars($v['name']) ?></td>
            <td>
                <?php $cs = cbCaseSize($p, $v); ?>
                <?= $cs !== '' ? htmlspecialchars($cs) : '<span class="cbdoc-missing">not set</span>' ?>
            </td>
            <td class="cbdoc-num">£<?= number_format((float)$v['price'], 2) ?></td>
            <?php if ($isTrade): ?>
            <td class="cbdoc-num">
                <?= (float)$v['wholesale_price'] > 0
                        ? '£' . number_format((float)$v['wholesale_price'], 2)
                        : '<span class="cbdoc-missing">on request</span>' ?>
            </td>
            <?php endif; ?>
        </tr>
        <?php endforeach; ?>
    <?php endif; ?>
    <?php endforeach; ?>
    </tbody>
</table>

<?php
// Only worth printing when something is actually missing — a permanent
// "some things may be blank" line teaches people to ignore it.
$missingCases = 0;
foreach ($products as $p) {
    $rows = $variants[(int)$p['id']] ?? [];
    if (empty($rows)) {
        if (cbCaseSize($p) === '') { $missingCases++; }
    } else {
        foreach ($rows as $v) {
            if (cbCaseSize($p, $v) === '') { $missingCases++; }
        }
    }
}
if ($missingCases > 0):
?>
<div class="cbdoc-notice">
    <strong><?= $missingCases ?> item<?= $missingCases === 1 ? '' : 's' ?></strong> in this
    list <?= $missingCases === 1 ? 'does' : 'do' ?> not yet have a case size recorded.
    Call us on <?= htmlspecialchars(SHOP_PHONE) ?> and we will confirm the case quantity
    before you order.
</div>
<?php endif; ?>

<h2>How to order</h2>
<p>
    Order online at <?= htmlspecialchars(preg_replace('#^https?://#', '', SITE_URL)) ?>,
    by phone on <?= htmlspecialchars(SHOP_PHONE) ?>, or by email to
    <?= htmlspecialchars(SHOP_EMAIL) ?>.
    <?php if ($isTrade): ?>
        Trade orders can be delivered or collected from the warehouse at no charge.
    <?php else: ?>
        Delivery is free within <?= rtrim(rtrim(number_format(FREE_DELIVERY_MILES, 1), '0'), '.') ?> miles
        of HA1 2SP, £<?= number_format(DELIVERY_CHARGE, 2) ?> up to
        <?= rtrim(rtrim(number_format(DELIVERY_RADIUS_MILES, 1), '0'), '.') ?> miles, with a
        £<?= number_format(MIN_DELIVERY_ORDER, 2) ?> minimum. Collection is free with no minimum.
    <?php endif; ?>
</p>

<?php endif; ?>
<?php
$docBody = ob_get_clean();

$docTitle    = 'Product Catalogue';
$docSubtitle = $isTrade
    ? 'Wholesale price list and case sizes'
    : 'Our full range of handcrafted ice cream';
$docOtherLinks = [
    'Allergens & nutrition' => $base . '/pages/allergens.php',
    'Storage'               => $base . '/pages/storage.php',
];

require __DIR__ . '/../includes/doc_page.php';
