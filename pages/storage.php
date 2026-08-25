<?php
// ============================================================
//  Creamy Bite – Storage & handling instructions
//
//  Two audiences on one sheet: a household putting a tub in the freezer, and
//  a trade customer who has to satisfy an environmental health officer that
//  they hold frozen stock correctly. The trade section is separate rather
//  than merged, because the advice genuinely differs — a shop needs the
//  temperature log and the stock rotation, a customer at home does not.
//
//  Products with their own storage wording print it individually. Everything
//  else falls back to CB_DEFAULT_STORAGE, defined once in product_spec.php
//  so the temperature on this page cannot drift away from the one in the FAQ.
// ============================================================
require_once __DIR__ . '/../includes/session.php';
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

// Only products that differ from the standard wording are worth a table row;
// listing thirteen identical lines buries the two that are different.
$special = array_filter($products, static function ($p) {
    return trim((string)($p['storage_instructions'] ?? '')) !== ''
        || trim((string)($p['shelf_life'] ?? '')) !== '';
});

$base = SITE_BASE;

ob_start();
?>

<div class="cbdoc-intro">
    Ice cream is unforgiving about temperature. Everything below comes down to
    one thing: get it into a freezer quickly and keep it there.
</div>

<div class="cbdoc-storage-grid">
    <div class="cbdoc-storage-card">
        <div class="cbdoc-temp">&minus;18&deg;C</div>
        <h3>Storage temperature</h3>
        <p>Or colder. This is the temperature your freezer should already be set to.</p>
    </div>
    <div class="cbdoc-storage-card">
        <h3>On arrival</h3>
        <p>
            Into the freezer straight away. Ice cream left out during unpacking is the
            most common reason a tub disappoints later.
        </p>
    </div>
    <div class="cbdoc-storage-card">
        <h3>Never refreeze</h3>
        <p>
            Once thawed, do not refreeze. Refrozen ice cream grows ice crystals, turns
            grainy, and is no longer safe to keep.
        </p>
    </div>
</div>

<h2>At home</h2>
<ul>
    <li>Keep tubs at the <strong>back of the freezer</strong>, not in the door — the door
        is the warmest part and swings in temperature every time it opens.</li>
    <li>Put the lid back on firmly. An open tub picks up freezer odours and forms
        ice on the surface.</li>
    <li>Let it soften for <strong>5 to 10 minutes</strong> before scooping rather than
        microwaving it. Warming melts the outside long before the middle gives.</li>
    <li>Return it to the freezer promptly after serving. Repeated partial thawing is
        what makes a tub icy by the end.</li>
    <li>Eat within the best-before date on the tub, and within a few days once opened
        for the best texture.</li>
</ul>

<h2>In transit</h2>
<p>
    Our deliveries travel frozen and are not left unattended — someone needs to be
    there to take the order in. If you are collecting, bring a cool bag for anything
    beyond a short journey, and go straight home rather than carrying it around
    while you run other errands.
</p>

<?php if ($isTrade): ?>
<h2>Trade &amp; catering storage</h2>
<ul>
    <li>Hold at <strong>&minus;18&deg;C or colder</strong> throughout, and log freezer
        temperatures as your food safety management system requires.</li>
    <li>Rotate stock <strong>first in, first out</strong>. Check the date on each case
        as it goes in rather than as it comes out.</li>
    <li>Keep cases off the floor and clear of the freezer walls so cold air can move
        around them.</li>
    <li>Check deliveries on arrival and record the temperature. Tell us the same day
        if anything arrives soft — we will replace it.</li>
    <li>For display cabinets, &minus;14&deg;C to &minus;16&deg;C serves better while still
        holding safely. Return to &minus;18&deg;C for overnight storage.</li>
    <li>Do not decant into unlabelled containers: allergen and date information has to
        stay with the product.</li>
</ul>
<?php endif; ?>

<h2>Standard storage instruction</h2>
<p>
    Unless a product says otherwise, the instruction for our whole range is:
</p>
<p><strong><?= htmlspecialchars(CB_DEFAULT_STORAGE) ?></strong></p>

<?php if (!empty($special)): ?>
<h2>Products with specific instructions</h2>
<table class="cbdoc-table">
    <thead>
        <tr>
            <th>Product</th>
            <th>Storage</th>
            <th>Shelf life</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($special as $p): ?>
        <tr>
            <td class="cbdoc-prod"><?= htmlspecialchars($p['name']) ?></td>
            <td><?= htmlspecialchars(cbStorageInstructions($p)) ?></td>
            <td>
                <?php $sl = trim((string)($p['shelf_life'] ?? '')); ?>
                <?= $sl !== '' ? htmlspecialchars($sl) : '<span class="cbdoc-muted">see tub</span>' ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>
<?php endif; ?>

<h2>Something arrived soft?</h2>
<p>
    Tell us within 24 hours — call <?= htmlspecialchars(SHOP_PHONE) ?> or email
    <?= htmlspecialchars(SHOP_EMAIL) ?> with your order number and a photograph if you
    can. We will replace it or refund you. Frozen products cannot be assessed days
    later, which is the only reason we ask you to be quick.
</p>

<?php
$docBody = ob_get_clean();

$docTitle    = 'Storage & Handling';
$docSubtitle = 'How to keep our ice cream at its best';
$docOtherLinks = [
    'Catalogue'             => $base . '/pages/catalogue.php',
    'Allergens & nutrition' => $base . '/pages/allergens.php',
];

require __DIR__ . '/../includes/doc_page.php';
