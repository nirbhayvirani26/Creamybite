<?php
// ============================================================
//  Sweet Scoops – Order Confirmation Page
// ============================================================
session_start();
require_once 'config.php';
require_once 'db.php';

$code = trim($_GET['code'] ?? '');
if (empty($code)) { header('Location: order.php'); exit; }

// Fetch order from DB
$stmt = $pdo->prepare("SELECT * FROM orders WHERE order_code = :code LIMIT 1");
$stmt->execute(['code' => $code]);
$order = $stmt->fetch();
if (!$order) { header('Location: order.php'); exit; }

$items = json_decode($order['items_json'], true) ?? [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Order Confirmed! – <?= SHOP_NAME ?></title>
    <meta name="description" content="Your ice cream order has been confirmed at <?= SHOP_NAME ?>.">
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="responsive.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>

<header class="navbar">
    <div class="container nav-container">
        <a href="index.php" class="logo"><img src="assets/images/logo.png" alt="<?= SHOP_NAME ?>" class="logo-img"></a>
        <nav><ul class="nav-links">
            <li><a href="index.php">Home</a></li>
            <li><a href="order.php">Order</a></li>
            <li><a href="gallery.php">Gallery</a></li>
            <li><a href="about.php">About Us</a></li>
        </ul></nav>
    </div>
</header>

<main class="confirmation-page">
    <div class="container">
        <div class="confirmation-icon">🎉</div>
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
        <div class="glass-panel" style="max-width: 540px; margin: 0 auto 32px; padding: 24px; border: 2px solid var(--color-accent); background: var(--color-accent-bg); border-radius: 16px; text-align: center; box-shadow: var(--shadow-sm);">
            <div style="font-size: 36px; margin-bottom: 12px;">🏪</div>
            <h3 style="font-size: 20px; font-weight: 800; font-family: var(--font-heading); color: var(--text-primary); margin: 0 0 10px;">Collection Information</h3>
            <p style="font-size: 15px; color: var(--text-secondary); line-height: 1.6; margin: 0 0 18px; font-family: var(--font-body);">
                <strong>Creamy Bite Warehouse</strong><br>
                Unit E5 Phoenix Business Centre<br>
                HA1 2SP<br><br>
                <span style="font-weight: 700; color: var(--text-primary);"><i class="fa-solid fa-clock"></i> Collection Hours: 11 AM – 8 PM</span>
            </p>
            <a href="https://maps.app.goo.gl/hrMSnTRqFvorzF7HA?g_st=iw" target="_blank" rel="noopener" class="btn-primary" style="background: var(--grad-golden); border: none; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; padding: 12px 28px; border-radius: 30px; font-weight: 700; color: #fff; box-shadow: var(--shadow-xs); transition: all 0.2s ease;">
                <i class="fa-solid fa-map-location-dot"></i> Get Directions on Google Maps
            </a>
        </div>
        <?php endif; ?>

        <!-- Order Detail Card -->
        <div class="glass-panel confirmation-details">

            <div class="conf-row">
                <span class="conf-label">📋 Status</span>
                <span class="conf-value">
                    <span class="status-badge status-pending"><?= htmlspecialchars($order['status']) ?></span>
                </span>
            </div>
            <div class="conf-row">
                <span class="conf-label">📞 Phone</span>
                <span class="conf-value"><?= htmlspecialchars($order['phone']) ?></span>
            </div>
            <div class="conf-row">
                <span class="conf-label"><?= $isCollection ? '📍 Collection Address' : '📍 Address' ?></span>
                <span class="conf-value"><?= nl2br(htmlspecialchars($order['address'])) ?></span>
            </div>
            <?php if (!empty($order['notes'])): ?>
            <div class="conf-row">
                <span class="conf-label">📝 Notes</span>
                <span class="conf-value"><?= nl2br(htmlspecialchars($order['notes'])) ?></span>
            </div>
            <?php endif; ?>

            <hr class="summary-divider" style="margin:20px 0;">

            <div style="margin-bottom:12px; font-size:13px; font-weight:700; text-transform:uppercase; letter-spacing:0.08em; color:var(--text-muted);">Items Ordered</div>

            <?php foreach ($items as $item): ?>
            <div class="conf-row">
                <span class="conf-label"><?= htmlspecialchars($item['emoji']) ?> × <?= (int)$item['quantity'] ?></span>
                <span class="conf-value">
                    <?= htmlspecialchars($item['name']) ?>
                    <span style="color:var(--color-primary); margin-left:8px; font-weight:700;">
                        £<?= number_format($item['price'] * $item['quantity'], 2) ?>
                    </span>
                </span>
            </div>
            <?php endforeach; ?>

            <hr class="summary-divider" style="margin:20px 0;">

            <?php
            $subtotal = 0.0;
            foreach ($items as $item) {
                $subtotal += $item['price'] * $item['quantity'];
            }
            ?>
            <div class="conf-row" style="font-size: 13px; color: var(--text-secondary); margin-bottom: 6px;">
                <span class="conf-label">Subtotal</span>
                <span class="conf-value">£<?= number_format($subtotal, 2) ?></span>
            </div>
            <?php if (!empty($order['promo_code']) && $order['discount_amount'] > 0): ?>
            <div class="conf-row" style="font-size: 13px; color: #10b981; margin-bottom: 6px;">
                <span class="conf-label">🎟️ Promo (<?= htmlspecialchars($order['promo_code']) ?>)</span>
                <span class="conf-value">−£<?= number_format($order['discount_amount'], 2) ?></span>
            </div>
            <?php endif; ?>
            <?php if ((float)$order['delivery_charge'] > 0): ?>
            <div class="conf-row" style="font-size: 13px; color: #f59e0b; margin-bottom: 6px;">
                <span class="conf-label">🚚 Delivery Charge</span>
                <span class="conf-value">+£<?= number_format($order['delivery_charge'], 2) ?></span>
            </div>
            <?php endif; ?>

            <div class="conf-row" style="margin-top: 10px;">
                <span class="conf-label" style="font-weight:700; color:var(--text-primary);">💰 Total</span>
                <span class="conf-value" style="font-size:22px; font-weight:800; font-family:var(--font-heading); color:var(--color-primary);">
                    £<?= number_format($order['total_price'], 2) ?>
                </span>
            </div>
        </div>

        <!-- CTA Buttons -->
        <div style="display:flex; gap:16px; justify-content:center; flex-wrap:wrap; margin-top:8px;">
            <a href="order.php" class="btn-primary">
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
        <a href="index.php"><img src="assets/images/logo.png" alt="<?= SHOP_NAME ?>" class="footer-logo-img" style="height:34px;"></a>
        <span class="footer-copy">© <?= date('Y') ?> <?= SHOP_NAME ?>. Thank you for your order!</span>
    </div>
</footer>

</body>
</html>
