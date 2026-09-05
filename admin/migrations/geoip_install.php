<?php
// ============================================================
//  Creamy Bite – Install / refresh the location database
//  URL: /admin/migrations/geoip_install.php
//
//  The Traffic & Visitors page can say which country and town a visitor's IP
//  belongs to. That needs a lookup table, which is a file rather than
//  something the code can work out for itself. This page fetches it.
//
//  DB-IP Lite: free, no account, republished monthly, CC BY 4.0. Two editions:
//
//    city     country + town   ~120MB on disk
//    country  country only     ~8MB on disk
//
//  MaxMind's GeoLite2 files are the same format and drop in unchanged if the
//  shop ever gets a licence key — put one at includes/geoip/city.mmdb.
//
//  Nothing here is sent anywhere. The download is this SERVER fetching a file
//  once; afterwards every lookup is local, which is the whole reason for doing
//  it this way rather than calling a geolocation API per visitor.
//
//  A download that fails is not a dead end: the file can be fetched by hand
//  from db-ip.com and uploaded to includes/geoip/, and the instructions for
//  that are printed below whether or not the download worked.
// ============================================================
require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_permissions.php';
adminRequire('traffic');

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/geoip.php';

@set_time_limit(600);

$dir = dirname(__DIR__, 2) . '/includes/geoip';
$log = [];
$err = '';

/** Note one step for the report at the bottom. */
$say = function (string $msg, bool $ok = true) use (&$log) {
    $log[] = ['msg' => $msg, 'ok' => $ok];
};

$action  = $_POST['action'] ?? '';
$edition = ($_POST['edition'] ?? 'city') === 'country' ? 'country' : 'city';

// ── Download ────────────────────────────────────────────────
if ($action === 'download' && csrfValid()) {

    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        $err = 'Could not create includes/geoip — check the folder permissions.';
    } elseif (!is_writable($dir)) {
        // Checked up front and reported as itself. Discovering it mid-download
        // and reporting it as "this server may not allow outgoing connections"
        // sends whoever reads it to their host's firewall settings over a
        // problem that is one chmod away.
        $err = 'The folder includes/geoip is not writable by the web server, so nothing can be saved into it. '
             . 'Give it write permission (755 owned by the web user, or 775) and try again — or use the manual route below.';
    } else {
        // The current month's file is published on the 1st; early in a month
        // the previous one is still the newest that exists, so both are tried
        // rather than failing on a URL that is simply not there yet.
        $tried = [];
        $gzPath = $dir . '/.download.mmdb.gz';
        $ok = false;

        foreach ([date('Y-m'), date('Y-m', strtotime('-1 month'))] as $month) {
            $url = "https://download.db-ip.com/free/dbip-{$edition}-lite-{$month}.mmdb.gz";
            $tried[] = $month;

            $fh = @fopen($gzPath, 'wb');
            if (!$fh) {
                $err = 'Could not open a file to download into, even though the folder looked writable.';
                break;
            }

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_FILE           => $fh,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT        => 540,
                CURLOPT_CONNECTTIMEOUT => 20,
                CURLOPT_FAILONERROR    => true,
                CURLOPT_USERAGENT      => 'CreamyBite/1.0 (+admin geoip installer)',
            ]);
            $done = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $curlErr = curl_error($ch);
            curl_close($ch);
            fclose($fh);

            if ($done && $code === 200 && filesize($gzPath) > 100000) {
                $say("Downloaded the {$edition} edition for {$month} (" . round(filesize($gzPath) / 1048576, 1) . 'MB).');
                $ok = true;
                break;
            }
            @unlink($gzPath);
            $err = $curlErr !== '' ? $curlErr : ('HTTP ' . $code);
        }

        if (!$ok && $err !== '' && !str_contains($err, 'Could not open a file')) {
            $err = 'Could not download the database (tried ' . implode(' and ', $tried) . '). Last error: ' . $err
                 . ' — this server may not allow outgoing connections. Use the manual route below.';
        }
        if ($ok) {
            // Unpack to a temporary name. The live file is only replaced once
            // the new one has been proved readable, so a truncated download
            // cannot take the working database away with it.
            $tmp = $dir . '/.new.mmdb';
            $in  = @gzopen($gzPath, 'rb');
            $out = @fopen($tmp, 'wb');
            if (!$in || !$out) {
                $err = 'Downloaded, but could not unpack it.';
            } else {
                while (!gzeof($in)) { fwrite($out, gzread($in, 262144)); }
                gzclose($in); fclose($out);
                @unlink($gzPath);
                $say('Unpacked to ' . round(filesize($tmp) / 1048576, 1) . 'MB.');

                // Prove it before trusting it.
                $probe = cbGeoProbe($tmp);
                if ($probe === null) {
                    @unlink($tmp);
                    $err = 'The downloaded file is not a readable database. Nothing was changed.';
                } else {
                    $say('Verified: ' . $probe['type'] . ', built ' . date('j M Y', $probe['built'])
                        . '. Test lookup 81.2.69.142 → ' . ($probe['sample'] ?: 'no answer') . '.');

                    $final = $dir . '/' . $edition . '.mmdb';
                    if (is_file($final)) { @unlink($final); }
                    if (@rename($tmp, $final)) {
                        $say('Installed as includes/geoip/' . $edition . '.mmdb.');
                        // The city edition supersedes country-only; leaving both
                        // wastes ~8MB and the reader would prefer city anyway.
                        if ($edition === 'city' && is_file($dir . '/country.mmdb')) {
                            @unlink($dir . '/country.mmdb');
                            $say('Removed the older country-only file, no longer needed.');
                        }
                    } else {
                        @unlink($tmp);
                        $err = 'Verified but could not move it into place — check folder permissions.';
                    }
                }
            }
        }
    }
}

// ── Backfill ────────────────────────────────────────────────
//
// Rows recorded before the database existed have empty location columns. This
// fills them in from the IP already stored, in batches, so the Audience panel
// is not blank for everything that happened before today.
if ($action === 'backfill' && csrfValid()) {
    if (!cbGeoReady()) {
        $err = 'No database is installed yet, so there is nothing to fill these in from.';
    } else {
        try {
            $todo = (int)$pdo->query(
                "SELECT COUNT(*) FROM page_views WHERE country_code = '' AND ip_address <> ''"
            )->fetchColumn();

            $rows = $pdo->query(
                "SELECT id, ip_address FROM page_views
                  WHERE country_code = '' AND ip_address <> ''
                  ORDER BY id DESC
                  LIMIT 20000"
            )->fetchAll();

            $upd = $pdo->prepare(
                "UPDATE page_views SET country_code = ?, country = ?, city = ? WHERE id = ?"
            );
            $filled = 0; $unknown = 0;
            $pdo->beginTransaction();
            foreach ($rows as $r) {
                $geo = cbGeoLookup((string)$r['ip_address']);
                if ($geo === null || $geo['country_code'] === '') { $unknown++; continue; }
                $upd->execute([
                    mb_substr($geo['country_code'], 0, 2),
                    mb_substr($geo['country'], 0, 80),
                    mb_substr($geo['city'], 0, 90),
                    $r['id'],
                ]);
                $filled++;
            }
            $pdo->commit();

            $say("Filled in $filled of " . count($rows) . " rows examined."
                . ($unknown ? " $unknown had addresses the database does not list (private or unallocated)." : ''));
            $left = $todo - count($rows);
            if ($left > 0) {
                $say("About $left older rows still to do — run this again to continue.", false);
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $err = 'Backfill failed: ' . $e->getMessage();
        }
    }
}

// ── Current state ───────────────────────────────────────────
$meta    = cbGeoReady() ? cbGeoMeta() : null;
$dbFile  = cbGeoDbPath();
$sample  = null;
if ($meta) { $sample = cbGeoLookup('81.2.69.142'); }

$stats = ['total' => 0, 'located' => 0];
try {
    $r = $pdo->query("SELECT COUNT(*) total, SUM(country_code <> '') located FROM page_views")->fetch();
    if ($r) { $stats = ['total' => (int)$r['total'], 'located' => (int)$r['located']]; }
} catch (Throwable $e) { /* table not migrated yet; the zeros are the answer */ }

$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Location Database</title>
    <?php require __DIR__ . '/../../includes/favicon.php'; ?>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/setup.css">
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body class="admin-wrapper su-page-warm">
<div class="su-wrap">
    <div class="glass-panel su-card">
        <h1 class="su-h1">🌍 Location Database</h1>
        <p class="su-lead">
            Lets the Traffic page say which country and town a visitor is in.
            The file lives on this server; no visitor data is sent anywhere.
        </p>

        <p class="su-env <?= IS_LOCAL ? 'su-env-local' : 'su-env-live' ?>">
            <?= IS_LOCAL ? '💻 LOCAL' : '🌍 LIVE' ?> &mdash; <?= $h(DB_NAME) ?>
        </p>

        <?php if ($err !== ''): ?>
        <div class="su-failbox">
            <h2 class="su-failbox-h">That did not work</h2>
            <ul class="su-failbox-list"><li><?= $h($err) ?></li></ul>
        </div>
        <?php endif; ?>

        <?php if ($log): ?>
        <div class="su-log">
            <?php foreach ($log as $l): ?>
            <div class="su-log-line"><span class="su-mark <?= $l['ok'] ? 'su-mark-ok' : 'su-mark-warn' ?>"><?= $l['ok'] ? '✓' : '!' ?></span> <?= $h($l['msg']) ?></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <h2 class="su-h2">Installed now</h2>
        <?php if ($meta): ?>
        <p class="su-result su-ok">
            ✅ <strong><?= $h($meta['type']) ?></strong>,
            built <?= $h(date('j M Y', $meta['built'])) ?>,
            <?= $h(round(filesize($dbFile) / 1048576, 1)) ?>MB.<br>
            Test lookup — 81.2.69.142 →
            <?= $h(trim(($sample['city'] ?? '') . ' ' . ($sample['country'] ?? ''))) ?: 'no answer' ?>.
        </p>
        <?php else: ?>
        <p class="su-result">
            ⚠️ No database installed. The Traffic page works, it just cannot show
            countries or towns until one is here.
        </p>
        <?php endif; ?>

        <?php if ($stats['total'] > 0): ?>
        <p class="su-lead">
            <?= number_format($stats['located']) ?> of <?= number_format($stats['total']) ?>
            recorded page views have a location
            (<?= $stats['total'] ? round($stats['located'] / $stats['total'] * 100) : 0 ?>%).
        </p>
        <?php endif; ?>

        <h2 class="su-h2">Download it</h2>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="download">
            <p class="su-lead">
                <label><input type="radio" name="edition" value="city" checked> <strong>City</strong> — countries and towns, about 120MB</label><br>
                <label><input type="radio" name="edition" value="country"> <strong>Country only</strong> — about 8MB, for a server tight on space</label>
            </p>
            <button type="submit" class="su-btn su-btn-next">Download &amp; install</button>
        </form>

        <h2 class="su-h2">Fill in past visits</h2>
        <p class="su-lead">
            Views recorded before the database was installed have no location.
            This works them out from the addresses already stored — 20,000 at a
            time, so run it more than once if it says there are more to do.
        </p>
        <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="backfill">
            <button type="submit" class="su-btn" <?= $meta ? '' : 'disabled' ?>>Fill in past visits</button>
        </form>

        <h2 class="su-h2">If the download is blocked</h2>
        <p class="su-lead">
            Some hosts do not allow outgoing connections. In that case:
        </p>
        <ol class="su-lead">
            <li>Go to <a href="https://db-ip.com/db/lite.php" target="_blank" rel="noopener noreferrer">db-ip.com/db/lite.php</a></li>
            <li>Download <strong>IP to City Lite</strong> in <strong>MMDB</strong> format</li>
            <li>Unzip it — you want the <code>.mmdb</code> file inside</li>
            <li>Upload it to <code>includes/geoip/</code> and rename it <code>city.mmdb</code></li>
        </ol>
        <p class="su-lead">
            It is republished monthly. Refreshing it a few times a year is
            plenty — addresses move slowly.
        </p>

        <p class="su-loghint su-loghint-note">
            IP address data from <a href="https://db-ip.com" target="_blank" rel="noopener noreferrer">DB-IP</a>,
            used under <a href="https://creativecommons.org/licenses/by/4.0/" target="_blank" rel="noopener noreferrer">CC BY 4.0</a>.
            Country is dependable; town is an estimate — see the note on the Traffic page.
        </p>

        <p><a class="su-btn su-btn-back" href="../traffic.php">← Back to Traffic &amp; Visitors</a></p>
    </div>
</div>
</body>
</html>
