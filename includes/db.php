<?php
// ============================================================
//  Sweet Scoops – Database Layer (MySQL via PDO)
//  Tables are created manually in phpMyAdmin using setup.sql
// ============================================================
require_once __DIR__ . '/config.php';

// A live server with no .env has no database login, and "connection failed"
// would send someone hunting for a MySQL problem that does not exist. Say
// what is actually wrong.
if (!IS_LOCAL && (DB_NAME === '' || DB_USER === '')) {
    error_log('No database credentials — is there a .env on this server?');
    http_response_code(503);
    exit('The site is not configured yet. If you are the owner: copy .env.example to .env '
       . 'in the site root, fill in the database and Stripe values, then restart PHP.');
}

try {
    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE,            PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES,   false);

    // Pin the session to the same zone PHP uses (see CB_TIMEZONE in config.php).
    // NOW() writes delivered_at, printed_at, sent_at and the VAT submitted_at,
    // while PHP date() writes created_at — if the two disagree, so do the
    // timestamps on a single order.
    //
    // Sent as a fixed offset rather than the name 'Europe/London', because the
    // named zones need MySQL's timezone tables loaded and shared hosting often
    // has not loaded them. The offset is computed from PHP, so it follows BST
    // and GMT automatically instead of being hard-coded to one of them.
    try {
        $tzOffset = (new DateTime('now', new DateTimeZone(CB_TIMEZONE)))->format('P');
        $pdo->exec("SET time_zone = '" . $tzOffset . "'");
    } catch (Throwable $tzError) {
        // A database that refuses the offset still works; its NOW() just falls
        // back to the server clock, which is what happened before this existed.
        error_log('Could not set the database time zone: ' . $tzError->getMessage());
    }
} catch (PDOException $e) {
    die("<div style='font-family:-apple-system,BlinkMacSystemFont,sans-serif; color:#5C1D24; padding:40px; max-width:600px; margin:40px auto; background:#fff7ef; border-radius:16px; border:1px solid #f0e6ee; box-shadow:0 10px 30px rgba(0,0,0,0.08);'>
        <h2 style='margin-top:0;'>⚠️ Database connection failed</h2>
        <p style='color:#666; font-size:14px; background:#fff; padding:12px; border-radius:8px; font-family:monospace; border:1px solid #eee;'>" . htmlspecialchars($e->getMessage()) . "</p>
        <p style='font-size:13px; color:#555;'>Please ensure your MySQL database is active and check the database credentials in <code>config.php</code>.</p>
    </div>");
}
