<?php
// ============================================================
//  Creamy Bite – admin <head> tags for the home-screen app
//
//  Include inside <head> on every admin page, in place of
//  includes/favicon.php. It carries the favicon too, so a page needs
//  only this one line.
//
//  What it adds on top of the favicon:
//    · manifest.json — name, icon and "open full screen", used when the
//      admin is added to a phone's home screen.
//    · Apple tags — iOS ignores most of the manifest and reads these
//      instead. Without apple-mobile-web-app-capable the icon just opens
//      Safari with an address bar, which is not an app.
//    · A PNG apple-touch-icon. iOS cannot use an SVG home-screen icon;
//      given the SVG favicon it falls back to a screenshot of the page.
//
//  Deliberately no service worker and no offline cache: these pages hold
//  customer names, phone numbers and money, and a cached copy would show
//  stale orders as if they were current. Every open fetches fresh.
// ============================================================

// Absolute from the site root, so it works the same from admin/ and from
// admin/accounting/, locally under /orders and live at the domain root.
$cbAdminBase = (defined('SITE_BASE') ? rtrim(SITE_BASE, '/') : '') . '/admin';
$cbAppV      = @filemtime(__DIR__ . '/manifest.json') ?: 1;   // new icon/name reaches phones after an upload
?>
<link rel="icon" type="image/svg+xml" href="<?= htmlspecialchars((defined('SITE_BASE') ? rtrim(SITE_BASE, '/') : '') . '/assets/images/favicon.svg') ?>">
<link rel="icon" type="image/png" sizes="192x192" href="<?= htmlspecialchars($cbAdminBase . '/assets/icons/app-icon-192.png') ?>">
<link rel="apple-touch-icon" sizes="180x180" href="<?= htmlspecialchars($cbAdminBase . '/assets/icons/apple-touch-icon.png') ?>">
<link rel="manifest" href="<?= htmlspecialchars($cbAdminBase . '/manifest.json?v=' . $cbAppV) ?>">
<meta name="theme-color" content="#5C1D24">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="CB Admin">
<?php // "default" keeps the clock and battery on their own strip above the page,
      // so nothing has to be moved out from under the iPhone's notch. ?>
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<?php // "Print / Save as PDF" for the home-screen app, where iOS ignores
      // window.print(). Does nothing in a normal browser. Versioned by file
      // time: Hostinger's CDN keeps scripts for a year otherwise. ?>
<link rel="stylesheet" href="<?= htmlspecialchars($cbAdminBase . '/assets/css/app-print.css?v=' . (@filemtime(__DIR__ . '/assets/css/app-print.css') ?: 1)) ?>">
<script src="<?= htmlspecialchars($cbAdminBase . '/assets/js/app-print.js?v=' . (@filemtime(__DIR__ . '/assets/js/app-print.js') ?: 1)) ?>" defer></script>
<script>
// Refresh button, only when the admin is opened from the home-screen icon.
// That window has no address bar and no reload button, so without this
// there is no way to see a new order without closing the app.
//
// Reloads as a plain GET of the same address — never location.reload(),
// which after saving a form would offer to submit it again.
(function () {
    var standalone = window.navigator.standalone === true
        || (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches);
    if (!standalone) { return; }
    document.documentElement.classList.add('cb-standalone');
    document.addEventListener('DOMContentLoaded', function () {
        if (/\/login\.php$/.test(location.pathname)) { return; }
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'cb-app-refresh';
        btn.setAttribute('aria-label', 'Refresh');
        btn.title = 'Refresh';
        btn.innerHTML = '<i class="fa-solid fa-rotate-right" aria-hidden="true"></i>';
        btn.addEventListener('click', function () {
            btn.classList.add('is-spinning');
            location.replace(location.pathname + location.search + location.hash);
        });
        var toggle = document.getElementById('sbToggle');
        if (toggle && toggle.parentNode) {
            toggle.parentNode.insertBefore(btn, toggle.nextSibling);
        } else {
            btn.classList.add('is-floating');
            document.body.appendChild(btn);
        }
    });
})();
</script>
