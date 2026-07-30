<?php
// ============================================================
// Creamy Bite – B2B Trade Customer Account & Order History
// URL: /trade_history.php
// ============================================================
session_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

// Auth check
if (empty($_SESSION['trade_user'])) {
    header('Location: trade_login.php');
    exit;
}

$tradeUser = $_SESSION['trade_user'];
$userId    = (int)$tradeUser['id'];
$userEmail = strtolower(trim($tradeUser['email']));
$userPhone = trim($tradeUser['phone']);

// Fetch trade orders
$stmt = $pdo->prepare("SELECT * FROM orders 
    WHERE trade_user_id = :uid 
       OR customer_email = :email 
       OR phone = :phone 
    ORDER BY created_at DESC");
$stmt->execute([
    'uid'   => $userId,
    'email' => $userEmail,
    'phone' => $userPhone,
]);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trade Order History – <?= htmlspecialchars($tradeUser['business_name']) ?></title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="responsive.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="trade-page">

<!-- Navbar -->
<header class="navbar">
    <div class="container nav-container-centered">
        <nav class="nav-left">
            <ul class="nav-links">
                <li><a href="index.php">Home</a></li>
                <li><a href="order.php">Order Menu</a></li>
                <li><a href="gallery.php">Gallery</a></li>
                <li><a href="about.php">About Us</a></li>
            </ul>
        </nav>
        <a href="index.php" class="logo logo-center">
            <img src="assets/images/logo.png" alt="<?= SHOP_NAME ?>" class="logo-img">
        </a>
        <div class="nav-actions nav-right">
            <a href="trade_history.php" class="btn-primary" style="padding:8px 16px; font-size:13px;">
                <i class="fa-solid fa-clock-rotate-left"></i> My Trade Orders
            </a>
            <a href="trade_logout.php" class="btn-secondary" style="padding:8px 14px; font-size:13px;">
                <i class="fa-solid fa-right-from-bracket"></i> Logout
            </a>
            <button class="nav-hamburger" id="navHamburger" aria-label="Open menu"><span></span><span></span><span></span></button>
        </div>
    </div>
</header>

<main style="padding:40px 0 80px; background:var(--bg-main); min-height:85vh;">
    <div class="container" style="max-width:1050px;">

        <!-- Header Banner -->
        <div style="background:linear-gradient(135deg, var(--color-primary), var(--color-primary-dark)); color:white; padding:28px; border-radius:var(--radius-lg); margin-bottom:32px; box-shadow:var(--shadow-md); display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:20px;">
            <div>
                <span style="font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:0.12em; color:var(--color-secondary); background:rgba(255,255,255,0.1); padding:4px 12px; border-radius:14px;">
                    🏪 Verified B2B Trade Partner
                </span>
                <h1 style="font-size:26px; margin:10px 0 6px; color:white;">
                    <?= htmlspecialchars($tradeUser['business_name']) ?>
                </h1>
                <p style="margin:0; font-size:14px; opacity:0.9;">
                    Contact: <strong><?= htmlspecialchars($tradeUser['contact_name']) ?></strong> | 
                    Email: <strong><?= htmlspecialchars($tradeUser['email']) ?></strong> | 
                    Phone: <strong><?= htmlspecialchars($tradeUser['phone']) ?></strong>
                </p>
                <div style="margin-top:6px; font-size:13px; opacity:0.8;">
                    📍 Store Address: <?= htmlspecialchars($tradeUser['address']) ?>, <strong><?= htmlspecialchars($tradeUser['postcode']) ?></strong>
                </div>
            </div>
            <div>
                <a href="order.php" class="btn-secondary" style="background:white; color:var(--color-primary); padding:12px 22px; font-size:14px; font-weight:700; border:none;">
                    <i class="fa-solid fa-basket-shopping"></i> Place Wholesale Order
                </a>
            </div>
        </div>

        <div class="glass-panel" style="padding:28px; border-radius:var(--radius-lg);">
            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:20px;">
                <h2 style="margin:0; font-size:20px; font-family:var(--font-heading); color:var(--text-primary); display:flex; align-items:center; gap:10px;">
                    <span>📋</span> Trade Order History & Delivery Notes
                </h2>
                <span style="font-size:13px; color:var(--text-muted); font-weight:600;">
                    Total Orders: <?= count($orders) ?>
                </span>
            </div>

            <?php if (empty($orders)): ?>
            <div style="text-align:center; padding:60px 20px; color:var(--text-muted); background:var(--bg-main); border-radius:var(--radius-md); border:1px dashed var(--border-color);">
                <div style="font-size:54px; margin-bottom:16px; opacity:0.4;">📦</div>
                <h3 style="font-size:18px; margin:0 0 6px; color:var(--text-primary);">No trade orders placed yet</h3>
                <p style="font-size:14px; color:var(--text-secondary); max-width:400px; margin:0 auto 20px;">
                    Explore our authentic handcrafted ice cream menu and place your first wholesale order today!
                </p>
                <a href="order.php" class="btn-primary" style="padding:12px 24px; font-size:14px;">Browse Wholesale Menu</a>
            </div>
            <?php else: ?>
            <div class="table-wrapper">
                <table class="data-table" style="width:100%;">
                    <thead>
                        <tr>
                            <th>Order Code</th>
                            <th>Date & Time</th>
                            <th>Items Summary</th>
                            <th>Total (£)</th>
                            <th>Order Status</th>
                            <th>Payment Status</th>
                            <th style="text-align:right;">Delivery Note</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $o):
                            $items = json_decode($o['items_json'], true) ?? [];
                            $totalQty = array_sum(array_column($items, 'quantity'));
                            $firstItem = $items[0] ?? null;
                            $st = $o['status'];
                            $ps = $o['payment_status'] ?? 'Unpaid';
                        ?>
                        <tr style="border-bottom:1px solid var(--border-light);">
                            <td style="font-weight:800; font-family:monospace; color:var(--color-primary); font-size:14px;">
                                <?= htmlspecialchars($o['order_code']) ?>
                            </td>
                            <td style="font-size:13px; color:var(--text-secondary); white-space:nowrap;">
                                <?= date('d M Y', strtotime($o['created_at'])) ?>
                                <div style="font-size:11px; color:var(--text-muted);"><?= date('H:i', strtotime($o['created_at'])) ?></div>
                            </td>
                            <td style="font-size:13px;">
                                <?php if ($firstItem): ?>
                                <strong><?= htmlspecialchars($firstItem['emoji'] ?? '🍦') ?> <?= htmlspecialchars($firstItem['name']) ?></strong> ×<?= (int)$firstItem['quantity'] ?>
                                <?php if (count($items) > 1): ?>
                                <span style="font-size:11px; color:var(--text-muted); font-weight:600;">(+<?= count($items) - 1 ?> more)</span>
                                <?php endif; ?>
                                <div style="font-size:11px; color:var(--text-muted); margin-top:2px;">Total Tubs: <?= $totalQty ?></div>
                                <?php endif; ?>
                            </td>
                            <td style="font-weight:800; font-family:var(--font-heading); color:var(--color-primary); font-size:15px;">
                                £<?= number_format((float)$o['total_price'], 2) ?>
                            </td>
                            <td>
                                <?php if ($st === 'Delivered'): ?>
                                <span style="background:#ecfdf5; color:#047857; font-size:12px; font-weight:700; padding:4px 10px; border-radius:20px; border:1px solid #a7f3d0; display:inline-flex; align-items:center; gap:5px;">
                                    <i class="fa-solid fa-circle-check"></i> Delivered
                                </span>
                                <?php elseif ($st === 'Processing'): ?>
                                <span style="background:#eff6ff; color:#1d4ed8; font-size:12px; font-weight:700; padding:4px 10px; border-radius:20px; border:1px solid #bfdbfe; display:inline-flex; align-items:center; gap:5px;">
                                    <i class="fa-solid fa-spinner fa-spin"></i> Processing
                                </span>
                                <?php elseif ($st === 'Cancelled'): ?>
                                <span style="background:#fef2f2; color:#b91c1c; font-size:12px; font-weight:700; padding:4px 10px; border-radius:20px; border:1px solid #fecaca; display:inline-flex; align-items:center; gap:5px;">
                                    <i class="fa-solid fa-ban"></i> Cancelled
                                </span>
                                <?php else: ?>
                                <span style="background:#fffbeb; color:#b45309; font-size:12px; font-weight:700; padding:4px 10px; border-radius:20px; border:1px solid #fde68a; display:inline-flex; align-items:center; gap:5px;">
                                    <i class="fa-solid fa-clock"></i> Pending
                                </span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($ps === 'Paid'): ?>
                                <span style="color:#047857; font-weight:700; font-size:12px; display:inline-flex; align-items:center; gap:4px;">
                                    <i class="fa-solid fa-circle-check"></i> Paid Online
                                </span>
                                <?php elseif ($ps === 'Cash'): ?>
                                <span style="color:#b45309; font-weight:700; font-size:12px; display:inline-flex; align-items:center; gap:4px;">
                                    <i class="fa-solid fa-money-bill-wave"></i> Cash Paid
                                </span>
                                <?php else: ?>
                                <span style="color:#6b7280; font-weight:600; font-size:12px; display:inline-flex; align-items:center; gap:4px;">
                                    <i class="fa-solid fa-clock"></i> Invoice Unpaid
                                </span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align:right;">
                                <a href="admin/delivery_note.php?code=<?= urlencode($o['order_code']) ?>" target="_blank" class="btn-sm btn-sm-outline" style="padding:6px 14px; font-size:12px; gap:6px; display:inline-flex; align-items:center;">
                                    <i class="fa-solid fa-print"></i> Delivery Note
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

    </div>
</main>

<footer class="footer-enhanced">
    <div class="container" style="text-align:center; padding:20px 0; font-size:13px; color:var(--text-muted);">
        &copy; <?= date('Y') ?> CreamyBite.com — B2B Wholesale Portal
    </div>
</footer>

</body>
</html>
