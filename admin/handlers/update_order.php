<?php
// ============================================================
//  Creamy Bite – Admin: Update Order Status / Payment Status / Delete
// ============================================================
$GLOBALS['ADMIN_GUARD_JSON'] = true;   // reply in JSON, not a redirect
require_once __DIR__ . '/../_guard.php';
header('Content-Type: application/json');
csrfCheckJson();

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/mailer.php';
require_once __DIR__ . '/../../includes/stock.php';

// ── Delete Order ──────────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'delete_order') {
    $orderId = (int)($_POST['order_id'] ?? 0);
    if ($orderId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid order ID']);
        exit;
    }
    try {
        // Give the stock back before the row disappears. Deleting an order
        // used to leave its units permanently deducted, so the shop believed
        // it had less stock than it really did and eventually showed sold out
        // while tubs sat in the freezer.
        $chk = $pdo->prepare("SELECT stock_deducted, items_json FROM orders WHERE id = :id");
        $chk->execute(['id' => $orderId]);
        $row = $chk->fetch(PDO::FETCH_ASSOC);

        $returned = 0;
        if ($row && !empty($row['stock_deducted'])) {
            $items = json_decode($row['items_json'] ?? '', true) ?? [];
            $need  = stockRequirements($items);
            restoreStock($pdo, $need);
            $returned = array_sum($need);
        }

        $pdo->prepare("DELETE FROM orders WHERE id = :id")->execute(['id' => $orderId]);

        echo json_encode([
            'success' => true,
            'message' => $returned > 0
                ? 'Order deleted. ' . $returned . ' item(s) returned to stock.'
                : 'Order deleted.',
        ]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

$orderId = (int)($_POST['order_id'] ?? 0);
if ($orderId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid order ID']);
    exit;
}

// ── Update Order Status ───────────────────────────────────
if (isset($_POST['status'])) {
    $status  = trim($_POST['status']);
    $allowed = ['Pending', 'Processing', 'Delivered', 'Cancelled'];

    if (!in_array($status, $allowed)) {
        echo json_encode(['success' => false, 'message' => 'Invalid status']);
        exit;
    }

    // What was it before? Re-saving a status that is already set must not
    // re-fire the side effects — the delivery-note email was being sent
    // again every single time the admin clicked Delivered.
    $prev = $pdo->prepare("SELECT status FROM orders WHERE id = :id");
    $prev->execute(['id' => $orderId]);
    $previousStatus = (string)$prev->fetchColumn();
    $statusChanged  = ($previousStatus !== $status);

    try {
        $pdo->prepare("UPDATE orders SET status = :status WHERE id = :id")
            ->execute(['status' => $status, 'id' => $orderId]);

        // ── Send Delivery Note & Invoice Email on Delivered status ──
        if ($status === 'Delivered' && $statusChanged) {
            try {
                $fullStmt = $pdo->prepare("SELECT * FROM orders WHERE id = :id");
                $fullStmt->execute(['id' => $orderId]);
                $fullOrder = $fullStmt->fetch(PDO::FETCH_ASSOC);
                if ($fullOrder && !empty($fullOrder['customer_email'])) {
                    sendDeliveryNoteEmail($fullOrder);
                }
            } catch (Exception $e) {
                error_log('Delivery note email error: ' . $e->getMessage());
            }

            // Stock is committed when the order is PLACED (checkout_handler.php),
            // not here. Orders placed before that change may still be
            // undeducted, so catch those up rather than double-counting.
            //
            // The old code here also never worked: it incremented sold_online
            // and only decremented stock_qty from the catch block, which never
            // ran because sold_online exists — so stock_qty never moved.
            $chk = $pdo->prepare("SELECT stock_deducted, items_json FROM orders WHERE id = :id");
            $chk->execute(['id' => $orderId]);
            $orderRow = $chk->fetch(PDO::FETCH_ASSOC);

            if ($orderRow && empty($orderRow['stock_deducted'])) {
                $items = json_decode($orderRow['items_json'] ?? '', true) ?? [];
                deductStock($pdo, stockRequirements($items));
                $pdo->prepare("UPDATE orders SET stock_deducted = 1 WHERE id = :id")
                    ->execute(['id' => $orderId]);
            }
        }

        // ── Cancelled: give the stock back ──────────────────
        if ($status === 'Cancelled') {
            $chk = $pdo->prepare("SELECT stock_deducted, items_json FROM orders WHERE id = :id");
            $chk->execute(['id' => $orderId]);
            $orderRow = $chk->fetch(PDO::FETCH_ASSOC);

            if ($orderRow && !empty($orderRow['stock_deducted'])) {
                $items = json_decode($orderRow['items_json'] ?? '', true) ?? [];
                restoreStock($pdo, stockRequirements($items));
                // Clearing the flag means re-Delivering the order deducts once
                // more, keeping the counters correct either way.
                $pdo->prepare("UPDATE orders SET stock_deducted = 0 WHERE id = :id")
                    ->execute(['id' => $orderId]);
            }
        }

        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ── Update Payment Status ─────────────────────────────────
if (isset($_POST['payment_status'])) {
    $ps      = trim($_POST['payment_status']);
    $allowed = ['Unpaid', 'Paid', 'Cash'];

    if (!in_array($ps, $allowed)) {
        echo json_encode(['success' => false, 'message' => 'Invalid payment status']);
        exit;
    }

    // Guard: Online paid orders are locked and cannot have payment status manually changed
    $chkStmt = $pdo->prepare("SELECT payment_status, payment_method FROM orders WHERE id = :id");
    $chkStmt->execute(['id' => $orderId]);
    $currOrder = $chkStmt->fetch(PDO::FETCH_ASSOC);

    // Lock only genuine ONLINE card payments — those are settled with Stripe
    // and must not be edited by hand. The lock used to trigger on
    // payment_status alone, so mis-clicking "Paid" on a cash order made that
    // mistake permanent with no way back.
    $isOnlinePaid = $currOrder
        && $currOrder['payment_status'] === 'Paid'
        && in_array($currOrder['payment_method'] ?? '', ['online', 'card', 'stripe'], true);

    if ($isOnlinePaid) {
        echo json_encode([
            'success' => false,
            'message' => 'This order was paid by card through Stripe, so its payment status is locked. Refund it in Stripe to change this.',
        ]);
        exit;
    }

    try {
        $pdo->prepare("UPDATE orders SET payment_status = :ps WHERE id = :id")
            ->execute(['ps' => $ps, 'id' => $orderId]);

        // Send payment receipt email to customer if newly paid
        if (in_array($ps, ['Paid', 'Cash'])) {
            try {
                $order = $pdo->prepare("SELECT * FROM orders WHERE id = :id");
                $order->execute(['id' => $orderId]);
                $orderRow = $order->fetch();
                if ($orderRow && !empty($orderRow['customer_email'])) {
                    sendPaymentReceiptEmail($orderRow);
                }
            } catch (Exception $e) {
                error_log('Payment receipt email failed: ' . $e->getMessage());
            }
        }

        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Nothing to update']);
