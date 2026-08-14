<?php
// ============================================================
//  Creamy Bite – Order / Product Page (with Variant Support)
// ============================================================
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/product_icons.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/flavour_colours.php';

require_once __DIR__ . '/../includes/trade_cart.php';
// The offers a customer is shown and the money they are charged come from the
// same place. pricing.php is the only thing on the site that decides what a
// basket costs, and it pulls in includes/offers.php with it.
require_once __DIR__ . '/../includes/pricing.php';
// A revoked trade account must stop seeing wholesale prices immediately.
tradeSessionRevalidate($pdo);

// ── Offers, for the badges and the cart drawer ───────────────
//
// Read once for the whole page. A trade partner only sees offers that were
// explicitly marked for trade — their prices are already wholesale.
$offerAudience = !empty($_SESSION['trade_user']) ? 'trade' : 'retail';
$pageOffers    = [];
try {
    if (function_exists('cbActiveOffers')) {
        $pageOffers = cbActiveOffers($offerAudience);
    }
} catch (Throwable $e) {
    // A promotion that cannot be read costs the shop a badge, never the menu.
    error_log('Offers skipped on the menu: ' . $e->getMessage());
    $pageOffers = [];
}

/**
 * The offer badge for one product card, or '' for no badge.
 *
 * Only offers that change what THIS product costs earn a badge. A flat
 * "£5 off" is taken off the BASKET and spread across whatever is in it, so
 * printing it on a single tub would promise £5 off that tub; free delivery
 * and a gift over £30 are basket-wide too. Those all speak for themselves in
 * the cart instead, where the figures are real.
 *
 * An offer with a minimum spend is left off the card for the same reason: the
 * card has no way to say "once you have spent £30", and a badge with a
 * condition it cannot state is a badge that misleads.
 *
 * The FIRST matching offer wins, in the order the owner arranged them. Two
 * badges stacked on one card reads as a clearance bin.
 */
function cbOrderOfferBadge(array $product, array $offers): string
{
    if (!function_exists('cbOfferMatchesLine')) {
        return '';
    }

    // cbOfferMatchesLine() reads exactly these three keys, so the same
    // matching rule decides the badge and the discount. Writing a second
    // "does this offer apply" test here is how a card ends up advertising
    // something the basket then refuses to give.
    $line = [
        'product_id' => (int)($product['id'] ?? 0),
        'name'       => trim((string)($product['name'] ?? '')),
        'category'   => trim((string)($product['category'] ?? '')),
    ];

    foreach ($offers as $offer) {
        $type = (string)($offer['type'] ?? '');
        if (!in_array($type, ['bogo', 'buy_x_get_y', 'percent_off'], true)) {
            continue;
        }
        if ((float)($offer['min_spend'] ?? 0) > 0) {
            continue;
        }

        // A CATEGORY offer is not badged, and this is deliberate.
        //
        // Basket lines do not carry the product's category yet —
        // cart_handler.php stores the name, price and quantity — so
        // cbOfferMatchesLine() matches nothing in the basket for a category
        // offer, and includes/offers.php takes nothing off. A product card
        // DOES know its category, so badging one here would put "10% off" on
        // a tub and then charge full price for it. Rather than have the card
        // promise what the till refuses, category offers stay off the cards
        // until a basket line carries its category.
        if ((string)($offer['scope'] ?? 'all') === 'category') {
            continue;
        }

        if (!cbOfferMatchesLine($offer, $line)) {
            continue;
        }

        // The owner's own wording first, always. They know their shop.
        $badge = trim((string)($offer['badge_text'] ?? ''));
        if ($badge === '') {
            if ($type === 'bogo') {
                $badge = 'Two for one';
            } elseif ($type === 'buy_x_get_y') {
                $grouping = function_exists('cbOfferGrouping') ? cbOfferGrouping($offer) : null;
                if ($grouping === null) {
                    continue;   // half-configured — say nothing rather than guess
                }
                [$buy, $get] = $grouping;
                $badge = 'Buy ' . $buy . ', get ' . $get . ' free';
            } else {
                $percent = min(100.0, max(0.0, (float)($offer['amount'] ?? 0)));
                if ($percent <= 0) {
                    continue;
                }
                $badge = rtrim(rtrim(number_format($percent, 1), '0'), '.') . '% off';
            }
        }

        if ($badge !== '') {
            return $badge;
        }
    }

    return '';
}

/**
 * The lines of encouragement under the cart drawer's total.
 *
 * Anything an offer row above already states is dropped, so a customer is not
 * told about the same buy-one-get-one twice — the row is the accounting, these
 * are the words. Never fatal: a shop whose offers cannot be read still has a
 * working cart.
 */
function cbOrderCartNotes(array $cart, array $totals, string $orderType): array
{
    if (!function_exists('cbCartMessages')) {
        return [];
    }
    try {
        $messages = cbCartMessages($cart, (float)$totals['subtotal'], $orderType);
    } catch (Throwable $e) {
        error_log('Cart messages skipped on the menu: ' . $e->getMessage());
        return [];
    }

    // Only an offer that ALREADY HAS A ROW of its own gets its message
    // dropped. An offer that took money off is listed above with the amount,
    // and a gift has its own row — saying it again in words is repetition.
    //
    // Free delivery has neither: the drawer has no postcode and so no delivery
    // row at all. Dropping its message on the strength of it being "applied"
    // is how a customer who has just earned free delivery gets told nothing
    // about it.
    $alreadyShown = [];
    foreach (($totals['offers_applied'] ?? []) as $offer) {
        $hasRow = round((float)($offer['discount'] ?? 0), 2) > 0
               || !empty($offer['free_items']);
        $said   = trim((string)($offer['message'] ?? ''));
        if ($hasRow && $said !== '') {
            $alreadyShown[] = $said;
        }
    }

    return array_values(array_filter(
        $messages,
        fn($m) => !in_array(trim((string)$m), $alreadyShown, true)
    ));
}

/**
 * The cart drawer's money block, as finished HTML.
 *
 * The drawer used to print one figure — cart_handler.php's basket total — and
 * nothing else. With an offer running that is no longer what the customer
 * pays, so every figure in here now comes straight out of computeOrderTotals(),
 * the same function that builds the Stripe charge and writes the order.
 * Nothing is added up, worked back or rounded a second time.
 *
 * Delivery is deliberately absent: the drawer has no postcode and no
 * collection choice, so there is nothing yet to price. The delivery figures
 * appear as words in the notes ("you are £4.20 from free delivery") and as
 * real money at checkout.
 */
function cbOrderCartSummary(array $totals, array $cart, ?array $appliedPromo, string $orderType): string
{
    $esc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

    $rows = '';

    // ── One row per offer, named the way the owner named it ──
    foreach (($totals['offers_applied'] ?? []) as $offer) {
        $amount = round((float)($offer['discount'] ?? 0), 2);
        if ($amount <= 0) {
            continue;   // free delivery and free gifts are not money off
        }
        $label = trim((string)($offer['name'] ?? ''));
        if ($label === '') {
            $label = trim((string)($offer['badge'] ?? ''));
        }
        if ($label === '') {
            $label = 'Offer applied';
        }
        $rows .= '<div class="cbof-cart-line cbof-cart-line-offer">'
               . '<span class="cbof-cart-line-label"><i class="fa-solid fa-tag"></i> ' . $esc($label) . '</span>'
               . '<span class="cbof-cart-line-value">&minus;' . cbOfferMoney($amount) . '</span></div>';
    }

    // ── The code the customer typed, kept apart from the shop's own offers ──
    $promoDiscount = round((float)($totals['promo_discount'] ?? 0), 2);
    if ($promoDiscount > 0) {
        $rows .= '<div class="cbof-cart-line cbof-cart-line-offer">'
               . '<span class="cbof-cart-line-label"><i class="fa-solid fa-ticket"></i> '
               . ($appliedPromo ? 'Code ' . $esc($appliedPromo['code']) : 'Promo code')
               . '</span>'
               . '<span class="cbof-cart-line-value">&minus;' . cbOfferMoney($promoDiscount) . '</span></div>';
    }

    // ── VAT (trade partners with a VAT number only) ──────────
    if (!empty($totals['vat_applies'])) {
        $rows .= '<div class="cbof-cart-line cbof-cart-line-vat">'
               . '<span class="cbof-cart-line-label"><i class="fa-solid fa-receipt"></i> VAT @ '
               . (int)round((float)$totals['vat_rate'] * 100) . '%</span>'
               . '<span class="cbof-cart-line-value">+ ' . cbOfferMoney((float)$totals['vat']) . '</span></div>';
    }

    $out = '';

    // The breakdown only appears when there is something to break down. A
    // basket with no offer, no code and no VAT shows exactly what it always
    // showed: one Total.
    if ($rows !== '') {
        $out .= '<div class="cbof-cart-lines">'
              . '<div class="cbof-cart-line">'
              . '<span class="cbof-cart-line-label">Subtotal</span>'
              . '<span class="cbof-cart-line-value">' . cbOfferMoney((float)$totals['subtotal']) . '</span></div>'
              . $rows
              . '</div>';
    }

    $out .= '<div class="cart-total-row">'
          . '<span class="cart-total-label">Total</span>'
          . '<span class="cart-total-amount" id="cartTotal">' . cbOfferMoney((float)$totals['total']) . '</span>'
          . '</div>';

    // ── A gift that has been earned ──────────────────────────
    foreach (($totals['offer_free_items'] ?? []) as $gift) {
        $label = trim((string)($gift['label'] ?? ''));
        if ($label === '') {
            $label = 'Your free gift';
        }
        $qty = max(1, (int)($gift['qty'] ?? 1));
        if ($qty > 1) {
            $label .= ' &times; ' . $qty;
        }
        $out .= '<div class="cbof-gift-row">'
              . '<span class="cbof-gift-label"><i class="fa-solid fa-gift"></i> ' . $esc($label) . '</span>'
              . '<span class="cbof-gift-value">On us</span></div>';
    }

    // ── The words ────────────────────────────────────────────
    $notes = cbOrderCartNotes($cart, $totals, $orderType);
    if ($notes !== []) {
        $out .= '<div class="cbof-notes">';
        foreach ($notes as $note) {
            $out .= '<p class="cbof-note">' . $esc($note) . '</p>';
        }
        $out .= '</div>';
    }

    return $out;
}

/**
 * Work out the drawer's figures for whatever is in the basket right now.
 *
 * 'delivery' rather than 'collection' because the drawer has no order-type
 * choice on it, and delivery is the case with something worth saying: how far
 * off free delivery the basket is, and whether it has reached the minimum.
 * With no postcode the delivery charge itself is 0, so nothing is quoted that
 * has not been looked up.
 */
function cbOrderCartFigures(PDO $pdo, array $cart): array
{
    $subtotal = 0.0;
    foreach ($cart as $item) {
        $subtotal += (float)($item['price'] ?? 0) * (int)($item['quantity'] ?? 0);
    }
    $promoRow = validatedPromoRow($pdo, round($subtotal, 2));

    return [
        'totals' => computeOrderTotals($cart, $promoRow, 'delivery', ''),
        'promo'  => $promoRow ? ($_SESSION['promo'] ?? null) : null,
    ];
}

// ── The drawer asks for its figures again whenever the basket changes ──
//
// GET, and nothing personal in it: there is no postcode and no address here,
// only "what does what is already in my session basket come to".
if (($_GET['summary'] ?? '') === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    $cart = $_SESSION['cart'] ?? [];
    $html = '';
    if ($cart !== []) {
        try {
            $figures = cbOrderCartFigures($pdo, $cart);
            $html    = cbOrderCartSummary($figures['totals'], $cart, $figures['promo'], 'delivery');
        } catch (Throwable $e) {
            // The drawer keeps whatever it last showed rather than emptying
            // itself. A basket the customer cannot see is worse than one that
            // is a moment out of date.
            error_log('Cart summary unavailable: ' . $e->getMessage());
            $html = '';
        }
    }

    echo json_encode(['ok' => true, 'html' => $html]);
    exit;
}

// ── The drawer's opening figures ─────────────────────────────
//
// Rendered here rather than left to the first fetch, so a customer who arrives
// with a basket already in their session opens the drawer on the right total
// instead of watching £0.00 correct itself.
$drawerCart    = $_SESSION['cart'] ?? [];
$drawerSummary = '';
if ($drawerCart !== []) {
    try {
        $drawerFigures = cbOrderCartFigures($pdo, $drawerCart);
        $drawerSummary = cbOrderCartSummary(
            $drawerFigures['totals'], $drawerCart, $drawerFigures['promo'], 'delivery'
        );
    } catch (Throwable $e) {
        error_log('Cart summary unavailable on load: ' . $e->getMessage());
        $drawerSummary = '';
    }
}

// Load categories
$categories = [];
try {
    $cats = $pdo->query("SELECT * FROM categories ORDER BY sort_order ASC, name ASC")->fetchAll();
    foreach ($cats as $c) { $categories[] = $c; }
} catch (PDOException $e) { }

// Load available products & categories
$products = [];
try {
    $catCheck = (int)$pdo->query("SELECT COUNT(*) FROM categories")->fetchColumn();
    if ($catCheck === 0) {
        // categories.icon and products.emoji below now seed bare Font Awesome
        // names rather than emoji characters, so a fresh install starts on the
        // brand icon set. cbProductIcon() still renders either, which is what
        // keeps an existing catalogue working untouched.
        $cats = [
            ['name' => 'Royal Flavours',   'icon' => 'crown',       'sort_order' => 1],
            ['name' => 'Nutty Delights',   'icon' => 'jar-wheat',   'sort_order' => 2],
            ['name' => 'Fruit Delights',   'icon' => 'apple-whole', 'sort_order' => 3],
            ['name' => 'Classics',         'icon' => 'ice-cream',   'sort_order' => 4],
            ['name' => 'Exotic Speciality','icon' => 'leaf',        'sort_order' => 5],
        ];
        foreach ($cats as $c) {
            $pdo->prepare("INSERT INTO categories (name, icon, sort_order) VALUES (:name, :icon, :sort_order)")->execute($c);
        }
    }

    // First-run seeding only.
    //
    // This used to reseed whenever 'Rajbhog Ice Cream Tub' was missing, and
    // inserted all 13 rows without checking which already existed — so a
    // single missing product duplicated the entire catalogue on the next page
    // load. Seed only into a genuinely empty products table, and skip any name
    // that is already there.
    $productCount = (int)$pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();
    if ($productCount === 0) {
        $flavorProducts = [
            ['name' => 'Rajbhog Ice Cream Tub',         'emoji' => 'crown',        'description' => 'Rich saffron ice cream loaded with almonds, cashews, pistachios & cardamom.', 'price' => 6.99, 'category' => 'Royal Flavours',    'image' => 'Rajbhog.jpg',             'available' => 1, 'track_stock' => 1, 'total_stock' => 40, 'stock_qty' => 40],
            ['name' => 'Kesar Pista Ice Cream Tub',     'emoji' => 'wheat-awn',    'description' => 'Royal saffron and roasted pistachio blended into rich cream.',                'price' => 6.99, 'category' => 'Royal Flavours',    'image' => 'Kesar-Pista.jpg',         'available' => 1, 'track_stock' => 1, 'total_stock' => 45, 'stock_qty' => 45],
            ['name' => 'Afghan Meva Ice Cream Tub',     'emoji' => 'mountain-sun', 'description' => 'Exotic Afghan dry fruits, figs, raisins, and premium nuts.',                  'price' => 7.49, 'category' => 'Royal Flavours',    'image' => 'Afghan-Meva.jpg',         'available' => 1, 'track_stock' => 1, 'total_stock' => 30, 'stock_qty' => 30],
            ['name' => 'Roasted Almond Ice Cream Tub',  'emoji' => 'jar-wheat',    'description' => 'Crunchy slow-roasted California almonds folded into rich cream.',             'price' => 6.49, 'category' => 'Nutty Delights',    'image' => 'Roasted-Almond.jpg',      'available' => 1, 'track_stock' => 1, 'total_stock' => 50, 'stock_qty' => 50],
            ['name' => 'American Dry Fruits Tub',       'emoji' => 'bowl-food',    'description' => 'Loaded with chopped almonds, cashews, raisins & tutti frutti morsels.',       'price' => 6.99, 'category' => 'Nutty Delights',    'image' => 'American-Dry-Fruits.jpg', 'available' => 1, 'track_stock' => 1, 'total_stock' => 35, 'stock_qty' => 35],
            ['name' => 'Kaju Draksh Ice Cream Tub',     'emoji' => 'holly-berry',  'description' => 'Premium cashew nuts and golden raisins folded into rich cream.',              'price' => 6.49, 'category' => 'Nutty Delights',    'image' => 'Kaju-Draksh.jpg',         'available' => 1, 'track_stock' => 1, 'total_stock' => 40, 'stock_qty' => 40],
            ['name' => 'Fresh Sitafal (Custard Apple)', 'emoji' => 'apple-whole',  'description' => 'Crafted with real fresh custard apple pulp for a sweet fruity sensation.',    'price' => 6.99, 'category' => 'Fruit Delights',    'image' => 'Fresh-Sitafal.jpg',       'available' => 1, 'track_stock' => 1, 'total_stock' => 25, 'stock_qty' => 25],
            ['name' => 'Naked Coconut Ice Cream Tub',   'emoji' => 'droplet',      'description' => 'Refreshing tender coconut flesh and pure coconut milk ice cream.',            'price' => 6.49, 'category' => 'Fruit Delights',    'image' => 'Naked-Coconut.jpg',       'available' => 1, 'track_stock' => 1, 'total_stock' => 30, 'stock_qty' => 30],
            ['name' => 'Kaju Gulkand Ice Cream Tub',    'emoji' => 'spa',          'description' => 'Rich cashew nut ice cream infused with sweet damask rose petal preserves.',   'price' => 6.99, 'category' => 'Royal Flavours',    'image' => 'Kaju-Gulkand.jpg',        'available' => 1, 'track_stock' => 1, 'total_stock' => 30, 'stock_qty' => 30],
            ['name' => 'Mava Malai Ice Cream Tub',      'emoji' => 'glass-water',  'description' => 'Traditional rich mawa and thickened malai cream with cardamom hint.',         'price' => 5.99, 'category' => 'Classics',          'image' => 'Mava-Malai.jpg',          'available' => 1, 'track_stock' => 1, 'total_stock' => 50, 'stock_qty' => 50],
            ['name' => 'Cookies & Cream Tub',           'emoji' => 'cookie-bite',  'description' => 'Velvety vanilla ice cream swirled with crunchy chocolate cookie crumbles.',   'price' => 5.99, 'category' => 'Classics',          'image' => 'Cookies-&-Cream.jpg',     'available' => 1, 'track_stock' => 1, 'total_stock' => 45, 'stock_qty' => 45],
            ['name' => 'Choco Chips Ice Cream Tub',     'emoji' => 'cubes',        'description' => 'Creamy chocolate ice cream studded with rich dark chocolate chips.',          'price' => 5.99, 'category' => 'Classics',          'image' => 'Choco-Chips.jpg',         'available' => 1, 'track_stock' => 1, 'total_stock' => 40, 'stock_qty' => 40],
            ['name' => 'Pan Masala Ice Cream Tub',      'emoji' => 'leaf',         'description' => 'Authentic Meetha Paan infused ice cream with fennel seeds & sweet spices.',   'price' => 6.49, 'category' => 'Exotic Speciality', 'image' => 'Pan-Masala.jpg',          'available' => 1, 'track_stock' => 1, 'total_stock' => 30, 'stock_qty' => 30],
        ];
        $existsStmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE name = :name");
        $insertStmt = $pdo->prepare("INSERT INTO products (name, emoji, description, price, category, image, available, track_stock, total_stock, stock_qty) VALUES (:name, :emoji, :description, :price, :category, :image, :available, :track_stock, :total_stock, :stock_qty)");
        foreach ($flavorProducts as $fp) {
            $existsStmt->execute(['name' => $fp['name']]);
            if ((int)$existsStmt->fetchColumn() === 0) {
                $insertStmt->execute($fp);
            }
        }
    }

    // The old "purge products not in this hardcoded list" block used to run
    // here on EVERY page load. It deleted any product the admin added, the
    // moment a customer opened this page — and with the product_variants
    // foreign key it took their variants and trade prices too. A public
    // storefront page must never delete catalogue data; removing products is
    // the admin panel's job.

    // Trade-only products are hidden from retail visitors entirely.
    $tradeOnlyFilter = !empty($_SESSION['trade_user']) ? '' : ' AND trade_only = 0';
    $stmt = $pdo->query("SELECT * FROM products WHERE available = 1{$tradeOnlyFilter} ORDER BY id ASC");
    while ($row = $stmt->fetch()) { $products[$row['id']] = $row; }
} catch (PDOException $e) { }

// Load ALL variants for available products in one query
$variantsByProduct = [];
if (!empty($products)) {
    $ids = implode(',', array_keys($products));
    try {
        $vRows = $pdo->query("SELECT * FROM product_variants WHERE product_id IN ($ids) AND available = 1 ORDER BY sort_order ASC, id ASC")->fetchAll();
        $isTradeUser = !empty($_SESSION['trade_user']);
        foreach ($vRows as $v) {
            if ($isTradeUser && (float)($v['wholesale_price'] ?? 0) > 0) {
                $v['price'] = (float)$v['wholesale_price'];
            }
            $variantsByProduct[$v['product_id']][] = $v;
        }
    } catch (PDOException $e) { }
}

// ── Product structured data for the menu ──────────────────────
//
// Skipped entirely for a signed-in trade session: the block above swaps
// $variantsByProduct prices to wholesale in place for a trade partner, and a
// search crawler — always anonymous — must never be handed a wholesale
// price as if it were the retail one. Anonymous is the only session a
// crawler ever has, so this still covers everything that gets indexed.
$cbMenuSchema = [];
if (empty($_SESSION['trade_user'])) {
    foreach ($products as $p) {
        $vs = $variantsByProduct[$p['id']] ?? [];
        if ($vs) {
            $prices = array_column($vs, 'price');
            $offer  = [
                '@type'         => 'AggregateOffer',
                'priceCurrency' => 'GBP',
                'lowPrice'      => number_format((float)min($prices), 2, '.', ''),
                'highPrice'     => number_format((float)max($prices), 2, '.', ''),
                'offerCount'    => count($vs),
                'availability'  => 'https://schema.org/InStock',
            ];
        } else {
            $oos   = ($p['track_stock'] ?? 0) && ($p['stock_qty'] ?? 1) <= 0;
            $offer = [
                '@type'         => 'Offer',
                'priceCurrency' => 'GBP',
                'price'         => number_format((float)$p['price'], 2, '.', ''),
                'availability'  => $oos ? 'https://schema.org/OutOfStock' : 'https://schema.org/InStock',
            ];
        }
        $cbMenuSchema[] = [
            'id'          => (int)$p['id'],
            'name'        => $p['name'],
            'description' => $p['description'],
            'category'    => $p['category'] ?? null,
            'image'       => !empty($p['image'])
                ? rtrim(SITE_URL, '/') . '/assets/images/products/' . $p['image']
                : rtrim(SITE_URL, '/') . '/assets/images/logo.png',
            'offer'       => $offer,
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order – <?= SHOP_NAME ?></title>
<?php require __DIR__ . '/../includes/seo_head.php'; ?>
    <meta name="description" content="Browse and order handcrafted ice cream and cocoa drinks at <?= SHOP_NAME ?>. Fresh flavours made daily, delivered to your door.">
    <?php if (!empty($cbMenuSchema)):
        $cbOrderUrl = rtrim(SITE_URL, '/') . '/pages/order.php';
        $cbItemList = [
            '@context'        => 'https://schema.org',
            '@type'           => 'ItemList',
            'itemListElement' => [],
        ];
        foreach ($cbMenuSchema as $i => $item) {
            $cbItemList['itemListElement'][] = [
                '@type'    => 'ListItem',
                'position' => $i + 1,
                'item'     => [
                    '@type'       => 'Product',
                    'name'        => $item['name'],
                    'description' => $item['description'],
                    'category'    => $item['category'],
                    'image'       => $item['image'],
                    'url'         => $cbOrderUrl . '#pcard-' . $item['id'],
                    'sku'         => (string)$item['id'],
                    'brand'       => ['@type' => 'Brand', 'name' => SHOP_NAME],
                    'offers'      => $item['offer'],
                ],
            ];
        }
    ?>
    <?php // No JSON_UNESCAPED_SLASHES here on purpose — product names and
          // descriptions are admin-editable free text, and an unescaped "/"
          // would let a stray "</script>" inside one close this tag early
          // for every visitor. Escaped slashes are inert either way. ?>
    <script type="application/ld+json"><?= json_encode($cbItemList, JSON_PRETTY_PRINT) ?></script>
    <?php endif; ?>
    <link rel="stylesheet" href="<?= cbAsset('../assets/css/style.css') ?>">
    <link rel="stylesheet" href="<?= cbAsset('../assets/css/responsive.css') ?>">
    <link rel="stylesheet" href="<?= cbAsset('../assets/css/animations.css') ?>">
    <link rel="stylesheet" href="<?= cbAsset('../assets/css/components.css') ?>">
    <link rel="stylesheet" href="<?= cbAsset('../assets/css/modal.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>

<!-- ══ Navbar ══════════════════════════════════════════════ -->
<?php
$cbNavActive = 'order';
ob_start(); ?>
<button class="btn-view-cart" id="cartToggle" onclick="openCart()" aria-label="View cart">
    <i class="fa-solid fa-bag-shopping"></i> <span class="cart-btn-text">View Cart</span>
    <span class="cart-badge" id="cartBadge">0</span>
</button>
<?php $cbNavRight = ob_get_clean();
ob_start(); ?>
<button class="btn-view-cart cbor-drawer-action-btn" onclick="closeMobileMenu(); openCart();">
    <i class="fa-solid fa-bag-shopping"></i> View Cart
</button>
<a href="checkout.php" class="btn-primary cbor-drawer-action-btn">
    <i class="fa-solid fa-bolt"></i> Order Now
</a>
<?php $cbNavDrawerRight = ob_get_clean();
require __DIR__ . '/../includes/site_header.php';
?>

<!-- ══ Variant Picker Modal ════════════════════════════════ -->
<div class="variant-picker-overlay" id="variantPicker">
    <div class="variant-picker-card" id="variantPickerCard">
        <button class="variant-picker-close" onclick="closeVariantPicker()" aria-label="Close">
            <i class="fa-solid fa-xmark"></i>
        </button>
        <div id="variantPickerImg"></div>
        <div class="variant-picker-title" id="variantPickerTitle"></div>
        <div class="variant-picker-label">Choose your size / option:</div>
        <div class="variant-pills" id="variantPills"></div>
    </div>
</div>

<!-- ══ Products / Menu ═════════════════════════════════════ -->
<section class="products-section cbor-menu-section" id="menu">
    <div class="container">
        <?php
        // ── Are we taking orders? ────────────────────────────
        //
        // Said HERE, at the top of the menu, and not left to the checkout. A
        // customer who picks out four tubs, opens the basket, presses through
        // to checkout and only THEN reads that the shop has stopped taking
        // deliveries has been let down twice — once by the shop being shut and
        // once by us for letting them do all that first.
        //
        // Calm, not alarming. Nothing here is broken; the shop has made a
        // decision about this evening, and the menu is still worth reading.
        // The checkout says it again, because that is where it is enforced.
        $cbDeliveryOpen   = cbOrderingOpen('delivery');
        $cbCollectionOpen = cbOrderingOpen('collection');
        if (!$cbDeliveryOpen || !$cbCollectionOpen):
            $cbBothClosed = !$cbDeliveryOpen && !$cbCollectionOpen;
            // With both off, cbOrderingClosedNote() returns the SAME sentence
            // whichever method it is asked about — one that points at neither,
            // because sending someone to collect from a shut shop is worse than
            // saying nothing. So it is asked once and printed once.
            $cbClosedType  = !$cbDeliveryOpen ? 'delivery' : 'collection';
            $cbClosedNote  = cbOrderingClosedNote($cbClosedType);
            // Same words as the checkout panel, deliberately. A customer who
            // reads this here and then goes through to checkout should meet the
            // same sentence, not a second differently-worded apology that reads
            // like a different problem. Time-neutral too — the owner may well
            // be closing at ten in the morning, not "for the evening".
            $cbClosedTitle = $cbBothClosed
                ? 'We are not taking orders just now'
                : ($cbClosedType === 'delivery'
                    ? 'Delivery is paused just now — collection is still running'
                    : 'Collection is paused just now — delivery is still running');
        ?>
        <div class="cbor-paused-notice" role="status">
            <i class="fa-solid fa-clock cbor-paused-notice-icon" aria-hidden="true"></i>
            <div class="cbor-paused-notice-body">
                <strong class="cbor-paused-notice-title"><?= htmlspecialchars($cbClosedTitle) ?></strong>
                <span class="cbor-paused-notice-text"><?= htmlspecialchars($cbClosedNote) ?></span>
            </div>
        </div>
        <?php endif; ?>
        <?php
        $cartNotice = $_SESSION['cart_notice'] ?? null;
        unset($_SESSION['cart_notice']);
        if ($cartNotice):
        ?>
        <div class="cbor-notice-warn">
            <i class="fa-solid fa-circle-info"></i> <?= htmlspecialchars($cartNotice) ?>
        </div>
        <?php endif; ?>
        <?php
        $isTradeUser = !empty($_SESSION['trade_user']);
        if ($isTradeUser):
        ?>
        <div class="cbor-trade-banner">
            <div>
                <i class="fa-solid fa-store cbor-trade-banner-icon"></i>
                <strong>Trade Wholesale Active:</strong> <?= htmlspecialchars($_SESSION['trade_user']['business_name']) ?>
                <span class="cbor-trade-banner-note">(Wholesale pricing applied)</span>
            </div>
            <div class="cbor-trade-banner-actions">
                <a href="trade_profile.php" class="btn-secondary cbor-trade-banner-btn">
                    <i class="fa-solid fa-user"></i> My Account
                </a>
                <a href="trade_logout.php" class="btn-secondary cbor-trade-banner-btn">
                    <i class="fa-solid fa-right-from-bracket"></i> Logout Trade
                </a>
            </div>
        </div>
        <?php else: ?>
        <div class="cbor-trade-cta">
            <div>
                <i class="fa-solid fa-store cbor-trade-cta-icon"></i>
                <strong>Are you a Shop, Cafe, or Restaurant owner?</strong> Get wholesale pricing on bulk tubs.
            </div>
            <div class="cbor-trade-cta-links">
                <a href="trade_register.php" class="cbor-trade-cta-link">Apply for Trade &rarr;</a>
                <span class="cbor-trade-cta-sep">|</span>
                <a href="trade_login.php" class="cbor-trade-cta-link">Trade Login &rarr;</a>
            </div>
        </div>
        <?php endif; ?>

        <div class="section-header">
            <span class="section-label">Our Menu</span>
            <h1 class="section-title">Pick Your Favourite</h1>
            <div class="cb-drip-line" aria-hidden="true"><span></span><span></span><span></span></div>
            <p class="section-subtitle">Every product is handcrafted fresh daily with premium ingredients. No preservatives, just pure deliciousness.</p>
        </div>

        <!-- Category filter tabs -->
        <div class="category-tabs" id="catTabs">
            <button class="cat-tab active" data-cat="all"><i class="fa-solid fa-ice-cream"></i> All</button>
            <?php foreach ($categories as $cat): ?>
            <button class="cat-tab" data-cat="<?= htmlspecialchars($cat['name']) ?>"><?= htmlspecialchars($cat['name']) ?></button>
            <?php endforeach; ?>
        </div>

        <!-- Products Grid -->
        <div class="products-grid" id="productsGrid">
            <?php foreach ($products as $product):
                $hasVariants  = !empty($variantsByProduct[$product['id']]);
                $variants     = $hasVariants ? $variantsByProduct[$product['id']] : [];
                $variantsJson = htmlspecialchars(json_encode($variants), ENT_QUOTES);

                // Price display
                if ($hasVariants) {
                    $prices   = array_column($variants, 'price');
                    $minPrice = min($prices);
                    $maxPrice = max($prices);
                } else {
                    $minPrice = $maxPrice = $product['price'];
                }

                $badgeClass = '';
                $badgeLabel = $product['badge'] ?? '';
                if ($badgeLabel === 'New')          $badgeClass = 'badge-new';
                elseif ($badgeLabel === 'Hot')      $badgeClass = 'badge-hot';
                elseif ($badgeLabel === 'Best Seller') $badgeClass = 'badge-best-seller';

                $imgSrc = !empty($product['image']) ? '../assets/images/products/' . htmlspecialchars($product['image']) : '';

                $isOutOfStock = ($product['track_stock'] ?? 0) && ($product['stock_qty'] ?? 1) <= 0;
                $stockLow     = ($product['track_stock'] ?? 0) && ($product['stock_qty'] ?? 0) > 0 && ($product['stock_qty'] ?? 0) <= 5;

                // Nothing to advertise on something we cannot sell today.
                $offerBadge = $isOutOfStock ? '' : cbOrderOfferBadge($product, $pageOffers);

                // Same per-flavour brand colour as the home page teaser —
                // shared logic in includes/flavour_colours.php so the two
                // pages can't disagree about what colour a flavour is.
                $cbFlavourClass = cbFlavourCardClass($product['name']);
            ?>
            <article class="product-card glass-panel<?php if ($isOutOfStock): ?> out-of-stock<?php endif; ?><?= $cbFlavourClass !== '' ? ' ' . $cbFlavourClass : '' ?>"
                     data-category="<?= htmlspecialchars($product['category']) ?>"
                     id="pcard-<?= $product['id'] ?>"
                     <?php if ($isOutOfStock): ?>data-available="0"<?php endif; ?>>

                <?php if (!empty($product['badge'])): ?>
                <span class="product-badge <?= $badgeClass ?>"><?= htmlspecialchars($product['badge']) ?></span>
                <?php endif; ?>

                <?php // The offer badge sits top LEFT, so a product can carry
                      // both this and its own New / Hot / Best Seller badge
                      // without either covering the other. ?>
                <?php if ($offerBadge !== ''): ?>
                <span class="cbof-badge"><i class="fa-solid fa-tag" aria-hidden="true"></i> <?= htmlspecialchars($offerBadge) ?></span>
                <?php endif; ?>

                <?php // Same top-left slot as the offer badge — never both at
                      // once, since $offerBadge is forced empty for an
                      // out-of-stock product above. ?>
                <?php if ($isOutOfStock): ?>
                <span class="cbor-oos-ribbon"><i class="fa-solid fa-ban" aria-hidden="true"></i> Out of Stock</span>
                <?php endif; ?>

                <!-- Product Image -->
                <div class="product-image-wrap">
                    <?php if ($imgSrc): ?>
                    <img src="<?= $imgSrc ?>" alt="<?= htmlspecialchars($product['name']) ?>" loading="lazy" onerror="this.style.display='none'; if(this.nextElementSibling) this.nextElementSibling.style.display='flex';">
                    <span class="product-image-placeholder cbor-hidden"><?= cbProductIcon($product['emoji'] ?? null) ?></span>
                    <?php else: ?>
                    <span class="product-image-placeholder"><?= cbProductIcon($product['emoji'] ?? null) ?></span>
                    <?php endif; ?>
                </div>

                <div class="product-body">
                    <span class="product-cat"><?= htmlspecialchars($product['category']) ?></span>
                    <h3 class="product-name"><?= htmlspecialchars($product['name']) ?></h3>
                    <p class="product-desc"><?= htmlspecialchars($product['description']) ?></p>

                    <?php if (!empty($product['nuts_allergy'])): ?>
                    <div class="allergy-tag">
                        <i class="fa-solid fa-triangle-exclamation"></i> Contains Nuts
                    </div>
                    <?php endif; ?>

                    <div class="product-footer">
                        <div>
                            <?php
                            // Sizes decide the price whenever a product has them.
                            //
                            // This used to check the product-level trade price
                            // FIRST, so a product with sizes advertised a price
                            // no size actually sold at — Rajbhog showed "Trade
                            // £4.59" on the card while its cheapest size cost
                            // £5.00. $minPrice/$maxPrice already come from the
                            // trade-adjusted variant rows, so they are correct
                            // for whoever is logged in.
                            $wPrice = (float)($product['wholesale_price'] ?? 0);
                            if ($hasVariants):
                            ?>
                            <?php if ($isTradeUser): ?>
                            <div class="product-price-from cbor-price-trade-label">Trade Wholesale</div>
                            <?php else: ?>
                            <div class="product-price-from">From</div>
                            <?php endif; ?>
                            <div class="product-price">£<?= number_format($minPrice, 2) ?></div>
                            <?php if ($minPrice < $maxPrice): ?>
                            <div class="product-price-range">up to £<?= number_format($maxPrice, 2) ?></div>
                            <?php endif; ?>
                            <?php elseif ($isTradeUser && $wPrice > 0): ?>
                            <div class="product-price-from cbor-price-trade-label">Trade Wholesale</div>
                            <div class="product-price">£<?= number_format($wPrice, 2) ?> <s class="cbor-price-strike">£<?= number_format($product['price'], 2) ?></s></div>
                            <?php else: ?>
                            <div class="product-price">£<?= number_format($product['price'], 2) ?></div>
                            <?php endif; ?>
                            <?php if ($stockLow && !$isOutOfStock): ?>
                            <div class="cbor-stock-low"><i class="fa-solid fa-triangle-exclamation"></i> Only <?= (int)$product['stock_qty'] ?> left!</div>
                            <?php endif; ?>
                            <!-- In-cart indicator -->
                            <div class="in-cart-indicator" id="incart-<?= $product['id'] ?>">
                                <i class="fa-solid fa-check"></i>
                                <span id="incart-qty-<?= $product['id'] ?>">0</span> in cart
                            </div>
                        </div>
                        <?php if ($isOutOfStock): ?>
                        <button class="btn-add-cart cbor-btn-oos" disabled
                            title="Out of Stock">Out of Stock</button>
                        <?php else: ?>
                        <button class="btn-add-cart"
                            onclick="handleAddToCart(
                                <?= $product['id'] ?>,
                                '<?= addslashes($product['name']) ?>',
                                <?php // The raw column value, not markup: cbIconHtml() turns it
                                      // into an <i> at the point the picker renders it. ?>
                                '<?= addslashes($product['emoji'] ?? 'ice-cream') ?>',
                                '<?= addslashes($imgSrc) ?>',
                                <?= $hasVariants ? 'true' : 'false' ?>,
                                '<?= $variantsJson ?>',
                                this
                            )"
                            aria-label="Add <?= htmlspecialchars($product['name']) ?> to cart"
                            id="addbtn-<?= $product['id'] ?>"
                            title="<?= $hasVariants ? 'Choose size' : 'Add to cart' ?>">
                            <i class="fa-solid fa-<?= $hasVariants ? 'caret-down' : 'plus' ?>"></i>
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- ══ Cart Sidebar ════════════════════════════════════════ -->
<div class="cart-overlay" id="cartOverlay" onclick="closeCart()"></div>
<aside class="cart-sidebar" id="cartSidebar">
    <div class="cart-header">
        <h2><i class="fa-solid fa-bag-shopping cbor-cart-title-icon"></i> Your Order</h2>
        <button class="btn-close-cart" onclick="closeCart()" aria-label="Close cart">
            <i class="fa-solid fa-xmark"></i>
        </button>
    </div>

    <div class="cart-items" id="cartItems">
        <div class="cart-empty">
            <div class="cart-empty-icon"><i class="fa-solid fa-basket-shopping"></i></div>
            <p>Your cart is empty.<br>Add something delicious!</p>
        </div>
    </div>

    <div class="cart-footer cbor-hidden" id="cartFooter">
        <?php // The total, any offer that has come off it, an earned gift and
              // the lines of encouragement — all rendered by the SERVER from
              // computeOrderTotals(), the same function that makes the charge.
              // This box used to hold one figure typed in by the browser from
              // cart_handler.php's basket total, which stopped being what the
              // customer pays the moment an offer applied to the basket. ?>
        <div id="cbofCartSummary"><?php if ($drawerSummary !== ''): ?><?= $drawerSummary ?><?php else: ?>
            <div class="cart-total-row">
                <span class="cart-total-label">Total</span>
                <span class="cart-total-amount" id="cartTotal">£0.00</span>
            </div>
            <?php endif; ?></div>
        <a href="checkout.php" class="btn-primary btn-checkout">
            <i class="fa-solid fa-arrow-right"></i> Proceed to Checkout
        </a>
        <button class="btn-clear-cart" onclick="clearCart()">
            <i class="fa-solid fa-trash"></i> Clear Cart
        </button>
    </div>
</aside>

<!-- ══ Toast ════════════════════════════════════════════════ -->
<div class="toast" id="toast">
    <span class="toast-icon"><i class="fa-solid fa-ice-cream"></i></span>
    <div>
        <div class="toast-text" id="toastText">Added to cart!</div>
        <div class="toast-sub" id="toastSub"></div>
    </div>
</div>

<!-- ══ Footer ══════════════════════════════════════════════ -->
<?php // One shared footer — it used to be copied into five pages, so adding a
      // link meant editing all five and hoping none were missed. ?>
<?php require __DIR__ . '/../includes/site_footer.php'; ?>

<!-- ══ Scripts ══════════════════════════════════════════════ -->
<script>
// ── Cart state ──────────────────────────────────────────────
let cartState = { items: [], cart_count: 0, cart_total: '0.00' };

// ── Open / Close cart ────────────────────────────────────────
function openCart()  { document.getElementById('cartSidebar').classList.add('open'); document.getElementById('cartOverlay').classList.add('open'); document.body.style.overflow = 'hidden'; }
function closeCart() { document.getElementById('cartSidebar').classList.remove('open'); document.getElementById('cartOverlay').classList.remove('open'); document.body.style.overflow = ''; }

// ── Variant Picker ───────────────────────────────────────────
let currentPickerData = null;

function handleAddToCart(productId, name, emoji, imgSrc, hasVariants, variantsJson, fromEl) {
    if (!hasVariants) {
        addToCart(productId, 0, name, emoji, null, null, fromEl);
        return;
    }
    // Parse variants
    let variants;
    try { variants = JSON.parse(variantsJson); } catch(e) { variants = []; }
    if (!variants.length) { addToCart(productId, 0, name, emoji, null, null, fromEl); return; }

    openVariantPicker(productId, name, emoji, imgSrc, variants, fromEl);
}

function openVariantPicker(productId, name, emoji, imgSrc, variants, fromEl) {
    currentPickerData = { productId, name, emoji, imgSrc, variants, fromEl };

    // Set image/emoji
    const imgEl = document.getElementById('variantPickerImg');
    if (imgSrc) {
        imgEl.innerHTML = `<img class="variant-picker-img" src="${escHtml(imgSrc)}" alt="${escHtml(name)}">`;
    } else {
        imgEl.innerHTML = `<span class="variant-picker-emoji">${cbIconHtml(emoji)}</span>`;
    }

    document.getElementById('variantPickerTitle').textContent = name;

    // Build pills
    const pillsEl = document.getElementById('variantPills');
    pillsEl.innerHTML = '';
    variants.forEach(v => {
        const btn = document.createElement('button');
        btn.className = 'variant-pill';
        btn.setAttribute('data-variant-id', v.id);
        btn.innerHTML = `<span class="variant-pill-name">${escHtml(v.name)}</span><span class="variant-pill-price">£${parseFloat(v.price).toFixed(2)}</span>`;
        btn.addEventListener('click', () => {
            // Highlight selected
            pillsEl.querySelectorAll('.variant-pill').forEach(p => p.classList.remove('selected'));
            btn.classList.add('selected');
            // Short delay then add and close
            setTimeout(() => {
                addToCart(productId, v.id, name, emoji, v.name, v.price, currentPickerData ? currentPickerData.fromEl : null);
                closeVariantPicker();
            }, 180);
        });
        pillsEl.appendChild(btn);
    });

    document.getElementById('variantPicker').classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closeVariantPicker() {
    document.getElementById('variantPicker').classList.remove('open');
    document.body.style.overflow = '';
    currentPickerData = null;
}

// Close on backdrop click
document.getElementById('variantPicker').addEventListener('click', function(e) {
    if (e.target === this) closeVariantPicker();
});
// Close on Escape
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') { closeCart(); closeMobileMenu(); closeVariantPicker(); }
});

// ── Add to Cart ──────────────────────────────────────────────
function addToCart(productId, variantId, name, emoji, variantName, variantPrice, fromEl) {
    variantId = variantId || 0;
    let body = 'action=add&product_id=' + productId;
    if (variantId > 0) body += '&variant_id=' + variantId;

    fetch('../cart_handler.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body,
    })
    .then(r => r.json())
    .then(data => {
        if (data.success !== false) {
            cartState = data;
            renderCart();
            updateInCartIndicators();
            const label = variantName ? name + ' (' + variantName + ')' : name;
            showToast('Added to Order!', label + ' added to your cart');
            bumpBadge();

            // Little fanfare: sprinkles fly from wherever the add happened.
            if (typeof cbSprinkleBurst === 'function' && fromEl) {
                cbSprinkleBurst(fromEl, 10);
            }

            if (!sessionStorage.getItem('delivery_popup_shown')) {
                sessionStorage.setItem('delivery_popup_shown', '1');
                setTimeout(showDeliveryRadiusPopup, 600);
            }
        } else {
            // The server refuses for real reasons — sold out, trade-only,
            // withdrawn. This branch used to be missing entirely, so the
            // click simply did nothing and the customer had no idea why.
            showToast('Cannot add this item', data.message || 'That item is not available right now.');
        }
    })
    .catch(() => showToast('Error', 'Could not add item, please try again.'));
}

// ── Remove item ──────────────────────────────────────────────
function removeItem(cartKey) {
    fetch('../cart_handler.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=remove&cart_key=' + encodeURIComponent(cartKey),
    })
    .then(r => r.json()).then(data => { cartState = data; renderCart(); updateBadge(); updateInCartIndicators(); });
}

// ── Update quantity ───────────────────────────────────────────
function updateQty(cartKey, qty) {
    fetch('../cart_handler.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=update&cart_key=' + encodeURIComponent(cartKey) + '&quantity=' + qty,
    })
    .then(r => r.json()).then(data => { cartState = data; renderCart(); updateBadge(); updateInCartIndicators(); });
}

// ── Clear cart ───────────────────────────────────────────────
function clearCart() {
    fetch('../cart_handler.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=clear',
    })
    .then(r => r.json()).then(data => { cartState = data; renderCart(); updateBadge(); updateInCartIndicators(); });
}

// ── Render cart sidebar ──────────────────────────────────────
function renderCart() {
    const itemsEl  = document.getElementById('cartItems');
    const footerEl = document.getElementById('cartFooter');

    if (!cartState.items || cartState.items.length === 0) {
        itemsEl.innerHTML = `<div class="cart-empty"><div class="cart-empty-icon"><i class="fa-solid fa-basket-shopping"></i></div><p>Your cart is empty.<br>Add something delicious!</p></div>`;
        footerEl.style.display = 'none';
        return;
    }

    let html = '';
    cartState.items.forEach(item => {
        const subtotal  = (item.price * item.quantity).toFixed(2);
        const cartKey   = item.cart_key || item.product_id;
        const safeKey   = encodeURIComponent(cartKey);
        const imgHtml   = item.image
            ? `<img class="cart-item-img" src="../assets/images/products/${escHtml(item.image)}" alt="${escHtml(item.name)}">`
            : `<div class="cart-item-img-placeholder">${cbIconHtml(item.emoji)}</div>`;
        const variantLabel = item.variant_name ? `<span class="cbo-variant-label">${escHtml(item.variant_name)}</span>` : '';

        // Trade lines move a whole case per press. The step comes from the
        // server on each line, so the buttons cannot disagree with the rule
        // the handler actually enforces — stepping by 1 against an 8-per-case
        // item would just get rounded back and look like a dead button.
        const step  = Math.max(1, parseInt(item.case_qty, 10) || 1);
        const cases = step > 1 ? Math.round(item.quantity / step) : 0;
        const caseLabel = step > 1
            ? `<div class="cbo-case-note">${cases} case${cases === 1 ? '' : 's'} · ${step} per case</div>`
            : '';

        html += `
        <div class="cart-item">
            ${imgHtml}
            <div class="cart-item-info">
                <div class="cart-item-name">${escHtml(item.name)}${variantLabel ? '<br>' + variantLabel : ''}</div>
                ${caseLabel}
                <div class="cart-item-price">£${subtotal}</div>
            </div>
            <div class="qty-controls">
                <button class="qty-btn" onclick="updateQty('${cartKey}', ${item.quantity - step})"
                        title="${step > 1 ? 'Remove one case (' + step + ')' : 'Remove one'}">−</button>
                <span class="qty-value">${item.quantity}</span>
                <button class="qty-btn" onclick="updateQty('${cartKey}', ${item.quantity + step})"
                        title="${step > 1 ? 'Add one case (' + step + ')' : 'Add one'}">+</button>
            </div>
            <button class="btn-remove-item" onclick="removeItem('${cartKey}')" title="Remove">
                <i class="fa-solid fa-xmark"></i>
            </button>
        </div>`;
    });

    itemsEl.innerHTML = html;
    footerEl.style.display = 'block';

    // The total is no longer typed in here. cartState.cart_total is what the
    // items add up to before any offer, and printing it next to "Buy one, get
    // one free" would show the customer a figure they are not being charged.
    // The server renders that whole block instead.
    refreshCartSummary();
} // ← end renderCart

// ── The drawer's money block ─────────────────────────────────
//
// NOTHING about money is worked out down here. The page asks order.php for the
// figures computeOrderTotals() would actually charge and puts the finished
// block on screen. Only the newest answer is allowed to land: two quick presses
// of "+" would otherwise race, and the slower reply could arrive last.
let cartSummaryRequestId = 0;
function refreshCartSummary() {
    const mine = ++cartSummaryRequestId;

    return fetch('order.php?summary=json', { headers: { 'Accept': 'application/json' } })
        .then(r => r.json())
        .then(data => {
            if (mine !== cartSummaryRequestId) return;   // a newer answer is on its way
            if (!data || !data.ok || typeof data.html !== 'string' || data.html === '') return;
            const box = document.getElementById('cbofCartSummary');
            if (box) box.innerHTML = data.html;
        })
        .catch(() => {
            // Leave the last figures the server gave us on screen. They are
            // stale by one change at worst; invented ones would be wrong.
        });
}

// ── Update in-cart indicators on product cards ───────────────

function updateInCartIndicators() {
    // Sum quantity per product_id (across all variants)
    const qtyMap = {};
    if (cartState.items) {
        cartState.items.forEach(item => {
            const pid = item.product_id;
            qtyMap[pid] = (qtyMap[pid] || 0) + item.quantity;
        });
    }
    document.querySelectorAll('[id^="incart-"]').forEach(el => {
        const pid  = el.id.replace('incart-', '');
        const qty  = qtyMap[pid] || 0;
        const qtyEl = document.getElementById('incart-qty-' + pid);
        if (qty > 0) {
            el.classList.add('visible');
            if (qtyEl) qtyEl.textContent = qty;
        } else {
            el.classList.remove('visible');
        }
    });
}

// ── Badge helpers ────────────────────────────────────────────
function updateBadge() {
    document.getElementById('cartBadge').textContent = cartState.cart_count || 0;
}
function bumpBadge() {
    const badge = document.getElementById('cartBadge');
    badge.textContent = cartState.cart_count;
    badge.classList.add('bump');
    setTimeout(() => badge.classList.remove('bump'), 400);
}

// ── Toast ────────────────────────────────────────────────────
let toastTimeout;
function showToast(title, sub) {
    const toast = document.getElementById('toast');
    document.getElementById('toastText').textContent = title;
    document.getElementById('toastSub').textContent  = sub || '';
    toast.classList.add('show');
    clearTimeout(toastTimeout);
    toastTimeout = setTimeout(() => toast.classList.remove('show'), 3000);
}

// ── Category filter ──────────────────────────────────────────
//  EVERY card in the new set is animated the same way, so each tab behaves
//  identically no matter what was on screen before. Animating only the
//  newly-arriving cards looked inconsistent: on one tab a card faded in, on
//  the next the same card just appeared, because it happened to be visible
//  already. One rule for all of them keeps it predictable.
document.querySelectorAll('.cat-tab').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.cat-tab').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');

        const cat = btn.dataset.cat;
        let shown = 0;

        const STEPS = 10;   // must match the .cb-step-* rules in animations.css
        const clearSteps = card => {
            for (let i = 0; i < STEPS; i++) card.classList.remove('cb-step-' + i);
            card.classList.remove('cb-step-max');
        };

        document.querySelectorAll('.product-card').forEach(card => {
            const show = cat === 'all' || card.dataset.category === cat;

            if (!show) {
                card.classList.add('cb-filtered-out');
                card.classList.remove('cb-filter-in');
                clearSteps(card);
                return;
            }

            card.classList.remove('cb-filtered-out');
            card.classList.remove('cb-filter-in');
            clearSteps(card);
            void card.offsetWidth;                          // restart the animation
            // Cascade step is a class, never a style attribute.
            card.classList.add(shown < STEPS ? 'cb-step-' + shown : 'cb-step-max');
            card.classList.add('cb-filter-in');
            shown++;
        });
    });
});

// ── Escape HTML helper ───────────────────────────────────────
function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── Product icon helper ──────────────────────────────────────
// The JS twin of cbProductIcon() at the top of this file. products.emoji holds
// either a bare Font Awesome name ('ice-cream') or, on a catalogue that has
// not been migrated yet, the original emoji character — and the cart JSON
// carries whichever it is straight through. Only a value that matches the
// name pattern is allowed into the class attribute; anything else is escaped
// and printed as text, exactly as it was before.
const CB_ICON_MAP = <?= cbProductIconMapJson() ?>;
function cbIconHtml(value) {
    const raw = (value === null || value === undefined)
        ? '' : String(value).replace(/\uFE0F/g, '').trim();
    if (!raw) return '<i class="fa-solid fa-ice-cream" aria-hidden="true"></i>';
    // Same order of precedence as cbProductIcon() in includes/product_icons.php:
    // a known emoji, then an icon name in any stored form, then escaped text.
    if (Object.prototype.hasOwnProperty.call(CB_ICON_MAP, raw)) {
        return '<i class="' + CB_ICON_MAP[raw] + '" aria-hidden="true"></i>';
    }
    const m = raw.match(/^(?:fa-(?:solid|regular|brands)\s+)?(?:fa-)?([a-z][a-z0-9-]*)$/);
    return m
        ? '<i class="fa-solid fa-' + m[1] + '" aria-hidden="true"></i>'
        : escHtml(raw);
}

// closeMobileMenu() (used above in the Escape handler and in the drawer's
// View Cart button) now lives in includes/site_header.php, shared by
// every page instead of defined here on its own.

// ── Load cart on page start ──────────────────────────────────
fetch('../cart_handler.php?action=get')
    .then(r => r.json())
    .then(data => { cartState = data; renderCart(); updateBadge(); updateInCartIndicators(); });

function showDeliveryRadiusPopup() {
    const pop = document.getElementById('deliveryRadiusPopup');
    if (pop) pop.style.display = 'block';
}
function closeDeliveryPopup() {
    const pop = document.getElementById('deliveryRadiusPopup');
    if (pop) pop.style.display = 'none';
}
</script>

<!-- ── Delivery 6-Mile Radius Notification Pop-up ────────────── -->
<div id="deliveryRadiusPopup" class="cbor-delivery-popup">
    <div class="cbor-delivery-popup-row">
        <div class="cbor-delivery-popup-emoji"><i class="fa-solid fa-truck-fast"></i></div>
        <div class="cbor-delivery-popup-body">
            <strong class="cbor-delivery-popup-title">
                <i class="fa-solid fa-location-dot"></i> Harrow Delivery Area Notice
            </strong>
            <p class="cbor-delivery-popup-text">
                We deliver fresh handcrafted ice cream within a <strong>6-mile radius of Harrow (HA1 4EX / HA1 2SP)</strong>.<br>
                <span class="cbor-delivery-popup-highlight"><i class="fa-solid fa-gift"></i> Free delivery under 3 miles!</span>
            </p>
            <div class="cbor-delivery-popup-foot">
                <i class="fa-solid fa-store cbor-delivery-popup-foot-icon"></i> Warehouse collection is also available!
            </div>
        </div>
        <button onclick="closeDeliveryPopup()" class="cbor-delivery-popup-close">
            <i class="fa-solid fa-xmark"></i>
        </button>
    </div>
</div>
<script src="<?= cbAsset('../assets/js/modal.js') ?>" defer></script>
<script src="<?= cbAsset('../assets/js/animations.js') ?>" defer></script>
</body>
</html>
