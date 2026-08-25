<?php
// ============================================================
//  Creamy Bite – Offers
//
//  WHAT THIS IS
//  ------------
//  Buy one get one free, money off, a free tub over £30, free delivery on a
//  big order — the promotions the owner sets up in the admin panel, and the
//  short lines of encouragement that go with them in the cart.
//
//  THE SHAPE OF IT
//  ---------------
//      cbActiveOffers()  reads the offers that are switched on and in date.
//      cbApplyOffers()   works out what they are worth on one basket.
//      cbCartMessages()  writes the plain-English lines the customer reads.
//
//  cbApplyOffers() is deliberately PURE — it touches no session, no database
//  and prints nothing. You hand it a basket and a list of offers and it hands
//  back numbers. That is what makes the arithmetic testable, and money
//  arithmetic that cannot be tested is money arithmetic nobody can trust.
//
//  THE TWO RULES THE ARITHMETIC MUST NEVER BREAK
//  ---------------------------------------------
//   1. No line is ever discounted below what that line is worth. A £4.50 tub
//      cannot have £6 taken off it, however many offers land on it.
//   2. The total discount never exceeds the basket subtotal. The shop cannot
//      end up owing the customer money.
//  Both are enforced by construction below, not by a check at the end: every
//  deduction is taken out of a per-line allowance AND out of a whole-basket
//  allowance, and neither can go negative.
//
//  WHERE THE MONEY ACTUALLY GETS CHARGED
//  -------------------------------------
//  Nowhere in this file. computeOrderTotals() in includes/pricing.php is the
//  one place that decides what a customer pays, and it is the only thing that
//  should ever call cbApplyOffers() for a real order. Keeping the offer maths
//  here and the charging there is what stops a discount appearing on screen
//  without also being in the payment.
//
//  A NOTE ON CATEGORY OFFERS
//  -------------------------
//  An offer scoped to a category matches on a `category` key on the basket
//  line. cart_handler.php and includes/trade_cart.php both put it there, so
//  category offers work. They did nothing at all until those two started
//  storing it — the matching below was written and correct, the basket simply
//  never carried the field it matched on, and the admin page had to warn
//  owners off the option.
//
//  If a line ever arrives without the key it matches nothing rather than
//  everything, which is the safe way round: a basket saved before this
//  existed takes no money off instead of taking it off the whole order.
// ============================================================

require_once __DIR__ . '/config.php';

// ────────────────────────────────────────────────────────────
//  Small internal helpers. Prefixed and guarded like the rest of
//  includes/, but they exist for the three functions below — the
//  outside world should only ever need those three.
// ────────────────────────────────────────────────────────────

if (!function_exists('cbOfferMoney')) {
    /** "£4.20" — money as a customer reads it. */
    function cbOfferMoney(float $amount): string
    {
        return '£' . number_format(round($amount, 2), 2);
    }
}

if (!function_exists('cbOfferAtLeast')) {
    /**
     * Is $value at least $threshold, in money terms?
     *
     * Half a penny of tolerance, because a basket that adds up to exactly £30
     * can land a hair under it in binary floating point, and a customer who
     * has spent thirty pounds must not be told they are a hundredth of a penny
     * short of the offer.
     */
    function cbOfferAtLeast(float $value, float $threshold): bool
    {
        return $value >= $threshold - 0.005;
    }
}

if (!function_exists('cbOfferLines')) {
    /**
     * Turn a session basket into clean lines the maths can rely on.
     *
     * Anything with no quantity, no price or a negative price is dropped
     * rather than trusted. `allowance` is how much of that line is still
     * available to be discounted — offers eat into it in turn, so two offers
     * landing on the same line can never take off more than the line is worth.
     *
     * @return array<int, array{key:string,product_id:int,name:string,category:string,price:float,qty:int,value:float,allowance:float}>
     */
    function cbOfferLines(array $cart): array
    {
        $lines = [];
        foreach ($cart as $key => $item) {
            if (!is_array($item)) {
                continue;
            }
            $qty   = (int)($item['quantity'] ?? 0);
            $price = round((float)($item['price'] ?? 0), 2);
            if ($qty <= 0 || $price <= 0) {
                continue;
            }
            $value = round($price * $qty, 2);
            $lines[] = [
                'key'        => (string)($item['cart_key'] ?? $key),
                'product_id' => (int)($item['product_id'] ?? 0),
                'name'       => trim((string)($item['name'] ?? 'item')),
                'category'   => trim((string)($item['category'] ?? '')),
                'price'      => $price,
                'qty'        => $qty,
                'value'      => $value,
                'allowance'  => $value,
            ];
        }
        return $lines;
    }
}

if (!function_exists('cbOfferMatchesLine')) {
    /** Does this offer apply to this basket line? */
    function cbOfferMatchesLine(array $offer, array $line): bool
    {
        $scope = (string)($offer['scope'] ?? 'all');
        $value = trim((string)($offer['scope_value'] ?? ''));

        if ($scope === 'all') {
            return true;
        }
        if ($value === '') {
            // Scoped to something in particular, but nothing was named. Match
            // nothing — an unfinished offer must not behave like an
            // everything offer.
            return false;
        }
        if ($scope === 'category') {
            return $line['category'] !== '' && strcasecmp($line['category'], $value) === 0;
        }
        if ($scope === 'product') {
            // Named by id where the admin page gives an id, by name where a
            // person typed it.
            if (ctype_digit($value)) {
                return $line['product_id'] === (int)$value;
            }
            return strcasecmp($line['name'], $value) === 0;
        }
        return false;
    }
}

if (!function_exists('cbOfferUnits')) {
    /**
     * Every individual item the offer applies to, cheapest first.
     *
     * A line of "3 × £4.50" becomes three units of £4.50, each remembering
     * which line it came from so a free one can be charged back to the right
     * line's allowance. Sorted ascending because on buy-one-get-one the FREE
     * one is the cheapest — that is how it is done on a UK high street, and
     * doing it the other way round would quietly hand out the dearest item.
     *
     * @return array<int, array{line:int, price:float}>
     */
    function cbOfferUnits(array $lines, array $offer): array
    {
        $units = [];
        foreach ($lines as $i => $line) {
            if (!cbOfferMatchesLine($offer, $line)) {
                continue;
            }
            for ($n = 0; $n < $line['qty']; $n++) {
                $units[] = ['line' => $i, 'price' => $line['price']];
            }
        }
        usort($units, fn($a, $b) => $a['price'] <=> $b['price']);
        return $units;
    }
}

if (!function_exists('cbOfferGrouping')) {
    /**
     * How many you buy and how many you then get, for the two "get one free"
     * offer types. null means the offer is not set up properly and must be
     * left alone rather than guessed at.
     *
     * @return array{0:int,1:int}|null  [buy, get]
     */
    function cbOfferGrouping(array $offer): ?array
    {
        $type = (string)($offer['type'] ?? '');
        if ($type === 'bogo') {
            // Buy one get one free. The columns are ignored on purpose —
            // "bogo" means one and one, whatever is in the boxes.
            return [1, 1];
        }
        if ($type === 'buy_x_get_y') {
            $buy = (int)($offer['buy_qty'] ?? 0);
            $get = (int)($offer['get_qty'] ?? 0);
            return ($buy >= 1 && $get >= 1) ? [$buy, $get] : null;
        }
        return null;
    }
}

// ────────────────────────────────────────────────────────────
//  The three functions everything else uses
// ────────────────────────────────────────────────────────────

if (!function_exists('cbActiveOffers')) {
    /**
     * The offers that are switched on and running today, in the order the
     * owner arranged them.
     *
     * $audience is 'retail' or 'trade'. Trade prices are already wholesale, so
     * an offer only reaches a trade basket if it was explicitly set to.
     *
     * Never throws. If the database cannot be reached, or the offers table has
     * not been created yet, this returns an empty list and the shop simply
     * sells at its normal prices.
     */
    function cbActiveOffers(string $audience = 'retail'): array
    {
        static $cache = [];

        $audience = ($audience === 'trade') ? 'trade' : 'retail';
        if (isset($cache[$audience])) {
            return $cache[$audience];
        }

        // Use the connection the page already has where there is one, so an
        // ordinary page load does not open a second one. pricing.php is
        // reachable without includes/db.php, so fall back to our own.
        $pdo = (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) ? $GLOBALS['pdo'] : null;
        if ($pdo === null) {
            if (!defined('DB_NAME') || !defined('DB_USER') || DB_NAME === '') {
                return $cache[$audience] = [];
            }
            try {
                $pdo = new PDO(
                    'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
                    DB_USER,
                    DB_PASS,
                    [
                        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_TIMEOUT            => 3,
                    ]
                );
            } catch (Throwable $e) {
                error_log('Offers unavailable (no database): ' . $e->getMessage());
                return $cache[$audience] = [];
            }
        }

        // Today comes from PHP, which config.php has already pinned to UK
        // time, rather than from the database's own idea of the date — an
        // offer that ends on the 31st has to end at midnight in Harrow.
        $today = date('Y-m-d');

        try {
            $st = $pdo->prepare(
                "SELECT * FROM `offers`
                  WHERE `active` = 1
                    AND `applies_to` IN ('both', :audience)
                    AND (`starts_on` IS NULL OR `starts_on` <= :from_day)
                    AND (`ends_on`   IS NULL OR `ends_on`   >= :to_day)
                  ORDER BY `sort_order` ASC, `id` ASC"
            );
            $st->execute(['audience' => $audience, 'from_day' => $today, 'to_day' => $today]);
            $rows = $st->fetchAll();
        } catch (Throwable $e) {
            error_log('Offers could not be read: ' . $e->getMessage());
            return $cache[$audience] = [];
        }

        return $cache[$audience] = is_array($rows) ? $rows : [];
    }
}

if (!function_exists('cbApplyOffers')) {
    /**
     * What a list of offers is worth against one basket.
     *
     * PURE. No session, no database, no output. Same basket in, same numbers
     * out, every time — which is what lets it be tested directly.
     *
     * @param array  $cart      basket lines: price, quantity, name, product_id
     *                          (and category, once lines carry one)
     * @param array  $offers    rows from cbActiveOffers(), in order
     * @param float  $subtotal  the basket subtotal — also the hard ceiling on
     *                          the total discount
     * @param string $orderType 'delivery' or 'collection'
     *
     * @return array{discount:float, free_items:array, messages:array<int,string>, applied:array}
     *
     * DELIVERY: an offer that makes delivery free does NOT appear in
     * 'discount' — delivery is not part of the subtotal. It appears in
     * 'applied' with 'free_delivery' => true, and the caller zeroes the
     * delivery line:
     *
     *     $deliveryFree = false;
     *     foreach ($result['applied'] as $a) { $deliveryFree = $deliveryFree || $a['free_delivery']; }
     *
     * FREE ITEMS: a gift is an extra thing in the box, not money off, so it
     * appears in 'free_items' and changes no figure. Whoever packs the order
     * reads that list.
     */
    function cbApplyOffers(array $cart, array $offers, float $subtotal, string $orderType): array
    {
        $lines    = cbOfferLines($cart);
        $subtotal = round(max(0.0, $subtotal), 2);

        $result = [
            'discount'   => 0.0,
            'free_items' => [],
            'messages'   => [],
            'applied'    => [],
        ];

        if ($lines === [] || $offers === []) {
            return $result;
        }

        // The whole-basket allowance. Every penny taken off comes out of this,
        // so the total discount cannot pass the subtotal no matter how many
        // offers are running.
        $budget = $subtotal;

        foreach ($offers as $offer) {
            $type = (string)($offer['type'] ?? '');

            // Nothing left to take off. Free delivery and a free gift are not
            // money off the basket, so those two are still worth checking.
            if ($budget <= 0.0 && $type !== 'free_delivery' && $type !== 'free_item_over') {
                continue;
            }

            // A spend threshold, if the offer has one, is measured against the
            // basket subtotal before any offer discount — otherwise one offer
            // could knock the basket under the bar for the next one and the
            // result would depend on the order they happen to be listed in.
            $minSpend = (float)($offer['min_spend'] ?? 0);
            if ($minSpend > 0 && !cbOfferAtLeast($subtotal, $minSpend)) {
                continue;
            }

            $discount     = 0.0;
            $freeUnits    = 0;
            $freeDelivery = false;
            $freeItems    = [];

            switch ($type) {

                // ── Buy one get one free, and buy X get Y free ──
                case 'bogo':
                case 'buy_x_get_y':
                    $grouping = cbOfferGrouping($offer);
                    if ($grouping === null) {
                        continue 2;   // not set up properly — leave the basket alone
                    }
                    [$buy, $get] = $grouping;
                    $units = cbOfferUnits($lines, $offer);
                    $group = $buy + $get;
                    $sets  = intdiv(count($units), $group);
                    $free  = $sets * $get;

                    // The cheapest ones are the free ones. $units is already
                    // sorted cheapest first.
                    for ($i = 0; $i < $free; $i++) {
                        $unit = $units[$i];
                        $take = min(
                            $unit['price'],                       // never more than the item is worth
                            $lines[$unit['line']]['allowance'],   // never more than the line has left
                            $budget                               // never more than the basket has left
                        );
                        $take = round(max(0.0, $take), 2);
                        if ($take <= 0.0) {
                            continue;
                        }
                        $lines[$unit['line']]['allowance'] = round($lines[$unit['line']]['allowance'] - $take, 2);
                        $budget    = round($budget - $take, 2);
                        $discount  = round($discount + $take, 2);
                        $freeUnits++;
                    }
                    break;

                // ── A percentage off ──
                case 'percent_off':
                    $percent = min(100.0, max(0.0, (float)($offer['amount'] ?? 0)));
                    if ($percent <= 0.0) {
                        continue 2;
                    }
                    foreach ($lines as $i => $line) {
                        if (!cbOfferMatchesLine($offer, $line)) {
                            continue;
                        }
                        $take = min(
                            round($line['value'] * $percent / 100, 2),
                            $line['allowance'],
                            $budget
                        );
                        $take = round(max(0.0, $take), 2);
                        if ($take <= 0.0) {
                            continue;
                        }
                        $lines[$i]['allowance'] = round($line['allowance'] - $take, 2);
                        $budget   = round($budget - $take, 2);
                        $discount = round($discount + $take, 2);
                    }
                    break;

                // ── A flat amount off ──
                case 'fixed_off':
                    $wanted = round(max(0.0, (float)($offer['amount'] ?? 0)), 2);
                    if ($wanted <= 0.0) {
                        continue 2;
                    }
                    foreach ($lines as $i => $line) {
                        if ($wanted <= 0.0 || $budget <= 0.0) {
                            break;
                        }
                        if (!cbOfferMatchesLine($offer, $line)) {
                            continue;
                        }
                        $take = round(max(0.0, min($wanted, $line['allowance'], $budget)), 2);
                        if ($take <= 0.0) {
                            continue;
                        }
                        $lines[$i]['allowance'] = round($line['allowance'] - $take, 2);
                        $wanted   = round($wanted - $take, 2);
                        $budget   = round($budget - $take, 2);
                        $discount = round($discount + $take, 2);
                    }
                    break;

                // ── Delivery on us ──
                case 'free_delivery':
                    // Nothing to give away on a collection order.
                    if ($orderType !== 'delivery') {
                        continue 2;
                    }
                    // The admin form lets this be aimed at one product or one
                    // category, so honour that: free delivery "on Royal
                    // Flavours" must not apply to a basket of Classics. Without
                    // this the scope was collected, shown back to the owner as
                    // the offer they had set, and then quietly ignored.
                    if (($offer['scope'] ?? 'all') !== 'all') {
                        $matched = false;
                        foreach ($lines as $line) {
                            if (cbOfferMatchesLine($offer, $line)) { $matched = true; break; }
                        }
                        if (!$matched) {
                            continue 2;
                        }
                    }
                    $freeDelivery = true;
                    break;

                // ── A gift once they have spent enough ──
                case 'free_item_over':
                    // The threshold is the whole point of this offer, so
                    // without one it does nothing.
                    if ($minSpend <= 0) {
                        continue 2;
                    }
                    // Same as above: a gift aimed at a category only earns its
                    // place when the basket actually contains that category.
                    if (($offer['scope'] ?? 'all') !== 'all') {
                        $matched = false;
                        foreach ($lines as $line) {
                            if (cbOfferMatchesLine($offer, $line)) { $matched = true; break; }
                        }
                        if (!$matched) {
                            continue 2;
                        }
                    }
                    $freeItems[] = [
                        'label'       => trim((string)($offer['badge_text'] ?? '')) !== ''
                                            ? trim((string)$offer['badge_text'])
                                            : trim((string)($offer['scope_value'] ?? '')),
                        'qty'         => max(1, (int)($offer['get_qty'] ?? 1)),
                        'scope'       => (string)($offer['scope'] ?? 'all'),
                        'scope_value' => trim((string)($offer['scope_value'] ?? '')),
                        'offer_id'    => (int)($offer['id'] ?? 0),
                        'offer_name'  => (string)($offer['name'] ?? ''),
                    ];
                    break;

                default:
                    continue 2;   // an offer type this code does not know — ignore it
            }

            // Did this offer actually do anything?
            if ($discount <= 0.0 && !$freeDelivery && $freeItems === []) {
                continue;
            }

            // ── What the customer is told about it ──
            $message = trim((string)($offer['cart_message'] ?? ''));
            if ($message === '') {
                $message = match ($type) {
                    'bogo' => $freeUnits === 1
                        ? 'Buy one, get one free — your second one is on us, ' . cbOfferMoney($discount) . ' off.'
                        : 'Buy one, get one free — ' . $freeUnits . ' items on us, ' . cbOfferMoney($discount) . ' off.',
                    // $buy, $get and $percent were worked out above for this
                    // very offer — a match arm only runs for its own type, so
                    // they cannot be another offer's numbers.
                    'buy_x_get_y' => 'Buy ' . $buy . ', get ' . $get
                        . ' free — ' . $freeUnits . ($freeUnits === 1 ? ' item' : ' items')
                        . ' on us, ' . cbOfferMoney($discount) . ' off.',
                    'percent_off' => rtrim(rtrim(number_format($percent, 1), '0'), '.')
                        . '% off — ' . cbOfferMoney($discount) . ' off your basket.',
                    'fixed_off'   => cbOfferMoney($discount) . ' off your basket.',
                    'free_delivery' => 'Delivery is on us with this order.',
                    // Written so the owner's own wording drops in cleanly.
                    // "a free " . $label would read "a free a free tub" the
                    // moment someone types the article themselves.
                    'free_item_over' => $freeItems[0]['label'] !== ''
                        ? 'You have earned your free gift — ' . $freeItems[0]['label'] . '. We will pop it in the box.'
                        : 'You have earned a free gift. We will pop it in the box.',
                    default => (string)($offer['name'] ?? ''),
                };
            }

            $result['messages'][]   = $message;
            $result['free_items']   = array_merge($result['free_items'], $freeItems);
            $result['discount']     = round($result['discount'] + $discount, 2);
            $result['applied'][]    = [
                'id'             => (int)($offer['id'] ?? 0),
                'name'           => (string)($offer['name'] ?? ''),
                'type'           => $type,
                'discount'       => $discount,
                'free_delivery'  => $freeDelivery,
                'free_items'     => $freeItems,
                'badge'          => trim((string)($offer['badge_text'] ?? '')),
                'message'        => $message,
            ];
        }

        // Belt and braces. The loop above cannot produce a discount above the
        // subtotal, because every deduction comes out of $budget — this line
        // is here so that if anyone ever adds a new offer type and forgets,
        // the shop still cannot end up owing money.
        $result['discount'] = round(min($result['discount'], $subtotal), 2);
        if ($result['discount'] < 0) {
            $result['discount'] = 0.0;
        }

        return $result;
    }
}

if (!function_exists('cbCartMessages')) {
    /**
     * The lines of encouragement shown in the cart.
     *
     * "You are £4.20 from free delivery", "your second tub is on us", the
     * shop's own standing message. Plain English, warm, never pushy — a
     * customer is being told something useful, not sold at.
     *
     * At most four, in order of how much they matter to the person reading
     * them. A cart papered with notices reads as a discount bin, and this is
     * not a discount-bin shop.
     *
     * @return string[]
     */
    function cbCartMessages(array $cart, float $subtotal, string $orderType,
                            string $postcode = ''): array
    {
        $lines = cbOfferLines($cart);
        if ($lines === []) {
            return [];   // nothing in the basket, nothing to say about it
        }

        $subtotal = round(max(0.0, $subtotal), 2);
        $settings = cbStoreSettings();
        $messages = [];

        // 1. The shop's own standing message, if the owner has switched one on.
        if ($settings['cart_message_active'] === 1 && $settings['cart_message'] !== null) {
            $messages[] = $settings['cart_message'];
        }

        // 2. Offers already earned, in the offers' own words.
        //
        // Trade partners only see offers marked for them — their prices are
        // already wholesale. tradeIsLoggedIn() lives in pricing.php, which is
        // not required here, so ask for it rather than depend on it.
        $audience = (function_exists('tradeIsLoggedIn') && tradeIsLoggedIn()) ? 'trade' : 'retail';
        $offers   = cbActiveOffers($audience);
        $applied  = cbApplyOffers($cart, $offers, $subtotal, $orderType);
        foreach ($applied['messages'] as $m) {
            $messages[] = $m;
        }

        // 3. Delivery — the two figures a customer most wants to know, and the
        //    one that can stop them checking out at all. Ahead of the nudges
        //    below on purpose: being told the minimum order is £20 matters
        //    more than being invited to buy one more tub.
        if ($orderType === 'delivery') {
            // Only worth saying anything about spending your way to free
            // delivery if delivery is not already free for this address.
            // Inside the free radius it costs nothing whatever the basket
            // comes to, so "you are £16 away from free delivery" was both
            // wrong and faintly insulting to someone who lives round the
            // corner. Delivery on this order is free when it is already
            // being given away by the radius rule.
            $freeOver         = $settings['free_delivery_over'];
            $alreadyFreeByArea = ($applied['free_delivery'] ?? false)
                || (function_exists('calculateDeliveryCharge')
                    && $postcode !== ''
                    && calculateDeliveryCharge($postcode, $subtotal) <= 0.0);

            if ($freeOver !== null && !$alreadyFreeByArea) {
                $messages[] = cbOfferAtLeast($subtotal, $freeOver)
                    ? 'Delivery is on us — you have spent over ' . cbOfferMoney($freeOver) . '.'
                    : 'You are ' . cbOfferMoney($freeOver - $subtotal) . ' away from free delivery.';
            }

            $minimum = (float)$settings['min_delivery_order'];
            if ($minimum > 0 && !cbOfferAtLeast($subtotal, $minimum)) {
                $messages[] = 'Deliveries start at ' . cbOfferMoney($minimum) . '. Add '
                    . cbOfferMoney($minimum - $subtotal) . ' to have this brought to your door.';
            }
        }

        // 4. Offers they are close to earning.
        foreach ($offers as $offer) {
            $type = (string)($offer['type'] ?? '');

            // Nearly enough items for the free one.
            if ($type === 'bogo' || $type === 'buy_x_get_y') {
                $grouping = cbOfferGrouping($offer);
                if ($grouping === null) {
                    continue;
                }
                [$buy, $get] = $grouping;
                $count = count(cbOfferUnits($lines, $offer));
                $short = ($buy + $get) - ($count % ($buy + $get));
                if ($count > 0 && $short > 0 && $short < ($buy + $get)) {
                    $messages[] = $short === 1
                        ? 'Add one more and the next one is free.'
                        : 'Add ' . $short . ' more and the next one is free.';
                }
                continue;
            }

            // Nearly enough spent for the gift.
            if ($type === 'free_item_over') {
                $minSpend = (float)($offer['min_spend'] ?? 0);
                if ($minSpend > 0 && !cbOfferAtLeast($subtotal, $minSpend)) {
                    $gift = trim((string)($offer['badge_text'] ?? ''));
                    $messages[] = 'Spend ' . cbOfferMoney($minSpend - $subtotal)
                        . ' more to earn your free gift'
                        . ($gift !== '' ? ' — ' . $gift . '.' : '.');
                }
            }
        }

        // Same thing said twice is worse than said once.
        $messages = array_values(array_unique(array_filter($messages, fn($m) => trim((string)$m) !== '')));

        return array_slice($messages, 0, 4);
    }
}
