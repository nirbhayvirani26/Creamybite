<?php
/**
 * Traceability — link a production batch to an order line, or remove a link.
 *
 * Every rule that matters lives in includes/traceability.php rather than here:
 * that the order exists, that the line is really on it, that the batch is for
 * the same flavour, that a scrapped batch cannot be marked as sold. This file
 * only unpacks the request and reports the answer.
 */

$GLOBALS['ADMIN_GUARD_JSON'] = true;
require_once __DIR__ . '/../_guard.php';
header('Content-Type: application/json');
csrfCheckJson();
require_once __DIR__ . '/../_permissions.php';
adminRequire('traceability');

require_once __DIR__ . '/../../includes/traceability.php';

function cbtrOut(array $payload, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

if (!cbTraceReady($pdo)) {
    cbtrOut([
        'success' => false,
        'message' => 'Traceability is not set up on this server yet. Run the database update once '
                   . '(admin/migrations/update_db.php), then come back.',
    ], 503);
}

$action = trim((string)($_POST['action'] ?? ''));
$user   = (string)($_SESSION['admin_username'] ?? ($_SESSION['staff_name'] ?? 'admin'));

switch ($action) {

    // ── Link a batch to one line of one order ────────────────
    case 'assign': {
        $res = cbTraceAssign(
            $pdo,
            (int)($_POST['order_id'] ?? 0),
            trim((string)($_POST['cart_key'] ?? '')),
            (int)($_POST['run_id'] ?? 0),
            (int)($_POST['qty'] ?? 0),
            $user,
            trim((string)($_POST['notes'] ?? ''))
        );
        cbtrOut(['success' => $res['ok'], 'message' => $res['message']], $res['ok'] ? 200 : 422);
    }

    // ── Remove a link ────────────────────────────────────────
    case 'unassign': {
        $res = cbTraceUnassign($pdo, (int)($_POST['id'] ?? 0));
        cbtrOut(['success' => $res['ok'], 'message' => $res['message']], $res['ok'] ? 200 : 422);
    }

    // ── Which batches could have supplied this line? ─────────
    case 'runs_for': {
        $runs = cbTraceRunsFor(
            $pdo,
            (int)($_POST['product_id'] ?? 0),
            (int)($_POST['variant_id'] ?? 0)
        );
        cbtrOut(['success' => true, 'runs' => $runs]);
    }

    default:
        cbtrOut(['success' => false, 'message' => 'Unknown action.'], 400);
}
