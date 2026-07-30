<?php
// ============================================================
//  Creamy Bite – Checkout Page
// ============================================================
session_start();
require_once 'config.php';
require_once 'db.php';

// Load cart
$cart = $_SESSION['cart'] ?? [];
$errors = $_SESSION['checkout_errors'] ?? [];
unset($_SESSION['checkout_errors']);

// Redirect if cart empty
if (empty($cart)) {
    header('Location: order.php?empty_cart=1');
    exit;
}

// Calculate totals
$cartTotal = 0.0;
foreach ($cart as $item) {
    $cartTotal += $item['price'] * $item['quantity'];
}

// Load applied promo from session
$appliedPromo  = $_SESSION['promo'] ?? null;
$discountAmount = $appliedPromo ? (float)$appliedPromo['discount_amount'] : 0.0;
$grandTotal    = max(0, $cartTotal - $discountAmount);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout – <?= SHOP_NAME ?></title>
    <meta name="description" content="Complete your ice cream order at <?= SHOP_NAME ?>.">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="responsive.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://js.stripe.com/v3/"></script>
</head>
<body>

<!-- Navbar -->
<header class="navbar">
    <div class="container nav-container-centered">
        <nav class="nav-left">
            <ul class="nav-links">
                <li><a href="index.php">Home</a></li>
                <li><a href="order.php">Order</a></li>
                <li><a href="gallery.php">Gallery</a></li>
                <li><a href="about.php">About Us</a></li>
            </ul>
        </nav>

        <a href="index.php" class="logo logo-center">
            <img src="assets/images/logo.png" alt="<?= SHOP_NAME ?>" class="logo-img">
        </a>

        <div class="nav-actions nav-right">
            <a href="order.php" class="btn-secondary" style="padding:10px 18px; font-size:13px;">
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
            <li><a href="index.php">Home</a></li>
            <li><a href="order.php">Order</a></li>
            <li><a href="gallery.php">Gallery</a></li>
            <li><a href="about.php">About Us</a></li>
        </ul>
        <div class="mobile-nav-actions">
            <a href="order.php" class="btn-secondary" style="justify-content:center; display:flex;">
                <i class="fa-solid fa-arrow-left"></i> Back to Menu
            </a>
        </div>
    </div>
</div>

<!-- Checkout Content -->
<main class="checkout-page">
    <div class="container">

        <!-- Page Title -->
        <div style="text-align:center; margin-bottom:40px;">
            <span class="section-label">Almost There!</span>
            <h1 style="font-size:36px; margin-top:12px;">Complete Your Order 🍦</h1>
            <p style="color:var(--text-secondary); margin-top:8px;">Fill in your delivery details and we'll bring the sweetness to you.</p>
        </div>

        <div class="checkout-grid">

            <!-- ── Customer Form ──────────────────────────── -->
            <div>
                <?php if (!empty($errors)): ?>
                <div class="alert alert-danger" style="margin-bottom:24px;">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    <div>
                        <?php foreach ($errors as $err): ?>
                        <div><?= htmlspecialchars($err) ?></div>
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
                    <div style="background:linear-gradient(135deg, var(--color-primary), var(--color-primary-dark)); color:white; padding:18px; border-radius:12px; margin-bottom:24px; box-shadow:var(--shadow-md);">
                        <div style="font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:0.12em; color:var(--color-secondary);">🏪 B2B Trade Wholesale Checkout</div>
                        <h2 style="font-size:20px; margin:4px 0 6px; color:white;"><?= htmlspecialchars($tradeUser['business_name']) ?></h2>
                        <p style="font-size:13px; margin:0; opacity:0.9; line-height:1.4;">
                            Registered Delivery Address: <strong><?= htmlspecialchars($tradeUser['address']) ?>, <?= htmlspecialchars($tradeUser['postcode']) ?></strong>
                        </p>
                    </div>

                    <h2><i class="fa-solid fa-user-check" style="color:var(--color-primary);"></i> Contact & Delivery Instructions</h2>

                    <form action="checkout_handler.php" method="POST" id="checkoutForm">

                        <!-- Bot protection (honeypot) -->
                        <input type="text" name="website" id="hp_website" tabindex="-1" autocomplete="off" aria-hidden="true" style="display:none !important; visibility:hidden; position:absolute; left:-9999px; width:0; height:0; overflow:hidden;">
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

                        <div class="form-row" style="display:grid; grid-template-columns:1fr 1fr; gap:16px;">
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
                        <div class="form-group" style="margin-bottom: 20px;">
                            <label class="form-label" style="font-weight: 600; display: block; margin-bottom: 8px;">Order Delivery Method *</label>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                                <label id="type_delivery_label" style="cursor: pointer; border: 2px solid var(--color-primary); padding: 14px 10px; border-radius: var(--radius-sm); text-align: center; display: block; transition: all 0.2s ease; background: var(--color-primary-bg);">
                                    <input type="radio" name="order_type" value="delivery" checked style="display: none;" onchange="toggleOrderType('delivery')">
                                    <i class="fa-solid fa-truck-fast" style="font-size: 20px; display: block; margin-bottom: 8px; color: var(--color-primary);"></i>
                                    <span style="font-weight: 700; font-size: 14px; color: var(--text-primary); display: block;">Store Delivery</span>
                                </label>
                                <label id="type_collection_label" style="cursor: pointer; border: 1px solid var(--border-light); padding: 14px 10px; border-radius: var(--radius-sm); text-align: center; display: block; transition: all 0.2s ease; background: var(--bg-surface);">
                                    <input type="radio" name="order_type" value="collection" style="display: none;" onchange="toggleOrderType('collection')">
                                    <i class="fa-solid fa-store" style="font-size: 20px; display: block; margin-bottom: 8px; color: var(--text-secondary);"></i>
                                    <span style="font-weight: 700; font-size: 14px; color: var(--text-secondary); display: block;">Warehouse Collection</span>
                                </label>
                            </div>
                        </div>

                        <!-- B2B Trade Delivery Instructions (Opening Hours & Delivery Place) -->
                        <div class="form-group" style="margin-bottom:20px;">
                            <label for="notes" class="form-label" style="font-weight:700;">
                                <i class="fa-solid fa-clock" style="color:var(--color-primary);"></i> Store Opening Hours & Delivery Instructions *
                            </label>
                            <textarea id="notes" name="notes" class="form-control" rows="3"
                                placeholder="Please specify: 
1. Store opening hours (e.g. 9:00 AM - 8:00 PM)
2. Delivery place / drop-off location (e.g. Rear loading bay / Front counter)
3. Any access codes or special delivery notes" required><?= htmlspecialchars($_POST['notes'] ?? '') ?></textarea>
                            <small style="font-size:12px; color:var(--text-muted); margin-top:4px; display:block;">
                                <i class="fa-solid fa-circle-info"></i> Our drivers will use these details for smooth store delivery.
                            </small>
                        </div>

                    <?php else: ?>
                    <!-- ── RETAIL CUSTOMER CHECKOUT ────────────────────────── -->
                    <h2><i class="fa-solid fa-user" style="color:var(--color-primary);"></i> Delivery Details</h2>

                    <form action="checkout_handler.php" method="POST" id="checkoutForm">

                        <!-- ── Bot protection (honeypot) ─────────── -->
                        <input type="text" name="website" id="hp_website" tabindex="-1" autocomplete="off"
                               aria-hidden="true"
                               style="display:none !important; visibility:hidden; position:absolute; left:-9999px; width:0; height:0; overflow:hidden;">
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
                            <small style="font-size:12px; color:var(--text-muted); margin-top:4px; display:block;">
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
                        <div class="form-group" style="margin-bottom: 20px;">
                            <label class="form-label" style="font-weight: 600; display: block; margin-bottom: 8px;">Order Type *</label>
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
                                <label id="type_delivery_label" style="cursor: pointer; border: 2px solid var(--color-primary); padding: 14px 10px; border-radius: var(--radius-sm); text-align: center; display: block; transition: all 0.2s ease; background: var(--color-primary-bg);">
                                    <input type="radio" name="order_type" value="delivery" checked style="display: none;" onchange="toggleOrderType('delivery')">
                                    <i class="fa-solid fa-truck-fast" style="font-size: 20px; display: block; margin-bottom: 8px; color: var(--color-primary);"></i>
                                    <span style="font-weight: 700; font-size: 14px; color: var(--text-primary); display: block;">Delivery</span>
                                </label>
                                <label id="type_collection_label" style="cursor: pointer; border: 1px solid var(--border-light); padding: 14px 10px; border-radius: var(--radius-sm); text-align: center; display: block; transition: all 0.2s ease; background: var(--bg-surface);">
                                    <input type="radio" name="order_type" value="collection" style="display: none;" onchange="toggleOrderType('collection')">
                                    <i class="fa-solid fa-store" style="font-size: 20px; display: block; margin-bottom: 8px; color: var(--text-secondary);"></i>
                                    <span style="font-weight: 700; font-size: 14px; color: var(--text-secondary); display: block;">Collection</span>
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
                            <small style="font-size:12px; color:var(--text-muted); margin-top:4px; display:block;">
                                <i class="fa-solid fa-circle-info"></i> Used to calculate delivery distance and pre-fill address
                            </small>
                            <div id="postcodeStatus" style="margin-top:8px; font-size:13px; display:none;"></div>
                        </div>
                        <input type="hidden" name="delivery_charge" id="delivery_charge_input" value="0">

                        <!-- Address fields container (Hidden when Collection is active) -->
                        <div id="address_fields_container">
                            <!-- Structured Address Section (Hidden by default, shown when valid postcode is checked) -->
                            <div id="structuredAddressSection" style="display:none; border:1px solid var(--border-light); padding:16px; border-radius:var(--radius-sm); background:rgba(255,255,255,0.02); margin-bottom:20px;">
                                <div style="display:grid; grid-template-columns:1fr 2fr; gap:12px; margin-bottom:12px;">
                                    <div>
                                        <label for="addr_house" class="form-label" style="font-size:13px;">House/Flat No. *</label>
                                        <input type="text" id="addr_house" class="form-control" placeholder="e.g. 15" autocomplete="address-line2">
                                    </div>
                                    <div>
                                        <label for="addr_street" class="form-label" style="font-size:13px;">Street Name *</label>
                                        <input type="text" id="addr_street" class="form-control" placeholder="e.g. High Street" autocomplete="address-line1">
                                    </div>
                                </div>
                                <div style="margin-bottom:12px;">
                                    <label for="addr_city" class="form-label" style="font-size:13px;">Town / City *</label>
                                    <input type="text" id="addr_city" class="form-control" placeholder="e.g. London" autocomplete="address-level2">
                                </div>
                                <div style="text-align:right;">
                                    <a href="#" id="btnManualAddress" style="font-size:12px; color:var(--color-primary); text-decoration:none; font-weight:600;"><i class="fa-solid fa-pen"></i> Enter address manually</a>
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
                        <div id="warehouseCollectionInfo" style="display: none; border: 1px solid var(--color-primary-light); padding: 18px; border-radius: var(--radius-sm); background: var(--color-primary-bg); margin-bottom: 20px;">
                            <h3 style="font-size: 16px; margin: 0 0 10px; color: var(--color-primary); display: flex; align-items: center; gap: 8px;">
                                <i class="fa-solid fa-store"></i> Warehouse Collection Details
                            </h3>
                            <p style="font-size: 14px; margin: 0 0 8px; color: var(--text-primary); line-height: 1.5;">
                                <strong>Creamy Bite Warehouse:</strong><br>
                                Unit E5, Phoenix Business Centre,<br>
                                HA1 2SP
                            </p>
                            <p style="font-size: 13px; margin: 0 0 12px; color: var(--text-secondary);">
                                <i class="fa-solid fa-clock" style="color: var(--color-primary);"></i> <strong>Collection Time:</strong> 11 AM to 8 PM
                            </p>
                            <div style="margin-top: 10px;">
                                <a href="https://maps.app.goo.gl/hrMSnTRqFvorzF7HA?g_st=iw" target="_blank" rel="noopener" style="display: inline-flex; align-items: center; gap: 6px; background: var(--color-accent); color: var(--text-primary); font-size: 12px; font-weight: 700; padding: 6px 14px; border-radius: 20px; text-decoration: none; box-shadow: var(--shadow-xs);">
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
                        <div style="margin-bottom:24px;">
                            <label class="form-label" style="margin-bottom:10px;">How would you like to pay?</label>

                            <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                                <label id="payOnlineLabel" onclick="selectPayment('online')" style="display:flex; align-items:center; gap:10px; padding:14px 16px; border:2px solid var(--color-primary); border-radius:var(--radius-sm); cursor:pointer; background:var(--color-primary-bg); transition:all 0.2s;">
                                    <input type="radio" name="payment_method" value="online" id="payOnlineRadio" style="width:16px;height:16px;" checked>
                                    <span>
                                        <i class="fa-solid fa-credit-card" style="color:var(--color-primary);"></i>
                                        <strong style="display:block; font-size:14px; margin-top:2px;">Pay Online</strong>
                                        <span style="font-size:11px; color:var(--text-muted);">Card, Apple Pay, Google Pay</span>
                                    </span>
                                </label>

                                <label id="payLaterLabel" onclick="selectPayment('later')" style="display:flex; align-items:center; gap:10px; padding:14px 16px; border:2px solid var(--border-light); border-radius:var(--radius-sm); cursor:pointer; background:var(--bg-surface); transition:all 0.2s;">
                                    <input type="radio" name="payment_method" value="later" id="payLaterRadio" style="width:16px;height:16px;">
                                    <span>
                                        <i class="fa-solid fa-phone" style="color:var(--text-secondary);"></i>
                                        <strong style="display:block; font-size:14px; margin-top:2px;">Pay Later</strong>
                                        <span style="font-size:11px; color:var(--text-muted);">We'll contact you</span>
                                    </span>
                                </label>
                            </div>

                            <!-- Stripe Payment Element (shown when 'Pay Online' selected) -->
                            <div id="stripePanel" style="margin-top:16px;">
                                <div id="stripeElement" style="padding:16px; background:var(--bg-main); border:1px solid var(--border-light); border-radius:var(--radius-sm); min-height:60px;">
                                    <div style="text-align:center; color:var(--text-muted); font-size:13px; padding:8px;">
                                        <i class="fa-solid fa-spinner fa-spin"></i> Loading secure payment form...
                                    </div>
                                </div>
                                <div id="stripeError" style="font-size:12px; color:#ef4444; margin-top:8px; display:none;"></div>
                                <div style="display:flex; align-items:center; gap:6px; margin-top:8px; font-size:11px; color:var(--text-muted);">
                                    <i class="fa-solid fa-lock" style="color:#10b981;"></i>
                                    Secured by Stripe &mdash; we never see your card details
                                </div>
                            </div>

                            <!-- Pay Later info (shown when 'Pay Later' selected) -->
                            <div id="laterPanel" style="display:none; margin-top:12px;">
                                <div class="alert alert-info" style="font-size:13px; margin:0;">
                                    <i class="fa-solid fa-phone"></i>
                                    <span>No payment now — we'll contact you to confirm and arrange payment.</span>
                                </div>
                            </div>
                        </div>

                        <button type="button" id="placeOrderBtn" onclick="handleCheckout()" class="btn-primary" style="width:100%; justify-content:center; font-size:16px; padding:16px;">
                            <i class="fa-solid fa-credit-card" id="btnIcon"></i>
                            <span id="btnText">Pay Now</span>
                        </button>

                    </form>
                </div>
            </div>

            <!-- ── Order Summary (Editable) ──────────────────────────── -->
            <div>
                <div class="glass-panel section-card" style="position:sticky; top:88px;">
                    <h2><i class="fa-solid fa-receipt" style="color:var(--color-secondary);"></i> Order Summary</h2>
                    <p style="font-size:12px; color:var(--text-muted); margin-bottom:18px; margin-top:-8px;">
                        <i class="fa-solid fa-pen-to-square"></i> You can edit quantities below
                    </p>

                    <div id="summaryItems">
                        <?php foreach ($cart as $cartKey => $item):
                            $lineTotal = $item['price'] * $item['quantity'];
                            $imgSrc = !empty($item['image']) ? 'assets/images/products/' . htmlspecialchars($item['image']) : '';
                            $safeKey = htmlspecialchars($cartKey, ENT_QUOTES);
                            $domKey  = preg_replace('/[^a-z0-9]/i', '-', $cartKey);
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
                                    <span style="display:block;font-size:11px;color:var(--text-muted);font-weight:500;margin-top:2px;"><?= htmlspecialchars($item['variant_name']) ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="qty-controls" style="margin-top:4px;">
                                    <button class="qty-btn" onclick="summaryUpdateQty('<?= $safeKey ?>', '<?= $domKey ?>', <?= (float)$item['price'] ?>, -1)">−</button>
                                    <span class="qty-value" id="sq-<?= $domKey ?>"><?= $item['quantity'] ?></span>
                                    <button class="qty-btn" onclick="summaryUpdateQty('<?= $safeKey ?>', '<?= $domKey ?>', <?= (float)$item['price'] ?>, 1)">+</button>
                                </div>
                            </div>
                            <div>
                                <div class="osi-price" id="sp-<?= $domKey ?>">£<?= number_format($lineTotal, 2) ?></div>
                                <button class="btn-remove-item" onclick="summaryRemoveItem('<?= $safeKey ?>', '<?= $domKey ?>')" title="Remove" style="display:block; margin-top:4px;">
                                    <i class="fa-solid fa-xmark"></i>
                                </button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <hr class="summary-divider">

                    <!-- Promo Code Input -->
                    <div id="promoSection" style="margin-bottom:14px;">
                        <?php if ($appliedPromo): ?>
                        <div id="promoApplied" style="display:flex; align-items:center; justify-content:space-between; padding:10px 14px; background:rgba(16,185,129,0.08); border:1px solid rgba(16,185,129,0.25); border-radius:var(--radius-sm);">
                            <div style="font-size:13px;">
                                <i class="fa-solid fa-check-circle" style="color:#10b981;"></i>
                                <strong style="color:#10b981; letter-spacing:1px;"><?= htmlspecialchars($appliedPromo['code']) ?></strong>
                                <span style="color:var(--text-secondary);">&nbsp;— <?= htmlspecialchars($appliedPromo['label']) ?></span>
                            </div>
                            <button onclick="removePromo()" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:14px;" title="Remove promo">
                                <i class="fa-solid fa-xmark"></i>
                            </button>
                        </div>
                        <?php else: ?>
                        <div id="promoApplied" style="display:none;"></div>
                        <div id="promoInputRow" style="display:flex; gap:8px;">
                            <input type="text" id="promoInput" placeholder="Promo code" class="form-control" style="flex:1; text-transform:uppercase; letter-spacing:1px;" oninput="this.value=this.value.toUpperCase()">
                            <button class="btn-secondary" onclick="applyPromo()" style="padding:10px 16px; font-size:13px; white-space:nowrap;">
                                <i class="fa-solid fa-tag"></i> Apply
                            </button>
                        </div>
                        <div id="promoMsg" style="font-size:12px; margin-top:6px; min-height:16px;"></div>
                        <?php endif; ?>
                    </div>

                    <!-- Subtotal row -->
                    <div style="display:flex; justify-content:space-between; font-size:14px; color:var(--text-secondary); margin-bottom:6px;">
                        <span>Subtotal</span>
                        <span id="subtotalDisplay">£<?= number_format($cartTotal, 2) ?></span>
                    </div>

                    <!-- Discount row (hidden if no promo) -->
                    <div id="discountRow" style="display:<?= $appliedPromo ? 'flex' : 'none' ?>; justify-content:space-between; font-size:14px; color:#10b981; margin-bottom:6px; font-weight:600;">
                        <span><i class="fa-solid fa-ticket"></i> Discount</span>
                        <span id="discountDisplay">−£<?= number_format($discountAmount, 2) ?></span>
                    </div>

                    <!-- Delivery charge row (hidden until postcode entered) -->
                    <div id="deliveryChargeRow" style="display:none; justify-content:space-between; font-size:14px; color:#f59e0b; margin-bottom:6px; font-weight:600;">
                        <span><i class="fa-solid fa-truck-fast"></i> Delivery</span>
                        <span id="deliveryChargeAmt">+ £1.99</span>
                    </div>

                    <hr class="summary-divider" style="margin:10px 0;">

                    <div class="summary-total-row">
                        <span class="summary-total-label">Total</span>
                        <span class="summary-total-price" id="summaryTotal">£<?= number_format($grandTotal, 2) ?></span>
                    </div>

                    <div id="deliveryInfoBanner" style="margin-top:20px; padding:14px; background:rgba(0,201,167,0.06); border:1px solid rgba(0,201,167,0.15); border-radius:var(--radius-sm);">
                        <p style="font-size:13px; color:var(--color-secondary); display:flex; align-items:center; gap:8px;">
                            <i class="fa-solid fa-location-dot"></i>
                            Enter your postcode above to see delivery cost
                        </p>
                    </div>

                    <div style="margin-top:14px;">
                        <a href="order.php" style="font-size:13px; color:var(--text-muted); display:inline-flex; align-items:center; gap:6px;">
                            <i class="fa-solid fa-arrow-left"></i> Add more items
                        </a>
                    </div>
                </div>
            </div>

        </div>
    </div>
</main>

<footer class="footer-enhanced">
    <div class="container">
        <div class="footer-bottom-bar" style="padding-top:0;">
            <a href="index.php"><img src="assets/images/logo.png" alt="<?= SHOP_NAME ?>" class="footer-logo-img" style="height:34px;"></a>
            <span class="footer-copy-text">© <?= date('Y') ?> <?= SHOP_NAME ?>. All rights reserved.</span>
        </div>
    </div>
</footer>

<script>
// ── Promo Code & Totals Recalculation ───────────────────────────
let cartSubtotal = <?= (float)$cartTotal ?>;
let deliveryCharge = 0.0;
let appliedPromo = <?= json_encode($appliedPromo) ?>;

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
    
    let grandTotal = Math.max(0, subtotal - discount + currentDelivery);
    
    const totalEl = document.getElementById('summaryTotal');
    if (totalEl) {
        totalEl.textContent = '£' + grandTotal.toFixed(2);
    }
}

function applyPromo() {
    const code = (document.getElementById('promoInput')?.value || '').trim().toUpperCase();
    const msgEl = document.getElementById('promoMsg');
    if (!code) { showPromoMsg('Please enter a promo code.', 'error'); return; }

    fetch(`promo_handler.php?action=validate&code=${encodeURIComponent(code)}&cart_total=${cartSubtotal}`)
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
                <div style="font-size:13px;">
                    <i class="fa-solid fa-check-circle" style="color:#10b981;"></i>
                    <strong style="color:#10b981; letter-spacing:1px;">${data.code}</strong>
                    <span style="color:var(--text-secondary);">&nbsp;— ${data.discount_label}</span>
                </div>
                <button onclick="removePromo()" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:14px;" title="Remove promo">
                    <i class="fa-solid fa-xmark"></i>
                </button>`;
            
            showPromoMsg(data.message, 'success');
            recalculateTotals();
            triggerStripeAmountUpdate();
        })
        .catch(() => showPromoMsg('Could not apply promo. Try again.', 'error'));
}

function removePromo() {
    fetch('promo_handler.php?action=remove').then(() => location.reload());
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
            fetch('promo_handler.php?action=remove');

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
            if (statusEl) statusEl.innerHTML = '<span style="color:#10b981; font-weight:600;"><i class="fa-solid fa-circle-check"></i> 🎉 Free delivery! You are within ' + lastCalculatedMiles.toFixed(1) + ' miles.</span>';
            updateDeliveryDisplay(0);
        } else {
            if (chargeInput) chargeInput.value = DELIVERY_CHARGE.toFixed(2);
            if (statusEl) statusEl.innerHTML = '<span style="color:#f59e0b; font-weight:600;"><i class="fa-solid fa-truck-fast"></i> £1.99 delivery charge – ' + lastCalculatedMiles.toFixed(1) + ' miles from us.</span>';
            updateDeliveryDisplay(DELIVERY_CHARGE);
        }
    } else {
        // No postcode yet — just recalculate totals with whatever we have
        recalculateTotals();
    }
}

// ── Order Summary qty/remove ───────────────────────────
function summaryUpdateQty(cartKey, domKey, price, delta) {
    const qtyEl   = document.getElementById('sq-' + domKey);
    const priceEl = document.getElementById('sp-' + domKey);
    let qty = parseInt(qtyEl.textContent) + delta;

    if (qty <= 0) { summaryRemoveItem(cartKey, domKey); return; }

    fetch('cart_handler.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=update&cart_key=' + encodeURIComponent(cartKey) + '&quantity=' + qty,
    })
    .then(r => r.json())
    .then(data => {
        qtyEl.textContent   = qty;
        priceEl.textContent = '£' + (price * qty).toFixed(2);

        cartSubtotal = data.cart_total_raw;
        const subtotalEl = document.getElementById('subtotalDisplay');
        if (subtotalEl) subtotalEl.textContent = '£' + cartSubtotal.toFixed(2);

        reEvaluateCharges();   // ← live-update delivery + promo
        triggerStripeAmountUpdate();

        if (data.items.length === 0) window.location.href = 'order.php';
    });
}

function summaryRemoveItem(cartKey, domKey) {
    fetch('cart_handler.php', {
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
        const res  = await fetch('stripe_intent.php');
        const data = await res.json();

        if (data.error) {
            document.getElementById('stripeElement').innerHTML =
                `<div style="color:#ef4444;font-size:13px;padding:10px;"><i class="fa-solid fa-triangle-exclamation"></i> ${data.error}</div>`;
            // Auto-switch to Pay Later if Stripe keys not set yet
            if (data.error.includes('REPLACE_ME') || data.error.includes('setup')) {
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
            '<div style="color:#ef4444;font-size:13px;padding:10px;"><i class="fa-solid fa-triangle-exclamation"></i> Could not load payment form. You can still choose Pay Later.</div>';
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

    if (currentPaymentMethod === 'later') {
        // Pay Later — just submit the form
        document.querySelector('[name="payment_method"]').value = 'later';
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Placing order...';
        form.submit();
        return;
    }

    // Pay Online — process via Stripe
    if (!stripeReady) {
        alert('Payment form is still loading. Please wait a moment or choose Pay Later.');
        return;
    }

    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Processing payment...';

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
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-lock"></i> <span>Pay Now</span>';
    } else {
        // Payment succeeded — submit form to save order
        document.querySelector('[name="payment_method"]').value = 'online';
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving order...';
        form.submit();
    }
}
</script>

<script>
// ── Postcode delivery charge and address lookup ──────────────────
const SHOP_LAT = 51.5729;
const SHOP_LON = -0.3356; // HA1 2SP coordinates
const DELIVERY_CHARGE   = 1.99;
const FREE_MILES        = 3;    // Within 3 miles = free delivery
const MIN_ORDER         = 10.00; // Minimum order value for delivery

let lastCalculatedMiles = -1; // cache so we can re-evaluate on cart changes

let manualMode = false;

function haversineDistance(lat1, lon1, lat2, lon2) {
    const R = 3958.8; // Earth radius in miles
    const dLat = (lat2 - lat1) * Math.PI / 180;
    const dLon = (lon2 - lon1) * Math.PI / 180;
    const a = Math.sin(dLat/2) * Math.sin(dLat/2) +
              Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
              Math.sin(dLon/2) * Math.sin(dLon/2);
    return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
}

function isValidUKPostcode(pc) {
    return /^[A-Z]{1,2}[0-9][0-9A-Z]?\s*[0-9][A-Z]{2}$/i.test(pc.trim());
}

function updateConcatenatedAddress() {
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
    manualMode = true;
    document.getElementById('structuredAddressSection').style.display = 'none';
    document.getElementById('manualAddressSection').style.display = 'block';
    
    document.getElementById('address').required = true;
    document.getElementById('addr_house').required = false;
    document.getElementById('addr_street').required = false;
    document.getElementById('addr_city').required = false;
}

function switchToStructuredMode(cityValue) {
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
    const statusEl = document.getElementById('postcodeStatus');
    const chargeInput = document.getElementById('delivery_charge_input');

    if (!pc) {
        statusEl.style.display = 'none';
        chargeInput.value = '0';
        updateDeliveryDisplay(0);
        resetAddressModes();
        return;
    }

    if (!isValidUKPostcode(pc)) {
        statusEl.style.display = 'block';
        statusEl.innerHTML = '<span style="color:#ef4444;"><i class="fa-solid fa-circle-xmark"></i> Please enter a valid UK postcode</span>';
        resetAddressModes();
        return;
    }

    statusEl.style.display = 'block';
    statusEl.innerHTML = '<span style="color:var(--text-muted);"><i class="fa-solid fa-spinner fa-spin"></i> Checking delivery distance...</span>';

    postcodeTimeout = setTimeout(() => {
        const cleanPc = pc.replace(/\s/g, '');
        fetch('https://api.postcodes.io/postcodes/' + encodeURIComponent(cleanPc))
            .then(r => r.json())
            .then(data => {
                if (!data.result) {
                    statusEl.innerHTML = '<span style="color:#ef4444;"><i class="fa-solid fa-circle-xmark"></i> Postcode not found. Please check and try again.</span>';
                    resetAddressModes();
                    return;
                }
                const lat = data.result.latitude;
                const lon = data.result.longitude;
                const miles = haversineDistance(SHOP_LAT, SHOP_LON, lat, lon);
                lastCalculatedMiles = miles;

                const MAX_DELIVERY_MILES = 6.0;
                const submitBtn = document.getElementById('placeOrderBtn');

                if (miles > MAX_DELIVERY_MILES) {
                    chargeInput.value = '0';
                    statusEl.innerHTML = '<div style="background:#fef2f2; border:1px solid #fecaca; color:#b91c1c; padding:12px 14px; border-radius:8px; margin-top:8px; font-weight:600; font-size:13px; line-height:1.4;">' +
                        '<i class="fa-solid fa-triangle-exclamation"></i> <strong>Distance Limit Exceeded (' + miles.toFixed(1) + ' miles):</strong><br>' +
                        'We cannot deliver to locations more than 6 miles from our Harrow warehouse (HA1 2SP / HA1 4EX).<br>' +
                        'Please select <strong>Warehouse Collection</strong> or contact support at <strong>+44 7497 779997</strong> for special orders.</div>';
                    if (submitBtn) submitBtn.disabled = true;
                    updateDeliveryDisplay(0);
                    return;
                } else {
                    if (submitBtn) submitBtn.disabled = false;
                }

                if (miles <= FREE_MILES) {
                    chargeInput.value = '0';
                    statusEl.innerHTML = '<span style="color:#10b981; font-weight:600;"><i class="fa-solid fa-circle-check"></i> 🎉 Free delivery! You are within ' + miles.toFixed(1) + ' miles.</span>';
                    updateDeliveryDisplay(0);
                } else {
                    chargeInput.value = DELIVERY_CHARGE.toFixed(2);
                    statusEl.innerHTML = '<span style="color:#f59e0b; font-weight:600;"><i class="fa-solid fa-truck-fast"></i> £1.99 delivery charge – ' + miles.toFixed(1) + ' miles from us.</span>';
                    updateDeliveryDisplay(DELIVERY_CHARGE);
                }

                const city = data.result.admin_district || data.result.region || '';
                switchToStructuredMode(city);
            })
            .catch(() => {
                statusEl.innerHTML = '<span style="color:#f59e0b;"><i class="fa-solid fa-triangle-exclamation"></i> Could not check postcode. Delivery charge will be calculated at checkout.</span>';
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
            infoBanner.innerHTML = '<p style="font-size:13px; color:#10b981; display:flex; align-items:center; gap:8px;"><i class="fa-solid fa-truck-fast"></i><strong>Free delivery</strong> to your postcode!</p>';
        } else {
            infoBanner.style.background = 'rgba(245,158,11,0.06)';
            infoBanner.style.borderColor = 'rgba(245,158,11,0.25)';
            infoBanner.innerHTML = '<p style="font-size:13px; color:#f59e0b; display:flex; align-items:center; gap:8px;"><i class="fa-solid fa-truck-fast"></i><strong>£1.99</strong> delivery charge applies</p>';
        }
    }
    recalculateTotals();
    triggerStripeAmountUpdate();
}

function toggleOrderType(type) {
    const isCollection = (type === 'collection');
    const delLabel = document.getElementById('type_delivery_label');
    const colLabel = document.getElementById('type_collection_label');
    
    if (isCollection) {
        delLabel.style.borderColor = 'var(--border-light)';
        delLabel.style.background = 'var(--bg-surface)';
        delLabel.querySelector('span').style.color = 'var(--text-secondary)';
        delLabel.querySelector('i').style.color = 'var(--text-secondary)';
        
        colLabel.style.borderColor = 'var(--color-primary)';
        colLabel.style.background = 'var(--color-primary-bg)';
        colLabel.querySelector('span').style.color = 'var(--text-primary)';
        colLabel.querySelector('i').style.color = 'var(--color-primary)';
        
        document.getElementById('postcode_field_group').style.display = 'none';
        document.getElementById('address_fields_container').style.display = 'none';
        document.getElementById('warehouseCollectionInfo').style.display = 'block';
        
        document.getElementById('delivery_postcode').required = false;
        document.getElementById('address').required = false;
        document.getElementById('addr_house').required = false;
        document.getElementById('addr_street').required = false;
        document.getElementById('addr_city').required = false;
        
        document.getElementById('postcodeStatus').style.display = 'none';
        updateDeliveryDisplay(0);
    } else {
        colLabel.style.borderColor = 'var(--border-light)';
        colLabel.style.background = 'var(--bg-surface)';
        colLabel.querySelector('span').style.color = 'var(--text-secondary)';
        colLabel.querySelector('i').style.color = 'var(--text-secondary)';
        
        delLabel.style.borderColor = 'var(--color-primary)';
        delLabel.style.background = 'var(--color-primary-bg)';
        delLabel.querySelector('span').style.color = 'var(--text-primary)';
        delLabel.querySelector('i').style.color = 'var(--color-primary)';
        
        document.getElementById('postcode_field_group').style.display = 'block';
        document.getElementById('address_fields_container').style.display = 'block';
        document.getElementById('warehouseCollectionInfo').style.display = 'none';
        
        document.getElementById('delivery_postcode').required = true;
        
        if (manualMode) {
            switchToManualMode();
        } else {
            const pc = document.getElementById('delivery_postcode').value.trim();
            if (pc && isValidUKPostcode(pc)) {
                document.getElementById('addr_house').required = true;
                document.getElementById('addr_street').required = true;
                document.getElementById('addr_city').required = true;
            } else {
                switchToManualMode();
            }
        }
        
        const chargeVal = parseFloat(document.getElementById('delivery_charge_input').value) || 0;
        updateDeliveryDisplay(chargeVal);
    }
}

function triggerStripeAmountUpdate() {
    if (!stripeReady) return;
    const isCollection = document.querySelector('input[name="order_type"]:checked')?.value === 'collection';
    const pc = document.getElementById('delivery_postcode')?.value || '';
    
    fetch('stripe_intent.php', {
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

</body>
</html>

