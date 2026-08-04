<?php
// ============================================================
//  Creamy Bite – Allergen & nutrition information
//
//  The single rule this page exists to enforce:
//
//      A product nobody has checked is NEVER printed as free from anything.
//
//  In the database, "contains no allergens" and "nobody has filled this in"
//  are both an empty allergens column. A sheet that renders them the same way
//  tells a customer with a nut allergy that an unchecked Roasted Almond ice
//  cream is safe. cbAllergenStatus() separates the two and this page prints
//  three visibly different things:
//
//      listed      – the allergens, in bold
//      none        – "None of the 14 declarable allergens", but ONLY where
//                    someone has ticked the confirmation in the product editor
//      unreviewed  – "Not yet confirmed — please ask", in red
//
//  The cross-contact warning is printed at the top rather than the bottom
//  because it applies to every row, including the green ones: a shared
//  kitchen cannot promise absence, and "free from" here means "not an
//  ingredient", not "not present".
// ============================================================
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/pricing.php';
require_once __DIR__ . '/../includes/product_spec.php';

$isTrade = tradeIsLoggedIn();

$sql = "SELECT * FROM products WHERE available = 1";
if (!$isTrade) {
    $sql .= " AND trade_only = 0";
}
$sql .= " ORDER BY category ASC, name ASC";
$products = $pdo->query($sql)->fetchAll();

$base = SITE_BASE;

// How much of this is actually filled in? Said plainly at the top, because a
// customer needs to know whether they are reading a complete document.
$reviewed = 0;
$withNutrition = 0;
foreach ($products as $p) {
    if (cbAllergenReviewed($p))  { $reviewed++; }
    if (cbHasNutrition($p))      { $withNutrition++; }
}
$total = count($products);

ob_start();
?>

<div class="cbdoc-warning">
    <strong>Please read before ordering.</strong>
    <?= htmlspecialchars(CB_CROSS_CONTACT_NOTICE) ?>
    Call <?= htmlspecialchars(SHOP_PHONE) ?> and we will go through a specific
    product with you.
</div>

<?php if ($total > 0 && $reviewed < $total): ?>
<div class="cbdoc-notice">
    <strong>This list is still being completed.</strong>
    <?= $reviewed ?> of <?= $total ?> products have had their allergens confirmed
    against the recipe. Products marked <em>not yet confirmed</em> below have not
    been checked — that is not the same as containing no allergens, and we will
    not print it as though it were. Please ask us about any product showing that
    status.
</div>
<?php endif; ?>

<h2>Allergens</h2>

<?php if (empty($products)): ?>
    <p>No products to list. Please call us on <?= htmlspecialchars(SHOP_PHONE) ?>.</p>
<?php else: ?>
<table class="cbdoc-table">
    <thead>
        <tr>
            <th>Product</th>
            <th>Contains</th>
            <th>Notes</th>
        </tr>
    </thead>
    <tbody>
    <?php
    $lastCategory = null;
    foreach ($products as $p):
        if ($p['category'] !== $lastCategory):
            $lastCategory = $p['category'];
    ?>
        <tr class="cbdoc-cat-row"><td colspan="3"><?= htmlspecialchars($p['category']) ?></td></tr>
    <?php endif; ?>
        <tr>
            <td class="cbdoc-prod"><?= htmlspecialchars($p['name']) ?></td>
            <td>
                <?php
                switch (cbAllergenStatus($p)):
                    case 'listed':
                        $labels = cbAllergenLabels($p['allergens']);
                ?>
                    <span class="cbdoc-allergen-list"><?= htmlspecialchars(implode(', ', $labels)) ?></span>
                    <?php if (!cbAllergenReviewed($p)): ?>
                        <br><span class="cbdoc-missing">not yet confirmed against the recipe</span>
                    <?php endif; ?>
                <?php break; case 'none': ?>
                    <span class="cbdoc-allergen-none">None of the 14 declarable allergens</span>
                <?php break; default: ?>
                    <span class="cbdoc-allergen-unknown">Not yet confirmed — please ask</span>
                <?php endswitch; ?>
            </td>
            <td>
                <?php $notes = trim((string)($p['allergen_notes'] ?? '')); ?>
                <?= $notes !== '' ? htmlspecialchars($notes) : '<span class="cbdoc-muted">—</span>' ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<p class="cbdoc-muted">
    The 14 allergens above are those UK law requires food businesses to declare:
    <?= htmlspecialchars(implode(', ', array_values(cbAllergens()))) ?>.
</p>

<h2>Ingredients</h2>
<?php
$withIngredients = array_filter($products, static fn($p) => trim((string)($p['ingredients'] ?? '')) !== '');
?>
<?php if (empty($withIngredients)): ?>
    <p class="cbdoc-missing">
        Ingredient lists have not yet been published here. Please call
        <?= htmlspecialchars(SHOP_PHONE) ?> or check the tub.
    </p>
<?php else: ?>
<table class="cbdoc-table">
    <thead><tr><th>Product</th><th>Ingredients</th></tr></thead>
    <tbody>
    <?php foreach ($withIngredients as $p): ?>
        <tr>
            <td class="cbdoc-prod"><?= htmlspecialchars($p['name']) ?></td>
            <td><?= nl2br(htmlspecialchars($p['ingredients'])) ?></td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php if (count($withIngredients) < $total): ?>
<p class="cbdoc-muted">
    <?= $total - count($withIngredients) ?> further product<?= ($total - count($withIngredients)) === 1 ? '' : 's' ?>
    do not yet have ingredients listed here.
</p>
<?php endif; ?>
<?php endif; ?>

<h2>Nutrition</h2>

<?php if ($withNutrition === 0): ?>
    <div class="cbdoc-notice">
        Nutrition figures have not been published yet. They are added per product in
        our records and will appear here as soon as they are entered — we would
        rather show nothing than show a number nobody has verified.
        Call <?= htmlspecialchars(SHOP_PHONE) ?> if you need a figure now.
    </div>
<?php else: ?>
<?php
    $nutritionRows = cbNutritionRows();
    $bases = array_unique(array_map(
        static fn($p) => trim((string)($p['nutrition_basis'] ?? '100 ml')) ?: '100 ml',
        array_filter($products, 'cbHasNutrition')
    ));
?>
<p>Figures are per <?= htmlspecialchars(implode(' / ', $bases)) ?>.</p>
<table class="cbdoc-table">
    <thead>
        <tr>
            <th>Product</th>
            <?php foreach ($nutritionRows as [$label, $unit, $indent]): ?>
            <th class="cbdoc-num"><?= htmlspecialchars($indent ? $label : $label) ?><br><?= htmlspecialchars($unit) ?></th>
            <?php endforeach; ?>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($products as $p): ?>
        <?php if (!cbHasNutrition($p)) { continue; } ?>
        <tr>
            <td class="cbdoc-prod"><?= htmlspecialchars($p['name']) ?></td>
            <?php foreach (array_keys($nutritionRows) as $key): ?>
            <td class="cbdoc-num">
                <?php $val = cbNutritionValue($p, $key); ?>
                <?= $val !== null ? htmlspecialchars($val) : '<span class="cbdoc-muted">—</span>' ?>
            </td>
            <?php endforeach; ?>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php if ($withNutrition < $total): ?>
<p class="cbdoc-muted">
    <?= $total - $withNutrition ?> product<?= ($total - $withNutrition) === 1 ? '' : 's' ?>
    do not yet have nutrition figures recorded and are not listed in this table.
</p>
<?php endif; ?>
<?php endif; ?>

<h2>If you have an allergy</h2>
<p>
    Tell us before you order — on the phone, or in the notes box at checkout.
    We would far rather have the conversation than have you take a chance on a
    printed sheet. If we cannot be certain a product is safe for you, we will
    say so.
</p>

<?php endif; ?>
<?php
$docBody = ob_get_clean();

$docTitle    = 'Allergen & Nutrition Information';
$docSubtitle = 'Allergens, ingredients and nutrition for our range';
$docOtherLinks = [
    'Catalogue' => $base . '/pages/catalogue.php',
    'Storage'   => $base . '/pages/storage.php',
];

require __DIR__ . '/../includes/doc_page.php';
