<?php
// ============================================================
//  Creamy Bite – Stripe: Create Payment Intent
//  Called via AJAX before form submit when "Pay Online" chosen
// ============================================================
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/pricing.php';
require_once __DIR__ . '/includes/stock.php';

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
if ($orderType === 'delivery' && $totals['subtotal'] < MIN_DELIVERY_ORDER) {
    // Flagged rather than just worded, so the page can tell this apart from a
    // real payment failure. The checkout already states the minimum beside the
    // basket and keeps it current as items are added; repeating it inside the
    // card panel meant the customer read the same complaint twice.
    echo json_encode([
        'error'                => 'Minimum order for delivery is £' . number_format(MIN_DELIVERY_ORDER, 2)
                                . '. Please add more items, or choose collection.',
        'basket_below_minimum' => true,
    ]);
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

    // A customer is told the same thing whatever went wrong — the detail is
    // for the shop, not for them. But an expired or revoked key is not a
    // passing glitch: card payment stays broken until someone replaces it,
    // and "please try again" invites a customer to keep failing while the
    // real cause sits unnoticed in a log nobody reads.
    //
    // Stripe revokes live keys automatically when it detects one published
    // somewhere public, so this is a state a working shop really does reach.
    $code  = method_exists($e, 'getStripeCode') ? (string)$e->getStripeCode() : '';
    $isKey = in_array($code, ['api_key_expired', 'invalid_api_key'], true)
          || $e instanceof \Stripe\Exception\AuthenticationException;

    echo json_encode([
        'error' => $isKey
            ? 'Card payment is temporarily unavailable. Please choose Pay Later — your order will still reach us.'
            : 'Payment setup failed. Please try again or choose Pay Later.',
        // Read by the checkout script to fall straight back to Pay Later
        // rather than leaving the customer prodding a form that cannot work.
        'fallback_to_later' => $isKey,
    ]);
}
