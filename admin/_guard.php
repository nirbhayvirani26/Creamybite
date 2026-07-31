<?php
// ============================================================
//  Creamy Bite – Admin gate
//
//  require_once __DIR__ . '/_guard.php';  at the top of EVERY admin file
//  that can change anything. It starts the session, refuses anyone who is
//  not logged in, and pulls in the CSRF helpers.
//
//  This exists because several admin scripts shipped with no check at all:
//  seed_products.php (which rewrites every product's price), setup_stock_v3
//  and setup_trade_v4 were all reachable by anyone who knew the URL.
// ============================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['admin_logged_in'])) {
    // JSON handlers must not get an HTML redirect — the caller is parsing JSON.
    $wantsJson = (
        (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))
        || !empty($GLOBALS['ADMIN_GUARD_JSON'])
    );

    if ($wantsJson) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Not authorised. Please log in again.']);
    } else {
        header('Location: login.php');
    }
    exit;
}

require_once __DIR__ . '/../csrf.php';
