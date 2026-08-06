<?php
// ============================================================
//  Creamy Bite – Admin: Update Order Status / Payment Status / Delete
// ============================================================
$GLOBALS['ADMIN_GUARD_JSON'] = true;   // reply in JSON, not a redirect
require_once __DIR__ . '/../_guard.php';
header('Content-Type: application/json');
csrfCheckJson();
require_once __DIR__ . '/../_permissions.php';
adminRequire('orders');

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/mailer.php';
require_once __DIR__ . '/../../includes/stock.php';
require_once __DIR__ . '/../../includes/invoice.php';   // keep invoices in step with the order

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

// ── Add a delivery person ─────────────────────────────────
//
// Reachable from the "who delivered this?" prompt, because otherwise the
// very first delivery is a dead end: the list starts empty and the only
// place to add a name is buried in the invoice editor. Returns the refreshed
// list so the prompt can select the new person straight away.
if (isset($_POST['action']) && $_POST['action'] === 'add_delivery_rep') {
    $name = trim($_POST['rep_name'] ?? '');
    if ($name === '') {
        echo json_encode(['success' => false, 'message' => 'Enter a name.']);
        exit;
    }
    try {
        $pdo->prepare("INSERT INTO sales_reps (name, phone, email) VALUES (:n, '', '')")
            ->execute(['n' => mb_substr($name, 0, 150)]);
        $newId = (int)$pdo->lastInsertId();
    } catch (PDOException $e) {
        // The name is unique, so re-adding someone reuses the person already
        // on the list rather than splitting their deliveries across two rows.
        if (str_contains($e->getMessage(), 'Duplicate')) {
            $find = $pdo->prepare("SELECT id FROM sales_reps WHERE name = :n");
            $find->execute(['n' => $name]);
            $newId = (int)$find->fetchColumn();
            // They may have been deactivated earlier; bring them back.
            $pdo->prepare("UPDATE sales_reps SET active = 1 WHERE id = :id")->execute(['id' => $newId]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Could not add that name.']);
            exit;
        }
    }
    echo json_encode([
        'success' => true,
        'rep_id'  => $newId,
        'reps'    => $pdo->query("SELECT id, name FROM sales_reps WHERE active = 1 ORDER BY name ASC")
                         ->fetchAll(PDO::FETCH_ASSOC),
    ]);
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

    // ── Who delivered it? ────────────────────────────────────
    //
    // Asked at the moment the order is marked Delivered, because that is the
    // only moment anyone actually knows. Asking later means guessing from a
    // date, and a delivery nobody can attribute is no use in a report.
    //
    // Refused rather than defaulted: picking "the first rep on the list"
    // would quietly credit the wrong person's deliveries, which is worse than
    // an error message. The reply carries the rep list so the page can put up
    // the picker without a second request.
    $repId = (int)($_POST['sales_rep_id'] ?? 0);

    if ($status === 'Delivered' && $statusChanged && $repId <= 0) {
        $reps = $pdo->query("SELECT id, name FROM sales_reps WHERE active = 1 ORDER BY name ASC")
                    ->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode([
            'success'   => false,
            'needs_rep' => true,
            'reps'      => $reps,
            'message'   => $reps
                ? 'Who delivered this order?'
                : 'No delivery staff on file yet — add the first one to continue.',
        ]);
        exit;
    }

    // A posted id has to be a real, active person. Otherwise a stale page or
    // a hand-made request could attribute the delivery to an id that is not
    // on the list, and the report would show a blank name against real work.
    if ($repId > 0) {
        $repChk = $pdo->prepare("SELECT COUNT(*) FROM sales_reps WHERE id = :id AND active = 1");
        $repChk->execute(['id' => $repId]);
        if ((int)$repChk->fetchColumn() === 0) {
            echo json_encode(['success' => false, 'message' => 'That delivery person is not on the active list.']);
            exit;
        }
    }

    try {
        $pdo->prepare("UPDATE orders SET status = :status WHERE id = :id")
            ->execute(['status' => $status, 'id' => $orderId]);

        // Recorded only when marking Delivered, so moving an order back to
        // Processing and forward again re-asks rather than keeping a stale
        // name against it.
        if ($status === 'Delivered' && $repId > 0) {
            $pdo->prepare("UPDATE orders SET sales_rep_id = :rep, delivered_at = NOW() WHERE id = :id")
                ->execute(['rep' => $repId, 'id' => $orderId]);
        }

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

        // Send the name back so the row can show who it was credited to
        // without a reload — the point of asking is that it can be checked.
        $repName = '';
        if ($repId > 0) {
            $n = $pdo->prepare("SELECT name FROM sales_reps WHERE id = :id");
            $n->execute(['id' => $repId]);
            $repName = (string)($n->fetchColumn() ?: '');
        }

        echo json_encode(['success' => true, 'rep_name' => $repName]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

// ── Update Payment Status ─────────────────────────────────
if (isset($_POST['payment_status'])) {
    $ps      = trim($_POST['payment_status']);
    $allowed = ['Unpaid', 'Paid', 'Cash', 'Bank'];

    if (!in_array($ps, $allowed)) {
        echo json_encode(['success' => false, 'message' => 'Invalid payment status']);
        exit;
    }

    // Stripe card orders used to be locked here entirely — the only way to
    // change one was to go refund it in Stripe first. Now that a refund can
    // be issued (or logged) right from this page, that hard block would just
    // be in the way. The warning about an unrefunded balance is given
    // client-side instead, as a confirm() before this request is even sent —
    // see updatePaymentStatus() in admin/index.php.

    try {
        $pdo->prepare("UPDATE orders SET payment_status = :ps WHERE id = :id")
            ->execute(['ps' => $ps, 'id' => $orderId]);

        // Send payment receipt email to customer if newly paid
        if (in_array($ps, ['Paid', 'Cash', 'Bank'])) {
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

            // An invoice raised BEFORE the money arrived is now settled, so
            // bring it in step here rather than waiting for someone to notice
            // and mark it by hand. Left alone, the invoice list keeps showing
            // an outstanding balance that has in fact been paid, and the
            // customer keeps holding a bill that says they still owe it.
            try {
                $ivq = $pdo->prepare(
                    "SELECT id FROM invoices
                      WHERE order_id = :o AND status NOT IN ('void', 'paid')"
                );
                $ivq->execute(['o' => $orderId]);

                foreach ($ivq->fetchAll(PDO::FETCH_COLUMN) as $invId) {
                    $invId = (int)$invId;
                    if (!invoiceSettleFromPaidOrder($pdo, $invId)) {
                        continue;   // already had a payment recorded against it
                    }
                    syncInvoicePaymentState($pdo, $invId);

                    // Tell the customer their invoice is settled, and give them
                    // the document — a receipt they cannot open is not a receipt.
                    $inv = loadInvoice($pdo, $invId);
                    if ($inv && trim((string)$inv['to_email']) !== '') {
                        $pdo->prepare("UPDATE invoices SET sent_at = NOW() WHERE id = :id")
                            ->execute(['id' => $invId]);
                        sendInvoiceEmail($inv, invoicePublicUrl($pdo, $inv));
                    }
                }
            } catch (Throwable $e) {
                // Never let invoice housekeeping fail the payment update the
                // admin actually asked for.
                error_log('Invoice sync after payment failed: ' . $e->getMessage());
            }
        }

        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Nothing to update']);
