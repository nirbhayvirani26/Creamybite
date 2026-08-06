<?php
// ============================================================
//  Creamy Bite – Revenue CSV Report Download
//  Usage: admin/revenue_report.php?from=2025-01-01&to=2025-01-31
// ============================================================
session_start();
if (empty($_SESSION['admin_logged_in'])) {
    header('Location: login.php'); exit;
}
require_once __DIR__ . '/_permissions.php';
adminRequire('revenue');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to']   ?? date('Y-m-d');

// Validate dates
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-m-01');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to))   $to   = date('Y-m-d');

// Fetch orders in range
$stmt = $pdo->prepare(
    "SELECT * FROM orders WHERE DATE(created_at) BETWEEN :from AND :to ORDER BY created_at DESC"
);
$stmt->execute(['from' => $from, 'to' => $to]);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Build product → category map
$productMap = [];
try {
    $rows = $pdo->query("SELECT id, name, category FROM products")->fetchAll();
    foreach ($rows as $r) $productMap[$r['id']] = $r;
} catch (PDOException $e) {}

// Category revenue breakdown
$catRevenue = [];
foreach ($orders as $order) {
    // Cancelled orders must not count as revenue even if payment_status still
    // says Paid/Cash/Bank — this check was missing here (present everywhere
    // else revenue is totalled), so a cancelled order could still show up in
    // this CSV even after being correctly excluded from every other report.
    if ($order['status'] === 'Cancelled') continue;
    if (!in_array($order['payment_status'], ['Paid', 'Cash', 'Bank'])) continue;
    $items = json_decode($order['items_json'], true) ?? [];
    foreach ($items as $item) {
        $pid = $item['product_id'];
        $cat = $productMap[$pid]['category'] ?? ($item['category'] ?? 'Uncategorised');
        $line = (float)$item['price'] * (int)$item['quantity'];
        if (!isset($catRevenue[$cat])) $catRevenue[$cat] = ['revenue' => 0, 'qty' => 0];
        $catRevenue[$cat]['revenue'] += $line;
        $catRevenue[$cat]['qty']     += (int)$item['quantity'];
    }
}

// Output CSV
$filename = 'revenue_' . $from . '_to_' . $to . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');

$fp = fopen('php://output', 'w');
// BOM for Excel UTF-8
fwrite($fp, "\xEF\xBB\xBF");

// ── Section 1: Orders ─────────────────────────────────────
fputcsv($fp, ['=== ORDERS: ' . $from . ' to ' . $to . ' ===']);
fputcsv($fp, [
    'Date', 'Order Code', 'Customer', 'Phone', 'Status',
    'Payment', 'Postcode', 'Items', 'Subtotal (£)', 'Discount (£)',
    'Delivery (£)', 'Total (£)'
]);

$totalRevenue = 0;
$totalOnline  = 0;
$totalCash    = 0;
$totalBank    = 0;
$totalUnpaid  = 0;

foreach ($orders as $order) {
    $items     = json_decode($order['items_json'], true) ?? [];
    // Include the size — "Rajbhog Ice Cream Tub 1L x2", not just the product.
    $itemsStr  = implode('; ', array_map(
        fn($i) => trim(($i['name'] ?? 'Item') . ' ' . ($i['variant_name'] ?? '')) . ' x' . ($i['quantity'] ?? 0),
        $items
    ));
    $delivery  = (float)($order['delivery_charge'] ?? 0);
    $postcode  = $order['postcode'] ?? '';
    $subtotal  = (float)$order['total_price'] + (float)($order['discount_amount'] ?? 0) - $delivery;
    
    fputcsv($fp, [
        date('d/m/Y H:i', strtotime($order['created_at'])),
        $order['order_code'],
        $order['customer_name'],
        $order['phone'],
        $order['status'],
        $order['payment_status'],
        $postcode,
        $itemsStr,
        number_format($subtotal, 2),
        number_format($order['discount_amount'] ?? 0, 2),
        number_format($delivery, 2),
        number_format($order['total_price'], 2),
    ]);
    
    // Cancelled orders must not count as revenue even though the row keeps
    // its Paid/Cash/Bank payment_status — same rule the category breakdown
    // above already applies, missing here until now.
    if ($order['status'] === 'Cancelled') continue;
    if ($order['payment_status'] === 'Paid')  { $totalRevenue += $order['total_price']; $totalOnline += $order['total_price']; }
    if ($order['payment_status'] === 'Cash')  { $totalRevenue += $order['total_price']; $totalCash   += $order['total_price']; }
    if ($order['payment_status'] === 'Bank')  { $totalRevenue += $order['total_price']; $totalBank   += $order['total_price']; }
    if ($order['payment_status'] === 'Unpaid') $totalUnpaid  += $order['total_price'];
}

// Refunds issued in this period — by when the refund was given, not when the
// original order was placed, since a refund can land weeks after the sale.
$totalRefunded = 0.0;
try {
    $rf = $pdo->prepare(
        "SELECT COALESCE(SUM(amount), 0) FROM order_refunds WHERE DATE(created_at) BETWEEN :f AND :t"
    );
    $rf->execute(['f' => $from, 't' => $to]);
    $totalRefunded = (float)$rf->fetchColumn();
} catch (PDOException $e) {
    $totalRefunded = 0.0;
}

fputcsv($fp, []);
fputcsv($fp, ['', '', '', '', '', '', '', 'TOTAL REVENUE:', '£' . number_format($totalRevenue, 2)]);
fputcsv($fp, ['', '', '', '', '', '', '', 'Online (Card):', '£' . number_format($totalOnline,  2)]);
fputcsv($fp, ['', '', '', '', '', '', '', 'Cash:', '£' . number_format($totalCash,    2)]);
fputcsv($fp, ['', '', '', '', '', '', '', 'Bank Transfer:', '£' . number_format($totalBank,    2)]);
fputcsv($fp, ['', '', '', '', '', '', '', 'Unpaid Total:', '£' . number_format($totalUnpaid,  2)]);
fputcsv($fp, ['', '', '', '', '', '', '', 'Refunded (this period):', '£' . number_format($totalRefunded, 2)]);
fputcsv($fp, ['', '', '', '', '', '', '', 'Net Revenue (after refunds):', '£' . number_format($totalRevenue - $totalRefunded, 2)]);

// ── Section 2: Category Breakdown ─────────────────────────
fputcsv($fp, []);
fputcsv($fp, ['=== CATEGORY REVENUE BREAKDOWN ===']);
fputcsv($fp, ['Category', 'Revenue (£)', 'Items Sold']);
arsort($catRevenue);
foreach ($catRevenue as $cat => $data) {
    fputcsv($fp, [$cat, number_format($data['revenue'], 2), $data['qty']]);
}

fclose($fp);
