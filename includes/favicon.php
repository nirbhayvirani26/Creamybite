<?php
// ============================================================
//  Creamy Bite – Favicon
//
//  Include inside <head>, admin and storefront alike. Split out of
//  seo_head.php because admin pages need the icon but not canonical tags,
//  Open Graph, Twitter cards or the local-business JSON-LD — all of that
//  is public-page-specific and wrong to pull into the admin panel.
// ============================================================
$cbIconBase = defined('SITE_BASE') ? SITE_BASE : '';
?>
<?php // An SVG so it stays sharp on any screen, and because the shop logo is a
      // wide banner — at 16px it scaled to an unreadable sliver. This is drawn
      // for that size: bold shapes, no text, no thin lines. ?>
<link rel="icon" type="image/svg+xml" href="<?= htmlspecialchars($cbIconBase . '/assets/images/favicon.svg') ?>">
<link rel="apple-touch-icon" href="<?= htmlspecialchars($cbIconBase . '/assets/images/favicon.svg') ?>">
<meta name="theme-color" content="#5C1D24">
