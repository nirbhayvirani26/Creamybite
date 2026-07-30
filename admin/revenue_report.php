<?php
// ============================================================
//  Creamy Bite – Revenue CSV Report Download
//  Usage: admin/revenue_report.php?from=2025-01-01&to=2025-01-31
// ============================================================
session_start();
if (empty($_SESSION['admin_logged_in'])) {
    header('Location: login.php'); exit;
}

require_once '../config.php';
require_once '../db.php';

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
    if (!in_array($order['payment_status'], ['Paid', 'Cash'])) continue;
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
$totalUnpaid  = 0;

foreach ($orders as $order) {
    $items     = json_decode($order['items_json'], true) ?? [];
    $itemsStr  = implode('; ', array_map(fn($i) => $i['name'] . ' x' . $i['quantity'], $items));
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
    
    if ($order['payment_status'] === 'Paid')  { $totalRevenue += $order['total_price']; $totalOnline += $order['total_price']; }
    if ($order['payment_status'] === 'Cash')  { $totalRevenue += $order['total_price']; $totalCash   += $order['total_price']; }
    if ($order['payment_status'] === 'Unpaid') $totalUnpaid  += $order['total_price'];
}

fputcsv($fp, []);
fputcsv($fp, ['', '', '', '', '', '', '', 'TOTAL REVENUE:', '£' . number_format($totalRevenue, 2)]);
fputcsv($fp, ['', '', '', '', '', '', '', 'Online (Card):', '£' . number_format($totalOnline,  2)]);
fputcsv($fp, ['', '', '', '', '', '', '', 'Cash:', '£' . number_format($totalCash,    2)]);
fputcsv($fp, ['', '', '', '', '', '', '', 'Unpaid Total:', '£' . number_format($totalUnpaid,  2)]);

// ── Section 2: Category Breakdown ─────────────────────────
fputcsv($fp, []);
fputcsv($fp, ['=== CATEGORY REVENUE BREAKDOWN ===']);
fputcsv($fp, ['Category', 'Revenue (£)', 'Items Sold']);
arsort($catRevenue);
foreach ($catRevenue as $cat => $data) {
    fputcsv($fp, [$cat, number_format($data['revenue'], 2), $data['qty']]);
}

fclose($fp);
