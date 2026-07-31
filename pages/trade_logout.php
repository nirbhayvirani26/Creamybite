<?php
// ============================================================
// Creamy Bite – Trade B2B Logout
// URL: /trade_logout.php
// ============================================================
session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/trade_cart.php';

// Keep the basket for next time, then take it out of this browser session.
// Clearing the session copy matters: the prices in it are wholesale, and
// whoever uses this browser next is a retail visitor until they log in.
tradeCartSave($pdo);
$_SESSION['cart'] = [];
unset($_SESSION['promo']);
unset($_SESSION['trade_user']);

// Back to the home page with an empty basket.
header('Location: ../index.php');
exit;
