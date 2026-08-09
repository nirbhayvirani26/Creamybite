<?php
// ============================================================
//  Creamy Bite – Shared layout for the policy pages
//
//  Shipping, Returns, Privacy and Terms are the same page with different
//  words in the middle, and they are the pages nobody remembers to update.
//  One layout means a change to the header, the footer or the print styling
//  reaches all four, and it is impossible for one of them to quietly drift
//  into a different shape.
//
//  Usage, from a file in pages/:
//      $policyTitle = 'Shipping & Delivery';
//      $policyIntro = 'How and where we deliver.';
//      $policyBody  = '<h2>…</h2><p>…</p>';
//      require __DIR__ . '/../includes/policy_page.php';
// ============================================================

if (!isset($policyTitle, $policyBody)) {
    http_response_code(500);
    exit('policy_page.php included without content.');
}

$policyIntro   = $policyIntro   ?? '';
$policyUpdated = $policyUpdated ?? date('F Y');
?>
<!DOCTYPE html>
<html lang="en-GB">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($policyTitle) ?> – <?= SHOP_NAME ?></title>
    <meta name="description" content="<?= htmlspecialchars($policyIntro !== '' ? $policyIntro : $policyTitle . ' for ' . SHOP_NAME) ?>">
    <?php $seoDescription = $policyIntro !== '' ? $policyIntro : $policyTitle . ' for ' . SHOP_NAME; ?>
    <?php $cbSeoTitle = $policyTitle . ' – ' . SHOP_NAME; ?>
    <?php require __DIR__ . '/seo_head.php'; ?>
    <?php // Mirrors the visible breadcrumb in <main> below — Home > this page,
          // nothing more, since that is the only trail a visitor actually sees. ?>
    <script type="application/ld+json"><?= json_encode([
        '@context'        => 'https://schema.org',
        '@type'           => 'BreadcrumbList',
        'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => $cbSeoBase . '/index.php'],
            ['@type' => 'ListItem', 'position' => 2, 'name' => $policyTitle, 'item' => $cbSeoUrl],
        ],
    ]) ?></script>
    <link rel="stylesheet" href="<?= cbAsset('../assets/css/style.css') ?>">
    <link rel="stylesheet" href="<?= cbAsset('../assets/css/responsive.css') ?>">
    <link rel="stylesheet" href="<?= cbAsset('../assets/css/components.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>

<header class="navbar">
    <div class="container nav-container-centered">
        <nav class="nav-left">
            <ul class="nav-links">
                <li><a href="../index.php">Home</a></li>
                <li><a href="order.php">Order</a></li>
                <li><a href="gallery.php">Gallery</a></li>
                <li><a href="about.php">About Us</a></li>
            </ul>
        </nav>
        <a href="../index.php" class="logo logo-center">
            <img src="../assets/images/logo.png" alt="<?= SHOP_NAME ?>" class="logo-img">
        </a>
        <div class="nav-actions nav-right">
            <a href="order.php" class="btn-primary cbpol-nav-btn">Order Now</a>
        </div>
    </div>
</header>

<main class="cbpol-page">
    <div class="container cbpol-shell">
        <nav class="cbpol-breadcrumb" aria-label="Breadcrumb">
            <a href="../index.php">Home</a> <span aria-hidden="true">›</span>
            <span><?= htmlspecialchars($policyTitle) ?></span>
        </nav>

        <h1 class="cbpol-title"><?= htmlspecialchars($policyTitle) ?></h1>
        <?php if ($policyIntro !== ''): ?>
        <p class="cbpol-intro"><?= htmlspecialchars($policyIntro) ?></p>
        <?php endif; ?>
        <p class="cbpol-updated">Last updated: <?= htmlspecialchars($policyUpdated) ?></p>

        <div class="cbpol-body">
            <?= $policyBody ?>
        </div>

        <div class="cbpol-help">
            <h2>Still not sure?</h2>
            <p>
                Call us on <a href="tel:<?= htmlspecialchars(SHOP_PHONE) ?>"><?= htmlspecialchars(SHOP_PHONE) ?></a>
                or email <a href="mailto:<?= htmlspecialchars(SHOP_EMAIL) ?>"><?= htmlspecialchars(SHOP_EMAIL) ?></a>.
                We would rather sort it out than have you guess.
            </p>
        </div>
    </div>
</main>

<?php require __DIR__ . '/site_footer.php'; ?>

</body>
</html>
