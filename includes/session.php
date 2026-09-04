<?php
// ============================================================
//  Creamy Bite – session start
//
//  require_once this instead of calling session_start() directly. Every entry
//  point on the site goes through it, so the session cookie is issued with the
//  same three flags wherever the visitor happens to land first.
//
//  Those flags were all missing. The cookie went out as a bare
//  "PHPSESSID=…; path=/", which on a live site means:
//
//    HttpOnly  — without it any injected script can read document.cookie and
//                walk off with a logged-in session. It is the difference
//                between an XSS bug being a defacement and being a full
//                takeover of the admin panel.
//
//    Secure    — without it the browser also sends the cookie over plain
//                http. One http:// request to the domain — a typed address, an
//                old bookmark, an image in an email — puts the owner's admin
//                session on the wire in clear text for anyone sharing the
//                cafe wifi. The site 301s to https, but the redirect happens
//                AFTER the cookie has already been sent.
//
//    SameSite  — Lax stops the cookie riding along on cross-site requests,
//                which is a second lock behind the CSRF tokens rather than a
//                replacement for them. Lax, not Strict: Strict would drop the
//                session when a customer follows the link in their own order
//                confirmation email, and they would arrive logged out with an
//                empty basket.
//
//  Secure is set only when the request really is HTTPS, so this stays working
//  on MAMP over http://localhost:8888. Hardcoding it would silently break
//  local development — the browser would refuse to store the cookie and
//  nothing would keep a basket or an admin login.
// ============================================================

if (session_status() === PHP_SESSION_NONE) {

    // Hostinger terminates TLS at a proxy, so $_SERVER['HTTPS'] alone is not
    // enough — the forwarded header is what says how the visitor connected.
    $isHttps =
        (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['HTTP_X_FORWARDED_SSL']   ?? '') === 'on')
        || (($_SERVER['SERVER_PORT'] ?? '') === '443');

    session_set_cookie_params([
        'lifetime' => 0,          // until the browser closes
        'path'     => '/',
        'domain'   => '',         // this host only, no subdomains
        'secure'   => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

// ── The visit log ───────────────────────────────────────────────────────
//
// Hooked here, and nowhere else, because this file is the one thing all 38
// entry points require — which is exactly why the traffic figures could not
// simply be hung off includes/site_header.php: order_confirmation.php,
// catalogue.php and trade_invoice.php never include it, so a third of the
// shop would have been invisible in its own statistics.
//
// cbTrafficArm() only registers a shutdown function; it decides whether the
// request is a public page view, and writes anything, after the page has
// already been sent. The admin panel and the AJAX handlers require this file
// too and are filtered out inside includes/traffic.php.
//
// Nothing is stored in the visitor's browser by any of this. See the header
// of includes/traffic.php for why that distinction is the whole point.
require_once __DIR__ . '/traffic.php';
cbTrafficArm();
