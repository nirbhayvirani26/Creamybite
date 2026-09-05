<?php
// ============================================================
//  Creamy Bite – Visit log (first-party, cookie-free)
//
//  Writes one row per public page view so the admin panel can answer the
//  questions the shop previously had no way to answer at all: how many people
//  came today, which pages they actually opened, where they arrived from, and
//  whether the forty hits in the last minute were forty customers or one
//  scraper.
//
//  WHY THIS IS NOT A CONSENT-BANNER PROBLEM
//
//  Nothing here is stored in the visitor's browser. There is no analytics
//  cookie, no pixel, no third party, no request leaving this server. PECR
//  regulation 6 governs storing or reading information ON a user's device,
//  and this stores nothing — the visit is recorded server-side from the
//  request the browser was already making. That is why pages/cookies.php can
//  still honestly say there is no consent banner, and why adding Google
//  Analytics instead would have changed that answer.
//
//  The IP address IS personal data under UK GDPR even though it is not a
//  cookie, so two things below are not optional and must not be quietly
//  removed later: cbTrafficPurge() enforces a retention limit, and
//  pages/cookies.php and pages/privacy.php tell the public this log exists.
//
//  HOW IT IS HOOKED
//
//  includes/session.php calls cbTrafficArm() once, and that registers a
//  shutdown function. Two consequences, both deliberate:
//
//    * The insert happens AFTER the page has been produced, so a slow or
//      failing write can never delay a customer looking at ice cream.
//    * By shutdown, config.php and db.php have loaded on any page that was
//      going to load them, so this file requires nothing at the top and
//      cannot create an include loop with config.php — which is a real risk
//      here, because db.php requires config.php on its line 6 (the same trap
//      documented in includes/store_settings.php).
//
//  Every path is wrapped so that a failure is silent to the visitor. A shop
//  that cannot sell ice cream because its traffic counter broke would be a
//  far worse bug than having no traffic counter.
// ============================================================

/** How long a visit row is kept before cbTrafficPurge() deletes it. */
const CB_TRAFFIC_RETENTION_DAYS = 90;

/**
 * Register the end-of-request recorder. Safe to call on every request and
 * from anywhere; only the first call does anything.
 */
function cbTrafficArm(): void
{
    static $armed = false;
    if ($armed) {
        return;
    }
    $armed = true;

    register_shutdown_function(static function (): void {
        try {
            cbTrafficRecord();
        } catch (Throwable $e) {
            // Never surface. The page has already been sent by now anyway, so
            // the only thing an exception here could do is append a PHP error
            // to finished HTML.
            error_log('Visit log failed: ' . $e->getMessage());
        }
    });
}

/**
 * Is this request a page view worth recording?
 *
 * The filter lives in one place rather than being spread over the pages,
 * because the hook is includes/session.php and that is required by all 38
 * entry points — the admin panel and the AJAX handlers included.
 */
function cbTrafficShouldRecord(): bool
{
    if (PHP_SAPI === 'cli') {
        return false;
    }

    // A page view is a GET. A POST to checkout_handler.php is a customer
    // doing something, not a page being read, and counting it would double
    // every checkout.
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        return false;
    }

    // The owner and staff browsing their own shop would otherwise be the
    // busiest visitors on it.
    if (!empty($_SESSION['admin_logged_in'])) {
        return false;
    }

    $path = cbTrafficPath();

    // The admin panel is not public traffic. Nor are the AJAX endpoints, which
    // are called several times per page and would drown the real page views.
    if (str_starts_with($path, '/admin/') || str_contains($path, '/admin/')) {
        return false;
    }

    $file = basename($path);
    $skip = [
        'cart_handler.php', 'checkout_handler.php', 'promo_handler.php',
        'stripe_intent.php', 'review_submit.php',
    ];
    if (in_array($file, $skip, true)) {
        return false;
    }

    // Anything served as a file rather than read as a page. Apache normally
    // serves these without touching PHP at all, but a rewritten or proxied
    // asset would otherwise land here as a "page view".
    if (preg_match('/\.(css|js|png|jpe?g|gif|webp|svg|ico|woff2?|ttf|map|txt|xml|json)$/i', $file)) {
        return false;
    }

    return true;
}

/** Request path with the query string removed, always starting with "/". */
function cbTrafficPath(): string
{
    $uri  = (string)($_SERVER['REQUEST_URI'] ?? '/');
    $path = (string)(parse_url($uri, PHP_URL_PATH) ?? '/');
    if ($path === '' || $path[0] !== '/') {
        $path = '/' . $path;
    }
    return $path;
}

/**
 * The visitor's real IP address.
 *
 * X-Forwarded-For is NOT trusted on its own. Any client can send that header,
 * so trusting it blindly would make this column say whatever a visitor typed
 * — which is worse than having no IP column, because it looks authoritative.
 *
 * The rule: REMOTE_ADDR is the truth. It is only overridden when REMOTE_ADDR
 * is a private or reserved address, which is exactly the case where the
 * connection genuinely arrived through a reverse proxy on the same network
 * (Hostinger terminates TLS at one — see the same problem solved for the
 * https check in includes/session.php). A visitor connecting directly from
 * the internet has a public REMOTE_ADDR, so their forwarded header is ignored
 * and they cannot forge their own address.
 */
function cbTrafficClientIp(): string
{
    $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');

    $isPublic = static fn(string $ip): bool =>
        filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;

    if ($remote !== '' && $isPublic($remote)) {
        return $remote;                       // direct connection — believe it
    }

    // Behind a proxy. Cloudflare's header is single-valued and set by the edge,
    // so prefer it; otherwise take the first public address in the X-Forwarded-For
    // chain, which is the client end of it.
    $cf = trim((string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
    if ($cf !== '' && $isPublic($cf)) {
        return $cf;
    }

    $chain = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
    foreach (explode(',', $chain) as $candidate) {
        $candidate = trim($candidate);
        if ($candidate !== '' && $isPublic($candidate)) {
            return $candidate;
        }
    }

    return $remote;   // localhost in development, and honest about it
}

/**
 * Browser, OS, device and bot flag, read from the user agent.
 *
 * Order matters in both lists below: Edge and Opera both claim to be Chrome,
 * Chrome claims to be Safari, and every one of them claims to be Mozilla. The
 * most specific match has to be tested first or everything reads as Safari.
 */
function cbTrafficAgent(string $ua): array
{
    $out = ['browser' => 'Unknown', 'os' => 'Unknown', 'device' => 'desktop', 'is_bot' => 0];

    if ($ua === '') {
        // No user agent at all is not a browser. Real ones always send one.
        $out['is_bot'] = 1;
        return $out;
    }

    if (preg_match('/(bot|crawl|spider|slurp|bingpreview|facebookexternalhit|whatsapp|telegram|preview|monitor|uptime|scan|curl|wget|python-requests|httpclient|headless|lighthouse|pingdom|semrush|ahrefs|mj12|dotbot|petalbot|gptbot|claudebot|ccbot|bytespider)/i', $ua)) {
        $out['is_bot'] = 1;
    }

    foreach ([
        'Edge'             => '/\bEdgi?A?e?\/|\bEdg\//i',
        'Opera'            => '/\bOPR\/|\bOpera\b/i',
        'Samsung Internet' => '/SamsungBrowser/i',
        'Firefox'          => '/\bFirefox\/|\bFxiOS\//i',
        'Chrome'           => '/\bChrome\/|\bCriOS\//i',
        'Safari'           => '/\bSafari\//i',
    ] as $name => $pattern) {
        if (preg_match($pattern, $ua)) {
            $out['browser'] = $name;
            break;
        }
    }

    foreach ([
        'iOS'     => '/\biPhone\b|\biPad\b|\biPod\b/i',
        'Android' => '/\bAndroid\b/i',
        'Windows' => '/\bWindows\b/i',
        'macOS'   => '/\bMac OS X\b|\bMacintosh\b/i',
        'Linux'   => '/\bLinux\b|\bX11\b/i',
    ] as $name => $pattern) {
        if (preg_match($pattern, $ua)) {
            $out['os'] = $name;
            break;
        }
    }

    if (preg_match('/\biPad\b|\bTablet\b/i', $ua)) {
        $out['device'] = 'tablet';
    } elseif (preg_match('/\bMobi\b|\bMobile\b|\biPhone\b|\bAndroid\b/i', $ua)) {
        $out['device'] = 'mobile';
    }

    return $out;
}

/**
 * Does the page_views table exist on this server?
 *
 * Checked rather than assumed, and cached for the request, because the live
 * database goes un-migrated more often than anyone would like — that is the
 * whole reason admin/migrations/update_db.php carries the warning it does.
 * A missing table must mean "no traffic figures", never a fatal on the shop.
 */
function cbTrafficReady(PDO $pdo): bool
{
    static $ready = null;
    if ($ready === null) {
        try {
            $q = $pdo->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'page_views'"
            );
            $q->execute();
            $ready = (int)$q->fetchColumn() > 0;
        } catch (Throwable $e) {
            $ready = false;
        }
    }
    return $ready;
}

/**
 * Delete visit rows past the retention window.
 *
 * LIMIT-ed on purpose: an unbounded DELETE on a table with a year of traffic
 * in it holds locks for long enough to be felt on the shop. Called rarely
 * from the front end and once per admin traffic page load, it catches up over
 * a few runs instead of stalling one.
 */
function cbTrafficPurge(PDO $pdo, int $limit = 2000): int
{
    if (!cbTrafficReady($pdo)) {
        return 0;
    }
    try {
        $stmt = $pdo->prepare(
            "DELETE FROM page_views
              WHERE occurred_at < (NOW() - INTERVAL ? DAY)
              ORDER BY id ASC
              LIMIT " . max(1, $limit)
        );
        $stmt->execute([CB_TRAFFIC_RETENTION_DAYS]);
        return $stmt->rowCount();
    } catch (Throwable $e) {
        error_log('Visit log purge failed: ' . $e->getMessage());
        return 0;
    }
}

/**
 * A connection of our own, for pages that never opened one.
 *
 * Several public pages load session.php and config.php but not db.php —
 * pages/cookies.php, pages/faq.php and the other policy pages go through
 * includes/policy_page.php, which needs no database at all. Those pages were
 * silently missing from the traffic figures until this existed, which is the
 * exact failure this feature was supposed to avoid.
 *
 * Two things make this a separate connector rather than a require of db.php:
 *
 *   * SCOPE. db.php assigns $pdo as a plain variable. Required from inside a
 *     function that variable is local to the function, so $GLOBALS['pdo'] is
 *     never set and the caller sees nothing — which is precisely how those
 *     pages came to record no visits at all.
 *
 *   * db.php DIES on failure, printing an HTML "Back in a moment" panel and
 *     sending a 503. That is right for a page that cannot work without a
 *     database, and completely wrong here: this runs at shutdown, after a
 *     perfectly good page has already been sent to the customer, so a blip
 *     would staple an error panel onto the bottom of it.
 *
 * includes/store_settings.php opens its own connection for a related reason
 * and is the pattern followed here, down to the short timeout.
 */
function cbTrafficConnect(): ?PDO
{
    static $pdo = null;
    static $tried = false;

    if ($tried) {
        return $pdo;
    }
    $tried = true;

    if (!defined('DB_NAME') || !defined('DB_USER') || DB_NAME === '') {
        return null;
    }

    try {
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT
             . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            // Counting a visit is never worth holding a connection open for.
            PDO::ATTR_TIMEOUT            => 3,
        ]);
    } catch (Throwable $e) {
        error_log('Visit log could not reach the database: ' . $e->getMessage());
        $pdo = null;
    }

    return $pdo;
}

/**
 * Write the row. Called at shutdown by cbTrafficArm().
 */
function cbTrafficRecord(): void
{
    if (!cbTrafficShouldRecord()) {
        return;
    }
    // config.php has to have loaded for db.php to have credentials. On a page
    // that never loaded it there is nothing to connect with, and that is fine.
    if (!defined('CB_CONFIG_LOADED')) {
        return;
    }

    // Reuse the page's own connection when there is one. Opening a second
    // connection per request just to count the request would cost more than
    // the feature is worth.
    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO) {
        $pdo = cbTrafficConnect();
    }
    if (!$pdo instanceof PDO || !cbTrafficReady($pdo)) {
        return;
    }

    $ua    = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    $agent = cbTrafficAgent($ua);
    $ip    = mb_substr(cbTrafficClientIp(), 0, 45);

    // Where the address says they are, resolved NOW rather than when the
    // report is read. Addresses get reassigned between countries and cities,
    // so resolving at read time would rewrite history: last month's figures
    // would change every time the database file is refreshed.
    //
    // Returns nulls when no database is installed, which is the normal state
    // until somebody runs admin/migrations/geoip_install.php — the columns
    // stay empty and everything else on the page still works.
    require_once __DIR__ . '/geoip.php';
    $geo = cbGeoLookup($ip);

    // Only the referring HOST is kept, never the full URL. The host answers
    // the question the shop actually has ("is Instagram sending anyone?"),
    // while a full referrer can carry someone's search terms or a private
    // path from the site they came from — data this shop has no reason to
    // hold and would then be responsible for.
    $referrerHost = '';
    $referrer = (string)($_SERVER['HTTP_REFERER'] ?? '');
    if ($referrer !== '') {
        $host = parse_url($referrer, PHP_URL_HOST);
        if (is_string($host)) {
            $referrerHost = mb_substr(strtolower($host), 0, 120);
            // Our own pages linking to each other are not a traffic source.
            if ($referrerHost === strtolower((string)($_SERVER['HTTP_HOST'] ?? ''))) {
                $referrerHost = '';
            }
        }
    }

    // Groups the pages of one visit together so "visits" can be counted apart
    // from "page views" — using the session cookie the site already sets for
    // the basket, so this adds nothing new to the visitor's browser. Hashed
    // rather than stored raw: a table of live session ids is a table of keys
    // to other people's baskets and trade logins.
    $sessionKey = '';
    if (session_status() === PHP_SESSION_ACTIVE && session_id() !== '') {
        $sessionKey = substr(hash('sha256', session_id()), 0, 32);
    }

    try {
        $stmt = $pdo->prepare(
            "INSERT INTO page_views
                (path, `query`, referrer_host, ip_address, country_code, country, city,
                 user_agent, browser, os, device, is_bot, session_key, trade_user_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            mb_substr(cbTrafficPath(), 0, 190),
            mb_substr((string)($_SERVER['QUERY_STRING'] ?? ''), 0, 190),
            $referrerHost,
            $ip,
            mb_substr((string)($geo['country_code'] ?? ''), 0, 2),
            mb_substr((string)($geo['country'] ?? ''), 0, 80),
            mb_substr((string)($geo['city'] ?? ''), 0, 90),
            $ua,
            $agent['browser'],
            $agent['os'],
            $agent['device'],
            $agent['is_bot'],
            $sessionKey,
            (int)($_SESSION['trade_user']['id'] ?? 0),
        ]);
    } catch (Throwable $e) {
        error_log('Visit log insert failed: ' . $e->getMessage());
        return;
    }

    // Retention, kept cheap. One request in two hundred pays for it, so the
    // window is enforced continuously without every visitor being charged a
    // DELETE for the privilege of loading a page.
    if (random_int(1, 200) === 1) {
        cbTrafficPurge($pdo);
    }
}
