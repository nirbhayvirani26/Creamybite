<?php
// ============================================================
//  Creamy Bite – Mark an order as printed (JSON, POST)
//
//    POST admin/print/mark_printed.php   body: order_id=N
//    -> {"success":true,"order_id":N,"print_count":M}
//
//  printed_at is stamped only the FIRST time — it records when the kitchen
//  copy came off the printer, and a reprint three hours later must not
//  rewrite that. print_count goes up every single time, so the order list can
//  show "printed x3" and the owner can see a receipt was run again.
//
//  Calling it twice for the same order is safe and expected. The station
//  prints FIRST and marks afterwards, so a reply lost to a dropped Wi-Fi
//  connection is retried; the worst that happens is print_count counting a
//  print that did happen. The alternative — mark first, print after — loses
//  the order entirely when the printer is off, and a lost order is far worse
//  than a wasted till roll.
// ============================================================
$GLOBALS['ADMIN_GUARD_JSON'] = true;   // reply in JSON, not an HTML redirect
require_once __DIR__ . '/../_guard.php';
header('Content-Type: application/json');
csrfCheckJson();
require_once __DIR__ . '/../_permissions.php';
adminRequire('orders');

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';

// State-changing, so POST only. A GET says the caller is not doing what it
// thinks it is, and should say so loudly rather than half-working.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'code'    => 'method_not_allowed',
        'message' => 'Marking an order as printed has to be sent as a POST with order_id in the body.',
    ]);
    exit;
}

// Check the raw text, not a cast: (int)"abc" is 0 and an array would fatal.
$raw = $_POST['order_id'] ?? null;
$looksLikeInt = is_string($raw) && preg_match('/^[0-9]{1,18}$/', $raw) === 1;
$orderId = $looksLikeInt ? (int)$raw : 0;

if ($orderId <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'code'    => 'bad_order_id',
        'message' => 'No valid order_id was sent, so nothing was marked as printed.',
    ]);
    exit;
}

try {
    $pdo->beginTransaction();

    // FOR UPDATE so two receipts fired at once cannot read the same count and
    // both write back the same number — and so the count reported below is
    // exactly the one this call produced.
    $sel = $pdo->prepare("SELECT id, order_code, printed_at, print_count FROM orders WHERE id = :id FOR UPDATE");
    $sel->execute(['id' => $orderId]);
    $order = $sel->fetch();

    if (!$order) {
        $pdo->rollBack();
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'code'    => 'not_found',
            'message' => 'There is no order #' . $orderId . ', so there was nothing to mark as printed.',
        ]);
        exit;
    }

    $wasAlreadyPrinted = $order['printed_at'] !== null;

    // COALESCE keeps the original timestamp on a reprint. Doing both columns
    // in one statement means a crash between them is impossible.
    $pdo->prepare(
        "UPDATE orders
            SET printed_at  = COALESCE(printed_at, NOW()),
                print_count = print_count + 1
          WHERE id = :id"
    )->execute(['id' => $orderId]);

    $after = $pdo->prepare("SELECT printed_at, print_count FROM orders WHERE id = :id");
    $after->execute(['id' => $orderId]);
    $now = $after->fetch();

    $pdo->commit();

    $printCount = (int)($now['print_count'] ?? 0);

    echo json_encode([
        'success'     => true,
        'order_id'    => $orderId,
        'order_code'  => (string)$order['order_code'],
        'print_count' => $printCount,
        'printed_at'  => $now['printed_at'] ?? null,
        'reprint'     => $wasAlreadyPrinted,
        'message'     => $wasAlreadyPrinted
            ? 'Receipt for ' . $order['order_code'] . ' printed again (' . $printCount . ' times in total).'
            : 'Receipt for ' . $order['order_code'] . ' printed.',
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    // Left unmarked on purpose: an order that failed to mark stays in the
    // queue and gets picked up again, which is the safe direction to fail in.
    error_log('mark_printed failed for order ' . $orderId . ': ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'code'    => 'server_error',
        'message' => 'Could not record that order #' . $orderId . ' was printed: ' . $e->getMessage(),
    ]);
}
