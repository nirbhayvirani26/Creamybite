<?php
// ============================================================
//  Creamy Bite – Thermal receipt for ONE order
//  URL: /admin/print/receipt.php?id=12       (look at it)
//       /admin/print/receipt.php?id=12&auto=1 (print it and go)
//
//  80mm roll on the Oxhoo TP85 at the counter, 72mm of it
//  printable. This page is the paper the customer's order gets
//  picked from, so it carries everything the person at the till
//  needs and nothing they do not: no nav, no dashboard, no icon
//  fonts, no colour.
//
//  Every figure on it comes from cbReceiptData() in
//  includes/receipt.php. Nothing is recomputed here and
//  items_json is never decoded here — the whole point of that
//  file is that the receipt, the reprint and the end-of-day
//  summary cannot quote three different totals for one order.
//  This page formats what it is handed.
//
//  ── auto=1 ───────────────────────────────────────────────
//
//  Two callers pass it, and they behave differently:
//
//    * the reprint button in admin/index.php opens this page in
//      a new window. It prints itself and closes.
//    * admin/print/station.php loads it into a hidden iframe and
//      calls print on the iframe. The self-print below stands
//      down when that has already happened (see the script at
//      the bottom) so the shop does not get two copies of every
//      order.
//
//  A missing order prints NOTHING. A blank slip with a shop
//  header on it looks like a real order that lost its items.
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
 * Two separate jobs, both of which showed up in the real data:
 *
 *  1. Typographic punctuation the shop's own writing is full of
 *     — em dashes and curly quotes out of the refund notes, the
 *     "->" arrow in "Order total £14.40 -> £8.40" — is swapped
 *     for its ASCII equivalent rather than dropped, because the
 *     monospace faces a printer driver falls back to often have
 *     no glyph for them and a missing arrow makes that sentence
 *     unreadable.
 *
 *  2. Emoji are removed outright. Every trade order's notes open
 *     with a shop glyph and every catalogue item carries one in
 *     items_json; a thermal head has no colour and renders them
 *     as a black rectangle or as nothing at all. (The item emoji
 *     is already dropped upstream by cbReceiptData(); the notes
 *     are raw and come through here.)
 *
 * Defined guarded and prefixed so it can live in this file and
 * in summary.php without either page depending on the other.
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

        // A removed glyph leaves its space behind, which on a 34-character
        // line is a visible hole — the trade orders all open with one inside a
        // bracket, so without this every one of them prints "[ TRADE B2B
        // ORDER - Store: ...]".
        $s = preg_replace('/[ \t]{2,}/', ' ', $s) ?? $s;
        $s = preg_replace('/([\[(])[ \t]+/', '$1', $s) ?? $s;
        $s = preg_replace('/[ \t]+([\])])/', '$1', $s) ?? $s;

        return trim($s);
    }
}

$orderId = (int)($_GET['id'] ?? 0);

// Anything but auto=0 counts as on. The station and the reprint button both
// send auto=1; a hand-typed &auto is clearly a request to print.
$autoParam = $_GET['auto'] ?? null;
$auto      = $autoParam !== null && $autoParam !== '0' && strtolower(is_scalar($autoParam) ? (string)$autoParam : '') !== 'false';

$data = $orderId > 0 ? cbReceiptData($pdo, $orderId) : null;

// Output escaper. Everything user-supplied goes through this — cleaned for
// the printer first, then escaped for the browser.
$h = static fn($v): string => htmlspecialchars(cbrpText((string)$v), ENT_QUOTES, 'UTF-8');
$m = static fn($n): string => '£' . number_format((float)$n, 2);

if ($data === null) {
    http_response_code(404);
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Receipt not found</title>
<link rel="stylesheet" href="../../assets/css/receipt.css">
</head>
<body class="cbr-body">
<div class="cbr-stage">
    <div class="cbr-paper">
        <div class="cbr-banner">
            <div class="cbr-banner-title">No such order</div>
            <div class="cbr-banner-body">
                Order <?= $orderId > 0 ? '#' . (int)$orderId : '(no id given)' ?> is not in the
                database, so there is nothing to print.
            </div>
        </div>
        <div class="cbr-foot">Check the order number and try again.</div>
    </div>
    <div class="cbr-toolbar cbr-noprint">
        <a class="cbr-btn" href="../index.php?tab=orders">Back to orders</a>
    </div>
</div>
</body>
</html>
    <?php
    exit;
}

$order = $data['order'];
$items = $data['items'];
$meta  = $data['meta'];

// ── Collection or delivery ───────────────────────────────────
//
// Detected exactly the way includes/mailer.php does it — the address column
// on a collection order literally starts "Collection - Creamy Bite, ...".
// Two different tests for the same thing is how a receipt ends up saying
// DELIVERY on an order the customer is coming to fetch.
$address      = (string)($order['address'] ?? '');
$isCollection = $address !== '' && str_contains($address, 'Collection');
$postcode = trim((string)($order['postcode'] ?? ''));
$status   = (string)($order['status'] ?? '');
$isCancelled = strcasecmp($status, 'Cancelled') === 0;

// ── Payment: the line that decides whether money changes hands ──
$ps = (string)($order['payment_status'] ?? 'Unpaid');
$pm = strtolower(trim((string)($order['payment_method'] ?? '')));

// Same wording as the method breakdown in cbReceiptDailySummary(), so the
// receipt and the end-of-day slip name the same thing the same way.
$methodLabel = match ($pm) {
    'online', 'card', 'stripe' => 'Card (online)',
    'cash'                     => 'Cash',
    'bank'                     => 'Bank transfer',
    'later'                    => $isCollection ? 'Pay on collection' : 'Pay on delivery',
    ''                         => 'Not recorded',
    default                    => ucfirst($pm),
};

switch ($ps) {
    case 'Paid':
        $payBig = 'PAID IN FULL';
        $paySub = 'Nothing to collect - ' . $methodLabel;
        break;
    case 'Cash':
        $payBig = 'PAID - CASH';
        $paySub = 'Cash received. Nothing to collect.';
        break;
    case 'Bank':
        $payBig = 'PAID - BANK TRANSFER';
        $paySub = 'Transfer received. Nothing to collect.';
        break;
    case 'Refunded':
        $payBig = 'REFUNDED';
        $paySub = 'This order has been refunded in full.';
        break;
    case 'Part-refunded':
        $payBig = 'PART REFUNDED';
        $paySub = 'Part of this order has been refunded - see the notes below.';
        break;
    default:
        $payBig = 'COLLECT ' . $m($data['total']);
        if (in_array($pm, ['online', 'card', 'stripe'], true)) {
            $paySub = 'Card payment not completed - take payment before handing over.';
        } else {
            $paySub = $isCollection
                ? 'Not paid. Take payment on collection.'
                : 'Not paid. Take payment on delivery.';
        }
        break;
}

// ── How many things are in the bag ───────────────────────────
$unitCount = 0;
foreach ($items as $it) {
    $unitCount += (int)$it['qty'];
}

$notes = cbrpText((string)($order['notes'] ?? ''));

// print_count is added by the migration. Read defensively: on a server where
// the migration has not been run yet the column is simply absent, and a
// receipt must still print.
$printCount = (int)($order['print_count'] ?? 0);
$printedAt  = trim((string)($order['printed_at'] ?? ''));

$store     = trim((string)($order['trade_business_name'] ?? ''));
$vatNumber = trim((string)($order['vat_number'] ?? ''));
$promo     = trim((string)($order['promo_code'] ?? ''));

$placed = strtotime((string)($order['created_at'] ?? '')) ?: time();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Receipt <?= $h($order['order_code']) ?></title>
<?php require __DIR__ . '/../../includes/favicon.php'; ?>
<link rel="stylesheet" href="../../assets/css/receipt.css">
</head>
<body class="cbr-body">
<div class="cbr-stage">

    <div class="cbr-paper">

        <?php if ($printCount > 0): ?>
        <?php // Announced loudly, because the failure this prevents is an order
              // being picked, packed and handed over twice. ?>
        <div class="cbr-reprint">
            Reprint
            <div class="cbr-reprint-sub">
                Printed <?= $printCount ?> time<?= $printCount === 1 ? '' : 's' ?> already<?php
                    if ($printedAt !== '' && strtotime($printedAt)):
                        ?>, first on <?= date('j M Y H:i', (int)strtotime($printedAt)) ?><?php
                    endif; ?>.
            </div>
        </div>
        <?php endif; ?>

        <?php if ($isCancelled): ?>
        <div class="cbr-banner">
            <div class="cbr-banner-title">Cancelled</div>
            <div class="cbr-banner-note">Do not make or hand over this order.</div>
        </div>
        <?php endif; ?>

        <!-- ── Shop ────────────────────────────────────────── -->
        <div class="cbr-head">
            <div class="cbr-shop-name"><?= $h($meta['name']) ?></div>
            <?php if ($meta['tagline'] !== ''): ?>
            <div class="cbr-tagline"><?= $h($meta['tagline']) ?></div>
            <?php endif; ?>
            <?php foreach ($meta['address_lines'] as $line): ?>
            <div class="cbr-head-line"><?= $h($line) ?></div>
            <?php endforeach; ?>
            <?php if ($meta['phone'] !== ''): ?>
            <div class="cbr-head-line">Tel <?= $h($meta['phone']) ?></div>
            <?php endif; ?>
        </div>

        <div class="cbr-rule-double"></div>

        <!-- ── Order code ─────────────────────────────────── -->
        <div class="cbr-code-block">
            <div class="cbr-code"><?= $h($order['order_code']) ?></div>
            <div class="cbr-when"><?= date('D j M Y', $placed) ?> at <?= date('H:i', $placed) ?></div>
            <?php if ($status !== '' && !$isCancelled): ?>
            <div class="cbr-when"><?= $h($status) ?></div>
            <?php endif; ?>
        </div>

        <!-- ── Where it goes ──────────────────────────────── -->
        <?php if ($isCollection): ?>
        <div class="cbr-banner">
            <?php // No address on a collection order. The only address there is to
                  // print is the shop's own, and this receipt never leaves the shop —
                  // it just costs a line of paper and pushes the items further down. ?>
            <div class="cbr-banner-title">Collection</div>
            <div class="cbr-banner-note">Customer is collecting - do not send out.</div>
        </div>
        <?php else: ?>
        <div class="cbr-banner">
            <div class="cbr-banner-title">Delivery</div>
            <div class="cbr-banner-body">
                <?= $address !== '' ? $h($address) : 'No address recorded on this order.' ?>
                <?php if ($postcode !== ''): ?>
                <span class="cbr-postcode"><?= $h(strtoupper($postcode)) ?></span>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="cbr-rule"></div>

        <!-- ── Who it is for ──────────────────────────────── -->
        <div class="cbr-sec">
            <div class="cbr-sec-title">Customer</div>
            <div class="cbr-line-big"><?= $h($order['customer_name'] ?: 'Not given') ?></div>
            <?php if (trim((string)($order['phone'] ?? '')) !== ''): ?>
            <div class="cbr-phone"><?= $h($order['phone']) ?></div>
            <?php endif; ?>
            <?php if ($store !== ''): ?>
            <div class="cbr-line">Trade account: <span class="cbr-strong"><?= $h($store) ?></span></div>
            <?php endif; ?>
            <?php if ($vatNumber !== ''): ?>
            <div class="cbr-line">VAT no: <?= $h($vatNumber) ?></div>
            <?php endif; ?>
        </div>

        <div class="cbr-rule-solid"></div>

        <!-- ── The items ──────────────────────────────────── -->
        <div class="cbr-sec">
            <div class="cbr-sec-title">Items</div>
            <?php if ($items): ?>
            <div class="cbr-item-count">
                <?= count($items) ?> line<?= count($items) === 1 ? '' : 's' ?>
                / <?= $unitCount ?> item<?= $unitCount === 1 ? '' : 's' ?>
            </div>
            <?php endif; ?>

            <div class="cbr-items">
                <?php foreach ($items as $item): ?>
                <div class="cbr-item">
                    <div class="cbr-item-top">
                        <span class="cbr-item-qty"><?= (int)$item['qty'] ?>x</span>
                        <span class="cbr-item-name"><?= $h($item['name']) ?></span>
                        <span class="cbr-item-amt"><?= $m($item['line_total']) ?></span>
                    </div>
                    <?php // The second line only earns its space when it says something:
                          // a single-size product has variant '' and repeating the unit
                          // price of a quantity of one is noise. ?>
                    <?php if ($item['variant'] !== '' || (int)$item['qty'] > 1): ?>
                    <div class="cbr-item-sub">
                        <span class="cbr-item-variant"><?= $item['variant'] !== '' ? $h($item['variant']) : '' ?></span>
                        <span class="cbr-item-unit"><?= (int)$item['qty'] ?> x <?= $m($item['unit_price']) ?></span>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>

                <?php if (!$items): ?>
                <div class="cbr-line">No item lines are recorded on this order.</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="cbr-rule-solid"></div>

        <!-- ── The money ──────────────────────────────────── -->
        <div class="cbr-tot">
            <div class="cbr-tot-row">
                <span class="cbr-tot-k">Subtotal</span>
                <span class="cbr-tot-v"><?= $m($data['subtotal']) ?></span>
            </div>

            <?php if ($data['discount'] > 0): ?>
            <div class="cbr-tot-row">
                <span class="cbr-tot-k">Discount<?= $promo !== '' ? ' (' . $h($promo) . ')' : '' ?></span>
                <span class="cbr-tot-v">-<?= $m($data['discount']) ?></span>
            </div>
            <?php endif; ?>

            <?php if ($data['delivery'] > 0): ?>
            <div class="cbr-tot-row">
                <span class="cbr-tot-k">Delivery</span>
                <span class="cbr-tot-v"><?= $m($data['delivery']) ?></span>
            </div>
            <?php elseif (!$isCollection): ?>
            <div class="cbr-tot-row">
                <span class="cbr-tot-k">Delivery</span>
                <span class="cbr-tot-v">FREE</span>
            </div>
            <?php endif; ?>

            <?php if ($data['vat'] > 0): ?>
            <div class="cbr-tot-row">
                <span class="cbr-tot-k">VAT <?= rtrim(rtrim(number_format((float)$meta['vat_rate'] * 100, 1, '.', ''), '0'), '.') ?>%</span>
                <span class="cbr-tot-v"><?= $m($data['vat']) ?></span>
            </div>
            <?php endif; ?>

            <div class="cbr-rule"></div>

            <div class="cbr-tot-row cbr-tot-grand">
                <span class="cbr-tot-k">TOTAL</span>
                <span class="cbr-tot-v"><?= $m($data['total']) ?></span>
            </div>
        </div>

        <!-- ── Paid, or not ───────────────────────────────── -->
        <div class="cbr-pay">
            <div class="cbr-pay-big"><?= $h($payBig) ?></div>
            <div class="cbr-pay-sub"><?= $h($paySub) ?></div>
        </div>

        <div class="cbr-kv">
            <span class="cbr-kv-k">Payment method</span>
            <span class="cbr-kv-v"><?= $h($methodLabel) ?></span>
        </div>

        <?php if ($notes !== ''): ?>
        <div class="cbr-rule"></div>
        <div class="cbr-sec">
            <div class="cbr-sec-title">Notes</div>
            <div class="cbr-note"><?= $h($notes) ?></div>
        </div>
        <?php endif; ?>

        <?php if (!empty($data['warnings'])): ?>
        <?php // cbReceiptData() raises these when the stored total and the item
              // lines do not agree, or when items_json could not be read. They
              // print, in plain English, because whoever is holding the paper
              // is the only person who can do anything about it. ?>
        <div class="cbr-warn">
            <div class="cbr-warn-title">Please check</div>
            <?php foreach ($data['warnings'] as $warning): ?>
            <div><?= $h($warning) ?></div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="cbr-rule-double"></div>

        <div class="cbr-foot">
            <div class="cbr-foot-big">Thank you</div>
            <?php if ($meta['phone'] !== ''): ?>
            <div>Questions? <?= $h($meta['phone']) ?></div>
            <?php endif; ?>
            <?php if ($meta['website'] !== ''): ?>
            <div><?= $h(preg_replace('#^https?://#', '', $meta['website'])) ?></div>
            <?php endif; ?>
            <div>Printed <?= date('j M Y H:i') ?></div>
        </div>

        <div class="cbr-tail"></div>
    </div>

    <!-- Screen only. Never reaches the printer: .cbr-noprint is display:none
         inside @media print. -->
    <div class="cbr-toolbar cbr-noprint">
        <button type="button" class="cbr-btn" id="cbrPrintBtn">Print this receipt</button>
        <a class="cbr-btn" href="summary.php">Today's summary</a>
        <a class="cbr-btn" href="../index.php?tab=orders">Back to orders</a>
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

    // Guard against printing the same order twice.
    //
    // station.php loads this page into a hidden iframe and calls print on it
    // itself, which is what the build contract asks it to do. If it has
    // already fired, the browser has raised beforeprint in here first and the
    // self-print below stands down. The delay is what gives that a chance to
    // happen: without it, both fire and every order costs two receipts.
    var printed = false;

    window.addEventListener('beforeprint', function () {
        printed = true;
    });

    window.addEventListener('afterprint', function () {
        // Only close a window of our own. In the station's iframe this is not
        // ours to close, and the browser refuses anyway.
        if (window.self === window.top) {
            try {
                window.close();
            } catch (err) {
                // Typed straight into the address bar rather than opened by a
                // script: nothing to do, leave the page up.
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
            // A printer that is off or unreachable must not take the page down
            // with it — the order stays unprinted in the database and the
            // station picks it up again on the next poll.
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
