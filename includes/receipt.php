<?php
// ============================================================
//  Creamy Bite – Thermal receipt domain layer
//
//  Everything printed on the Oxhoo TP85 at the counter gets its numbers from
//  here: the receipt that prints automatically when an order lands, the
//  reprint from the admin order list, and the end-of-day summary. They are
//  three different pages showing the same money, so the arithmetic happens
//  once, in this file, and each page only formats what it is handed.
//
//  ── What the money means ───────────────────────────────────
//
//  The order of operations is the one in includes/pricing.php, which is what
//  actually charged the customer:
//
//      subtotal = sum(line price x qty)
//      base     = subtotal - discount + delivery
//      VAT      = 20% of the base, trade customers with a VAT number only
//      total    = base + VAT
//
//  `total` is READ from orders.total_price, never recomputed. That column is
//  the amount the customer was actually charged and the amount the daily
//  summary adds up; recomputing it here would let a receipt hand someone a
//  figure the till never took. Where the stored total and the item lines
//  disagree — a hand-edited order, a line removed after the fact — the
//  receipt prints the recorded total and carries a warning saying so, because
//  a receipt that flags a discrepancy is useful and one that silently picks a
//  side is not.
//
//  ── What items_json really contains ────────────────────────
//
//  Read from the live database, not assumed. Each line item is:
//
//      cart_key, product_id, variant_id, variant_name, name, emoji,
//      image, price, quantity
//
//  variant_name is '' (not null) on a product sold without sizes, and
//  variant_id is then null. cart_key is sometimes the string "19:11" and
//  sometimes a bare int. price is a JSON number, quantity an int. `emoji` is
//  deliberately NOT carried through: thermal printers render emoji as a black
//  box or nothing at all, so the receipt is plain text and CSS borders only.
//
//  Malformed or empty items_json must never be fatal. A receipt that prints
//  with a warning line beats a 500 error at a counter with a customer waiting.
//
//  Requires config.php and a $pdo from db.php.
// ============================================================

if (defined('CB_RECEIPT_LOADED')) {
    return;
}
define('CB_RECEIPT_LOADED', true);

require_once __DIR__ . '/config.php';

/**
 * The shop's own details for the top of any printed receipt.
 *
 * One source for the header so the order receipt, the reprint and the daily
 * summary cannot end up quoting different phone numbers. Everything comes
 * from includes/config.php rather than the database: the receipt has to print
 * on a counter PC with a flaky connection, and shop identity is not
 * per-document data.
 *
 * `address_lines` is the address already split, so a print template can emit
 * one short line at a time — 72mm of paper fits roughly 32 monospaced
 * characters and a longer line is clipped, not wrapped.
 *
 * @return array{name:string,tagline:string,address:string,address_lines:string[],phone:string,email:string,website:string,vat_rate:float}
 */
function cbReceiptMeta(): array
{
    $address = defined('SHOP_ADDRESS') ? (string)SHOP_ADDRESS : '';
    $lines   = array_values(array_filter(array_map(
        'trim',
        preg_split('/\r\n|\r|\n/', $address) ?: []
    ), static fn(string $l): bool => $l !== ''));

    return [
        'name'          => defined('SHOP_NAME')    ? (string)SHOP_NAME    : 'Creamy Bite',
        'tagline'       => defined('SHOP_TAGLINE') ? (string)SHOP_TAGLINE : '',
        'address'       => $address,
        'address_lines' => $lines,
        'phone'         => defined('SHOP_PHONE')   ? (string)SHOP_PHONE   : '',
        'email'         => defined('SHOP_EMAIL')   ? (string)SHOP_EMAIL   : '',
        'website'       => defined('SITE_URL')     ? (string)SITE_URL     : '',
        'vat_rate'      => defined('TRADE_VAT_RATE') ? (float)TRADE_VAT_RATE : 0.20,
    ];
}

/**
 * Everything one order's receipt needs, with the money worked out once.
 *
 * Returns null when the order does not exist — the caller sends a 404 rather
 * than printing a blank slip.
 *
 * The returned array:
 *   order     the raw orders row (customer_name, phone, address, postcode,
 *             notes, order_code, created_at, status, payment_status,
 *             payment_method, trade_business_name, vat_number, printed_at,
 *             print_count, ...)
 *   items     normalised lines, each: name, variant, qty, unit_price,
 *             line_total. variant is '' when the product has no sizes.
 *   subtotal  sum of line_total
 *   discount  promo money off
 *   delivery  delivery charge
 *   vat       VAT added on top (0 on every retail order)
 *   total     what the customer was charged (orders.total_price)
 *   meta      cbReceiptMeta() — the shop header
 *   warnings  plain-English lines for the printer to show when something
 *             does not add up. Empty on a healthy order.
 *   is_trade  true when the order belongs to a wholesale account
 *
 * All money is a float already rounded to 2dp.
 */
function cbReceiptData(PDO $pdo, int $orderId): ?array
{
    try {
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = :id");
        $stmt->execute(['id' => $orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('cbReceiptData failed for order ' . $orderId . ': ' . $e->getMessage());
        return null;
    }

    if (!$order) {
        return null;
    }

    $warnings = [];

    // ── The lines ────────────────────────────────────────────
    //
    // json_decode returns null on anything it cannot read, including an empty
    // column. Both are handled the same way: no lines, and a warning that
    // says the totals came off the order rather than out of the basket.
    //
    // The warning is worded for whoever is standing at the till, not for a
    // developer — "Syntax error" on a receipt tells the owner nothing they can
    // act on, and json_last_error_msg() even reports "No error" for a column
    // holding the literal word null. The technical detail goes to the log.
    $json = trim((string)($order['items_json'] ?? ''));
    $raw  = $json === '' ? null : json_decode($json, true);
    if (!is_array($raw)) {
        error_log('cbReceiptData: unreadable items_json on order ' . $orderId
                . ' (' . json_last_error_msg() . ')');
        $warnings[] = 'The item list on this order could not be read, so no items are shown. '
                    . 'The totals below are the ones recorded on the order.';
        $raw = [];
    }

    $items   = [];
    $skipped = 0;
    $lineSum = 0.0;

    foreach ($raw as $line) {
        if (!is_array($line)) {
            $skipped++;
            continue;
        }

        $name = trim((string)($line['name'] ?? ''));
        if ($name === '') {
            $name = 'Item';
        }

        // variant_name is the real column; '' means the product is sold in one
        // size only. `variant` is accepted as well so a line written by any
        // future code path still prints its size.
        $variant = trim((string)($line['variant_name'] ?? ''));
        if ($variant === '') {
            $variant = trim((string)($line['variant'] ?? ''));
        }

        // Quantities on an order are whole tubs — the trade cart adds and
        // removes by the case, never a fraction of one.
        $qty  = max(0, (int)($line['quantity'] ?? $line['qty'] ?? 0));
        $unit = round((float)($line['price'] ?? $line['unit_price'] ?? 0), 2);
        $lineTotal = round($unit * $qty, 2);

        $items[] = [
            'name'       => $name,
            'variant'    => $variant,
            'qty'        => $qty,
            'unit_price' => $unit,
            'line_total' => $lineTotal,
        ];
        $lineSum += $lineTotal;
    }

    if ($skipped > 0) {
        $warnings[] = $skipped . ' line(s) on this order could not be read and are not shown.';
    }

    // ── The money ────────────────────────────────────────────
    $discount = round((float)($order['discount_amount'] ?? 0), 2);
    $delivery = round((float)($order['delivery_charge'] ?? 0), 2);
    $vat      = round((float)($order['vat_amount'] ?? 0), 2);
    $total    = round((float)($order['total_price'] ?? 0), 2);
    $subtotal = round($lineSum, 2);

    if (!$items) {
        // Nothing readable to add up, so work the subtotal back out of the
        // recorded figures. The totals block on the paper then still balances
        // and the warning above explains why there are no lines above it.
        $subtotal = max(0.0, round($total - $vat - $delivery + $discount, 2));
        if (!$warnings) {
            $warnings[] = 'This order has no item lines recorded.';
        }
    } else {
        $expected = round($subtotal - $discount + $delivery + $vat, 2);
        if (abs($expected - $total) >= 0.01) {
            $warnings[] = 'Check this order: the lines add up to £' . number_format($expected, 2)
                        . ' but £' . number_format($total, 2) . ' is recorded against it.';
        }
    }

    return [
        'order'    => $order,
        'items'    => $items,
        'subtotal' => $subtotal,
        'discount' => $discount,
        'delivery' => $delivery,
        'vat'      => $vat,
        'total'    => $total,
        'meta'     => cbReceiptMeta(),
        'warnings' => $warnings,
        'is_trade' => (int)($order['trade_user_id'] ?? 0) > 0,
    ];
}

/**
 * One day's takings, for the summary the owner prints at closing time.
 *
 * $date is 'YYYY-MM-DD'. Orders are counted by the date they were PLACED
 * (created_at), which is the day the money came in over the counter.
 *
 * Nothing is excluded. A cancelled or refunded order is still in the count
 * and still in the gross, and is listed again under `flagged` with what is
 * wrong with it — the owner is reconciling a till drawer against this slip,
 * and an order that quietly vanished from the total is exactly what makes
 * that impossible. `cancelled_total` and `refunded_total` are there to be
 * subtracted by hand where they need to be.
 *
 * The returned array:
 *   date              the normalised 'YYYY-MM-DD'
 *   date_label        'Thu 7 Aug 2026', for the top of the slip
 *   order_count       every order placed that day
 *   gross_total       sum of total_price — what was billed, VAT included
 *   discount_total    sum of discount_amount
 *   delivery_total    sum of delivery_charge
 *   vat_total         sum of vat_amount
 *   net_total         gross_total - vat_total: the shop's own money, with the
 *                     VAT that belongs to HMRC taken back out
 *   by_payment_method [method => ['label','count','total']]
 *   by_payment_status [status => ['label','count','total']]
 *   cancelled_count / cancelled_total   orders with status 'Cancelled'
 *   refunded_count  / refunded_total    orders wholly or partly refunded
 *   flagged           [['id','order_code','customer','total','status',
 *                       'payment_status','flag'], ...]
 *   meta              cbReceiptMeta() — the shop header
 *   warnings          plain-English problems, empty on a clean day
 *
 * All money is a float already rounded to 2dp.
 */
function cbReceiptDailySummary(PDO $pdo, string $date): array
{
    // Accept anything strtotime understands but always report back a real
    // date, so a mistyped query string prints today rather than an empty slip
    // headed '1970-01-01'.
    $stamp = strtotime(trim($date));
    $day   = $stamp !== false ? date('Y-m-d', $stamp) : date('Y-m-d');

    $summary = [
        'date'              => $day,
        'date_label'        => date('D j M Y', strtotime($day)),
        'order_count'       => 0,
        'gross_total'       => 0.0,
        'discount_total'    => 0.0,
        'delivery_total'    => 0.0,
        'vat_total'         => 0.0,
        'net_total'         => 0.0,
        'by_payment_method' => [],
        'by_payment_status' => [],
        'cancelled_count'   => 0,
        'cancelled_total'   => 0.0,
        'refunded_count'    => 0,
        'refunded_total'    => 0.0,
        'flagged'           => [],
        'meta'              => cbReceiptMeta(),
        'warnings'          => [],
    ];

    try {
        $stmt = $pdo->prepare(
            "SELECT id, order_code, customer_name, trade_business_name, total_price,
                    discount_amount, delivery_charge, vat_amount, payment_method,
                    payment_status, status, created_at
               FROM orders
              WHERE DATE(created_at) = :d
              ORDER BY id ASC"
        );
        $stmt->execute(['d' => $day]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log('cbReceiptDailySummary failed for ' . $day . ': ' . $e->getMessage());
        $summary['warnings'][] = 'The day\'s orders could not be read from the database.';
        return $summary;
    }

    $gross = 0.0;
    $disc  = 0.0;
    $del   = 0.0;
    $vat   = 0.0;

    foreach ($rows as $row) {
        $total  = round((float)$row['total_price'], 2);
        $status = (string)$row['status'];
        $pay    = (string)($row['payment_status'] ?? 'Unpaid');
        $method = (string)($row['payment_method'] ?? '');

        $gross += $total;
        $disc  += round((float)$row['discount_amount'], 2);
        $del   += round((float)$row['delivery_charge'], 2);
        $vat   += round((float)$row['vat_amount'], 2);

        // ── by payment method ────────────────────────────────
        $mKey = $method !== '' ? $method : 'unknown';
        if (!isset($summary['by_payment_method'][$mKey])) {
            $summary['by_payment_method'][$mKey] = [
                'label' => match ($mKey) {
                    'online', 'card', 'stripe' => 'Card (online)',
                    'cash'                     => 'Cash',
                    'bank'                     => 'Bank transfer',
                    'later'                    => 'Pay on delivery',
                    default                    => ucfirst($mKey),
                },
                'count' => 0,
                'total' => 0.0,
            ];
        }
        $summary['by_payment_method'][$mKey]['count']++;
        $summary['by_payment_method'][$mKey]['total'] += $total;

        // ── by payment status ────────────────────────────────
        $sKey = $pay !== '' ? $pay : 'Unpaid';
        if (!isset($summary['by_payment_status'][$sKey])) {
            $summary['by_payment_status'][$sKey] = [
                'label' => $sKey === 'Bank' ? 'Bank transfer' : $sKey,
                'count' => 0,
                'total' => 0.0,
            ];
        }
        $summary['by_payment_status'][$sKey]['count']++;
        $summary['by_payment_status'][$sKey]['total'] += $total;

        // ── things that need a second look ───────────────────
        $flag = '';
        if ($status === 'Cancelled') {
            $summary['cancelled_count']++;
            $summary['cancelled_total'] += $total;
            $flag = 'CANCELLED';
        }
        if ($pay === 'Refunded' || $pay === 'Part-refunded') {
            $summary['refunded_count']++;
            $summary['refunded_total'] += $total;
            $flag = $flag !== '' ? $flag . ' / ' . strtoupper($pay) : strtoupper($pay);
        }
        if ($flag !== '') {
            $summary['flagged'][] = [
                'id'             => (int)$row['id'],
                'order_code'     => (string)$row['order_code'],
                'customer'       => trim((string)($row['trade_business_name'] ?? '')) !== ''
                                    ? (string)$row['trade_business_name']
                                    : (string)$row['customer_name'],
                'total'          => $total,
                'status'         => $status,
                'payment_status' => $pay,
                'flag'           => $flag,
            ];
        }
    }

    $summary['order_count']    = count($rows);
    $summary['gross_total']    = round($gross, 2);
    $summary['discount_total'] = round($disc, 2);
    $summary['delivery_total'] = round($del, 2);
    $summary['vat_total']      = round($vat, 2);
    $summary['net_total']      = round($gross - $vat, 2);
    $summary['cancelled_total'] = round($summary['cancelled_total'], 2);
    $summary['refunded_total']  = round($summary['refunded_total'], 2);

    foreach (['by_payment_method', 'by_payment_status'] as $group) {
        foreach ($summary[$group] as $key => $bucket) {
            $summary[$group][$key]['total'] = round($bucket['total'], 2);
        }
    }

    // Biggest takings first — that is the line the owner checks against the
    // drawer, and it should not be buried under a one-order method.
    uasort($summary['by_payment_method'], static fn(array $a, array $b): int => $b['total'] <=> $a['total']);
    uasort($summary['by_payment_status'], static fn(array $a, array $b): int => $b['total'] <=> $a['total']);

    if ($summary['order_count'] === 0) {
        $summary['warnings'][] = 'No orders were placed on this date.';
    }

    return $summary;
}

/**
 * The highest order id the server knows about — the station's starting
 * watermark, so its first load watches for NEW orders instead of printing
 * the archive.
 *
 * Normally MAX(id). When the table is empty it falls back to the highest id
 * the table has ever issued (AUTO_INCREMENT - 1), which matters because
 * clearing the test orders before launch would otherwise reset the watermark
 * to 0 and leave the station polling with a 0 it is not allowed to send.
 * Raising it can never skip a real order: if there are no rows, there is
 * nothing to skip, and the next order is issued an id above this one.
 */
function cbPrintServerMaxId(PDO $pdo): int
{
    $max = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) FROM orders")->fetchColumn();
    if ($max > 0) {
        return $max;
    }

    // Empty table. Shared hosting sometimes restricts information_schema, so
    // this is best-effort — a failure just leaves the watermark at 0.
    try {
        $st = $pdo->prepare(
            "SELECT AUTO_INCREMENT FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders'"
        );
        $st->execute();
        $next = $st->fetchColumn();
        if ($next !== false && $next !== null && (int)$next > 1) {
            return (int)$next - 1;
        }
    } catch (Throwable $e) {
        error_log('Print queue: could not read orders AUTO_INCREMENT — ' . $e->getMessage());
    }

    return 0;
}
