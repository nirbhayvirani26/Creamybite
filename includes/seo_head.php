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
$cbSeoUrl = $cbSeoBase . $cbSeoPath;

$cbSeoTitle = $cbSeoTitle ?? (SHOP_NAME . ' – ' . SHOP_TAGLINE);
$cbSeoDesc  = $seoDescription ?? 'Handcrafted ice cream and cocoa drinks made fresh daily in Harrow. Order online for local delivery or collection.';
$cbSeoImg   = $seoImage ?? ($cbSeoBase . '/assets/images/logo.png');
if (!str_starts_with($cbSeoImg, 'http')) {
    $cbSeoImg = $cbSeoBase . '/' . ltrim($cbSeoImg, '/');
}
?>
<link rel="canonical" href="<?= htmlspecialchars($cbSeoUrl) ?>">
<?php $cbIconBase = defined('SITE_BASE') ? SITE_BASE : ''; ?>
<?php // An SVG so it stays sharp on any screen, and because the shop logo is a
      // wide banner — at 16px it scaled to an unreadable sliver. This is drawn
      // for that size: bold shapes, no text, no thin lines. ?>
<link rel="icon" type="image/svg+xml" href="<?= htmlspecialchars($cbIconBase . '/assets/images/favicon.svg') ?>">
<link rel="apple-touch-icon" href="<?= htmlspecialchars($cbIconBase . '/assets/images/favicon.svg') ?>">
<meta name="theme-color" content="#5C1D24">

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
    'email'       => ADMIN_EMAIL,
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
