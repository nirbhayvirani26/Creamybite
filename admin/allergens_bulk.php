<?php
// ============================================================
//  Creamy Bite – Admin: set allergens for every product at once
//
//  Exists because the alternative is opening thirteen products one at a time
//  and it therefore does not get done — and an allergen sheet that says "not
//  yet confirmed" against every flavour is worth very little to a customer
//  with an allergy.
//
//  What this page does NOT do is pre-tick anything. Milk is a near certainty
//  in a dairy ice cream and the nut flavours are obvious from their names,
//  but "obvious" is not a recipe, and a tick this page put there would look
//  exactly like a tick a person put there. The shortcut buttons fill the
//  boxes in the browser only — nothing is stored until the form is saved,
//  and the confirmation column is what turns "recorded" into "checked".
// ============================================================
require_once __DIR__ . '/../includes/session.php';
if (empty($_SESSION['admin_logged_in'])) {
    header('Location: login.php'); exit;
}
require_once __DIR__ . '/_permissions.php';
require_once __DIR__ . '/../includes/product_icons.php';
adminRequire('products');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/product_spec.php';

$saved = 0;
$errorMsg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $postedAllergens = (array)($_POST['a'] ?? []);
    $postedReviewed  = (array)($_POST['reviewed'] ?? []);
    $postedNotes     = (array)($_POST['notes'] ?? []);

    try {
        $valid = array_keys(cbAllergens());
        $upd = $pdo->prepare(
            "UPDATE products
                SET allergens = :a,
                    allergen_notes = :n,
                    allergen_reviewed_at = :r,
                    nuts_allergy = :nuts
              WHERE id = :id"
        );

        foreach ($pdo->query("SELECT id, allergen_reviewed_at FROM products")->fetchAll() as $row) {
            $pid  = (int)$row['id'];
            $list = array_values(array_intersect($valid, array_map('strval', (array)($postedAllergens[$pid] ?? []))));

            // Keep the original sign-off date when the box stays ticked, so
            // saving this page to correct a typo does not restamp every
            // product as freshly reviewed today.
            if (!empty($postedReviewed[$pid])) {
                $reviewedAt = $row['allergen_reviewed_at'] ?: date('Y-m-d H:i:s');
            } else {
                $reviewedAt = null;
            }

            // The "Contains Nuts" badge on the shop follows the list, so the
            // menu can never disagree with the allergen sheet.
            $nuts = (in_array('nuts', $list, true) || in_array('peanuts', $list, true)) ? 1 : 0;

            $upd->execute([
                'a'    => implode(',', $list),
                'n'    => mb_substr(trim((string)($postedNotes[$pid] ?? '')), 0, 255),
                'r'    => $reviewedAt,
                'nuts' => $nuts,
                'id'   => $pid,
            ]);
            $saved++;
        }
    } catch (PDOException $e) {
        $errorMsg = 'Could not save: ' . $e->getMessage();
        $saved = 0;
    }
}

$products  = $pdo->query("SELECT * FROM products ORDER BY category ASC, name ASC")->fetchAll();
$allergens = cbAllergens();

$confirmed = 0;
foreach ($products as $p) { if (cbAllergenReviewed($p)) { $confirmed++; } }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Allergens – <?= SHOP_NAME ?> Admin</title>
    <?php require __DIR__ . '/../includes/favicon.php'; ?>
    <link rel="stylesheet" href="<?= cbAsset('../assets/css/style.css') ?>">
    <?php // admin.css lives under admin/assets/, not the site-wide assets/ —
          // so it is NOT "../assets/css/admin.css" like the others. Getting
          // that wrong loads no admin styling at all, and the page renders as
          // an unstyled table that still works, which is exactly the kind of
          // break nobody reports as an error. ?>
    <link rel="stylesheet" href="<?= cbAsset('assets/css/admin.css') ?>">
    <link rel="stylesheet" href="<?= cbAsset('../assets/css/components.css') ?>">
    <link rel="stylesheet" href="<?= cbAsset('../assets/css/modal.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="admin-wrapper has-sidebar cbab-body">

<?php
// Same sidebar as every other admin page.
$cbSidebarCurrent = 'products';
require __DIR__ . '/_sidebar.php';
?>

<div class="admin-shell">
<header class="admin-topbar cbat-toggle-only">
    <button class="sb-toggle" id="sbToggle" aria-label="Open menu" aria-controls="adminSidebar" aria-expanded="false">
        <i class="fa-solid fa-bars"></i>
    </button>
</header>
<div class="cbab-wrap">

    <div class="cbab-head">
        <div>
            <h1 class="cbab-title"><i class="fa-solid fa-triangle-exclamation"></i> Allergens</h1>
            <p class="cbab-sub">
                Every product on one screen. Tick what each flavour actually contains,
                then tick <strong>Checked</strong> on the right to confirm you have been
                through the recipe.
            </p>
        </div>
        <a href="index.php?tab=products" class="btn-secondary cbab-back">
            <i class="fa-solid fa-arrow-left"></i> Back to Products
        </a>
    </div>

    <?php if ($saved > 0): ?>
    <div class="alert alert-success">
        <i class="fa-solid fa-circle-check"></i>
        Saved <?= $saved ?> product<?= $saved === 1 ? '' : 's' ?>.
    </div>
    <?php endif; ?>
    <?php if ($errorMsg !== ''): ?>
    <div class="alert alert-danger"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($errorMsg) ?></div>
    <?php endif; ?>

    <div class="cbab-status <?= $confirmed === count($products) ? 'is-done' : 'is-todo' ?>">
        <strong><?= $confirmed ?> of <?= count($products) ?></strong> products confirmed.
        <?php if ($confirmed < count($products)): ?>
            The public allergen sheet shows the rest as <em>not yet confirmed</em> and tells
            customers to ask — it will not describe a product as free from anything until
            you have checked it.
        <?php else: ?>
            The allergen sheet is showing confirmed information for every product.
        <?php endif; ?>
    </div>

    <form method="post">
        <?= csrfField() ?>

        <div class="cbab-tools cbab-noprint">
            <span class="cbab-tools-label">Quick fill:</span>
            <?php // Browser-side only. Nothing is written until Save, and the
                  // Checked column still has to be ticked deliberately. ?>
            <button type="button" class="btn-sm" onclick="cbabColumn('milk', true)">Tick Milk for all</button>
            <button type="button" class="btn-sm" onclick="cbabColumn('milk', false)">Untick Milk for all</button>
            <button type="button" class="btn-sm" onclick="cbabAllChecked(true)">Tick every Checked box</button>
            <button type="button" class="btn-sm" onclick="cbabAllChecked(false)">Clear every Checked box</button>
            <span class="cbab-tools-note">These only fill the boxes — nothing saves until you press Save.</span>
        </div>

        <div class="cbab-scroll">
            <table class="cbab-table">
                <thead>
                    <tr>
                        <th class="cbab-col-product">Product</th>
                        <?php foreach ($allergens as $slug => $label): ?>
                        <th class="cbab-col-allergen">
                            <button type="button" class="cbab-col-toggle"
<?php // cbJsAttr() like every other inline handler. These slugs come from
      // cbAllergens() and contain nothing that needs escaping today, but the
      // pattern is the one that broke Add to Cart and the gallery lightbox, so
      // it is not left lying around to be copied. ?>
                                    onclick="cbabColumnToggle(<?= cbJsAttr($slug) ?>)"
                                    title="Tick or clear this whole column">
                                <span><?= htmlspecialchars($label) ?></span>
                            </button>
                        </th>
                        <?php endforeach; ?>
                        <th class="cbab-col-notes">Notes <small>(e.g. may contain traces)</small></th>
                        <th class="cbab-col-checked">Checked</th>
                    </tr>
                </thead>
                <tbody>
                <?php
                $lastCat = null;
                foreach ($products as $p):
                    $pid = (int)$p['id'];
                    $sel = cbAllergenList($p['allergens'] ?? '');
                    if ($p['category'] !== $lastCat):
                        $lastCat = $p['category'];
                ?>
                    <tr class="cbab-cat-row">
                        <td colspan="<?= count($allergens) + 3 ?>"><?= htmlspecialchars($p['category']) ?></td>
                    </tr>
                <?php endif; ?>
                    <tr class="<?= cbAllergenReviewed($p) ? 'is-confirmed' : 'is-unconfirmed' ?>">
                        <td class="cbab-col-product">
                            <span class="cbab-emoji"><?= cbProductIcon($p['emoji'] ?? null) ?></span>
                            <?= htmlspecialchars($p['name']) ?>
                        </td>
                        <?php foreach ($allergens as $slug => $label): ?>
                        <td class="cbab-cell">
                            <label class="cbab-box" title="<?= htmlspecialchars($label) ?>">
                                <input type="checkbox"
                                       name="a[<?= $pid ?>][]"
                                       value="<?= htmlspecialchars($slug) ?>"
                                       data-allergen="<?= htmlspecialchars($slug) ?>"
                                       <?= in_array($slug, $sel, true) ? 'checked' : '' ?>>
                                
                            </label>
                        </td>
                        <?php endforeach; ?>
                        <td>
                            <input type="text" name="notes[<?= $pid ?>]" class="form-control cbab-notes-input"
                                   maxlength="255" placeholder="—"
                                   value="<?= htmlspecialchars($p['allergen_notes'] ?? '') ?>">
                        </td>
                        <td class="cbab-cell">
                            <label class="cbab-box cbab-box-check"
                                   title="Confirm you have checked this product against its recipe">
                                <input type="checkbox" name="reviewed[<?= $pid ?>]" value="1"
                                       class="cbab-reviewed"
                                       <?= cbAllergenReviewed($p) ? 'checked' : '' ?>>
                                
                            </label>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <div class="cbab-save-bar">
            <button type="submit" class="btn-primary">
                <i class="fa-solid fa-floppy-disk"></i> Save all products
            </button>
            <span class="cbab-save-note">
                Anything left unticked and unchecked keeps showing as
                <em>not yet confirmed</em> to customers, which is the honest answer
                until you have been through it.
            </span>
        </div>
    </form>
</div>

<script>
// All three helpers only tick boxes on screen. Nothing reaches the database
// until Save is pressed, so a mis-click costs nothing.
function cbabColumn(slug, on) {
    document.querySelectorAll('input[data-allergen="' + slug + '"]').forEach(cb => { cb.checked = on; });
}
function cbabColumnToggle(slug) {
    const boxes = [...document.querySelectorAll('input[data-allergen="' + slug + '"]')];
    const allOn = boxes.every(cb => cb.checked);
    boxes.forEach(cb => { cb.checked = !allOn; });
}
function cbabAllChecked(on) {
    document.querySelectorAll('.cbab-reviewed').forEach(cb => { cb.checked = on; });
}
</script>

</div><!-- /admin-shell -->
</body>
</html>
