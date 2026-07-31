<?php
// ============================================================
//  Creamy Bite – Business reports
//  URL: admin/reports.php?period=this_month&type=summary&format=html
//
//  Periods : this_month | last_month | this_quarter | this_year |
//            last_year | all_time | custom (with from/to)
//  Types   : summary | sales | clients | products | payments | invoices
//  Formats : html  – print-ready page; use the browser's "Save as PDF"
//            csv   – opens straight in Excel / Numbers / Sheets
//
//  On PDF: there is no PDF library installed (vendor/ holds only Stripe), so
//  rather than pull in a dependency the HTML report is laid out for print at
//  A4 and produced through the browser's print-to-PDF — the same route the
//  invoices already use.
// ============================================================
require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

// ── Resolve the reporting period ─────────────────────────────
$period = $_GET['period'] ?? 'this_month';
$today  = date('Y-m-d');

switch ($period) {
    case 'last_month':
        $from = date('Y-m-01', strtotime('first day of last month'));
        $to   = date('Y-m-t',  strtotime('last day of last month'));
        $label = 'Last month (' . date('F Y', strtotime($from)) . ')';
        break;
    case 'this_quarter':
        $q     = (int)ceil((int)date('n') / 3);
        $from  = date('Y-m-01', mktime(0, 0, 0, ($q - 1) * 3 + 1, 1));
        $to    = $today;
        $label = 'Quarter ' . $q . ' ' . date('Y');
        break;
    case 'this_year':
        $from = date('Y-01-01'); $to = $today;
        $label = 'This year (' . date('Y') . ')';
        break;
    case 'last_year':
        $from = date('Y-01-01', strtotime('-1 year'));
        $to   = date('Y-12-31', strtotime('-1 year'));
        $label = 'Last year (' . date('Y', strtotime($from)) . ')';
        break;
    case 'all_time':
        $from = '2000-01-01'; $to = $today;
        $label = 'All time';
        break;
    case 'custom':
        $from = $_GET['from'] ?? date('Y-m-01');
        $to   = $_GET['to']   ?? $today;
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-01');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = $today;
        if ($from > $to) { [$from, $to] = [$to, $from]; }   // tolerate reversed dates
        $label = date('d M Y', strtotime($from)) . ' – ' . date('d M Y', strtotime($to));
        break;
    default:
        $period = 'this_month';
        $from = date('Y-m-01'); $to = $today;
        $label = 'This month (' . date('F Y') . ')';
}

$type   = $_GET['type']   ?? 'summary';
$format = ($_GET['format'] ?? 'html') === 'csv' ? 'csv' : 'html';
$validTypes = ['summary', 'sales', 'clients', 'products', 'payments', 'invoices'];
if (!in_array($type, $validTypes, true)) {
    $type = 'summary';
}

$P = ['f' => $from, 't' => $to];

// ── Gather the data ──────────────────────────────────────────
// Cancelled orders are excluded from money everywhere: the row keeps its
// Paid/Cash status but the cash was refunded or never taken.
$data = [];

try {
    // Orders in range
    $oStmt = $pdo->prepare(
        "SELECT * FROM orders WHERE DATE(created_at) BETWEEN :f AND :t ORDER BY created_at ASC"
    );
    $oStmt->execute($P);
    $orders = $oStmt->fetchAll();

    // Invoices in range
    $iStmt = $pdo->prepare(
        "SELECT i.*, o.order_code
           FROM invoices i
      LEFT JOIN orders o ON o.id = i.order_id
          WHERE i.status <> 'void' AND DATE(i.issue_date) BETWEEN :f AND :t
       ORDER BY i.issue_date ASC, i.id ASC"
    );
    $iStmt->execute($P);
    $invoices = $iStmt->fetchAll();
} catch (PDOException $e) {
    error_log('Report query failed: ' . $e->getMessage());
    $orders = $invoices = [];
}

$paidOrders = array_filter($orders, fn($o) =>
    in_array($o['payment_status'], ['Paid', 'Cash'], true) && $o['status'] !== 'Cancelled');

// ── Summary figures ──────────────────────────────────────────
$sum = [
    'retail' => 0.0, 'retail_n' => 0,
    'trade'  => 0.0, 'trade_n'  => 0,
    'vat'    => 0.0, 'delivery' => 0.0, 'discount' => 0.0,
    'unpaid' => 0.0, 'unpaid_n' => 0,
    'cancelled' => 0.0, 'cancelled_n' => 0,
];
foreach ($orders as $o) {
    $amt = (float)$o['total_price'];
    if ($o['status'] === 'Cancelled') {
        $sum['cancelled'] += $amt; $sum['cancelled_n']++;
        continue;
    }
    if (in_array($o['payment_status'], ['Paid', 'Cash'], true)) {
        $k = ((int)$o['trade_user_id'] > 0) ? 'trade' : 'retail';
        $sum[$k] += $amt; $sum[$k . '_n']++;
        $sum['vat']      += (float)($o['vat_amount'] ?? 0);
        $sum['delivery'] += (float)($o['delivery_charge'] ?? 0);
        $sum['discount'] += (float)($o['discount_amount'] ?? 0);
    } else {
        $sum['unpaid'] += $amt; $sum['unpaid_n']++;
    }
}

// Standalone invoices (not raised from an order) are income no order row
// accounts for. Only the amount actually received counts as revenue.
$sum['inv_direct'] = 0.0; $sum['inv_direct_n'] = 0; $sum['inv_owing'] = 0.0;
foreach ($invoices as $iv) {
    if (empty($iv['order_id'])) {
        $sum['inv_direct'] += (float)$iv['amount_paid'];
        $sum['inv_direct_n']++;
    }
    $sum['inv_owing'] += (float)$iv['total'] - (float)$iv['amount_paid'];
}

$sum['orders_revenue'] = $sum['retail'] + $sum['trade'];
$sum['grand_total']    = $sum['orders_revenue'] + $sum['inv_direct'];
$sum['order_count']    = $sum['retail_n'] + $sum['trade_n'];
$sum['aov']            = $sum['order_count'] > 0 ? $sum['orders_revenue'] / $sum['order_count'] : 0.0;

// ── Clients ──────────────────────────────────────────────────
$clients = [];
foreach ($orders as $o) {
    if ($o['status'] === 'Cancelled') continue;
    $isTrade = (int)$o['trade_user_id'] > 0;
    $key = $isTrade ? 'T' . $o['trade_user_id'] : 'R' . strtolower($o['customer_email'] ?: $o['phone']);
    if (!isset($clients[$key])) {
        $clients[$key] = [
            'name'  => $isTrade ? ($o['trade_business_name'] ?: $o['customer_name']) : $o['customer_name'],
            'type'  => $isTrade ? 'Trade' : 'Retail',
            'email' => $o['customer_email'], 'phone' => $o['phone'],
            'orders' => 0, 'paid' => 0.0, 'unpaid' => 0.0, 'last' => $o['created_at'],
        ];
    }
    $clients[$key]['orders']++;
    if (in_array($o['payment_status'], ['Paid', 'Cash'], true)) {
        $clients[$key]['paid'] += (float)$o['total_price'];
    } else {
        $clients[$key]['unpaid'] += (float)$o['total_price'];
    }
    if ($o['created_at'] > $clients[$key]['last']) $clients[$key]['last'] = $o['created_at'];
}
uasort($clients, fn($a, $b) => $b['paid'] <=> $a['paid']);

// ── Products sold ────────────────────────────────────────────
$products = [];
foreach ($orders as $o) {
    if ($o['status'] === 'Cancelled') continue;
    foreach (json_decode($o['items_json'] ?? '', true) ?? [] as $it) {
        $name = trim(($it['name'] ?? 'Item') . ' ' . ($it['variant_name'] ?? ''));
        if (!isset($products[$name])) $products[$name] = ['qty' => 0, 'revenue' => 0.0, 'orders' => 0];
        $products[$name]['qty']     += (int)($it['quantity'] ?? 0);
        $products[$name]['revenue'] += (float)($it['price'] ?? 0) * (int)($it['quantity'] ?? 0);
        $products[$name]['orders']++;
    }
}
uasort($products, fn($a, $b) => $b['revenue'] <=> $a['revenue']);

// ── Payments ─────────────────────────────────────────────────
$payments = [];
foreach ($orders as $o) {
    if ($o['status'] === 'Cancelled') continue;
    $method = match ($o['payment_method'] ?? 'later') {
        'online', 'card', 'stripe' => 'Card (Stripe)',
        'cash'                     => 'Cash',
        default                    => 'Pay on delivery / invoice',
    };
    $st = $o['payment_status'] ?? 'Unpaid';
    $k  = $method . ' — ' . $st;
    if (!isset($payments[$k])) $payments[$k] = ['method' => $method, 'status' => $st, 'n' => 0, 'total' => 0.0];
    $payments[$k]['n']++;
    $payments[$k]['total'] += (float)$o['total_price'];
}
uasort($payments, fn($a, $b) => $b['total'] <=> $a['total']);

$money = fn($n) => '£' . number_format((float)$n, 2);

// ============================================================
//  CSV
// ============================================================
if ($format === 'csv') {
    $slug = $type . '_' . $from . '_to_' . $to;
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="creamybite_' . $slug . '.csv"');

    $out = fopen('php://output', 'w');
    // BOM so Excel opens UTF-8 (and the £ sign) correctly.
    fwrite($out, "\xEF\xBB\xBF");

    fputcsv($out, [SHOP_NAME . ' — ' . ucfirst($type) . ' report']);
    fputcsv($out, ['Period', $label]);
    fputcsv($out, ['Generated', date('d M Y H:i')]);
    fputcsv($out, []);

    switch ($type) {
        case 'summary':
            fputcsv($out, ['Metric', 'Value']);
            fputcsv($out, ['Retail revenue',              number_format($sum['retail'], 2)]);
            fputcsv($out, ['Retail paid orders',          $sum['retail_n']]);
            fputcsv($out, ['Trade revenue',               number_format($sum['trade'], 2)]);
            fputcsv($out, ['Trade paid orders',           $sum['trade_n']]);
            fputcsv($out, ['Order revenue',               number_format($sum['orders_revenue'], 2)]);
            fputcsv($out, ['Direct invoice revenue',      number_format($sum['inv_direct'], 2)]);
            fputcsv($out, ['Direct invoices',             $sum['inv_direct_n']]);
            fputcsv($out, ['TOTAL REVENUE',               number_format($sum['grand_total'], 2)]);
            fputcsv($out, []);
            fputcsv($out, ['VAT collected',               number_format($sum['vat'], 2)]);
            fputcsv($out, ['Delivery charged',            number_format($sum['delivery'], 2)]);
            fputcsv($out, ['Discounts given',             number_format($sum['discount'], 2)]);
            fputcsv($out, ['Average order value',         number_format($sum['aov'], 2)]);
            fputcsv($out, []);
            fputcsv($out, ['Unpaid orders',               $sum['unpaid_n']]);
            fputcsv($out, ['Unpaid value',                number_format($sum['unpaid'], 2)]);
            fputcsv($out, ['Invoices outstanding',        number_format($sum['inv_owing'], 2)]);
            fputcsv($out, ['Cancelled orders',            $sum['cancelled_n']]);
            fputcsv($out, ['Cancelled value (excluded)',  number_format($sum['cancelled'], 2)]);
            break;

        case 'sales':
            fputcsv($out, ['Order code','Date','Customer','Type','Items','Subtotal','Discount','Delivery','VAT','Total','Payment','Status']);
            foreach ($orders as $o) {
                $items = json_decode($o['items_json'] ?? '', true) ?? [];
                $qty = array_sum(array_column($items, 'quantity'));
                $sub = (float)$o['total_price'] - (float)($o['delivery_charge'] ?? 0)
                     - (float)($o['vat_amount'] ?? 0) + (float)($o['discount_amount'] ?? 0);
                fputcsv($out, [
                    $o['order_code'], date('Y-m-d H:i', strtotime($o['created_at'])),
                    (int)$o['trade_user_id'] > 0 ? ($o['trade_business_name'] ?: $o['customer_name']) : $o['customer_name'],
                    (int)$o['trade_user_id'] > 0 ? 'Trade' : 'Retail',
                    $qty,
                    number_format($sub, 2), number_format((float)($o['discount_amount'] ?? 0), 2),
                    number_format((float)($o['delivery_charge'] ?? 0), 2),
                    number_format((float)($o['vat_amount'] ?? 0), 2),
                    number_format((float)$o['total_price'], 2),
                    $o['payment_status'], $o['status'],
                ]);
            }
            break;

        case 'clients':
            fputcsv($out, ['Customer','Type','Email','Phone','Orders','Paid','Unpaid','Last order']);
            foreach ($clients as $c) {
                fputcsv($out, [$c['name'], $c['type'], $c['email'], $c['phone'], $c['orders'],
                               number_format($c['paid'], 2), number_format($c['unpaid'], 2),
                               date('Y-m-d', strtotime($c['last']))]);
            }
            break;

        case 'products':
            fputcsv($out, ['Product','Qty sold','Revenue','Times ordered']);
            foreach ($products as $name => $p) {
                fputcsv($out, [$name, $p['qty'], number_format($p['revenue'], 2), $p['orders']]);
            }
            break;

        case 'payments':
            fputcsv($out, ['Method','Status','Orders','Total']);
            foreach ($payments as $p) {
                fputcsv($out, [$p['method'], $p['status'], $p['n'], number_format($p['total'], 2)]);
            }
            break;

        case 'invoices':
            fputcsv($out, ['Invoice','Date','Bill to','From order','Total','Paid','Balance','Status']);
            foreach ($invoices as $iv) {
                fputcsv($out, [$iv['invoice_number'], date('Y-m-d', strtotime($iv['issue_date'])),
                               $iv['to_name'], $iv['order_code'] ?: '(direct)',
                               number_format((float)$iv['total'], 2),
                               number_format((float)$iv['amount_paid'], 2),
                               number_format((float)$iv['total'] - (float)$iv['amount_paid'], 2),
                               strtoupper($iv['status'])]);
            }
            break;
    }
    fclose($out);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= ucfirst($type) ?> report – <?= SHOP_NAME ?></title>
<style>
    *{ box-sizing:border-box; }
    body{ margin:0; padding:26px 16px; background:#eef0f3; color:#1f2937; font-size:14px;
          font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Helvetica,Arial,sans-serif; }
    .bar{ max-width:1000px; margin:0 auto 14px; display:flex; gap:10px; flex-wrap:wrap; }
    .btn{ border:none; border-radius:8px; padding:10px 18px; font-size:13px; font-weight:700;
          cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; gap:7px; }
    .btn-p{ background:#5C1D24; color:#fff; } .btn-s{ background:#fff; color:#5C1D24; border:1px solid #d9dde3; }
    .sheet{ max-width:1000px; margin:0 auto; background:#fff; padding:40px 44px;
            box-shadow:0 10px 32px rgba(0,0,0,.09); }
    .head{ display:flex; justify-content:space-between; align-items:flex-start; gap:24px;
           border-bottom:2px solid #5C1D24; padding-bottom:18px; margin-bottom:22px; flex-wrap:wrap; }
    .head img{ max-height:52px; }
    h1{ font-size:22px; margin:0 0 4px; color:#111827; }
    .muted{ color:#6b7280; font-size:12.5px; }
    h2{ font-size:14px; text-transform:uppercase; letter-spacing:.09em; color:#6b7280;
        margin:26px 0 10px; border-bottom:1px solid #eef0f3; padding-bottom:6px; }
    .cards{ display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:12px; margin-bottom:8px; }
    .card{ border:1px solid #eef0f3; border-radius:10px; padding:13px 15px; }
    .card .l{ font-size:10.5px; text-transform:uppercase; letter-spacing:.08em; color:#9ca3af; font-weight:800; }
    .card .v{ font-size:20px; font-weight:800; color:#5C1D24; margin-top:3px; }
    .card .s{ font-size:11px; color:#9ca3af; margin-top:2px; }
    table{ width:100%; border-collapse:collapse; margin-bottom:8px; }
    th{ background:#5C1D24; color:#fff; text-align:left; font-size:10.5px; text-transform:uppercase;
        letter-spacing:.07em; padding:9px 10px; }
    th.r,td.r{ text-align:right; }
    td{ padding:9px 10px; border-bottom:1px solid #f1f3f5; font-size:12.5px; }
    tr:nth-child(even) td{ background:#fbfbfc; }
    tfoot td{ font-weight:800; border-top:2px solid #5C1D24; background:#fff !important; }
    .empty{ text-align:center; color:#9ca3af; padding:26px; }
    @media print{
        body{ background:#fff; padding:0; font-size:11.5px; }
        .sheet{ box-shadow:none; padding:0; max-width:none; }
        .bar{ display:none; }
        th{ -webkit-print-color-adjust:exact; print-color-adjust:exact; }
        table{ page-break-inside:auto; } tr{ page-break-inside:avoid; }
        thead{ display:table-header-group; }   /* repeat headers on each page */
    }
    @page{ size:A4; margin:14mm; }

    /* extracted from inline style attributes */
    .mono-b{ font-family:monospace; font-weight:700 }
    .owed{ color:#b91c1c }
    .r{ text-align:right }
    .foot-note{ margin-top:28px; border-top:1px solid #f1f3f5; padding-top:12px }
    .b{ font-weight:700 }
    .sm{ font-size:11.5px }
    .mono-sm{ font-family:monospace; font-size:11px }
</style>
</head>
<body>

<div class="bar">
    <a href="index.php?tab=revenue" class="btn btn-s">&larr; Back to Revenue</a>
    <button class="btn btn-p" onclick="window.print()">🖨️ Print / Save as PDF</button>
    <a class="btn btn-s" href="?<?= htmlspecialchars(http_build_query(
        ['period'=>$period,'from'=>$from,'to'=>$to,'type'=>$type,'format'=>'csv'])) ?>">⬇️ Download for Excel (CSV)</a>
</div>

<div class="sheet">
    <div class="head">
        <div>
            <?php if (is_file(__DIR__ . '/../assets/images/logo.png')): ?>
            <img src="../assets/images/logo.png" alt="<?= SHOP_NAME ?>">
            <?php else: ?>
            <h1><?= SHOP_NAME ?></h1>
            <?php endif; ?>
        </div>
        <div class="r">
            <h1><?= ucfirst($type) ?> Report</h1>
            <div class="muted"><?= htmlspecialchars($label) ?></div>
            <div class="muted"><?= date('d M Y', strtotime($from)) ?> to <?= date('d M Y', strtotime($to)) ?></div>
            <div class="muted">Generated <?= date('d M Y, H:i') ?></div>
        </div>
    </div>

<?php if ($type === 'summary'): ?>
    <h2>Revenue</h2>
    <div class="cards">
        <div class="card"><div class="l">Total revenue</div><div class="v"><?= $money($sum['grand_total']) ?></div><div class="s">orders + direct invoices</div></div>
        <div class="card"><div class="l">Retail</div><div class="v"><?= $money($sum['retail']) ?></div><div class="s"><?= $sum['retail_n'] ?> paid order(s)</div></div>
        <div class="card"><div class="l">Trade</div><div class="v"><?= $money($sum['trade']) ?></div><div class="s"><?= $sum['trade_n'] ?> paid order(s)</div></div>
        <div class="card"><div class="l">Direct invoices</div><div class="v"><?= $money($sum['inv_direct']) ?></div><div class="s"><?= $sum['inv_direct_n'] ?> invoice(s)</div></div>
    </div>

    <h2>Breakdown</h2>
    <div class="cards">
        <div class="card"><div class="l">VAT collected</div><div class="v"><?= $money($sum['vat']) ?></div></div>
        <div class="card"><div class="l">Delivery charged</div><div class="v"><?= $money($sum['delivery']) ?></div></div>
        <div class="card"><div class="l">Discounts given</div><div class="v"><?= $money($sum['discount']) ?></div></div>
        <div class="card"><div class="l">Average order</div><div class="v"><?= $money($sum['aov']) ?></div></div>
    </div>

    <h2>Owed &amp; excluded</h2>
    <div class="cards">
        <div class="card"><div class="l">Unpaid orders</div><div class="v"><?= $money($sum['unpaid']) ?></div><div class="s"><?= $sum['unpaid_n'] ?> order(s)</div></div>
        <div class="card"><div class="l">Invoices outstanding</div><div class="v"><?= $money($sum['inv_owing']) ?></div></div>
        <div class="card"><div class="l">Cancelled</div><div class="v"><?= $money($sum['cancelled']) ?></div><div class="s"><?= $sum['cancelled_n'] ?> order(s), not counted</div></div>
    </div>

    <h2>Top products</h2>
    <table>
        <thead><tr><th>Product</th><th class="r">Qty</th><th class="r">Revenue</th></tr></thead>
        <tbody>
        <?php $i=0; foreach ($products as $n => $p): if ($i++ >= 10) break; ?>
            <tr><td><?= htmlspecialchars($n) ?></td><td class="r"><?= $p['qty'] ?></td><td class="r"><?= $money($p['revenue']) ?></td></tr>
        <?php endforeach; if (!$products): ?><tr><td colspan="3" class="empty">No sales in this period.</td></tr><?php endif; ?>
        </tbody>
    </table>

<?php elseif ($type === 'sales'): ?>
    <table>
        <thead><tr><th>Order</th><th>Date</th><th>Customer</th><th>Type</th><th class="r">Total</th><th>Payment</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach ($orders as $o): $tr = (int)$o['trade_user_id'] > 0; ?>
            <tr>
                <td class="mono-b"><?= htmlspecialchars($o['order_code']) ?></td>
                <td><?= date('d M Y', strtotime($o['created_at'])) ?></td>
                <td><?= htmlspecialchars($tr ? ($o['trade_business_name'] ?: $o['customer_name']) : $o['customer_name']) ?></td>
                <td><?= $tr ? 'Trade' : 'Retail' ?></td>
                <td class="r"><?= $money($o['total_price']) ?></td>
                <td><?= htmlspecialchars($o['payment_status']) ?></td>
                <td><?= htmlspecialchars($o['status']) ?></td>
            </tr>
        <?php endforeach; if (!$orders): ?><tr><td colspan="7" class="empty">No orders in this period.</td></tr><?php endif; ?>
        </tbody>
        <?php if ($orders): ?>
        <tfoot><tr><td colspan="4">Paid revenue (<?= $sum['order_count'] ?> orders)</td><td class="r"><?= $money($sum['orders_revenue']) ?></td><td colspan="2"></td></tr></tfoot>
        <?php endif; ?>
    </table>

<?php elseif ($type === 'clients'): ?>
    <table>
        <thead><tr><th>Customer</th><th>Type</th><th>Contact</th><th class="r">Orders</th><th class="r">Paid</th><th class="r">Unpaid</th><th>Last order</th></tr></thead>
        <tbody>
        <?php foreach ($clients as $c): ?>
            <tr>
                <td class="b"><?= htmlspecialchars($c['name']) ?></td>
                <td><?= $c['type'] ?></td>
                <td class="sm"><?= htmlspecialchars($c['email']) ?><br><?= htmlspecialchars($c['phone']) ?></td>
                <td class="r"><?= $c['orders'] ?></td>
                <td class="r"><?= $money($c['paid']) ?></td>
                <td class="r"<?= $c['unpaid'] > 0 ? ' class="owed"' : '' ?>><?= $money($c['unpaid']) ?></td>
                <td><?= date('d M Y', strtotime($c['last'])) ?></td>
            </tr>
        <?php endforeach; if (!$clients): ?><tr><td colspan="7" class="empty">No customers in this period.</td></tr><?php endif; ?>
        </tbody>
    </table>

<?php elseif ($type === 'products'): ?>
    <table>
        <thead><tr><th>Product</th><th class="r">Qty sold</th><th class="r">Revenue</th><th class="r">Times ordered</th></tr></thead>
        <tbody>
        <?php $tq=0; $tr2=0.0; foreach ($products as $n => $p): $tq+=$p['qty']; $tr2+=$p['revenue']; ?>
            <tr><td><?= htmlspecialchars($n) ?></td><td class="r"><?= $p['qty'] ?></td><td class="r"><?= $money($p['revenue']) ?></td><td class="r"><?= $p['orders'] ?></td></tr>
        <?php endforeach; if (!$products): ?><tr><td colspan="4" class="empty">No products sold in this period.</td></tr><?php endif; ?>
        </tbody>
        <?php if ($products): ?><tfoot><tr><td>Total</td><td class="r"><?= $tq ?></td><td class="r"><?= $money($tr2) ?></td><td></td></tr></tfoot><?php endif; ?>
    </table>

<?php elseif ($type === 'payments'): ?>
    <table>
        <thead><tr><th>Method</th><th>Status</th><th class="r">Orders</th><th class="r">Total</th></tr></thead>
        <tbody>
        <?php foreach ($payments as $p): ?>
            <tr><td><?= htmlspecialchars($p['method']) ?></td><td><?= htmlspecialchars($p['status']) ?></td><td class="r"><?= $p['n'] ?></td><td class="r"><?= $money($p['total']) ?></td></tr>
        <?php endforeach; if (!$payments): ?><tr><td colspan="4" class="empty">No payments in this period.</td></tr><?php endif; ?>
        </tbody>
    </table>

<?php else: ?>
    <table>
        <thead><tr><th>Invoice</th><th>Date</th><th>Bill to</th><th>Source</th><th class="r">Total</th><th class="r">Paid</th><th class="r">Balance</th><th>Status</th></tr></thead>
        <tbody>
        <?php $it=0.0; $ip=0.0; foreach ($invoices as $iv): $it+=(float)$iv['total']; $ip+=(float)$iv['amount_paid']; ?>
            <tr>
                <td class="mono-b"><?= htmlspecialchars($iv['invoice_number']) ?></td>
                <td><?= date('d M Y', strtotime($iv['issue_date'])) ?></td>
                <td><?= htmlspecialchars($iv['to_name']) ?></td>
                <td class="mono-sm"><?= htmlspecialchars($iv['order_code'] ?: '(direct)') ?></td>
                <td class="r"><?= $money($iv['total']) ?></td>
                <td class="r"><?= $money($iv['amount_paid']) ?></td>
                <td class="r"<?= ((float)$iv['total'] - (float)$iv['amount_paid']) > 0 ? ' class="owed"' : '' ?>><?= $money((float)$iv['total'] - (float)$iv['amount_paid']) ?></td>
                <td><?= strtoupper($iv['status']) ?></td>
            </tr>
        <?php endforeach; if (!$invoices): ?><tr><td colspan="8" class="empty">No invoices in this period.</td></tr><?php endif; ?>
        </tbody>
        <?php if ($invoices): ?><tfoot><tr><td colspan="4">Total</td><td class="r"><?= $money($it) ?></td><td class="r"><?= $money($ip) ?></td><td class="r"><?= $money($it - $ip) ?></td><td></td></tr></tfoot><?php endif; ?>
    </table>
<?php endif; ?>

    <p class="muted foot-note" >
        Cancelled orders are excluded from all revenue figures. Direct-invoice revenue counts
        only what has actually been paid; anything still owed is shown separately.
    </p>
</div>
</body>
</html>
