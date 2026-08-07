<?php
// ============================================================
//  Creamy Bite – End-of-day summary receipt
//  URL: /admin/print/summary.php               (today)
//       /admin/print/summary.php?date=2026-08-06
//       /admin/print/summary.php?date=...&auto=1  (print and go)
//
//  The slip the owner prints at closing time and reconciles the
//  till drawer against. Same 72mm roll, same visual language as
//  the order receipt, so the two stack in the same spike.
//
//  Every figure comes from cbReceiptDailySummary() in
//  includes/receipt.php. Nothing is added up here.
//
//  Nothing is hidden either. A cancelled or refunded order is
//  still in the count and still in the gross, and is listed
//  again at the bottom with what is wrong with it — an order
//  that quietly vanished from the total is exactly what makes a
//  drawer impossible to reconcile.
// ============================================================

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_permissions.php';
adminRequire('orders');

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/receipt.php';

/**
 * Make a string safe to burn onto thermal paper.
 *
 * The same helper as admin/print/receipt.php, guarded by function_exists so
 * the two pages stay independent of one another. Typographic punctuation is
 * swapped for ASCII (a monospace printer fallback face often has no glyph for
 * an em dash) and emoji are removed outright — a thermal head is one bit and
 * renders them as a black rectangle. Customer and trade names reach this page
 * straight out of the database, so they go through it too.
 */
if (!function_exists('cbrpText')) {
    function cbrpText(string $s): string
    {
        if ($s === '') {
            return '';
        }

        $s = strtr($s, [
            "\xE2\x80\x94" => '-',    // em dash
            "\xE2\x80\x93" => '-',    // en dash
            "\xE2\x80\x98" => "'",    // curly quotes
            "\xE2\x80\x99" => "'",
            "\xE2\x80\x9C" => '"',
            "\xE2\x80\x9D" => '"',
            "\xE2\x86\x92" => '->',   // arrows, used in the edit notes
            "\xE2\x86\x90" => '<-',
            "\xE2\x80\xA6" => '...',
            "\xE2\x80\xA2" => '-',    // bullet
            "\xC3\x97"     => 'x',    // multiplication sign
            "\xE2\x88\x92" => '-',    // true minus
            "\xC2\xA0"     => ' ',    // non-breaking space
        ]);

        // preg_replace returns null on invalid UTF-8. Keeping the original is
        // right: a customer name with one bad byte in it still needs to print.
        $s = preg_replace(
            '/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}'
          . '\x{FE00}-\x{FE0F}\x{200D}\x{20E3}\x{2190}-\x{21FF}'
          . '\x{2122}\x{2139}\x{3030}\x{303D}\x{3297}\x{3299}]/u',
            '',
            $s
        ) ?? $s;

        // A removed glyph leaves its space behind, and the trade orders all
        // carry one inside a bracket.
        $s = preg_replace('/[ \t]{2,}/', ' ', $s) ?? $s;
        $s = preg_replace('/([\[(])[ \t]+/', '$1', $s) ?? $s;
        $s = preg_replace('/[ \t]+([\])])/', '$1', $s) ?? $s;

        return trim($s);
    }
}

$requested = trim(is_scalar($_GET['date'] ?? '') ? (string)($_GET['date'] ?? '') : '');
if ($requested === '') {
    $requested = date('Y-m-d');
}

// Anything but auto=0 counts as on, same rule as receipt.php.
$autoParam = $_GET['auto'] ?? null;
$auto      = $autoParam !== null && $autoParam !== '0' && strtolower(is_scalar($autoParam) ? (string)$autoParam : '') !== 'false';

// A mistyped date comes back as today rather than as an empty 1970 slip —
// cbReceiptDailySummary() normalises it and tells us which day it used.
$sum  = cbReceiptDailySummary($pdo, $requested);
$meta = $sum['meta'];
$day  = $sum['date'];

$prevDay = date('Y-m-d', strtotime($day . ' -1 day'));
$nextDay = date('Y-m-d', strtotime($day . ' +1 day'));

$h = static fn($v): string => htmlspecialchars(cbrpText((string)$v), ENT_QUOTES, 'UTF-8');
$m = static fn($n): string => '£' . number_format((float)$n, 2);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Daily summary <?= htmlspecialchars($day, ENT_QUOTES, 'UTF-8') ?></title>
<?php require __DIR__ . '/../../includes/favicon.php'; ?>
<link rel="stylesheet" href="../../assets/css/receipt.css">
</head>
<body class="cbr-body">
<div class="cbr-stage">

    <div class="cbr-paper">

        <!-- ── Shop ────────────────────────────────────────── -->
        <div class="cbr-head">
            <div class="cbr-shop-name"><?= $h($meta['name']) ?></div>
            <?php foreach ($meta['address_lines'] as $line): ?>
            <div class="cbr-head-line"><?= $h($line) ?></div>
            <?php endforeach; ?>
            <?php if ($meta['phone'] !== ''): ?>
            <div class="cbr-head-line">Tel <?= $h($meta['phone']) ?></div>
            <?php endif; ?>
        </div>

        <div class="cbr-rule-double"></div>

        <div class="cbr-code-block">
            <div class="cbr-doc-label">End of day</div>
            <div class="cbr-code"><?= $h($sum['date_label']) ?></div>
            <div class="cbr-when">
                <?= (int)$sum['order_count'] ?> order<?= (int)$sum['order_count'] === 1 ? '' : 's' ?>
            </div>
        </div>

        <div class="cbr-rule-solid"></div>

        <!-- ── The day's money ────────────────────────────── -->
        <div class="cbr-tot">
            <div class="cbr-tot-row cbr-tot-grand">
                <span class="cbr-tot-k">TAKINGS</span>
                <span class="cbr-tot-v"><?= $m($sum['gross_total']) ?></span>
            </div>

            <div class="cbr-rule"></div>

            <div class="cbr-tot-row">
                <span class="cbr-tot-k">Orders</span>
                <span class="cbr-tot-v"><?= (int)$sum['order_count'] ?></span>
            </div>
            <div class="cbr-tot-row">
                <span class="cbr-tot-k">Discounts given</span>
                <?php // No minus in front of nothing: "-£0.00" reads as a
                      // number someone has to go and check. ?>
                <span class="cbr-tot-v"><?= $sum['discount_total'] > 0 ? '-' : '' ?><?= $m($sum['discount_total']) ?></span>
            </div>
            <div class="cbr-tot-row">
                <span class="cbr-tot-k">Delivery charged</span>
                <span class="cbr-tot-v"><?= $m($sum['delivery_total']) ?></span>
            </div>
            <div class="cbr-tot-row">
                <span class="cbr-tot-k">VAT in the total</span>
                <span class="cbr-tot-v"><?= $m($sum['vat_total']) ?></span>
            </div>

            <div class="cbr-rule"></div>

            <?php // Gross minus the VAT that belongs to HMRC — the shop's own
                  // money, on the same ex-VAT basis the invoice commission uses. ?>
            <div class="cbr-tot-row cbr-strong">
                <span class="cbr-tot-k">Takings less VAT</span>
                <span class="cbr-tot-v"><?= $m($sum['net_total']) ?></span>
            </div>
        </div>

        <!-- ── How it was paid ────────────────────────────── -->
        <?php if (!empty($sum['by_payment_method'])): ?>
        <div class="cbr-rule-solid"></div>
        <div class="cbr-sec">
            <div class="cbr-sec-title">By payment method</div>
            <?php foreach ($sum['by_payment_method'] as $bucket): ?>
            <div class="cbr-kv">
                <span class="cbr-kv-k">
                    <?= $h($bucket['label']) ?>
                    (<?= (int)$bucket['count'] ?>)
                </span>
                <span class="cbr-kv-v"><?= $m($bucket['total']) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- ── What is actually settled ───────────────────── -->
        <?php if (!empty($sum['by_payment_status'])): ?>
        <div class="cbr-rule"></div>
        <div class="cbr-sec">
            <div class="cbr-sec-title">By payment status</div>
            <?php foreach ($sum['by_payment_status'] as $bucket): ?>
            <div class="cbr-kv">
                <span class="cbr-kv-k">
                    <?= $h($bucket['label']) ?>
                    (<?= (int)$bucket['count'] ?>)
                </span>
                <span class="cbr-kv-v"><?= $m($bucket['total']) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- ── Money to take back out by hand ─────────────── -->
        <?php if ((int)$sum['cancelled_count'] > 0 || (int)$sum['refunded_count'] > 0): ?>
        <div class="cbr-rule-solid"></div>
        <div class="cbr-sec">
            <div class="cbr-sec-title">In the total above</div>
            <?php if ((int)$sum['cancelled_count'] > 0): ?>
            <div class="cbr-kv">
                <span class="cbr-kv-k">Cancelled (<?= (int)$sum['cancelled_count'] ?>)</span>
                <span class="cbr-kv-v"><?= $m($sum['cancelled_total']) ?></span>
            </div>
            <?php endif; ?>
            <?php if ((int)$sum['refunded_count'] > 0): ?>
            <?php // Spelled out because cbReceiptDailySummary() counts a
                  // part-refunded order in here too, and a bare "Refunded (3)"
                  // sitting under a "Refunded (2)" in the status breakdown
                  // above looks like one of the two is wrong. ?>
            <div class="cbr-kv">
                <span class="cbr-kv-k">Refunded or part-refunded (<?= (int)$sum['refunded_count'] ?>)</span>
                <span class="cbr-kv-v"><?= $m($sum['refunded_total']) ?></span>
            </div>
            <?php endif; ?>
            <div class="cbr-banner-note">
                These are counted in the takings above. Subtract by hand if you need
                the figure that stayed in the drawer.
            </div>
        </div>
        <?php endif; ?>

        <!-- ── The orders that need a second look ─────────── -->
        <?php if (!empty($sum['flagged'])): ?>
        <div class="cbr-rule-solid"></div>
        <div class="cbr-sec">
            <div class="cbr-sec-title">Check these orders</div>
            <?php foreach ($sum['flagged'] as $flag): ?>
            <div class="cbr-flag">
                <div class="cbr-kv">
                    <span class="cbr-kv-k cbr-strong"><?= $h($flag['order_code']) ?></span>
                    <span class="cbr-kv-v"><?= $m($flag['total']) ?></span>
                </div>
                <div class="cbr-flag-mark"><?= $h($flag['flag']) ?></div>
                <?php if (trim((string)$flag['customer']) !== ''): ?>
                <div><?= $h($flag['customer']) ?></div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (!empty($sum['warnings'])): ?>
        <div class="cbr-warn">
            <div class="cbr-warn-title">Please note</div>
            <?php foreach ($sum['warnings'] as $warning): ?>
            <div><?= $h($warning) ?></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="cbr-rule-double"></div>

        <div class="cbr-foot">
            <div class="cbr-foot-big">End of <?= $h($sum['date_label']) ?></div>
            <div>Counted by order date placed.</div>
            <div>Printed <?= date('j M Y H:i') ?></div>
        </div>

        <div class="cbr-tail"></div>
    </div>

    <!-- Screen only. Never reaches the printer. -->
    <div class="cbr-toolbar cbr-noprint">
        <button type="button" class="cbr-btn" id="cbrPrintBtn">Print this summary</button>
        <form class="cbr-toolbar-form" method="get" action="summary.php">
            <input type="date" class="cbr-date-input" name="date"
                   value="<?= htmlspecialchars($day, ENT_QUOTES, 'UTF-8') ?>">
            <button type="submit" class="cbr-btn">Show</button>
        </form>
        <div class="cbr-toolbar-form">
            <a class="cbr-btn" href="summary.php?date=<?= urlencode($prevDay) ?>">Day before</a>
            <a class="cbr-btn" href="summary.php?date=<?= urlencode($nextDay) ?>">Day after</a>
        </div>
        <div class="cbr-toolbar-form">
            <a class="cbr-btn" href="../index.php?tab=orders">Back to orders</a>
        </div>
        <div class="cbr-toolbar-hint">
            The dashed edge is the 72mm the printer can reach.
            Anything outside it is cut off on the roll.
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';

    var btn = document.getElementById('cbrPrintBtn');
    if (btn) {
        btn.addEventListener('click', function () {
            window.print();
        });
    }

    var auto = <?= $auto ? 'true' : 'false' ?>;
    if (!auto) {
        return;
    }

    // Same one-copy guard as receipt.php: if something outside this page has
    // already asked for a print, beforeprint has fired and the self-print
    // below stands down.
    var printed = false;

    window.addEventListener('beforeprint', function () {
        printed = true;
    });

    window.addEventListener('afterprint', function () {
        if (window.self === window.top) {
            try {
                window.close();
            } catch (err) {
                // Opened by hand rather than by a script: leave the page up.
            }
        }
    });

    function cbrGo() {
        if (printed) {
            return;
        }
        printed = true;
        try {
            window.print();
        } catch (err) {
            // A printer that is off must not take the page down with it.
        }
    }

    if (document.readyState === 'complete') {
        window.setTimeout(cbrGo, 400);
    } else {
        window.addEventListener('load', function () {
            window.setTimeout(cbrGo, 400);
        });
    }
}());
</script>
</body>
</html>
