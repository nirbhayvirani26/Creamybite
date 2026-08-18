<?php
/**
 * Recall list as CSV — the forward trace, for a spreadsheet or an EHO's email.
 *
 * Streamed rather than built in memory, and prefixed with a UTF-8 BOM so Excel
 * on Windows opens the accented names correctly instead of turning them into
 * mojibake, which is what happens without it.
 */

require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_permissions.php';
adminRequire('traceability');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/traceability.php';

$batch = trim((string)($_GET['batch'] ?? ''));
if ($batch === '') {
    http_response_code(400);
    exit('No batch given.');
}

$rows = cbTraceForward($pdo, $batch);

// A filename built from user input is a header-injection risk, so it is
// stripped to a safe shape rather than trusted.
$safe = preg_replace('/[^A-Za-z0-9._-]/', '', $batch) ?: 'batch';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="recall-' . $safe . '-' . date('Ymd-His') . '.csv"');
header('Cache-Control: no-store');

/**
 * fputcsv with the escape character pinned to "" — the same helper the other
 * exports use (see admin/reports.php). PHP 8.4 deprecates relying on the
 * default, and the notice is written straight into the output stream, which
 * for a download means the warning text lands inside the CSV and the recall
 * list opens corrupted. Not a good thing to discover mid-recall.
 */
function cbTrCsv($out, array $fields): void
{
    fputcsv($out, $fields, ',', '"', '');
}

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");

cbTrCsv($out, ['Recall list — batch ' . $batch]);
cbTrCsv($out, ['Generated', date('d/m/Y H:i'), 'by', (string)($_SESSION['admin_username'] ?? 'admin')]);
cbTrCsv($out, ['Business', SHOP_NAME, 'Orders affected', count($rows)]);
cbTrCsv($out, []);
cbTrCsv($out, [
    'Order code', 'Customer type', 'Customer / business', 'Phone', 'Email',
    'Address', 'Postcode', 'Units held', 'Order date', 'Order status',
    'Batch (on tub)', 'Internal batch', 'Made on', 'Best before', 'Contacted (date/time)',
]);

$total = 0;
foreach ($rows as $r) {
    $trade  = (int)$r['trade_user_id'] > 0;
    $total += (int)$r['qty'];
    cbTrCsv($out, [
        $r['order_code'] ?: ('#' . $r['order_id']),
        $trade ? 'Trade' : 'Retail',
        $trade && $r['trade_business_name'] ? $r['trade_business_name'] : $r['customer_name'],
        $r['phone'],
        $r['customer_email'],
        preg_replace('/\s+/', ' ', (string)$r['address']),
        $r['postcode'],
        (int)$r['qty'],
        $r['created_at'] ? date('d/m/Y', strtotime((string)$r['created_at'])) : '',
        $r['status'],
        $r['external_batch'],
        $r['batch_code'],
        $r['produced_on']  ? date('d/m/Y', strtotime((string)$r['produced_on']))  : '',
        $r['best_before']  ? date('d/m/Y', strtotime((string)$r['best_before']))  : '',
        '',   // filled in by hand as each customer is reached
    ]);
}

cbTrCsv($out, []);
cbTrCsv($out, ['Total units in circulation', $total]);
fclose($out);
