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
//      offers        = the shop's own promotions (BOGO and friends)
//      promo         = the code the customer typed, on what is left after them
//      discount      = offers + promo, never more than the subtotal
//      delivery      = postcode-based, 0 for collection, 0 when an offer or
//                      the free-delivery-over figure covers it
//      taxable base  = subtotal - discount + delivery
//      VAT           = 20% of the base, trade customers with a VAT number only
//      total         = base + VAT
//
//  VAT is charged only to trade partners who have entered a VAT number on
//  their profile. Retail shelf prices are VAT-inclusive, so retail orders
//  never have VAT added on top.
//
//  OFFERS AND PROMO CODES ARE REPORTED SEPARATELY
//  ----------------------------------------------
//  computeOrderTotals() returns 'offer_discount' and 'promo_discount' as well
//  as the combined 'discount', so the checkout can list "Buy one get one free
//  −£4.50" and "SUMMER10 −£1.35" on their own lines. 'discount' keeps its old
//  meaning — everything taken off the goods — so the three existing callers
//  (the checkout page, stripe_intent.php, checkout_handler.php) stay correct
//  without being touched.
//
//  Requires config.php (TRADE_VAT_RATE) and an open session.
// ============================================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/offers.php';

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
                $straightLine = $R * 2 * atan2(sqrt($a), sqrt(1 - $a));
                // Scaled toward a realistic driving distance — see
                // DELIVERY_DISTANCE_FACTOR in config.php for why.
                $miles = $straightLine * DELIVERY_DISTANCE_FACTOR;
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
 * @return array{
 *   subtotal:float, discount:float, offer_discount:float, promo_discount:float,
 *   delivery:float, delivery_before_offer:float, delivery_is_free:bool,
 *   delivery_free_reason:string, offer_messages:array<int,string>,
 *   offer_free_items:array, offers_applied:array,
 *   vat:float, vat_rate:float, vat_applies:bool, total:float, total_pence:int
 * }
 */
function computeOrderTotals(array $cart, ?array $promoRow, string $orderType, string $postcode): array
{
    // ── Subtotal ─────────────────────────────────────────────
    $subtotal = 0.0;
    foreach ($cart as $item) {
        $subtotal += (float)($item['price'] ?? 0) * (int)($item['quantity'] ?? 0);
    }
    $subtotal = round($subtotal, 2);

    // ── The shop's own offers ────────────────────────────────
    //
    // Worked out before the promo code, because the code applies to what is
    // left after them (see below). The whole block is wrapped: a promotion the
    // owner has half-configured, or an offers table that cannot be read, must
    // cost the shop a discount it meant to give — never a checkout. If
    // anything at all goes wrong here the basket simply sells at its normal
    // price and the customer can still pay.
    $offerDiscount     = 0.0;
    $offerMessages     = [];
    $offerFreeItems    = [];
    $offersApplied     = [];
    $offerFreeDelivery = false;

    if (function_exists('cbActiveOffers') && function_exists('cbApplyOffers')) {
        try {
            $offers = cbActiveOffers(tradeIsLoggedIn() ? 'trade' : 'retail');
            if ($offers !== []) {
                $applied = cbApplyOffers($cart, $offers, $subtotal, $orderType);

                // Read back defensively rather than trusted wholesale. This is
                // the boundary between the offer maths and the customer's card,
                // and it is the last place a wrong figure can be stopped.
                $offerDiscount = round((float)($applied['discount'] ?? 0), 2);
                if (!is_finite($offerDiscount) || $offerDiscount < 0) {
                    $offerDiscount = 0.0;
                }
                $offerDiscount = min($offerDiscount, $subtotal);

                $offerMessages  = is_array($applied['messages'] ?? null)   ? array_values($applied['messages'])   : [];
                $offerFreeItems = is_array($applied['free_items'] ?? null) ? array_values($applied['free_items']) : [];
                $offersApplied  = is_array($applied['applied'] ?? null)    ? array_values($applied['applied'])    : [];

                foreach ($offersApplied as $one) {
                    if (!empty($one['free_delivery'])) {
                        $offerFreeDelivery = true;
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('Offers skipped for this basket: ' . $e->getMessage());
            $offerDiscount     = 0.0;
            $offerMessages     = [];
            $offerFreeItems    = [];
            $offersApplied     = [];
            $offerFreeDelivery = false;
        }
    }

    // ── The promo code ───────────────────────────────────────
    //
    // Applied to what is left AFTER the offers, which is how a high street
    // does it: "10% off" on a basket where one tub is already free is 10% of
    // what you are actually paying, not 10% of the ticket price. With no
    // offers running the remainder IS the subtotal, so a basket that has no
    // offer on it is charged exactly what it was charged before.
    //
    // Taking it out of the remainder also means offers + promo can never
    // between them exceed the subtotal, and the two figures reported below
    // always add up to the combined discount — so the checkout can never list
    // two lines that do not sum to the total taken off.
    $remaining     = round(max(0.0, $subtotal - $offerDiscount), 2);
    $promoDiscount = 0.0;
    if ($promoRow) {
        if (($promoRow['discount_type'] ?? '') === 'percentage') {
            $promoDiscount = round($remaining * ((float)$promoRow['discount_value'] / 100), 2);
        } else {
            $promoDiscount = round((float)$promoRow['discount_value'], 2);
        }
        // A negative value in the promo row would ADD to the bill. Nothing
        // in the admin panel writes one, but a customer must not be charged
        // extra by a discount however the row got there.
        $promoDiscount = min(max(0.0, $promoDiscount), $remaining);
    }

    $discount = round($offerDiscount + $promoDiscount, 2);
    // Cannot happen — both come out of the same subtotal above — but the
    // shop can never end up owing money, so it is checked rather than assumed.
    if ($discount > $subtotal) {
        $discount = $subtotal;
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

    // Two things can put the delivery charge on the shop: an offer that says
    // so, and the "free delivery over £X" figure in the store settings.
    //
    // The threshold is measured against the SUBTOTAL, which is the same figure
    // cbCartMessages() measures it against when it tells the customer
    // "delivery is on us". If one of them used the discounted amount instead,
    // a customer could be told delivery was free and then charged for it.
    // Applied here rather than inside calculateDeliveryCharge() because that
    // function's answer is cached per postcode for the whole checkout: it
    // answers "how far away is this address", which does not change when the
    // basket does.
    $deliveryBeforeOffer = $delivery;
    $freeReason          = '';

    if ($delivery > 0.0 && $offerFreeDelivery) {
        $freeReason = 'offer';
    } elseif ($delivery > 0.0) {
        $freeOver = cbStoreSettings()['free_delivery_over'];
        if ($freeOver !== null && $freeOver > 0 && cbOfferAtLeast($subtotal, (float)$freeOver)) {
            $freeReason = 'spend';
        }
    }
    if ($freeReason !== '') {
        $delivery = 0.0;
    }

    // ── VAT ──────────────────────────────────────────────────
    $base       = max(0.0, round($subtotal - $discount + $delivery, 2));
    $vatApplies = tradeVatApplies();
    $vat        = $vatApplies ? round($base * TRADE_VAT_RATE, 2) : 0.0;
    $total      = round($base + $vat, 2);

    return [
        'subtotal'              => $subtotal,
        // Everything off the goods — offers and the promo code together.
        // Unchanged in meaning, so existing callers need no edit.
        'discount'              => $discount,
        'offer_discount'        => $offerDiscount,
        'promo_discount'        => $promoDiscount,
        'delivery'              => $delivery,
        // What delivery would have cost before anything made it free, so the
        // checkout can show the saving rather than just a missing row.
        'delivery_before_offer' => $deliveryBeforeOffer,
        'delivery_is_free'      => $freeReason !== '',
        'delivery_free_reason'  => $freeReason,   // '', 'offer' or 'spend'
        'offer_messages'        => $offerMessages,
        'offer_free_items'      => $offerFreeItems,
        'offers_applied'        => $offersApplied,
        'vat'                   => $vat,
        'vat_rate'              => TRADE_VAT_RATE,
        'vat_applies'           => $vatApplies,
        'total'                 => $total,
        'total_pence'           => (int)round($total * 100),
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
