<?php
// ============================================================
//  Creamy Bite – Stripe: Create Payment Intent
//  Called via AJAX before form submit when "Pay Online" chosen
// ============================================================
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

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

// Helper: Calculate delivery charge
function calculateDeliveryCharge(string $postcode): float {
    $shopLat  = 51.5729;
    $shopLon  = -0.3356; // HA1 2SP
    $freeMiles = 3.0;
    $charge    = 1.99;

    $clean = str_replace(' ', '', strtoupper(trim($postcode)));
    $url   = 'https://api.postcodes.io/postcodes/' . urlencode($clean);

    try {
        $ctx  = stream_context_create(['http' => ['timeout' => 4]]);
        $json = @file_get_contents($url, false, $ctx);
        if (!$json) return 0.0;
        $data = json_decode($json, true);
        if (empty($data['result'])) return $charge;

        $lat2 = (float)$data['result']['latitude'];
        $lon2 = (float)$data['result']['longitude'];

        $R    = 3958.8;
        $dLat = deg2rad($lat2 - $shopLat);
        $dLon = deg2rad($lon2 - $shopLon);
        $a    = sin($dLat/2)**2 + cos(deg2rad($shopLat)) * cos(deg2rad($lat2)) * sin($dLon/2)**2;
        $miles = $R * 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $miles <= $freeMiles ? 0.0 : $charge;
    } catch (Throwable $e) {
        return 0.0;
    }
}

// Calculate total (in pence for Stripe)
$cartTotal = 0.0;
foreach ($cart as $item) {
    $cartTotal += $item['price'] * $item['quantity'];
}

// Apply promo if present
$promo          = $_SESSION['promo'] ?? null;
$discountAmount = 0.0;
if ($promo) {
    try {
        $pStmt = $pdo->prepare("SELECT * FROM promo_codes WHERE id = :id AND code = :code AND active = 1");
        $pStmt->execute(['id' => $promo['id'], 'code' => $promo['code']]);
        $promoRow = $pStmt->fetch();
        if ($promoRow &&
            (is_null($promoRow['max_uses']) || $promoRow['uses_count'] < $promoRow['max_uses']) &&
            (empty($promoRow['expires_at'])  || strtotime($promoRow['expires_at']) >= strtotime('today')) &&
            $cartTotal >= (float)$promoRow['min_order']
        ) {
            if ($promoRow['discount_type'] === 'percentage') {
                $discountAmount = round($cartTotal * ($promoRow['discount_value'] / 100), 2);
            } else {
                $discountAmount = min((float)$promoRow['discount_value'], $cartTotal);
            }
        }
    } catch (Exception $e) { }
}

// Calculate delivery charge
$deliveryCharge = 0.0;
$postcode = strtoupper(trim($_REQUEST['postcode'] ?? ''));
$orderType = trim($_REQUEST['order_type'] ?? 'delivery');

if ($orderType === 'delivery' && !empty($postcode) && preg_match('/^[A-Z]{1,2}[0-9][0-9A-Z]?\s*[0-9][A-Z]{2}$/i', $postcode)) {
    $deliveryCharge = calculateDeliveryCharge($postcode);
}

$total    = max(0, $cartTotal - $discountAmount + $deliveryCharge);
$amountPence = (int)round($total * 100); // Stripe works in pence

if ($amountPence < 30) { // Stripe minimum is 30p
    echo json_encode(['error' => 'Order total is too small for card payment.']);
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
                    'promo'      => $promo['code'] ?? '',
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
                'promo'      => $promo['code'] ?? '',
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
