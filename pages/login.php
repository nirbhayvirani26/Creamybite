<?php
// Redirect root-level login to admin folder
// cbUrl() needs SITE_BASE, so config.php has to be loaded even though this
// file does nothing but redirect.
require_once __DIR__ . '/../includes/config.php';
header('Location: ' . cbUrl('admin/login.php'));
exit;
