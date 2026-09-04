<?php
// ============================================================
//  Creamy Bite – Trade Order History (superseded)
//  URL: /trade_history.php
//
//  Order history now lives in the trade account hub, alongside the
//  profile, payments and invoices. This file stays so existing links
//  and bookmarks keep working.
//
//  It also replaced an unsafe query: orders used to be matched on
//  trade_user_id OR customer_email OR phone, which showed a partner any
//  retail order that happened to share their phone number or email.
//  trade_profile.php scopes strictly by trade_user_id.
// ============================================================
require_once __DIR__ . '/../includes/session.php';
// cbUrl() needs SITE_BASE. session.php does not pull config.php in, so a file
// that only redirects still has to ask for it.
require_once __DIR__ . '/../includes/config.php';

if (empty($_SESSION['trade_user'])) {
    header('Location: ' . cbUrl('trade_login'));
    exit;
}

header('Location: ' . cbUrl('trade_profile') . '?tab=orders');
exit;
