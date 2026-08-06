<?php
// ============================================================
//  Creamy Bite – Admin: Issue a refund
//
//  Money is only ever moved/recorded here on an explicit click — never as a
//  side effect of another action. Three payment kinds, one shared record and
//  one shared badge so all three look the same to an admin scanning the
//  order list:
//
//    - Card (Stripe): the ceiling on "how much is left to refund" is always
//      read live from Stripe, never trusted from anything stored locally,
//      so it cannot drift even if a refund was ever issued by hand directly
//      in the Stripe dashboard. Stripe actually moves the money.
//    - Cash / Bank transfer: neither has a digital rail this app controls —
//      cash is handed back by a human, a bank transfer is sent by a human
//      from online banking. This just records that it was, against a
//      locally-tracked ceiling (nothing external to ask either of them), so
//      they are exactly as visible on the order as a card refund is.
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

$orderId = (int)($_POST['order_id'] ?? 0);
$amount  = round((float)($_POST['amount'] ?? 0), 2);
$reason  = trim($_POST['reason'] ?? '');

if ($orderId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid order ID.']);
    exit;
}
if ($amount <= 0) {
    echo json_encode(['success' => false, 'message' => 'Enter an amount to refund.']);
    exit;
}

try {
    $pdo->beginTransaction();

    // FOR UPDATE stops a double-click firing two refunds for the same
    // amount before the first one has committed.
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = :id FOR UPDATE");
    $stmt->execute(['id' => $orderId]);
    $order = $stmt->fetch();

    if (!$order) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Order not found.']);
        exit;
    }

    $pi = trim((string)($order['stripe_payment_intent'] ?? ''));
    $isCardPaid = $order['payment_status'] === 'Paid'
        && in_array($order['payment_method'] ?? '', ['online', 'card', 'stripe'], true)
        && $pi !== '';
    // Cash and Bank Transfer are both "paid, but nothing this app can move
    // automatically" — same manual/local path, just different wording below.
    $manualKind = $order['payment_status'] === 'Cash' ? 'cash'
                : ($order['payment_status'] === 'Bank' ? 'bank' : null);

    if (!$isCardPaid && $manualKind === null) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'This order has not been paid, so there is nothing to refund.']);
        exit;
    }

    $sumStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM order_refunds WHERE order_id = :id");
    $sumStmt->execute(['id' => $orderId]);
    $alreadyRefunded = (float)$sumStmt->fetchColumn();

    $stripeRefundId = '';

    if ($isCardPaid) {
        // Only load Stripe when a card payment actually needs one — a cash
        // refund never touches it.
        if (file_exists(__DIR__ . '/../../vendor/composer/autoload_real.php')) {
            require_once __DIR__ . '/../../vendor/autoload.php';
        } else {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'Payment setup unavailable — cannot reach Stripe.']);
            exit;
        }

        // ── Ask Stripe what is actually left to refund ───────
        \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);
        try {
            $intent = \Stripe\PaymentIntent::retrieve($pi, ['expand' => ['latest_charge']]);
            $charge = $intent->latest_charge;
        } catch (\Stripe\Exception\ApiErrorException $e) {
            $pdo->rollBack();
            error_log('Stripe error (refund lookup): ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Could not reach Stripe to check this payment. Please try again shortly.']);
            exit;
        }

        if (!$charge) {
            $pdo->rollBack();
            echo json_encode(['success' => false, 'message' => 'No captured payment was found on Stripe for this order.']);
            exit;
        }

        $remaining = ($charge->amount - $charge->amount_refunded) / 100;
        if ($amount > $remaining + 0.005) {
            $pdo->rollBack();
            echo json_encode([
                'success' => false,
                'message' => 'Only £' . number_format($remaining, 2) . ' remains available to refund on this payment.',
            ]);
            exit;
        }

        try {
            $refund = \Stripe\Refund::create([
                'payment_intent' => $pi,
                'amount'         => (int) round($amount * 100),
                'reason'         => 'requested_by_customer',
                'metadata'       => [
                    'order_code' => $order['order_code'],
                    'note'       => $reason,
                ],
            ]);
        } catch (\Stripe\Exception\ApiErrorException $e) {
            $pdo->rollBack();
            error_log('Stripe error (refund create): ' . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Stripe declined the refund: ' . $e->getMessage()]);
            exit;
        }

        $stripeRefundId = (string)$refund->id;
    } else {
        // Cash / Bank: no external truth to ask, so the ceiling is the
        // order's own total minus whatever has already been logged against it.
        $remaining = (float)$order['total_price'] - $alreadyRefunded;
        if ($amount > $remaining + 0.005) {
            $pdo->rollBack();
            echo json_encode([
                'success' => false,
                'message' => 'Only £' . number_format(max(0, $remaining), 2) . ' is left on this order to record as refunded.',
            ]);
            exit;
        }
    }

    $pdo->prepare(
        "INSERT INTO order_refunds (order_id, amount, reason, stripe_refund_id, created_by)
         VALUES (:o, :a, :r, :sr, :by)"
    )->execute([
        'o'   => $orderId,
        'a'   => $amount,
        'r'   => $reason,
        'sr'  => $stripeRefundId,
        'by'  => adminStaffName(),
    ]);

    $stamp   = date('d M Y H:i');
    $howWord = $manualKind === 'bank' ? 'by bank transfer' : ($manualKind === 'cash' ? 'in cash' : 'via Stripe');
    $note    = "[REFUND {$stamp}] £" . number_format($amount, 2) . " refunded {$howWord}"
             . ($reason !== '' ? " — reason: {$reason}" : '')
             . ($isCardPaid ? " (ref {$stripeRefundId})" : " — recorded by " . adminStaffName());
    $pdo->prepare("UPDATE orders SET notes = :n WHERE id = :id")
        ->execute(['n' => trim($note . "\n\n" . (string)$order['notes']), 'id' => $orderId]);

    $pdo->commit();

    // ── Tell the customer ────────────────────────────────────
    if (!empty($order['customer_email'])) {
        $subject = 'Refund for your order ' . $order['order_code'];
        $howPaid = $isCardPaid
            ? '<p>We have refunded <strong>£' . number_format($amount, 2) . '</strong> to your card for order <strong>'
              . htmlspecialchars($order['order_code']) . '</strong>.</p>'
              . '<p>This usually appears on your statement within 5 to 10 working days, depending on your bank.</p>'
            : '<p>We have refunded <strong>£' . number_format($amount, 2) . '</strong> ' . $howWord . ' for order <strong>'
              . htmlspecialchars($order['order_code']) . '</strong>.</p>';
        $body = '<p>Hello ' . htmlspecialchars($order['customer_name']) . ',</p>'
              . $howPaid
              . ($reason !== '' ? '<p>' . htmlspecialchars($reason) . '</p>' : '')
              . '<p>— ' . SHOP_NAME . '</p>';
        try {
            sendGenericEmail($order['customer_email'], $subject, $body);
        } catch (Throwable $e) {
            error_log('Refund email failed: ' . $e->getMessage());
        }
    }

    $sumStmt->execute(['id' => $orderId]);

    echo json_encode([
        'success'        => true,
        'message'        => '£' . number_format($amount, 2) . ' refund recorded.',
        'refunded_total' => number_format($alreadyRefunded + $amount, 2),
        'manual_kind'    => $manualKind,
    ]);

} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('Refund failed: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Could not process that refund: ' . $e->getMessage()]);
}
