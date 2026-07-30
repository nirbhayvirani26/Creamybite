<?php
// ============================================================
//  Creamy Bite – Admin: Update Order Status / Payment Status / Delete
// ============================================================
session_start();
header('Content-Type: application/json');

if (empty($_SESSION['admin_logged_in'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

require_once '../config.php';
require_once '../db.php';
require_once '../mailer.php';

// ── Delete Order ──────────────────────────────────────────
if (isset($_POST['action']) && $_POST['action'] === 'delete_order') {
    $orderId = (int)($_POST['order_id'] ?? 0);
    if ($orderId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid order ID']);
        exit;
    }
    try {
        $pdo->prepare("DELETE FROM orders WHERE id = :id")->execute(['id' => $orderId]);
        echo json_encode(['success' => true]);
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

    try {
        $pdo->prepare("UPDATE orders SET status = :status WHERE id = :id")
            ->execute(['status' => $status, 'id' => $orderId]);

        // ── Send Delivery Note & Invoice Email on Delivered status ──
        if ($status === 'Delivered') {
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

            $chk = $pdo->prepare("SELECT stock_deducted, items_json FROM orders WHERE id = :id");
            $chk->execute(['id' => $orderId]);
            $orderRow = $chk->fetch(PDO::FETCH_ASSOC);

            if ($orderRow && empty($orderRow['stock_deducted'])) {
                $items = json_decode($orderRow['items_json'], true) ?? [];
                foreach ($items as $item) {
                    $pid = (int)$item['product_id'];
                    $qty = (int)$item['quantity'];
                    try {
                        // Try new v2 logic: increment sold_online
                        $pdo->prepare(
                            "UPDATE products SET sold_online = sold_online + :qty
                             WHERE id = :id AND track_stock = 1"
                        )->execute(['qty' => $qty, 'id' => $pid]);
                    } catch (PDOException $e) {
                        // Fallback: sold_online column doesn't exist — use old stock_qty decrement
                        try {
                            $pdo->prepare(
                                "UPDATE products SET stock_qty = GREATEST(0, stock_qty - :qty)
                                 WHERE id = :id AND track_stock = 1"
                            )->execute(['qty' => $qty, 'id' => $pid]);
                        } catch (PDOException $e2) {
                            error_log('Stock deduct fallback error: ' . $e2->getMessage());
                        }
                    }
                }
                // Mark order as stock deducted
                try {
                    $pdo->prepare("UPDATE orders SET stock_deducted = 1 WHERE id = :id")
                        ->execute(['id' => $orderId]);
                } catch (PDOException $e) { /* stock_deducted column may not exist yet */ }
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

    if ($currOrder && $currOrder['payment_status'] === 'Paid') {
        echo json_encode(['success' => false, 'message' => 'Online paid orders are locked and cannot have their payment status manually modified.']);
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
