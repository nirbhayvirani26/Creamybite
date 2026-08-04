<?php
// ============================================================
//  Creamy Bite – Order Pricing (single source of truth)
//
//  Everything that puts a number in front of a customer or charges
//  them must go through here: the checkout summary, the Stripe payment
//  intent, and the order row itself. They previously each did their own
//  arithmetic, which is how an amount charged can drift from the amount
//  recorded.
//
//  Order of operations:
//      subtotal      = sum(line price x qty)
//      discount      = promo, never more than the subtotal
//      delivery      = postcode-based, 0 for collection
//      taxable base  = subtotal - discount + delivery
//      VAT           = 20% of the base, trade customers with a VAT number only
//      total         = base + VAT
//
//  VAT is charged only to trade partners who have entered a VAT number on
//  their profile. Retail shelf prices are VAT-inclusive, so retail orders
//  never have VAT added on top.
//
//  Requires config.php (TRADE_VAT_RATE) and an open session.
// ============================================================

require_once __DIR__ . '/config.php';

/**
 * Delivery charge for a postcode, in pounds.
 *
 * The result is memoised in the session per postcode. stripe_intent.php and
 * checkout_handler.php each used to call postcodes.io separately with a 4
 * second timeout; if one call succeeded and the other timed out, the customer
 * was charged one amount and the order recorded another. Caching the first
 * answer guarantees a single checkout uses one consistent figure.
 */
function calculateDeliveryCharge(string $postcode, float $subtotal = 0.0): float
{
    $shopLat   = 51.5729;
    $shopLon   = -0.3356;   // HA1 2SP
    $freeMiles = FREE_DELIVERY_MILES;
    $charge    = DELIVERY_CHARGE;

    $clean = str_replace(' ', '', strtoupper(trim($postcode)));
    if ($clean === '') {
        return 0.0;
    }

    if (isset($_SESSION['_delivery_cache'][$clean])) {
        return (float)$_SESSION['_delivery_cache'][$clean];
    }

    $result = 0.0;
    try {
        $url  = 'https://api.postcodes.io/postcodes/' . urlencode($clean);
        $ctx  = stream_context_create(['http' => ['timeout' => 4]]);
        $json = @file_get_contents($url, false, $ctx);

        if (!$json) {
            // Lookup unavailable — do not charge for delivery we cannot verify.
            error_log('Delivery lookup failed for ' . $clean . ' (no response)');
            $result = 0.0;
        } else {
            $data = json_decode($json, true);
            if (empty($data['result'])) {
                $result = $charge;   // unknown postcode: standard charge
            } else {
                $lat2  = (float)$data['result']['latitude'];
                $lon2  = (float)$data['result']['longitude'];
                $R     = 3958.8;
                $dLat  = deg2rad($lat2 - $shopLat);
                $dLon  = deg2rad($lon2 - $shopLon);
                $a     = sin($dLat / 2) ** 2 + cos(deg2rad($shopLat)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
                $miles = $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
                $result = $miles <= $freeMiles ? 0.0 : $charge;
            }
        }
    } catch (Throwable $e) {
        error_log('Delivery lookup error for ' . $clean . ': ' . $e->getMessage());
        $result = 0.0;
    }

    $_SESSION['_delivery_cache'][$clean] = $result;
    return $result;
}

/** Forget cached delivery charges — call once an order completes. */
function clearDeliveryCache(): void
{
    unset($_SESSION['_delivery_cache']);
}

/**
 * Is this order VAT-charged?
 * True only for a logged-in trade partner who has a VAT number on file.
 *
 * NOTE — the VAT number is SELF-DECLARED. The partner types it into their own
 * profile and nothing validates it against HMRC, so in effect they choose
 * whether VAT is added. That is the intended behaviour here, not a hole to
 * plug. If it ever needs tightening, make vat_number admin-editable only, or
 * verify it against the HMRC VAT checking API before honouring it.
 */
function tradeVatApplies(): bool
{
    if (!tradeIsLoggedIn()) {
        return false;
    }
    return trim((string)($_SESSION['trade_user']['vat_number'] ?? '')) !== '';
}

/**
 * Is a wholesale partner signed in?
 *
 * One place to ask, so the rules that hang off it — trade pricing, no promo
 * codes, no per-drop delivery charge — cannot get out of step with each other.
 */
function tradeIsLoggedIn(): bool
{
    return !empty($_SESSION['trade_user']);
}

/** The VAT number being charged against, for display on invoices. */
function tradeVatNumber(): string
{
    return trim((string)($_SESSION['trade_user']['vat_number'] ?? ''));
}

/**
 * Compute every figure for an order in one place.
 *
 * @param array      $cart      $_SESSION['cart']
 * @param array|null $promoRow  validated promo_codes row, or null
 * @param string     $orderType 'delivery' or 'collection'
 * @param string     $postcode  delivery postcode
 *
 * @return array{subtotal:float,discount:float,delivery:float,vat:float,vat_rate:float,vat_applies:bool,total:float,total_pence:int}
 */
function computeOrderTotals(array $cart, ?array $promoRow, string $orderType, string $postcode): array
{
    // ── Subtotal ─────────────────────────────────────────────
    $subtotal = 0.0;
    foreach ($cart as $item) {
        $subtotal += (float)($item['price'] ?? 0) * (int)($item['quantity'] ?? 0);
    }
    $subtotal = round($subtotal, 2);

    // ── Discount ─────────────────────────────────────────────
    $discount = 0.0;
    if ($promoRow) {
        if (($promoRow['discount_type'] ?? '') === 'percentage') {
            $discount = round($subtotal * ((float)$promoRow['discount_value'] / 100), 2);
        } else {
            $discount = (float)$promoRow['discount_value'];
        }
        // Never discount below zero.
        $discount = min($discount, $subtotal);
    }

    // ── Delivery ─────────────────────────────────────────────
    //
    // Wholesale is not billed per drop, so a trade basket never carries a
    // delivery charge. Deciding it here rather than only hiding the row means
    // the amount charged and the amount shown cannot drift apart — the row was
    // reappearing on trade baskets whenever the summary refreshed, quietly
    // adding £1.99 to an order that is not billed that way.
    $delivery = 0.0;
    if (!tradeIsLoggedIn()
        && $orderType === 'delivery'
        && $postcode !== ''
        && preg_match('/^[A-Z]{1,2}[0-9][0-9A-Z]?\s*[0-9][A-Z]{2}$/i', $postcode)) {
        $delivery = calculateDeliveryCharge($postcode, $subtotal - $discount);
    }

    // ── VAT ──────────────────────────────────────────────────
    $base       = max(0.0, round($subtotal - $discount + $delivery, 2));
    $vatApplies = tradeVatApplies();
    $vat        = $vatApplies ? round($base * TRADE_VAT_RATE, 2) : 0.0;
    $total      = round($base + $vat, 2);

    return [
        'subtotal'    => $subtotal,
        'discount'    => $discount,
        'delivery'    => $delivery,
        'vat'         => $vat,
        'vat_rate'    => TRADE_VAT_RATE,
        'vat_applies' => $vatApplies,
        'total'       => $total,
        'total_pence' => (int)round($total * 100),
    ];
}

/**
 * Load and validate a promo code from the session.
 * Returns the promo_codes row when it is genuinely usable against this
 * subtotal, otherwise null. Trade orders never take promo codes — their
 * wholesale prices are already the discount.
 */
function validatedPromoRow(PDO $pdo, float $subtotal): ?array
{
    if (!empty($_SESSION['trade_user'])) {
        return null;
    }
    $promo = $_SESSION['promo'] ?? null;
    if (!$promo) {
        return null;
    }

    try {
        $stmt = $pdo->prepare("SELECT * FROM promo_codes WHERE id = :id AND code = :code AND active = 1");
        $stmt->execute(['id' => $promo['id'], 'code' => $promo['code']]);
        $row = $stmt->fetch();
    } catch (PDOException $e) {
        error_log('Promo validation failed: ' . $e->getMessage());
        return null;
    }

    if (!$row) {
        return null;
    }
    if (!is_null($row['max_uses']) && $row['uses_count'] >= $row['max_uses']) {
        return null;
    }
    if (!empty($row['expires_at']) && strtotime($row['expires_at']) < strtotime('today')) {
        return null;
    }
    if ($subtotal < (float)$row['min_order']) {
        return null;
    }

    return $row;
}
