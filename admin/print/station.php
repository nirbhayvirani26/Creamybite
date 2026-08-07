<?php
// ============================================================
//  Creamy Bite – Print station
//  URL: /admin/print/station.php
//
//  This is the page that is left open on the counter PC all day. It asks the
//  website every 8 seconds whether any new orders have come in, and prints a
//  receipt for each one on the Oxhoo TP85 without anybody touching anything.
//
//  ── THE ONE THING THAT MUST NOT GO WRONG ────────────────────────────
//
//  There are already unprinted orders in the database — every order ever
//  placed has printed_at NULL until this page prints it. If the station ever
//  starts from "order 0", queue.php hands back the whole archive and the till
//  roll runs off a dozen historical test receipts in front of a customer.
//
//  So the very first thing that happens is a WATERMARK: the highest order id
//  that exists right now, read below in PHP so it is correct before a single
//  line of JavaScript runs. Only orders NEWER than that are ever printed. It
//  is saved in localStorage, so a refresh — or Chrome restarting overnight —
//  carries on where it left off instead of replaying the day.
//
//  The watermark only ever moves forward past an order once the database has
//  CONFIRMED that order as printed. An order that failed to print stays
//  behind the watermark and comes back on the next poll. That is deliberate:
//  a duplicate receipt is a wasted bit of paper, a missed one is a lost order.
//
//  ── HOW IT PRINTS ───────────────────────────────────────────────────
//
//  receipt.php?id=N&auto=1 is loaded into an off-screen iframe and printed
//  from here, then mark_printed.php is posted. Print FIRST, mark after.
//  receipt.php also prints itself 400ms after it loads as a backstop, and
//  stands that down if this page got there first — see the note on the
//  onload handler, which is why nothing may be awaited before the print call.
//
//  ── WHAT THIS FILE OWNS ─────────────────────────────────────────────
//
//  This page and assets/css/print-station.css. It reads the four endpoints in
//  this folder and changes nothing else. It does not recompute a single
//  figure: every number on a receipt comes from includes/receipt.php via
//  receipt.php, so the receipt, the reprint and the daily summary cannot
//  disagree.
// ============================================================

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_permissions.php';
adminRequire('orders');

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';

/**
 * The starting watermark.
 *
 * Read here rather than left to the first poll, so the page can never be in a
 * state where it is polling without knowing where to start. queue.php refuses
 * a request that does not say which order to start after, and every one of its
 * replies — including the refusals — carries server_max_id, so if this number
 * is ever wrong the JavaScript re-sets itself from the server rather than
 * falling back to 0.
 *
 * A database that cannot be read leaves this at 0. queue.php then refuses the
 * poll and hands back the real figure, which the page adopts. It fails towards
 * printing nothing, never towards printing everything.
 */
// Orders already waiting to print when this page loads. A fresh browser (or one
// whose site data was cleared) would otherwise jump the watermark straight to
// the newest order and skip these silently — the owner would never learn that
// three receipts were dropped. Counted here so the page can say so out loud.
$bootPendingCount = 0;
$bootPendingFrom  = 0;

$bootMaxId = 0;
try {
    // The SAME resolution queue.php uses. Two different answers to "what is the
    // newest order?" is precisely how a watermark ends up skipping or replaying
    // orders, so there is one definition of it and both callers share it.
    $bootMaxId = cbPrintServerMaxId($pdo);

    $row = $pdo->query(
        "SELECT COUNT(*) AS c, COALESCE(MIN(id), 0) AS lo FROM orders WHERE printed_at IS NULL"
    )->fetch(PDO::FETCH_ASSOC);
    $bootPendingCount = (int)($row['c']  ?? 0);
    $bootPendingFrom  = (int)($row['lo'] ?? 0);
} catch (Throwable $e) {
    error_log('Print station: could not read the highest order id — ' . $e->getMessage());
}

$shopName = defined('SHOP_NAME') ? SHOP_NAME : 'The shop';

// The address to put in the Chrome shortcut in the setup notes at the bottom.
// Built from the request so the instructions are right on localhost and on the
// live site without anybody editing this file.
$scheme     = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
$host       = (string)($_SERVER['HTTP_HOST'] ?? 'orders.creamybite.com');
$path       = strtok((string)($_SERVER['REQUEST_URI'] ?? '/admin/print/station.php'), '?');
$stationUrl = $scheme . '://' . $host . ($path !== false ? $path : '/admin/print/station.php');

$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

// The whole Windows shortcut line, ready to paste. Assembled once so the
// on-screen copy and the copy button cannot drift apart.
$shortcutTarget = '"C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe" --kiosk-printing ' . $stationUrl;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Print station &ndash; <?= $h($shopName) ?></title>
<?php require __DIR__ . '/../../includes/favicon.php'; ?>
<link rel="stylesheet" href="../../assets/css/print-station.css">
<?php // Wraps window.fetch and puts the CSRF token on every same-origin call,
      // which is what queue.php and mark_printed.php check. Do not hand-roll it. ?>
<?php include __DIR__ . '/../_csrf_js.php'; ?>
</head>
<body class="cbps-body">

<div class="cbps-wrap">

    <header class="cbps-header">
        <div class="cbps-brand">
            <span class="cbps-brand-name"><?= $h($shopName) ?></span>
            <span class="cbps-brand-page">Receipt printer</span>
        </div>
        <nav class="cbps-header-links">
            <a class="cbps-link" href="summary.php">Today's totals</a>
            <a class="cbps-link" href="../index.php?tab=orders">Back to orders</a>
        </nav>
    </header>

    <!-- The one thing that must be readable from across the counter. -->
    <section class="cbps-status" id="cbpsStatus">
        <div class="cbps-status-top">
            <span class="cbps-lamp"></span>
            <h1 class="cbps-headline" id="cbpsHeadline">Starting up</h1>
        </div>
        <p class="cbps-subline" id="cbpsSubline">Getting ready to watch for new orders.</p>
        <div class="cbps-fix cbps-hidden" id="cbpsFix">
            <a class="cbps-btn cbps-btn-small cbps-hidden" id="cbpsFixSignIn" href="../login.php">Sign in again</a>
            <button type="button" class="cbps-btn cbps-btn-small cbps-btn-quiet" id="cbpsFixReload">Reload this page</button>
        </div>
    </section>

    <div class="cbps-tiles">
        <div class="cbps-tile">
            <div class="cbps-tile-label">Watching for orders after</div>
            <div class="cbps-tile-value" id="cbpsWatermark">&hellip;</div>
            <div class="cbps-tile-note" id="cbpsWatermarkNote">Anything older than this is left alone.</div>
        </div>
        <div class="cbps-tile">
            <div class="cbps-tile-label">Last checked</div>
            <div class="cbps-tile-value" id="cbpsLastCheck">&mdash;</div>
            <div class="cbps-tile-note" id="cbpsLastCheckNote">Not checked yet.</div>
        </div>
        <div class="cbps-tile">
            <div class="cbps-tile-label">Printed today</div>
            <div class="cbps-tile-value" id="cbpsCount">0</div>
            <div class="cbps-tile-note" id="cbpsCountNote">receipts</div>
        </div>
    </div>

    <!-- Browsers refuse to let a page make a noise until somebody has clicked
         on it, so this asks once and only says the chime is on when the
         browser has actually started the sound system. -->
    <div class="cbps-sound" id="cbpsSound">
        <p class="cbps-sound-text" id="cbpsSoundText">
            The chime is off. Your browser will not let this page make a sound
            until you click the button once.
        </p>
        <button type="button" class="cbps-btn cbps-btn-small" id="cbpsSoundBtn">Turn the chime on</button>
    </div>

    <div class="cbps-actions">
        <button type="button" class="cbps-btn" id="cbpsTestBtn">Test print</button>
        <button type="button" class="cbps-btn cbps-btn-quiet" id="cbpsCheckBtn">Check for orders now</button>
    </div>

    <section class="cbps-panel">
        <h2 class="cbps-panel-title">What has happened</h2>
        <ul class="cbps-log" id="cbpsLog"></ul>
    </section>

    <details class="cbps-setup">
        <summary class="cbps-setup-summary">How to set this up on the shop computer</summary>
        <div class="cbps-setup-body">

            <p>
                Do these three things once, on the computer at the counter. After
                that the receipts print on their own and nobody has to click
                anything.
            </p>

            <h3>Step 1 &mdash; make the receipt printer the normal one</h3>
            <p>
                Windows sends printing to whichever printer it has been told is
                the usual one. If that is the office paper printer, the receipts
                come out on A4.
            </p>
            <ol class="cbps-steps">
                <li>Click the Windows <span class="cbps-kbd">Start</span> button and type <strong>Printers</strong>.</li>
                <li>Open <strong>Printers &amp; scanners</strong>.</li>
                <li>Turn <strong>off</strong> the switch called &ldquo;Let Windows manage my default printer&rdquo;. If Windows is left to manage it, it quietly changes the printer to whatever was used last and the receipts stop appearing.</li>
                <li>Click the <strong>Oxhoo TP85</strong> in the list, then click <strong>Set as default</strong>.</li>
                <li>Load the roll of 80mm paper and print a test page from that same screen. If nothing comes out, the problem is the printer or its cable &mdash; sort that out before going any further, because nothing on this page can fix it.</li>
            </ol>

            <h3>Step 2 &mdash; make Chrome print without asking</h3>
            <p>
                Normally Chrome pops up a &ldquo;Print&rdquo; window and waits for
                somebody to click Print. That is no good when the shop is busy, so
                Chrome is started in a special way that sends the receipt straight
                to the printer. It only does this if it is opened using the
                shortcut below.
            </p>
            <ol class="cbps-steps">
                <li>Right-click on an empty part of the desktop and choose <strong>New</strong>, then <strong>Shortcut</strong>.</li>
                <li>
                    Copy the line below and paste it into the box that asks for the location.
                    <div class="cbps-copybox" id="cbpsShortcut"><?= $h($shortcutTarget) ?></div>
                    <div class="cbps-copy-row">
                        <button type="button" class="cbps-btn cbps-btn-small cbps-btn-quiet" id="cbpsCopyBtn">Copy that line</button>
                        <span class="cbps-copy-said cbps-hidden" id="cbpsCopySaid">Copied.</span>
                    </div>
                </li>
                <li>Click <strong>Next</strong>, name it <strong>Receipt printer</strong>, and click <strong>Finish</strong>.</li>
                <li>
                    <strong>Close every Chrome window first</strong>, then open the new
                    shortcut. This matters: if Chrome is already running, opening the
                    shortcut just adds a tab to the Chrome that is already there and the
                    print window comes back. Look at the bottom-right of the screen and
                    close Chrome properly if it is still sitting there.
                </li>
            </ol>
            <p class="cbps-note">
                If a print window still appears each time, Chrome was already open
                when the shortcut was used. Close all Chrome windows and open the
                shortcut again.
            </p>
            <p>
                If Chrome is installed somewhere else on this computer, the first
                part of that line in quotes needs to match where it really is. The
                rest of the line stays exactly as it is.
            </p>

            <h3>Step 3 &mdash; leave this page open</h3>
            <ol class="cbps-steps">
                <li>Use the shortcut every morning when the shop opens.</li>
                <li>Sign in if it asks, and leave this page on the screen. It can sit behind other windows; it keeps working.</li>
                <li>Click <strong>Turn the chime on</strong> at the top once, so you hear a sound when an order arrives.</li>
                <li>Check the big line at the top says <strong>Watching for new orders</strong> and is green.</li>
                <li>Set the computer so it never goes to sleep. A sleeping computer prints nothing, and the orders sit and wait until it wakes up.</li>
            </ol>
            <p class="cbps-note">
                Nothing is ever lost while this page is closed. An order that has
                not been printed stays waiting in the system, and you can print it
                any time from the Orders page using the receipt button on the order.
            </p>

        </div>
    </details>

    <section class="cbps-panel">
        <h2 class="cbps-panel-title">If it stops printing</h2>
        <ol class="cbps-steps">
            <li>Look at the big line at the top. If it is red it says what is wrong in plain words.</li>
            <li>Check the printer is switched on and has paper.</li>
            <li>Press <strong>Test print</strong> above. If that prints, the printer is fine.</li>
            <li>Press <strong>Reload this page</strong> and give it ten seconds.</li>
            <li>Any order that did not print can still be printed by hand from the Orders page.</li>
        </ol>
        <p>
            The button below makes this page forget everything before the newest
            order and start watching from there. Only use it if the same receipt
            keeps printing over and over. Anything waiting to print will be
            skipped, so print those from the Orders page afterwards.
        </p>
        <button type="button" class="cbps-btn cbps-btn-warn cbps-btn-small" id="cbpsResetBtn">Start again from the newest order</button>
    </section>

</div>

<!-- Receipts are loaded and printed in here. Parked off-screen rather than
     hidden, because a display:none frame prints as a blank page. -->
<div class="cbps-frames" id="cbpsFrames"></div>

<script>
(function () {
    'use strict';

    var CFG = {
        pollMs:        8000,    // the contract's polling interval
        gapMs:         1400,    // breathing room between two receipts
        loadTimeoutMs: 25000,   // a receipt that never loads is retried, not lost
        bootMaxId:     <?= (int)$bootMaxId ?>,
        pendingCount:  <?= (int)$bootPendingCount ?>,
        pendingFrom:   <?= (int)$bootPendingFrom ?>,
        storeKey:      'cb_print_station_v1',
        logMax:        40,
        resetLabel:    'Start again from the newest order'
    };

    // ── State ───────────────────────────────────────────────────────
    var watermark   = 0;      // only orders with a higher id are ever printed
    var serverMaxId = CFG.bootMaxId;
    var handled     = {};     // order id -> true once a receipt has loaded for it
    var printedToday = 0;
    var lastCheckAt = null;
    var lastOrderId = 0;      // newest order this page has printed, for Test print
    var failCount   = 0;      // polls in a row that did not answer
    var printFails  = 0;      // receipts in a row that would not even open
    var stopped     = false;  // signed out or session expired: polling is pointless
    var busy        = false;  // a poll or a print run is in progress
    var mode        = 'boot'; // boot | ok | busy | bad
    var busyLabel   = '';
    var badTitle    = '';
    var badText     = '';
    var audioCtx    = null;
    var resetArmed  = false;
    var ui          = {};

    // ── Small helpers ───────────────────────────────────────────────

    function pad2(n) { return (n < 10 ? '0' : '') + n; }

    function clockNow() {
        var d = new Date();
        return pad2(d.getHours()) + ':' + pad2(d.getMinutes()) + ':' + pad2(d.getSeconds());
    }

    function todayKey() {
        var d = new Date();
        return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate());
    }

    function sleep(ms) {
        return new Promise(function (done) { window.setTimeout(done, ms); });
    }

    /** A whole number, or the fallback. Never NaN, never a string that looks numeric. */
    function whole(value, fallback) {
        var n = typeof value === 'number' ? value : parseInt(value, 10);
        if (typeof n !== 'number' || !isFinite(n)) { return fallback; }
        return Math.floor(n);
    }

    function shortErr(err) {
        if (!err) { return 'unknown problem'; }
        var msg = (typeof err === 'string') ? err : (err.message || String(err));
        return msg.replace(/\s+/g, ' ').slice(0, 160);
    }

    function orderLabel(order) {
        if (order && typeof order.order_code === 'string' && order.order_code !== '') {
            return order.order_code;
        }
        return 'order ' + (order ? order.id : '?');
    }

    // ── Remembering across reloads ──────────────────────────────────
    //
    // localStorage can throw outright (a locked-down profile, a full disk).
    // Everything here swallows that: the station still prints, it just forgets
    // its place if the page is reloaded, and the watermark falls back to the
    // number PHP put in the page — which is the safe direction.

    function readStore() {
        try {
            var raw = window.localStorage.getItem(CFG.storeKey);
            if (!raw) { return {}; }
            var obj = JSON.parse(raw);
            return (obj && typeof obj === 'object') ? obj : {};
        } catch (err) {
            return {};
        }
    }

    function writeStore(patch) {
        try {
            var cur = readStore();
            for (var k in patch) {
                if (Object.prototype.hasOwnProperty.call(patch, k)) { cur[k] = patch[k]; }
            }
            window.localStorage.setItem(CFG.storeKey, JSON.stringify(cur));
        } catch (err) {
            /* nothing to do — see the note above */
        }
    }

    // ── The watermark ───────────────────────────────────────────────

    function initWatermark() {
        var saved = readStore().watermark;
        var usable = (typeof saved === 'number' && isFinite(saved) && saved >= 0 && Math.floor(saved) === saved);

        if (!usable) {
            // First time on this computer. Start at the newest order that
            // already exists, so the orders already in the system are left
            // alone and only what comes in from now on is printed.
            watermark = CFG.bootMaxId;
            writeStore({ watermark: watermark });
            log('First time on this computer. Orders already in the system will not be printed \u2014 only new ones from now on.', 'good');

            // Say it plainly if that decision just stepped over real work. This
            // is the mid-day case: the printer jammed, receipts are owed, and
            // someone cleared the browser or opened a fresh profile. Silently
            // skipping them is the one outcome the shop must never get.
            if (CFG.pendingCount > 0) {
                log(CFG.pendingCount === 1
                        ? 'But 1 order has never been printed (from order ' + CFG.pendingFrom + '). '
                          + 'It will NOT print on its own. Use the reprint button on that order in Orders, '
                          + 'or click "Print the ' + CFG.pendingCount + ' waiting" below.'
                        : 'But ' + CFG.pendingCount + ' orders have never been printed (from order '
                          + CFG.pendingFrom + ' onwards). They will NOT print on their own. '
                          + 'Click "Print the ' + CFG.pendingCount + ' waiting" below, or use the reprint '
                          + 'buttons in Orders.',
                    'bad');
                showPendingOffer();
            }
            return;
        }

        if (saved > CFG.bootMaxId) {
            // Fewer orders exist than this computer remembers, so the order
            // list has been cleared out. Left alone, the station would wait
            // for an order number that will never come round again and print
            // nothing forever. Stepping back to the newest order that exists
            // cannot print any history, because there is none above it.
            watermark = CFG.bootMaxId;
            writeStore({ watermark: watermark });
            log('The order list is shorter than last time, so this has been set to watch from order ' + CFG.bootMaxId + '.', '');
            return;
        }

        watermark = saved;
    }

    // Deliberately a button rather than automatic. Printing a backlog without
    // being asked is how a shop ends up with a metre of receipts on the floor;
    // never printing it is how an order gets missed. So: ask.
    function showPendingOffer() {
        if (!ui.log || CFG.pendingCount < 1) { return; }
        var wrap = document.createElement('div');
        wrap.className = 'cbps-pending-offer';
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'cbps-btn cbps-btn-warn';
        btn.textContent = 'Print the ' + CFG.pendingCount
            + (CFG.pendingCount === 1 ? ' waiting order' : ' waiting orders');
        btn.addEventListener('click', function () {
            btn.disabled = true;
            btn.textContent = 'Printing the waiting orders\u2026';
            var from = Math.max(0, CFG.pendingFrom - 1);
            watermark = from;
            writeStore({ watermark: watermark });
            log('Now printing the ' + CFG.pendingCount + ' order(s) that were waiting.', 'good');
            render();
            tick();
        });
        wrap.appendChild(btn);
        ui.log.parentNode.insertBefore(wrap, ui.log);
    }

    function setWatermark(next) {
        var n = whole(next, null);
        if (n === null || n < 0 || n === watermark) { return; }
        watermark = n;
        writeStore({ watermark: watermark });
        render();
    }

    // ── Counting today's receipts ───────────────────────────────────

    function initCount() {
        var s = readStore();
        if (s.countDate === todayKey() && typeof s.countToday === 'number' && s.countToday >= 0) {
            printedToday = Math.floor(s.countToday);
        } else {
            printedToday = 0;
            writeStore({ countDate: todayKey(), countToday: 0 });
        }
    }

    function bumpCount() {
        if (readStore().countDate !== todayKey()) { printedToday = 0; }
        printedToday += 1;
        writeStore({ countDate: todayKey(), countToday: printedToday });
        render();
    }

    // ── What has happened ───────────────────────────────────────────

    function log(text, kind) {
        if (!ui.log || !document.createElement) { return; }
        var row = document.createElement('li');
        row.className = 'cbps-log-row' + (kind === 'good' ? ' cbps-log-good' : (kind === 'bad' ? ' cbps-log-bad' : ''));

        var when = document.createElement('span');
        when.className = 'cbps-log-time';
        when.textContent = clockNow();

        var what = document.createElement('span');
        what.className = 'cbps-log-text';
        what.textContent = text;

        row.appendChild(when);
        row.appendChild(what);
        ui.log.insertBefore(row, ui.log.firstChild);

        while (ui.log.childNodes.length > CFG.logMax) {
            ui.log.removeChild(ui.log.lastChild);
        }
    }

    // ── The big status line ─────────────────────────────────────────

    function goBad(title, text) {
        mode = 'bad';
        badTitle = title;
        badText = text;
        render();
    }

    function agoWords(then) {
        var secs = Math.max(0, Math.round((Date.now() - then.getTime()) / 1000));
        if (secs < 5)  { return 'just now'; }
        if (secs < 60) { return secs + ' seconds ago'; }
        var mins = Math.round(secs / 60);
        if (mins === 1) { return 'a minute ago'; }
        if (mins < 60)  { return mins + ' minutes ago'; }
        return 'over an hour ago';
    }

    function render() {
        if (ui.status) {
            ui.status.className = 'cbps-status'
                + (mode === 'ok'   ? ' cbps-ok'   : '')
                + (mode === 'busy' ? ' cbps-busy' : '')
                + (mode === 'bad'  ? ' cbps-bad'  : '');
        }

        var title = 'Starting up';
        var text  = 'Getting ready to watch for new orders.';

        if (mode === 'ok') {
            title = 'Watching for new orders';
            text  = 'Receipts print on their own. Leave this page open and the printer switched on.';
        } else if (mode === 'busy') {
            title = 'Printing a receipt';
            text  = busyLabel ? ('Sending ' + busyLabel + ' to the printer now.') : 'Sending an order to the printer now.';
        } else if (mode === 'bad') {
            title = badTitle;
            text  = badText;
        }

        if (ui.headline) { ui.headline.textContent = title; }
        if (ui.subline)  { ui.subline.textContent  = text;  }

        if (ui.watermark) {
            ui.watermark.textContent = watermark > 0 ? ('#' + watermark) : 'the very first order';
        }
        if (ui.watermarkNote) {
            ui.watermarkNote.textContent = watermark > 0
                ? ('Orders numbered above ' + watermark + ' print automatically. Older ones are left alone.')
                : 'There are no orders yet. Everything that comes in will print.';
        }

        if (ui.lastCheck) {
            ui.lastCheck.textContent = lastCheckAt ? clockOf(lastCheckAt) : '\u2014';
        }
        if (ui.lastCheckNote) {
            ui.lastCheckNote.textContent = lastCheckAt
                ? agoWords(lastCheckAt)
                : 'Not checked yet.';
        }

        if (ui.count)     { ui.count.textContent = String(printedToday); }
        if (ui.countNote) { ui.countNote.textContent = (printedToday === 1 ? 'receipt' : 'receipts') + ' since midnight'; }

        // The way out of a red state, shown only when there is one. Reloading
        // is worth offering whatever the trouble is; signing in only when that
        // is actually what is wrong, so it is never a red herring.
        toggle(ui.fix, mode === 'bad');
        toggle(ui.fixSignIn, mode === 'bad' && badTitle === 'Signed out');
    }

    function clockOf(d) {
        return pad2(d.getHours()) + ':' + pad2(d.getMinutes()) + ':' + pad2(d.getSeconds());
    }

    function toggle(node, on) {
        if (!node || !node.classList) { return; }
        if (on) { node.classList.remove('cbps-hidden'); }
        else    { node.classList.add('cbps-hidden'); }
    }

    // ── The chime ───────────────────────────────────────────────────
    //
    // Made with WebAudio rather than a sound file: nothing to download, no
    // binary in the repository, and it still works if the connection is down.
    // Browsers refuse to start the sound system until somebody has clicked, so
    // "on" is only ever claimed once the browser actually reports it running.

    function audioReady() {
        return !!(audioCtx && audioCtx.state === 'running');
    }

    function startAudio() {
        if (!audioCtx) {
            var Ctor = window.AudioContext || window.webkitAudioContext;
            if (!Ctor) { return false; }
            try {
                audioCtx = new Ctor();
            } catch (err) {
                audioCtx = null;
                return false;
            }
        }
        if (audioCtx.state === 'suspended' && audioCtx.resume) {
            try {
                var p = audioCtx.resume();
                if (p && p.then) { p.then(renderSound, renderSound); }
            } catch (err) {
                /* left suspended; the prompt stays up */
            }
        }
        return audioReady();
    }

    function tone(freq, at, seconds, peak) {
        var osc  = audioCtx.createOscillator();
        var gain = audioCtx.createGain();
        osc.type = 'sine';
        osc.frequency.setValueAtTime(freq, at);
        // Ramped rather than switched on, so it is a chime and not a click.
        gain.gain.setValueAtTime(0.0001, at);
        gain.gain.exponentialRampToValueAtTime(peak, at + 0.02);
        gain.gain.exponentialRampToValueAtTime(0.0001, at + seconds);
        osc.connect(gain);
        gain.connect(audioCtx.destination);
        osc.start(at);
        osc.stop(at + seconds + 0.05);
    }

    function chime() {
        if (!audioReady()) { renderSound(); return; }
        try {
            var t = audioCtx.currentTime + 0.01;
            tone(880.0,  t,        0.20, 0.28);   // A5
            tone(1318.5, t + 0.17, 0.34, 0.24);   // E6 — a rising two-note ding
        } catch (err) {
            /* a chime that will not play must never stop a receipt printing */
        }
    }

    function renderSound() {
        var on = audioReady();
        if (ui.sound && ui.sound.classList) {
            if (on) { ui.sound.classList.add('cbps-sound-on'); }
            else    { ui.sound.classList.remove('cbps-sound-on'); }
        }
        if (ui.soundText) {
            ui.soundText.textContent = on
                ? 'The chime is on. You will hear it when a new order arrives.'
                : 'The chime is off. Your browser will not let this page make a sound until you click the button once.';
        }
        if (ui.soundBtn) {
            ui.soundBtn.textContent = on ? 'Play it again' : 'Turn the chime on';
        }
    }

    // ── Talking to the website ──────────────────────────────────────

    function jsonFetch(url, init) {
        // The body is read as text first: a signed-out session or a PHP error
        // can answer with HTML, and calling .json() on that throws an error
        // about JSON that sends you looking in completely the wrong place.
        return fetch(url, init).then(function (res) {
            return res.text().then(function (body) {
                var data = null;
                try { data = JSON.parse(body); } catch (err) { data = null; }
                return { status: res.status, ok: res.ok, data: data };
            });
        });
    }

    /** The two answers that mean "stop polling and tell the owner what to do". */
    function fatalReply(r) {
        if (r.status === 401) {
            return {
                title: 'Signed out',
                text:  'The shop account has been signed out, so new orders cannot be checked and nothing will print. Sign in again and this page carries on where it left off.'
            };
        }
        if (r.status === 419) {
            return {
                title: 'This page needs reloading',
                text:  'This page has been open so long that the website no longer recognises it, so nothing will print. Press Reload this page below and it starts working again.'
            };
        }
        return null;
    }

    function replyMessage(r) {
        if (r.data && typeof r.data.message === 'string' && r.data.message !== '') {
            return r.data.message;
        }
        if (!r.data) {
            return 'The website sent back something this page could not read (code ' + r.status + ').';
        }
        return 'The website reported a problem (code ' + r.status + ').';
    }

    function stopWith(fatal) {
        stopped = true;
        goBad(fatal.title, fatal.text);
        log(fatal.title + '. ' + fatal.text, 'bad');
    }

    // ── Printing ────────────────────────────────────────────────────

    function dropFrame(frame, blank) {
        try {
            if (blank && frame) { frame.src = 'about:blank'; }
        } catch (err) { /* nothing worth doing */ }
        window.setTimeout(function () {
            if (frame && frame.parentNode) { frame.parentNode.removeChild(frame); }
        }, 8000);
    }

    /**
     * Load a page into an off-screen frame and print it.
     *
     * Resolves once the page has LOADED and print has been asked for. It does
     * not resolve "the paper came out" — no web page can know that. A job
     * handed to Windows for a printer that is switched off waits in the
     * Windows queue and prints when it comes back on, which is the behaviour
     * the shop wants.
     */
    function printUrl(url) {
        return new Promise(function (resolve, reject) {
            if (!ui.frames || !document.createElement) {
                reject(new Error('the page is not ready to print'));
                return;
            }

            var frame = document.createElement('iframe');
            frame.className = 'cbps-frame';
            frame.setAttribute('aria-hidden', 'true');
            frame.setAttribute('title', 'Receipt being printed');

            var done = false;

            var timer = window.setTimeout(function () {
                if (done) { return; }
                done = true;
                dropFrame(frame, true);
                reject(new Error('the receipt took too long to load'));
            }, CFG.loadTimeoutMs);

            frame.onload = function () {
                if (done) { return; }
                done = true;
                window.clearTimeout(timer);

                // PRINT RIGHT HERE, WITH NOTHING AWAITED FIRST.
                //
                // receipt.php?auto=1 prints itself 400ms after it loads as a
                // backstop for a station that never got round to it. Printing
                // from here raises beforeprint inside that page, which makes
                // its own timer stand down. Put anything asynchronous ahead of
                // this line and both fire, and every order costs two receipts.
                var printError = '';
                try {
                    var win = frame.contentWindow;
                    if (!win) { throw new Error('the receipt did not open'); }
                    try { win.focus(); } catch (e2) { /* Chrome does not need it, Firefox does */ }
                    win.print();
                } catch (err) {
                    printError = shortErr(err);
                }

                dropFrame(frame, false);
                resolve({ printError: printError });
            };

            frame.onerror = function () {
                if (done) { return; }
                done = true;
                window.clearTimeout(timer);
                dropFrame(frame, true);
                reject(new Error('the receipt page could not be loaded'));
            };

            frame.src = url;
            ui.frames.appendChild(frame);
        });
    }

    function printReceipt(id, auto) {
        return printUrl('receipt.php?id=' + encodeURIComponent(String(id))
                      + '&auto=' + (auto ? '1' : '0')
                      + '&_=' + Date.now());
    }

    /**
     * Record the print in the database.
     *
     * Called AFTER the receipt has gone to the printer, never before. If this
     * fails the order stays unprinted in the database and comes round again on
     * the next poll, where the list of orders already handled on this page
     * stops it printing a second time and this is simply retried.
     */
    function markPrinted(id) {
        var body = new URLSearchParams();
        body.set('order_id', String(id));

        return jsonFetch('mark_printed.php', {
            method:      'POST',
            cache:       'no-store',
            credentials: 'same-origin',
            headers:     { 'Accept': 'application/json' },
            body:        body
        }).then(function (r) {
            var fatal = fatalReply(r);
            if (fatal) { stopWith(fatal); return false; }

            // The order was deleted between printing and recording it. There is
            // nothing left to wait for, so let the watermark move past it rather
            // than jamming on an order that no longer exists.
            if (r.status === 404) { return true; }

            return !!(r.data && r.data.success === true);
        }, function () {
            return false;
        });
    }

    // ── One pass: ask, then print what came back ────────────────────

    function pollOnce() {
        var url = 'queue.php?after=' + encodeURIComponent(String(watermark)) + '&_=' + Date.now();

        return jsonFetch(url, {
            method:      'GET',
            cache:       'no-store',
            credentials: 'same-origin',
            headers:     { 'Accept': 'application/json' }
        }).then(function (r) {
            lastCheckAt = new Date();

            var fatal = fatalReply(r);
            if (fatal) { stopWith(fatal); return null; }

            var reported = whole(r.data && r.data.server_max_id, null);
            if (reported !== null) { serverMaxId = reported; }

            // The saved starting point was not one queue.php would accept. Every
            // reply carries the newest order number precisely so this can be put
            // right from the server. Guessing 0 here is the bug that prints the
            // whole archive, so it is never done.
            if (r.data && r.data.code === 'bad_after') {
                if (reported !== null) {
                    setWatermark(reported);
                    log('The starting point was not usable, so it has been set to order ' + reported + '.', '');
                    failCount = 0;
                    return [];
                }
                throw new Error('The website could not say which order to start from.');
            }

            if (!r.data || r.data.success !== true) {
                throw new Error(replyMessage(r));
            }

            // Fewer orders than this page remembers — the list has been cleared.
            if (reported !== null && reported < watermark) {
                setWatermark(reported);
                log('The order list is shorter than it was, so the starting point moved back to ' + reported + '.', '');
            }

            failCount = 0;
            return Array.isArray(r.data.orders) ? r.data.orders : [];
        });
    }

    /**
     * Ask the server what the newest order number is, right now.
     *
     * queue.php only ever answers with orders NEWER than the number asked for,
     * so asking after the largest number it will accept comes back with an
     * empty list and the figure wanted. There is no shape of this request that
     * could hand back a backlog, which is why it is safe to use for the
     * "start again" button — and why that button does not simply trust the
     * number from the last poll, which may be minutes old if polling has been
     * failing, and it is exactly then that somebody reaches for it.
     */
    function fetchNewestOrderId() {
        return jsonFetch('queue.php?after=999999999999999999&_=' + Date.now(), {
            method:      'GET',
            cache:       'no-store',
            credentials: 'same-origin',
            headers:     { 'Accept': 'application/json' }
        }).then(function (r) {
            return whole(r.data && r.data.server_max_id, null);
        }, function () {
            return null;
        });
    }

    async function runBatch(orders) {
        var list = [];
        var i;

        for (i = 0; i < orders.length; i++) {
            var id = whole(orders[i] && orders[i].id, null);
            if (id !== null && id > 0) {
                list.push({ id: id, order_code: orders[i].order_code });
            }
        }
        if (!list.length) { return; }

        // Oldest first, so the receipts come off the roll in the order the
        // customers placed them.
        list.sort(function (a, b) { return a.id - b.id; });

        var fresh = list.filter(function (o) { return !handled[o.id]; });
        if (fresh.length) {
            chime();
            log(fresh.length === 1
                ? 'New order ' + orderLabel(fresh[0]) + ' came in.'
                : fresh.length + ' new orders came in.', 'good');
        }

        // The watermark only moves past orders the database has CONFIRMED as
        // printed, and never past one that has not been. The moment anything is
        // unconfirmed, everything after it stays behind the line too, so an
        // order can never be stepped over and lost.
        var advanceTo = watermark;
        var blocked   = false;

        for (i = 0; i < list.length; i++) {
            var order = list[i];

            if (!handled[order.id]) {
                mode = 'busy';
                busyLabel = orderLabel(order);
                render();

                var loaded = false;
                try {
                    var res = await printReceipt(order.id, true);
                    printFails = 0;
                    if (res.printError) {
                        // The browser refused the print. NOTHING came off the
                        // roll, so this must not count as printed: marking it
                        // would set printed_at, drop it out of the queue and
                        // lose the order silently. Leave it unprinted, stop the
                        // batch, and let the next poll try it again.
                        blocked = true;
                        printFails += 1;
                        log('Could not print ' + orderLabel(order) + ' \u2014 ' + res.printError
                            + '. It has NOT been marked as printed and will be tried again.', 'bad');
                        break;
                    }
                    loaded = true;
                    log('Printed ' + orderLabel(order) + '.', 'good');
                } catch (err) {
                    // Nothing was printed, so nothing is marked and the order
                    // stays in the queue for the next poll. If one receipt will
                    // not load the rest almost certainly will not either, so the
                    // rest of the batch is left for next time rather than
                    // hammering a website that is not answering.
                    blocked = true;
                    printFails += 1;
                    log('Could not open the receipt for ' + orderLabel(order) + ' \u2014 ' + shortErr(err)
                        + '. It has not been lost; it will be tried again shortly.', 'bad');
                    break;
                }

                if (loaded) {
                    handled[order.id] = true;
                    if (order.id > lastOrderId) { lastOrderId = order.id; }
                    bumpCount();
                }
            }

            var marked = await markPrinted(order.id);
            if (marked) {
                if (!blocked) { advanceTo = order.id; }
            } else {
                blocked = true;
            }

            if (stopped) { break; }
            if (i < list.length - 1) { await sleep(CFG.gapMs); }
        }

        if (advanceTo > watermark) { setWatermark(advanceTo); }
    }

    // Only ONE tab may print. Two tabs each keep their own in-memory 'handled'
    // set, so without this every order comes off the roll twice — and a shop
    // that opens the station in a second tab has no way of knowing why.
    // Web Locks is held for the lifetime of the page and released automatically
    // when the tab closes or crashes, so the surviving tab takes over on its
    // own with nothing to reset by hand.
    var isPrinter = !supportsLocks();

    function supportsLocks() {
        return !!(window.navigator && window.navigator.locks && window.navigator.locks.request);
    }

    function claimPrinterRole() {
        if (!supportsLocks()) { return; }   // older browser: behave as before

        // Tell the owner straight away if another tab already has the job,
        // otherwise this one just sits there looking broken.
        if (window.navigator.locks.query) {
            window.navigator.locks.query().then(function (state) {
                var taken = (state.held || []).some(function (l) { return l.name === 'cb-print-station'; });
                if (taken && !isPrinter) {
                    log('Another tab of this page is already doing the printing, so this one is only watching. '
                        + 'Close the other tab if you want this one to take over.', '');
                }
            }).catch(function () {});
        }

        window.navigator.locks.request('cb-print-station', function () {
            isPrinter = true;
            render();
            log('This tab is the one printing.', 'good');
            // Held until the tab goes away. The promise never settles on
            // purpose — that is what keeps the lock.
            return new Promise(function () {});
        });
    }

    async function tick() {
        if (busy || stopped) { return; }
        if (!isPrinter) { return; }
        busy = true;
        try {
            var orders = await pollOnce();
            if (orders === null) { return; }          // stopped, message already shown
            if (orders.length) { await runBatch(orders); }

            if (!stopped) {
                busyLabel = '';
                // The website is answering but the receipts themselves will not
                // open. Without this the headline would sit there green while
                // the printer produced nothing, which is the one way this page
                // could lie to the shop.
                if (printFails >= 2) {
                    goBad('Orders are arriving but receipts are not printing',
                          'The orders are coming through, but the receipt will not open so nothing is reaching the printer. '
                        + 'Press Reload this page. Nothing has been lost — every order that has not printed is still '
                        + 'waiting and will print as soon as this is working again.');
                } else {
                    mode = 'ok';
                    render();
                }
            }
        } catch (err) {
            failCount += 1;
            // One missed poll on a shop wifi is noise, not news. Two in a row
            // is worth shouting about.
            if (failCount >= 2) {
                goBad('Cannot reach the website',
                      'The shop internet or the website is down, so new orders cannot be checked and nothing will print. '
                    + 'Check the internet is working. Nothing has been lost \u2014 any order waiting will print as soon as this reconnects.');
            }
            if (failCount === 1 || failCount === 2 || failCount % 20 === 0) {
                log('Could not check for orders \u2014 ' + shortErr(err), 'bad');
            }
        } finally {
            busy = false;
            render();
        }
    }

    // ── Buttons ─────────────────────────────────────────────────────

    async function doTestPrint() {
        if (ui.testBtn) { ui.testBtn.disabled = true; }
        var id = lastOrderId > 0 ? lastOrderId : (serverMaxId > 0 ? serverMaxId : CFG.bootMaxId);

        try {
            if (id > 0) {
                log('Test print: printing the receipt for order ' + id + ' again. It is only a test, so nothing is recorded against the order.', '');
                await printReceipt(id, false);
            } else {
                log('Test print: there are no orders yet, so today\u2019s totals slip is being printed instead.', '');
                await printUrl('summary.php?auto=0&_=' + Date.now());
            }
            log('Test print sent to the printer. If no paper came out, the printer is off, out of paper, or is not the one Windows is set to use.', 'good');
        } catch (err) {
            log('The test print did not work \u2014 ' + shortErr(err), 'bad');
        }

        if (ui.testBtn) { ui.testBtn.disabled = false; }
    }

    async function doReset() {
        // Two clicks, because this one skips anything still waiting to print.
        if (!resetArmed) {
            resetArmed = true;
            if (ui.resetBtn) { ui.resetBtn.textContent = 'Are you sure? Click once more'; }
            window.setTimeout(function () {
                if (!resetArmed) { return; }
                resetArmed = false;
                if (ui.resetBtn) { ui.resetBtn.textContent = CFG.resetLabel; }
            }, 6000);
            return;
        }

        resetArmed = false;
        if (ui.resetBtn) { ui.resetBtn.disabled = true; ui.resetBtn.textContent = 'Starting again…'; }

        var newest = await fetchNewestOrderId();
        if (newest === null) {
            newest = serverMaxId > 0 ? serverMaxId : CFG.bootMaxId;
            log('The website could not be asked for the newest order number, so the last one this page saw was used.', '');
        }

        handled = {};
        setWatermark(newest);
        log('Started again. Only orders after ' + newest + ' will print from now on. Anything that was waiting can still be printed from the Orders page.', '');

        if (ui.resetBtn) { ui.resetBtn.disabled = false; ui.resetBtn.textContent = CFG.resetLabel; }
        render();
        tick();
    }

    function doCopyShortcut() {
        if (!ui.shortcut) { return; }
        var text = ui.shortcut.textContent || '';
        var said = function () {
            toggle(ui.copySaid, true);
            window.setTimeout(function () { toggle(ui.copySaid, false); }, 2500);
        };

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(said, function () {
                log('The line could not be copied automatically. Select it with the mouse and press Ctrl and C together.', '');
            });
            return;
        }
        log('This browser will not copy it automatically. Select the line with the mouse and press Ctrl and C together.', '');
    }

    // ── Start ───────────────────────────────────────────────────────

    function start() {
        ui = {
            status:        document.getElementById('cbpsStatus'),
            headline:      document.getElementById('cbpsHeadline'),
            subline:       document.getElementById('cbpsSubline'),
            fix:           document.getElementById('cbpsFix'),
            fixSignIn:     document.getElementById('cbpsFixSignIn'),
            fixReload:     document.getElementById('cbpsFixReload'),
            watermark:     document.getElementById('cbpsWatermark'),
            watermarkNote: document.getElementById('cbpsWatermarkNote'),
            lastCheck:     document.getElementById('cbpsLastCheck'),
            lastCheckNote: document.getElementById('cbpsLastCheckNote'),
            count:         document.getElementById('cbpsCount'),
            countNote:     document.getElementById('cbpsCountNote'),
            sound:         document.getElementById('cbpsSound'),
            soundText:     document.getElementById('cbpsSoundText'),
            soundBtn:      document.getElementById('cbpsSoundBtn'),
            testBtn:       document.getElementById('cbpsTestBtn'),
            checkBtn:      document.getElementById('cbpsCheckBtn'),
            resetBtn:      document.getElementById('cbpsResetBtn'),
            copyBtn:       document.getElementById('cbpsCopyBtn'),
            copySaid:      document.getElementById('cbpsCopySaid'),
            shortcut:      document.getElementById('cbpsShortcut'),
            log:           document.getElementById('cbpsLog'),
            frames:        document.getElementById('cbpsFrames')
        };

        initCount();
        initWatermark();          // before anything is fetched. Always.
        claimPrinterRole();       // and only one tab may go on to print.
        render();
        renderSound();

        if (ui.soundBtn) {
            ui.soundBtn.addEventListener('click', function () {
                var ok = startAudio();
                renderSound();
                if (ok) { chime(); }
            });
        }

        // Any click on the page counts as the gesture the browser is waiting
        // for, so the chime often comes on without the owner pressing anything.
        document.addEventListener('click', function () {
            if (!audioReady()) { startAudio(); renderSound(); }
        }, true);

        if (ui.testBtn)   { ui.testBtn.addEventListener('click', doTestPrint); }
        if (ui.checkBtn)  { ui.checkBtn.addEventListener('click', function () { tick(); }); }
        if (ui.resetBtn)  { ui.resetBtn.addEventListener('click', doReset); }
        if (ui.copyBtn)   { ui.copyBtn.addEventListener('click', doCopyShortcut); }
        if (ui.fixReload) { ui.fixReload.addEventListener('click', function () { window.location.reload(); }); }

        // Keep "last checked" honest without waiting for the next poll, and
        // notice if the browser quietly suspends the sound again.
        window.setInterval(function () {
            render();
            renderSound();
        }, 1000);

        window.setInterval(tick, CFG.pollMs);

        // A laptop that has been asleep, or a tab that has been in the
        // background, should catch up the moment it is looked at again.
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) { tick(); }
        });
        window.addEventListener('online', function () { tick(); });

        tick();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
}());
</script>

</body>
</html>
