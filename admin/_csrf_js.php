<?php
// ============================================================
//  Creamy Bite – CSRF for admin JavaScript
//
//  The real thing now lives in includes/csrf_js.php, shared with the customer
//  side so the two cannot drift apart. This file stays because eight admin
//  pages include it by this name, and renaming it in all of them buys nothing
//  but eight more chances to miss one.
// ============================================================
require __DIR__ . '/../includes/csrf_js.php';
