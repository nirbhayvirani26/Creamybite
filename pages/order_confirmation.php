<?php
// ============================================================
//  Creamy Bite – Order Confirmation Page
// ============================================================
require_once __DIR__ . '/../includes/session.php';
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/product_icons.php';
require_once __DIR__ . '/../includes/db.php';

$code = trim($_GET['code'] ?? '');
if (empty($code)) { header('Location: ' . cbUrl('order')); exit; }

// Fetch order from DB
$stmt = $pdo->prepare("SELECT * FROM orders WHERE order_code = :code LIMIT 1");
$stmt->execute(['code' => $code]);
$order = $stmt->fetch();
if (!$order) { header('Location: ' . cbUrl('order')); exit; }

// ── Who is allowed to see this order? ────────────────────────
// Order codes are 'CB-' plus six digits — about 900,000 values, which is
// trivially enumerable. Without this check anyone could walk the range and
// read customers' names, addresses, phone numbers and order contents.
// You may view an order if you just placed it, if it belongs to your trade
// account, or if you are the admin.
$justPlaced = in_array($code, $_SESSION['own_order_codes'] ?? [], true);
$ownTrade   = !empty($_SESSION['trade_user'])
              && (int)$order['trade_user_id'] === (int)($_SESSION['trade_user']['id'] ?? 0)
              && (int)$order['trade_user_id'] > 0;
$isAdmin    = !empty($_SESSION['admin_logged_in']);

if (!$justPlaced && !$ownTrade && !$isAdmin) {
    http_response_code(403);
    header('Location: ' . cbUrl('order') . '?not_your_order=1');
    exit;
}

$items = json_decode($order['items_json'], true) ?? [];

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Confirmed! – <?= SHOP_NAME ?></title>
    <meta name="description" content="Your ice cream order has been confirmed at <?= SHOP_NAME ?>.">
    <?php // Private, per-order page: the URL carries a code only the buyer knows,
          // so it must never be indexed or followed by search engines. ?>
    <meta name="robots" content="noindex, nofollow">
    <?php require __DIR__ . '/../includes/favicon.php'; ?>
    <link rel="stylesheet" href="<?= cbAsset('../assets/css/style.css') ?>">
    <link rel="stylesheet" href="<?= cbAsset('../assets/css/responsive.css') ?>">
    <link rel="stylesheet" href="<?= cbAsset('../assets/css/animations.css') ?>">
    <link rel="stylesheet" href="<?= cbAsset('../assets/css/components.css') ?>">
    <link rel="stylesheet" href="<?= cbAsset('../assets/css/modal.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>

<header class="navbar">
    <div class="container nav-container">
        <a href="<?= cbUrl() ?>" class="logo"><img src="<?= cbUrl('assets/images/logo.png') ?>" alt="<?= SHOP_NAME ?>" class="logo-img"></a>
        <nav><ul class="nav-links">
            <li><a href="<?= cbUrl() ?>">Home</a></li>
            <li><a href="<?= cbUrl('order') ?>">Order</a></li>
            <li><a href="<?= cbUrl('gallery') ?>">Gallery</a></li>
            <li><a href="<?= cbUrl('about') ?>">About Us</a></li>
        </ul></nav>
    </div>
</header>

<main class="confirmation-page">
    <div class="container">
        <div class="confirmation-icon cb-scoop-pop"><i class="fa-solid fa-circle-check"></i></div>
        <h1 class="confirmation-title">Order <span class="gradient-text-warm">Placed!</span></h1>
        <?php
        $isCollection = (strpos($order['address'], 'Collection') !== false);
        $confSubtitle = $isCollection
            ? "Your delicious ice cream is being prepared. You can collect it from our warehouse during collection hours."
            : "Your delicious ice cream is being prepared. We'll call you to confirm delivery.";
        ?>
        <p class="confirmation-subtitle">
            Thank you <strong><?= htmlspecialchars($order['customer_name']) ?></strong>!<br>
            <?= $confSubtitle ?>
        </p>

        <!-- Order Code -->
        <div class="order-code-box">
            <div class="order-code-label">Your Order Code</div>
            <div class="order-code-value"><?= htmlspecialchars($order['order_code']) ?></div>
        </div>

        <?php if ($isCollection): ?>
        <div class="glass-panel cboc-collect-panel">
            <div class="cboc-collect-emoji"><i class="fa-solid fa-store"></i></div>
            <h3 class="cboc-collect-title">Collection Information</h3>
            <p class="cboc-collect-address">
                <strong>Creamy Bite Warehouse</strong><br>
                Unit E5 Phoenix Business Centre<br>
                HA1 2SP<br><br>
                <span class="cboc-text-strong"><i class="fa-solid fa-clock"></i> Collection Hours: 11 AM – 8 PM</span>
            </p>
            <a href="https://maps.app.goo.gl/hrMSnTRqFvorzF7HA?g_st=iw" target="_blank" rel="noopener" class="btn-primary cboc-directions-btn">
                <i class="fa-solid fa-map-location-dot"></i> Get Directions on Google Maps
            </a>
        </div>
        <?php endif; ?>

        <!-- Order Detail Card -->
        <div class="glass-panel confirmation-details">

            <div class="conf-row">
                <span class="conf-label"><i class="fa-solid fa-clipboard-list"></i> Status</span>
                <span class="conf-value">
                    <span class="status-badge status-pending"><?= htmlspecialchars($order['status']) ?></span>
                </span>
            </div>
            <div class="conf-row">
                <span class="conf-label"><i class="fa-solid fa-phone"></i> Phone</span>
                <span class="conf-value"><?= htmlspecialchars($order['phone']) ?></span>
            </div>
            <div class="conf-row">
                <span class="conf-label"><?= $isCollection ? '<i class="fa-solid fa-location-dot"></i> Collection Address' : '<i class="fa-solid fa-location-dot"></i> Address' ?></span>
                <span class="conf-value"><?= nl2br(htmlspecialchars($order['address'])) ?></span>
            </div>
            <?php if (!empty($order['notes'])): ?>
            <div class="conf-row">
                <span class="conf-label"><i class="fa-solid fa-note-sticky"></i> Notes</span>
                <span class="conf-value"><?= nl2br(htmlspecialchars($order['notes'])) ?></span>
            </div>
            <?php endif; ?>

            <hr class="summary-divider cboc-divider-tight">

            <div class="cboc-items-heading">Items Ordered</div>

            <?php foreach ($items as $item): ?>
            <div class="conf-row">
                <span class="conf-label"><?= cbProductIcon($item['emoji'] ?? null) ?> × <?= (int)$item['quantity'] ?></span>
                <span class="conf-value">
                    <?= htmlspecialchars($item['name']) ?><?php if (!empty($item['variant_name'])): ?><span class="cboc-item-variant"> <?= htmlspecialchars($item['variant_name']) ?></span><?php endif; ?>
                    <span class="cboc-item-price">
                        £<?= number_format($item['price'] * $item['quantity'], 2) ?>
                    </span>
                </span>
            </div>
            <?php endforeach; ?>

            <hr class="summary-divider cboc-divider-tight">

            <?php
            $subtotal = 0.0;
            foreach ($items as $item) {
                $subtotal += $item['price'] * $item['quantity'];
            }
            ?>
            <div class="conf-row cboc-summary-row">
                <span class="conf-label">Subtotal</span>
                <span class="conf-value">£<?= number_format($subtotal, 2) ?></span>
            </div>
            <?php if (!empty($order['promo_code']) && $order['discount_amount'] > 0): ?>
            <div class="conf-row cboc-summary-row cboc-summary-row-promo">
                <span class="conf-label"><i class="fa-solid fa-ticket"></i> Promo (<?= htmlspecialchars($order['promo_code']) ?>)</span>
                <span class="conf-value">−£<?= number_format($order['discount_amount'], 2) ?></span>
            </div>
            <?php endif; ?>
            <?php if ((float)$order['delivery_charge'] > 0): ?>
            <div class="conf-row cboc-summary-row cboc-summary-row-delivery">
                <span class="conf-label"><i class="fa-solid fa-truck-fast"></i> Delivery Charge</span>
                <span class="conf-value">+£<?= number_format($order['delivery_charge'], 2) ?></span>
            </div>
            <?php endif; ?>

            <div class="conf-row cboc-total-row">
                <span class="conf-label cboc-text-strong"><i class="fa-solid fa-sterling-sign"></i> Total</span>
                <span class="conf-value cboc-total-value">
                    £<?= number_format($order['total_price'], 2) ?>
                </span>
            </div>
        </div>

        <!-- CTA Buttons -->
        <div class="cboc-cta-row">
            <a href="<?= cbUrl('order') ?>" class="btn-primary">
                <i class="fa-solid fa-ice-cream"></i> Order More Scoops
            </a>
            <button onclick="window.print()" class="btn-secondary">
                <i class="fa-solid fa-print"></i> Print Receipt
            </button>
        </div>
    </div>
</main>

<footer class="footer">
    <div class="container footer-inner">
        <a href="<?= cbUrl() ?>"><img src="<?= cbUrl('assets/images/logo.png') ?>" alt="<?= SHOP_NAME ?>" class="footer-logo-img cboc-footer-logo-sm"></a>
        <span class="footer-copy">© <?= date('Y') ?> <?= SHOP_NAME ?>. Thank you for your order!</span>
    </div>
</footer>
<script src="<?= cbAsset('../assets/js/modal.js') ?>" defer></script>
<script src="<?= cbAsset('../assets/js/animations.js') ?>" defer></script>

</body>
</html>
