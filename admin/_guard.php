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

require_once __DIR__ . '/../includes/session.php';

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
        // Remember where they were actually trying to go.
        //
        // Without this, logging in always dumps you on the dashboard, so
        // opening a one-off admin URL while logged out runs NOTHING and looks
        // like it worked — you get bounced to login, log in, and land on a
        // normal-looking dashboard. That is precisely how the live database
        // went un-migrated: the migration URL was opened, the login gate ate
        // it, and the runner never executed a single statement.
        //
        // Only a same-site path is stored, never a full URL, so this cannot
        // be turned into an open redirect by crafting the request.
        $wanted = $_SERVER['REQUEST_URI'] ?? '';
        if ($wanted !== '' && $wanted[0] === '/' && !str_starts_with($wanted, '//')) {
            $_SESSION['admin_redirect_after_login'] = $wanted;
        }

        // Resolved from THIS file's folder, not the caller's. A relative
        // "login.php" would break for anything guarded from a subfolder
        // (admin/migrations/…), sending the browser to a path that does
        // not exist. __DIR__ is always the admin folder, so this is
        // correct however deep the guarded script sits.
        $adminUrl = str_replace('\\', '/', substr(__DIR__, strlen(rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/'))));
        header('Location: ' . ($adminUrl !== '' && $adminUrl[0] === '/' ? $adminUrl : '') . '/login.php');
    }
    exit;
}

require_once __DIR__ . '/../includes/csrf.php';

// ── JSON handlers must fail as JSON, never as an HTML fatal ──────────
//
// Without this, any uncaught error (a missing table, a bad column) prints
// PHP's HTML error page. The browser then calls r.json() on that, which
// throws, and the fetch's .catch() reports "Network error" — sending you
// looking at the network when the real message was sitting in the response.
//
// Registered once here, so it covers every current and future handler.
if (!empty($GLOBALS['ADMIN_GUARD_JSON'])) {

    /** Turn a throwable into a JSON reply the caller can actually read. */
    $cbJsonFail = function (string $msg, string $where = '') {
        if (headers_sent()) {
            return;
        }
        http_response_code(500);
        header('Content-Type: application/json');

        // Show the real cause to the admin — they are the only one who can
        // reach these endpoints, and a vague message is what made this hard
        // to diagnose in the first place.
        $hint = '';
        if (stripos($msg, "doesn't exist") !== false || stripos($msg, 'Base table') !== false) {
            $hint = ' — a database table is missing. Run the migrations in admin/migrations on this server.';
        } elseif (stripos($msg, 'Unknown column') !== false) {
            $hint = ' — a database column is missing. Run the migrations in admin/migrations on this server.';
        }

        echo json_encode([
            'success' => false,
            'message' => $msg . $hint,
            'where'   => $where,
        ]);
    };

    set_exception_handler(function (Throwable $e) use ($cbJsonFail) {
        error_log('Handler exception: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        $cbJsonFail($e->getMessage(), basename($e->getFile()) . ':' . $e->getLine());
    });

    register_shutdown_function(function () use ($cbJsonFail) {
        $e = error_get_last();
        if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            $cbJsonFail($e['message'], basename($e['file']) . ':' . $e['line']);
        }
    });
}
