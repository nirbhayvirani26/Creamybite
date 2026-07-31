<?php
// ============================================================
//  Creamy Bite – Credentials
//
//  Every real secret lives here and nowhere else. config.php reads
//  this file and publishes the values as constants.
//
//  KEEP THIS FILE OUT of any public repository, ZIP, or backup that
//  leaves your machine. If it is ever exposed, rotate everything in it:
//    - Stripe keys:  https://dashboard.stripe.com/apikeys
//    - Gmail app password: https://myaccount.google.com/apppasswords
//    - Hostinger database password: hPanel > Databases
// ============================================================

return [

    // ── Database ─────────────────────────────────────────────
    'db_local' => [
        'host' => 'localhost',
        'port' => '8889',          // MAMP's MySQL port
        'name' => 'creamybite',
        'user' => 'root',
        'pass' => 'root',
    ],
    'db_live' => [
        'host' => 'localhost',
        'port' => '3306',
        'name' => 'u167013900_creamybite',
        'user' => 'u167013900_creamyuser',
        'pass' => 'Creamyorder@2026*',
    ],

    // ── Admin panel login ────────────────────────────────────
    'admin' => [
        'username' => 'creamybite',
        'password' => 'Creamy@2025',
    ],

    // ── Stripe ───────────────────────────────────────────────
    'stripe' => [
        'publishable' => 'pk_live_REDACTED',
        'secret'      => 'sk_live_REDACTED',
        'currency'    => 'gbp',
    ],

    // ── Outgoing email (Gmail SMTP) ───────────────────────────
    'smtp' => [
        'host' => 'smtp.gmail.com',
        'user' => 'princevir2610@gmail.com',
        'pass' => 'uyks hqgb nswn ukmz',   // Gmail App Password, not the account password
        'port' => 587,
    ],
];
