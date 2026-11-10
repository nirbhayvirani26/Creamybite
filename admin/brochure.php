<?php
/**
 * Product catalogue — a designed, print-ready brochure of the whole range.
 *
 * For handing to shops, suppliers and wholesale buyers, or emailing as a PDF:
 * open it, press "Print / Save as PDF", choose "Save as PDF". Every page is an
 * A4 sheet.
 *
 * Built from the live products table each time it opens, so a new flavour or
 * a changed size is in the next copy without anyone redoing a design file.
 *
 * Lives in the admin, not on the public site, because it can show trade
 * prices. The public trade price list (pages/catalogue.php) is a different
 * thing — a plain table for signed-in trade customers.
 *
 * Copy rules: the story and the "how we make it" points are the wording
 * already published on the About page. Nothing here is invented — no
 * sourcing, award or certification claims the shop has not made itself.
 *
 * ?prices=none|retail|trade   (default none: safe to give to anyone)
 */

require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_permissions.php';
adminRequire('products');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/asset.php';
require_once __DIR__ . '/../includes/product_spec.php';

$h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

$prices = (string)($_GET['prices'] ?? 'none');
if (!in_array($prices, ['none', 'retail', 'trade'], true)) { $prices = 'none'; }

// ── Products and sizes ───────────────────────────────────────
// Trade-only lines are left out of a retail-priced or unpriced copy: they are
// not on public sale, and a shop customer handed this could not buy them.
$where = $prices === 'trade' ? 'available = 1' : 'available = 1 AND trade_only = 0';
try {
    $products = $pdo->query("SELECT * FROM products WHERE $where ORDER BY category ASC, name ASC")
                    ->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Older databases have no trade_only column yet.
    $products = $pdo->query("SELECT * FROM products WHERE available = 1 ORDER BY category ASC, name ASC")
                    ->fetchAll(PDO::FETCH_ASSOC);
}

$variants = [];
if ($products) {
    foreach ($pdo->query("SELECT * FROM product_variants WHERE available = 1 ORDER BY sort_order ASC, id ASC") as $v) {
        $variants[(int)$v['product_id']][] = $v;
    }
}

// ── Each flavour's colour, taken from its photo ──────────────
//
// The photos are tubs on a white backdrop, and every tub is printed in its
// flavour's colour. Averaging the whole picture would land on grey, so only
// clearly coloured pixels are counted (not near-white, near-black or grey),
// then the hue picks one of twelve tint classes in brochure.css. Classes,
// not a computed colour, because this project keeps styling in stylesheets.
function cbbrTintClass(string $file): string
{
    if (!is_file($file) || !function_exists('imagecreatefromstring')) { return 'cbbr-tint-neutral'; }
    $img = @imagecreatefromstring((string)file_get_contents($file));
    if (!$img) { return 'cbbr-tint-neutral'; }

    $w = imagesx($img); $hgt = imagesy($img);
    $step = max(2, (int)floor(min($w, $hgt) / 90));
    $sx = $sy = $n = 0.0;                       // hue as a vector, so 350° and 10° average to red
    $light = 0.0;
    for ($y = 0; $y < $hgt; $y += $step) {
        for ($x = 0; $x < $w; $x += $step) {
            $c = imagecolorat($img, $x, $y);
            $r = (($c >> 16) & 0xFF) / 255; $g = (($c >> 8) & 0xFF) / 255; $b = ($c & 0xFF) / 255;
            $max = max($r, $g, $b); $min = min($r, $g, $b);
            $l = ($max + $min) / 2; $d = $max - $min;
            if ($d < 0.12 || $l < 0.12 || $l > 0.92) { continue; }   // white backdrop, shadows, greys
            $s = $d / (1 - abs(2 * $l - 1));
            if ($s < 0.25) { continue; }
            if ($max === $r)      { $hue = 60 * fmod((($g - $b) / $d), 6); }
            elseif ($max === $g)  { $hue = 60 * ((($b - $r) / $d) + 2); }
            else                  { $hue = 60 * ((($r - $g) / $d) + 4); }
            $rad = deg2rad($hue < 0 ? $hue + 360 : $hue);
            $sx += cos($rad) * $s; $sy += sin($rad) * $s; $n += $s; $light += $l * $s;
        }
    }
    imagedestroy($img);
    if ($n < 25) { return 'cbbr-tint-neutral'; }      // hardly any colour in the shot

    $hue = rad2deg(atan2($sy, $sx)); if ($hue < 0) { $hue += 360; }
    $l   = $light / $n;
    // Dark oranges and reds read as chocolate/caramel rather than orange.
    if ($hue >= 10 && $hue < 45 && $l < 0.42) { return 'cbbr-tint-cocoa'; }
    return 'cbbr-tint-h' . ((int)round($hue / 30) % 12);   // h0 red, h1 orange … h11 rose
}

$imgDir = __DIR__ . '/../assets/images/products/';
$imgUrl = rtrim(SITE_BASE, '/') . '/assets/images/products/';
foreach ($products as $i => $p) {
    $file = trim((string)($p['image'] ?? ''));
    $products[$i]['_img']  = ($file !== '' && is_file($imgDir . $file)) ? $imgUrl . rawurlencode($file) : '';
    $products[$i]['_tint'] = $products[$i]['_img'] !== '' ? cbbrTintClass($imgDir . $file) : 'cbbr-tint-neutral';
}

// Up to six flavours to a page (two columns, three rows), spread evenly so
// the last page is never one lonely card: 13 flavours go 5 / 4 / 4, not
// 6 / 6 / 1. The spare slots are filled with brand tiles further down.
$perPage = count($products) > 0 ? (int)ceil(count($products) / (int)ceil(count($products) / 6)) : 6;
$pages   = $products ? array_chunk($products, $perPage) : [];
$categories = array_values(array_unique(array_column($products, 'category')));
$missingImg = count(array_filter($products, fn($p) => $p['_img'] === ''));

$money = static fn($v) => '£' . number_format((float)$v, 2);

/** One size line: name, case, and the chosen price if there is one. */
$sizeLine = function (array $p, ?array $v) use ($prices, $money): array {
    $name = $v ? (string)$v['name'] : '';
    $case = cbCaseSize($p, $v);
    // "8 × 500ml" beside a size already called "500ml" says 500ml twice and
    // wrapped onto two lines in the narrow cards. Shorten it to "case of 8".
    if ($name !== '' && preg_match('/^(\d+)\s*[×xX*]\s*(.+)$/u', $case, $cm)
        && mb_strtolower(trim($cm[2])) === mb_strtolower(trim($name))) {
        $case = 'case of ' . $cm[1];
    }
    $price = '';
    if ($prices === 'retail') {
        $price = $money($v ? $v['price'] : $p['price']);
    } elseif ($prices === 'trade') {
        $wp = (float)($v ? $v['wholesale_price'] : $p['wholesale_price']);
        $price = $wp > 0 ? $money($wp) : 'on request';
    }
    return ['name' => $name, 'case' => $case, 'price' => $price];
};

// ── Trade terms ──────────────────────────────────────────────
// The owner's own terms (set 10 Nov 2026). Printed in this catalogue only —
// on the back page and the order form. By the owner's decision the cart and
// checkout are NOT changed to match: they keep their own delivery minimum
// from Delivery & Offers.
$tradeMinimum = 200;
$tradeTerms = [
    ['fa-sterling-sign', 'Minimum trade order £' . number_format($tradeMinimum), 'Per order.'],
    ['fa-hand-holding-dollar', 'Payment on delivery', 'Pay when your order is delivered.'],
];

// ── "Valid until" ────────────────────────────────────────────
// A PDF lives on in inboxes long after prices change, so a priced copy says
// how long its prices stand. Defaults to the end of the month after next.
$validUntil = (string)($_GET['valid'] ?? '');
// checkdate, not strtotime: strtotime quietly turns 30 February into 2 March.
if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $validUntil, $vm) || !checkdate((int)$vm[2], (int)$vm[3], (int)$vm[1])) {
    $validUntil = date('Y-m-t', strtotime('first day of +2 months'));
}
$validLabel = date('j F Y', strtotime($validUntil));

// ── Order form rows: one per size ────────────────────────────
$formRows = [];
foreach ($products as $p) {
    $rows = $variants[(int)$p['id']] ?? [];
    foreach ($rows ?: [null] as $v) {
        $l = $sizeLine($p, $v);
        $formRows[] = ['name' => $p['name'], 'size' => $l['name'] !== '' ? $l['name'] : 'Tub',
                       'case' => $l['case'], 'price' => $l['price']];
    }
}
// The first sheet also carries the customer's details, so it holds fewer rows.
$formPages = [];
if ($formRows) {
    $formPages[] = array_slice($formRows, 0, 16);
    foreach (array_chunk(array_slice($formRows, 16), 30) as $more) { $formPages[] = $more; }
}

// ── Specification rows ───────────────────────────────────────
// Only flavours with ingredients or a shelf life recorded. If none have
// either, the page is left out rather than printed full of blanks.
$specRows = array_values(array_filter($products, fn($p) =>
    trim((string)($p['ingredients'] ?? '')) !== '' || trim((string)($p['shelf_life'] ?? '')) !== ''));
$specPages = $specRows ? array_chunk($specRows, 8) : [];

$totalPages = 2 + count($pages) + count($specPages) + count($formPages) + 1;

// ── Owner's own cover and story photos ───────────────────────
// Uploaded from the "Catalogue images" panel (handlers/brochure_handler.php)
// into assets/images/catalogue/. The ?v= is the file's time, so replacing a
// photo shows the new one at once instead of a cached copy.
$cbbrPhoto = function (string $slot): ?array {
    foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
        $file = __DIR__ . '/../assets/images/catalogue/' . $slot . '.' . $ext;
        if (is_file($file)) {
            $dim = @getimagesize($file);
            return [
                'url' => rtrim(SITE_BASE, '/') . '/assets/images/catalogue/' . $slot . '.' . $ext . '?v=' . filemtime($file),
                'w'   => $dim ? (int)$dim[0] : 0,
                'h'   => $dim ? (int)$dim[1] : 0,
            ];
        }
    }
    return null;
};
$coverPhoto = $cbbrPhoto('cover');
$storyPhoto = $cbbrPhoto('story');
$imgMsg = isset($_GET['img_msg']) ? mb_substr((string)$_GET['img_msg'], 0, 300) : '';
$imgOk  = ($_GET['img_ok'] ?? '') === '1';

$year      = date('Y');
$siteHost  = preg_replace('#^https?://#', '', rtrim(SITE_URL, '/'));
$priceNote = [
    'none'   => '',
    'retail' => 'Retail prices, including VAT where applicable.',
    'trade'  => 'Trade prices, excluding VAT. VAT at ' . (int)(defined('TRADE_VAT_RATE') ? TRADE_VAT_RATE * 100 : 20) . '% is added for VAT-registered accounts.',
][$prices];
?>
<!DOCTYPE html>
<html lang="en-GB">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= $h(SHOP_NAME) ?> – Product Catalogue <?= $h($year) ?></title>
    <?php require __DIR__ . '/_app_head.php'; ?>
    <link rel="stylesheet" href="<?= cbAsset('../assets/css/style.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?= cbAsset('../assets/css/modal.css') ?>">
    <link rel="stylesheet" href="<?= cbAsset('assets/css/brochure.css') ?>">
</head>
<body class="cbbr-body">

<!-- Screen-only toolbar: never printed. -->
<div class="cbbr-toolbar">
    <a href="index.php?tab=products" class="cbbr-tool-back"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Products</a>
    <form method="get" class="cbbr-tool-form">
        <label for="cbbrPrices">Prices</label>
        <select id="cbbrPrices" name="prices" onchange="this.form.submit()">
            <option value="none"   <?= $prices === 'none'   ? 'selected' : '' ?>>No prices — for anyone</option>
            <option value="retail" <?= $prices === 'retail' ? 'selected' : '' ?>>Retail prices</option>
            <option value="trade"  <?= $prices === 'trade'  ? 'selected' : '' ?>>Trade prices — wholesale buyers only</option>
        </select>
        <?php if ($prices !== 'none'): ?>
        <label for="cbbrValid">Prices valid until</label>
        <input type="date" id="cbbrValid" name="valid" value="<?= $h($validUntil) ?>" onchange="this.form.submit()">
        <?php endif; ?>
        <noscript><button type="submit">Show</button></noscript>
    </form>
    <button type="button" class="cbbr-tool-print" onclick="window.print()">
        <i class="fa-solid fa-file-pdf" aria-hidden="true"></i> Print / Save as PDF
    </button>
    <span class="cbbr-tool-note">
        <?= count($products) ?> flavour<?= count($products) === 1 ? '' : 's' ?> · <?= $totalPages ?> A4 pages.
        In the print window choose <strong>Save as PDF</strong>, paper <strong>A4</strong>, and tick <strong>Background graphics</strong>.
        <?php if ($missingImg): ?>
        <span class="cbbr-tool-warn"><?= $missingImg ?> without a photo — add one on the product to fill its card.</span>
        <?php endif; ?>
        <?php if (!$specPages): ?>
        <span class="cbbr-tool-info">The ingredients &amp; shelf-life page appears once those are filled in on the products.</span>
        <?php endif; ?>
        <?php if ($prices === 'trade'): ?>
        <span class="cbbr-tool-warn">Trade prices are on this copy. Only send it to approved trade customers.</span>
        <?php endif; ?>
    </span>
</div>

<!-- Screen-only: choose the cover and story photos. Never printed. -->
<details class="cbbr-images"<?= $imgMsg !== '' ? ' open' : '' ?>>
    <summary><i class="fa-solid fa-image" aria-hidden="true"></i> Catalogue images
        <span class="cbbr-images-sum"><?= $coverPhoto ? 'Own cover photo' : 'Default cover' ?> · <?= $storyPhoto ? 'own story photo' : 'default story photo' ?></span>
    </summary>
    <?php if ($imgMsg !== ''): ?>
    <p class="cbbr-images-msg <?= $imgOk ? 'is-ok' : 'is-bad' ?>"><?= $h($imgMsg) ?></p>
    <?php endif; ?>
    <div class="cbbr-images-grid">
        <?php foreach ([
            'cover' => ['Cover photo', $coverPhoto, 'The big picture on the front page. A square or slightly wide photo fills the frame; a wide banner is shown whole, never cropped. Without one, the cover shows a group of your tubs.', rtrim(SITE_BASE, '/') . '/assets/images/logo.png'],
            'story' => ['Story photo', $storyPhoto, 'Sits beside "Our story". A kitchen, team or behind-the-scenes shot. Without one, the About page photo is used.', rtrim(SITE_BASE, '/') . '/assets/images/about_story.jpg'],
        ] as $slot => [$title, $photo, $help, $fallback]): ?>
        <div class="cbbr-image-slot">
            <div class="cbbr-image-thumb<?= $photo ? '' : ' is-default' ?>">
                <img src="<?= $h($photo ? $photo['url'] : $fallback) ?>" alt="">
                <span><?= $photo ? $h($photo['w'] . '×' . $photo['h']) : 'Default' ?></span>
            </div>
            <div class="cbbr-image-body">
                <strong><?= $h($title) ?></strong>
                <p><?= $h($help) ?></p>
                <form method="post" action="handlers/brochure_handler.php" enctype="multipart/form-data" class="cbbr-image-form">
                    <?= csrfField() ?>
                    <input type="hidden" name="slot" value="<?= $h($slot) ?>">
                    <input type="hidden" name="action" value="upload">
                    <input type="hidden" name="prices" value="<?= $h($prices) ?>">
                    <input type="hidden" name="valid" value="<?= $h($validUntil) ?>">
                    <input type="file" name="image" accept="image/jpeg,image/png,image/webp" required>
                    <button type="submit" class="cbbr-image-btn"><i class="fa-solid fa-upload" aria-hidden="true"></i> <?= $photo ? 'Replace' : 'Upload' ?></button>
                </form>
                <?php if ($photo): ?>
                <form method="post" action="handlers/brochure_handler.php" class="cbbr-image-form"
                      data-confirm="Remove the <?= $h(strtolower($title)) ?>? The catalogue goes back to its default picture." data-confirm-title="Remove photo?" data-confirm-tone="danger" data-confirm-ok="Remove">
                    <?= csrfField() ?>
                    <input type="hidden" name="slot" value="<?= $h($slot) ?>">
                    <input type="hidden" name="action" value="remove">
                    <input type="hidden" name="prices" value="<?= $h($prices) ?>">
                    <input type="hidden" name="valid" value="<?= $h($validUntil) ?>">
                    <button type="submit" class="cbbr-image-btn is-remove"><i class="fa-solid fa-trash" aria-hidden="true"></i> Remove</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</details>

<main class="cbbr-doc">

    <!-- ═══ 1. Cover ═══ -->
    <section class="cbbr-sheet cbbr-cover">
        <div class="cbbr-cover-top">
            <img class="cbbr-cover-logo" src="<?= $h(rtrim(SITE_BASE, '/') . '/assets/images/logo.png') ?>" alt="<?= $h(SHOP_NAME) ?>">
        </div>
        <div class="cbbr-cover-mid">
            <p class="cbbr-cover-kicker">Product catalogue <?= $h($year) ?></p>
            <h1 class="cbbr-cover-title">Small-batch ice cream,<br>made to be remembered</h1>
            <p class="cbbr-cover-tag"><?= $h(SHOP_TAGLINE) ?></p>
        </div>
        <?php if ($coverPhoto): ?>
        <?php
            // A wide banner (often with words on it) would lose its edges if
            // cropped to fill the frame, so anything wider than about 1.6:1
            // is shown whole instead; photos nearer square still fill it.
            $coverWide = $coverPhoto['h'] > 0 && ($coverPhoto['w'] / $coverPhoto['h']) > 1.6;
        ?>
        <div class="cbbr-cover-photo<?= $coverWide ? ' is-wide' : '' ?>">
            <img src="<?= $h($coverPhoto['url']) ?>" alt="">
        </div>
        <?php else: ?>
        <div class="cbbr-cover-tubs">
            <?php
            $coverTubs = array_slice(array_values(array_filter($products, fn($p) => $p['_img'] !== '')), 0, 5);
            foreach ($coverTubs as $i => $p): ?>
            <figure class="cbbr-cover-tub cbbr-cover-tub-<?= $i ?> <?= $h($p['_tint']) ?>">
                <img src="<?= $h($p['_img']) ?>" alt="<?= $h($p['name']) ?>">
            </figure>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <div class="cbbr-cover-foot">
            <span><?= count($products) ?> flavours<?php if ($categories): ?> · <?= $h(implode(' · ', $categories)) ?><?php endif; ?></span>
            <span>
                <?= $prices !== 'none' ? 'Prices valid until ' . $h($validLabel) : 'Edition ' . $h(date('F Y')) ?>
                · <?= $h($siteHost) ?>
            </span>
        </div>
    </section>

    <!-- ═══ 2. Brand story ═══ -->
    <section class="cbbr-sheet cbbr-story">
        <header class="cbbr-sheet-head">
            <span class="cbbr-label">Our story</span>
            <span class="cbbr-sheet-brand"><?= $h(SHOP_NAME) ?></span>
        </header>

        <div class="cbbr-story-grid">
            <div class="cbbr-story-photo">
                <img src="<?= $h($storyPhoto ? $storyPhoto['url'] : rtrim(SITE_BASE, '/') . '/assets/images/about_story.jpg') ?>" alt="">
            </div>
            <div class="cbbr-story-text">
                <h2 class="cbbr-h2">Made with love,<br>served with joy</h2>
                <p>
                    Creamy Bite started from a simple love of real, honest ice cream — the kind
                    that brings you back to summer days and childhood memories. We believed the
                    world needed more flavour and fewer shortcuts.
                </p>
                <p>
                    Everything we make begins with quality: fresh dairy, real fruits, natural
                    cocoa, and genuine passion. No artificial shortcuts, no mass production.
                    Just small-batch, handcrafted goodness made for you.
                </p>
                <ul class="cbbr-highlights">
                    <li><i class="fa-solid fa-leaf" aria-hidden="true"></i> Natural ingredients</li>
                    <li><i class="fa-solid fa-heart" aria-hidden="true"></i> Handcrafted daily</li>
                    <li><i class="fa-solid fa-flag" aria-hidden="true"></i> Based in the UK</li>
                </ul>
            </div>
        </div>

        <h3 class="cbbr-h3">How we make it — every tub is traceable to its batch</h3>
        <div class="cbbr-process">
            <div class="cbbr-process-card">
                <i class="fa-solid fa-layer-group" aria-hidden="true"></i>
                <strong>Batch production</strong>
                <p>Made in numbered batches, each recording what went into it and the day it was made.</p>
            </div>
            <div class="cbbr-process-card">
                <i class="fa-solid fa-link" aria-hidden="true"></i>
                <strong>Tub to order</strong>
                <p>Every tub is assigned to its batch against your order, so we know exactly which batch you received.</p>
            </div>
            <div class="cbbr-process-card">
                <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
                <strong>Recall in one step</strong>
                <p>If a batch ever needs pulling, we can list every order it went out in and contact those customers directly.</p>
            </div>
            <div class="cbbr-process-card">
                <i class="fa-solid fa-clipboard-check" aria-hidden="true"></i>
                <strong>Allergens on record</strong>
                <p>Allergens are recorded per flavour and signed off. A flavour not yet checked says so.</p>
            </div>
        </div>

        <?php // The range at a glance — straight from the products table. ?>
        <h3 class="cbbr-h3">The range at a glance</h3>
        <div class="cbbr-glance">
            <?php foreach ($categories as $cat):
                $names = array_column(array_filter($products, fn($p) => $p['category'] === $cat), 'name');
                $names = array_map(fn($n) => preg_replace('/\s+(Ice Cream\s+)?Tub$/i', '', $n), $names);
            ?>
            <div class="cbbr-glance-cat">
                <strong><?= $h($cat) ?></strong>
                <span><?= $h(implode(' · ', $names)) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- ═══ 3. The range, six to a page ═══ -->
    <?php foreach ($pages as $pi => $chunk): ?>
    <section class="cbbr-sheet cbbr-range">
        <header class="cbbr-sheet-head">
            <span class="cbbr-label">Our flavours</span>
            <span class="cbbr-sheet-brand">
                <?= $h(implode(' · ', array_values(array_unique(array_column($chunk, 'category'))))) ?>
            </span>
        </header>
        <?php if ($priceNote !== '' && $pi === 0): ?>
        <p class="cbbr-price-note"><?= $h($priceNote) ?> Valid until <?= $h($validLabel) ?>.</p>
        <?php endif; ?>

        <div class="cbbr-grid">
        <?php foreach ($chunk as $p):
            $rows = $variants[(int)$p['id']] ?? [];
            $lines = $rows ? array_map(fn($v) => $sizeLine($p, $v), $rows) : [$sizeLine($p, null)];
            $lines = array_values(array_filter($lines, fn($l) => $l['name'] !== '' || $l['case'] !== '' || $l['price'] !== ''));
            $alStatus = cbAllergenStatus($p);
            $alLabels = cbAllergenLabels($p['allergens'] ?? '');
        ?>
            <article class="cbbr-card <?= $h($p['_tint']) ?>">
                <div class="cbbr-card-photo">
                    <?php if ($p['_img'] !== ''): ?>
                    <img src="<?= $h($p['_img']) ?>" alt="<?= $h($p['name']) ?>">
                    <?php else: ?>
                    <span class="cbbr-card-nophoto"><i class="fa-solid fa-ice-cream" aria-hidden="true"></i></span>
                    <?php endif; ?>
                    <span class="cbbr-card-cat"><?= $h($p['category']) ?></span>
                    <?php if (trim((string)($p['badge'] ?? '')) !== ''): ?>
                    <span class="cbbr-card-badge"><?= $h($p['badge']) ?></span>
                    <?php endif; ?>
                </div>
                <div class="cbbr-card-body">
                    <h3 class="cbbr-card-name"><?= $h($p['name']) ?></h3>
                    <p class="cbbr-card-desc"><?= $h($p['description']) ?></p>

                    <?php if ($lines): ?>
                    <ul class="cbbr-sizes">
                        <?php foreach ($lines as $l): ?>
                        <li>
                            <span class="cbbr-size-name"><?= $h($l['name'] !== '' ? $l['name'] : 'Tub') ?></span>
                            <?php if ($l['case'] !== ''): ?><span class="cbbr-size-case"><?= $h($l['case']) ?></span><?php endif; ?>
                            <?php if ($l['price'] !== ''): ?><span class="cbbr-size-price"><?= $h($l['price']) ?></span><?php endif; ?>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>

                    <p class="cbbr-allergens">
                        <?php // Same three states as the allergen sheet. "Not yet confirmed"
                              // is printed as such — never as an empty list, which a buyer
                              // would read as "free from". ?>
                        <?php if ($alStatus === 'listed'): ?>
                            <strong>Contains:</strong> <?= $h(implode(', ', $alLabels)) ?><?php if (!cbAllergenReviewed($p)): ?> <em>(not yet confirmed)</em><?php endif; ?>
                        <?php elseif ($alStatus === 'none'): ?>
                            <strong>Allergens:</strong> none of the 14 declared
                        <?php else: ?>
                            <strong>Allergens:</strong> <em>not yet confirmed — please ask us</em>
                        <?php endif; ?>
                    </p>
                </div>
            </article>
        <?php endforeach; ?>
        <?php
        // Spare slots on a part-full page. Every line here is something the
        // shop already says about itself on the site — nothing new is claimed.
        $fillers = [
            ['fa-quote-left',     SHOP_TAGLINE,                    'Small-batch, handcrafted ice cream, made in the UK.'],
            ['fa-layer-group',    'Made in numbered batches',      'Every tub can be traced back to the batch and the day it was made.'],
            ['fa-store',          'Stock us in your shop',         'Trade accounts get wholesale pricing and case ordering — ' . $siteHost . '/trade_register'],
            ['fa-clipboard-check','Allergens on record',           'Full allergen and nutrition details for every flavour at ' . $siteHost . '/allergens'],
            ['fa-phone',          'Talk to us',                    'Samples, events and custom orders: ' . SHOP_PHONE],
            ['fa-ice-cream',      'New flavours',                  'The range grows through the year. See what is new at ' . $siteHost . '/order'],
        ];
        for ($f = count($chunk); $f < 6 && count($pages) > 1; $f++):
            [$fIcon, $fTitle, $fText] = $fillers[($pi * 2 + $f) % count($fillers)];
        ?>
            <aside class="cbbr-filler cbbr-filler-<?= ($pi + $f) % 3 ?>">
                <i class="fa-solid <?= $h($fIcon) ?>" aria-hidden="true"></i>
                <strong><?= $h($fTitle) ?></strong>
                <p><?= $h($fText) ?></p>
            </aside>
        <?php endfor; ?>
        </div>

        <footer class="cbbr-sheet-foot">
            <span><?= $h(SHOP_NAME) ?> · <?= $h($siteHost) ?></span>
            <span>Page <?= $pi + 3 ?></span>
        </footer>
    </section>
    <?php endforeach; ?>

    <?php $pageNo = 2 + count($pages); ?>

    <!-- ═══ 4. Ingredients, storage & shelf life (only when recorded) ═══ -->
    <?php foreach ($specPages as $si => $specChunk): $pageNo++; ?>
    <section class="cbbr-sheet cbbr-spec">
        <header class="cbbr-sheet-head">
            <span class="cbbr-label">Product specification</span>
            <span class="cbbr-sheet-brand">Ingredients · storage · shelf life</span>
        </header>
        <table class="cbbr-spec-table">
            <thead><tr><th>Flavour</th><th>Ingredients</th><th>Shelf life</th><th>Storage</th></tr></thead>
            <tbody>
            <?php foreach ($specChunk as $p): ?>
                <tr>
                    <td class="cbbr-spec-name"><?= $h($p['name']) ?></td>
                    <td><?= trim((string)$p['ingredients']) !== '' ? $h($p['ingredients']) : '<em>On request</em>' ?></td>
                    <td><?= trim((string)$p['shelf_life']) !== '' ? $h($p['shelf_life']) : '<em>On request</em>' ?></td>
                    <td><?= $h(cbStorageInstructions($p)) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <footer class="cbbr-sheet-foot">
            <span><?= $h(SHOP_NAME) ?> · <?= $h($siteHost) ?>/allergens</span>
            <span>Page <?= $pageNo ?></span>
        </footer>
    </section>
    <?php endforeach; ?>

    <!-- ═══ 5. Trade order form ═══ -->
    <?php foreach ($formPages as $fi => $formChunk): $pageNo++; ?>
    <section class="cbbr-sheet cbbr-form">
        <header class="cbbr-sheet-head">
            <span class="cbbr-label">Trade order form<?= count($formPages) > 1 ? ' · ' . ($fi + 1) . ' of ' . count($formPages) : '' ?></span>
            <span class="cbbr-sheet-brand">Send to <?= $h(SHOP_EMAIL) ?> or WhatsApp <?= $h(SHOP_PHONE) ?></span>
        </header>

        <?php if ($fi === 0): ?>
        <div class="cbbr-form-fields">
            <div class="cbbr-field cbbr-field-wide"><span>Business name</span></div>
            <div class="cbbr-field"><span>Phone</span></div>
            <div class="cbbr-field cbbr-field-wide"><span>Delivery address &amp; postcode</span></div>
            <div class="cbbr-field"><span>Email</span></div>
            <div class="cbbr-field"><span>Trade account no. (if any)</span></div>
            <div class="cbbr-field"><span>Delivery date wanted</span></div>
        </div>
        <p class="cbbr-form-terms">
            <?php foreach ($tradeTerms as $t): ?><strong><?= $h($t[1]) ?></strong> · <?php endforeach; ?>
            Write the number of <strong>cases</strong> you want against each size.
            <?php if ($prices !== 'none'): ?>Prices are per tub, valid until <?= $h($validLabel) ?>.<?php endif; ?>
        </p>
        <?php endif; ?>

        <table class="cbbr-form-table">
            <thead>
                <tr>
                    <th>Flavour</th><th>Size</th><th>Case</th>
                    <?php if ($prices !== 'none'): ?><th class="cbbr-num"><?= $prices === 'trade' ? 'Trade' : 'Price' ?> / tub</th><?php endif; ?>
                    <th class="cbbr-form-qty">Cases</th>
                </tr>
            </thead>
            <tbody>
            <?php $lastName = null; foreach ($formChunk as $r): ?>
                <tr class="<?= $r['name'] !== $lastName ? 'cbbr-form-first' : '' ?>">
                    <td class="cbbr-form-name"><?= $r['name'] !== $lastName ? $h($r['name']) : '' ?></td>
                    <td><?= $h($r['size']) ?></td>
                    <td class="cbbr-form-case"><?= $r['case'] !== '' ? $h($r['case']) : '—' ?></td>
                    <?php if ($prices !== 'none'): ?><td class="cbbr-num"><?= $h($r['price']) ?></td><?php endif; ?>
                    <td class="cbbr-form-qty"><span class="cbbr-box"></span></td>
                </tr>
            <?php $lastName = $r['name']; endforeach; ?>
            </tbody>
        </table>

        <?php if ($fi === count($formPages) - 1): ?>
        <div class="cbbr-form-sign">
            <div class="cbbr-field"><span>Name</span></div>
            <div class="cbbr-field"><span>Signature</span></div>
            <div class="cbbr-field"><span>Date</span></div>
        </div>
        <?php endif; ?>

        <footer class="cbbr-sheet-foot">
            <span><?= $h(SHOP_NAME) ?> · <?= $h($siteHost) ?>/trade_register</span>
            <span>Page <?= $pageNo ?></span>
        </footer>
    </section>
    <?php endforeach; ?>

    <!-- ═══ 6. Ordering and contact ═══ -->
    <section class="cbbr-sheet cbbr-back">
        <header class="cbbr-sheet-head">
            <span class="cbbr-label">Work with us</span>
            <span class="cbbr-sheet-brand"><?= $h(SHOP_NAME) ?></span>
        </header>

        <h2 class="cbbr-h2">Stock Creamy Bite in your shop,<br>café or restaurant</h2>

        <div class="cbbr-ways">
            <div class="cbbr-way">
                <i class="fa-solid fa-store" aria-hidden="true"></i>
                <strong>Open a trade account</strong>
                <p>Wholesale pricing and case ordering for shops, cafés and caterers.</p>
                <span class="cbbr-way-link"><?= $h($siteHost) ?><wbr>/trade_register</span>
            </div>
            <div class="cbbr-way">
                <i class="fa-solid fa-cart-shopping" aria-hidden="true"></i>
                <strong>Order online</strong>
                <p>The full menu with sizes, allergens and delivery days.</p>
                <span class="cbbr-way-link"><?= $h($siteHost) ?><wbr>/order</span>
            </div>
            <div class="cbbr-way">
                <i class="fa-brands fa-whatsapp" aria-hidden="true"></i>
                <strong>Call or message us</strong>
                <p>For samples, event orders and anything not on the menu.</p>
                <span class="cbbr-way-link"><?= $h(SHOP_PHONE) ?></span>
            </div>
        </div>

        <div class="cbbr-terms">
            <?php foreach ($tradeTerms as $t): ?>
            <div class="cbbr-term">
                <i class="fa-solid <?= $h($t[0]) ?>" aria-hidden="true"></i>
                <div><strong><?= $h($t[1]) ?></strong><span><?= $h($t[2]) ?></span></div>
            </div>
            <?php endforeach; ?>
            <div class="cbbr-term">
                <i class="fa-solid fa-snowflake" aria-hidden="true"></i>
                <div><strong>Storage</strong><span><?= $h(CB_DEFAULT_STORAGE) ?></span></div>
            </div>
        </div>

        <div class="cbbr-contact">
            <div class="cbbr-contact-list">
                <p><i class="fa-solid fa-phone" aria-hidden="true"></i> <?= $h(SHOP_PHONE) ?></p>
                <p><i class="fa-solid fa-envelope" aria-hidden="true"></i> <?= $h(SHOP_EMAIL) ?></p>
                <p><i class="fa-solid fa-globe" aria-hidden="true"></i> <?= $h($siteHost) ?></p>
                <?php if (SHOP_INSTAGRAM !== ''): ?>
                <p><i class="fa-brands fa-instagram" aria-hidden="true"></i> @<?= $h(trim((string)parse_url(SHOP_INSTAGRAM, PHP_URL_PATH), '/')) ?></p>
                <?php endif; ?>
                <p class="cbbr-address"><i class="fa-solid fa-location-dot" aria-hidden="true"></i> <?= nl2br($h(SHOP_ADDRESS)) ?></p>
            </div>
            <div class="cbbr-qr">
                <div id="cbbrQr" class="cbbr-qr-code" data-url="<?= $h(rtrim(SITE_URL, '/') . '/order') ?>"></div>
                <span>Scan to see the menu</span>
            </div>
        </div>

        <p class="cbbr-allergy-notice">
            <strong>Allergy advice.</strong> <?= $h(CB_CROSS_CONTACT_NOTICE) ?>
            Full allergen and nutrition information for every flavour: <?= $h($siteHost) ?>/allergens
        </p>

        <footer class="cbbr-sheet-foot">
            <span><?= $h(SHOP_NAME) ?> · <?= $h(SHOP_TAGLINE) ?></span>
            <span>Catalogue <?= $h(date('F Y')) ?></span>
        </footer>
    </section>
</main>

<script src="<?= cbAsset('../assets/js/modal.js') ?>"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
// QR code to the online menu, drawn in the browser. If the library cannot
// load (offline), the box simply stays empty and the address beside it still
// tells people where to go.
(function () {
    var el = document.getElementById('cbbrQr');
    if (!el || typeof QRCode === 'undefined') { return; }
    new QRCode(el, { text: el.getAttribute('data-url'), width: 240, height: 240,
                     colorDark: '#5C1C22', colorLight: '#ffffff', correctLevel: QRCode.CorrectLevel.M });
})();
</script>
</body>
</html>
