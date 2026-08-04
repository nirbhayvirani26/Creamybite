<?php
// ============================================================
//  Creamy Bite – Shared layout for the printable documents
//
//  Used by the product catalogue, the allergen & nutrition sheet and the
//  storage guide.
//
//  ── Why these are HTML and not real PDF files ──────────────
//  Generating a true .pdf needs a PDF library, and this project has none —
//  vendor/ contains the Stripe SDK and nothing else. Rather than add a
//  dependency the shop owner would then have to keep working through every
//  future upload, these pages are laid out for paper and carry a button that
//  opens the browser's print dialog, where "Save as PDF" is the default
//  destination on every current browser. The result is a real PDF file the
//  customer can keep or email on.
//
//  The trade-off is honest and worth stating: the customer performs one extra
//  click, and the exact page breaks depend on their browser. What is gained is
//  a catalogue that is never out of date, because it is generated from the
//  live product table every time it is opened — the failure mode of a real
//  PDF is a price list still in circulation six months after the prices
//  changed.
//
//  Usage, from a file in pages/:
//      $docTitle    = 'Product Catalogue';
//      $docSubtitle = 'Wholesale price list';
//      $docBody     = '<h2>…</h2>';
//      require __DIR__ . '/../includes/doc_page.php';
// ============================================================

if (!isset($docTitle, $docBody)) {
    http_response_code(500);
    exit('doc_page.php included without content.');
}

$docSubtitle = $docSubtitle ?? '';
$docFilename = $docFilename ?? preg_replace('/[^a-z0-9]+/', '-', strtolower($docTitle));
$docBase     = defined('SITE_BASE') ? SITE_BASE : '';
?>
<!DOCTYPE html>
<html lang="en-GB">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($docTitle) ?> – <?= SHOP_NAME ?></title>
    <meta name="description" content="<?= htmlspecialchars($docSubtitle !== '' ? $docSubtitle : $docTitle . ' for ' . SHOP_NAME) ?>">
    <?php
        $cbSeoTitle     = $docTitle . ' – ' . SHOP_NAME;
        $seoDescription = $docSubtitle !== '' ? $docSubtitle : $docTitle . ' for ' . SHOP_NAME;
        require __DIR__ . '/seo_head.php';
    ?>
    <link rel="stylesheet" href="<?= $docBase ?>/assets/css/style.css">
    <link rel="stylesheet" href="<?= $docBase ?>/assets/css/responsive.css">
    <link rel="stylesheet" href="<?= $docBase ?>/assets/css/components.css">
    <link rel="stylesheet" href="<?= $docBase ?>/assets/css/print-doc.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="cbdoc-body">

<?php // Everything in here is hidden by print-doc.css when printing, so the
      // saved PDF opens on the document itself rather than on a toolbar. ?>
<div class="cbdoc-toolbar cbdoc-noprint">
    <div class="cbdoc-toolbar-inner">
        <a href="<?= $docBase ?>/index.php" class="cbdoc-toolbar-back">
            <i class="fa-solid fa-arrow-left"></i> <span>Back to the shop</span>
        </a>
        <div class="cbdoc-toolbar-actions">
            <?php if (!empty($docOtherLinks)): ?>
                <?php foreach ($docOtherLinks as $lbl => $href): ?>
                <a href="<?= htmlspecialchars($href) ?>" class="cbdoc-toolbar-link"><?= htmlspecialchars($lbl) ?></a>
                <?php endforeach; ?>
            <?php endif; ?>
            <button type="button" class="btn-primary cbdoc-print-btn" onclick="window.print()">
                <i class="fa-solid fa-file-arrow-down"></i> Save as PDF
            </button>
        </div>
    </div>
    <p class="cbdoc-toolbar-hint">
        Choose <strong>Save as PDF</strong> as the destination in the print window
        to keep a copy.
    </p>
</div>

<main class="cbdoc-sheet">

    <header class="cbdoc-head">
        <img src="<?= $docBase ?>/assets/images/logo.png" alt="<?= SHOP_NAME ?>" class="cbdoc-logo">
        <div class="cbdoc-head-text">
            <h1 class="cbdoc-title"><?= htmlspecialchars($docTitle) ?></h1>
            <?php if ($docSubtitle !== ''): ?>
            <p class="cbdoc-subtitle"><?= htmlspecialchars($docSubtitle) ?></p>
            <?php endif; ?>
        </div>
        <div class="cbdoc-head-meta">
            <?php // Dated because a price list without a date is one someone
                  // will still be quoting from next year. ?>
            <div class="cbdoc-issued">Issued <?= date('j F Y') ?></div>
            <div class="cbdoc-contact">
                <?= htmlspecialchars(SHOP_PHONE) ?><br>
                <?= htmlspecialchars(SHOP_EMAIL) ?>
            </div>
        </div>
    </header>

    <?= $docBody ?>

    <footer class="cbdoc-foot">
        <div class="cbdoc-foot-block">
            <strong><?= htmlspecialchars(SHOP_NAME) ?></strong><br>
            Unit E5, Phoenix Business Centre<br>
            Harrow HA1 2SP
        </div>
        <div class="cbdoc-foot-block">
            <?= htmlspecialchars(SHOP_PHONE) ?><br>
            <?= htmlspecialchars(SHOP_EMAIL) ?><br>
            <?= htmlspecialchars(preg_replace('#^https?://#', '', SITE_URL)) ?>
        </div>
        <div class="cbdoc-foot-block cbdoc-foot-note">
            This document was generated on <?= date('j F Y') ?> from our live product
            records. Prices and specifications may change — check the website or call
            us before ordering from an older copy.
        </div>
    </footer>

</main>

</body>
</html>
