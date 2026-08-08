<?php
// ============================================================
//  Creamy Bite – Checkout Handler (POST only)
// ============================================================
session_start();

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/trade_cart.php';
require_once __DIR__ . '/includes/pricing.php';
require_once __DIR__ . '/includes/stock.php';
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


// Delivery charge, VAT and totals all come from pricing.php so that this
// file, stripe_intent.php and the checkout summary can never disagree.

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: pages/checkout.php');
    exit;
}

// ── Validate cart ─────────────────────────────────────────
$cart = $_SESSION['cart'] ?? [];
if (empty($cart)) {
    header('Location: pages/order.php?empty_cart=1');
    exit;
}

// ── Validate fields ───────────────────────────────────────
$name    = trim($_POST['customer_name']  ?? '');
$email   = trim($_POST['customer_email'] ?? '');
$phone   = trim($_POST['phone']          ?? '');
$address = trim($_POST['address']        ?? '');
$notes   = trim($_POST['notes']          ?? '');
// Set when the postcode lookup was unreachable, so the order can say so.
$distanceUnverified = false;

// Trade customers fill in a separate mandatory "store opening hours &
// delivery instructions" box. Keep both — this used to share the name
// "notes" with the general box below it and was silently overwritten.
$tradeInstructions = trim($_POST['trade_instructions'] ?? '');
if ($tradeInstructions !== '') {
    $notes = "Store hours / delivery instructions:\n" . $tradeInstructions
           . ($notes !== '' ? "\n\nOther notes:\n" . $notes : '');
}
$postcode     = strtoupper(trim($_POST['delivery_postcode'] ?? ''));
$clientCharge = round((float)($_POST['delivery_charge'] ?? 0), 2);
$orderType    = trim($_POST['order_type'] ?? 'delivery');

if ($orderType === 'collection') {
    $postcode = 'HA1 2SP';
    $clientCharge = 0.0;
    $address = 'Collection - Creamy Bite, Unit E5 Phoenix Business centre, HA1 2SP (Collection Time: 11 AM to 8 PM)';
}

// A revoked trade account must not reach checkout at wholesale prices.
tradeSessionRevalidate($pdo);

// ── Has the card already been charged? ───────────────────────
// checkout.php calls stripe.confirmPayment() in the browser and only then
// submits this form, so by the time any rule below runs the money may
// already be captured. Establish that FIRST: from here on, no failure path
// may throw the request away without recording what the customer paid.
$capturedPence  = null;
$stripeIntentId = $_SESSION['stripe_intent_id'] ?? null;
if (($_POST['payment_method'] ?? '') === 'online' && $stripeIntentId && class_exists('\Stripe\Stripe')) {
    try {
        \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);
        $earlyIntent = \Stripe\PaymentIntent::retrieve($stripeIntentId);
        if ($earlyIntent->status === 'succeeded') {
            $capturedPence = (int)$earlyIntent->amount;
        }
    } catch (Exception $e) {
        error_log('Stripe early retrieve failed: ' . $e->getMessage());
    }
}

/**
 * Abandon the checkout and send the customer back with a message.
 * Only ever called when nothing has been charged — a captured payment is
 * recorded for review instead of being discarded.
 */
function abandonCheckout(array $messages): void
{
    $_SESSION['checkout_errors'] = $messages;
    header('Location: pages/checkout.php');
    exit;
}

// Rules that failed. With no payment taken these send the customer back;
// with a payment taken they become review notes on a recorded order.
$errors = [];

// ── Bot protection check ─────────────────────────────────────
if (!empty($_POST['website'])) {
    if ($capturedPence === null) {
        abandonCheckout(['Order could not be submitted. Please refresh and try again.']);
    }
    $errors[] = 'Honeypot field was filled in.';
}
$loadedAt = (int)($_POST['form_loaded_at'] ?? 0);
if ($loadedAt > 0 && ((time() * 1000) - $loadedAt) < 2500) {
    if ($capturedPence === null) {
        abandonCheckout(['Please take a moment to review your order before submitting.']);
    }
    $errors[] = 'Form submitted unusually quickly.';
}

if (strlen($name)    < 2) $errors[] = 'Please enter your full name.';
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Please enter a valid email address.';
if (strlen($phone)   < 6) $errors[] = 'Please enter a valid phone number.';

// ── Helper: calculate postcode distance in miles ─────────
/**
 * Distance to a postcode, or null when it could not be determined.
 *
 * $reason tells the caller WHY it is null, because the two cases need
 * opposite handling: a postcode the lookup says does not exist is a bad
 * address and the order should stop, while the lookup being unreachable is
 * our problem, not the customer's, and refusing every order because a third
 * party is down would be worse than taking one we have to check by hand.
 */
function getPostcodeDistanceMiles(string $postcode, ?string &$reason = null): ?float {
    $shopLat = 51.5729;
    $shopLon = -0.3356; // HA1 2SP
    $clean   = str_replace(' ', '', strtoupper(trim($postcode)));
    $url     = 'https://api.postcodes.io/postcodes/' . urlencode($clean);
    $reason  = null;

    try {
        // ignore_errors keeps the body on a 404. Without it file_get_contents
        // returns false, and "this postcode does not exist" becomes
        // indistinguishable from "the lookup is down" — which matters, because
        // one should stop the order and the other should not.
        $ctx  = stream_context_create(['http' => ['timeout' => 4, 'ignore_errors' => true]]);
        $json = @file_get_contents($url, false, $ctx);

        if ($json === false) { $reason = 'unreachable'; return null; }

        $data = json_decode($json, true);
        if (!is_array($data)) { $reason = 'unreachable'; return null; }

        // postcodes.io answers a bad postcode with {"status":404,...}
        if ((int)($data['status'] ?? 200) === 404 || empty($data['result']['latitude'])) {
            $reason = 'not_found';
            return null;
        }

        $lat2  = (float)$data['result']['latitude'];
        $lon2  = (float)$data['result']['longitude'];
        $R     = 3958.8;
        $dLat  = deg2rad($lat2 - $shopLat);
        $dLon  = deg2rad($lon2 - $shopLon);
        $a     = sin($dLat/2)**2 + cos(deg2rad($shopLat)) * cos(deg2rad($lat2)) * sin($dLon/2)**2;
        $straightLine = $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
        // Straight-line, scaled toward a realistic driving distance — see the
        // constant's own comment in config.php for where 1.3 comes from.
        return $straightLine * DELIVERY_DISTANCE_FACTOR;
    } catch (Throwable $e) {
        $reason = 'unreachable';
        return null;
    }
}

if ($orderType === 'delivery') {
    if (strlen($address) < 5) $errors[] = 'Please enter your delivery address.';
    
    // Validate UK postcode
    if (!preg_match('/^[A-Z]{1,2}[0-9][0-9A-Z]?\s*[0-9][A-Z]{2}$/i', $postcode)) {
        $errors[] = 'Please enter a valid UK delivery postcode.';
    } else if (empty($_SESSION['trade_user'])) {
        // Enforce the 6-mile radius for retail delivery.
        //
        // A null distance used to mean "allowed", so while the lookup was
        // unreachable every postcode in the country passed — and
        // calculateDeliveryCharge returns 0 in the same situation, so those
        // orders were also delivered free. The two failure modes are now
        // separated: a postcode that does not exist is refused, and a lookup
        // we could not reach lets the order through but flags it, because
        // losing every sale while a third party is down is the worse outcome.
        $distReason = null;
        $dist = getPostcodeDistanceMiles($postcode, $distReason);

        if ($dist !== null && $dist > DELIVERY_RADIUS_MILES) {
            $errors[] = 'We currently only deliver within a ' . rtrim(rtrim(number_format(DELIVERY_RADIUS_MILES, 1), '0'), '.')
                      . '-mile radius of our Harrow warehouse (HA1 2SP / HA1 4EX). Your postcode is ' . number_format($dist, 1)
                      . ' miles away. Please select Warehouse Collection or contact support at ' . SHOP_PHONE . ' for special orders.';
        } elseif ($distReason === 'not_found') {
            $errors[] = 'We could not find the postcode ' . htmlspecialchars($postcode)
                      . '. Please check it and try again, or choose Warehouse Collection.';
        } elseif ($distReason === 'unreachable') {
            // Taken, but not silently: staff must confirm it is in range.
            $distanceUnverified = true;
            error_log('Postcode distance unverified for ' . $postcode . ' — lookup unreachable');
        }
    }
}

// ── Minimum order check (delivery only) ──────────────────
if ($orderType === 'delivery') {
    $minOrderValue = MIN_DELIVERY_ORDER;
    $preCheckSubtotal = 0.0;
    foreach (($_SESSION['cart'] ?? []) as $item) {
        $preCheckSubtotal += $item['price'] * $item['quantity'];
    }
    if ($preCheckSubtotal < $minOrderValue) {
        $errors[] = 'Minimum order for delivery is £' . number_format($minOrderValue, 2)
                  . '. Your basket is £' . number_format($preCheckSubtotal, 2)
                  . ' — add £' . number_format($minOrderValue - $preCheckSubtotal, 2)
                  . ' more, or choose collection.';
    }
}

// Nothing charged yet — safe to send them back to fix it.
if (!empty($errors) && $capturedPence === null) {
    abandonCheckout($errors);
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

// ── Totals: one computation, shared with Stripe and the summary ──
// Trade orders never take promo codes — validatedPromoRow() enforces that,
// so the session copy is cleared here to keep the UI honest.
if (!empty($_SESSION['trade_user'])) {
    unset($_SESSION['promo']);
}

$promoRow  = validatedPromoRow($pdo, $subtotal);
$promoCode = $promoRow['code'] ?? null;

$totals         = computeOrderTotals($cart, $promoRow, $orderType, $postcode);
$subtotal       = $totals['subtotal'];
$discountAmount = $totals['discount'];
$deliveryCharge = $totals['delivery'];
$vatAmount      = $totals['vat'];
$vatNumber      = $totals['vat_applies'] ? tradeVatNumber() : '';
$total          = $totals['total'];

// ── Stock check ───────────────────────────────────────────
// stripe_intent.php runs this same check before creating a charge, so for a
// card order this is a second line of defence. The authoritative re-check
// happens under a row lock inside the transaction below.
$stockNeed = stockRequirements($items);
$shortages = stockShortages($pdo, $stockNeed);
if (!empty($shortages)) {
    if ($capturedPence === null) {
        abandonCheckout(array_merge(
            ['Sorry — your basket changed while you were checking out:'],
            $shortages
        ));
    }
    // Already paid: take the order anyway and flag it, rather than keeping
    // the money and shipping nothing.
    $errors = array_merge($errors, $shortages);
}

// ── Payment method ────────────────────────────────────────
$paymentMethod = $_POST['payment_method'] ?? 'later'; // 'online' or 'later'
$paymentStatus = 'Unpaid';

if ($paymentMethod === 'online') {
    if ($capturedPence !== null) {
        // Money is in. Confirm it matches what we are about to record —
        // the basket can change between creating the intent and submitting
        // the form, and only the amount actually captured is real.
        if ($capturedPence === $totals['total_pence']) {
            $paymentStatus = 'Paid';
        } else {
            $paymentStatus = 'Unpaid';
            $errors[] = sprintf(
                'Amount mismatch: card charged £%s but this order totals £%s (difference £%s).',
                number_format($capturedPence / 100, 2),
                number_format($totals['total'], 2),
                number_format(abs($totals['total'] - $capturedPence / 100), 2)
            );
        }
    } else {
        // Nothing was captured — treat as an unpaid order rather than
        // pretending it is paid.
        $paymentMethod = 'later';
        $paymentStatus = 'Unpaid';
    }
    // NOTE: the intent id is deliberately NOT cleared here. It used to be,
    // which meant that if the INSERT below then failed we had taken the
    // customer's money and lost every trace of which payment it belonged to.
    // It is cleared after the order is safely committed instead.
}

// ── An unverified distance is worth saying on the order ─────
// The order is accepted, but nobody should discover at the van that it was
// forty miles away. This is a note, not a rejection.
if ($distanceUnverified) {
    $notes = "[DISTANCE NOT VERIFIED — check this is within " . rtrim(rtrim(number_format(DELIVERY_RADIUS_MILES, 1), '0'), '.') . " miles before dispatch]\n" . $notes;
}

// ── Anything unresolved becomes a review flag on the order ──
$needsReview = !empty($errors);
if ($needsReview) {
    $notes = "[NEEDS REVIEW]\n- " . implode("\n- ", $errors)
           . ($capturedPence !== null
                ? "\n- Card payment captured: £" . number_format($capturedPence / 100, 2)
                : '')
           . "\n\n" . $notes;
    error_log('Checkout recorded for review: ' . implode(' | ', $errors));
}

// ── Generate unique order code ────────────────────────────
$orderCode = ORDER_PREFIX . '-' . random_int(100000, 999999);

// ── Trade B2B User Info ───────────────────────────────────
$tradeUserId       = 0;
$tradeBusinessName = '';
if (!empty($_SESSION['trade_user'])) {
    $tradeUserId       = (int)($_SESSION['trade_user']['id'] ?? 0);
    $tradeBusinessName = trim($_SESSION['trade_user']['business_name'] ?? '');
    // A gift the customer was PROMISED in the basket. computeOrderTotals()
    // returns it, both the cart and the checkout print it ("— On us", "We will
    // pop it in the box"), and until this was added nothing carried it onto the
    // order: the shop packed the box from items_json and the freebie was never
    // mentioned, so the one thing the customer had been told to expect was the
    // one thing missing. It goes in the notes rather than into items_json
    // because a gift is not a priced line and must not disturb the totals or
    // the stock check that has already run against the paid items.
    if (!empty($totals['offer_free_items'])) {
        $giftLines = [];
        foreach ($totals['offer_free_items'] as $gift) {
            $label = trim((string)($gift['label'] ?? ''));
            if ($label === '') {
                $label = trim((string)($gift['offer_name'] ?? 'Free item'));
            }
            $qty = max(1, (int)($gift['qty'] ?? 1));
            $giftLines[] = '- ' . $qty . ' x ' . $label
                         . ' (free, from "' . trim((string)($gift['offer_name'] ?? '')) . '")';
        }
        if ($giftLines !== []) {
            $notes = "[FREE ITEM PROMISED — put this in the box]\n"
                   . implode("\n", $giftLines)
                   . ($notes !== '' ? "\n\n" . $notes : '');
        }
    }

    if (!empty($tradeBusinessName) && strpos($notes, 'TRADE B2B ORDER') === false) {
        $notes = '[TRADE B2B ORDER - Store: ' . $tradeBusinessName . ']' . "\n" . $notes;
    }
}

// ── Save order to DB ──────────────────────────────────────
// One INSERT, no silent fallback. The previous version retried without the
// trade columns when anything went wrong, which quietly recorded a trade
// order as an anonymous retail one instead of surfacing the problem.
try {
    // One transaction: lock the stock rows, re-check availability, write the
    // order, then commit the stock. Without the lock two simultaneous
    // checkouts can both pass the check and both sell the last tub.
    $pdo->beginTransaction();

    $shortages = stockShortages($pdo, $stockNeed, true);
    if (!empty($shortages) && $capturedPence === null) {
        // Nothing charged — send them back rather than accept an order we
        // cannot fulfil.
        $pdo->rollBack();
        abandonCheckout(array_merge(
            ['Sorry — that sold out while you were checking out:'],
            $shortages
        ));
    }
    // If the card was already charged we record the order regardless and let
    // the review flag surface it; stock may go negative, which deductStock()
    // clamps at zero.

    $stmt = $pdo->prepare("INSERT INTO orders
        (order_code, trade_user_id, trade_business_name, customer_name, customer_email, phone, address, notes, items_json, total_price, promo_code, discount_amount, payment_method, stripe_payment_intent, payment_status, postcode, delivery_charge, vat_amount, vat_number, stock_deducted, status)
        VALUES (:order_code, :trade_user_id, :trade_business_name, :customer_name, :customer_email, :phone, :address, :notes, :items_json, :total_price, :promo_code, :discount_amount, :payment_method, :stripe_payment_intent, :payment_status, :postcode, :delivery_charge, :vat_amount, :vat_number, 1, 'Pending')");

    $stmt->execute([
        'order_code'          => $orderCode,
        // Only meaningful for a card order; blank for Pay Later and cash.
        'stripe_payment_intent' => ($paymentMethod === 'online' && $stripeIntentId) ? $stripeIntentId : '',
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
        'vat_amount'          => $vatAmount,
        'vat_number'          => $vatNumber,
    ]);

    // Stock is committed here, with the order, inside the same transaction.
    deductStock($pdo, $stockNeed);

    $pdo->commit();

} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['checkout_errors'] = ['Sorry, we could not place your order. Please try again.'];
    error_log("Order save error: " . $e->getMessage());
    header('Location: pages/checkout.php');
    exit;
}

// ── Post-save: promo uses count ──────────────────────────
try {
    if ($promoRow) {
        // Increment and enforce the limit in ONE statement. Checking
        // uses_count and then incrementing it separately lets two orders
        // placed at the same moment both pass the check and both redeem a
        // single-use code. The WHERE clause makes the database arbitrate.
        $bump = $pdo->prepare(
            "UPDATE promo_codes
                SET uses_count = uses_count + 1
              WHERE id = :id
                AND (max_uses IS NULL OR uses_count < max_uses)"
        );
        $bump->execute(['id' => $promoRow['id']]);

        if ($bump->rowCount() === 0) {
            // Someone took the last use between validation and here. The
            // order stands; the discount is recorded as not granted.
            error_log('Promo ' . $promoRow['code'] . ' hit its usage limit during checkout of ' . $orderCode);
        }
    }
    // NOTE: Stock is NOT deducted at checkout.
    // Stock is deducted when the admin marks an order as "Delivered".
} catch (PDOException $e) {
    error_log("Post-save error: " . $e->getMessage()); // non-fatal
}

// ── Label the Stripe payment with the order code ──────────
//
// The intent is created before the order exists, so its metadata could not
// carry the order code at the time. Writing it back now means the Stripe
// dashboard shows "CB-123456" against the payment, and a refund can be made
// from Stripe against a named order instead of by matching amounts by eye.
//
// Wrapped and non-fatal on purpose: the customer has paid and the order is
// saved. A failure to annotate a payment must never surface as a checkout
// error or take the confirmation page down.
if ($paymentMethod === 'online' && $stripeIntentId && class_exists('\Stripe\Stripe')) {
    try {
        \Stripe\PaymentIntent::update($stripeIntentId, [
            'metadata' => [
                'order_code'    => $orderCode,
                'customer_name' => $name,
                'customer_email'=> $email,
            ],
        ]);
    } catch (\Throwable $e) {
        error_log('Could not tag Stripe intent ' . $stripeIntentId . ' with ' . $orderCode . ': ' . $e->getMessage());
    }
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
    'delivery_charge' => $deliveryCharge,
    'vat_amount'      => $vatAmount,
    'vat_number'      => $vatNumber,
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
// The order is placed, so the trade customer's saved basket goes too.
tradeCartClear($pdo);
$_SESSION['cart'] = [];
unset($_SESSION['promo']);
unset($_SESSION['stripe_intent_id'], $_SESSION['stripe_intent_amount']);
clearDeliveryCache();

// Remember which orders this browser is entitled to view on the confirmation
// page. Order codes are guessable, so the page checks against this list.
$_SESSION['own_order_codes'] = array_slice(
    array_unique(array_merge($_SESSION['own_order_codes'] ?? [], [$orderCode])),
    -20
);

// ── Redirect to confirmation ──────────────────────────────
header('Location: pages/order_confirmation.php?code=' . urlencode($orderCode));
exit;
