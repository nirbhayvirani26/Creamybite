<?php
// ============================================================
//  Creamy Bite – Stripe: Create Payment Intent
//  Called via AJAX before form submit when "Pay Online" chosen
// ============================================================
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/pricing.php';
require_once __DIR__ . '/stock.php';

// Only load vendor if fully installed
if (file_exists(__DIR__ . '/vendor/composer/autoload_real.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} else {
    echo json_encode(['error' => 'Payment setup unavailable. Please choose Pay Later or contact us.']);
    exit;
}

use Stripe\Stripe;
use Stripe\PaymentIntent;

// Cart must exist
$cart = $_SESSION['cart'] ?? [];
if (empty($cart)) {
    echo json_encode(['error' => 'Cart is empty']);
    exit;
}

// Totals come from pricing.php — the same function checkout_handler.php uses
// to write the order. Computing them separately here is how the amount
// charged drifts away from the amount recorded.
$postcode  = strtoupper(trim($_REQUEST['postcode'] ?? ''));
$orderType = trim($_REQUEST['order_type'] ?? 'delivery');

$subtotalForPromo = 0.0;
foreach ($cart as $item) {
    $subtotalForPromo += (float)($item['price'] ?? 0) * (int)($item['quantity'] ?? 0);
}

$promoRow       = validatedPromoRow($pdo, round($subtotalForPromo, 2));
$totals         = computeOrderTotals($cart, $promoRow, $orderType, $postcode);
$discountAmount = $totals['discount'];
$deliveryCharge = $totals['delivery'];
$total          = $totals['total'];
$amountPence    = $totals['total_pence'];   // Stripe works in pence

// ── Refuse to create a charge for a basket checkout will reject ──
// The browser confirms this PaymentIntent BEFORE checkout_handler.php runs,
// so any rule enforced only in the handler takes the customer's money and
// then throws the order away. Mirror those rules here.
if ($orderType === 'delivery' && $totals['subtotal'] < 10.00) {
    echo json_encode(['error' => 'Minimum order for delivery is £10.00. Please add more items to your basket.']);
    exit;
}

$stockShort = stockShortages($pdo, stockRequirements($cart));
if (!empty($stockShort)) {
    echo json_encode(['error' => implode(' ', $stockShort)]);
    exit;
}

if ($amountPence < 30) {
    // Stripe will not take less than 30p. Say so plainly and point at the
    // alternative — this used to return an error the checkout page swallowed,
    // leaving the customer on a "still loading" state that never resolved.
    echo json_encode([
        'error' => 'Card payment needs a total of at least £0.30. Please add another item, or choose "Pay on collection / delivery".',
    ]);
    exit;
}

// Create or Update Payment Intent
try {
    Stripe::setApiKey(STRIPE_SECRET_KEY);

    $stripeIntentId = $_SESSION['stripe_intent_id'] ?? null;
    $intent = null;

    if ($stripeIntentId) {
        try {
            $intent = PaymentIntent::update($stripeIntentId, [
                'amount'   => $amountPence,
                'metadata' => [
                    'shop'       => SHOP_NAME,
                    'promo'      => $promoRow['code'] ?? '',
                    'discount'   => $discountAmount,
                    'postcode'   => $postcode,
                    'order_type' => $orderType,
                ],
            ]);
            $_SESSION['stripe_intent_amount'] = $amountPence;
        } catch (\Stripe\Exception\ApiErrorException $e) {
            // Fallback to create a new one below if update fails
            $stripeIntentId = null;
        }
    }

    if (!$intent) {
        $intent = PaymentIntent::create([
            'amount'               => $amountPence,
            'currency'             => STRIPE_CURRENCY,
            'payment_method_types' => ['card'],
            'metadata'             => [
                'shop'       => SHOP_NAME,
                'promo'      => $promoRow['code'] ?? '',
                'discount'   => $discountAmount,
                'postcode'   => $postcode,
                'order_type' => $orderType,
            ],
        ]);

        // Store intent ID in session for verification after payment
        $_SESSION['stripe_intent_id']     = $intent->id;
        $_SESSION['stripe_intent_amount'] = $amountPence;
    }

    echo json_encode([
        'clientSecret'  => $intent->client_secret,
        'amount'        => number_format($total, 2),
        'publishableKey'=> STRIPE_PUBLISHABLE_KEY,
    ]);

} catch (\Stripe\Exception\ApiErrorException $e) {
    error_log('Stripe error: ' . $e->getMessage());
    echo json_encode(['error' => 'Payment setup failed. Please try again or choose Pay Later.']);
}
