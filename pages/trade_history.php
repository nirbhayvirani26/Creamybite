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

if (empty($_SESSION['trade_user'])) {
    header('Location: trade_login.php');
    exit;
}

header('Location: trade_profile.php?tab=orders');
exit;
