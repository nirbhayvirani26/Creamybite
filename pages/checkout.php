<?php
// ============================================================
//  Creamy Bite – Checkout Page
// ============================================================
session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/product_icons.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/pricing.php';
require_once __DIR__ . '/../includes/trade_cart.php';
tradeSessionRevalidate($pdo);

// Load cart
$cart = $_SESSION['cart'] ?? [];
$errors = $_SESSION['checkout_errors'] ?? [];

// ── The order summary, asked for on its own ──────────────────
//
// Every figure on this page can move while the customer is standing on it:
// they change a quantity, type a postcode, switch to collection, apply a code.
// Each of those numbers has to be the one computeOrderTotals() would actually
// charge, so the page asks the SERVER to work the summary out again and hands
// back finished HTML. Nothing about money is worked out in the browser — a
// page that does its own arithmetic is how a customer ends up seeing a
// different number from the one on their card.
//
// The cart drawer on order.php answers the same question for itself, in its
// own markup, through the same computeOrderTotals().
//
// POST rather than GET: a postcode is the customer's own address, and personal
// details do not belong in a URL.
$summaryJson = (($_POST['summary'] ?? $_GET['summary'] ?? '') === 'json');

if ($summaryJson) {
    // A background refresh must NOT swallow the one-shot error list — those
    // belong to the next real page load, not to a summary request.
    $errors = [];
} else {
    unset($_SESSION['checkout_errors']);

    // Redirect if cart empty
    if (empty($cart)) {
        header('Location: order.php?empty_cart=1');
        exit;
    }
}

// Totals come from pricing.php, the same code that creates the Stripe
// charge and writes the order — so what is shown here is what is charged.
// Delivery is 0 until a postcode is entered; the JS below updates it.
$rawSubtotal = 0.0;
foreach ($cart as $item) {
    $rawSubtotal += (float)($item['price'] ?? 0) * (int)($item['quantity'] ?? 0);
}

// Only show a promo the server would actually honour. validatedPromoRow()
// re-checks the code, its expiry, its usage limit and the minimum order, so
// a code that no longer qualifies is dropped from the session here rather
// than being rendered as applied while checkout quietly charges full price.
$promoRow = validatedPromoRow($pdo, round($rawSubtotal, 2));
if (!empty($_SESSION['promo']) && !$promoRow) {
    unset($_SESSION['promo']);
    $errors[] = 'Your promo code no longer applies to this basket and has been removed.';
}
$appliedPromo = $_SESSION['promo'] ?? null;
$isTradeUser  = !empty($_SESSION['trade_user']);
$tradeUser    = $_SESSION['trade_user'] ?? [];

// ── Is the shop taking orders? ───────────────────────────────
//
// Two switches the owner flips by hand in the admin panel, read once here.
// Everything on this page that depends on them — which method is ticked when
// the page opens, which one can be chosen at all, whether the form is offered
// — follows from these four lines and nothing else.
//
// NONE OF THIS IS ENFORCEMENT. Hiding a radio button stops nobody: a customer
// who had this page open when the shop closed still has a live form in front
// of them, and the address bar is not a security boundary. checkout_handler.php
// and stripe_intent.php each make the same check on the way in, and those are
// the ones that actually refuse the order. This is only about not wasting
// somebody's evening filling in a form that was never going to work.
$cbDeliveryOpen   = cbOrderingOpen('delivery');
$cbCollectionOpen = cbOrderingOpen('collection');
$cbAnyOpen        = cbAnyOrderingOpen();

// Delivery is the default and always has been. The one case where that is
// wrong is when delivery is the method the owner has switched off — opening on
// it would greet the customer with a postcode box, a minimum-order warning and
// a delivery charge, all for an order the shop is not taking, and leave them to
// work out for themselves that the other button is the live one.
//
// Nothing else moves the default. With BOTH closed this stays 'delivery',
// which never reaches the screen: the form is replaced further down.
$cbDefaultOrderType = (!$cbDeliveryOpen && $cbCollectionOpen) ? 'collection' : 'delivery';

// What the summary is being asked about. The page itself always renders the
// "postcode not given yet" case, exactly as it did before; a summary request
// says which order type and postcode the customer has since chosen.
//
// With BOTH methods shut there is no order type — nobody is choosing anything
// — so the basket is priced the collection way: no delivery row, no minimum,
// and none of the delivery banner's promises. Priced as a delivery it sat next
// to the "we are not taking orders" panel cheerfully inviting the customer to
// "enter your postcode above and we will show you the delivery cost", with no
// postcode box anywhere on the page to enter it into.
$summaryOrderType = $cbAnyOpen ? $cbDefaultOrderType : 'collection';
$summaryPostcode  = '';
if ($summaryJson) {
    $summaryOrderType = (($_POST['order_type'] ?? '') === 'collection') ? 'collection' : 'delivery';
    // A summary asked for on a method that has since been switched off is
    // priced as the method the customer can actually use, so the figures on
    // screen stay figures they could really be charged — a delivery charge and
    // a minimum-order line for an order the shop will refuse is worse than no
    // answer. Refusing the ORDER is the handler's job; this is only arithmetic.
    //
    // With both shut this lands on 'collection' for the reason given above the
    // page's own $summaryOrderType: it is the pricing with nothing added to it.
    // That case is reached in earnest — the basket beside the closed panel is
    // still live, and every +/− press asks this endpoint for new figures.
    if (!cbOrderingOpen($summaryOrderType)) {
        $summaryOrderType = $cbDeliveryOpen ? 'delivery' : 'collection';
    }
    $summaryPostcode  = strtoupper(trim((string)($_POST['postcode'] ?? '')));
    // Only a real UK postcode is passed on. calculateDeliveryCharge() calls
    // postcodes.io for anything else it is given, and a half-typed postcode is
    // a lookup that can only fail.
    if (!preg_match('/^[A-Z]{1,2}[0-9][0-9A-Z]?\s*[0-9][A-Z]{2}$/', $summaryPostcode)) {
        $summaryPostcode = '';
    }
}

$totals = computeOrderTotals($cart, $promoRow, $summaryOrderType, $summaryPostcode);

$cartTotal      = $totals['subtotal'];
$discountAmount = $totals['discount'];
$vatApplies     = $totals['vat_applies'];
$vatRate        = $totals['vat_rate'];
$vatAmount      = $totals['vat'];
$grandTotal     = $totals['total'];

/**
 * The Delivery / Collection chooser.
 *
 * Both checkouts on this page offer the same two methods — retail says
 * "Delivery" and "Collection", trade says "Store Delivery" and "Warehouse
 * Collection" — and both have to react identically when the owner switches one
 * of them off. Written out twice they would drift, and the one that drifts is
 * always the one nobody tested.
 *
 * A CLOSED METHOD IS SHOWN, GREYED OUT, NOT REMOVED. Dropping it would leave a
 * lone unexplained button and a customer wondering whether this shop collects
 * at all, or whether the page is broken. Greyed out, with the reason written
 * underneath in the owner's own words, answers the question they were about to
 * ask — and the shape of the page does not change under someone who has used
 * it before.
 *
 * The radio for a closed method is `disabled`, so it cannot be ticked, is
 * skipped by the keyboard, and is not submitted. That is courtesy, not
 * security: checkout_handler.php and stripe_intent.php refuse the order
 * regardless of what arrives.
 *
 * @param string $legend          Wording above the pair, without the asterisk.
 * @param string $deliveryLabel   What this checkout calls delivery.
 * @param string $collectionLabel What this checkout calls collection.
 * @param string $defaultType     Which one opens ticked — see $cbDefaultOrderType.
 */
function cbOrderTypeChooser(
    string $legend,
    string $deliveryLabel,
    string $collectionLabel,
    string $defaultType
): string {
    $esc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

    $out = '<div class="form-group cbco-mb-20">'
         . '<label class="form-label cbco-order-type-label">' . $esc($legend) . ' *</label>'
         . '<div class="cbco-order-type-grid">';

    foreach ([
        ['delivery',   $deliveryLabel,   'fa-truck-fast'],
        ['collection', $collectionLabel, 'fa-store'],
    ] as [$type, $label, $icon]) {

        $open   = cbOrderingOpen($type);
        $picked = $open && $type === $defaultType;

        // Three states, not two. The pair used to be simply active/idle, and
        // toggleOrderType() still swaps those two round as the customer picks;
        // -closed is written later in components.css than -idle, so it keeps
        // its greyed-out look even after that swap has run.
        $state = !$open ? 'cbco-order-type-option-closed'
                        : ($picked ? 'cbco-order-type-option-active' : 'cbco-order-type-option-idle');

        // The icon and the wording follow the same three states, so a paused
        // method never sits there in full brand colour looking available.
        $tone = !$open ? 'cbco-text-muted' : ($picked ? 'cbco-icon-primary' : 'cbco-text-secondary');
        $textTone = !$open ? 'cbco-text-muted' : ($picked ? 'cbco-text-primary' : 'cbco-text-secondary');

        $out .= '<label id="type_' . $type . '_label" class="cbco-order-type-option ' . $state . '"'
              . (!$open ? ' aria-disabled="true"' : '') . '>'
              . '<input type="radio" name="order_type" value="' . $type . '"'
              . ($picked ? ' checked' : '')
              . (!$open ? ' disabled' : '')
              . ' class="cbco-hidden" onchange="toggleOrderType(\'' . $type . '\')">'
              . '<i class="fa-solid ' . $icon . ' cbco-order-type-icon ' . $tone . '" aria-hidden="true"></i>'
              . '<span class="cbco-order-type-text ' . $textTone . '">' . $esc($label) . '</span>';

        if (!$open) {
            $out .= '<span class="cbco-order-type-pill">'
                  . '<i class="fa-solid fa-clock" aria-hidden="true"></i> Paused</span>';
        }

        $out .= '</label>';
    }

    $out .= '</div>';

    // The explanation, once, under the pair — the owner's own sentence if they
    // wrote one. cbOrderingClosedNote() returns '' for a method that is
    // running, so an ordinary evening prints nothing here at all.
    //
    // Only ONE line can appear, because only one method can be closed by the
    // time this renders: with both closed the form is not on the page.
    foreach (['delivery', 'collection'] as $type) {
        $note = cbOrderingClosedNote($type);
        if ($note !== '') {
            $out .= '<p class="cbco-channel-note" role="status">'
                  . '<i class="fa-solid fa-circle-info" aria-hidden="true"></i> '
                  . $esc($note) . '</p>';
        }
    }

    return $out . '</div>';
}

/**
 * The lines of encouragement to print under the total.
 *
 * cbCartMessages() writes them all — the shop's standing message, what has
 * been earned, the delivery figures, the gentle nudges. Anything an offer row
 * already states in the summary above is dropped here, so the customer is not
 * told about the same buy-one-get-one twice: the row is the accounting, these
 * are the words.
 *
 * Never fatal. A shop whose offers cannot be read still has a checkout.
 *
 * $postcode is a PARAMETER, and has to be. It used to be read as a bare
 * $postcode inside the body, where no such variable exists — which printed
 * "Warning: Undefined variable $postcode" into the middle of the customer's
 * order summary, and then handed NULL to a `string` parameter, which throws.
 * The catch below swallowed the throw, so every line of encouragement on this
 * page silently went missing while the cart drawer on order.php still showed
 * them. Both symptoms had the same one-word cause.
 */
function cbCheckoutNotes(array $cart, array $totals, string $orderType, string $postcode = ''): array
{
    if (!function_exists('cbCartMessages')) {
        return [];
    }
    try {
        $messages = cbCartMessages($cart, (float)$totals['subtotal'], $orderType, $postcode);
    } catch (Throwable $e) {
        error_log('Cart messages skipped: ' . $e->getMessage());
        return [];
    }

    // Only an offer that ALREADY HAS A ROW of its own gets its message
    // dropped: one that took money off is listed above with the amount, and a
    // gift has its own row, so repeating either in words is just repetition.
    //
    // Free delivery is the exception. It has no row until a postcode has been
    // entered — before that there is nothing on screen to say delivery has
    // been earned, so its message is kept. $totals['delivery_is_free'] is true
    // exactly when the delivery row and the banner are already saying it.
    $deliveryRowSaysIt = !empty($totals['delivery_is_free']);
    $alreadyShown = [];
    foreach (($totals['offers_applied'] ?? []) as $offer) {
        $hasRow = round((float)($offer['discount'] ?? 0), 2) > 0
               || !empty($offer['free_items'])
               || (!empty($offer['free_delivery']) && $deliveryRowSaysIt);
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
 * Every money row of the order summary, as finished HTML.
 *
 * ONE renderer, used both for the page itself and for the refresh that follows
 * a quantity change. Two renderers — one in PHP, one in JavaScript — is how the
 * screen and the charge drift apart, so there is deliberately only this.
 *
 * Every figure in here comes straight out of computeOrderTotals(). Nothing is
 * added up, worked back or rounded a second time.
 */
function cbCheckoutSummaryFigures(
    array $totals,
    array $cart,
    ?array $appliedPromo,
    string $orderType,
    string $postcode,
    bool $isTradeUser
): string {
    $esc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $out = '';

    // ── Subtotal ─────────────────────────────────────────────
    $out .= '<div class="cbco-summary-row"><span>Subtotal</span>'
          . '<span id="subtotalDisplay">' . cbOfferMoney((float)$totals['subtotal']) . '</span></div>';

    // ── One row per offer, named the way the owner named it ──
    // Named rather than lumped into "Discount": a customer should be able to
    // see WHICH promotion took the money off, and match it to the one the
    // shop advertised.
    foreach (($totals['offers_applied'] ?? []) as $offer) {
        $amount = round((float)($offer['discount'] ?? 0), 2);
        if ($amount <= 0) {
            continue;   // free delivery and free gifts have their own rows
        }
        $label = trim((string)($offer['name'] ?? ''));
        if ($label === '') {
            $label = trim((string)($offer['badge'] ?? ''));
        }
        if ($label === '') {
            $label = 'Offer applied';
        }
        $out .= '<div class="cbco-summary-row cbco-summary-row-discount">'
              . '<span><i class="fa-solid fa-tag"></i> ' . $esc($label) . '</span>'
              . '<span>&minus;' . cbOfferMoney($amount) . '</span></div>';
    }

    // ── The code the customer typed, kept apart from the shop's own offers ──
    $promoDiscount = round((float)$totals['promo_discount'], 2);
    $out .= '<div id="discountRow" class="cbco-summary-row cbco-summary-row-discount'
          . ($promoDiscount > 0 ? '' : ' cbco-hidden') . '">'
          . '<span><i class="fa-solid fa-ticket"></i> '
          . ($appliedPromo ? 'Code ' . $esc($appliedPromo['code']) : 'Promo code')
          . '</span>'
          . '<span id="discountDisplay">&minus;' . cbOfferMoney($promoDiscount) . '</span></div>';

    // ── Delivery ─────────────────────────────────────────────
    // Wholesale is not billed per drop, so a trade basket never has this row.
    if (!$isTradeUser) {
        $deliveryClass = 'cbco-summary-row cbco-summary-row-delivery';
        $deliveryValue = '';
        if (!empty($totals['delivery_is_free'])) {
            // Something made a real charge disappear — say so rather than
            // silently dropping the row, so the saving is visible.
            $deliveryClass .= ' cbof-row-free';
            $deliveryValue  = 'On us';
        } elseif ((float)$totals['delivery'] > 0) {
            $deliveryValue = '+ ' . cbOfferMoney((float)$totals['delivery']);
        } else {
            $deliveryClass .= ' cbco-hidden';
        }
        $out .= '<div id="deliveryChargeRow" class="' . $deliveryClass . '">'
              . '<span><i class="fa-solid fa-truck-fast"></i> Delivery</span>'
              . '<span id="deliveryChargeAmt">' . $deliveryValue . '</span></div>';
    }

    // ── VAT (trade partners with a VAT number only) ──────────
    if (!empty($totals['vat_applies'])) {
        $out .= '<div id="vatRow" class="cbco-summary-row cbco-summary-row-vat">'
              . '<span><i class="fa-solid fa-receipt"></i> VAT @ '
              . (int)round((float)$totals['vat_rate'] * 100) . '%</span>'
              . '<span id="vatDisplay">+ ' . cbOfferMoney((float)$totals['vat']) . '</span></div>';
    }

    $out .= '<hr class="summary-divider cbco-summary-divider-tight">';
    $out .= '<div class="summary-total-row"><span class="summary-total-label">Total</span>'
          . '<span class="summary-total-price" id="summaryTotal">'
          . cbOfferMoney((float)$totals['total']) . '</span></div>';

    if (!empty($totals['vat_applies']) && function_exists('tradeVatNumber')) {
        $out .= '<div class="cbco-vat-note">VAT charged against <strong>'
              . $esc(tradeVatNumber()) . '</strong></div>';
    }

    // ── A gift that has been earned ──────────────────────────
    // Not money off — an extra thing in the box — so it sits below the total
    // rather than pretending to be a discount.
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
    $notes = cbCheckoutNotes($cart, $totals, $orderType, $postcode);
    if ($notes !== []) {
        $out .= '<div class="cbof-notes">';
        foreach ($notes as $note) {
            $out .= '<p class="cbof-note">' . $esc($note) . '</p>';
        }
        $out .= '</div>';
    }

    // ── The delivery banner under the total ──────────────────
    // Written here, from the same figures, so it can never advertise a
    // delivery price the summary above disagrees with.
    if (!$isTradeUser && $orderType !== 'collection') {
        $bannerClass = 'cbco-delivery-banner';
        $textClass   = 'cbco-delivery-banner-text';
        $icon        = 'fa-location-dot';
        $bannerText  = 'Enter your postcode above and we will show you the delivery cost.';

        if ($postcode !== '') {
            $icon = 'fa-truck-fast';
            if ((float)$totals['delivery'] <= 0) {
                $bannerClass .= ' cbof-banner-good';
                $textClass   .= ' cbof-banner-text-good';
                $bannerText   = match ((string)($totals['delivery_free_reason'] ?? '')) {
                    'spend' => 'Delivery is on us — this basket is over our free delivery amount.',
                    'offer' => 'Delivery is on us with this order.',
                    default => 'Free delivery to your postcode.',
                };
            } else {
                $bannerClass .= ' cbof-banner-warn';
                $textClass   .= ' cbof-banner-text-warn';
                $bannerText   = 'Delivery to your postcode is '
                              . cbOfferMoney((float)$totals['delivery']) . '.';
            }
        }

        $out .= '<div id="deliveryInfoBanner" class="' . $bannerClass . '">'
              . '<p class="' . $textClass . '"><i class="fa-solid ' . $icon . '"></i> '
              . $esc($bannerText) . '</p></div>';
    }

    return $out;
}

/**
 * A one-line statement of what delivery costs, for the message under the
 * postcode box. The browser knows the distance; only the server knows the
 * price, because only the server knows what the offers did to it.
 */
function cbCheckoutDeliveryNote(array $totals, string $orderType, string $postcode, bool $isTradeUser): string
{
    if ($isTradeUser || $orderType === 'collection' || $postcode === '') {
        return '';
    }
    if ((float)$totals['delivery'] > 0) {
        return 'Delivery is ' . cbOfferMoney((float)$totals['delivery']) . '.';
    }
    return 'Delivery is on us.';
}

// ── The JSON reply ───────────────────────────────────────────
if ($summaryJson) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode([
        'ok'             => true,
        'item_count'     => count($cart),
        // The subtotal is the only raw number the page keeps, and only because
        // the minimum-order rule is measured against it — the same figure
        // checkout_handler.php and stripe_intent.php measure it against.
        'subtotal'       => (float)$totals['subtotal'],
        'delivery_note'  => cbCheckoutDeliveryNote($totals, $summaryOrderType, $summaryPostcode, $isTradeUser),
        'html'           => cbCheckoutSummaryFigures(
            $totals, $cart, $appliedPromo, $summaryOrderType, $summaryPostcode, $isTradeUser
        ),
    ]);
    exit;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout – <?= SHOP_NAME ?></title>
    <meta name="description" content="Complete your ice cream order at <?= SHOP_NAME ?>.">
    <?php // Cart contents and personal details pass through here — must never be indexed. ?>
    <meta name="robots" content="noindex, nofollow">
    <?php require __DIR__ . '/../includes/favicon.php'; ?>
    <link rel="stylesheet" href="<?= cbAsset('../assets/css/style.css') ?>">
    <link rel="stylesheet" href="<?= cbAsset('../assets/css/responsive.css') ?>">
    <link rel="stylesheet" href="<?= cbAsset('../assets/css/animations.css') ?>">
    <link rel="stylesheet" href="<?= cbAsset('../assets/css/components.css') ?>">
    <link rel="stylesheet" href="<?= cbAsset('../assets/css/modal.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://js.stripe.com/v3/"></script>
</head>
<body>

<!-- Navbar -->
<header class="navbar">
    <div class="container nav-container-centered">
        <nav class="nav-left">
            <ul class="nav-links">
                <li><a href="../index.php">Home</a></li>
                <li><a href="order.php">Order</a></li>
                <li><a href="gallery.php">Gallery</a></li>
                <li><a href="about.php">About Us</a></li>
            </ul>
        </nav>

        <a href="../index.php" class="logo logo-center">
            <img src="../assets/images/logo.png" alt="<?= SHOP_NAME ?>" class="logo-img">
        </a>

        <div class="nav-actions nav-right">
            <?php include __DIR__ . '/../includes/trade_nav_button.php'; ?>
            <a href="order.php" class="btn-secondary cbco-nav-back-btn">
                <i class="fa-solid fa-arrow-left"></i> Back to Menu
            </a>
            <button class="nav-hamburger" id="navHamburger" aria-label="Open menu"><span></span><span></span><span></span></button>
        </div>
    </div>
</header>

<!-- ══ Mobile Nav Drawer ══════════════════════════════════ -->
<div class="mobile-drawer" id="mobileDrawer">
    <div class="mobile-nav-panel">
        <button class="mobile-drawer-close" id="mobileDrawerClose" aria-label="Close menu">
            <i class="fa-solid fa-xmark"></i>
        </button>
        <ul class="mobile-nav-links">
            <li><a href="../index.php">Home</a></li>
            <li><a href="order.php">Order</a></li>
            <li><a href="gallery.php">Gallery</a></li>
            <li><a href="about.php">About Us</a></li>
        </ul>
        <div class="mobile-nav-actions">
            <a href="order.php" class="btn-secondary cbco-drawer-back-btn">
                <i class="fa-solid fa-arrow-left"></i> Back to Menu
            </a>
        </div>
    </div>
</div>

<!-- Checkout Content -->
<main class="checkout-page">
    <div class="container">

        <!-- Page Title -->
        <?php // "Almost There!" and "fill in your delivery details" are exactly
              // wrong above a panel that has just said the shop is not taking
              // orders — the customer is not almost there and there are no
              // details to fill in. The heading is part of the message, so it
              // changes with it. ?>
        <div class="cbco-page-head">
            <?php if ($cbAnyOpen): ?>
            <span class="section-label">Almost There!</span>
            <h1 class="cbco-page-title">Complete Your Order <i class="fa-solid fa-ice-cream cb-title-icon" aria-hidden="true"></i></h1>
            <p class="cbco-page-subtitle">Fill in your delivery details and we'll bring the sweetness to you.</p>
            <?php else: ?>
            <span class="section-label">Back Shortly</span>
            <h1 class="cbco-page-title">Your Basket <i class="fa-solid fa-ice-cream cb-title-icon" aria-hidden="true"></i></h1>
            <p class="cbco-page-subtitle">Everything you picked is here waiting — we will be taking orders again soon.</p>
            <?php endif; ?>
        </div>

        <div class="checkout-grid">

            <!-- ── Customer Form ──────────────────────────── -->
            <div>
                <?php if (!empty($errors)): ?>
                <div class="alert alert-danger cbco-mb-24" id="serverErrors">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <div>
                        <?php foreach ($errors as $err): ?>
                        <?php // Tagged so the page can drop it once the basket
                              // satisfies the rule. A rejection printed on page
                              // load has no idea the customer has since fixed it,
                              // and stays on screen contradicting the summary. ?>
                        <div<?= str_contains($err, 'Minimum order') ? ' data-clears-at="min-order"' : '' ?>><?= htmlspecialchars($err) ?></div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php // With BOTH methods switched off there is no order to
                      // take, so the form is not printed at all. Leaving it up
                      // — greyed out, or with a warning above it — invites
                      // somebody to fill in their address and their card and
                      // find out at the last press that none of it counted.
                      //
                      // The basket panel beside this stays exactly as it is.
                      // Nothing has been lost and it should look that way. ?>
                <?php if (!$cbAnyOpen): ?>
                <div class="glass-panel section-card cbco-paused-panel">
                    <div class="cbco-paused-mark" aria-hidden="true">
                        <i class="fa-solid fa-hourglass-half"></i>
                    </div>
                    <h2 class="cbco-paused-title">We are not taking orders just now</h2>
                    <?php // Both closed, so cbOrderingClosedNote() gives the same
                          // sentence for either method — asked once and printed
                          // once. It deliberately points at neither delivery nor
                          // collection, because neither is available. ?>
                    <p class="cbco-paused-note"><?= htmlspecialchars(cbOrderingClosedNote('delivery')) ?></p>
                    <p class="cbco-paused-sub">
                        Your basket is safe — everything in it will still be here when we open back up.
                    </p>
                    <div class="cbco-paused-actions">
                        <a href="order.php" class="btn-secondary cbco-paused-btn">
                            <i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Back to the menu
                        </a>
                        <a href="tel:<?= htmlspecialchars(SHOP_PHONE) ?>" class="btn-primary cbco-paused-btn">
                            <i class="fa-solid fa-phone" aria-hidden="true"></i> Call us on <?= htmlspecialchars(SHOP_PHONE) ?>
                        </a>
                    </div>
                </div>
                <?php else: ?>
                <div class="glass-panel section-card">
                    <?php // $isTradeUser and $tradeUser are settled at the top of
                          // the file — the summary renderer needs them before any
                          // of this markup runs. ?>
                    <?php if ($isTradeUser): ?>
                    <!-- ── B2B TRADE CUSTOMER CHECKOUT ────────────────────────── -->
                    <div class="cbco-trade-banner">
                        <div class="cbco-trade-banner-eyebrow"><i class="fa-solid fa-store cb-badge-icon" aria-hidden="true"></i>B2B Trade Wholesale Checkout</div>
                        <h2 class="cbco-trade-banner-title"><?= htmlspecialchars($tradeUser['business_name']) ?></h2>
                        <p class="cbco-trade-banner-address">
                            Registered Delivery Address: <strong><?= htmlspecialchars($tradeUser['address']) ?>, <?= htmlspecialchars($tradeUser['postcode']) ?></strong>
                        </p>
                    </div>

                    <h2><i class="fa-solid fa-user-check cbco-icon-primary"></i> Contact & Delivery Instructions</h2>

                    <form action="../checkout_handler.php" method="POST" id="checkoutForm">

                        <!-- Bot protection (honeypot) -->
                        <input type="text" name="website" id="hp_website" tabindex="-1" autocomplete="off" aria-hidden="true" class="cbco-honeypot">
                        <input type="hidden" name="form_loaded_at" id="hp_loaded_at" value="0">
                        <script>document.getElementById('hp_loaded_at').value = Date.now();</script>

                        <!-- Pre-filled hidden address fields for trade customer -->
                        <input type="hidden" name="address" value="<?= htmlspecialchars($tradeUser['address'] . ' (' . $tradeUser['business_name'] . ')') ?>">
                        <input type="hidden" name="delivery_postcode" id="delivery_postcode" value="<?= htmlspecialchars($tradeUser['postcode']) ?>">
                        <input type="hidden" name="delivery_charge" id="delivery_charge_input" value="0">

                        <div class="form-group">
                            <label for="customer_name" class="form-label">Contact Person Name *</label>
                            <input type="text" id="customer_name" name="customer_name" class="form-control"
                                placeholder="e.g. John Smith" required
                                value="<?= htmlspecialchars($_POST['customer_name'] ?? $tradeUser['contact_name']) ?>">
                        </div>

                        <div class="form-row cbco-trade-contact-grid">
                            <div class="form-group">
                                <label for="phone" class="form-label">Contact Mobile Number *</label>
                                <input type="tel" id="phone" name="phone" class="form-control"
                                    placeholder="e.g. 07123 456789" required
                                    value="<?= htmlspecialchars($_POST['phone'] ?? $tradeUser['phone']) ?>">
                            </div>
                            <div class="form-group">
                                <label for="customer_email" class="form-label">Work Email Address *</label>
                                <input type="email" id="customer_email" name="customer_email" class="form-control"
                                    placeholder="orders@yourstore.com" required
                                    value="<?= htmlspecialchars($_POST['customer_email'] ?? $tradeUser['email']) ?>">
                            </div>
                        </div>

                        <!-- Order Type Selector (Collection / Delivery) -->
                        <?= cbOrderTypeChooser(
                                'Order Delivery Method',
                                'Store Delivery',
                                'Warehouse Collection',
                                $cbDefaultOrderType
                            ) ?>

                        <!-- B2B Trade Delivery Instructions (Opening Hours & Delivery Place) -->
                        <div class="form-group cbco-mb-20">
                            <!-- Must NOT be name="notes": the general notes textarea further
                                 down renders for trade customers too, and a duplicate field
                                 name means PHP keeps only the last one — which silently threw
                                 these mandatory delivery instructions away. -->
                            <label for="trade_instructions" id="trade_instructions_label" class="form-label cbco-label-strong">
                                <i class="fa-solid fa-clock cbco-icon-primary"></i> Store Opening Hours &amp; Delivery Instructions *
                            </label>
                            <textarea id="trade_instructions" name="trade_instructions" class="form-control" rows="3"
                                placeholder="Please specify:
1. Store opening hours (e.g. 9:00 AM - 8:00 PM)
2. Delivery place / drop-off location (e.g. Rear loading bay / Front counter)
3. Any access codes or special delivery notes" required><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
                            <small class="cbco-field-hint" id="trade_instructions_hint">
                                <i class="fa-solid fa-circle-info"></i> Our drivers will use these details for smooth store delivery.
                            </small>
                        </div>

                    <?php else: ?>
                    <!-- ── RETAIL CUSTOMER CHECKOUT ────────────────────────── -->
                    <h2><i class="fa-solid fa-user cbco-icon-primary"></i> Delivery Details</h2>

                    <form action="../checkout_handler.php" method="POST" id="checkoutForm">

                        <!-- ── Bot protection (honeypot) ─────────── -->
                        <input type="text" name="website" id="hp_website" tabindex="-1" autocomplete="off"
                               aria-hidden="true"
                               class="cbco-honeypot">
                        <input type="hidden" name="form_loaded_at" id="hp_loaded_at" value="0">
                        <script>document.getElementById('hp_loaded_at').value = Date.now();</script>

                        <div class="form-group">
                            <label for="customer_name" class="form-label">Full Name *</label>
                            <input type="text" id="customer_name" name="customer_name" class="form-control"
                                placeholder="e.g. Jane Smith" required
                                value="<?= htmlspecialchars($_POST['customer_name'] ?? '') ?>">
                        </div>

                        <div class="form-group">
                            <label for="customer_email" class="form-label">Email Address *</label>
                            <input type="email" id="customer_email" name="customer_email" class="form-control"
                                placeholder="you@example.com" required
                                value="<?= htmlspecialchars($_POST['customer_email'] ?? '') ?>">
                            <small class="cbco-field-hint">
                                <i class="fa-solid fa-circle-info"></i> Your order confirmation will be sent here
                            </small>
                        </div>

                        <div class="form-group">
                            <label for="phone" class="form-label">Phone Number *</label>
                            <input type="tel" id="phone" name="phone" class="form-control"
                                placeholder="e.g. +44 7700 900 123" required
                                value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
                        </div>

                        <!-- Order Type Selector (Collection / Delivery) -->
                        <?= cbOrderTypeChooser(
                                'Order Type',
                                'Delivery',
                                'Collection',
                                $cbDefaultOrderType
                            ) ?>

                        <!-- Postcode field group -->
                        <div class="form-group" id="postcode_field_group">
                            <label for="delivery_postcode" class="form-label">Delivery Postcode *</label>
                            <input type="text" id="delivery_postcode" name="delivery_postcode" class="form-control"
                                placeholder="e.g. HA1 2SP" required maxlength="8" autocomplete="postal-code"
                                value="<?= htmlspecialchars($_POST['delivery_postcode'] ?? '') ?>"
                                oninput="this.value=this.value.toUpperCase()">
                            <small class="cbco-field-hint">
                                <i class="fa-solid fa-circle-info"></i> Used to calculate delivery distance and pre-fill address
                            </small>
                            <div id="postcodeStatus" class="cbco-postcode-status"></div>
                            <?php // What delivery actually COSTS, in the server's words.
                                  // #postcodeStatus above says how far away the address
                                  // is — the browser can work that out. Only the server
                                  // knows the price, because only the server knows what
                                  // the offers and the free-delivery-over figure did to
                                  // it, so that sentence is kept in its own element and
                                  // written only from the summary reply. ?>
                            <div id="deliveryPriceNote" class="cbof-price-note cbco-hidden"></div>
                        </div>
                        <input type="hidden" name="delivery_charge" id="delivery_charge_input" value="0">

                        <!-- Address fields container (Hidden when Collection is active) -->
                        <div id="address_fields_container">
                            <!-- Structured Address Section (Hidden by default, shown when valid postcode is checked) -->
                            <div id="structuredAddressSection" class="cbco-structured-address">
                                <div class="cbco-addr-name-grid">
                                    <div>
                                        <label for="addr_house" class="form-label cbco-addr-label">House/Flat No. *</label>
                                        <input type="text" id="addr_house" class="form-control" placeholder="e.g. 15" autocomplete="address-line2">
                                    </div>
                                    <div>
                                        <label for="addr_street" class="form-label cbco-addr-label">Street Name *</label>
                                        <input type="text" id="addr_street" class="form-control" placeholder="e.g. High Street" autocomplete="address-line1">
                                    </div>
                                </div>
                                <div class="cbco-addr-city-row">
                                    <label for="addr_city" class="form-label cbco-addr-label">Town / City *</label>
                                    <input type="text" id="addr_city" class="form-control" placeholder="e.g. London" autocomplete="address-level2">
                                </div>
                                <div class="cbco-manual-link-row">
                                    <a href="#" id="btnManualAddress" class="cbco-manual-link"><i class="fa-solid fa-pen"></i> Enter address manually</a>
                                </div>
                            </div>

                            <!-- Manual Address Textarea (Fallback) -->
                            <div class="form-group" id="manualAddressSection">
                                <label for="address" class="form-label">Delivery Address *</label>
                                <textarea id="address" name="address" class="form-control" rows="3"
                                    placeholder="House number, street, city, postcode" required autocomplete="street-address"><?= htmlspecialchars($_POST['address'] ?? '') ?></textarea>
                            </div>
                        </div>
                    <?php endif; ?>

                        <!-- Warehouse Collection Info (Shown when Collection is selected) -->
                        <div id="warehouseCollectionInfo" class="cbco-collection-info">
                            <h3 class="cbco-collection-title">
                                <i class="fa-solid fa-store"></i> Warehouse Collection Details
                            </h3>
                            <p class="cbco-collection-address">
                                <strong>Creamy Bite Warehouse:</strong><br>
                                Unit E5, Phoenix Business Centre,<br>
                                HA1 2SP
                            </p>
                            <p class="cbco-collection-hours">
                                <i class="fa-solid fa-clock cbco-icon-primary"></i> <strong>Collection Time:</strong> 11 AM to 8 PM
                            </p>
                            <div class="cbco-collection-map-row">
                                <a href="https://maps.app.goo.gl/hrMSnTRqFvorzF7HA?g_st=iw" target="_blank" rel="noopener" class="cbco-map-link">
                                    <i class="fa-solid fa-map-location-dot"></i> Get Directions on Google Maps
                                </a>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="notes" class="form-label">Special Notes (optional)</label>
                            <textarea id="notes" name="notes" class="form-control" rows="2"
                                placeholder="Allergy info, delivery instructions, etc."><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
                        </div>

                        <!-- ── Payment Method Selector ──────────────────────── -->
                        <div class="cbco-mb-24">
                            <label class="form-label cbco-pay-method-label">How would you like to pay?</label>

                            <div class="cbco-pay-method-grid">
                                <label id="payOnlineLabel" onclick="selectPayment('online')" class="cbco-pay-option cbco-pay-option-active">
                                    <input type="radio" name="payment_method" value="online" id="payOnlineRadio" class="cbco-pay-radio" checked>
                                    <span>
                                        <i class="fa-solid fa-credit-card cbco-icon-primary"></i>
                                        <strong class="cbco-pay-title">Pay Online</strong>
                                        <span class="cbco-pay-sub">Card, Apple Pay, Google Pay</span>
                                    </span>
                                </label>

                                <label id="payLaterLabel" onclick="selectPayment('later')" class="cbco-pay-option">
                                    <input type="radio" name="payment_method" value="later" id="payLaterRadio" class="cbco-pay-radio">
                                    <span>
                                        <i class="fa-solid fa-phone cbco-text-secondary"></i>
                                        <strong class="cbco-pay-title">Pay Later</strong>
                                        <span class="cbco-pay-sub">We'll contact you</span>
                                    </span>
                                </label>
                            </div>

                            <!-- Stripe Payment Element (shown when 'Pay Online' selected) -->
                            <div id="stripePanel" class="cbco-stripe-panel">
                                <div id="stripeElement" class="cbco-stripe-element">
                                    <div class="cbco-stripe-loading">
                                        <i class="fa-solid fa-spinner fa-spin"></i> Loading secure payment form...
                                    </div>
                                </div>
                                <div id="stripeError" class="cbco-stripe-error"></div>
                                <div class="cbco-stripe-secure-note">
                                    <i class="fa-solid fa-lock cbco-text-success"></i>
                                    Secured by Stripe &mdash; we never see your card details
                                </div>
                            </div>

                            <!-- Pay Later info (shown when 'Pay Later' selected) -->
                            <div id="laterPanel" class="cbco-later-panel">
                                <div class="alert alert-info cbco-later-alert">
                                    <i class="fa-solid fa-phone"></i>
                                    <span>No payment now — we'll contact you to confirm and arrange payment.</span>
                                </div>
                            </div>
                        </div>

                        <!-- Shown while a delivery basket is under the minimum, and
                             removed the moment it is met. The rule was previously
                             enforced only on the server, so the customer met it
                             without the warning ever going away. -->
                        <div id="minOrderNotice" class="cbco-min-notice cbco-hidden">
                            <i class="fa-solid fa-basket-shopping"></i>
                            <span id="minOrderText"></span>
                        </div>
                        <button type="button" id="placeOrderBtn" onclick="handleCheckout()" class="btn-primary cbco-place-order-btn">
                            <i class="fa-solid fa-credit-card" id="btnIcon"></i>
                            <span id="btnText">Pay Now</span>
                        </button>

                    </form>
                </div>
                <?php endif; // $cbAnyOpen ?>
            </div>

            <!-- ── Order Summary (Editable) ──────────────────────────── -->
            <div>
                <div class="glass-panel section-card cbco-summary-panel">
                    <h2><i class="fa-solid fa-receipt cbco-icon-secondary"></i> Order Summary</h2>
                    <p class="cbco-summary-hint">
                        <i class="fa-solid fa-pen-to-square"></i> You can edit quantities below
                    </p>

                    <div id="summaryItems">
                        <?php foreach ($cart as $cartKey => $item):
                            $lineTotal = $item['price'] * $item['quantity'];
                            $imgSrc = !empty($item['image']) ? '../assets/images/products/' . htmlspecialchars($item['image']) : '';
                            $safeKey = htmlspecialchars($cartKey, ENT_QUOTES);
                            $domKey  = preg_replace('/[^a-z0-9]/i', '-', $cartKey);
                            // Trade lines move a whole case per press, exactly as they do
                            // in the cart drawer. Stepping by 1 here made the buttons look
                            // broken: the handler rounds back to the nearest whole case, so
                            // the number sprang straight back to where it started.
                            $step  = max(1, (int)($item['case_qty'] ?? 1));
                            $cases = $step > 1 ? (int)round($item['quantity'] / $step) : 0;
                        ?>
                        <div class="order-summary-item" id="osi-<?= $domKey ?>">
                            <?php if ($imgSrc): ?>
                            <img class="cart-item-img" src="<?= $imgSrc ?>" alt="<?= htmlspecialchars($item['name']) ?>">
                            <?php else: ?>
                            <div class="osi-emoji"><?= cbProductIcon($item['emoji'] ?? null) ?></div>
                            <?php endif; ?>
                            <div class="osi-info">
                                <div class="osi-name">
                                    <?= htmlspecialchars($item['name']) ?>
                                    <?php if (!empty($item['variant_name'])): ?>
                                    <span class="cbco-osi-variant"><?= htmlspecialchars($item['variant_name']) ?></span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($step > 1): ?>
                                <div class="cbco-osi-case" id="sc-<?= $domKey ?>">
                                    <?= $cases ?> case<?= $cases === 1 ? '' : 's' ?> · <?= $step ?> per case
                                </div>
                                <?php endif; ?>
                                <div class="qty-controls cbco-osi-qty">
                                    <button class="qty-btn" onclick="summaryUpdateQty('<?= $safeKey ?>', '<?= $domKey ?>', -1, <?= $step ?>)"
                                            title="<?= $step > 1 ? 'Remove one case (' . $step . ')' : 'Remove one' ?>">−</button>
                                    <span class="qty-value" id="sq-<?= $domKey ?>"><?= $item['quantity'] ?></span>
                                    <button class="qty-btn" onclick="summaryUpdateQty('<?= $safeKey ?>', '<?= $domKey ?>', 1, <?= $step ?>)"
                                            title="<?= $step > 1 ? 'Add one case (' . $step . ')' : 'Add one' ?>">+</button>
                                </div>
                            </div>
                            <div>
                                <div class="osi-price" id="sp-<?= $domKey ?>">£<?= number_format($lineTotal, 2) ?></div>
                                <button class="btn-remove-item cbco-osi-remove" onclick="summaryRemoveItem('<?= $safeKey ?>', '<?= $domKey ?>')" title="Remove">
                                    <i class="fa-solid fa-xmark"></i>
                                </button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <hr class="summary-divider">

                    <!-- Promo Code Input.
                         Not offered on a wholesale basket: trade already buys at
                         trade prices, and promo_handler.php refuses trade
                         accounts anyway — so showing the box only invited a
                         code to be typed and rejected. -->
                    <?php if (!$isTradeUser): ?>
                    <div id="promoSection" class="cbco-promo-section">
                        <?php if ($appliedPromo): ?>
                        <div id="promoApplied" class="cbco-promo-applied">
                            <div class="cbco-promo-applied-text">
                                <i class="fa-solid fa-check-circle cbco-text-success"></i>
                                <strong class="cbco-text-success cbco-promo-code"><?= htmlspecialchars($appliedPromo['code']) ?></strong>
                                <span class="cbco-text-secondary">&nbsp;— <?= htmlspecialchars($appliedPromo['label']) ?></span>
                            </div>
                            <button onclick="removePromo()" class="cbco-promo-remove" title="Remove promo">
                                <i class="fa-solid fa-xmark"></i>
                            </button>
                        </div>
                        <?php else: ?>
                        <div id="promoApplied" class="cbco-hidden"></div>
                        <div id="promoInputRow" class="cbco-promo-input-row">
                            <input type="text" id="promoInput" placeholder="Promo code" class="form-control cbco-promo-input" oninput="this.value=this.value.toUpperCase()">
                            <button class="btn-secondary cbco-promo-apply-btn" onclick="applyPromo()">
                                <i class="fa-solid fa-tag"></i> Apply
                            </button>
                        </div>
                        <div id="promoMsg" class="cbco-promo-msg"></div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <?php // Every money row, the earned gifts, the messages and the
                          // delivery banner live in here, rendered by ONE function
                          // from the figures computeOrderTotals() returned. When the
                          // basket or the postcode changes, the server renders it
                          // again and this box is replaced whole — so the screen and
                          // the charge cannot drift apart. ?>
                    <div id="cbofSummaryFigures"><?= cbCheckoutSummaryFigures(
                            $totals, $cart, $appliedPromo, $summaryOrderType, $summaryPostcode, $isTradeUser
                        ) ?></div>

                    <div class="cbco-add-more-row">
                        <a href="order.php" class="cbco-add-more-link">
                            <i class="fa-solid fa-arrow-left"></i> Add more items
                        </a>
                    </div>
                </div>
            </div>

        </div>
    </div>
</main>

<?php // One shared footer — it used to be copied into five pages, so adding a
      // link meant editing all five and hoping none were missed. ?>
<?php require __DIR__ . '/../includes/site_footer.php'; ?>

<script>
// Escapes anything the server sends before it goes near innerHTML. The
// closed-shop wording is written by the owner in the admin panel and comes back
// through this JSON, so without this a stray angle bracket — or a deliberate
// script tag — would execute in a customer's browser. Same implementation as
// pages/order.php so the two cannot drift.
function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ── The order summary ───────────────────────────────────────────
//
// NOTHING about money is worked out down here. This page used to compute the
// promo discount, the VAT and the total in the browser, which meant three
// separate copies of rules that live in computeOrderTotals() — and offers,
// which the browser has no way of knowing about at all, would have made a
// fourth. Instead the server renders the whole block of figures and this code
// simply puts it on screen.
let cartSubtotal = <?= json_encode((float)$cartTotal) ?>;
let appliedPromo = <?= json_encode($appliedPromo) ?>;

// The postcode the summary should be priced against. Empty until one has been
// looked up and found to be inside the delivery radius — see onPostcodeInput().
let totalsPostcode = '';
// Only the newest answer is allowed on screen: two quick presses of "+" would
// otherwise race, and the slower reply could land last.
let totalsRequestId = 0;
// What the server last said delivery costs, in words, for the line under the
// postcode box. The browser knows the distance; only the server knows the
// price, because only the server knows what the offers did to it.
let summaryDeliveryNote = '';

/** Ask the server to work the summary out again and show what it says. */
function refreshSummary() {
    const isCollection = document.querySelector('input[name="order_type"]:checked')?.value === 'collection';
    const mine = ++totalsRequestId;

    const body = new URLSearchParams({
        summary:    'json',
        order_type: isCollection ? 'collection' : 'delivery',
        postcode:   isCollection ? '' : totalsPostcode,
    });

    return fetch('checkout.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body:    body.toString(),
    })
    .then(r => r.json())
    .then(data => {
        if (mine !== totalsRequestId) return;   // a newer answer is already on its way
        if (!data || !data.ok) return;

        const box = document.getElementById('cbofSummaryFigures');
        if (box && typeof data.html === 'string') box.innerHTML = data.html;

        if (typeof data.subtotal === 'number') cartSubtotal = data.subtotal;
        summaryDeliveryNote = data.delivery_note || '';

        renderDeliveryPriceNote();
        checkMinimumOrder();
    })
    .catch(() => {
        // Leave the last figures the server gave us on screen. They are stale
        // by one change at worst; invented ones would be wrong outright.
    });
}

/**
 * Put the server's one-line delivery price under the postcode box.
 *
 * Its own element, never #postcodeStatus — that one is written by the distance
 * lookup as the customer types, and two writers on one element is how a stale
 * price survives on screen. Empty text hides the line rather than leaving a
 * blank gap where a sentence used to be.
 */
function renderDeliveryPriceNote() {
    const el = document.getElementById('deliveryPriceNote');
    if (!el) return;   // the trade checkout has no postcode box
    const note = (summaryDeliveryNote || '').trim();
    el.textContent = note;
    el.classList.toggle('cbco-hidden', note === '');
}

/**
 * Which postcode the server should price the summary against from now on.
 *
 * Records it only — the caller asks for the summary, so a single change of
 * address makes one request rather than two. '' means "no postcode yet",
 * which is what a fresh page load is priced against; anything the server does
 * not recognise as a UK postcode it ignores.
 */
function useTotalsPostcode(pc) {
    totalsPostcode = (pc || '').trim().toUpperCase();
}

// Kept under its old name because several places call it. It no longer
// calculates anything — it asks.
function recalculateTotals() {
    checkMinimumOrder();
    refreshSummary();
}

function applyPromo() {
    const code = (document.getElementById('promoInput')?.value || '').trim().toUpperCase();
    const msgEl = document.getElementById('promoMsg');
    if (!code) { showPromoMsg('Please enter a promo code.', 'error'); return; }

    // ../ because this page lives in pages/ and the handler sits at the
    // project root. Without it the request resolved to
    // /orders/pages/promo_handler.php — a 404, whose HTML body then broke
    // r.json(), so every valid code came back looking invalid.
    fetch(`../promo_handler.php?action=validate&code=${encodeURIComponent(code)}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success) { showPromoMsg(data.message, 'error'); return; }
            
            // Set appliedPromo details in JS (include min_order for live validation)
            let isPercentage = data.discount_label.includes('%');
            appliedPromo = {
                code:           data.code,
                discount_type:  isPercentage ? 'percentage' : 'fixed',
                discount_value: isPercentage ? parseFloat(data.discount_label) : parseFloat(data.discount_amount),
                min_order:      parseFloat(data.min_order ?? 0)
            };

            // Show applied banner
            document.getElementById('promoInputRow').style.display = 'none';
            document.getElementById('promoApplied').style.display = 'flex';
            document.getElementById('promoApplied').innerHTML = `
                <div class="cbco-promo-applied-text">
                    <i class="fa-solid fa-check-circle cbco-text-success"></i>
                    <strong class="cbco-promo-code cbco-text-success">${data.code}</strong>
                    <span class="cbco-text-secondary">&nbsp;— ${data.discount_label}</span>
                </div>
                <button onclick="removePromo()" class="cbco-promo-remove" title="Remove promo">
                    <i class="fa-solid fa-xmark"></i>
                </button>`;
            
            showPromoMsg(data.message, 'success');
            recalculateTotals();
            triggerStripeAmountUpdate();
        })
        .catch(() => showPromoMsg('Could not apply promo. Try again.', 'error'));
}

function removePromo() {
    fetch('../promo_handler.php?action=remove').then(() => location.reload());
}

function showPromoMsg(msg, type, iconClass) {
    const el = document.getElementById('promoMsg');
    if (!el) return;
    // Most of these messages are composed by promo_handler.php, so the text
    // goes in as a text node rather than as markup. Only the icon is real
    // HTML, and it is always chosen here — never sent by the server.
    el.textContent = '';
    if (iconClass) {
        const ico = document.createElement('i');
        ico.className = iconClass;
        ico.setAttribute('aria-hidden', 'true');
        el.appendChild(ico);
        el.appendChild(document.createTextNode(' '));
    }
    el.appendChild(document.createTextNode(msg));
    el.style.color = type === 'success' ? '#10b981' : 'var(--color-danger, #ef4444)';
}

// Handle Enter key in promo input
document.addEventListener('DOMContentLoaded', () => {
    const inp = document.getElementById('promoInput');
    if (inp) inp.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); applyPromo(); } });

    // Check the minimum on arrival, not only after something changes. A
    // customer who lands here with a basket that is already too small would
    // otherwise see no warning until they pressed Place Order.
    checkMinimumOrder();
});

// ── Re-evaluate delivery + promo when cart changes ──────────
function reEvaluateCharges() {
    const isCollection = document.querySelector('input[name="order_type"]:checked')?.value === 'collection';

    // ── 1. Validate promo minimum order ────────────────────────
    if (appliedPromo) {
        const minOrder = parseFloat(appliedPromo.min_order ?? 0);
        if (minOrder > 0 && cartSubtotal < minOrder) {
            // Cart dropped below promo minimum — auto-remove
            const promoCode = appliedPromo.code;
            appliedPromo = null;

            // Remove from session silently
            fetch('../promo_handler.php?action=remove');

            // Restore input field with code pre-filled + warning
            const inputRow    = document.getElementById('promoInputRow');
            const appliedBanner = document.getElementById('promoApplied');
            const promoInput  = document.getElementById('promoInput');
            if (inputRow)      inputRow.style.display    = 'flex';
            if (appliedBanner) appliedBanner.style.display = 'none';
            if (promoInput)    promoInput.value           = promoCode;
            showPromoMsg(
                'Promo removed — minimum order £' + minOrder.toFixed(2) + ' not met.',
                'error',
                'fa-solid fa-triangle-exclamation'
            );
            // The promo row disappears with the next summary the server sends
            // — it is the server that decides whether the code still counts.
        }
    }

    // ── 2. Re-check delivery if postcode already looked up ────────
    if (!isCollection && lastCalculatedMiles >= 0) {
        updateDeliveryDisplay(lastCalculatedMiles <= FREE_MILES ? 0 : DELIVERY_CHARGE);
    } else {
        // No postcode yet — ask for the summary with what we have
        recalculateTotals();
    }
}

// ── Order Summary qty/remove ───────────────────────────
function summaryUpdateQty(cartKey, domKey, direction, step) {
    const qtyEl   = document.getElementById('sq-' + domKey);
    const priceEl = document.getElementById('sp-' + domKey);
    const caseEl  = document.getElementById('sc-' + domKey);

    step = Math.max(1, parseInt(step, 10) || 1);
    const qty = parseInt(qtyEl.textContent, 10) + (direction * step);

    // Below one whole case there is nothing to keep, so a minus press on the
    // last case removes the line rather than asking the server to round it
    // back up to where it already was.
    if (qty < step) { summaryRemoveItem(cartKey, domKey); return; }

    fetch('../cart_handler.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=update&cart_key=' + encodeURIComponent(cartKey) + '&quantity=' + qty,
    })
    .then(r => r.json())
    .then(data => {
        // Show what the server actually stored, never what we asked for. It
        // rounds to whole cases and caps against stock, so the number we sent
        // is a request and the number it returns is the truth — printing our
        // own arithmetic here is how the screen ends up disagreeing with the
        // basket that gets charged.
        const line = (data.items || []).find(i => String(i.cart_key) === String(cartKey));

        if (!line) {
            const row = document.getElementById('osi-' + domKey);
            if (row) row.remove();
        } else {
            qtyEl.textContent   = line.quantity;
            priceEl.textContent = '£' + (line.price * line.quantity).toFixed(2);
            if (caseEl) {
                const n = Math.round(line.quantity / step);
                caseEl.textContent = n + ' case' + (n === 1 ? '' : 's') + ' · ' + step + ' per case';
            }
        }

        // Only when the server changed what we asked for — rounded to a whole
        // case, or capped against stock. cbAlert comes from modal.js, which
        // this page already loads; showToast does not exist here.
        if (data.message && typeof cbAlert === 'function') {
            cbAlert(data.message, { title: 'Basket updated' });
        }

        cartSubtotal = data.cart_total_raw;
        const subtotalEl = document.getElementById('subtotalDisplay');
        if (subtotalEl) subtotalEl.textContent = '£' + cartSubtotal.toFixed(2);

        reEvaluateCharges();   // ← live-update delivery + promo
        triggerStripeAmountUpdate();

        if (data.items.length === 0) window.location.href = 'order.php';
    });
}

function summaryRemoveItem(cartKey, domKey) {
    fetch('../cart_handler.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=remove&cart_key=' + encodeURIComponent(cartKey),
    })
    .then(r => r.json())
    .then(data => {
        const row = document.getElementById('osi-' + domKey);
        if (row) { row.style.opacity='0'; row.style.transition='opacity 0.3s'; setTimeout(() => row.remove(), 300); }

        cartSubtotal = data.cart_total_raw;
        const subtotalEl = document.getElementById('subtotalDisplay');
        if (subtotalEl) subtotalEl.textContent = '£' + cartSubtotal.toFixed(2);

        reEvaluateCharges();   // ← live-update delivery + promo
        triggerStripeAmountUpdate();

        if (data.items.length === 0) window.location.href = 'order.php';
    });
}
</script>

<script>
// ── Mobile nav ─────────────────────────────────────────────
const ham = document.getElementById('navHamburger');
const drawer = document.getElementById('mobileDrawer');
const drawerClose = document.getElementById('mobileDrawerClose');
function openMobileMenu()  { ham.classList.add('open'); drawer.classList.add('open'); document.body.style.overflow='hidden'; }
function closeMobileMenu() { ham.classList.remove('open'); drawer.classList.remove('open'); document.body.style.overflow=''; }
ham.addEventListener('click', openMobileMenu);
drawerClose.addEventListener('click', closeMobileMenu);
drawer.addEventListener('click', e => { if (e.target === drawer) closeMobileMenu(); });
document.addEventListener('keydown', e => { if (e.key === 'Escape') closeMobileMenu(); });
</script>

<script>
// ═══════════════════════════════════════════════════════════
//  STRIPE PAYMENT
// ═══════════════════════════════════════════════════════════
let stripe, elements, paymentElement, clientSecret;
let stripeReady = false;
let currentPaymentMethod = 'online';

// Which method the page opened on. Almost always 'delivery'; 'collection' only
// when the owner has delivery switched off — see $cbDefaultOrderType. The
// listener at the bottom of this file uses it to put the panels into the state
// the ticked radio says they are in.
const CB_DEFAULT_ORDER_TYPE = <?= json_encode($cbDefaultOrderType) ?>;

<?php // With both methods closed there is no form, no #stripeElement and no
      // card to take — and stripe_intent.php refuses the request anyway. The
      // block below writes straight into #stripeElement on both of its failure
      // paths, INCLUDING inside its own catch, so letting it run against a page
      // that does not have that element throws from the handler meant to catch
      // the throw. Everything else in this script stays declared: the basket
      // panel is still live, and it calls into these functions. ?>
<?php if ($cbAnyOpen): ?>
// Initialise Stripe on page load
(async () => {
    try {
        const res  = await fetch('../stripe_intent.php');
        const data = await res.json();

        if (data.error) {
            // A basket under the minimum is not a payment failure, and the
            // notice beside the basket already says so — and keeps saying it
            // accurately as items are added, which a message frozen inside the
            // card panel cannot. Show a neutral placeholder here instead of
            // repeating the complaint.
            document.getElementById('stripeElement').innerHTML = data.basket_below_minimum
                ? `<div class="cbco-card-waiting"><i class="fa-solid fa-basket-shopping"></i> The card form appears once your basket reaches the minimum.</div>`
                : `<div class="cbco-inline-error"><i class="fa-solid fa-triangle-exclamation"></i> ${escHtml(data.error)}</div>`;
            // Fall back to Pay Later when card payment cannot work at all —
            // an expired key, or keys never configured. Leaving the customer
            // on a card form that will never load loses the order outright.
            // data.error can now be the owner's own closed-shop wording, and a
            // perfectly ordinary sentence containing the word "setup" must not
            // be mistaken for a Stripe misconfiguration and silently switch the
            // customer to Pay Later. Only the server's explicit flag, or the
            // unmistakable placeholder key, may do that.
            if (data.fallback_to_later || data.error.includes('REPLACE_ME')) {
                selectPayment('later');
            }
            return;
        }

        clientSecret = data.clientSecret;
        stripe       = Stripe(data.publishableKey);
        elements     = stripe.elements({ clientSecret, appearance: {
            theme: 'night',
            variables: {
                colorPrimary:       '#ff6b9d',
                colorBackground:    '#1a0a14',
                colorText:          '#f0e6ee',
                borderRadius:       '8px',
                fontFamily:         'Inter, system-ui, sans-serif',
            }
        }});

        paymentElement = elements.create('payment', {
            layout: { type: 'tabs', defaultCollapsed: false },
            wallets: {
                applePay:  'auto',   // shows on Safari / iPhone / Mac
                googlePay: 'auto',   // shows on Chrome / Android
            },
        });
        paymentElement.mount('#stripeElement');
        stripeReady = true;

    } catch (err) {
        document.getElementById('stripeElement').innerHTML =
            '<div class="cbco-inline-error"><i class="fa-solid fa-triangle-exclamation"></i> Could not load payment form. You can still choose Pay Later.</div>';
    }
})();
<?php endif; // $cbAnyOpen ?>

// Switch between Pay Online / Pay Later
function selectPayment(method) {
    currentPaymentMethod = method;
    const onlineLabel = document.getElementById('payOnlineLabel');
    const laterLabel  = document.getElementById('payLaterLabel');
    const stripePanel = document.getElementById('stripePanel');
    const laterPanel  = document.getElementById('laterPanel');
    const btnIcon     = document.getElementById('btnIcon');
    const btnText     = document.getElementById('btnText');

    if (method === 'online') {
        document.getElementById('payOnlineRadio').checked = true;
        onlineLabel.style.borderColor  = 'var(--color-primary)';
        onlineLabel.style.background   = 'var(--color-primary-bg)';
        laterLabel.style.borderColor   = 'var(--border-light)';
        laterLabel.style.background    = 'var(--bg-surface)';
        stripePanel.style.display = 'block';
        laterPanel.style.display  = 'none';
        btnIcon.className = 'fa-solid fa-lock';
        btnText.textContent = 'Pay Now';
    } else {
        document.getElementById('payLaterRadio').checked = true;
        laterLabel.style.borderColor   = 'var(--color-primary)';
        laterLabel.style.background    = 'var(--color-primary-bg)';
        onlineLabel.style.borderColor  = 'var(--border-light)';
        onlineLabel.style.background   = 'var(--bg-surface)';
        stripePanel.style.display = 'none';
        laterPanel.style.display  = 'block';
        btnIcon.className = 'fa-solid fa-paper-plane';
        btnText.textContent = 'Place My Order';
    }
}

// Main checkout handler
async function handleCheckout() {
    const btn = document.getElementById('placeOrderBtn');

    // Validate required fields first
    const form = document.getElementById('checkoutForm');
    if (!form.reportValidity()) return;

    const isCollection = document.querySelector('input[name="order_type"]:checked')?.value === 'collection';

    // Out of range. The status line under the postcode says so too, but that
    // sits well above the button on a long page and is easy to scroll past —
    // a customer who has filled the whole form deserves to be stopped in
    // front of the thing they just pressed, not sent hunting for the reason.
    if (!isCollection && lastCalculatedMiles > MAX_DELIVERY_MILES) {
        await cbAlert(
            'We are unable to deliver more than ' + MAX_MILES_TXT + ' miles radius.\n\n' +
            'Your postcode is ' + lastCalculatedMiles.toFixed(1) + ' miles from our Harrow warehouse. ' +
            'Choose Warehouse Collection instead, or call us on ' + <?= json_encode(SHOP_PHONE) ?> +
            ' and we will see what we can do.',
            { title: 'Too far for delivery', tone: 'danger', okText: 'I understand' }
        );
        return;
    }

    // Below the minimum. Same reasoning — say it at the button.
    if (!isCollection && cartSubtotal < MIN_ORDER) {
        await cbAlert(
            'Minimum order for delivery is £' + MIN_ORDER.toFixed(2) + '.\n\n' +
            'Your basket is £' + cartSubtotal.toFixed(2) + ' — add £' +
            (MIN_ORDER - cartSubtotal).toFixed(2) + ' more, or choose Warehouse Collection.',
            { title: 'A little more needed', okText: 'OK' }
        );
        return;
    }

    if (currentPaymentMethod === 'later') {
        // Pay Later — just submit the form. The scoop-stacking loader is
        // shown while the page navigates; it also disables the button, so a
        // double-click cannot submit two orders.
        document.querySelector('[name="payment_method"]').value = 'later';
        if (typeof cbScoopLoader === 'function') {
            cbScoopLoader(btn, 'Building your order');
        } else {
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Placing order...';
        }
        form.submit();
        return;
    }

    // Pay Online — process via Stripe
    if (!stripeReady) {
        cbAlert('The card form is still loading. Give it a moment, or choose Pay Later.', {title:'Nearly ready'});
        return;
    }

    // Swap in the scoop-stacking loader while the payment round-trip runs.
    // restore() undoes it on the error path; setLabel rewords the caption
    // once the charge has succeeded. Falls back to the plain spinner if
    // animations.js failed to load — and it must show SOMETHING, so the
    // fallback sets its own busy content rather than leaving the button
    // sitting on "Pay Now" for the whole round-trip.
    var busy = (typeof cbScoopLoader === 'function')
        ? cbScoopLoader(btn, 'Processing payment')
        : (function () {
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Processing payment...';
            return {
                restore: function () {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fa-solid fa-lock"></i> <span>Pay Now</span>';
                },
                setLabel: function () {}   // the plain spinner already says enough
            };
        })();

    const errEl = document.getElementById('stripeError');
    errEl.style.display = 'none';

    const returnUrl = window.location.origin + '/orders/checkout_handler.php';

    const { error } = await stripe.confirmPayment({
        elements,
        confirmParams: {
            return_url: returnUrl,
        },
        redirect: 'if_required',
    });

    if (error) {
        errEl.textContent = error.message;
        errEl.style.display = 'block';
        busy.restore();
    } else {
        // Payment succeeded — submit form to save order
        document.querySelector('[name="payment_method"]').value = 'online';
        busy.setLabel('Saving your order');
        form.submit();
    }
}
</script>

<script>
// ── Postcode delivery charge and address lookup ──────────────────
const SHOP_LAT = 51.5729;
const SHOP_LON = -0.3356; // HA1 2SP coordinates
// All four come from config.php so the page can never quote a delivery price
// or radius that differs from the one pricing.php actually charges.
const DELIVERY_CHARGE   = <?= json_encode(DELIVERY_CHARGE) ?>;
const FREE_MILES        = <?= json_encode(FREE_DELIVERY_MILES) ?>;
// Comes from MIN_DELIVERY_ORDER in config.php — the same figure the server
// enforces, so the page can never warn about a different number than the one
// that actually blocks the order.
const MIN_ORDER         = <?= json_encode(MIN_DELIVERY_ORDER) ?>;
const MAX_DELIVERY_MILES = <?= json_encode(DELIVERY_RADIUS_MILES) ?>;
// Scales the straight-line figure toward a realistic driving distance — see
// DELIVERY_DISTANCE_FACTOR in config.php for where this number comes from.
// Same constant the server applies, so this page can never show a distance
// the server would disagree with.
const DISTANCE_FACTOR = <?= json_encode(DELIVERY_DISTANCE_FACTOR) ?>;
// Pre-formatted for the message below: "6" not "6.0".
const MAX_MILES_TXT = MAX_DELIVERY_MILES.toString().replace(/\.0$/, '');
// There is deliberately no pre-formatted delivery PRICE here any more. This
// page quotes distances, never prices — an offer or the free-delivery-over
// figure can make the charge nothing, and only the server knows that. The
// price a customer reads comes from the summary the server renders.

let lastCalculatedMiles = -1; // cache so we can re-evaluate on cart changes

let manualMode = false;

/**
 * Warn while a delivery basket is below the minimum, and stop warning as soon
 * as it is not.
 *
 * MIN_ORDER existed as a constant but nothing ever read it, so the rule was
 * enforced only after the customer pressed Place Order — they would add items,
 * meet the minimum, and still be looking at the same message. Collection is
 * exempt: the minimum covers the driver, and there is no driver.
 */
/**
 * Is this basket below the delivery minimum?
 *
 * One place asks it, so the notice, the Stripe call and the Place Order guard
 * can never disagree about whether the order is allowed — which is how the
 * same complaint ended up on screen from three different sources.
 */
function cbBelowMinimum() {
    const isCollection = document.querySelector('input[name="order_type"]:checked')?.value === 'collection';
    return !isCollection && (MIN_ORDER - cartSubtotal) > 0.001;
}

function checkMinimumOrder() {
    const notice = document.getElementById('minOrderNotice');
    const text   = document.getElementById('minOrderText');
    const btn    = document.getElementById('placeOrderBtn');
    if (!notice || !text) return;

    const isCollection = document.querySelector('input[name="order_type"]:checked')?.value === 'collection';
    const short = MIN_ORDER - cartSubtotal;

    if (!isCollection && short > 0.001) {
        text.textContent = 'Minimum order for delivery is £' + MIN_ORDER.toFixed(2) +
            '. Add £' + short.toFixed(2) + ' more, or choose Warehouse Collection.';
        notice.classList.remove('cbco-hidden');
        if (btn) btn.disabled = true;

        // The server prints the same complaint when it rejects the order, so
        // arriving back here showed it twice — once in the red banner and once
        // here. This one stays because it updates as the basket changes; the
        // server's is a snapshot of the moment it was refused.
        document.querySelectorAll('[data-clears-at="min-order"]').forEach(el => el.remove());
        const dupBox = document.getElementById('serverErrors');
        if (dupBox && dupBox.textContent.trim() === '') dupBox.remove();
    } else {
        notice.classList.add('cbco-hidden');
        text.textContent = '';
        // Only re-enable what THIS rule disabled — the out-of-range postcode
        // check disables the same button, and clearing it here would let an
        // undeliverable order through.
        if (btn && !btn.dataset.blockedByDistance) btn.disabled = false;

        // Drop the server's rejection too. It was printed when the page loaded
        // and cannot know the basket has since grown, so leaving it up tells
        // the customer their order is too small while the summary shows it is
        // not — which is what made this look unfixable.
        document.querySelectorAll('[data-clears-at="min-order"]').forEach(el => el.remove());
        // Drop the whole box once nothing is left to say. Counting child divs
        // does not work — the wrapper is itself a div inside a div — so this
        // asks the simpler question: is there any text left?
        const box = document.getElementById('serverErrors');
        if (box && box.textContent.trim() === '') box.remove();
    }
}

function haversineDistance(lat1, lon1, lat2, lon2) {
    const R = 3958.8; // Earth radius in miles
    const dLat = (lat2 - lat1) * Math.PI / 180;
    const dLon = (lon2 - lon1) * Math.PI / 180;
    const a = Math.sin(dLat/2) * Math.sin(dLat/2) +
              Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
              Math.sin(dLon/2) * Math.sin(dLon/2);
    const straightLine = R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    // Scaled toward a realistic driving distance, same as the server — see
    // DISTANCE_FACTOR above.
    return straightLine * DISTANCE_FACTOR;
}

function isValidUKPostcode(pc) {
    return /^[A-Z]{1,2}[0-9][0-9A-Z]?\s*[0-9][A-Z]{2}$/i.test(pc.trim());
}

function updateConcatenatedAddress() {
    // The structured address block is RETAIL-ONLY — a trade partner delivers
    // to their registered address and never types one. Without this guard the
    // first getElementById here returns null on the trade checkout, the
    // TypeError kills the postcode handler mid-flight, and everything that
    // runs after it — including loading the card form — never happens. That
    // is why "Pay Online is not loading" appeared on trade orders only.
    if (!document.getElementById('structuredAddressSection')) return;
    const house = document.getElementById('addr_house').value.trim();
    const street = document.getElementById('addr_street').value.trim();
    const city = document.getElementById('addr_city').value.trim();
    const postcode = document.getElementById('delivery_postcode').value.trim();
    
    const parts = [];
    if (house) parts.push(house);
    if (street) parts.push(street);
    if (city) parts.push(city);
    if (postcode) parts.push(postcode);
    
    document.getElementById('address').value = parts.join(', ');
}

function switchToManualMode() {
    // The structured address block is RETAIL-ONLY — a trade partner delivers
    // to their registered address and never types one. Without this guard the
    // first getElementById here returns null on the trade checkout, the
    // TypeError kills the postcode handler mid-flight, and everything that
    // runs after it — including loading the card form — never happens. That
    // is why "Pay Online is not loading" appeared on trade orders only.
    if (!document.getElementById('structuredAddressSection')) return;
    manualMode = true;
    document.getElementById('structuredAddressSection').style.display = 'none';
    document.getElementById('manualAddressSection').style.display = 'block';
    
    document.getElementById('address').required = true;
    document.getElementById('addr_house').required = false;
    document.getElementById('addr_street').required = false;
    document.getElementById('addr_city').required = false;
}

function switchToStructuredMode(cityValue) {
    // The structured address block is RETAIL-ONLY — a trade partner delivers
    // to their registered address and never types one. Without this guard the
    // first getElementById here returns null on the trade checkout, the
    // TypeError kills the postcode handler mid-flight, and everything that
    // runs after it — including loading the card form — never happens. That
    // is why "Pay Online is not loading" appeared on trade orders only.
    if (!document.getElementById('structuredAddressSection')) return;
    if (manualMode) return;
    
    const currentAddr = document.getElementById('address').value.trim();
    if (currentAddr && !document.getElementById('addr_house').value.trim() && !document.getElementById('addr_street').value.trim()) {
        switchToManualMode();
        return;
    }

    document.getElementById('structuredAddressSection').style.display = 'block';
    document.getElementById('manualAddressSection').style.display = 'none';
    
    if (cityValue && !document.getElementById('addr_city').value.trim()) {
        document.getElementById('addr_city').value = cityValue;
    }
    
    document.getElementById('address').required = false;
    document.getElementById('addr_house').required = true;
    document.getElementById('addr_street').required = true;
    document.getElementById('addr_city').required = true;
    
    updateConcatenatedAddress();
}

function resetAddressModes() {
    // The structured address block is RETAIL-ONLY — a trade partner delivers
    // to their registered address and never types one. Without this guard the
    // first getElementById here returns null on the trade checkout, the
    // TypeError kills the postcode handler mid-flight, and everything that
    // runs after it — including loading the card form — never happens. That
    // is why "Pay Online is not loading" appeared on trade orders only.
    if (!document.getElementById('structuredAddressSection')) return;
    document.getElementById('structuredAddressSection').style.display = 'none';
    document.getElementById('manualAddressSection').style.display = 'block';
    
    document.getElementById('address').required = true;
    document.getElementById('addr_house').required = false;
    document.getElementById('addr_street').required = false;
    document.getElementById('addr_city').required = false;
}

let lastCheckedPostcode = '';
let postcodeTimeout = null;
function onPostcodeInput() {
    clearTimeout(postcodeTimeout);
    const pc = document.getElementById('delivery_postcode').value.trim();
    const normalizedPc = pc.toUpperCase().replace(/\s/g, '');
    
    if (normalizedPc === lastCheckedPostcode) {
        return;
    }
    
    lastCheckedPostcode = normalizedPc;
    // #postcodeStatus is rendered only in the retail branch of this page, so on
    // the trade path it does not exist. Without this stand-in the first
    // statusEl.style.display below threw a TypeError and aborted the whole
    // function — the delivery charge was never shown to trade customers even
    // though the server still applied it.
    const statusEl = document.getElementById('postcodeStatus')
                     || { style: {}, innerHTML: '' };
    const chargeInput = document.getElementById('delivery_charge_input')
                     || { value: '0' };

    if (!pc) {
        statusEl.style.display = 'none';
        chargeInput.value = '0';
        useTotalsPostcode('');
        updateDeliveryDisplay(0);
        resetAddressModes();
        return;
    }

    if (!isValidUKPostcode(pc)) {
        statusEl.style.display = 'block';
        statusEl.innerHTML = '<span class="cbco-status cbco-status-err"><i class="fa-solid fa-circle-xmark"></i> Please enter a valid UK postcode</span>';
        resetAddressModes();
        return;
    }

    statusEl.style.display = 'block';
    statusEl.innerHTML = '<span class="cbco-status cbco-status-muted"><i class="fa-solid fa-spinner fa-spin"></i> Checking delivery distance...</span>';

    postcodeTimeout = setTimeout(() => {
        const cleanPc = pc.replace(/\s/g, '');
        fetch('https://api.postcodes.io/postcodes/' + encodeURIComponent(cleanPc))
            .then(r => r.json())
            .then(data => {
                if (!data.result) {
                    statusEl.innerHTML = '<span class="cbco-status cbco-status-err"><i class="fa-solid fa-circle-xmark"></i> Postcode not found. Please check and try again.</span>';
                    // A postcode we cannot place must not be priced, or the
                    // summary would keep quoting the last one that worked.
                    useTotalsPostcode('');
                    recalculateTotals();
                    resetAddressModes();
                    return;
                }
                const lat = data.result.latitude;
                const lon = data.result.longitude;
                const miles = haversineDistance(SHOP_LAT, SHOP_LON, lat, lon);
                lastCalculatedMiles = miles;

                const submitBtn = document.getElementById('placeOrderBtn');

                if (miles > MAX_DELIVERY_MILES) {
                    chargeInput.value = '0';
                    statusEl.innerHTML = '<div class="cbco-status-box">' +
                        '<i class="fa-solid fa-triangle-exclamation"></i> ' +
                        '<strong>We are unable to deliver more than ' + MAX_MILES_TXT + ' miles radius.</strong><br>' +
                        'Your postcode is ' + miles.toFixed(1) + ' miles from our Harrow warehouse (HA1 2SP).<br>' +
                        'Please choose <strong>Warehouse Collection</strong>, or call <strong>+44 7497 779997</strong> if you need a special arrangement.</div>';
                    if (submitBtn) {
                        submitBtn.disabled = true;
                        submitBtn.dataset.blockedByDistance = '1';
                    }
                    // Outside the radius: there is no delivery to price, so
                    // the summary goes back to asking for a postcode.
                    useTotalsPostcode('');
                    updateDeliveryDisplay(0);
                    return;
                } else {
                    if (submitBtn) {
                        delete submitBtn.dataset.blockedByDistance;
                        submitBtn.disabled = false;
                    }
                    checkMinimumOrder();   // distance is fine; the basket may not be
                }

                // Inside the radius. From here the server prices the basket
                // against this postcode.
                useTotalsPostcode(pc);

                // These two lines say how FAR AWAY the address is, which the
                // browser has just measured. What delivery COSTS is a separate
                // sentence, written underneath from the server's answer — it
                // used to be claimed here from the page's own constants, which
                // is why a basket that had earned free delivery was still told
                // "£1.99 delivery charge".
                if (miles <= FREE_MILES) {
                    chargeInput.value = '0';
                    statusEl.innerHTML = '<span class="cbco-status cbco-status-ok"><i class="fa-solid fa-circle-check"></i> We deliver to you — ' + miles.toFixed(1) + ' miles from our Harrow kitchen.</span>';
                    updateDeliveryDisplay(0);
                } else {
                    chargeInput.value = DELIVERY_CHARGE.toFixed(2);
                    statusEl.innerHTML = '<span class="cbco-status cbco-status-ok"><i class="fa-solid fa-circle-check"></i> We deliver to you — ' + miles.toFixed(1) + ' miles from our Harrow kitchen.</span>';
                    updateDeliveryDisplay(DELIVERY_CHARGE);
                }

                const city = data.result.admin_district || data.result.region || '';
                switchToStructuredMode(city);
            })
            .catch(() => {
                statusEl.innerHTML = '<span class="cbco-status cbco-status-warn"><i class="fa-solid fa-triangle-exclamation"></i> Could not check postcode. Delivery charge will be calculated at checkout.</span>';
                chargeInput.value = '0';
                useTotalsPostcode('');
                recalculateTotals();
                resetAddressModes();
            });
    }, 600);
}

/**
 * The distance lookup has an answer — tell the server and let it re-price.
 *
 * This used to write the delivery figure straight into the summary: it set the
 * delivery row's display, typed '+ £' + charge into it, and rebuilt the banner
 * underneath. That was the browser doing its own money arithmetic, and it could
 * only ever be a guess — it knew the distance, but not whether an offer or the
 * "free delivery over £X" figure had already taken the charge off. A basket
 * that qualified for free delivery was shown £1.99 until the server's answer
 * arrived, and kept showing it if that request failed.
 *
 * So it no longer touches the summary at all. It records the charge for the
 * hidden form field, then asks the server to render the rows and the banner
 * from computeOrderTotals() — the one function that decides what is charged.
 */
function updateDeliveryDisplay(charge) {
    // Posted with the order as a cross-check only. checkout_handler.php works
    // the real figure out again from the postcode.
    const chargeInput = document.getElementById('delivery_charge_input');
    if (chargeInput) chargeInput.value = charge.toFixed(2);

    recalculateTotals();
    triggerStripeAmountUpdate();
}

// Show/hide, without assuming an element exists.
//
// The trade and retail checkouts render DIFFERENT field sets — the structured
// address inputs are retail-only — so reaching straight for
// getElementById('addr_house').style on the trade page threw on the first null
// and abandoned the rest of the function. That is why picking Warehouse
// Collection as a trade customer did nothing: the warehouse panel never
// appeared and the mandatory delivery instructions stayed mandatory.
function cbShow(id, visible, displayAs) {
    const el = document.getElementById(id);
    if (el) el.style.display = visible ? (displayAs || 'block') : 'none';
}
function cbRequire(id, required) {
    const el = document.getElementById(id);
    if (el) el.required = required;
}

function toggleOrderType(type) {
    const isCollection = (type === 'collection');
    // Collection has no minimum, so switching to it must clear the warning.
    setTimeout(checkMinimumOrder, 0);
    const delLabel = document.getElementById('type_delivery_label');
    const colLabel = document.getElementById('type_collection_label');

    if (delLabel) delLabel.classList.toggle('cbco-order-type-option-active', !isCollection);
    if (delLabel) delLabel.classList.toggle('cbco-order-type-option-idle',    isCollection);
    if (colLabel) colLabel.classList.toggle('cbco-order-type-option-active',  isCollection);
    if (colLabel) colLabel.classList.toggle('cbco-order-type-option-idle',   !isCollection);

    // Collecting from the warehouse means there is no delivery to instruct,
    // so the trade instructions stop being mandatory and say why.
    const tradeNote  = document.getElementById('trade_instructions');
    const tradeLabel = document.getElementById('trade_instructions_label');
    const tradeHint  = document.getElementById('trade_instructions_hint');
    if (tradeNote) {
        tradeNote.required = !isCollection;
        tradeNote.placeholder = isCollection
            ? 'Anything we should know about your collection (optional)'
            : 'Please specify:\n1. Store opening hours (e.g. 9:00 AM - 8:00 PM)\n2. Delivery place / drop-off location (e.g. Rear loading bay / Front counter)\n3. Any access codes or special delivery notes';
    }
    if (tradeLabel) {
        tradeLabel.innerHTML = isCollection
            ? '<i class="fa-solid fa-clock cbco-icon-primary"></i> Collection notes <span class="cbco-optional">(optional)</span>'
            : '<i class="fa-solid fa-clock cbco-icon-primary"></i> Store Opening Hours &amp; Delivery Instructions *';
    }
    if (tradeHint) {
        tradeHint.innerHTML = isCollection
            ? '<i class="fa-solid fa-circle-info"></i> Not needed for collection — tell us anything useful if you like.'
            : '<i class="fa-solid fa-circle-info"></i> Our drivers will use these details for smooth store delivery.';
    }

    if (isCollection) {
        cbShow('postcode_field_group', false);
        cbShow('address_fields_container', false);
        cbShow('warehouseCollectionInfo', true);

        cbRequire('delivery_postcode', false);
        cbRequire('address', false);
        cbRequire('addr_house', false);
        cbRequire('addr_street', false);
        cbRequire('addr_city', false);

        cbShow('postcodeStatus', false);
        updateDeliveryDisplay(0);
    } else {
        cbShow('postcode_field_group', true);
        cbShow('address_fields_container', true);
        cbShow('warehouseCollectionInfo', false);

        cbRequire('delivery_postcode', true);

        if (manualMode) {
            switchToManualMode();
        } else {
            // Trade has no structured address block, so read defensively here
            // too rather than reintroducing the same crash on this path.
            const pc = (document.getElementById('delivery_postcode')?.value || '').trim();
            if (pc && isValidUKPostcode(pc)) {
                cbRequire('addr_house', true);
                cbRequire('addr_street', true);
                cbRequire('addr_city', true);
            } else {
                switchToManualMode();
            }
        }
        
        // Recover the delivery charge from the cached postcode distance, not
        // from delivery_charge_input — switching to Collection sets that field
        // to "0.00", so reading it back here showed £0 delivery on screen while
        // the server (which recomputes from the postcode) still charged £1.99.
        reEvaluateCharges();
    }
}

function triggerStripeAmountUpdate() {
    if (!stripeReady) return;
    // Nothing to charge for a basket that cannot be ordered. Asking anyway
    // produced a console error on every quantity change.
    if (cbBelowMinimum()) return;
    const isCollection = document.querySelector('input[name="order_type"]:checked')?.value === 'collection';
    const pc = document.getElementById('delivery_postcode')?.value || '';
    
    fetch('../stripe_intent.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `order_type=${isCollection ? 'collection' : 'delivery'}&postcode=${encodeURIComponent(pc)}`
    })
    .then(r => r.json())
    .then(data => {
        if (data.error) {
            console.error("Stripe update error:", data.error);
        } else {
            console.log("Stripe payment intent updated successfully.");
        }
    })
    .catch(err => console.error("Stripe update catch:", err));
}

// Attach event listeners
document.addEventListener('DOMContentLoaded', () => {
    const pcInput = document.getElementById('delivery_postcode');
    if (pcInput) {
        pcInput.addEventListener('input', onPostcodeInput);
        pcInput.addEventListener('blur', onPostcodeInput);
        if (pcInput.value.trim()) onPostcodeInput();
    }
    
    // Structured address input listeners
    const addrHouse = document.getElementById('addr_house');
    const addrStreet = document.getElementById('addr_street');
    const addrCity = document.getElementById('addr_city');
    const btnManual = document.getElementById('btnManualAddress');
    
    if (addrHouse) addrHouse.addEventListener('input', updateConcatenatedAddress);
    if (addrStreet) addrStreet.addEventListener('input', updateConcatenatedAddress);
    if (addrCity) addrCity.addEventListener('input', updateConcatenatedAddress);
    
    if (btnManual) {
        btnManual.addEventListener('click', (e) => {
            e.preventDefault();
            switchToManualMode();
        });
    }

    // The markup is written in its delivery state — postcode box showing,
    // address required, warehouse panel hidden — because that is the state it
    // is in on all but a handful of evenings. When delivery is the method the
    // owner has switched off, the collection radio is the one rendered ticked,
    // and the panels have to be told to agree with it. Without this the
    // customer is asked for a delivery postcode for an order they are coming
    // to collect, and cannot submit until they fill it in.
    //
    // Runs LAST in this listener on purpose: it sets displays that the
    // postcode listener above can also touch, and the later write wins.
    if (CB_DEFAULT_ORDER_TYPE === 'collection') {
        toggleOrderType('collection');
    }
});
</script>
<script src="<?= cbAsset('../assets/js/modal.js') ?>" defer></script>
<script src="<?= cbAsset('../assets/js/animations.js') ?>" defer></script>

</body>
</html>

