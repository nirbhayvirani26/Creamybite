<?php
// ============================================================
// Creamy Bite – Trade B2B Logout
// URL: /trade_logout.php
// ============================================================
session_start();
unset($_SESSION['trade_user']);
header('Location: order.php');
exit;
