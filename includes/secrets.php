<?php
// ============================================================
//  Creamy Bite – Credentials
//
//  Values come from the .env file at the project root. This file is only the
//  bridge between .env and the constants config.php publishes; it holds no
//  secrets of its own any more.
//
//  Why the change: this file used to contain the real keys, and it ships in
//  every upload. So each deployment overwrote the server's copy with the
//  developer's — meaning a Stripe key rolled on the live server was silently
//  replaced by an older one the next time anything was uploaded. The symptom
//  was "Could not load payment form" after every deploy, with nothing in the
//  code to point at.
//
//  .env is excluded from git and from the upload package, so nothing that
//  ships can overwrite it. Each machine keeps its own.
//
//  To change a key: edit .env on that machine. On the live server, restart
//  PHP in hPanel afterwards.
// ============================================================

require_once __DIR__ . '/env.php';

if (!cbEnvLoaded()) {
    // Being explicit beats a checkout that half-works. Card payments and email
    // will be unavailable, and the health check names this as the cause.
    error_log('No .env found at ' . dirname(__DIR__) . '/.env — payments and email are disabled.');
}

return [
    // ── Admin panel login ────────────────────────────────────
    'admin' => [
        'username' => cbEnv('ADMIN_USERNAME', ''),
        'password' => cbEnv('ADMIN_PASSWORD', ''),
    ],

    // ── Stripe ───────────────────────────────────────────────
    'stripe' => [
        'publishable' => cbEnv('STRIPE_PUBLISHABLE_KEY', ''),
        'secret'      => cbEnv('STRIPE_SECRET_KEY', ''),
        'currency'    => cbEnv('STRIPE_CURRENCY', 'gbp'),
    ],

    // ── Outgoing email (Gmail SMTP) ───────────────────────────
    'smtp' => [
        'host' => cbEnv('SMTP_HOST', 'smtp.gmail.com'),
        'user' => cbEnv('SMTP_USER', ''),
        'pass' => cbEnv('SMTP_PASS', ''),   // Gmail App Password, not the account password
        'port' => (int)cbEnv('SMTP_PORT', '587'),
    ],
];
