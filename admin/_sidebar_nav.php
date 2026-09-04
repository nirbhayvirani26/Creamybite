<?php
// ============================================================
//  Creamy Bite – Admin sidebar, shared
//
//  This markup used to live only inside admin/index.php, which meant exactly
//  one admin page had a sidebar and every other one — Delivery & Offers, the
//  product form, the reports, the bulk allergen editor, the invoice editor —
//  opened with no way back except the browser button.
//
//  HOW TO USE IT, from any admin page:
//
//      $cbSidebarCurrent = 'store';            // which entry to highlight
//      require __DIR__ . '/_sidebar.php';      // after the guard, inside <body>
//
//  and give the page <body class="admin-wrapper has-sidebar">, because the
//  existing CSS hangs the content offset off that class.
//
//  $adminNav lives HERE and nowhere else. It was moved rather than copied: two
//  copies of a menu drift, and this project has already shipped a navbar that
//  had quietly lost Trade Accounts, Revenue and Inquiries because it was being
//  kept in step by hand. admin/index.php requires this file for the array.
//
//  The badge counts are computed only if the including page has not already
//  worked them out, so index.php — which queries them for its own dashboard —
//  does not pay for them twice.
// ============================================================

if (!isset($pdo)) {
    require_once __DIR__ . '/../includes/db.php';
}
require_once __DIR__ . '/_permissions.php';

// Badges. Each is wrapped because the table it counts may not exist yet on a
// server where the migration has not been run, and a missing badge is a far
// better outcome than a fatal on every admin page.
if (!isset($pendingOrders)) {
    try { $pendingOrders = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'Pending'")->fetchColumn(); }
    catch (Throwable $e) { $pendingOrders = 0; }
}
if (!isset($unreadInquiries)) {
    try { $unreadInquiries = (int)$pdo->query("SELECT COUNT(*) FROM inquiries WHERE is_read = 0")->fetchColumn(); }
    catch (Throwable $e) { $unreadInquiries = 0; }
}
if (!isset($pendingTradeCount)) {
    try { $pendingTradeCount = (int)$pdo->query("SELECT COUNT(*) FROM trade_users WHERE status = 'pending'")->fetchColumn(); }
    catch (Throwable $e) { $pendingTradeCount = 0; }
}
if (!isset($pendingReviews)) {
    try { $pendingReviews = (int)$pdo->query("SELECT COUNT(*) FROM testimonials WHERE approved = 0")->fetchColumn(); }
    catch (Throwable $e) { $pendingReviews = 0; }
}
if (!isset($invoiceOutstanding)) { $invoiceOutstanding = 0.0; }

// Which entry is lit. index.php passes its current tab; a standalone page sets
// $cbSidebarCurrent to its own key before including this.
$cbSidebarCurrent = $cbSidebarCurrent ?? ($activeTab ?? ($tab ?? ''));

$adminNav = [
    ['group' => 'Sales'],
    ['tab' => 'orders',     'icon' => 'fa-clipboard-list',     'label' => 'Orders',
     'badge' => $pendingOrders > 0 ? (string)$pendingOrders : null],
    ['tab' => 'invoices',   'icon' => 'fa-file-invoice',       'label' => 'Invoices',
     'badge' => $invoiceOutstanding > 0 ? '£' . number_format($invoiceOutstanding, 0) : null, 'alert' => true],
    ['tab' => 'revenue',    'icon' => 'fa-chart-line',         'label' => 'Revenue'],

    ['group' => 'Catalogue'],
    ['tab' => 'products',   'icon' => 'fa-ice-cream',          'label' => 'Products'],
    ['tab' => 'stock',      'icon' => 'fa-boxes-stacked',      'label' => 'Stock'],
    ['tab' => 'categories', 'icon' => 'fa-tags',               'label' => 'Categories'],
    ['tab' => 'promos',     'icon' => 'fa-ticket',             'label' => 'Promos'],

    ['group' => 'Shop Setup'],
    // A standalone page rather than a ?tab= in this file — same treatment as
    // VAT & Accounting below. Deliberately NOT added to $validTabs: there is
    // no 'store' block in the tab content further down, so a ?tab=store would
    // render an empty page. Gated by its own 'store' permission key, which is
    // also registered in CBI_GRANTABLE_SECTIONS (admin/handlers/staff_handler.php)
    // and in $cbiGrantableSections on the Staff tab, or it could not be ticked.
    ['href' => 'store.php',  'icon' => 'fa-truck-fast',        'label' => 'Delivery & Offers',
     'perm' => 'store'],

    ['href' => 'production.php', 'icon' => 'fa-industry',      'label' => 'Production',
     'perm' => 'production'],

    ['href' => 'documents.php', 'icon' => 'fa-folder-open',    'label' => 'Documents & SOPs',
     'perm' => 'documents'],

    // Sits beside Production because it is the other half of the same record:
    // Production says what was made, this says where it went. Same standalone
    // treatment, same two-place permission registration.
    ['href' => 'traceability.php', 'icon' => 'fa-diagram-project', 'label' => 'Traceability & Recall',
     'perm' => 'traceability'],

    ['group' => 'Customers'],
    ['tab' => 'trade',      'icon' => 'fa-store',              'label' => 'Trade Accounts',
     'badge' => $pendingTradeCount > 0 ? (string)$pendingTradeCount : null, 'alert' => true],
    ['tab' => 'inquiries',  'icon' => 'fa-envelope-open-text', 'label' => 'Inquiries',
     'badge' => $unreadInquiries > 0 ? (string)$unreadInquiries : null, 'alert' => true],

    ['group' => 'Finance'],
    // Links out to the standalone module rather than being a tab in this
    // file — see admin/accounting/_layout.php for why it lives apart.
    ['href' => 'accounting/index.php', 'icon' => 'fa-chart-pie', 'label' => 'VAT & Accounting',
     'perm' => 'accounting'],

    ['group' => 'Content'],
    ['tab' => 'banners',    'icon' => 'fa-rectangle-ad',       'label' => 'Home Banner'],
    ['tab' => 'gallery',    'icon' => 'fa-images',             'label' => 'Gallery'],
    ['tab' => 'reviews',    'icon' => 'fa-star',               'label' => 'Reviews',
     'badge' => $pendingReviews > 0 ? (string)$pendingReviews : null, 'alert' => true],

    ['group' => 'Admin'],

    // Standalone page, same treatment as Delivery & Offers above: NOT a
    // ?tab= in index.php, so it must never be added to $validTabs there.
    // Its 'traffic' permission key is registered in the two places a key has
    // to appear or it cannot be ticked for a staff member — see the comment
    // on Delivery & Offers.
    ['href' => 'traffic.php', 'icon' => 'fa-chart-simple',     'label' => 'Traffic & Visitors',
     'perm' => 'traffic'],

    ['tab' => 'staff',      'icon' => 'fa-user-shield',        'label' => 'Staff'],
];
