<?php
// ============================================================
//  Creamy Bite – Shared SEO tags
//
//  Include inside <head>, after the page's own <title> and description.
//
//  Canonical: without one, /index.php, /index.php?x=1 and / are three
//  different pages to a search engine competing with each other. It is built
//  from SITE_URL and the script path rather than REQUEST_URI, because
//  REQUEST_URI carries query strings and would make every filtered view its
//  own canonical — which is the problem, not the fix.
//
//  Open Graph: a link to this shop pasted into WhatsApp or Facebook showed a
//  bare URL with no picture. For a shop selling something as visual as ice
//  cream that is a real cost.
//
//  Optional, set before including:
//      $seoDescription  – overrides the description used for sharing
//      $seoImage        – absolute or site-relative image for the preview
// ============================================================

$cbSeoBase = defined('SITE_URL') ? rtrim(SITE_URL, '/') : '';
$cbSeoPath = $_SERVER['SCRIPT_NAME'] ?? '';

// Strip the folder the site is installed under, so a local /orders/ prefix
// does not end up baked into the public canonical.
if (defined('SITE_BASE') && SITE_BASE !== '' && str_starts_with($cbSeoPath, SITE_BASE)) {
    $cbSeoPath = substr($cbSeoPath, strlen(SITE_BASE));
}
// "/index.php" and "/" serve the identical page, so leaving the canonical on
// /index.php lets a search engine treat them as two competing copies and split
// the ranking between them. The root form is the one people link to and the one
// the sitemap lists, so that is the one to declare.
if ($cbSeoPath === '/index.php' || $cbSeoPath === 'index.php') {
    $cbSeoPath = '/';
}

// The same argument, for the clean URLs. SCRIPT_NAME is the FILE Apache ran —
// /pages/order.php — but that address now 301s to /order, and a canonical tag
// pointing at a redirect is the one thing a canonical must never be: it tells
// a search engine the real address is somewhere that immediately says "no, it
// is somewhere else". Declare the address visitors actually have.
if (preg_match('#^/pages/([A-Za-z0-9_-]+)\.php$#', $cbSeoPath, $cbSeoMatch)) {
    $cbSeoPath = '/' . $cbSeoMatch[1];
}
$cbSeoUrl = $cbSeoBase . $cbSeoPath;

$cbSeoTitle = $cbSeoTitle ?? (SHOP_NAME . ' – ' . SHOP_TAGLINE);
$cbSeoDesc  = $seoDescription ?? 'Handcrafted ice cream and cocoa drinks made fresh daily in Harrow. Order online for local delivery or collection.';
$cbSeoImg   = $seoImage ?? ($cbSeoBase . '/assets/images/logo.png');
if (!str_starts_with($cbSeoImg, 'http')) {
    $cbSeoImg = $cbSeoBase . '/' . ltrim($cbSeoImg, '/');
}
?>
<link rel="canonical" href="<?= htmlspecialchars($cbSeoUrl) ?>">
<?php require __DIR__ . '/favicon.php'; ?>

<meta property="og:type" content="website">
<meta property="og:site_name" content="<?= htmlspecialchars(SHOP_NAME) ?>">
<meta property="og:title" content="<?= htmlspecialchars($cbSeoTitle) ?>">
<meta property="og:description" content="<?= htmlspecialchars($cbSeoDesc) ?>">
<meta property="og:url" content="<?= htmlspecialchars($cbSeoUrl) ?>">
<meta property="og:image" content="<?= htmlspecialchars($cbSeoImg) ?>">
<meta property="og:locale" content="en_GB">

<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= htmlspecialchars($cbSeoTitle) ?>">
<meta name="twitter:description" content="<?= htmlspecialchars($cbSeoDesc) ?>">
<meta name="twitter:image" content="<?= htmlspecialchars($cbSeoImg) ?>">

<?php
// A local shop is found by people searching for one nearby, so the address and
// opening hours are marked up rather than left as text only a human can read.
if (!empty($cbSeoLocalBusiness)):
?>
<script type="application/ld+json">
<?= json_encode([
    '@context'    => 'https://schema.org',
    '@type'       => 'IceCreamShop',
    'name'        => SHOP_NAME,
    'description' => $cbSeoDesc,
    'url'         => $cbSeoBase,
    'telephone'   => SHOP_PHONE,
    'email'       => SHOP_EMAIL,
    'image'       => $cbSeoImg,
    'priceRange'  => '££',
    'address'     => [
        '@type'           => 'PostalAddress',
        'streetAddress'   => 'Unit E5, Phoenix Business Centre',
        'addressLocality' => 'Harrow',
        'postalCode'      => 'HA1 2SP',
        'addressCountry'  => 'GB',
    ],
    'openingHoursSpecification' => [[
        '@type'     => 'OpeningHoursSpecification',
        'dayOfWeek' => ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday'],
        'opens'     => '11:00',
        'closes'    => '20:00',
    ]],
    'sameAs' => array_values(array_filter([
        defined('SHOP_INSTAGRAM') ? SHOP_INSTAGRAM : null,
        defined('SHOP_FACEBOOK')  ? SHOP_FACEBOOK  : null,
    ])),
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) ?>
</script>
<?php endif; ?>
