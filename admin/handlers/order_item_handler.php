<?php
// ============================================================
//  Creamy Bite – Admin: amend the items on a placed order
//
//  Used when an item cannot be supplied ("we don't have 1L tubs today").
//  Removing a line does five things, and all of them matter:
//
//    1. drops the line from items_json
//    2. recalculates the order's subtotal, VAT and total
//    3. returns the quantity to stock (only if the order had deducted it)
//    4. writes what was removed, why, and by how much the total changed
//       into the order notes, so the delivery note and the admin history
//       both show it
//    5. emails the customer, and amends a linked DRAFT invoice
//
//  A paid card order is deliberately NOT auto-refunded here: taking money
//  back is a decision for a human. The shortfall is flagged instead.
// ============================================================
$GLOBALS['ADMIN_GUARD_JSON'] = true;   // reply in JSON, not a redirect
require_once __DIR__ . '/../_guard.php';
header('Content-Type: application/json');
csrfCheckJson();
require_once __DIR__ . '/../_permissions.php';
adminRequire('orders');

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/stock.php';
require_once __DIR__ . '/../../includes/invoice.php';
require_once __DIR__ . '/../../includes/mailer.php';

$action = $_POST['action'] ?? '';

if ($action !== 'remove_item') {
    echo json_encode(['success' => false, 'message' => 'Unknown action']);
    exit;
}

$orderId  = (int)($_POST['order_id'] ?? 0);
$itemIdx  = (int)($_POST['item_index'] ?? -1);
$reason   = trim($_POST['reason'] ?? '');
$notify   = !empty($_POST['notify']);

if ($orderId <= 0 || $itemIdx < 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid order or item.']);
    exit;
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = :id FOR UPDATE");
    $stmt->execute(['id' => $orderId]);
    $order = $stmt->fetch();

    if (!$order) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Order not found.']);
        exit;
    }

    $items = json_decode($order['items_json'] ?? '', true) ?? [];
    if (!isset($items[$itemIdx])) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'That item is no longer on the order.']);
        exit;
    }
    if (count($items) <= 1) {
        $pdo->rollBack();
        echo json_encode([
            'success' => false,
            'message' => 'This is the only item on the order. Cancel the whole order instead — that refunds the stock and tells the customer.',
        ]);
        exit;
    }

    $removed     = $items[$itemIdx];
    $removedQty  = (int)($removed['quantity'] ?? 0);
    $removedName = trim(($removed['name'] ?? 'Item') . ' ' . ($removed['variant_name'] ?? ''));
    $lineValue   = round((float)($removed['price'] ?? 0) * $removedQty, 2);

    // ── 1. drop the line ─────────────────────────────────────
    array_splice($items, $itemIdx, 1);

    // ── 2. recalculate the money ─────────────────────────────
    $subtotal = 0.0;
    foreach ($items as $it) {
        $subtotal += (float)$it['price'] * (int)$it['quantity'];
    }
    $subtotal = round($subtotal, 2);

    // Keep the discount, but never let it exceed the reduced subtotal.
    $discount = min((float)$order['discount_amount'], $subtotal);
    $delivery = (float)$order['delivery_charge'];
    $base     = max(0.0, round($subtotal - $discount + $delivery, 2));

    // VAT only if this order was charged VAT in the first place.
    $vatRate   = ((float)($order['vat_amount'] ?? 0) > 0) ? TRADE_VAT_RATE : 0.0;
    $vatAmount = round($base * $vatRate, 2);
    $newTotal  = round($base + $vatAmount, 2);
    $oldTotal  = (float)$order['total_price'];

    // ── 3. stock back, only if this order had taken it ───────
    $stockReturned = false;
    if (!empty($order['stock_deducted']) && $removedQty > 0) {
        restoreStock($pdo, stockRequirements([$removed]));
        $stockReturned = true;
    }

    // ── 4. record it on the order ────────────────────────────
    $stamp = date('d M Y H:i');
    $note  = "[ITEM REMOVED {$stamp}] {$removedName} x{$removedQty} (−£"
           . number_format($lineValue, 2) . ")"
           . ($reason !== '' ? " — reason: {$reason}" : '')
           . "\nOrder total £" . number_format($oldTotal, 2) . " → £" . number_format($newTotal, 2);

    $newNotes = trim($note . "\n\n" . (string)$order['notes']);

    $pdo->prepare(
        "UPDATE orders
            SET items_json = :items, total_price = :total, discount_amount = :disc,
                vat_amount = :vat, notes = :notes
          WHERE id = :id"
    )->execute([
        'items' => json_encode(array_values($items)),
        'total' => $newTotal,
        'disc'  => $discount,
        'vat'   => $vatAmount,
        'notes' => $newNotes,
        'id'    => $orderId,
    ]);

    // ── 5. amend a linked draft invoice ──────────────────────
    $invoiceNote = '';
    $inv = $pdo->prepare("SELECT id, invoice_number, status FROM invoices WHERE order_id = :o AND status <> 'void' LIMIT 1");
    $inv->execute(['o' => $orderId]);
    if ($row = $inv->fetch()) {
        if ($row['status'] === 'draft') {
            // Safe to rebuild a draft: it has not been sent to anyone.
            $pdo->prepare("DELETE FROM invoice_items WHERE invoice_id = :i")->execute(['i' => (int)$row['id']]);
            $ins = $pdo->prepare(
                "INSERT INTO invoice_items (invoice_id, description, rate, qty, amount, sort_order)
                 VALUES (:inv, :d, :r, :q, :a, :s)"
            );
            $sort = 0;
            foreach ($items as $it) {
                $desc = trim((string)($it['name'] ?? 'Item'));
                if (!empty($it['variant_name'])) {
                    $desc .= ' ' . $it['variant_name'];
                }
                $rate = (float)$it['price'];
                $qty  = (int)$it['quantity'];
                $ins->execute([
                    'inv' => (int)$row['id'], 'd' => $desc, 'r' => $rate,
                    'q' => $qty, 'a' => round($rate * $qty, 2), 's' => ++$sort,
                ]);
            }
            recalcInvoice($pdo, (int)$row['id']);
            $invoiceNote = 'Draft invoice ' . $row['invoice_number'] . ' updated.';
        } else {
            // An issued invoice is a document of record — never rewrite it.
            $invoiceNote = 'Invoice ' . $row['invoice_number'] . ' has already been issued and was NOT changed. '
                         . 'Issue a credit note for £' . number_format($lineValue, 2) . '.';
        }
    }

    $pdo->commit();

    // ── Tell the customer ────────────────────────────────────
    $emailNote = '';
    if ($notify && !empty($order['customer_email'])) {
        $subject = 'Update to your order ' . $order['order_code'];
        $body = '<p>Hello ' . htmlspecialchars($order['customer_name']) . ',</p>'
              . '<p>We are sorry — we could not supply the following item on your order <strong>'
              . htmlspecialchars($order['order_code']) . '</strong>:</p>'
              . '<p style="padding:12px 16px;background:#fff7ef;border-radius:8px;">'
              . '<strong>' . htmlspecialchars($removedName) . '</strong> × ' . $removedQty
              . ($reason !== '' ? '<br><span style="color:#666;">' . htmlspecialchars($reason) . '</span>' : '')
              . '</p>'
              . '<p>Your order total has been adjusted from <strong>£' . number_format($oldTotal, 2)
              . '</strong> to <strong>£' . number_format($newTotal, 2) . '</strong>.</p>'
              . (($order['payment_status'] ?? 'Unpaid') !== 'Unpaid'
                    ? '<p>As your order was already paid, we will refund the difference of <strong>£'
                      . number_format($oldTotal - $newTotal, 2) . '</strong>.</p>'
                    : '<p>The reduced amount is what will be due.</p>')
              . '<p>The rest of your order is unaffected. Sorry again for the inconvenience.</p>'
              . '<p>— ' . SHOP_NAME . '</p>';

        try {
            sendGenericEmail($order['customer_email'], $subject, $body);
            $emailNote = 'Customer notified at ' . $order['customer_email'] . '.';
        } catch (Throwable $e) {
            error_log('Item-removal email failed: ' . $e->getMessage());
            $emailNote = 'Could not email the customer — please contact them directly.';
        }
    }

    $parts = array_filter([
        $removedName . ' removed.',
        $stockReturned ? $removedQty . ' returned to stock.' : 'Stock was not deducted, so nothing to return.',
        'New total £' . number_format($newTotal, 2) . '.',
        $invoiceNote,
        $emailNote,
    ]);

    echo json_encode([
        'success'   => true,
        'message'   => implode(' ', $parts),
        'new_total' => number_format($newTotal, 2),
        'refund_due'=> (($order['payment_status'] ?? 'Unpaid') !== 'Unpaid')
                        ? number_format($oldTotal - $newTotal, 2) : null,
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Order item removal failed: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Could not remove that item: ' . $e->getMessage()]);
}
