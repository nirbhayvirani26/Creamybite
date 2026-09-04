<?php
/**
 * Traffic & Visitors.
 *
 * What the shop previously had no way of knowing: how many people came, which
 * pages they opened, where they arrived from, and which addresses were behind
 * it. The rows come from includes/traffic.php, which records a page view
 * server-side without storing anything in the visitor's browser — read that
 * file's header before changing anything here, because the cookie-free part is
 * what keeps pages/cookies.php honest and the site free of a consent banner.
 *
 * Read-only. There is nothing to edit on this page, so it takes no POST and
 * needs no CSRF-protected action; the one thing it writes is the retention
 * purge, which deletes rows the policy already says are gone.
 */

require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_permissions.php';
adminRequire('traffic');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/traffic.php';

$pageTitle = 'Traffic & Visitors';
$pageSub   = 'Who is reaching the shop, what they open, and where they came from';

$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

$ready = cbTrafficReady($pdo);

// ── Filters ─────────────────────────────────────────────────
//
// Whitelisted rather than cast, so ?days=99999 cannot ask the database for a
// scan of everything it has.
$days = (int)($_GET['days'] ?? 7);
if (!in_array($days, [1, 7, 30, 90], true)) {
    $days = 7;
}

// Bots are recorded but hidden by default. A crawler hitting every page each
// night is real traffic to a server and completely irrelevant to a shop
// asking whether customers are arriving — mixing the two makes both useless.
$showBots = ($_GET['bots'] ?? '') === '1';
$botWhere = $showBots ? '' : ' AND is_bot = 0';

// Drill-down: one address, everything it did.
$ipFilter = trim((string)($_GET['ip'] ?? ''));
if ($ipFilter !== '' && filter_var($ipFilter, FILTER_VALIDATE_IP) === false) {
    $ipFilter = '';   // not an address; ignore rather than search for nonsense
}

// Free-text search over the IP table.
$search = trim((string)($_GET['q'] ?? ''));
$search = mb_substr($search, 0, 60);

$page    = max(1, (int)($_GET['p'] ?? 1));
$perPage = 50;

// Every query below is bounded by this window.
$since = date('Y-m-d H:i:s', strtotime('-' . $days . ' days'));

/**
 * Run one of this page's queries, or hand back a default.
 *
 * Wrapped for the same reason the badge counts in _sidebar_nav.php are: this
 * page must still render on a server where the migration has not been run.
 * One failed panel is a far better outcome than a fatal on the whole page.
 */
$ask = function (string $sql, array $args = [], $default = []) use ($pdo) {
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($args);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('Traffic page query failed: ' . $e->getMessage());
        return $default;
    }
};

$totals   = ['views' => 0, 'ips' => 0, 'visits' => 0, 'bots' => 0];
$today    = ['views' => 0, 'ips' => 0];
$series   = [];
$topPages = [];
$topRefs  = [];
$devices  = [];
$browsers = [];
$ipRows   = [];
$ipTotal  = 0;
$ipDetail = [];
$oldest   = null;

if ($ready) {

    // Retention, enforced whenever the owner looks at the page as well as from
    // the front end — so the window holds even on a quiet shop.
    cbTrafficPurge($pdo);

    // Filtered exactly as every table below is, so the tiles and the tables
    // can never disagree. Subtracting the bot count from an unfiltered total
    // would have fixed the views figure and left "unique addresses" quietly
    // counting crawlers.
    $rows = $ask(
        "SELECT COUNT(*) AS views,
                COUNT(DISTINCT ip_address)  AS ips,
                COUNT(DISTINCT session_key) AS visits
           FROM page_views
          WHERE occurred_at >= ?" . $botWhere,
        [$since]
    );
    if ($rows) {
        $totals['views']  = (int)$rows[0]['views'];
        $totals['ips']    = (int)$rows[0]['ips'];
        $totals['visits'] = (int)$rows[0]['visits'];
    }

    // Bots are counted whether or not they are being shown — that tile is how
    // the owner notices a scraper, so hiding it when bots are hidden would
    // hide the very thing it exists to report.
    $rows = $ask(
        "SELECT COUNT(*) AS bots FROM page_views WHERE occurred_at >= ? AND is_bot = 1",
        [$since]
    );
    if ($rows) {
        $totals['bots'] = (int)$rows[0]['bots'];
    }

    $rows = $ask(
        "SELECT COUNT(*) AS views, COUNT(DISTINCT ip_address) AS ips
           FROM page_views
          WHERE occurred_at >= CURDATE()" . $botWhere
    );
    if ($rows) {
        $today = ['views' => (int)$rows[0]['views'], 'ips' => (int)$rows[0]['ips']];
    }

    // Daily counts for the chart. Days with no traffic are absent from this
    // result and are filled in as zero below — a gap in a bar chart reads as
    // "no data recorded", which is a different claim from "nobody came".
    $series = $ask(
        "SELECT DATE(occurred_at) AS d,
                COUNT(*) AS views,
                COUNT(DISTINCT ip_address) AS ips
           FROM page_views
          WHERE occurred_at >= ?" . $botWhere . "
          GROUP BY DATE(occurred_at)
          ORDER BY d ASC",
        [$since]
    );

    $topPages = $ask(
        "SELECT path, COUNT(*) AS views, COUNT(DISTINCT ip_address) AS ips
           FROM page_views
          WHERE occurred_at >= ?" . $botWhere . "
          GROUP BY path
          ORDER BY views DESC
          LIMIT 15",
        [$since]
    );

    $topRefs = $ask(
        "SELECT referrer_host, COUNT(*) AS views, COUNT(DISTINCT ip_address) AS ips
           FROM page_views
          WHERE occurred_at >= ? AND referrer_host <> ''" . $botWhere . "
          GROUP BY referrer_host
          ORDER BY views DESC
          LIMIT 12",
        [$since]
    );

    $devices = $ask(
        "SELECT device, COUNT(*) AS views
           FROM page_views
          WHERE occurred_at >= ?" . $botWhere . "
          GROUP BY device
          ORDER BY views DESC",
        [$since]
    );

    $browsers = $ask(
        "SELECT browser, COUNT(*) AS views
           FROM page_views
          WHERE occurred_at >= ?" . $botWhere . "
          GROUP BY browser
          ORDER BY views DESC
          LIMIT 8",
        [$since]
    );

    $oldestRow = $ask("SELECT MIN(occurred_at) AS o FROM page_views");
    $oldest    = $oldestRow[0]['o'] ?? null;

    // ── The IP table ────────────────────────────────────────
    //
    // LIKE on three columns rather than one, because the owner searching this
    // is as likely to be typing "instagram" or "/trade_login" as an address.
    $where = "occurred_at >= ?" . $botWhere;
    $args  = [$since];
    if ($search !== '') {
        $where .= " AND (ip_address LIKE ? OR user_agent LIKE ? OR path LIKE ?)";
        $like   = '%' . $search . '%';
        array_push($args, $like, $like, $like);
    }

    $countRows = $ask(
        "SELECT COUNT(DISTINCT ip_address) AS n FROM page_views WHERE " . $where,
        $args
    );
    $ipTotal = (int)($countRows[0]['n'] ?? 0);

    $pages = max(1, (int)ceil($ipTotal / $perPage));
    $page  = min($page, $pages);
    $offset = ($page - 1) * $perPage;

    // MAX(...) rather than a correlated subquery for the last page and agent:
    // on a table this shape, one grouped pass is the difference between an
    // instant page and one that scans the traffic log once per address.
    $ipRows = $ask(
        "SELECT ip_address,
                COUNT(*)                    AS views,
                COUNT(DISTINCT session_key) AS visits,
                MIN(occurred_at)            AS first_seen,
                MAX(occurred_at)            AS last_seen,
                MAX(is_bot)                 AS is_bot,
                SUBSTRING_INDEX(GROUP_CONCAT(path      ORDER BY occurred_at DESC SEPARATOR '\\n'), '\\n', 1) AS last_path,
                SUBSTRING_INDEX(GROUP_CONCAT(browser   ORDER BY occurred_at DESC SEPARATOR '\\n'), '\\n', 1) AS browser,
                SUBSTRING_INDEX(GROUP_CONCAT(os        ORDER BY occurred_at DESC SEPARATOR '\\n'), '\\n', 1) AS os,
                SUBSTRING_INDEX(GROUP_CONCAT(device    ORDER BY occurred_at DESC SEPARATOR '\\n'), '\\n', 1) AS device,
                MAX(trade_user_id)          AS trade_user_id
           FROM page_views
          WHERE " . $where . "
          GROUP BY ip_address
          ORDER BY views DESC, last_seen DESC
          LIMIT " . (int)$perPage . " OFFSET " . (int)$offset,
        $args
    );

    if ($ipFilter !== '') {
        $ipDetail = $ask(
            "SELECT occurred_at, path, `query`, referrer_host, browser, os, device, is_bot, user_agent
               FROM page_views
              WHERE ip_address = ? AND occurred_at >= ?
              ORDER BY occurred_at DESC
              LIMIT 300",
            [$ipFilter, $since]
        );
    }
}

// ── CSV export ──────────────────────────────────────────────
//
// Emitted before a byte of HTML, because headers cannot follow output. It
// honours whatever filters are on screen, so what downloads is what the owner
// is looking at rather than a second, differently-shaped export.
if ($ready && ($_GET['export'] ?? '') === 'csv') {

    /**
     * Neutralise a value a spreadsheet would run as a formula.
     *
     * user_agent and referrer_host are written by whoever made the request, so
     * a visitor can choose to send "=HYPERLINK(...)" as their user agent. Excel
     * and Sheets execute a cell beginning =, +, - or @ on open, which turns
     * this export into a way of attacking the person who downloads it. A
     * leading apostrophe makes the cell text and is the standard fix.
     */
    $csvSafe = static function ($v): string {
        $v = (string)$v;
        return ($v !== '' && str_contains("=+-@\t\r", $v[0])) ? "'" . $v : $v;
    };

    $filename = 'creamybite-traffic-' . date('Y-m-d') . '-' . $days . 'd.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');

    $out = fopen('php://output', 'w');

    // A BOM, so Excel opens the file as UTF-8 instead of mangling any
    // non-ASCII in a page path or a browser name.
    fwrite($out, "\xEF\xBB\xBF");

    if ($ipFilter !== '') {
        // The drill-down: one address, every page it opened.
        fputcsv($out, ['When', 'IP address', 'Page', 'Query', 'Came from', 'Browser', 'OS', 'Device', 'Bot', 'User agent']);
        foreach ($ipDetail as $d) {
            fputcsv($out, array_map($csvSafe, [
                $d['occurred_at'], $ipFilter, $d['path'], $d['query'], $d['referrer_host'],
                $d['browser'], $d['os'], $d['device'], (int)$d['is_bot'] === 1 ? 'yes' : 'no', $d['user_agent'],
            ]));
        }
    } else {
        // The summary table. Not paginated — a download is not a screen, and
        // splitting it over pages would be a worse export than a long one.
        fputcsv($out, ['IP address', 'Views', 'Visits', 'First seen', 'Last seen', 'Last page', 'Device', 'Browser', 'OS', 'Bot', 'Trade account']);
        $rows = $ask(
            "SELECT ip_address,
                    COUNT(*)                    AS views,
                    COUNT(DISTINCT session_key) AS visits,
                    MIN(occurred_at)            AS first_seen,
                    MAX(occurred_at)            AS last_seen,
                    MAX(is_bot)                 AS is_bot,
                    SUBSTRING_INDEX(GROUP_CONCAT(path    ORDER BY occurred_at DESC SEPARATOR '\\n'), '\\n', 1) AS last_path,
                    SUBSTRING_INDEX(GROUP_CONCAT(browser ORDER BY occurred_at DESC SEPARATOR '\\n'), '\\n', 1) AS browser,
                    SUBSTRING_INDEX(GROUP_CONCAT(os      ORDER BY occurred_at DESC SEPARATOR '\\n'), '\\n', 1) AS os,
                    SUBSTRING_INDEX(GROUP_CONCAT(device  ORDER BY occurred_at DESC SEPARATOR '\\n'), '\\n', 1) AS device,
                    MAX(trade_user_id)          AS trade_user_id
               FROM page_views
              WHERE " . $where . "
              GROUP BY ip_address
              ORDER BY views DESC, last_seen DESC
              LIMIT 5000",
            $args
        );
        foreach ($rows as $r) {
            fputcsv($out, array_map($csvSafe, [
                $r['ip_address'], (int)$r['views'], (int)$r['visits'], $r['first_seen'], $r['last_seen'],
                $r['last_path'], $r['device'], $r['browser'], $r['os'],
                (int)$r['is_bot'] === 1 ? 'yes' : 'no',
                (int)$r['trade_user_id'] > 0 ? 'yes' : 'no',
            ]));
        }
    }

    fclose($out);
    exit;
}

// ── Chart series, gaps filled ───────────────────────────────
$byDay = [];
foreach ($series as $r) {
    $byDay[(string)$r['d']] = ['views' => (int)$r['views'], 'ips' => (int)$r['ips']];
}
$chart = [];
for ($i = $days - 1; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $chart[] = [
        'date'  => $d,
        'views' => $byDay[$d]['views'] ?? 0,
        'ips'   => $byDay[$d]['ips']   ?? 0,
    ];
}
$peak = 0;
foreach ($chart as $c) {
    $peak = max($peak, $c['views']);
}

/** Keep the current filters when building a link that changes one of them. */
$linkWith = function (array $changes) use ($days, $showBots, $search, $ipFilter): string {
    $params = array_filter([
        'days' => $days,
        'bots' => $showBots ? '1' : null,
        'q'    => $search !== '' ? $search : null,
        'ip'   => $ipFilter !== '' ? $ipFilter : null,
    ], fn($v) => $v !== null && $v !== '');
    foreach ($changes as $k => $v) {
        if ($v === null) { unset($params[$k]); } else { $params[$k] = $v; }
    }
    return 'traffic.php?' . http_build_query($params);
};

$deviceTotal = 0;
foreach ($devices as $d) { $deviceTotal += (int)$d['views']; }
$browserTotal = 0;
foreach ($browsers as $b) { $browserTotal += (int)$b['views']; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $h($pageTitle) ?> – <?= $h(SHOP_NAME) ?> Admin</title>
    <?php require __DIR__ . '/../includes/favicon.php'; ?>
    <link rel="stylesheet" href="<?= cbAsset('../assets/css/style.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?= cbAsset('assets/css/admin.css') ?>">
</head>
<body class="admin-wrapper has-sidebar">

<?php
$cbSidebarCurrent = 'traffic';
require __DIR__ . '/_sidebar.php';
?>

<div class="admin-shell">
<header class="admin-topbar cbat-toggle-only">
    <button class="sb-toggle" id="sbToggle" aria-label="Open menu" aria-controls="adminSidebar" aria-expanded="false">
        <i class="fa-solid fa-bars"></i>
    </button>
</header>

<div class="cbtr-wrap">

    <header class="cbtr-head">
        <h1 class="cbtr-title"><i class="fa-solid fa-chart-simple" aria-hidden="true"></i> <?= $h($pageTitle) ?></h1>
        <p class="cbtr-sub"><?= $h($pageSub) ?></p>
    </header>

    <?php if (!$ready): ?>
    <div class="cbtr-banner is-warn">
        <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
        <div>
            <strong>Traffic recording is not set up on this server yet.</strong>
            Run <a href="migrations/update_db.php">Update DB</a> once, then come back.
            Nothing is being recorded until you do, so this page will stay empty.
        </div>
    </div>
    <?php else: ?>

    <!-- ── Filters ────────────────────────────────────────── -->
    <div class="cbtr-filters">
        <div class="cbtr-range" role="group" aria-label="Date range">
            <?php foreach ([1 => 'Today', 7 => '7 days', 30 => '30 days', 90 => '90 days'] as $d => $label): ?>
            <a class="cbtr-chip <?= $days === $d ? 'is-on' : '' ?>"
               href="<?= $h($linkWith(['days' => $d, 'p' => null])) ?>"><?= $h($label) ?></a>
            <?php endforeach; ?>
        </div>

        <a class="cbtr-chip <?= $showBots ? 'is-on' : '' ?>"
           href="<?= $h($linkWith(['bots' => $showBots ? null : '1', 'p' => null])) ?>">
            <i class="fa-solid fa-robot" aria-hidden="true"></i>
            <?= $showBots ? 'Bots included' : 'Humans only' ?>
        </a>

        <form class="cbtr-search" method="get" action="traffic.php">
            <input type="hidden" name="days" value="<?= (int)$days ?>">
            <?php if ($showBots): ?><input type="hidden" name="bots" value="1"><?php endif; ?>
            <label class="cbtr-visually-hidden" for="cbtrSearch">Search visitors</label>
            <input type="search" id="cbtrSearch" name="q" value="<?= $h($search) ?>"
                   class="cbtr-input" placeholder="Search IP, page or browser…" maxlength="60">
            <button type="submit" class="cbtr-btn"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i> Search</button>
        </form>

        <a class="cbtr-chip cbtr-export" href="<?= $h($linkWith(['export' => 'csv'])) ?>">
            <i class="fa-solid fa-file-csv" aria-hidden="true"></i> Export CSV
        </a>
    </div>

    <!-- ── Headline figures ───────────────────────────────── -->
    <div class="cbtr-stats">
        <div class="cbtr-stat">
            <div class="cbtr-stat-icon"><i class="fa-solid fa-eye" aria-hidden="true"></i></div>
            <div class="cbtr-stat-label">Page views</div>
            <div class="cbtr-stat-value"><?= number_format($totals['views']) ?></div>
            <div class="cbtr-stat-note">last <?= $days === 1 ? 'day' : $days . ' days' ?></div>
        </div>
        <div class="cbtr-stat">
            <div class="cbtr-stat-icon"><i class="fa-solid fa-users" aria-hidden="true"></i></div>
            <div class="cbtr-stat-label">Unique addresses</div>
            <div class="cbtr-stat-value"><?= number_format($totals['ips']) ?></div>
            <div class="cbtr-stat-note">distinct IPs seen</div>
        </div>
        <div class="cbtr-stat">
            <div class="cbtr-stat-icon"><i class="fa-solid fa-person-walking" aria-hidden="true"></i></div>
            <div class="cbtr-stat-label">Visits</div>
            <div class="cbtr-stat-value"><?= number_format($totals['visits']) ?></div>
            <div class="cbtr-stat-note">browsing sessions</div>
        </div>
        <div class="cbtr-stat">
            <div class="cbtr-stat-icon"><i class="fa-solid fa-calendar-day" aria-hidden="true"></i></div>
            <div class="cbtr-stat-label">Today</div>
            <div class="cbtr-stat-value"><?= number_format($today['views']) ?></div>
            <div class="cbtr-stat-note"><?= number_format($today['ips']) ?> address<?= $today['ips'] === 1 ? '' : 'es' ?></div>
        </div>
        <div class="cbtr-stat">
            <div class="cbtr-stat-icon"><i class="fa-solid fa-robot" aria-hidden="true"></i></div>
            <div class="cbtr-stat-label">Bot hits</div>
            <div class="cbtr-stat-value"><?= number_format($totals['bots']) ?></div>
            <div class="cbtr-stat-note">crawlers and scanners</div>
        </div>
    </div>

    <!-- ── Traffic over time ──────────────────────────────── -->
    <section class="cbtr-panel">
        <h2 class="cbtr-panel-title">
            <i class="fa-solid fa-chart-column" aria-hidden="true"></i> Traffic over time
            <span class="cbtr-count">peak <?= number_format($peak) ?>/day</span>
        </h2>

        <?php if ($peak === 0): ?>
            <p class="cbtr-empty">No page views recorded in this period.</p>
        <?php else: ?>
        <?php // Drawn with plain elements rather than a charting library: this
              // shop loads no third-party JavaScript anywhere, and a bar chart
              // of at most ninety values does not justify being the first. ?>
        <div class="cbtr-chart" role="img"
             aria-label="Daily page views for the last <?= (int)$days ?> day<?= $days === 1 ? '' : 's' ?>, peaking at <?= number_format($peak) ?>.">
            <?php foreach ($chart as $c): ?>
            <div class="cbtr-bar-cell">
                <div class="cbtr-bar" style="height: <?= $peak > 0 ? max(2, round($c['views'] / $peak * 100)) : 2 ?>%"
                     title="<?= $h(date('D j M', strtotime($c['date']))) ?> — <?= number_format($c['views']) ?> views, <?= number_format($c['ips']) ?> addresses"></div>
                <span class="cbtr-bar-label"><?= $h(date($days > 30 ? 'j' : 'D', strtotime($c['date']))) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </section>

    <div class="cbtr-cols">
        <!-- ── Top pages ──────────────────────────────────── -->
        <section class="cbtr-panel">
            <h2 class="cbtr-panel-title"><i class="fa-solid fa-file-lines" aria-hidden="true"></i> Most opened pages</h2>
            <?php if (!$topPages): ?>
                <p class="cbtr-empty">Nothing recorded yet.</p>
            <?php else: ?>
            <table class="cbtr-table">
                <thead><tr><th scope="col">Page</th><th scope="col" class="cbtr-num">Views</th><th scope="col" class="cbtr-num">People</th></tr></thead>
                <tbody>
                <?php foreach ($topPages as $p): ?>
                    <tr>
                        <td class="cbtr-mono"><?= $h($p['path']) ?></td>
                        <td class="cbtr-num"><?= number_format((int)$p['views']) ?></td>
                        <td class="cbtr-num"><?= number_format((int)$p['ips']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </section>

        <!-- ── Referrers ──────────────────────────────────── -->
        <section class="cbtr-panel">
            <h2 class="cbtr-panel-title"><i class="fa-solid fa-arrow-right-to-bracket" aria-hidden="true"></i> Where they came from</h2>
            <p class="cbtr-panel-note">
                Only the site they arrived from is kept, never the full link —
                a search page's address can carry what somebody typed into it.
            </p>
            <?php if (!$topRefs): ?>
                <p class="cbtr-empty">No outside referrals recorded. Visitors typed the address, used a bookmark, or arrived from an app that sends no referrer.</p>
            <?php else: ?>
            <table class="cbtr-table">
                <thead><tr><th scope="col">Source</th><th scope="col" class="cbtr-num">Views</th><th scope="col" class="cbtr-num">People</th></tr></thead>
                <tbody>
                <?php foreach ($topRefs as $r): ?>
                    <tr>
                        <td class="cbtr-mono"><?= $h($r['referrer_host']) ?></td>
                        <td class="cbtr-num"><?= number_format((int)$r['views']) ?></td>
                        <td class="cbtr-num"><?= number_format((int)$r['ips']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </section>
    </div>

    <!-- ── Device and browser ─────────────────────────────── -->
    <div class="cbtr-cols">
        <section class="cbtr-panel">
            <h2 class="cbtr-panel-title"><i class="fa-solid fa-mobile-screen" aria-hidden="true"></i> Devices</h2>
            <?php if (!$devices): ?>
                <p class="cbtr-empty">Nothing recorded yet.</p>
            <?php else: foreach ($devices as $d): $pct = $deviceTotal > 0 ? round($d['views'] / $deviceTotal * 100) : 0; ?>
                <div class="cbtr-meter">
                    <div class="cbtr-meter-top">
                        <span><?= $h(ucfirst((string)$d['device'])) ?></span>
                        <span class="cbtr-meter-pct"><?= (int)$pct ?>% · <?= number_format((int)$d['views']) ?></span>
                    </div>
                    <div class="cbtr-meter-track"><div class="cbtr-meter-fill" style="width: <?= (int)$pct ?>%"></div></div>
                </div>
            <?php endforeach; endif; ?>
        </section>

        <section class="cbtr-panel">
            <h2 class="cbtr-panel-title"><i class="fa-solid fa-window-maximize" aria-hidden="true"></i> Browsers</h2>
            <?php if (!$browsers): ?>
                <p class="cbtr-empty">Nothing recorded yet.</p>
            <?php else: foreach ($browsers as $b): $pct = $browserTotal > 0 ? round($b['views'] / $browserTotal * 100) : 0; ?>
                <div class="cbtr-meter">
                    <div class="cbtr-meter-top">
                        <span><?= $h($b['browser']) ?></span>
                        <span class="cbtr-meter-pct"><?= (int)$pct ?>% · <?= number_format((int)$b['views']) ?></span>
                    </div>
                    <div class="cbtr-meter-track"><div class="cbtr-meter-fill" style="width: <?= (int)$pct ?>%"></div></div>
                </div>
            <?php endforeach; endif; ?>
        </section>
    </div>

    <!-- ── One address, in detail ─────────────────────────── -->
    <?php if ($ipFilter !== ''): ?>
    <section class="cbtr-panel cbtr-panel-detail">
        <h2 class="cbtr-panel-title">
            <i class="fa-solid fa-location-crosshairs" aria-hidden="true"></i>
            Everything from <span class="cbtr-mono"><?= $h($ipFilter) ?></span>
            <span class="cbtr-count"><?= count($ipDetail) ?> view<?= count($ipDetail) === 1 ? '' : 's' ?></span>
        </h2>
        <p class="cbtr-panel-note">
            Newest first, up to the last 300 in this period.
            <a href="<?= $h($linkWith(['ip' => null, 'p' => null])) ?>">Clear this filter</a>
        </p>
        <?php if (!$ipDetail): ?>
            <p class="cbtr-empty">Nothing from this address in the selected period.</p>
        <?php else: ?>
        <div class="cbtr-scroll">
        <table class="cbtr-table">
            <thead><tr>
                <th scope="col">When</th><th scope="col">Page</th>
                <th scope="col">From</th><th scope="col">Browser</th>
            </tr></thead>
            <tbody>
            <?php foreach ($ipDetail as $d): ?>
                <tr>
                    <td class="cbtr-nowrap"><?= $h(date('j M, H:i:s', strtotime((string)$d['occurred_at']))) ?></td>
                    <td class="cbtr-mono"><?= $h($d['path']) ?><?= $d['query'] !== '' ? $h('?' . $d['query']) : '' ?></td>
                    <td class="cbtr-mono"><?= $d['referrer_host'] !== '' ? $h($d['referrer_host']) : '—' ?></td>
                    <td><?= $h($d['browser']) ?> · <?= $h($d['os']) ?><?= (int)$d['is_bot'] === 1 ? ' <span class="cbtr-tag is-bot">bot</span>' : '' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <!-- ── The IP table ───────────────────────────────────── -->
    <section class="cbtr-panel">
        <h2 class="cbtr-panel-title">
            <i class="fa-solid fa-network-wired" aria-hidden="true"></i> Visitors by IP address
            <span class="cbtr-count"><?= number_format($ipTotal) ?> address<?= $ipTotal === 1 ? '' : 'es' ?></span>
        </h2>
        <p class="cbtr-panel-note">
            Busiest first. Click an address to see every page it opened.
            Addresses are deleted automatically after <?= (int)CB_TRAFFIC_RETENTION_DAYS ?> days.
        </p>

        <?php if (!$ipRows): ?>
            <p class="cbtr-empty">
                <?= $search !== '' ? 'Nothing matches that search in this period.' : 'No visitors recorded in this period yet.' ?>
            </p>
        <?php else: ?>
        <div class="cbtr-scroll">
        <table class="cbtr-table">
            <thead><tr>
                <th scope="col">IP address</th>
                <th scope="col" class="cbtr-num">Views</th>
                <th scope="col" class="cbtr-num">Visits</th>
                <th scope="col">Last page</th>
                <th scope="col">Device</th>
                <th scope="col">First seen</th>
                <th scope="col">Last seen</th>
            </tr></thead>
            <tbody>
            <?php foreach ($ipRows as $r): ?>
                <tr<?= (int)$r['is_bot'] === 1 ? ' class="is-bot-row"' : '' ?>>
                    <td class="cbtr-mono">
                        <a href="<?= $h($linkWith(['ip' => $r['ip_address'], 'p' => null])) ?>"><?= $h($r['ip_address']) ?></a>
                        <?php if ((int)$r['is_bot'] === 1): ?><span class="cbtr-tag is-bot">bot</span><?php endif; ?>
                        <?php if ((int)$r['trade_user_id'] > 0): ?><span class="cbtr-tag is-trade">trade</span><?php endif; ?>
                    </td>
                    <td class="cbtr-num"><?= number_format((int)$r['views']) ?></td>
                    <td class="cbtr-num"><?= number_format((int)$r['visits']) ?></td>
                    <td class="cbtr-mono cbtr-clip"><?= $h($r['last_path']) ?></td>
                    <td class="cbtr-nowrap"><?= $h(ucfirst((string)$r['device'])) ?> · <?= $h($r['browser']) ?></td>
                    <td class="cbtr-nowrap"><?= $h(date('j M, H:i', strtotime((string)$r['first_seen']))) ?></td>
                    <td class="cbtr-nowrap"><?= $h(date('j M, H:i', strtotime((string)$r['last_seen']))) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>

        <?php $pages = max(1, (int)ceil($ipTotal / $perPage)); if ($pages > 1): ?>
        <nav class="cbtr-pager" aria-label="Pages of visitors">
            <?php if ($page > 1): ?>
                <a class="cbtr-chip" href="<?= $h($linkWith(['p' => $page - 1])) ?>">← Previous</a>
            <?php endif; ?>
            <span class="cbtr-pager-at">Page <?= (int)$page ?> of <?= (int)$pages ?></span>
            <?php if ($page < $pages): ?>
                <a class="cbtr-chip" href="<?= $h($linkWith(['p' => $page + 1])) ?>">Next →</a>
            <?php endif; ?>
        </nav>
        <?php endif; ?>
        <?php endif; ?>
    </section>

    <p class="cbtr-foot">
        <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
        Recorded on this server only — no analytics service, no advertising tag and
        nothing stored in a visitor's browser. Rows older than
        <?= (int)CB_TRAFFIC_RETENTION_DAYS ?> days are deleted automatically<?php
        if ($oldest): ?>; the oldest kept is from <?= $h(date('j M Y', strtotime((string)$oldest))) ?><?php
        endif; ?>.
    </p>

    <?php endif; ?>
</div>
</div>

</body>
</html>
