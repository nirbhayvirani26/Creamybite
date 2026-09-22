<?php
// ============================================================
//  Creamy Bite – What is still missing from the product data  (READ ONLY)
//  URL: /admin/migrations/allergen_check.php
//
//  Allergen information is not a nice-to-have for a food business: under
//  the Food Information Regulations it has to be available, and getting it
//  wrong is the kind of mistake that hurts somebody. The database has the
//  columns. Most rows do not have the values.
//
//  Until now nothing said which. The product editor shows one product at a
//  time, so "are we done?" meant opening fourteen of them and remembering.
//  This is that question answered on one screen, worst first.
//
//  Writes nothing. Every check is a read.
//
//  A note on what counts as done. A product with no allergens ticked is NOT
//  finished — it is indistinguishable from one nobody has opened yet. Ticking
//  nothing and signing it off are different acts, and only the second is a
//  statement. That is why allergen_reviewed_at exists and why this page
//  counts it rather than counting ticks.
// ============================================================
require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_permissions.php';
adminRequire('products');

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/product_spec.php';

$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

$products = $pdo->query("SELECT * FROM products ORDER BY available DESC, name")->fetchAll(PDO::FETCH_ASSOC);

// ── What is missing, per product ────────────────────────────
//
// Ordered by how much it matters. Allergens first because that is the one
// with a legal duty and a safety consequence behind it; nutrition next
// because it is also mandatory on packaged food; the rest are commercial.
$rows = [];
foreach ($products as $p) {
    $missing = [];
    $severity = 0;

    if (!cbAllergenReviewed($p)) {
        $missing[] = ['critical', 'Allergens not signed off'];
        $severity += 100;
    }
    if (trim((string)($p['ingredients'] ?? '')) === '') {
        $missing[] = ['critical', 'No ingredients'];
        $severity += 50;
    }
    if (!cbHasNutrition($p)) {
        $missing[] = ['warn', 'No nutrition figures'];
        $severity += 20;
    } else {
        // A partly-filled panel is its own problem: a label showing energy
        // and fat but no salt looks complete and is not.
        $blank = [];
        foreach (cbNutritionRows() as $key => [$label, $unit, $indent]) {
            if (cbNutritionValue($p, $key) === null) {
                $blank[] = $label . ($unit === 'kcal' ? ' (kcal)' : ($unit === 'kJ' ? ' (kJ)' : ''));
            }
        }
        if ($blank !== []) {
            $missing[] = ['warn', 'Nutrition incomplete: ' . implode(', ', $blank)];
            $severity += 10;
        }
        if (trim((string)($p['nutrition_basis'] ?? '')) === '') {
            $missing[] = ['warn', 'No nutrition basis (per 100g / per tub)'];
            $severity += 10;
        }
    }
    if (trim((string)($p['case_size'] ?? '')) === '') {
        $missing[] = ['info', 'No size'];
        $severity += 2;
    }
    if (trim((string)($p['shelf_life'] ?? '')) === '') {
        $missing[] = ['info', 'No shelf life'];
        $severity += 1;
    }

    // A description that names a nut while the allergens say otherwise is
    // worth a second look. This does not decide anything — it cannot, the
    // recipe is not in the database — it just refuses to stay quiet about a
    // contradiction a person should resolve.
    $hay  = strtolower((string)($p['name'] ?? '') . ' ' . ($p['description'] ?? '') . ' ' . ($p['ingredients'] ?? ''));
    $ticked = cbAllergenList($p['allergens'] ?? null);
    $hints = [
        'nuts'           => ['almond', 'cashew', 'pistachio', 'walnut', 'hazelnut', 'pecan', 'nut '],
        'milk'           => ['milk', 'cream', 'butter', 'mava', 'malai', 'khoya'],
        'cereals_gluten' => ['wheat', 'gluten', 'biscuit', 'cookie', 'brownie', 'cone'],
        'peanuts'        => ['peanut'],
        'soybeans'       => ['soy', 'soya'],
        'sesame'         => ['sesame', 'tahini'],
        'eggs'           => ['egg', 'custard'],
    ];
    $suspect = [];
    foreach ($hints as $slug => $words) {
        if (in_array($slug, $ticked, true)) {
            continue;
        }
        foreach ($words as $w) {
            if (str_contains($hay, $w)) {
                $suspect[] = cbAllergens()[$slug] . ' (saw "' . trim($w) . '")';
                break;
            }
        }
    }
    if ($suspect !== [] && cbAllergenReviewed($p)) {
        // Only worth raising on a product somebody has already signed off:
        // on an unreviewed one it is noise on top of a bigger warning.
        $missing[] = ['warn', 'Signed off, but the wording mentions: ' . implode('; ', $suspect)];
        $severity += 30;
    }

    $rows[] = [
        'p'        => $p,
        'missing'  => $missing,
        'severity' => $severity,
    ];
}

usort($rows, fn($a, $b) => $b['severity'] <=> $a['severity']);

$done      = count(array_filter($rows, fn($r) => $r['missing'] === []));
$total     = count($rows);
$isCritical = fn(array $r) => array_filter($r['missing'], fn($m) => $m[0] === 'critical') !== [];
$critical   = count(array_filter($rows, $isCritical));
// "Of those" has to mean of THOSE — the third tile counted every product with
// any gap at all, so it read 13 of 12 and quietly stopped being a subset.
$liveBad    = count(array_filter($rows, fn($r) => $isCritical($r) && (int)($r['p']['available'] ?? 0) === 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Product Data Check</title>
    <?php require __DIR__ . '/../../includes/favicon.php'; ?>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/setup.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body class="admin-wrapper su-page-warm">
<div class="su-wrap cbac-wrap">
    <div class="glass-panel su-card">
        <h1 class="su-h1">🥜 Product Data Check</h1>
        <p class="su-lead">
            What is still missing before the menu and the allergen sheet can be
            called finished. Nothing here changes anything — it only reads.
        </p>

        <div class="cbac-tiles">
            <div class="cbac-tile">
                <span class="cbac-tile-n"><?= $done ?>/<?= $total ?></span>
                <span class="cbac-tile-l">complete</span>
            </div>
            <div class="cbac-tile <?= $critical ? 'is-bad' : 'is-ok' ?>">
                <span class="cbac-tile-n"><?= $critical ?></span>
                <span class="cbac-tile-l">missing allergens or ingredients</span>
            </div>
            <div class="cbac-tile <?= $liveBad ? 'is-warn' : 'is-ok' ?>">
                <span class="cbac-tile-n"><?= $liveBad ?></span>
                <span class="cbac-tile-l">of those are on sale right now</span>
            </div>
        </div>

        <?php if ($critical > 0): ?>
        <div class="su-warn-box">
            <strong>Customers can see this.</strong> The menu now shows a panel per
            product, and a product whose allergens have not been signed off says so
            in plain words rather than showing an empty list. That is the right
            thing for it to say — an empty allergen list that actually means
            "nobody checked" is how someone gets hurt — but it is only a holding
            message until these are filled in.
        </div>
        <?php endif; ?>

        <?php if ($done === $total): ?>
        <p class="su-result su-ok">✅ Every product has its allergens signed off, its
           ingredients published and a full nutrition panel. Nothing to do.</p>
        <?php endif; ?>

        <table class="su-table cbac-table">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>On sale</th>
                    <th>Still needed</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $r): $p = $r['p']; ?>
                <tr class="su-row">
                    <td class="su-cell-name">
                        <?= $h($p['name']) ?>
                        <?php if ($r['missing'] === []): ?>
                        <span class="cbac-done">✓ done</span>
                        <?php endif; ?>
                    </td>
                    <td class="cbac-live">
                        <?= (int)($p['available'] ?? 0) === 1
                            ? '<span class="cbac-yes">Yes</span>'
                            : '<span class="cbac-no">No</span>' ?>
                    </td>
                    <td>
                        <?php if ($r['missing'] === []): ?>
                        <span class="su-ok">Nothing</span>
                        <?php else: ?>
                        <ul class="cbac-missing">
                            <?php foreach ($r['missing'] as [$level, $what]): ?>
                            <li class="is-<?= $h($level) ?>"><?= $h($what) ?></li>
                            <?php endforeach; ?>
                        </ul>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <p class="cbac-note">
            Fill these in under <strong>Products</strong> — open a product and use the
            Allergens &amp; Nutrition fields. Ticking no allergens is not the same as
            signing off that there are none, which is why a product with nothing
            ticked still counts as unreviewed here until it is saved as reviewed.
        </p>

        <p><a class="btn-secondary su-btn-back" href="../index.php?tab=products">← Back to Products</a></p>
    </div>
</div>
</body>
</html>
