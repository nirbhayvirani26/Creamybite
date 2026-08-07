<?php
// ============================================================
//  Creamy Bite – Print queue (JSON, GET)
//
//  The shop PC's print station polls this every few seconds to ask
//  "anything new to print since order #N?".
//
//    GET admin/print/queue.php?after=N
//    -> {"success":true,"orders":[{"id":12,"order_code":"CB-123456"}],
//        "server_max_id":12}
//
//  Returns orders with id > N that have never been printed, oldest first,
//  at most 20 in one reply.
//
//  WHY 'after' IS REQUIRED, AND WHY 0 IS REFUSED
//  ---------------------------------------------
//  This endpoint's one catastrophic failure mode is answering "everything".
//  A station that asks for orders after #0 gets the entire unprinted history
//  and the counter printer spools out every historical receipt in a row.
//  So there is no default: a missing, empty, negative or non-numeric 'after'
//  is an error, not a 0. A buggy caller doing `Number(saved) || 0` sends a
//  literal 0, which is exactly the bug this guards against, so 0 is refused
//  too — with the single exception below.
//
//  The one exception: a database that has never held an order. There is then
//  no history to dump, and refusing would leave a brand-new shop's station
//  erroring on every poll before it has done anything wrong.
//
//  EVERY reply — including the refusals — carries server_max_id, so a station
//  that has lost its watermark can always re-initialise from a rejected call
//  without ever being handed a backlog.
// ============================================================
$GLOBALS['ADMIN_GUARD_JSON'] = true;   // reply in JSON, not an HTML redirect
require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../../includes/receipt.php';
header('Content-Type: application/json');
csrfCheckJson();
require_once __DIR__ . '/../_permissions.php';
adminRequire('orders');

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';

$serverMaxId = cbPrintServerMaxId($pdo);

// ── Validate 'after' ────────────────────────────────────────────────
//
// Read from the query string only, and check the RAW text rather than
// casting: (int)"abc" is 0, (int)"1.9" is 1, and an array would fatal — all
// of which would quietly turn a broken request into a valid-looking one.
$raw = $_GET['after'] ?? null;
$looksLikeInt = is_string($raw) && preg_match('/^[0-9]{1,18}$/', $raw) === 1;
$after = $looksLikeInt ? (int)$raw : -1;

if (!$looksLikeInt || ($after < 1 && $serverMaxId > 0)) {
    http_response_code(400);
    echo json_encode([
        'success'       => false,
        'code'          => 'bad_after',
        'message'       => "The print station asked for orders without saying which one to start after, so nothing was sent. "
                         . "It should start from order #{$serverMaxId} and only print orders newer than that. "
                         . "Reload the print station page to reset it.",
        'orders'        => [],
        'server_max_id' => $serverMaxId,
    ]);
    exit;
}

// ── The queue itself ────────────────────────────────────────────────
//
// Oldest first so receipts come off the printer in the order they were
// placed. Capped at 20: if the station has been shut for a day, it prints a
// sensible batch and picks up the rest on the next poll 8 seconds later,
// rather than opening 200 iframes at once. Nothing is lost — an unprinted
// order stays unprinted in the database until it is marked.
$stmt = $pdo->prepare(
    "SELECT id, order_code
       FROM orders
      WHERE id > :after
        AND printed_at IS NULL
      ORDER BY id ASC
      LIMIT 20"
);
$stmt->execute(['after' => $after]);

$orders = [];
foreach ($stmt->fetchAll() as $row) {
    $orders[] = [
        'id'         => (int)$row['id'],
        'order_code' => (string)$row['order_code'],
    ];
}

echo json_encode([
    'success'       => true,
    'orders'        => $orders,
    'server_max_id' => $serverMaxId,
]);
