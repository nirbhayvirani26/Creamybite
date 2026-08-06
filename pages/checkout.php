<?php
// ============================================================
//  Creamy Bite – Checkout Page
// ============================================================
session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/pricing.php';
require_once __DIR__ . '/../includes/trade_cart.php';
tradeSessionRevalidate($pdo);

// Load cart
$cart = $_SESSION['cart'] ?? [];
$errors = $_SESSION['checkout_errors'] ?? [];
unset($_SESSION['checkout_errors']);

// Redirect if cart empty
if (empty($cart)) {
    header('Location: order.php?empty_cart=1');
    exit;
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

$totals = computeOrderTotals($cart, $promoRow, 'delivery', '');

$cartTotal      = $totals['subtotal'];
$discountAmount = $totals['discount'];
$vatApplies     = $totals['vat_applies'];
$vatRate        = $totals['vat_rate'];
$vatAmount      = $totals['vat'];
$grandTotal     = $totals['total'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout – <?= SHOP_NAME ?></title>
    <meta name="description" content="Complete your ice cream order at <?= SHOP_NAME ?>.">
    <?php require __DIR__ . '/../includes/favicon.php'; ?>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/responsive.css">
    <link rel="stylesheet" href="../assets/css/animations.css">
    <link rel="stylesheet" href="../assets/css/components.css">
    <link rel="stylesheet" href="../assets/css/modal.css">
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
        <div class="cbco-page-head">
            <span class="section-label">Almost There!</span>
            <h1 class="cbco-page-title">Complete Your Order 🍦</h1>
            <p class="cbco-page-subtitle">Fill in your delivery details and we'll bring the sweetness to you.</p>
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

                <div class="glass-panel section-card">
                    <?php
                    $isTradeUser = !empty($_SESSION['trade_user']);
                    $tradeUser   = $_SESSION['trade_user'] ?? [];
                    ?>

                    <?php if ($isTradeUser): ?>
                    <!-- ── B2B TRADE CUSTOMER CHECKOUT ────────────────────────── -->
                    <div class="cbco-trade-banner">
                        <div class="cbco-trade-banner-eyebrow">🏪 B2B Trade Wholesale Checkout</div>
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
                        <div class="form-group cbco-mb-20">
                            <label class="form-label cbco-order-type-label">Order Delivery Method *</label>
                            <div class="cbco-order-type-grid">
                                <label id="type_delivery_label" class="cbco-order-type-option cbco-order-type-option-active">
                                    <input type="radio" name="order_type" value="delivery" checked class="cbco-hidden" onchange="toggleOrderType('delivery')">
                                    <i class="fa-solid fa-truck-fast cbco-order-type-icon cbco-icon-primary"></i>
                                    <span class="cbco-order-type-text cbco-text-primary">Store Delivery</span>
                                </label>
                                <label id="type_collection_label" class="cbco-order-type-option cbco-order-type-option-idle">
                                    <input type="radio" name="order_type" value="collection" class="cbco-hidden" onchange="toggleOrderType('collection')">
                                    <i class="fa-solid fa-store cbco-order-type-icon cbco-text-secondary"></i>
                                    <span class="cbco-order-type-text cbco-text-secondary">Warehouse Collection</span>
                                </label>
                            </div>
                        </div>

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
                        <div class="form-group cbco-mb-20">
                            <label class="form-label cbco-order-type-label">Order Type *</label>
                            <div class="cbco-order-type-grid">
                                <label id="type_delivery_label" class="cbco-order-type-option cbco-order-type-option-active">
                                    <input type="radio" name="order_type" value="delivery" checked class="cbco-hidden" onchange="toggleOrderType('delivery')">
                                    <i class="fa-solid fa-truck-fast cbco-order-type-icon cbco-icon-primary"></i>
                                    <span class="cbco-order-type-text cbco-text-primary">Delivery</span>
                                </label>
                                <label id="type_collection_label" class="cbco-order-type-option cbco-order-type-option-idle">
                                    <input type="radio" name="order_type" value="collection" class="cbco-hidden" onchange="toggleOrderType('collection')">
                                    <i class="fa-solid fa-store cbco-order-type-icon cbco-text-secondary"></i>
                                    <span class="cbco-order-type-text cbco-text-secondary">Collection</span>
                                </label>
                            </div>
                        </div>

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
                            <div class="osi-emoji"><?= htmlspecialchars($item['emoji'] ?? '🍦') ?></div>
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

                    <!-- Subtotal row -->
                    <div class="cbco-summary-row">
                        <span>Subtotal</span>
                        <span id="subtotalDisplay">£<?= number_format($cartTotal, 2) ?></span>
                    </div>

                    <!-- Discount row (hidden if no promo) -->
                    <div id="discountRow" class="cbco-summary-row cbco-summary-row-discount<?= $appliedPromo ? '' : ' cbco-hidden' ?>">
                        <span><i class="fa-solid fa-ticket"></i> Discount</span>
                        <span id="discountDisplay">−£<?= number_format($discountAmount, 2) ?></span>
                    </div>

                    <!-- Delivery charge row (hidden until postcode entered).
                         Wholesale deliveries are not charged per drop, so trade
                         never sees this row — it was appearing on trade baskets
                         the moment the summary refreshed, adding £1.99 to an
                         order that is not billed that way. -->
                    <?php if (!$isTradeUser): ?>
                    <div id="deliveryChargeRow" class="cbco-summary-row cbco-summary-row-delivery">
                        <span><i class="fa-solid fa-truck-fast"></i> Delivery</span>
                        <span id="deliveryChargeAmt">+ £<?= number_format(DELIVERY_CHARGE, 2) ?></span>
                    </div>
                    <?php endif; ?>

                    <!-- VAT row (trade partners with a VAT number only) -->
                    <?php if ($vatApplies): ?>
                    <div id="vatRow" class="cbco-summary-row cbco-summary-row-vat">
                        <span><i class="fa-solid fa-receipt"></i> VAT @ <?= (int)($vatRate * 100) ?>%</span>
                        <span id="vatDisplay">+ £<?= number_format($vatAmount, 2) ?></span>
                    </div>
                    <?php endif; ?>

                    <hr class="summary-divider cbco-summary-divider-tight">

                    <div class="summary-total-row">
                        <span class="summary-total-label">Total</span>
                        <span class="summary-total-price" id="summaryTotal">£<?= number_format($grandTotal, 2) ?></span>
                    </div>

                    <?php if ($vatApplies): ?>
                    <div class="cbco-vat-note">
                        VAT charged against <strong><?= htmlspecialchars(tradeVatNumber()) ?></strong>
                    </div>
                    <?php endif; ?>

                    <?php if (!$isTradeUser): ?>
                    <div id="deliveryInfoBanner" class="cbco-delivery-banner">
                        <p class="cbco-delivery-banner-text">
                            <i class="fa-solid fa-location-dot"></i>
                            Enter your postcode above to see delivery cost
                        </p>
                    </div>
                    <?php endif; ?>

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
// ── Promo Code & Totals Recalculation ───────────────────────────
let cartSubtotal = <?= (float)$cartTotal ?>;
let deliveryCharge = 0.0;
let appliedPromo = <?= json_encode($appliedPromo) ?>;
const vatApplies = <?= $vatApplies ? 'true' : 'false' ?>;
const vatRate    = <?= (float)$vatRate ?>;

function recalculateTotals() {
    let subtotal = cartSubtotal;
    let discount = 0.0;
    
    if (appliedPromo) {
        const val = parseFloat(appliedPromo.discount_value);
        if (appliedPromo.discount_type === 'percentage') {
            discount = Math.round(subtotal * val) / 100;
        } else {
            discount = Math.min(val, subtotal);
        }
        
        const discountRow = document.getElementById('discountRow');
        const discountDisplay = document.getElementById('discountDisplay');
        if (discountRow && discountDisplay) {
            discountRow.style.display = discount > 0 ? 'flex' : 'none';
            discountDisplay.textContent = '−£' + discount.toFixed(2);
        }
    } else {
        const discountRow = document.getElementById('discountRow');
        if (discountRow) discountRow.style.display = 'none';
    }
    
    const isCollection = document.querySelector('input[name="order_type"]:checked')?.value === 'collection';
    let currentDelivery = isCollection ? 0.0 : deliveryCharge;

    // Mirrors computeOrderTotals() in pricing.php: VAT applies to the
    // discounted goods plus delivery. Keep the two in step.
    const base = Math.max(0, subtotal - discount + currentDelivery);
    const vat  = vatApplies ? Math.round(base * vatRate * 100) / 100 : 0.0;

    const vatRow = document.getElementById('vatRow');
    const vatDisplay = document.getElementById('vatDisplay');
    if (vatRow && vatDisplay) {
        vatRow.style.display = vatApplies ? 'flex' : 'none';
        vatDisplay.textContent = '+ £' + vat.toFixed(2);
    }

    let grandTotal = base + vat;

    checkMinimumOrder();

    const totalEl = document.getElementById('summaryTotal');
    if (totalEl) {
        totalEl.textContent = '£' + grandTotal.toFixed(2);
    }
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

function showPromoMsg(msg, type) {
    const el = document.getElementById('promoMsg');
    if (!el) return;
    el.textContent = msg;
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
                '⚠️ Promo removed — minimum order £' + minOrder.toFixed(2) + ' not met.',
                'error'
            );

            // Hide discount row
            const discountRow = document.getElementById('discountRow');
            if (discountRow) discountRow.style.display = 'none';
        }
    }

    // ── 2. Re-check delivery if postcode already looked up ────────
    if (!isCollection && lastCalculatedMiles >= 0) {
        const statusEl    = document.getElementById('postcodeStatus');
        const chargeInput = document.getElementById('delivery_charge_input');

        if (lastCalculatedMiles <= FREE_MILES) {
            if (chargeInput) chargeInput.value = '0';
            if (statusEl) statusEl.innerHTML = '<span class="cbco-status cbco-status-ok"><i class="fa-solid fa-circle-check"></i> 🎉 Free delivery! You are within ' + lastCalculatedMiles.toFixed(1) + ' miles.</span>';
            updateDeliveryDisplay(0);
        } else {
            if (chargeInput) chargeInput.value = DELIVERY_CHARGE.toFixed(2);
            if (statusEl) statusEl.innerHTML = '<span class="cbco-status cbco-status-warn"><i class="fa-solid fa-truck-fast"></i> ' + DELIVERY_CHARGE_TXT + ' delivery charge – ' + lastCalculatedMiles.toFixed(1) + ' miles from us.</span>';
            updateDeliveryDisplay(DELIVERY_CHARGE);
        }
    } else {
        // No postcode yet — just recalculate totals with whatever we have
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
                : `<div class="cbco-inline-error"><i class="fa-solid fa-triangle-exclamation"></i> ${data.error}</div>`;
            // Fall back to Pay Later when card payment cannot work at all —
            // an expired key, or keys never configured. Leaving the customer
            // on a card form that will never load loses the order outright.
            if (data.fallback_to_later || data.error.includes('REPLACE_ME') || data.error.includes('setup')) {
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
// Pre-formatted for the messages below: "6" not "6.0", "1.99" with two places.
const MAX_MILES_TXT = MAX_DELIVERY_MILES.toString().replace(/\.0$/, '');
const DELIVERY_CHARGE_TXT = '£' + DELIVERY_CHARGE.toFixed(2);

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
                    updateDeliveryDisplay(0);
                    return;
                } else {
                    if (submitBtn) {
                        delete submitBtn.dataset.blockedByDistance;
                        submitBtn.disabled = false;
                    }
                    checkMinimumOrder();   // distance is fine; the basket may not be
                }

                if (miles <= FREE_MILES) {
                    chargeInput.value = '0';
                    statusEl.innerHTML = '<span class="cbco-status cbco-status-ok"><i class="fa-solid fa-circle-check"></i> 🎉 Free delivery! You are within ' + miles.toFixed(1) + ' miles.</span>';
                    updateDeliveryDisplay(0);
                } else {
                    chargeInput.value = DELIVERY_CHARGE.toFixed(2);
                    statusEl.innerHTML = '<span class="cbco-status cbco-status-warn"><i class="fa-solid fa-truck-fast"></i> ' + DELIVERY_CHARGE_TXT + ' delivery charge – ' + miles.toFixed(1) + ' miles from us.</span>';
                    updateDeliveryDisplay(DELIVERY_CHARGE);
                }

                const city = data.result.admin_district || data.result.region || '';
                switchToStructuredMode(city);
            })
            .catch(() => {
                statusEl.innerHTML = '<span class="cbco-status cbco-status-warn"><i class="fa-solid fa-triangle-exclamation"></i> Could not check postcode. Delivery charge will be calculated at checkout.</span>';
                chargeInput.value = '0';
                resetAddressModes();
            });
    }, 600);
}

function updateDeliveryDisplay(charge) {
    deliveryCharge = charge;
    const chargeInput = document.getElementById('delivery_charge_input');
    if (chargeInput) chargeInput.value = charge.toFixed(2);

    const deliveryRow   = document.getElementById('deliveryChargeRow');
    const deliveryAmt   = document.getElementById('deliveryChargeAmt');
    const infoBanner    = document.getElementById('deliveryInfoBanner');
    if (deliveryRow) {
        deliveryRow.style.display = charge > 0 ? 'flex' : 'none';
    }
    if (deliveryAmt) {
        deliveryAmt.textContent = '+ £' + charge.toFixed(2);
    }
    if (infoBanner) {
        if (charge === 0) {
            infoBanner.style.background = 'rgba(16,185,129,0.06)';
            infoBanner.style.borderColor = 'rgba(16,185,129,0.25)';
            infoBanner.innerHTML = '<p class="cbco-delivery-banner-text cbco-text-success"><i class="fa-solid fa-truck-fast"></i><strong>Free delivery</strong> to your postcode!</p>';
        } else {
            infoBanner.style.background = 'rgba(245,158,11,0.06)';
            infoBanner.style.borderColor = 'rgba(245,158,11,0.25)';
            infoBanner.innerHTML = '<p class="cbco-delivery-banner-text cbco-status-warn"><i class="fa-solid fa-truck-fast"></i><strong>' + DELIVERY_CHARGE_TXT + '</strong> delivery charge applies</p>';
        }
    }
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
});
</script>
<script src="../assets/js/modal.js" defer></script>
<script src="../assets/js/animations.js" defer></script>

</body>
</html>

