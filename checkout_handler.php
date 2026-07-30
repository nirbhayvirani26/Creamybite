<?php
// ============================================================
//  Creamy Bite – Checkout Handler (POST only)
// ============================================================
session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';
// Only load vendor if fully installed (autoload.php alone is not enough)
if (file_exists(__DIR__ . '/vendor/composer/autoload_real.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

// ── Auto-migrate: ensure required columns exist ────────────────
try {
    $colCheck = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = ?");
    $colCheck->execute(['postcode']);
    if (!(int)$colCheck->fetchColumn()) {
        $pdo->exec("ALTER TABLE `orders` ADD COLUMN `postcode` VARCHAR(10) NOT NULL DEFAULT '' AFTER `address`");
    }
    $colCheck->execute(['delivery_charge']);
    if (!(int)$colCheck->fetchColumn()) {
        $pdo->exec("ALTER TABLE `orders` ADD COLUMN `delivery_charge` DECIMAL(6,2) NOT NULL DEFAULT 0.00 AFTER `discount_amount`");
    }
    $colCheck->execute(['customer_email']);
    if (!(int)$colCheck->fetchColumn()) {
        $pdo->exec("ALTER TABLE `orders` ADD COLUMN `customer_email` VARCHAR(180) NOT NULL DEFAULT '' AFTER `customer_name`");
    }
} catch (PDOException $e) {
    error_log('Auto-migrate error: ' . $e->getMessage());
}


// ── Delivery charge calculation ───────────────────────────
function calculateDeliveryCharge(string $postcode, float $subtotal): float {
    $shopLat   = 51.5729;
    $shopLon   = -0.3356; // HA1 2SP
    $freeMiles = 3.0;     // Within 3 miles = always free
    $charge    = 1.99;

    $clean = str_replace(' ', '', strtoupper(trim($postcode)));
    $url   = 'https://api.postcodes.io/postcodes/' . urlencode($clean);

    try {
        $ctx  = stream_context_create(['http' => ['timeout' => 4]]);
        $json = @file_get_contents($url, false, $ctx);
        if (!$json) return 0.0;
        $data = json_decode($json, true);
        if (empty($data['result'])) return $charge;

        $lat2  = (float)$data['result']['latitude'];
        $lon2  = (float)$data['result']['longitude'];
        $R     = 3958.8;
        $dLat  = deg2rad($lat2 - $shopLat);
        $dLon  = deg2rad($lon2 - $shopLon);
        $a     = sin($dLat/2)**2 + cos(deg2rad($shopLat)) * cos(deg2rad($lat2)) * sin($dLon/2)**2;
        $miles = $R * 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $miles <= $freeMiles ? 0.0 : $charge;
    } catch (Throwable $e) {
        return 0.0;
    }
}

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: checkout.php');
    exit;
}

// ── Validate cart ─────────────────────────────────────────
$cart = $_SESSION['cart'] ?? [];
if (empty($cart)) {
    header('Location: order.php?empty_cart=1');
    exit;
}

// ── Validate fields ───────────────────────────────────────
$name    = trim($_POST['customer_name']  ?? '');
$email   = trim($_POST['customer_email'] ?? '');
$phone   = trim($_POST['phone']          ?? '');
$address = trim($_POST['address']        ?? '');
$notes   = trim($_POST['notes']          ?? '');
$postcode     = strtoupper(trim($_POST['delivery_postcode'] ?? ''));
$clientCharge = round((float)($_POST['delivery_charge'] ?? 0), 2);
$orderType    = trim($_POST['order_type'] ?? 'delivery');

if ($orderType === 'collection') {
    $postcode = 'HA1 2SP';
    $clientCharge = 0.0;
    $address = 'Collection - Creamy Bite, Unit E5 Phoenix Business centre, HA1 2SP (Collection Time: 11 AM to 8 PM)';
}

// ── Bot protection check ─────────────────────────────────────
if (!empty($_POST['website'])) {
    $_SESSION['checkout_errors'] = ['Order could not be submitted. Please refresh and try again.'];
    header('Location: checkout.php'); exit;
}
$loadedAt = (int)($_POST['form_loaded_at'] ?? 0);
if ($loadedAt > 0 && ((time() * 1000) - $loadedAt) < 2500) {
    $_SESSION['checkout_errors'] = ['Please take a moment to review your order before submitting.'];
    header('Location: checkout.php'); exit;
}

$errors = [];
if (strlen($name)    < 2) $errors[] = 'Please enter your full name.';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email address.';
if (strlen($phone)   < 6) $errors[] = 'Please enter a valid phone number.';

// ── Helper: calculate postcode distance in miles ─────────
function getPostcodeDistanceMiles(string $postcode): ?float {
    $shopLat = 51.5729;
    $shopLon = -0.3356; // HA1 2SP
    $clean   = str_replace(' ', '', strtoupper(trim($postcode)));
    $url     = 'https://api.postcodes.io/postcodes/' . urlencode($clean);

    try {
        $ctx  = stream_context_create(['http' => ['timeout' => 4]]);
        $json = @file_get_contents($url, false, $ctx);
        if (!$json) return null;
        $data = json_decode($json, true);
        if (empty($data['result']['latitude'])) return null;

        $lat2  = (float)$data['result']['latitude'];
        $lon2  = (float)$data['result']['longitude'];
        $R     = 3958.8;
        $dLat  = deg2rad($lat2 - $shopLat);
        $dLon  = deg2rad($lon2 - $shopLon);
        $a     = sin($dLat/2)**2 + cos(deg2rad($shopLat)) * cos(deg2rad($lat2)) * sin($dLon/2)**2;
        return $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
    } catch (Throwable $e) {
        return null;
    }
}

if ($orderType === 'delivery') {
    if (strlen($address) < 5) $errors[] = 'Please enter your delivery address.';
    
    // Validate UK postcode
    if (!preg_match('/^[A-Z]{1,2}[0-9][0-9A-Z]?\s*[0-9][A-Z]{2}$/i', $postcode)) {
        $errors[] = 'Please enter a valid UK delivery postcode.';
    } else if (empty($_SESSION['trade_user'])) {
        // Enforce 6-mile radius limit for retail delivery customers
        $dist = getPostcodeDistanceMiles($postcode);
        if ($dist !== null && $dist > 6.0) {
            $errors[] = 'We currently only deliver within a 6-mile radius of our Harrow warehouse (HA1 2SP / HA1 4EX). Your postcode is ' . number_format($dist, 1) . ' miles away. Please select Warehouse Collection or contact support at +44 7497 779997 for special orders.';
        }
    }
}

// ── Minimum order check (delivery only) ──────────────────
if ($orderType === 'delivery') {
    $minOrderValue = 10.00;
    $preCheckSubtotal = 0.0;
    foreach (($_SESSION['cart'] ?? []) as $item) {
        $preCheckSubtotal += $item['price'] * $item['quantity'];
    }
    if ($preCheckSubtotal < $minOrderValue) {
        $_SESSION['checkout_errors'] = ['Minimum order for delivery is £10.00. Please add more items to your cart.'];
        header('Location: checkout.php'); exit;
    }
}

if (!empty($errors)) {
    $_SESSION['checkout_errors'] = $errors;
    header('Location: checkout.php');
    exit;
}

// ── Build items array ─────────────────────────────────────
$subtotal = 0.0;
$items    = [];
foreach ($cart as $item) {
    $subtotal += $item['price'] * $item['quantity'];
    $items[] = [
        'cart_key'     => $item['cart_key']     ?? (string)$item['product_id'],
        'product_id'   => $item['product_id'],
        'variant_id'   => $item['variant_id']   ?? null,
        'variant_name' => $item['variant_name'] ?? '',
        'name'         => $item['name'],
        'emoji'        => $item['emoji'],
        'image'        => $item['image']        ?? '',
        'price'        => $item['price'],
        'quantity'     => $item['quantity'],
    ];
}

// ── Apply promo if one is in session (retail only) ───────
if (!empty($_SESSION['trade_user'])) {
    unset($_SESSION['promo']);
}
$promo          = $_SESSION['promo'] ?? null;
$discountAmount = 0.0;
$promoCode      = null;

if ($promo) {
    $pStmt = $pdo->prepare("SELECT * FROM promo_codes WHERE id = :id AND code = :code AND active = 1");
    $pStmt->execute(['id' => $promo['id'], 'code' => $promo['code']]);
    $promoRow = $pStmt->fetch();

    if ($promoRow &&
        (is_null($promoRow['max_uses']) || $promoRow['uses_count'] < $promoRow['max_uses']) &&
        (empty($promoRow['expires_at'])  || strtotime($promoRow['expires_at']) >= strtotime('today')) &&
        $subtotal >= (float)$promoRow['min_order']
    ) {
        if ($promoRow['discount_type'] === 'percentage') {
            $discountAmount = round($subtotal * ($promoRow['discount_value'] / 100), 2);
        } else {
            $discountAmount = min((float)$promoRow['discount_value'], $subtotal);
        }
        $promoCode = $promoRow['code'];
    }
}

$total = max(0, $subtotal - $discountAmount);

// ── Calculate delivery charge ────────────────────────
$deliveryCharge = 0.0;
if ($orderType === 'delivery' && !empty($postcode) && preg_match('/^[A-Z]{1,2}[0-9][0-9A-Z]?\s*[0-9][A-Z]{2}$/i', $postcode)) {
    $deliveryCharge = calculateDeliveryCharge($postcode, $subtotal - $discountAmount);
}
$total = max(0, $subtotal - $discountAmount + $deliveryCharge);

// ── Payment method ────────────────────────────────────────
$paymentMethod = $_POST['payment_method'] ?? 'later'; // 'online' or 'later'
$paymentStatus = 'Unpaid';
$stripeIntentId = null;

if ($paymentMethod === 'online') {
    // Verify the Stripe PaymentIntent was actually paid
    $stripeIntentId = $_SESSION['stripe_intent_id'] ?? null;
    if ($stripeIntentId && class_exists('\Stripe\Stripe')) {
        try {
            \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);
            $intent = \Stripe\PaymentIntent::retrieve($stripeIntentId);
            if ($intent->status === 'succeeded') {
                $paymentStatus = 'Paid';
            } else {
                // Payment not confirmed — fall back to later
                $paymentMethod = 'later';
                $paymentStatus = 'Unpaid';
            }
        } catch (Exception $e) {
            error_log('Stripe verify error: ' . $e->getMessage());
            $paymentMethod = 'later';
            $paymentStatus = 'Unpaid';
        }
    }
    unset($_SESSION['stripe_intent_id'], $_SESSION['stripe_intent_amount']);
}

// ── Generate unique order code ────────────────────────────
$orderCode = 'CB-' . random_int(100000, 999999);

// ── Trade B2B User Info ───────────────────────────────────
$tradeUserId       = 0;
$tradeBusinessName = '';
if (!empty($_SESSION['trade_user'])) {
    $tradeUserId       = (int)($_SESSION['trade_user']['id'] ?? 0);
    $tradeBusinessName = trim($_SESSION['trade_user']['business_name'] ?? '');
    if (!empty($tradeBusinessName) && strpos($notes, 'TRADE B2B ORDER') === false) {
        $notes = '[🏪 TRADE B2B ORDER - Store: ' . $tradeBusinessName . ']' . "\n" . $notes;
    }
}

// ── Save order to DB ──────────────────────────────────────
try {
    // Try with trade_user_id, trade_business_name & customer_email columns
    $stmt = $pdo->prepare("INSERT INTO orders
        (order_code, trade_user_id, trade_business_name, customer_name, customer_email, phone, address, notes, items_json, total_price, promo_code, discount_amount, payment_method, payment_status, postcode, delivery_charge, status)
        VALUES (:order_code, :trade_user_id, :trade_business_name, :customer_name, :customer_email, :phone, :address, :notes, :items_json, :total_price, :promo_code, :discount_amount, :payment_method, :payment_status, :postcode, :delivery_charge, 'Pending')");

    $stmt->execute([
        'order_code'          => $orderCode,
        'trade_user_id'       => $tradeUserId,
        'trade_business_name' => $tradeBusinessName,
        'customer_name'       => $name,
        'customer_email'      => $email,
        'phone'               => $phone,
        'address'             => $address,
        'notes'               => $notes,
        'items_json'          => json_encode($items),
        'total_price'         => $total,
        'promo_code'          => $promoCode,
        'discount_amount'     => $discountAmount,
        'payment_method'      => $paymentMethod,
        'payment_status'      => $paymentStatus,
        'postcode'            => $postcode,
        'delivery_charge'     => $deliveryCharge,
    ]);

} catch (PDOException $e) {
    // Fallback if trade columns aren't created yet
    try {
        $stmt2 = $pdo->prepare("INSERT INTO orders
            (order_code, customer_name, customer_email, phone, address, notes, items_json, total_price, promo_code, discount_amount, payment_method, payment_status, postcode, delivery_charge, status)
            VALUES (:order_code, :customer_name, :customer_email, :phone, :address, :notes, :items_json, :total_price, :promo_code, :discount_amount, :payment_method, :payment_status, :postcode, :delivery_charge, 'Pending')");
        $stmt2->execute([
            'order_code'      => $orderCode,
            'customer_name'   => $name,
            'customer_email'  => $email,
            'phone'           => $phone,
            'address'         => $address,
            'notes'           => $notes,
            'items_json'      => json_encode($items),
            'total_price'     => $total,
            'promo_code'      => $promoCode,
            'discount_amount' => $discountAmount,
            'payment_method'  => $paymentMethod,
            'payment_status'  => $paymentStatus,
            'postcode'        => $postcode,
            'delivery_charge' => $deliveryCharge,
        ]);
    } catch (PDOException $e2) {
        $_SESSION['checkout_errors'] = ['Sorry, we could not place your order. Please try again.'];
        error_log("Order save error: " . $e2->getMessage());
        header('Location: checkout.php');
        exit;
    }
}

// ── Post-save: promo uses count ──────────────────────────
try {
    // Increment promo uses_count
    if ($promo && $promoCode) {
        $pdo->prepare("UPDATE promo_codes SET uses_count = uses_count + 1 WHERE id = :id")
            ->execute(['id' => $promo['id']]);
    }
    // NOTE: Stock is NOT deducted at checkout.
    // Stock is deducted when the admin marks an order as "Delivered".
} catch (PDOException $e) {
    error_log("Post-save error: " . $e->getMessage()); // non-fatal
}


// ── Send emails ───────────────────────────────────────────
$orderRow = [
    'order_code'      => $orderCode,
    'customer_name'   => $name,
    'phone'           => $phone,
    'address'         => $address,
    'notes'           => $notes,
    'items_json'      => json_encode($items),
    'total_price'     => $total,
    'promo_code'      => $promoCode,
    'discount_amount' => $discountAmount,
    'created_at'      => date('Y-m-d H:i:s'),
    'payment_status'  => $paymentStatus,
    'payment_method'  => $paymentMethod,
    'customer_email'  => $email,
];

try { sendOrderEmail($orderRow); } catch (Exception $e) { error_log("Admin email failed: " . $e->getMessage()); }
if (!empty($email)) {
    try { sendCustomerConfirmationEmail($orderRow, $email); } catch (Exception $e) { error_log("Customer email failed: " . $e->getMessage()); }
}

// ── Clear cart + promo session ────────────────────────────
$_SESSION['cart'] = [];
unset($_SESSION['promo']);

// ── Redirect to confirmation ──────────────────────────────
header('Location: order_confirmation.php?code=' . urlencode($orderCode));
exit;
