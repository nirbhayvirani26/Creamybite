<?php
// ============================================================
//  Creamy Bite – Admin Dashboard
//  Tabs: Orders | Products | Gallery | Categories
// ============================================================
session_start();
if (empty($_SESSION['admin_logged_in'])) {
    header('Location: login.php'); exit;
}

require_once '../config.php';
require_once '../db.php';

$successMsg = '';
$errorMsg   = '';

// ── Handle product delete ─────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'delete_product' && isset($_GET['id'])) {
    $delId = (int)$_GET['id'];
    try {
        $imgRow = $pdo->prepare("SELECT image FROM products WHERE id = :id");
        $imgRow->execute(['id' => $delId]);
        $imgData = $imgRow->fetch();
        if ($imgData && !empty($imgData['image'])) {
            $imgPath = __DIR__ . '/../assets/images/products/' . $imgData['image'];
            if (file_exists($imgPath)) { unlink($imgPath); }
        }
        $pdo->prepare("DELETE FROM products WHERE id = :id")->execute(['id' => $delId]);
        $successMsg = 'Product deleted successfully.';
    } catch (PDOException $e) {
        $errorMsg = 'Could not delete product: ' . $e->getMessage();
    }
}

// ── Handle Trade Account Status Update ────────────────────
if (isset($_GET['action']) && in_array($_GET['action'], ['approve_trade', 'reject_trade']) && isset($_GET['id'])) {
    $tradeId   = (int)$_GET['id'];
    $newStatus = $_GET['action'] === 'approve_trade' ? 'approved' : 'rejected';
    try {
        $stmt = $pdo->prepare("UPDATE trade_users SET status = :status WHERE id = :id");
        $stmt->execute(['status' => $newStatus, 'id' => $tradeId]);
        $successMsg = "✅ Trade account #$tradeId status updated to " . strtoupper($newStatus) . "!";
    } catch (PDOException $e) {
        $errorMsg = "Could not update trade account: " . $e->getMessage();
    }
}

// ── URL success messages ────────────────────────────────
if (isset($_GET['order_deleted'])) $successMsg = '✅ Order deleted successfully.';

// ── URL success messages ──────────────────────────────────
if (isset($_GET['product_added']))   $successMsg = '✅ New product added successfully!';
if (isset($_GET['product_updated'])) $successMsg = '✅ Product updated successfully!';

// ── Load stats ────────────────────────────────────────────
$totalOrders   = $pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
$pendingOrders = $pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'Pending'")->fetchColumn();
$totalRevenue  = $pdo->query("SELECT COALESCE(SUM(total_price), 0) FROM orders WHERE payment_status IN ('Paid', 'Cash')")->fetchColumn();
$totalProducts = $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();

// ── Active tab ────────────────────────────────────────────
$activeTab = $_GET['tab'] ?? 'orders';
$validTabs = ['orders','products','gallery','categories','promos','stock','revenue','inquiries','trade'];
if (!in_array($activeTab, $validTabs)) $activeTab = 'orders';

// ── Load Trade Accounts ───────────────────────────────────
$tradeUsers        = [];
$pendingTradeCount = 0;
try {
    $tradeUsers = $pdo->query("SELECT * FROM trade_users ORDER BY created_at DESC")->fetchAll();
    foreach ($tradeUsers as $tu) {
        if ($tu['status'] === 'pending') $pendingTradeCount++;
    }
} catch (PDOException $e) {}

// Repeat customer detection
$repeatPhones = [];
try {
    $repeatPhones = $pdo->query("SELECT phone FROM orders GROUP BY phone HAVING COUNT(*) > 1")->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) {}
$repeatPhoneSet = array_flip($repeatPhones);
$repeatCustomerCount = count($repeatPhones);

// ── Load orders ───────────────────────────────────────────
$orders = $pdo->query("SELECT * FROM orders ORDER BY created_at DESC")->fetchAll();

// ── Load stock data (for Stock tab) ───────────────────────
$stockProducts      = [];
$stockMigrationDone = false;
$stockV2Done        = false; // true when total_stock + sold_online also exist
try {
    // Try full v2 schema
    $stockProducts = $pdo->query(
        "SELECT id, name, emoji, image, category,
                IFNULL(track_stock,  0) AS track_stock,
                IFNULL(total_stock,  0) AS total_stock,
                IFNULL(stock_qty,    0) AS stock_qty,
                IFNULL(damage_stock, 0) AS damage_stock,
                IFNULL(sold_offline, 0) AS sold_offline,
                IFNULL(sold_online,  0) AS sold_online
         FROM products ORDER BY name ASC"
    )->fetchAll();
    $stockMigrationDone = true;
    $stockV2Done        = true;
} catch (PDOException $e) {
    // v2 columns missing — try v1 (damage + offline only)
    try {
        $stockProducts = $pdo->query(
            "SELECT id, name, emoji, image, category,
                    IFNULL(track_stock,  0) AS track_stock,
                    IFNULL(stock_qty,    0) AS total_stock,
                    IFNULL(stock_qty,    0) AS stock_qty,
                    IFNULL(damage_stock, 0) AS damage_stock,
                    IFNULL(sold_offline, 0) AS sold_offline,
                    0 AS sold_online
             FROM products ORDER BY name ASC"
        )->fetchAll();
        $stockMigrationDone = true;
    } catch (PDOException $e2) {
        // No stock columns at all — basic fallback
        try {
            $stockProducts = $pdo->query(
                "SELECT id, name, emoji, image, category,
                        0 AS track_stock, 0 AS total_stock, 0 AS stock_qty,
                        0 AS damage_stock, 0 AS sold_offline, 0 AS sold_online
                 FROM products ORDER BY name ASC"
            )->fetchAll();
        } catch (PDOException $e3) { $stockProducts = []; }
    }
}


// ── Load products ─────────────────────────────────────────
$products = $pdo->query("SELECT * FROM products ORDER BY id DESC")->fetchAll();

// ── Load gallery ──────────────────────────────────────────
$galleryItems = [];
try { $galleryItems = $pdo->query("SELECT * FROM gallery ORDER BY sort_order ASC, created_at DESC")->fetchAll(); } catch (PDOException $e) {}

// ── Load categories ───────────────────────────────────────
$catList = [];
try { $catList = $pdo->query("SELECT * FROM categories ORDER BY sort_order ASC, name ASC")->fetchAll(); } catch (PDOException $e) {}

// ── Load promo codes ───────────────────────────────
$promoCodes = [];
try { $promoCodes = $pdo->query("SELECT * FROM promo_codes ORDER BY created_at DESC")->fetchAll(); } catch (PDOException $e) {}

// ── Active tab ────────────────────────────────────────────
$activeTab = $_GET['tab'] ?? 'orders';
$validTabs = ['orders','products','gallery','categories','promos','stock','revenue','inquiries','trade'];
if (!in_array($activeTab, $validTabs)) $activeTab = 'orders';

// ── Load inquiries ──────────────────────────────────────
$inquiries        = [];
$unreadInquiries  = 0;
try {
    // Auto-create table if it doesn't exist
    $pdo->exec("CREATE TABLE IF NOT EXISTS `inquiries` (
        `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `name`       VARCHAR(120) NOT NULL,
        `email`      VARCHAR(180) NOT NULL,
        `phone`      VARCHAR(30)  NOT NULL DEFAULT '',
        `message`    TEXT         NOT NULL,
        `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `is_read`    TINYINT(1)   NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Mark as read if viewing the tab
    if ($activeTab === 'inquiries') {
        $pdo->exec("UPDATE inquiries SET is_read = 1 WHERE is_read = 0");
    }
    $inquiries       = $pdo->query("SELECT * FROM inquiries ORDER BY created_at DESC")->fetchAll();
    $unreadInquiries = (int)$pdo->query("SELECT COUNT(*) FROM inquiries WHERE is_read = 0")->fetchColumn();
} catch (PDOException $e) {}

// ── Revenue tab data ──────────────────────────────────
$revData = [];
if ($activeTab === 'revenue') {
    $revFrom = $_GET['rev_from'] ?? date('Y-m-01');
    $revTo   = $_GET['rev_to']   ?? date('Y-m-d');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $revFrom)) $revFrom = date('Y-m-01');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $revTo))   $revTo   = date('Y-m-d');

    // Summary by payment status
    try {
        $rstmt = $pdo->prepare("SELECT payment_status, SUM(total_price) AS total, COUNT(*) AS cnt FROM orders WHERE DATE(created_at) BETWEEN :f AND :t GROUP BY payment_status");
        $rstmt->execute(['f' => $revFrom, 't' => $revTo]);
        $byStatus = [];
        while ($r = $rstmt->fetch()) $byStatus[$r['payment_status']] = $r;
        $revData['online']       = (float)($byStatus['Paid']['total']  ?? 0);
        $revData['cash']         = (float)($byStatus['Cash']['total']  ?? 0);
        $revData['total']        = $revData['online'] + $revData['cash'];
        $revData['unpaid_total'] = (float)($byStatus['Unpaid']['total'] ?? 0);
        $revData['unpaid_count'] = (int)($byStatus['Unpaid']['cnt']    ?? 0);
    } catch (PDOException $e) { $revData['total'] = 0; $revData['online'] = 0; $revData['cash'] = 0; $revData['unpaid_total'] = 0; $revData['unpaid_count'] = 0; }

    // Product map for categories
    $revProductMap = [];
    try {
        $rows = $pdo->query("SELECT id, name, category FROM products")->fetchAll();
        foreach ($rows as $row) $revProductMap[$row['id']] = $row;
    } catch (PDOException $e) {}

    // Category breakdown + product sold qty (all-time & this month)
    $catRevenue      = [];
    $productAllTime  = [];
    $productThisMonth = [];
    $thisYear  = (int)date('Y');
    $thisMonth = (int)date('n');

    try {
        // For category table: filter by date range (paid only)
        $oStmt = $pdo->prepare("SELECT items_json, payment_status, created_at FROM orders WHERE DATE(created_at) BETWEEN :f AND :t");
        $oStmt->execute(['f' => $revFrom, 't' => $revTo]);
        $revOrders = $oStmt->fetchAll();
    } catch (PDOException $e) { $revOrders = []; }

    foreach ($revOrders as $o) {
        $isPaid = in_array($o['payment_status'], ['Paid', 'Cash']);
        $items  = json_decode($o['items_json'], true) ?? [];
        foreach ($items as $it) {
            $pid  = $it['product_id'] ?? 0;
            $cat  = $revProductMap[$pid]['category'] ?? ($it['category'] ?? 'Other');
            $qty  = (int)$it['quantity'];
            $line = (float)$it['price'] * $qty;
            if ($isPaid) {
                if (!isset($catRevenue[$cat])) $catRevenue[$cat] = ['revenue' => 0, 'qty' => 0];
                $catRevenue[$cat]['revenue'] += $line;
                $catRevenue[$cat]['qty']     += $qty;
            }
        }
    }
    arsort($catRevenue);

    // Product charts: all-time paid orders
    try {
        $allStmt = $pdo->query("SELECT items_json, created_at FROM orders WHERE payment_status IN ('Paid','Cash')");
        while ($o = $allStmt->fetch()) {
            $dt    = new DateTime($o['created_at']);
            $items = json_decode($o['items_json'], true) ?? [];
            $isThisMonth = ((int)$dt->format('Y') === $thisYear && (int)$dt->format('n') === $thisMonth);
            foreach ($items as $it) {
                $nm  = $it['name'];
                $qty = (int)$it['quantity'];
                $productAllTime[$nm]  = ($productAllTime[$nm]  ?? 0) + $qty;
                if ($isThisMonth) $productThisMonth[$nm] = ($productThisMonth[$nm] ?? 0) + $qty;
            }
        }
    } catch (PDOException $e) {}
    arsort($productAllTime);
    arsort($productThisMonth);
    $revData['chart_alltime']   = array_slice($productAllTime,   0, 10, true);
    $revData['chart_thismonth'] = array_slice($productThisMonth, 0, 10, true);
    $revData['cat_revenue']     = $catRevenue;
    $revData['from']            = $revFrom;
    $revData['to']              = $revTo;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard – <?= SHOP_NAME ?></title>
    <link rel="stylesheet" href="../style.css">
    <link rel="stylesheet" href="../responsive.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .expand-btn { cursor: pointer; background: none; border: none; color: var(--color-secondary); font-size: 13px; display: flex; align-items: center; gap: 4px; }
        .expand-btn:hover { color: var(--color-primary); }
    </style>
</head>
<body class="admin-wrapper">

<!-- ══ Admin Navbar ════════════════════════════════════════ -->
<header class="navbar">
    <div class="container nav-container">
        <a href="../index.php" class="logo">
            <img src="../assets/images/logo.png" alt="<?= SHOP_NAME ?>" class="logo-img" style="max-height:46px; width:auto;">
        </a>
        <nav>
            <ul class="nav-links">
                <li><a href="index.php?tab=orders"     class="<?= $activeTab==='orders'     ? 'active' : '' ?>"><i class="fa-solid fa-clipboard-list"></i> Orders</a></li>
                <li><a href="index.php?tab=products"   class="<?= $activeTab==='products'   ? 'active' : '' ?>"><i class="fa-solid fa-ice-cream"></i> Products</a></li>
                <li><a href="index.php?tab=stock"      class="<?= $activeTab==='stock'      ? 'active' : '' ?>"><i class="fa-solid fa-boxes-stacked"></i> Stock</a></li>
                <li><a href="index.php?tab=gallery"    class="<?= $activeTab==='gallery'    ? 'active' : '' ?>"><i class="fa-solid fa-images"></i> Gallery</a></li>
                <li><a href="index.php?tab=categories" class="<?= $activeTab==='categories' ? 'active' : '' ?>"><i class="fa-solid fa-tags"></i> Categories</a></li>
                <li><a href="index.php?tab=promos"     class="<?= $activeTab==='promos'     ? 'active' : '' ?>"><i class="fa-solid fa-ticket"></i> Promos</a></li>
                <li><a href="../index.php" target="_blank"><i class="fa-solid fa-globe"></i> View Shop</a></li>
            </ul>
        </nav>
        <div class="nav-actions">
            <span style="font-size:13px; color:var(--text-muted);">
                <i class="fa-solid fa-user-shield" style="color:var(--color-primary);"></i>
                <?= htmlspecialchars(ADMIN_USERNAME) ?>
            </span>
            <a href="logout.php" class="btn-secondary" style="padding:9px 16px; font-size:13px;">
                <i class="fa-solid fa-right-from-bracket"></i> Logout
            </a>
        </div>
    </div>
</header>

<main class="admin-content">
    <div class="container">

        <!-- Alerts -->
        <?php if ($successMsg): ?>
        <div class="alert alert-success" style="margin-bottom:24px;">
            <i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($successMsg) ?>
        </div>
        <?php endif; ?>
        <?php if ($errorMsg): ?>
        <div class="alert alert-danger" style="margin-bottom:24px;">
            <i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($errorMsg) ?>
        </div>
        <?php endif; ?>

        <!-- Page Header -->
        <div class="admin-page-header">
            <div>
                <h1 class="admin-page-title">
                    <?php
                    echo match($activeTab) {
                        'orders'     => '📋 Orders',
                        'products'   => '🍦 Products',
                        'stock'      => '📦 Stock Management',
                        'trade'      => '🏪 Trade Accounts (B2B)',
                        'gallery'    => '🖼️ Gallery',
                        'categories' => '🏷️ Categories',
                        'revenue'    => '💷 Revenue & Reports',
                        default      => '📋 Orders',
                    };
                    ?>
                </h1>
                <p class="admin-page-subtitle">
                    <?php
                    echo match($activeTab) {
                        'orders'     => 'View and manage all customer orders',
                        'products'   => 'Add, edit, or remove menu items & wholesale prices',
                        'stock'      => 'Track in-stock, damage, and offline sold quantities per product',
                        'trade'      => 'Approve and manage retailer trade applications & wholesale access',
                        'gallery'    => 'Upload and manage gallery photos',
                        'categories' => 'Add or remove product categories',
                        'revenue'    => 'Sales reports, payment breakdowns and product charts',
                        default      => '',
                    };
                    ?>
                </p>
            </div>
            <?php if ($activeTab === 'products'): ?>
            <a href="product_form.php" class="btn-primary">
                <i class="fa-solid fa-plus"></i> Add Product
            </a>
            <?php endif; ?>
        </div>

        <!-- Stats Cards -->
        <div class="stats-grid" style="margin-bottom:32px;">
            <div class="stat-card glass-panel">
                <div class="stat-card-icon">📋</div>
                <div class="stat-label">Total Orders</div>
                <div class="stat-value"><?= $totalOrders ?></div>
            </div>
            <div class="stat-card glass-panel">
                <div class="stat-card-icon">⏳</div>
                <div class="stat-label">Pending</div>
                <div class="stat-value"><?= $pendingOrders ?></div>
            </div>
            <div class="stat-card glass-panel">
                <div class="stat-card-icon">🏪</div>
                <div class="stat-label">Pending Trade</div>
                <div class="stat-value"><?= $pendingTradeCount ?></div>
            </div>
            <div class="stat-card glass-panel">
                <div class="stat-card-icon">💷</div>
                <div class="stat-label">Total Revenue</div>
                <div class="stat-value" style="font-size:24px;">£<?= number_format($totalRevenue, 2) ?></div>
            </div>
            <div class="stat-card glass-panel">
                <div class="stat-card-icon">🍦</div>
                <div class="stat-label">Products</div>
                <div class="stat-value"><?= $totalProducts ?></div>
            </div>
        </div>

        <!-- Tabs -->
        <div class="admin-tabs">
            <a class="admin-tab <?= $activeTab==='orders'     ? 'active' : '' ?>" href="index.php?tab=orders">
                <i class="fa-solid fa-clipboard-list"></i> Orders
                <?php if ($pendingOrders > 0): ?>
                <span style="background:var(--color-primary); color:white; font-size:10px; padding:2px 7px; border-radius:20px;"><?= $pendingOrders ?></span>
                <?php endif; ?>
            </a>
            <a class="admin-tab <?= $activeTab==='trade'      ? 'active' : '' ?>" href="index.php?tab=trade">
                <i class="fa-solid fa-store"></i> Trade Accounts
                <?php if ($pendingTradeCount > 0): ?>
                <span style="background:#ef4444; color:white; font-size:10px; padding:2px 7px; border-radius:20px;"><?= $pendingTradeCount ?></span>
                <?php endif; ?>
            </a>
            <a class="admin-tab <?= $activeTab==='products'   ? 'active' : '' ?>" href="index.php?tab=products">
                <i class="fa-solid fa-ice-cream"></i> Products
            </a>
            <a class="admin-tab <?= $activeTab==='stock'      ? 'active' : '' ?>" href="index.php?tab=stock">
                <i class="fa-solid fa-boxes-stacked"></i> Stock
            </a>
            <a class="admin-tab <?= $activeTab==='revenue'    ? 'active' : '' ?>" href="index.php?tab=revenue">
                <i class="fa-solid fa-chart-line"></i> Revenue
            </a>
            <a class="admin-tab <?= $activeTab==='gallery'    ? 'active' : '' ?>" href="index.php?tab=gallery">
                <i class="fa-solid fa-images"></i> Gallery
            </a>
            <a class="admin-tab <?= $activeTab==='categories' ? 'active' : '' ?>" href="index.php?tab=categories">
                <i class="fa-solid fa-tags"></i> Categories
            </a>
            <a class="admin-tab <?= $activeTab==='promos'     ? 'active' : '' ?>" href="index.php?tab=promos">
                <i class="fa-solid fa-ticket"></i> Promos
            </a>
            <a class="admin-tab <?= $activeTab==='inquiries'  ? 'active' : '' ?>" href="index.php?tab=inquiries">
                <i class="fa-solid fa-envelope-open-text"></i> Inquiries
                <?php if ($unreadInquiries > 0): ?>
                <span style="background:#ef4444; color:white; font-size:10px; padding:2px 7px; border-radius:20px;"><?= $unreadInquiries ?></span>
                <?php endif; ?>
            </a>
        </div>

        <!-- ═══════════════════ ORDERS TAB ═══════════════════ -->
        <?php if ($activeTab === 'orders'): ?>
        <div class="glass-panel" style="padding:24px; overflow:hidden;">
            <?php if (empty($orders)): ?>
            <div style="text-align:center; padding:60px 20px; color:var(--text-muted);">
                <div style="font-size:64px; margin-bottom:16px; opacity:0.3;">📋</div>
                <p style="font-size:16px;">No orders yet. Share your shop and orders will appear here!</p>
                <a href="../order.php" target="_blank" class="btn-primary" style="margin-top:20px; display:inline-flex;">
                    <i class="fa-solid fa-globe"></i> Open Shop
                </a>
            </div>
            <?php else: ?>

            <!-- Customer Name Filter -->
            <div style="display:flex; align-items:center; gap:10px; margin-bottom:18px; background:rgba(255,255,255,0.05); padding:12px 16px; border-radius:var(--radius-sm); border:1px solid var(--border-light);">
                <i class="fa-solid fa-magnifying-glass" style="color:var(--text-muted); font-size:14px;"></i>
                <input type="text" id="orderNameFilter"
                       placeholder="Filter by customer name…"
                       class="form-control"
                       style="font-size:13px; padding:6px 12px; height:auto; background:var(--bg-main); max-width:320px;"
                       oninput="filterOrdersByName(this.value)">
                <span id="orderFilterCount" style="font-size:12px; color:var(--text-muted); margin-left:4px;"></span>
            </div>

            <div class="table-wrapper">
                <table class="data-table" id="ordersTable" style="table-layout:fixed; width:100%;">
                    <colgroup>
                        <col style="width:36px;">
                        <col style="width:110px;">
                        <col style="width:160px;">
                        <col style="width:180px;">
                        <col style="width:80px;">
                        <col style="width:120px;">
                        <col style="width:120px;">
                        <col style="width:100px;">
                        <col style="width:90px;">
                    </colgroup>
                    <thead>
                        <tr>
                            <th></th>
                            <th>Order Code</th>
                            <th>Customer</th>
                            <th>Items</th>
                            <th>Total</th>
                            <th onclick="toggleSort('status')" style="cursor:pointer; user-select:none; white-space:nowrap;">
                                Status <i id="sort-icon-status" class="fa-solid fa-sort" style="margin-left:4px; opacity:0.5; font-size:12px;"></i>
                            </th>
                            <th onclick="toggleSort('payment')" style="cursor:pointer; user-select:none; white-space:nowrap;">
                                Payment <i id="sort-icon-payment" class="fa-solid fa-sort" style="margin-left:4px; opacity:0.5; font-size:12px;"></i>
                            </th>
                            <th onclick="toggleSort('date')" style="cursor:pointer; user-select:none; white-space:nowrap;">
                                Date <i id="sort-icon-date" class="fa-solid fa-sort-down" style="margin-left:4px; opacity:1; font-size:12px;"></i>
                            </th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $order):
                            $items = json_decode($order['items_json'], true) ?? [];
                            $statusClass = 'status-' . strtolower(str_replace(' ', '-', $order['status']));
                            $isTradeOrder = !empty($order['trade_business_name']) || strpos($order['notes'], 'TRADE B2B ORDER') !== false;
                            $tradeStore   = $order['trade_business_name'] ?: '';
                            if (empty($tradeStore) && preg_match('/Store:\s*([^\]]+)/i', $order['notes'], $m)) {
                                $tradeStore = trim($m[1]);
                            }
                        ?>
                        <tr id="row-<?= $order['id'] ?>" class="order-row" data-id="<?= $order['id'] ?>" data-date="<?= strtotime($order['created_at']) ?>" data-status="<?= htmlspecialchars($order['status']) ?>" data-payment-status="<?= htmlspecialchars($order['payment_status'] ?? 'Unpaid') ?>" data-payment-method="<?= htmlspecialchars($order['payment_method'] ?? 'later') ?>">
                            <td>
                                <button class="expand-btn" onclick="toggleDetail(<?= $order['id'] ?>)" title="View details">
                                    <i class="fa-solid fa-chevron-down" id="icon-<?= $order['id'] ?>"></i>
                                </button>
                            </td>
                            <td>
                                <span style="font-weight:700; font-family:var(--font-heading); color:var(--color-primary); letter-spacing:1px; font-size:12px;">
                                    <?= htmlspecialchars($order['order_code']) ?>
                                </span>
                            </td>
                            <td style="font-weight:600; overflow:hidden; text-overflow:ellipsis;">
                                <?php if ($isTradeOrder): ?>
                                <div style="display:inline-flex; align-items:center; gap:4px; font-size:11px; font-weight:800; padding:2px 8px; border-radius:12px; background:linear-gradient(135deg, var(--color-primary), var(--color-primary-dark)); color:white; margin-bottom:2px;">
                                    <i class="fa-solid fa-store" style="font-size:10px; color:var(--color-secondary);"></i>
                                    TRADE: <?= htmlspecialchars($tradeStore ?: 'Wholesale Partner') ?>
                                </div>
                                <div style="font-size:12px; color:var(--text-primary); font-weight:700;"><?= htmlspecialchars($order['customer_name']) ?></div>
                                <?php else: ?>
                                <span><?= htmlspecialchars($order['customer_name']) ?></span>
                                <?php endif; ?>

                                <?php if (isset($repeatPhoneSet[$order['phone']])): ?>
                                <span style="display:inline-flex; align-items:center; gap:3px; font-size:10px; font-weight:700; padding:2px 6px; border-radius:20px; background:rgba(139,92,246,0.15); color:#8b5cf6; margin-left:4px;">🔁</span>
                                <?php endif; ?>
                            </td>
                            <td style="overflow:hidden;">
                                <?php
                                    $totalQty = array_sum(array_column($items, 'quantity'));
                                    $firstItem = $items[0] ?? null;
                                ?>
                                <?php if ($firstItem): ?>
                                <span class="items-pill" style="font-size:11px;"><?= htmlspecialchars($firstItem['emoji'] ?? '🍦') ?> ×<?= (int)$firstItem['quantity'] ?></span>
                                <?php if (count($items) > 1): ?>
                                <span style="font-size:11px; color:var(--text-muted); font-weight:600;">+<?= count($items) - 1 ?> more</span>
                                <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td style="font-weight:700; font-family:var(--font-heading); color:var(--color-primary); font-size:14px;">
                                £<?= number_format($order['total_price'], 2) ?>
                            </td>
                            <td>
                                <span class="status-badge <?= $statusClass ?>" style="font-size:11px; padding:3px 8px;">
                                    <?= htmlspecialchars($order['status']) ?>
                                </span>
                            </td>
                            <td>
                                <?php
                                    $ps = $order['payment_status'] ?? 'Unpaid';
                                    $pm = $order['payment_method'] ?? 'later';
                                    if ($ps === 'Paid') {
                                        $payIcon = '<i class="fa-solid fa-circle-check" style="color:#10b981;"></i>';
                                        $payColor = '#10b981';
                                        $payBg = 'rgba(16,185,129,0.1)';
                                        $payLabel = 'Paid';
                                    } elseif ($ps === 'Cash') {
                                        $payIcon = '<i class="fa-solid fa-money-bill-wave" style="color:#f59e0b;"></i>';
                                        $payColor = '#f59e0b';
                                        $payBg = 'rgba(245,158,11,0.1)';
                                        $payLabel = 'Cash';
                                    } else {
                                        $payIcon = '<i class="fa-solid fa-clock" style="color:var(--text-muted);"></i>';
                                        $payColor = 'var(--text-muted)';
                                        $payBg = 'rgba(100,100,100,0.08)';
                                        $payLabel = 'Unpaid';
                                    }
                                ?>
                                <span id="pay-badge-<?= $order['id'] ?>" style="display:inline-flex; align-items:center; gap:4px; font-size:11px; font-weight:700; padding:3px 8px; border-radius:20px; background:<?= $payBg ?>; color:<?= $payColor ?>; white-space:nowrap;">
                                    <?= $payIcon ?> <?= $payLabel ?>
                                </span>
                            </td>
                            <td style="color:var(--text-secondary); font-size:12px; white-space:nowrap;">
                                <?= date('d M y', strtotime($order['created_at'])) ?>
                                <div style="font-size:11px; color:var(--text-muted);"><?= date('H:i', strtotime($order['created_at'])) ?></div>
                            </td>
                            <td style="text-align:right;">
                                <div style="display:flex; align-items:center; gap:4px; justify-content:flex-end;">
                                    <a href="delivery_note.php?code=<?= urlencode($order['order_code']) ?>" target="_blank" class="btn-sm btn-sm-outline" title="Print Delivery Note & Invoice" style="padding:4px 8px;">
                                        <i class="fa-solid fa-print"></i>
                                    </a>
                                    <button class="expand-btn btn-sm btn-sm-outline" onclick="toggleDetail(<?= $order['id'] ?>)" title="Details" style="padding:4px 8px;">
                                        <i class="fa-solid fa-eye"></i>
                                    </button>
                                    <button class="btn-sm btn-sm-danger" onclick="deleteOrder(<?= $order['id'] ?>, '<?= htmlspecialchars($order['order_code'], ENT_QUOTES) ?>')" title="Delete" style="padding:4px 8px;">
                                        <i class="fa-solid fa-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <!-- Expandable detail row -->
                        <tr class="order-detail-row" id="detail-<?= $order['id'] ?>">
                            <td colspan="9">
                                <div class="order-detail-inner">
                                    <!-- Customer Info Strip -->
                                    <div style="display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:16px; margin-bottom:16px; padding:12px 14px; background:var(--bg-main); border-radius:var(--radius-sm); border:1px solid var(--border-light); font-size:13px;">
                                        <div style="display:flex; flex-wrap:wrap; gap:16px; align-items:center;">
                                            <?php if ($isTradeOrder): ?>
                                            <div style="background:var(--color-primary); color:white; font-size:11px; font-weight:800; padding:3px 10px; border-radius:14px; display:flex; align-items:center; gap:5px;">
                                                <i class="fa-solid fa-store"></i> STORE: <?= htmlspecialchars($tradeStore ?: 'Trade Partner') ?>
                                            </div>
                                            <?php endif; ?>
                                            <div style="display:flex; align-items:center; gap:7px;">
                                                <i class="fa-solid fa-user" style="color:var(--color-primary); width:14px;"></i>
                                                <strong><?= htmlspecialchars($order['customer_name']) ?></strong>
                                            </div>
                                            <div style="display:flex; align-items:center; gap:7px; color:var(--text-secondary);">
                                                <i class="fa-solid fa-phone" style="color:var(--color-secondary); width:14px;"></i>
                                                <?= htmlspecialchars($order['phone']) ?>
                                            </div>
                                            <?php if (!empty($order['customer_email'])): ?>
                                            <div style="display:flex; align-items:center; gap:7px; color:var(--text-secondary);">
                                                <i class="fa-solid fa-envelope" style="color:#8b5cf6; width:14px;"></i>
                                                <a href="mailto:<?= htmlspecialchars($order['customer_email']) ?>" style="color:var(--text-secondary); text-decoration:none;"><?= htmlspecialchars($order['customer_email']) ?></a>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <a href="delivery_note.php?code=<?= urlencode($order['order_code']) ?>" target="_blank" class="btn-sm btn-sm-primary" style="padding:6px 12px; font-size:12px; gap:6px;">
                                                <i class="fa-solid fa-print"></i> Print Delivery Note & Invoice
                                            </a>
                                        </div>
                                    </div>
                                    <div class="order-detail-grid">
                                        <div class="order-detail-field">
                                            <label>📍 Delivery Address</label>
                                            <p><?= nl2br(htmlspecialchars($order['address'])) ?></p>
                                        </div>
                                        <div class="order-detail-field">
                                            <label>📝 Special Notes</label>
                                            <p><?= !empty($order['notes']) ? nl2br(htmlspecialchars($order['notes'])) : '<span style="color:var(--text-muted);">None</span>' ?></p>
                                        </div>
                                    </div>
                                    <!-- Items table -->
                                    <table style="width:100%; border-collapse:collapse; margin-bottom:16px;">
                                        <thead>
                                            <tr style="border-bottom:1px solid var(--border-color);">
                                                <th style="text-align:left; padding:6px 8px; font-size:11px; color:var(--text-muted); text-transform:uppercase;">Item</th>
                                                <th style="text-align:center; padding:6px 8px; font-size:11px; color:var(--text-muted); text-transform:uppercase;">Qty</th>
                                                <th style="text-align:right; padding:6px 8px; font-size:11px; color:var(--text-muted); text-transform:uppercase;">Price</th>
                                                <th style="text-align:right; padding:6px 8px; font-size:11px; color:var(--text-muted); text-transform:uppercase;">Subtotal</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach ($items as $it): ?>
                                        <tr>
                                            <td style="padding:8px; font-size:14px;"><?= htmlspecialchars($it['emoji'] ?? '🍦') ?> <?= htmlspecialchars($it['name']) ?><?= !empty($it['variant_name']) ? ' <span style="font-size:11px;color:var(--text-muted);">('.$it['variant_name'].')</span>' : '' ?></td>
                                            <td style="padding:8px; text-align:center; color:var(--text-secondary);">× <?= (int)$it['quantity'] ?></td>
                                            <td style="padding:8px; text-align:right; color:var(--text-secondary);">£<?= number_format($it['price'], 2) ?></td>
                                            <td style="padding:8px; text-align:right; font-weight:700; color:var(--color-primary);">£<?= number_format($it['price'] * $it['quantity'], 2) ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                        <tfoot>
                                            <?php 
                                                $subtotal = 0;
                                                foreach ($items as $it) {
                                                    $subtotal += $it['price'] * $it['quantity'];
                                                }
                                            ?>
                                            <tr style="border-top:1px solid var(--border-light);">
                                                <td colspan="3" style="padding:6px 8px; font-size:13px; color:var(--text-secondary);">Subtotal</td>
                                                <td style="padding:6px 8px; text-align:right; color:var(--text-secondary); font-weight:700;">£<?= number_format($subtotal, 2) ?></td>
                                            </tr>
                                            <?php if (!empty($order['promo_code']) && $order['discount_amount'] > 0): ?>
                                            <tr>
                                                <td colspan="3" style="padding:6px 8px; font-size:13px; color:var(--text-secondary);">🎟️ Promo: <strong><?= htmlspecialchars($order['promo_code']) ?></strong></td>
                                                <td style="padding:6px 8px; text-align:right; color:#10b981; font-weight:700;">−£<?= number_format($order['discount_amount'], 2) ?></td>
                                            </tr>
                                            <?php endif; ?>
                                            <?php if ((float)($order['delivery_charge'] ?? 0) > 0): ?>
                                            <tr>
                                                <td colspan="3" style="padding:6px 8px; font-size:13px; color:var(--text-secondary);">🚚 Delivery Charge</td>
                                                <td style="padding:6px 8px; text-align:right; color:#f59e0b; font-weight:700;">+£<?= number_format($order['delivery_charge'], 2) ?></td>
                                            </tr>
                                            <?php endif; ?>
                                            <tr style="border-top:1px solid var(--border-light);">
                                                <td colspan="3" style="padding:6px 8px; font-weight:700; font-size:15px;">Total</td>
                                                <td style="padding:6px 8px; text-align:right; font-weight:800; font-size:16px; color:var(--color-primary);">£<?= number_format($order['total_price'], 2) ?></td>
                                            </tr>
                                        </tfoot>
                                    </table>

                                    <!-- Status + Payment update row -->
                                    <div style="display:flex; align-items:center; gap:16px; flex-wrap:wrap; padding:14px; background:var(--bg-main); border-radius:var(--radius-sm); border:1px solid var(--border-light);">

                                        <!-- Order Status -->
                                        <div style="display:flex; align-items:center; gap:8px;">
                                            <label style="font-size:12px; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.5px;">Order Status</label>
                                            <select class="status-select" id="status-<?= $order['id'] ?>">
                                                <?php foreach (['Pending','Processing','Delivered','Cancelled'] as $s): ?>
                                                <option value="<?= $s ?>" <?= $order['status']===$s ? 'selected' : '' ?>><?= $s ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button class="btn-sm btn-sm-primary" onclick="updateStatus(<?= $order['id'] ?>, '<?= $order['order_code'] ?>')">
                                                <i class="fa-solid fa-check"></i> Save
                                            </button>
                                        </div>

                                        <div style="width:1px; height:32px; background:var(--border-light);"></div>

                                        <!-- Payment Status -->
                                        <?php if (($order['payment_status'] ?? '') === 'Paid'): ?>
                                        <div style="font-size:12px; color:#047857; font-weight:700; background:#ecfdf5; padding:6px 14px; border-radius:20px; border:1px solid #a7f3d0; display:inline-flex; align-items:center; gap:6px;">
                                            <i class="fa-solid fa-lock" style="font-size:11px;"></i> ✅ Paid Online (Locked)
                                        </div>
                                        <?php else: ?>
                                        <div style="display:flex; align-items:center; gap:8px;">
                                            <label style="font-size:12px; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:0.5px;">Payment</label>
                                            <select class="status-select" id="pstatus-<?= $order['id'] ?>">
                                                <option value="Unpaid"  <?= ($order['payment_status'] ?? 'Unpaid') === 'Unpaid' ? 'selected' : '' ?>>⏳ Not Paid</option>
                                                <option value="Paid"    <?= ($order['payment_status'] ?? '') === 'Paid'   ? 'selected' : '' ?>>✅ Paid Online</option>
                                                <option value="Cash"    <?= ($order['payment_status'] ?? '') === 'Cash'   ? 'selected' : '' ?>>💵 Cash Received</option>
                                            </select>
                                            <button class="btn-sm btn-sm-primary" onclick="updatePaymentStatus(<?= $order['id'] ?>)">
                                                <i class="fa-solid fa-check"></i> Save
                                            </button>
                                        </div>
                                        <?php endif; ?>

                                        <span id="status-msg-<?= $order['id'] ?>" style="font-size:12px; color:var(--color-secondary);"></span>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- ═══════════════════ TRADE ACCOUNTS TAB ═══════════ -->
        <?php elseif ($activeTab === 'trade'): ?>
        <div class="glass-panel" style="padding:28px;">
            <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px; margin-bottom:24px; padding-bottom:20px; border-bottom:1px solid var(--border-light);">
                <div>
                    <h2 style="margin:0; font-size:22px; font-family:var(--font-heading); color:var(--text-primary); display:flex; align-items:center; gap:8px;">
                        <span>🏪</span> Trade Partner Applications & Wholesale Accounts
                    </h2>
                    <p style="margin:6px 0 0; font-size:14px; color:var(--text-secondary);">Review, approve, or reject retail store applications for wholesale access.</p>
                </div>
                <a href="../trade_register.php" target="_blank" class="btn-secondary" style="padding:10px 18px; font-size:13px; gap:8px;">
                    <i class="fa-solid fa-arrow-up-right-from-square"></i> Open Registration Form
                </a>
            </div>

            <?php
            $tradePending  = count(array_filter($tradeUsers, fn($u) => $u['status'] === 'pending'));
            $tradeApproved = count(array_filter($tradeUsers, fn($u) => $u['status'] === 'approved'));
            $tradeRejected = count(array_filter($tradeUsers, fn($u) => $u['status'] === 'rejected'));
            ?>

            <!-- Trade Quick Stats -->
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:16px; margin-bottom:24px;">
                <div style="background:var(--bg-main); border:1px solid var(--border-light); padding:16px; border-radius:var(--radius-md); display:flex; align-items:center; gap:14px;">
                    <div style="font-size:28px;">🏪</div>
                    <div>
                        <div style="font-size:20px; font-weight:800; color:var(--text-primary);"><?= count($tradeUsers) ?></div>
                        <div style="font-size:12px; color:var(--text-secondary); font-weight:600;">Total Applications</div>
                    </div>
                </div>
                <div style="background:#fffbeb; border:1px solid #fde68a; padding:16px; border-radius:var(--radius-md); display:flex; align-items:center; gap:14px;">
                    <div style="font-size:28px;">⏳</div>
                    <div>
                        <div style="font-size:20px; font-weight:800; color:#b45309;"><?= $tradePending ?></div>
                        <div style="font-size:12px; color:#92400e; font-weight:600;">Pending Review</div>
                    </div>
                </div>
                <div style="background:#ecfdf5; border:1px solid #a7f3d0; padding:16px; border-radius:var(--radius-md); display:flex; align-items:center; gap:14px;">
                    <div style="font-size:28px;">✅</div>
                    <div>
                        <div style="font-size:20px; font-weight:800; color:#047857;"><?= $tradeApproved ?></div>
                        <div style="font-size:12px; color:#065f46; font-weight:600;">Approved Partners</div>
                    </div>
                </div>
                <div style="background:#fef2f2; border:1px solid #fecaca; padding:16px; border-radius:var(--radius-md); display:flex; align-items:center; gap:14px;">
                    <div style="font-size:28px;">❌</div>
                    <div>
                        <div style="font-size:20px; font-weight:800; color:#b91c1c;"><?= $tradeRejected ?></div>
                        <div style="font-size:12px; color:#991b1b; font-weight:600;">Rejected</div>
                    </div>
                </div>
            </div>

            <?php if (empty($tradeUsers)): ?>
            <div style="text-align:center; padding:60px 20px; color:var(--text-muted); background:var(--bg-main); border-radius:var(--radius-md); border:1px dashed var(--border-color);">
                <div style="font-size:56px; margin-bottom:16px; opacity:0.5;">🏪</div>
                <h3 style="font-size:18px; margin:0 0 6px; color:var(--text-primary);">No trade applications yet</h3>
                <p style="font-size:14px; color:var(--text-secondary); max-width:440px; margin:0 auto;">
                    Store owners can apply for wholesale pricing at <br>
                    <a href="../trade_register.php" target="_blank" style="color:var(--color-primary); font-weight:700;">orders.creamybite.com/trade_register.php</a>
                </p>
            </div>
            <?php else: ?>
            
            <!-- Applications Table -->
            <div class="table-wrapper">
                <table class="data-table" style="width:100%;">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Store / Business</th>
                            <th>Contact Person</th>
                            <th>Email & Phone</th>
                            <th>🔑 Password (Rep Access)</th>
                            <th>Delivery Address</th>
                            <th>VAT / Reg No</th>
                            <th>Status</th>
                            <th>Applied Date</th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tradeUsers as $tu):
                            $st = $tu['status'];
                            if ($st === 'approved') {
                                $stBadge = '<span style="background:#ecfdf5; color:#047857; font-size:12px; font-weight:700; padding:5px 12px; border-radius:20px; border:1px solid #a7f3d0; display:inline-flex; align-items:center; gap:5px;"><i class="fa-solid fa-circle-check"></i> Approved</span>';
                            } elseif ($st === 'rejected') {
                                $stBadge = '<span style="background:#fef2f2; color:#b91c1c; font-size:12px; font-weight:700; padding:5px 12px; border-radius:20px; border:1px solid #fecaca; display:inline-flex; align-items:center; gap:5px;"><i class="fa-solid fa-circle-xmark"></i> Rejected</span>';
                            } else {
                                $stBadge = '<span style="background:#fffbeb; color:#b45309; font-size:12px; font-weight:700; padding:5px 12px; border-radius:20px; border:1px solid #fde68a; display:inline-flex; align-items:center; gap:5px;"><i class="fa-solid fa-clock"></i> Pending Review</span>';
                            }
                            $rawPass = !empty($tu['raw_password']) ? htmlspecialchars($tu['raw_password']) : null;
                        ?>
                        <tr style="border-bottom:1px solid var(--border-light);">
                            <td style="font-weight:700; color:var(--text-muted);">#<?= $tu['id'] ?></td>
                            <td>
                                <strong style="font-size:15px; color:var(--text-primary); display:flex; align-items:center; gap:6px;">
                                    <span>🏬</span> <?= htmlspecialchars($tu['business_name']) ?>
                                </strong>
                            </td>
                            <td style="font-weight:600; font-size:13px; color:var(--text-primary);">
                                <?= htmlspecialchars($tu['contact_name']) ?>
                            </td>
                            <td style="font-size:13px;">
                                <a href="mailto:<?= htmlspecialchars($tu['email']) ?>" style="color:var(--color-primary); font-weight:700; text-decoration:none; display:block;">
                                    <i class="fa-solid fa-envelope" style="font-size:11px; margin-right:4px;"></i> <?= htmlspecialchars($tu['email']) ?>
                                </a>
                                <a href="tel:<?= htmlspecialchars($tu['phone']) ?>" style="color:var(--text-secondary); font-size:12px; text-decoration:none; display:block; margin-top:2px;">
                                    <i class="fa-solid fa-phone" style="font-size:11px; margin-right:4px;"></i> <?= htmlspecialchars($tu['phone']) ?>
                                </a>
                            </td>
                            <td style="font-size:13px;">
                                <?php if ($rawPass): ?>
                                <span style="background:#f0fdf4; border:1px solid #bbf7d0; color:#166534; font-family:monospace; font-weight:700; padding:4px 10px; border-radius:6px; font-size:13px; display:inline-flex; align-items:center; gap:6px;">
                                    <i class="fa-solid fa-key" style="font-size:11px;"></i> <?= $rawPass ?>
                                </span>
                                <?php else: ?>
                                <span style="color:var(--text-muted); font-size:11px; italic;">(Hashed Password)</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size:12px; max-width:220px; line-height:1.4;">
                                <div><?= htmlspecialchars($tu['address']) ?></div>
                                <span style="font-weight:800; color:var(--color-primary); font-family:monospace;"><?= htmlspecialchars($tu['postcode']) ?></span>
                            </td>
                            <td style="font-size:12px; color:var(--text-secondary);">
                                <?= !empty($tu['vat_number']) ? '<span style="font-family:monospace; background:var(--bg-main); padding:2px 8px; border-radius:6px; border:1px solid var(--border-light); font-weight:700;">' . htmlspecialchars($tu['vat_number']) . '</span>' : '<span style="color:var(--text-muted); italic;">None</span>' ?>
                            </td>
                            <td><?= $stBadge ?></td>
                            <td style="font-size:12px; color:var(--text-secondary); white-space:nowrap;">
                                <?= date('d M Y', strtotime($tu['created_at'])) ?>
                                <div style="font-size:11px; color:var(--text-muted);"><?= date('H:i', strtotime($tu['created_at'])) ?></div>
                            </td>
                            <td style="text-align:right; white-space:nowrap;">
                                <div style="display:flex; gap:6px; justify-content:flex-end;">
                                    <?php if ($st !== 'approved'): ?>
                                    <a href="index.php?tab=trade&action=approve_trade&id=<?= $tu['id'] ?>" class="btn-sm btn-sm-success" style="padding:6px 12px; font-size:12px; gap:4px;" onclick="return confirm('Approve trade account for <?= htmlspecialchars($tu['business_name'], ENT_QUOTES) ?>?')">
                                        <i class="fa-solid fa-check"></i> Approve
                                    </a>
                                    <?php endif; ?>
                                    <?php if ($st !== 'rejected'): ?>
                                    <a href="index.php?tab=trade&action=reject_trade&id=<?= $tu['id'] ?>" class="btn-sm btn-sm-danger" style="padding:6px 12px; font-size:12px; gap:4px;" onclick="return confirm('Reject trade account for <?= htmlspecialchars($tu['business_name'], ENT_QUOTES) ?>?')">
                                        <i class="fa-solid fa-xmark"></i> Reject
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- ═══════════════════ PRODUCTS TAB ═════════════════ -->
        <?php elseif ($activeTab === 'products'): ?>
        <div class="glass-panel" style="padding:24px; overflow:hidden;">
            <?php if (empty($products)): ?>
            <div style="text-align:center; padding:60px 20px; color:var(--text-muted);">
                <div style="font-size:64px; margin-bottom:16px; opacity:0.3;">🍦</div>
                <p style="font-size:16px;">No products yet.</p>
                <a href="product_form.php" class="btn-primary" style="margin-top:20px; display:inline-flex;">
                    <i class="fa-solid fa-plus"></i> Add First Product
                </a>
            </div>
            <?php else: ?>
            
            <!-- Filter & Sort Bar -->
            <div style="display:flex; align-items:center; gap:16px; margin-bottom:20px; flex-wrap:wrap; background:rgba(255,255,255,0.05); padding:14px; border-radius:var(--radius-sm); border:1px solid var(--border-light);">
                <div style="display:flex; align-items:center; gap:8px;">
                    <label for="prodFilterCategory" style="font-size:12px; font-weight:700; color:var(--text-secondary); text-transform:uppercase; letter-spacing:0.5px;">Category:</label>
                    <select id="prodFilterCategory" class="form-control" style="font-size:13px; padding:6px 12px; height:auto; width:auto; min-width:160px; background:var(--bg-main);" onchange="filterAndSortProducts()">
                        <option value="all">🔍 All Categories</option>
                        <?php 
                        $prodCats = array_unique(array_column($products, 'category'));
                        sort($prodCats);
                        foreach ($prodCats as $catName): 
                        ?>
                        <option value="<?= htmlspecialchars($catName) ?>"><?= htmlspecialchars($catName) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div style="display:flex; align-items:center; gap:8px;">
                    <label for="prodSort" style="font-size:12px; font-weight:700; color:var(--text-secondary); text-transform:uppercase; letter-spacing:0.5px;">Sort By:</label>
                    <select id="prodSort" class="form-control" style="font-size:13px; padding:6px 12px; height:auto; width:auto; min-width:160px; background:var(--bg-main);" onchange="filterAndSortProducts()">
                        <option value="default">Default (Newest)</option>
                        <option value="name_asc">Name (A-Z)</option>
                        <option value="name_desc">Name (Z-A)</option>
                        <option value="price_asc">Price (Low to High)</option>
                        <option value="price_desc">Price (High to Low)</option>
                        <option value="category_asc">Category (A-Z)</option>
                    </select>
                </div>
            </div>

            <div class="table-wrapper">
                <table class="data-table" id="productsTable">
                    <thead>
                        <tr>
                            <th>Image</th>
                            <th>Name</th>
                            <th>Category</th>
                            <th>Price</th>
                            <th>Badge</th>
                            <th>Available</th>
                            <th>Stock</th>
                            <th style="text-align:right;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($products as $p): ?>
                        <tr class="product-row" data-id="<?= $p['id'] ?>" data-name="<?= htmlspecialchars($p['name'], ENT_QUOTES) ?>" data-category="<?= htmlspecialchars($p['category'], ENT_QUOTES) ?>" data-price="<?= $p['price'] ?>">
                            <td>
                                <?php if (!empty($p['image'])): ?>
                                <img src="../assets/images/products/<?= htmlspecialchars($p['image']) ?>"
                                     alt="<?= htmlspecialchars($p['name']) ?>"
                                     style="width:52px; height:52px; object-fit:cover; border-radius:var(--radius-xs); border:1px solid var(--border-light);">
                                <?php else: ?>
                                <span style="font-size:32px;"><?= htmlspecialchars($p['emoji'] ?? '🍦') ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="font-weight:700; font-size:15px;"><?= htmlspecialchars($p['name']) ?></div>
                                <div style="font-size:12px; color:var(--text-secondary); margin-top:2px; max-width:240px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                                    <?= htmlspecialchars($p['description']) ?>
                                </div>
                            </td>
                            <td>
                                <span style="font-size:12px; font-weight:700; text-transform:uppercase; color:var(--color-secondary);">
                                    <?= htmlspecialchars($p['category']) ?>
                                </span>
                            </td>
                            <td style="font-weight:800; font-family:var(--font-heading); color:var(--color-primary); font-size:15px;">
                                £<?= number_format($p['price'], 2) ?>
                            </td>
                            <td>
                                <?php if (!empty($p['badge'])): ?>
                                    <?php $bc = $p['badge']==='New' ? 'badge-new' : ($p['badge']==='Hot' ? 'badge-hot' : 'badge-best-seller'); ?>
                                    <span class="product-badge <?= $bc ?>" style="position:static; display:inline-block;">
                                        <?= htmlspecialchars($p['badge']) ?>
                                    </span>
                                <?php else: ?>
                                    <span style="color:var(--text-muted);">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($p['available']): ?>
                                <span class="status-badge status-delivered" style="font-size:11px;">✅ Yes</span>
                                <?php else: ?>
                                <span class="status-badge status-cancelled" style="font-size:11px;">❌ No</span>
                                <?php endif; ?>
                            </td>
                            <td>
                            <?php
                                // Compute real in_stock using same formula as Stock tab
                                $pTs  = (int)($p['total_stock']  ?? $p['stock_qty'] ?? 0);
                                $pDmg = (int)($p['damage_stock'] ?? 0);
                                $pOff = (int)($p['sold_offline'] ?? 0);
                                $pSol = (int)($p['sold_online']  ?? 0);
                                $pIns = max(0, $pTs - $pDmg - $pOff - $pSol);
                            ?>
                            <?php if ($p['track_stock']): ?>
                                <?php if ($pIns > 0): ?>
                                <span style="display:inline-flex; align-items:center; gap:4px; font-size:11px; font-weight:700; padding:3px 9px; border-radius:20px; background:rgba(16,185,129,0.12); color:#10b981;">
                                    🟢 <?= $pIns ?> left
                                </span>
                                <?php else: ?>
                                <span style="display:inline-flex; align-items:center; gap:4px; font-size:11px; font-weight:700; padding:3px 9px; border-radius:20px; background:rgba(239,68,68,0.12); color:#ef4444;">🔴 Out of Stock</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span style="font-size:11px; color:var(--text-muted);">∞ Unlimited</span>
                            <?php endif; ?>
                            </td>
                            <td style="text-align:right;">
                                <div class="action-group" style="justify-content:flex-end;">
                                    <a href="product_form.php?id=<?= $p['id'] ?>" class="btn-sm btn-sm-outline">
                                        <i class="fa-solid fa-pen"></i> Edit
                                    </a>
                                    <a href="index.php?action=delete_product&id=<?= $p['id'] ?>&tab=products"
                                       class="btn-sm btn-sm-danger"
                                       onclick="return confirm('Delete &quot;<?= addslashes($p['name']) ?>&quot;? This cannot be undone.')">
                                        <i class="fa-solid fa-trash"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- ═══════════════════ STOCK TAB ════════════════════ -->
        <?php elseif ($activeTab === 'stock'): ?>
        <div class="glass-panel" style="padding:24px; overflow:hidden;">

            <!-- Stock summary cards -->
            <?php
            $totalInStock = 0; $totalDamage = 0; $totalOffline = 0; $totalOnline = 0; $grandTotal = 0;
            foreach ($stockProducts as $sp) {
                $ts  = (int)($sp['total_stock']  ?? $sp['stock_qty'] ?? 0);
                $dmg = (int)($sp['damage_stock'] ?? 0);
                $off = (int)($sp['sold_offline'] ?? 0);
                $sol = (int)($sp['sold_online']  ?? 0);
                $totalInStock += max(0, $ts - $dmg - $off - $sol);
                $totalDamage  += $dmg; $totalOffline += $off;
                $totalOnline  += $sol; $grandTotal   += $ts;
            }
            ?>
            <div style="display:grid; grid-template-columns:repeat(5,1fr); gap:14px; margin-bottom:28px;">
                <div style="background:rgba(139,92,246,0.08); border:1px solid rgba(139,92,246,0.2); border-radius:var(--radius-md); padding:16px 18px;">
                    <div style="font-size:20px; margin-bottom:4px;">📦</div>
                    <div style="font-size:26px; font-weight:800; color:#8b5cf6; font-family:var(--font-heading);"><?= $grandTotal ?></div>
                    <div style="font-size:11px; font-weight:700; color:var(--text-secondary); text-transform:uppercase; letter-spacing:0.5px; margin-top:2px;">Grand Total</div>
                </div>
                <div style="background:rgba(16,185,129,0.08); border:1px solid rgba(16,185,129,0.2); border-radius:var(--radius-md); padding:16px 18px;">
                    <div style="font-size:20px; margin-bottom:4px;">🟢</div>
                    <div style="font-size:26px; font-weight:800; color:#10b981; font-family:var(--font-heading);"><?= $totalInStock ?></div>
                    <div style="font-size:11px; font-weight:700; color:var(--text-secondary); text-transform:uppercase; letter-spacing:0.5px; margin-top:2px;">In Stock</div>
                </div>
                <div style="background:rgba(239,68,68,0.08); border:1px solid rgba(239,68,68,0.2); border-radius:var(--radius-md); padding:16px 18px;">
                    <div style="font-size:20px; margin-bottom:4px;">⚠️</div>
                    <div style="font-size:26px; font-weight:800; color:#ef4444; font-family:var(--font-heading);"><?= $totalDamage ?></div>
                    <div style="font-size:11px; font-weight:700; color:var(--text-secondary); text-transform:uppercase; letter-spacing:0.5px; margin-top:2px;">Damage</div>
                </div>
                <div style="background:rgba(245,158,11,0.08); border:1px solid rgba(245,158,11,0.2); border-radius:var(--radius-md); padding:16px 18px;">
                    <div style="font-size:20px; margin-bottom:4px;">🏪</div>
                    <div style="font-size:26px; font-weight:800; color:#f59e0b; font-family:var(--font-heading);"><?= $totalOffline ?></div>
                    <div style="font-size:11px; font-weight:700; color:var(--text-secondary); text-transform:uppercase; letter-spacing:0.5px; margin-top:2px;">Sold Offline</div>
                </div>
                <div style="background:rgba(59,130,246,0.08); border:1px solid rgba(59,130,246,0.2); border-radius:var(--radius-md); padding:16px 18px;">
                    <div style="font-size:20px; margin-bottom:4px;">🛒</div>
                    <div style="font-size:26px; font-weight:800; color:#3b82f6; font-family:var(--font-heading);"><?= $totalOnline ?></div>
                    <div style="font-size:11px; font-weight:700; color:var(--text-secondary); text-transform:uppercase; letter-spacing:0.5px; margin-top:2px;">Sold Online</div>
                </div>
            </div>

            <?php if (empty($stockProducts)): ?>
            <div style="text-align:center; padding:60px 20px; color:var(--text-muted);">
                <div style="font-size:64px; margin-bottom:16px; opacity:0.3;">📦</div>
                <p style="font-size:16px;">No products yet. <a href="product_form.php" class="btn-primary" style="display:inline-flex; margin-left:10px;"><i class="fa-solid fa-plus"></i> Add Product</a></p>
            </div>
            <?php else: ?>

            <!-- Setup warning -->
            <?php if (!$stockMigrationDone || !$stockV2Done): ?>
            <div style="background:rgba(245,158,11,0.12); border:1px solid rgba(245,158,11,0.4); border-radius:var(--radius-sm); padding:14px 18px; margin-bottom:20px; font-size:13px; color:var(--text-secondary); display:flex; align-items:flex-start; justify-content:space-between; gap:16px; flex-wrap:wrap;">
                <div style="display:flex; align-items:flex-start; gap:12px;">
                    <i class="fa-solid fa-triangle-exclamation" style="color:#f59e0b; margin-top:2px; flex-shrink:0; font-size:16px;"></i>
                    <div><strong style="color:#f59e0b; font-size:14px;">Database setup required</strong><br>Some stock columns are missing. <a href="setup_stock.php">Run Setup Now →</a></div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Info note -->
            <div style="background:rgba(139,92,246,0.08); border:1px solid rgba(139,92,246,0.2); border-radius:var(--radius-sm); padding:12px 16px; margin-bottom:20px; font-size:13px; color:var(--text-secondary); display:flex; align-items:flex-start; gap:10px;">
                <i class="fa-solid fa-circle-info" style="color:#8b5cf6; margin-top:2px; flex-shrink:0;"></i>
                <span><strong>Click ‘Edit Stock’ to add new stock, damage, or offline sales.</strong> Grand Total, In Stock, Damage and Sold columns are all read-only — updated when you save via the Edit button. Sold Online auto-counts when an order is marked Delivered.</span>
            </div>

            <div class="table-wrapper">
                <table class="data-table" id="stockTable">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th style="text-align:center;">Tracked</th>
                            <th style="text-align:center; color:#8b5cf6;">📦 Grand Total<div style="font-size:10px; font-weight:400; color:var(--text-muted); margin-top:2px;">(auto-cumulative)</div></th>
                            <th style="text-align:center; color:#10b981;">🟢 In Stock<div style="font-size:10px; font-weight:400; color:var(--text-muted); margin-top:2px;">(auto-calculated)</div></th>
                            <th style="text-align:center; color:#ef4444;">⚠️ Damage<div style="font-size:10px; font-weight:400; color:var(--text-muted); margin-top:2px;">(cumulative)</div></th>
                            <th style="text-align:center; color:#f59e0b;">🏪 Sold Offline<div style="font-size:10px; font-weight:400; color:var(--text-muted); margin-top:2px;">(cumulative)</div></th>
                            <th style="text-align:center; color:#3b82f6;">🛒 Sold Online<div style="font-size:10px; font-weight:400; color:var(--text-muted); margin-top:2px;">(auto on Delivered)</div></th>
                            <th style="text-align:center;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stockProducts as $sp):
                            $ts  = (int)($sp['total_stock']  ?? $sp['stock_qty'] ?? 0);
                            $dmg = (int)($sp['damage_stock'] ?? 0);
                            $off = (int)($sp['sold_offline'] ?? 0);
                            $sol = (int)($sp['sold_online']  ?? 0);
                            $ins = max(0, $ts - $dmg - $off - $sol);
                        ?>
                        <tr id="stock-row-<?= $sp['id'] ?>">
                            <!-- Product -->
                            <td>
                                <div style="display:flex; align-items:center; gap:10px;">
                                    <?php if (!empty($sp['image'])): ?>
                                    <img src="../assets/images/products/<?= htmlspecialchars($sp['image']) ?>"
                                         style="width:40px; height:40px; object-fit:cover; border-radius:8px; border:1px solid var(--border-light);">
                                    <?php else: ?>
                                    <span style="font-size:26px;"><?= htmlspecialchars($sp['emoji'] ?? '🍦') ?></span>
                                    <?php endif; ?>
                                    <div>
                                        <div style="font-weight:700; font-size:14px;"><?= htmlspecialchars($sp['name']) ?></div>
                                        <div style="font-size:11px; color:var(--text-muted); text-transform:uppercase;"><?= htmlspecialchars($sp['category']) ?></div>
                                    </div>
                                </div>
                            </td>
                            <!-- Tracked -->
                            <td style="text-align:center;">
                                <?php if ($sp['track_stock']): ?>
                                <span style="font-size:11px; font-weight:700; padding:3px 9px; border-radius:20px; background:rgba(16,185,129,0.12); color:#10b981;">Yes</span>
                                <?php else: ?>
                                <span style="font-size:11px; padding:3px 9px; border-radius:20px; background:rgba(100,100,100,0.1); color:var(--text-muted);">&#8734; Unlimited</span>
                                <?php endif; ?>
                            </td>
                            <!-- Grand Total (read-only) -->
                            <td style="text-align:center;">
                                <span id="val-total_stock-<?= $sp['id'] ?>" style="font-weight:800; font-size:18px; color:#8b5cf6; font-family:var(--font-heading);"><?= $ts ?></span>
                            </td>
                            <!-- In Stock (auto, read-only) -->
                            <td style="text-align:center;">
                                <?php if ($sp['track_stock']): ?>
                                <span id="val-in_stock-<?= $sp['id'] ?>" style="font-weight:900; font-size:20px; color:<?= $ins > 0 ? '#10b981' : '#ef4444' ?>; font-family:var(--font-heading);"><?= $ins ?></span>
                                <?php else: ?>
                                <span style="color:var(--text-muted); font-size:13px;">—</span>
                                <?php endif; ?>
                            </td>
                            <!-- Damage (read-only) -->
                            <td style="text-align:center;">
                                <span id="val-damage_stock-<?= $sp['id'] ?>" style="font-weight:800; font-size:18px; color:#ef4444; font-family:var(--font-heading);"><?= $dmg ?></span>
                            </td>
                            <!-- Sold Offline (read-only) -->
                            <td style="text-align:center;">
                                <span id="val-sold_offline-<?= $sp['id'] ?>" style="font-weight:800; font-size:18px; color:#f59e0b; font-family:var(--font-heading);"><?= $off ?></span>
                            </td>
                            <!-- Sold Online (auto, read-only) -->
                            <td style="text-align:center;">
                                <span id="val-sold_online-<?= $sp['id'] ?>" style="font-weight:800; font-size:18px; color:#3b82f6; font-family:var(--font-heading);"><?= $sol ?></span>
                            </td>
                            <!-- Actions -->
                            <td style="text-align:center;">
                                <div style="display:flex; align-items:center; justify-content:center; gap:6px;">
                                    <?php if ($sp['track_stock']): ?>
                                    <button class="btn-sm btn-primary" onclick="openStockEdit(<?= $sp['id'] ?>, '<?= htmlspecialchars($sp['name'], ENT_QUOTES) ?>', <?= $ts ?>, <?= $dmg ?>, <?= $off ?>)" style="font-size:12px; padding:5px 12px;">
                                        <i class="fa-solid fa-pen-to-square"></i> Edit Stock
                                    </button>
                                    <?php endif; ?>
                                    <a href="product_form.php?id=<?= $sp['id'] ?>" class="btn-sm btn-sm-outline" title="Edit product" style="font-size:12px; padding:5px 10px;">
                                        <i class="fa-solid fa-gear"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- Stock Edit Modal (incremental) -->
        <div id="stockEditModal" style="display:none; position:fixed; inset:0; z-index:9000; background:rgba(0,0,0,0.6); align-items:center; justify-content:center;">
            <div style="background:var(--bg-surface); border-radius:var(--radius-lg); padding:32px 36px; min-width:340px; max-width:420px; width:90%; box-shadow:0 20px 60px rgba(0,0,0,0.4); position:relative;">
                <button onclick="closeStockEdit()" style="position:absolute; top:14px; right:16px; background:none; border:none; font-size:20px; color:var(--text-muted); cursor:pointer;">&#x2715;</button>
                <h3 id="stockEditTitle" style="margin:0 0 6px; font-size:17px;">Edit Stock</h3>
                <p id="stockEditSubtitle" style="font-size:12px; color:var(--text-muted); margin:0 0 22px;">All values are additive — enter 0 to skip a field.</p>

                <div class="form-group" style="margin-bottom:16px;">
                    <label class="form-label" style="color:#8b5cf6;">📦 Add New Stock</label>
                    <input type="number" id="stockAddQty" class="form-control" min="0" value="0"
                           style="font-size:16px; font-weight:700; text-align:center;"
                           placeholder="e.g. 50">
                    <small style="font-size:11px; color:var(--text-muted); margin-top:4px; display:block;">Adds to Grand Total &rarr; increases In Stock</small>
                </div>

                <div class="form-group" style="margin-bottom:16px;">
                    <label class="form-label" style="color:#ef4444;">⚠️ Add Damage Qty</label>
                    <input type="number" id="stockDamageQty" class="form-control" min="0" value="0"
                           style="font-size:16px; font-weight:700; text-align:center;"
                           placeholder="e.g. 5">
                    <small style="font-size:11px; color:var(--text-muted); margin-top:4px; display:block;">Adds to Damage &rarr; reduces In Stock</small>
                </div>

                <div class="form-group" style="margin-bottom:20px;">
                    <label class="form-label" style="color:#f59e0b;">🏪 Add Sold Offline Qty</label>
                    <input type="number" id="stockOfflineQty" class="form-control" min="0" value="0"
                           style="font-size:16px; font-weight:700; text-align:center;"
                           placeholder="e.g. 10">
                    <small style="font-size:11px; color:var(--text-muted); margin-top:4px; display:block;">Adds to Sold Offline &rarr; reduces In Stock</small>
                </div>

                <div id="stockEditMsg" style="font-size:13px; margin-bottom:12px; min-height:18px;"></div>
                <div style="display:flex; gap:12px;">
                    <button class="btn-primary" onclick="saveStockEdit()" style="flex:1;"><i class="fa-solid fa-check"></i> Save</button>
                    <button class="btn-secondary" onclick="closeStockEdit()" style="flex:1;">Cancel</button>
                </div>
            </div>
        </div>

        <!-- ═══════════════════ REVENUE TAB ═══════════════════ -->
        <?php elseif ($activeTab === 'revenue'): ?>
        <?php
            $rFrom = $revData['from'] ?? date('Y-m-01');
            $rTo   = $revData['to']   ?? date('Y-m-d');
        ?>
        <!-- Summary Cards -->
        <div style="display:grid; grid-template-columns:repeat(4,1fr); gap:16px; margin-bottom:28px;">
            <div class="stat-card glass-panel" style="border-left:4px solid var(--color-primary);">
                <div class="stat-card-icon">💷</div>
                <div class="stat-label">Total Revenue</div>
                <div class="stat-value" style="font-size:22px; color:var(--color-primary);">£<?= number_format($revData['total'] ?? 0, 2) ?></div>
            </div>
            <div class="stat-card glass-panel" style="border-left:4px solid #10b981;">
                <div class="stat-card-icon">💳</div>
                <div class="stat-label">Online (Card)</div>
                <div class="stat-value" style="font-size:22px; color:#10b981;">£<?= number_format($revData['online'] ?? 0, 2) ?></div>
            </div>
            <div class="stat-card glass-panel" style="border-left:4px solid #f59e0b;">
                <div class="stat-card-icon">💵</div>
                <div class="stat-label">Cash</div>
                <div class="stat-value" style="font-size:22px; color:#f59e0b;">£<?= number_format($revData['cash'] ?? 0, 2) ?></div>
            </div>
            <div class="stat-card glass-panel" style="border-left:4px solid #ef4444;">
                <div class="stat-card-icon">⏳</div>
                <div class="stat-label">Unpaid (<?= $revData['unpaid_count'] ?? 0 ?> orders)</div>
                <div class="stat-value" style="font-size:22px; color:#ef4444;">£<?= number_format($revData['unpaid_total'] ?? 0, 2) ?></div>
            </div>
        </div>

        <!-- Date Filters + Download -->
        <div class="glass-panel" style="padding:20px 24px; margin-bottom:24px;">
            <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
                <span style="font-size:13px; font-weight:700; color:var(--text-secondary); white-space:nowrap;"><i class="fa-solid fa-calendar"></i> Filter:</span>
                <?php
                $today      = date('Y-m-d');
                $thisMonthStart = date('Y-m-01');
                $lastMonthStart = date('Y-m-01', strtotime('first day of last month'));
                $lastMonthEnd   = date('Y-m-t', strtotime('last day of last month'));
                $weekStart  = date('Y-m-d', strtotime('monday this week'));
                $yearStart  = date('Y-01-01');
                $quickBtns  = [
                    'Today'      => [$today,          $today],
                    'This Week'  => [$weekStart,       $today],
                    'This Month' => [$thisMonthStart,  $today],
                    'Last Month' => [$lastMonthStart,  $lastMonthEnd],
                    'This Year'  => [$yearStart,       $today],
                ];
                foreach ($quickBtns as $label => [$f, $t]):
                    $active = ($rFrom === $f && $rTo === $t);
                ?>
                <a href="?tab=revenue&rev_from=<?= $f ?>&rev_to=<?= $t ?>"
                   class="btn-sm <?= $active ? 'btn-primary' : 'btn-sm-outline' ?>"><?= $label ?></a>
                <?php endforeach; ?>

                <form method="GET" action="index.php" style="display:flex; align-items:center; gap:8px; margin-left:auto; flex-wrap:wrap;">
                    <input type="hidden" name="tab" value="revenue">
                    <input type="date" name="rev_from" value="<?= $rFrom ?>" class="form-control" style="height:36px; font-size:13px; width:150px;">
                    <span style="color:var(--text-muted); font-size:13px;">to</span>
                    <input type="date" name="rev_to"   value="<?= $rTo ?>"   class="form-control" style="height:36px; font-size:13px; width:150px;">
                    <button type="submit" class="btn-primary" style="height:36px; padding:0 16px; font-size:13px;"><i class="fa-solid fa-filter"></i> Apply</button>
                </form>

                <a href="revenue_report.php?from=<?= $rFrom ?>&to=<?= $rTo ?>" class="btn-sm btn-sm-outline" style="white-space:nowrap;">
                    <i class="fa-solid fa-download"></i> Download CSV
                </a>
            </div>
        </div>

        <div style="display:grid; grid-template-columns:1fr 1fr; gap:24px; margin-bottom:24px;">
            <!-- Category Revenue Table -->
            <div class="glass-panel" style="padding:24px;">
                <h3 style="font-size:15px; font-weight:700; margin:0 0 16px; display:flex; align-items:center; gap:8px;">
                    <i class="fa-solid fa-tags" style="color:var(--color-primary);"></i> Revenue by Category
                    <span style="font-size:11px; color:var(--text-muted); font-weight:400;"><?= date('d M', strtotime($rFrom)) ?> – <?= date('d M Y', strtotime($rTo)) ?></span>
                </h3>
                <?php if (empty($revData['cat_revenue'])): ?>
                <p style="color:var(--text-muted); font-size:13px;">No paid orders in this period.</p>
                <?php else: ?>
                <table style="width:100%; border-collapse:collapse; font-size:13px;">
                    <thead>
                        <tr style="border-bottom:2px solid var(--border-light);">
                            <th style="padding:6px 0; text-align:left; font-size:11px; text-transform:uppercase; color:var(--text-muted);">Category</th>
                            <th style="padding:6px 0; text-align:right; font-size:11px; text-transform:uppercase; color:var(--text-muted);">Revenue</th>
                            <th style="padding:6px 0; text-align:right; font-size:11px; text-transform:uppercase; color:var(--text-muted);">Qty Sold</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                        $totalCatRev = array_sum(array_column($revData['cat_revenue'], 'revenue'));
                        foreach ($revData['cat_revenue'] as $cat => $cdata):
                            $pct = $totalCatRev > 0 ? round($cdata['revenue'] / $totalCatRev * 100) : 0;
                    ?>
                        <tr style="border-bottom:1px solid var(--border-light);">
                            <td style="padding:9px 0;">
                                <div style="font-weight:600;"><?= htmlspecialchars($cat) ?></div>
                                <div style="height:4px; background:rgba(var(--color-primary-rgb),0.15); border-radius:2px; margin-top:4px; width:100%;">
                                    <div style="height:4px; background:var(--color-primary); border-radius:2px; width:<?= $pct ?>%;"></div>
                                </div>
                            </td>
                            <td style="padding:9px 0; text-align:right; font-weight:700; color:var(--color-primary);">£<?= number_format($cdata['revenue'], 2) ?></td>
                            <td style="padding:9px 0; text-align:right; color:var(--text-secondary);"><?= $cdata['qty'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>

            <!-- Charts column -->
            <div style="display:flex; flex-direction:column; gap:20px;">
                <!-- Chart 1: All-time -->
                <div class="glass-panel" style="padding:20px;">
                    <h3 style="font-size:14px; font-weight:700; margin:0 0 12px; display:flex; align-items:center; gap:8px;">
                        <i class="fa-solid fa-chart-bar" style="color:#8b5cf6;"></i> All-time Top Products (by qty sold)
                    </h3>
                    <?php if (empty($revData['chart_alltime'])): ?>
                    <p style="color:var(--text-muted); font-size:13px;">No data yet.</p>
                    <?php else: ?>
                    <canvas id="chartAllTime" height="180"></canvas>
                    <?php endif; ?>
                </div>
                <!-- Chart 2: This month -->
                <div class="glass-panel" style="padding:20px;">
                    <h3 style="font-size:14px; font-weight:700; margin:0 0 12px; display:flex; align-items:center; gap:8px;">
                        <i class="fa-solid fa-chart-bar" style="color:#10b981;"></i> This Month's Products (by qty sold)
                    </h3>
                    <?php if (empty($revData['chart_thismonth'])): ?>
                    <p style="color:var(--text-muted); font-size:13px;">No sales this month yet.</p>
                    <?php else: ?>
                    <canvas id="chartThisMonth" height="180"></canvas>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ═══════════════════ GALLERY TAB ══════════════════ -->
        <?php elseif ($activeTab === 'gallery'): ?>
        <div class="glass-panel" style="padding:28px;">

            <!-- Upload zone -->
            <h3 style="font-size:16px; margin-bottom:16px; display:flex; align-items:center; gap:8px; color:var(--color-primary);">
                <i class="fa-solid fa-cloud-arrow-up"></i> Upload New Photo
            </h3>

            <div id="galleryUploadResult"></div>

            <form id="galleryUploadForm" style="margin-bottom:32px;">
                <div class="gallery-upload-zone" id="galleryDropZone">
                    <input type="file" name="gallery_image" id="galleryFileInput"
                           accept="image/jpeg,image/png,image/webp,image/gif"
                           onchange="triggerGalleryUpload(this)">
                    <div style="font-size:36px; margin-bottom:10px; opacity:0.5;">📸</div>
                    <p style="font-size:13px; color:var(--text-secondary);">
                        <strong style="color:var(--color-primary);">Click to upload</strong> or drag & drop<br>
                        JPG, PNG, WebP, or GIF — max 8MB
                    </p>
                </div>
                <div style="display:flex; gap:12px; align-items:center; margin-top:12px;">
                    <input type="text" id="galleryCaption" placeholder="Optional caption…"
                           class="form-control" style="flex:1; max-width:380px;">
                </div>
            </form>

            <h3 style="font-size:16px; margin-bottom:16px; display:flex; align-items:center; gap:8px; color:var(--color-primary);">
                <i class="fa-solid fa-images"></i> Gallery Photos (<?= count($galleryItems) ?>)
            </h3>

            <div class="admin-gallery-grid" id="adminGalleryGrid">
                <?php foreach ($galleryItems as $gimg): ?>
                <div class="admin-gallery-item" id="gitem-<?= $gimg['id'] ?>">
                    <img src="../assets/images/gallery/<?= htmlspecialchars($gimg['filename']) ?>"
                         alt="<?= htmlspecialchars($gimg['caption'] ?: 'Gallery') ?>">
                    <div class="admin-gallery-item-meta">
                        <div class="admin-gallery-item-caption"><?= htmlspecialchars($gimg['caption'] ?: '—') ?></div>
                    </div>
                    <button class="admin-gallery-del" onclick="deleteGalleryItem(<?= $gimg['id'] ?>)" title="Delete photo">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
                <?php endforeach; ?>
                <?php if (empty($galleryItems)): ?>
                <div id="galleryEmptyMsg" style="grid-column:1/-1; text-align:center; padding:40px; color:var(--text-muted);">
                    No photos yet. Upload your first one above!
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ═══════════════════ CATEGORIES TAB ══════════════ -->
        <?php elseif ($activeTab === 'categories'): ?>
        <div class="glass-panel" style="padding:28px;">

            <!-- Add category form -->
            <h3 style="font-size:16px; margin-bottom:16px; display:flex; align-items:center; gap:8px; color:var(--color-primary);">
                <i class="fa-solid fa-plus"></i> Add New Category
            </h3>
            <div id="catFormMsg" style="margin-bottom:12px;"></div>
            <div style="display:flex; gap:12px; margin-bottom:32px; align-items:flex-start;">
                <input type="text" id="newCatName" placeholder="Category name e.g. Sorbets"
                       class="form-control" style="max-width:300px;">
                <button class="btn-primary" onclick="addCategory()" style="white-space:nowrap;">
                    <i class="fa-solid fa-plus"></i> Add Category
                </button>
            </div>

            <h3 style="font-size:16px; margin-bottom:16px; display:flex; align-items:center; gap:8px; color:var(--color-primary);">
                <i class="fa-solid fa-list"></i> Current Categories
            </h3>

            <!-- Category Search & Sort Bar -->
            <div style="display:flex; justify-content:space-between; align-items:center; gap:16px; margin-bottom:20px; flex-wrap:wrap; background:rgba(255,255,255,0.05); padding:14px; border-radius:var(--radius-sm); border:1px solid var(--border-light);">
                <div style="display:flex; align-items:center; gap:8px; flex:1; min-width:200px;">
                    <i class="fa-solid fa-magnifying-glass" style="color:var(--text-muted); font-size:14px; margin-left:4px;"></i>
                    <input type="text" id="catSearch" placeholder="Search categories..." class="form-control" style="font-size:13px; padding:6px 12px; height:auto; background:var(--bg-main);" oninput="filterAndSortCategories()">
                </div>
                
                <div style="display:flex; align-items:center; gap:8px;">
                    <label for="catSort" style="font-size:12px; font-weight:700; color:var(--text-secondary); text-transform:uppercase; letter-spacing:0.5px; white-space:nowrap;">Sort By:</label>
                    <select id="catSort" class="form-control" style="font-size:13px; padding:6px 12px; height:auto; width:auto; min-width:160px; background:var(--bg-main);" onchange="filterAndSortCategories()">
                        <option value="sort_order">Default (Sort Order)</option>
                        <option value="name_asc">Name (A-Z)</option>
                        <option value="name_desc">Name (Z-A)</option>
                    </select>
                </div>
            </div>

            <div class="glass-panel" style="padding:0; overflow:hidden;" id="catListContainer">
                <?php foreach ($catList as $cat): ?>
                <div class="cat-list-item" id="catrow-<?= $cat['id'] ?>" data-order="<?= (int)$cat['sort_order'] ?>">
                    <div style="display:flex; align-items:center; gap:12px;">
                        <i class="fa-solid fa-grip-vertical" style="color:var(--text-muted); font-size:13px;"></i>
                        <span class="cat-name" id="catname-<?= $cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></span>
                    </div>
                    <div class="action-group">
                        <button class="btn-sm btn-sm-outline" onclick="startRename(<?= $cat['id'] ?>, '<?= addslashes($cat['name']) ?>')">
                            <i class="fa-solid fa-pen"></i> Rename
                        </button>
                        <button class="btn-sm btn-sm-danger" onclick="deleteCategory(<?= $cat['id'] ?>, '<?= addslashes($cat['name']) ?>')">
                            <i class="fa-solid fa-trash"></i>
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if (empty($catList)): ?>
                <div style="padding:32px; text-align:center; color:var(--text-muted);">No categories yet.</div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- ═══════════════════ PROMOS TAB ════════════════════ -->
        <?php if ($activeTab === 'promos'): ?>
        <div class="glass-panel" style="padding:28px;">
            <div class="admin-page-header" style="margin-bottom:24px;">
                <div>
                    <h2 class="admin-page-title" style="margin:0;">🎟️ Promo Codes</h2>
                    <p class="admin-page-subtitle" style="margin-top:4px;">Create and manage discount codes for your customers</p>
                </div>
            </div>

            <!-- Create new promo code -->
            <div style="background:var(--bg-main); border-radius:var(--radius-md); padding:24px; border:1px solid var(--border-light); margin-bottom:28px;">
                <h3 style="margin:0 0 18px; font-size:15px; display:flex; align-items:center; gap:8px;">
                    <i class="fa-solid fa-plus-circle" style="color:var(--color-secondary);"></i> Create New Promo Code
                </h3>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:14px;">
                    <div class="form-group" style="margin:0;">
                        <label class="form-label">Code *</label>
                        <input type="text" id="promoCode" class="form-control" placeholder="e.g. SUMMER20" style="text-transform:uppercase;" oninput="this.value=this.value.toUpperCase()">
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label class="form-label">Description (shown to customer)</label>
                        <input type="text" id="promoDesc" class="form-control" placeholder="e.g. Summer sale 20% off">
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label class="form-label">Discount Type *</label>
                        <select id="promoType" class="form-control">
                            <option value="percentage">Percentage (e.g. 10%)</option>
                            <option value="fixed">Fixed Amount (e.g. £5 off)</option>
                        </select>
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label class="form-label">Discount Value * <span style="color:var(--text-muted); font-size:11px;">(% or £)</span></label>
                        <input type="number" id="promoValue" class="form-control" placeholder="e.g. 10" min="0.01" step="0.01">
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label class="form-label">Minimum Order (£) <span style="color:var(--text-muted); font-size:11px;">optional</span></label>
                        <input type="number" id="promoMin" class="form-control" placeholder="0.00" min="0" step="0.01">
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label class="form-label">Max Uses <span style="color:var(--text-muted); font-size:11px;">optional — leave blank for unlimited</span></label>
                        <input type="number" id="promoMax" class="form-control" placeholder="Unlimited" min="1">
                    </div>
                    <div class="form-group" style="margin:0;">
                        <label class="form-label">Expires On <span style="color:var(--text-muted); font-size:11px;">optional</span></label>
                        <input type="date" id="promoExpires" class="form-control">
                    </div>
                    <div class="form-group" style="margin:0; display:flex; align-items:flex-end;">
                        <label style="display:flex; align-items:center; gap:8px; cursor:pointer; font-size:14px;">
                            <input type="checkbox" id="promoActive" checked style="width:16px; height:16px;">
                            Active (available immediately)
                        </label>
                    </div>
                </div>
                <div style="margin-top:18px;">
                    <button class="btn-primary" onclick="createPromo()" style="padding:11px 24px;">
                        <i class="fa-solid fa-plus"></i> Create Promo Code
                    </button>
                </div>
            </div>

            <!-- Promo codes list -->
            <?php if (empty($promoCodes)): ?>
            <div style="text-align:center; padding:40px 20px; color:var(--text-muted);">
                <div style="font-size:48px; margin-bottom:12px; opacity:0.3;">🎟️</div>
                <p>No promo codes yet. Create your first one above!</p>
            </div>
            <?php else: ?>
            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Type</th>
                            <th>Value</th>
                            <th>Min Order</th>
                            <th>Uses</th>
                            <th>Expires</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($promoCodes as $p): ?>
                        <tr id="promorow-<?= $p['id'] ?>">
                            <td>
                                <code style="font-size:14px; font-weight:800; color:var(--color-primary); background:var(--color-primary-bg); padding:3px 10px; border-radius:6px; letter-spacing:1px;"><?= htmlspecialchars($p['code']) ?></code>
                                <?php if (!empty($p['description'])): ?>
                                <div style="font-size:11px; color:var(--text-muted); margin-top:4px;"><?= htmlspecialchars($p['description']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= $p['discount_type'] === 'percentage' ? '<i class="fa-solid fa-percent"></i> Percentage' : '<i class="fa-solid fa-sterling-sign"></i> Fixed' ?></td>
                            <td style="font-weight:700; color:var(--color-secondary);">
                                <?= $p['discount_type'] === 'percentage' ? (int)$p['discount_value'] . '%' : '£' . number_format($p['discount_value'], 2) ?>
                            </td>
                            <td><?= $p['min_order'] > 0 ? '£' . number_format($p['min_order'], 2) : '<span style="color:var(--text-muted);">—</span>' ?></td>
                            <td>
                                <?= $p['uses_count'] ?>
                                <?php if (!is_null($p['max_uses'])): ?>
                                / <?= $p['max_uses'] ?>
                                <?php else: ?>
                                <span style="color:var(--text-muted); font-size:11px;">/ ∞</span>
                                <?php endif; ?>
                            </td>
                            <td><?= !empty($p['expires_at']) ? date('d M Y', strtotime($p['expires_at'])) : '<span style="color:var(--text-muted);">Never</span>' ?></td>
                            <td>
                                <span id="promo-badge-<?= $p['id'] ?>" style="font-size:11px; font-weight:700; padding:3px 10px; border-radius:20px;
                                    <?= $p['active'] ? 'background:rgba(16,185,129,0.15); color:#10b981;' : 'background:rgba(100,100,100,0.12); color:var(--text-muted);' ?>">
                                    <?= $p['active'] ? 'Active' : 'Disabled' ?>
                                </span>
                            </td>
                            <td>
                                <div style="display:flex; gap:8px; flex-wrap:wrap;">
                                    <button id="promo-toggle-<?= $p['id'] ?>" class="btn-secondary" style="padding:6px 12px; font-size:12px;" onclick="togglePromo(<?= $p['id'] ?>)">
                                        <i class="fa-solid fa-toggle-<?= $p['active'] ? 'on' : 'off' ?>"></i>
                                        <?= $p['active'] ? 'Disable' : 'Enable' ?>
                                    </button>
                                    <button class="btn-danger" style="padding:6px 12px; font-size:12px;" onclick="deletePromo(<?= $p['id'] ?>, '<?= htmlspecialchars($p['code']) ?>')">
                                        <i class="fa-solid fa-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- ══════════════ INQUIRIES TAB ══════════════════════ -->
        <?php if ($activeTab === 'inquiries'): ?>
        <div class="glass-panel" style="padding:28px;">
            <div class="admin-page-header" style="margin-bottom:24px;">
                <div>
                    <h2 class="admin-page-title" style="margin:0;">📬 Customer Inquiries</h2>
                    <p class="admin-page-subtitle" style="margin-top:4px;">Messages submitted via the About / Contact page</p>
                </div>
                <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
                    <span style="font-size:13px; color:var(--text-muted);">
                        Total: <strong><?= count($inquiries) ?></strong>
                    </span>
                    <a href="index.php?tab=inquiries&delete_inquiry=all"
                       onclick="return confirm('Delete ALL inquiries? This cannot be undone.')"
                       class="btn-danger" style="font-size:12px; padding:7px 14px; display:inline-flex; align-items:center; gap:6px;">
                        <i class="fa-solid fa-trash"></i> Clear All
                    </a>
                </div>
            </div>

            <?php
            // ── Handle delete inquiry ─────────────────────────
            if (isset($_GET['delete_inquiry'])) {
                try {
                    if ($_GET['delete_inquiry'] === 'all') {
                        $pdo->exec("DELETE FROM inquiries");
                        $inquiries = [];
                        echo '<div class="alert alert-success" style="margin-bottom:20px;"><i class="fa-solid fa-check-circle"></i><div>All inquiries deleted.</div></div>';
                    } else {
                        $delId = (int)$_GET['delete_inquiry'];
                        $pdo->prepare("DELETE FROM inquiries WHERE id = :id")->execute(['id' => $delId]);
                        $inquiries = $pdo->query("SELECT * FROM inquiries ORDER BY created_at DESC")->fetchAll();
                        echo '<div class="alert alert-success" style="margin-bottom:20px;"><i class="fa-solid fa-check-circle"></i><div>Inquiry deleted.</div></div>';
                    }
                } catch (PDOException $e) {
                    echo '<div class="alert alert-danger" style="margin-bottom:20px;"><i class="fa-solid fa-triangle-exclamation"></i><div>Could not delete: ' . htmlspecialchars($e->getMessage()) . '</div></div>';
                }
            }
            ?>

            <?php if (empty($inquiries)): ?>
            <div style="text-align:center; padding:60px 20px; color:var(--text-muted);">
                <div style="font-size:64px; margin-bottom:16px; opacity:0.3;">📭</div>
                <p style="font-size:16px;">No inquiries yet. They'll appear here once customers submit the contact form.</p>
            </div>
            <?php else: ?>
            <div style="display:flex; flex-direction:column; gap:16px;">
                <?php foreach ($inquiries as $inq): ?>
                <?php $isNew = !$inq['is_read']; ?>
                <div style="background:var(--bg-main); border-radius:var(--radius-md); padding:20px 24px;
                            border:1px solid <?= $isNew ? 'var(--color-primary)' : 'var(--border-light)' ?>;
                            position:relative;">
                    <?php if ($isNew): ?>
                    <span style="position:absolute; top:14px; right:60px; background:var(--color-primary); color:white;
                                 font-size:10px; font-weight:700; padding:2px 8px; border-radius:20px; letter-spacing:.5px;">NEW</span>
                    <?php endif; ?>
                    <a href="index.php?tab=inquiries&delete_inquiry=<?= $inq['id'] ?>"
                       onclick="return confirm('Delete this inquiry?')"
                       style="position:absolute; top:14px; right:16px; color:var(--text-muted); font-size:13px;"
                       title="Delete">
                        <i class="fa-solid fa-trash-can"></i>
                    </a>

                    <!-- Header row -->
                    <div style="display:flex; align-items:center; gap:14px; margin-bottom:14px; flex-wrap:wrap;">
                        <div style="width:40px; height:40px; border-radius:50%; background:linear-gradient(135deg,var(--color-primary),var(--color-secondary));
                                    display:flex; align-items:center; justify-content:center; color:white; font-weight:700; font-size:16px; flex-shrink:0;">
                            <?= strtoupper(mb_substr($inq['name'], 0, 1)) ?>
                        </div>
                        <div>
                            <div style="font-weight:700; font-size:15px;"><?= htmlspecialchars($inq['name']) ?></div>
                            <div style="font-size:12px; color:var(--text-muted);">
                                <?= date('d M Y, H:i', strtotime($inq['created_at'])) ?>
                            </div>
                        </div>
                    </div>

                    <!-- Contact details -->
                    <div style="display:flex; gap:20px; flex-wrap:wrap; margin-bottom:14px; font-size:13px;">
                        <span style="display:flex; align-items:center; gap:6px; color:var(--text-secondary);">
                            <i class="fa-solid fa-envelope" style="color:var(--color-primary);"></i>
                            <a href="mailto:<?= htmlspecialchars($inq['email']) ?>" style="color:inherit; text-decoration:none;">
                                <?= htmlspecialchars($inq['email']) ?>
                            </a>
                        </span>
                        <?php if (!empty($inq['phone'])): ?>
                        <span style="display:flex; align-items:center; gap:6px; color:var(--text-secondary);">
                            <i class="fa-solid fa-phone" style="color:var(--color-secondary);"></i>
                            <a href="tel:<?= htmlspecialchars($inq['phone']) ?>" style="color:inherit; text-decoration:none;">
                                <?= htmlspecialchars($inq['phone']) ?>
                            </a>
                        </span>
                        <?php endif; ?>
                    </div>

                    <!-- Message -->
                    <div style="background:var(--bg-card); border-radius:var(--radius-sm); padding:14px 16px;
                                font-size:14px; line-height:1.7; color:var(--text-primary); border-left:3px solid var(--color-primary);">
                        <?= nl2br(htmlspecialchars($inq['message'])) ?>
                    </div>

                    <!-- Quick reply link -->
                    <div style="margin-top:12px;">
                        <a href="mailto:<?= htmlspecialchars($inq['email']) ?>?subject=Re: Your Enquiry – Creamy Bite"
                           class="btn-primary" style="font-size:12px; padding:7px 14px; display:inline-flex; align-items:center; gap:6px;">
                            <i class="fa-solid fa-reply"></i> Reply via Email
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

    </div>
</main>

<footer class="footer">
    <div class="container footer-inner">
        <span class="footer-logo">🍦 <?= SHOP_NAME ?> Admin</span>
        <span class="footer-copy">© <?= date('Y') ?> <?= SHOP_NAME ?>. All rights reserved.</span>
    </div>
</footer>

<script>
// ── Orders ──────────────────────────────────────────────────
function toggleDetail(id) {
    const row  = document.getElementById('detail-' + id);
    const icon = document.getElementById('icon-' + id);
    const isOpen = row.style.display === 'table-row';
    row.style.display  = isOpen ? 'none' : 'table-row';
    icon.style.transform = isOpen ? 'rotate(0)' : 'rotate(180deg)';
    icon.style.transition = 'transform 0.3s ease';
}

function updateStatus(orderId, orderCode) {
    const status = document.getElementById('status-' + orderId).value;
    const msgEl  = document.getElementById('status-msg-' + orderId);

    fetch('update_order.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'order_id=' + orderId + '&status=' + encodeURIComponent(status),
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            msgEl.textContent = '✅ Saved!';
            const badgeMap = { 'Pending':'status-pending','Processing':'status-processing','Delivered':'status-delivered','Cancelled':'status-cancelled' };
            const mainRow = document.getElementById('row-' + orderId);
            const badge = mainRow.querySelector('.status-badge');
            if (badge) { badge.textContent = status; badge.className = 'status-badge ' + (badgeMap[status] || ''); }
            setTimeout(() => msgEl.textContent = '', 3000);
        } else {
            msgEl.textContent = '❌ Failed to save.'; msgEl.style.color = 'var(--color-danger)';
        }
    })
    .catch(() => { msgEl.textContent = '❌ Network error.'; msgEl.style.color = 'var(--color-danger)'; });
}

function updatePaymentStatus(orderId) {
    const ps    = document.getElementById('pstatus-' + orderId).value;
    const msgEl = document.getElementById('status-msg-' + orderId);

    fetch('update_order.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'order_id=' + orderId + '&payment_status=' + encodeURIComponent(ps),
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            msgEl.textContent = '✅ Payment updated!';

            // Update the payment badge in the main row
            const badge = document.getElementById('pay-badge-' + orderId);
            if (badge) {
                const map = {
                    'Paid':   { icon: '<i class="fa-solid fa-circle-check" style="color:#10b981;"></i>', label: 'Paid Online',     color: '#10b981', bg: 'rgba(16,185,129,0.1)' },
                    'Cash':   { icon: '<i class="fa-solid fa-money-bill-wave" style="color:#f59e0b;"></i>', label: 'Cash Received', color: '#f59e0b', bg: 'rgba(245,158,11,0.1)' },
                    'Unpaid': { icon: '<i class="fa-solid fa-clock" style="color:var(--text-muted);"></i>', label: 'Not Paid',     color: 'var(--text-muted)', bg: 'rgba(100,100,100,0.08)' },
                };
                const m = map[ps] || map['Unpaid'];
                badge.innerHTML = m.icon + ' ' + m.label;
                badge.style.color      = m.color;
                badge.style.background = m.bg;
            }

            const mainRow = document.getElementById('row-' + orderId);
            if (mainRow) {
                mainRow.setAttribute('data-payment-status', ps);
            }
            if (typeof sortOrders === 'function') {
                sortOrders();
            }

            setTimeout(() => msgEl.textContent = '', 3000);
        } else {
            msgEl.textContent = '❌ Failed.'; msgEl.style.color = 'var(--color-danger)';
        }
    })
    .catch(() => { msgEl.textContent = '❌ Network error.'; msgEl.style.color = 'var(--color-danger)'; });
}

// ── Gallery ─────────────────────────────────────────────────
function triggerGalleryUpload(input) {
    if (!input.files || !input.files[0]) return;
    const caption = document.getElementById('galleryCaption').value;
    const formData = new FormData();
    formData.append('action', 'upload');
    formData.append('gallery_image', input.files[0]);
    formData.append('caption', caption);

    const resultEl = document.getElementById('galleryUploadResult');
    resultEl.innerHTML = '<div class="alert alert-info"><i class="fa-solid fa-spinner fa-spin"></i> Uploading…</div>';

    fetch('gallery_handler.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            resultEl.innerHTML = '<div class="alert alert-success"><i class="fa-solid fa-circle-check"></i> Photo uploaded!</div>';
            setTimeout(() => resultEl.innerHTML = '', 3000);

            // Add to grid
            const emptyMsg = document.getElementById('galleryEmptyMsg');
            if (emptyMsg) emptyMsg.remove();
            const grid = document.getElementById('adminGalleryGrid');
            const div = document.createElement('div');
            div.className = 'admin-gallery-item';
            div.id = 'gitem-' + data.id;
            div.innerHTML = `
                <img src="../assets/images/gallery/${data.filename}" alt="${data.caption || 'Gallery'}">
                <div class="admin-gallery-item-meta">
                    <div class="admin-gallery-item-caption">${data.caption || '—'}</div>
                </div>
                <button class="admin-gallery-del" onclick="deleteGalleryItem(${data.id})" title="Delete">
                    <i class="fa-solid fa-xmark"></i>
                </button>`;
            grid.prepend(div);

            // Reset form
            document.getElementById('galleryCaption').value = '';
            input.value = '';
        } else {
            resultEl.innerHTML = `<div class="alert alert-danger"><i class="fa-solid fa-triangle-exclamation"></i> ${data.message}</div>`;
        }
    })
    .catch(() => {
        resultEl.innerHTML = '<div class="alert alert-danger">Upload failed. Please try again.</div>';
    });
}

function deleteGalleryItem(id) {
    if (!confirm('Delete this photo? This cannot be undone.')) return;
    fetch('gallery_handler.php?action=delete&id=' + id)
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const el = document.getElementById('gitem-' + id);
            if (el) { el.style.opacity = '0'; el.style.transition = 'opacity 0.3s'; setTimeout(() => el.remove(), 300); }
        } else {
            alert('Failed to delete: ' + data.message);
        }
    });
}

// ── Categories ──────────────────────────────────────────────
const catMsgEl = document.getElementById('catFormMsg');

function addCategory() {
    const name = document.getElementById('newCatName').value.trim();
    if (!name) { showCatMsg('Please enter a category name.', 'danger'); return; }

    fetch('category_handler.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=add&name=' + encodeURIComponent(name),
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            showCatMsg('✅ Category added!', 'success');
            document.getElementById('newCatName').value = '';
            const container = document.getElementById('catListContainer');
            const div = document.createElement('div');
            div.className = 'cat-list-item';
            div.id = 'catrow-' + data.id;
            div.setAttribute('data-order', data.sort_order || 999);
            div.innerHTML = `
                <div style="display:flex;align-items:center;gap:12px;">
                    <i class="fa-solid fa-grip-vertical" style="color:var(--text-muted);font-size:13px;"></i>
                    <span class="cat-name" id="catname-${data.id}">${escHtml(data.name)}</span>
                </div>
                <div class="action-group">
                    <button class="btn-sm btn-sm-outline" onclick="startRename(${data.id},'${data.name.replace(/'/g,"\\'")}')">
                        <i class="fa-solid fa-pen"></i> Rename
                    </button>
                    <button class="btn-sm btn-sm-danger" onclick="deleteCategory(${data.id},'${data.name.replace(/'/g,"\\'")}')">
                        <i class="fa-solid fa-trash"></i>
                    </button>
                </div>`;
            container.appendChild(div);
            if (typeof filterAndSortCategories === 'function') {
                filterAndSortCategories();
            }
        } else {
            showCatMsg(data.message, 'danger');
        }
    });
}

function startRename(id, currentName) {
    const newName = prompt('Rename category:', currentName);
    if (!newName || newName.trim() === currentName) return;

    fetch('category_handler.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=rename&id=' + id + '&name=' + encodeURIComponent(newName.trim()),
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            document.getElementById('catname-' + id).textContent = newName.trim();
            showCatMsg('✅ Renamed!', 'success');
        } else {
            alert('Failed: ' + data.message);
        }
    });
}

function deleteCategory(id, name) {
    if (!confirm('Delete category "' + name + '"? Products using it will still exist.')) return;
    fetch('category_handler.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=delete&id=' + id,
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const el = document.getElementById('catrow-' + id);
            if (el) { el.style.opacity = '0'; el.style.transition = 'opacity 0.3s'; setTimeout(() => el.remove(), 300); }
        } else {
            alert(data.message);
        }
    });
}

function showCatMsg(msg, type) {
    if (!catMsgEl) return;
    catMsgEl.innerHTML = `<div class="alert alert-${type}" style="margin-bottom:12px;">${msg}</div>`;
    setTimeout(() => { catMsgEl.innerHTML = ''; }, 3500);
}

function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ═══════════════ PROMO CODES JS ═══════════════════════════
function createPromo() {
    const code  = document.getElementById('promoCode').value.trim().toUpperCase();
    const desc  = document.getElementById('promoDesc').value.trim();
    const type  = document.getElementById('promoType').value;
    const value = document.getElementById('promoValue').value;
    const min   = document.getElementById('promoMin').value || 0;
    const maxU  = document.getElementById('promoMax').value;
    const exp   = document.getElementById('promoExpires').value;
    const active = document.getElementById('promoActive').checked ? 1 : 0;

    if (!code || !value) { alert('Code and discount value are required.'); return; }

    fetch('../promo_handler.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: `action=create&code=${encodeURIComponent(code)}&description=${encodeURIComponent(desc)}&discount_type=${type}&discount_value=${value}&min_order=${min}&max_uses=${maxU}&expires_at=${exp}&active=${active}`,
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) { alert(data.message || 'Error'); return; }
        location.reload();
    });
}

function togglePromo(id) {
    fetch('../promo_handler.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: `action=toggle&id=${id}`,
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const badge = document.getElementById('promo-badge-' + id);
            const btn   = document.getElementById('promo-toggle-' + id);
            if (data.active) {
                badge.textContent = 'Active'; badge.style.background = 'rgba(16,185,129,0.15)'; badge.style.color = '#10b981';
                btn.innerHTML = '<i class="fa-solid fa-toggle-on"></i> Disable';
            } else {
                badge.textContent = 'Disabled'; badge.style.background = 'rgba(100,100,100,0.12)'; badge.style.color = 'var(--text-muted)';
                btn.innerHTML = '<i class="fa-solid fa-toggle-off"></i> Enable';
            }
        }
    });
}

function deletePromo(id, code) {
    if (!confirm(`Delete promo code "${code}"? This cannot be undone.`)) return;
    fetch('../promo_handler.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: `action=delete&id=${id}`,
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const row = document.getElementById('promorow-' + id);
            if (row) { row.style.opacity = '0'; row.style.transition = 'opacity 0.3s'; setTimeout(() => row.remove(), 300); }
        }
    });
}

// ── Sorting & Filtering for Products & Categories ─────────────
function filterAndSortProducts() {
    const selectedCategory = document.getElementById('prodFilterCategory').value;
    const sortValue = document.getElementById('prodSort').value;
    
    const tbody = document.querySelector('#productsTable tbody');
    if (!tbody) return;
    const rows = Array.from(tbody.querySelectorAll('.product-row'));
    
    // Filter rows
    rows.forEach(row => {
        const cat = row.dataset.category;
        const show = (selectedCategory === 'all' || cat === selectedCategory);
        row.style.display = show ? '' : 'none';
    });
    
    // Sort rows
    rows.sort((a, b) => {
        if (sortValue === 'name_asc') {
            return a.dataset.name.localeCompare(b.dataset.name);
        } else if (sortValue === 'name_desc') {
            return b.dataset.name.localeCompare(a.dataset.name);
        } else if (sortValue === 'price_asc') {
            return parseFloat(a.dataset.price) - parseFloat(b.dataset.price);
        } else if (sortValue === 'price_desc') {
            return parseFloat(b.dataset.price) - parseFloat(a.dataset.price);
        } else if (sortValue === 'category_asc') {
            return a.dataset.category.localeCompare(b.dataset.category);
        } else {
            // default: Sort by ID desc (newest first)
            return parseInt(b.dataset.id) - parseInt(a.dataset.id);
        }
    });
    
    // Re-append sorted rows
    rows.forEach(row => tbody.appendChild(row));
}

function filterAndSortCategories() {
    const searchQuery = (document.getElementById('catSearch').value || '').toLowerCase().trim();
    const sortValue = document.getElementById('catSort').value;
    
    const container = document.getElementById('catListContainer');
    if (!container) return;
    const items = Array.from(container.querySelectorAll('.cat-list-item'));
    
    // Filter items
    items.forEach(item => {
        const nameEl = item.querySelector('.cat-name');
        if (!nameEl) return;
        const name = nameEl.textContent.toLowerCase();
        const show = name.includes(searchQuery);
        item.style.display = show ? 'flex' : 'none';
    });
    
    // Sort items
    items.sort((a, b) => {
        const nameAEl = a.querySelector('.cat-name');
        const nameBEl = b.querySelector('.cat-name');
        if (!nameAEl || !nameBEl) return 0;
        
        const nameA = nameAEl.textContent.trim();
        const nameB = nameBEl.textContent.trim();
        
        const orderA = parseInt(a.dataset.order) || 0;
        const orderB = parseInt(b.dataset.order) || 0;
        
        if (sortValue === 'name_asc') {
            return nameA.localeCompare(nameB);
        } else if (sortValue === 'name_desc') {
            return nameB.localeCompare(nameA);
        } else {
            // default: sort_order asc
            return orderA - orderB;
        }
    });
    
    // Re-append sorted items
    items.forEach(item => container.appendChild(item));
}

// ── Customer Name Filter for Orders ──────────────────────────
function filterOrdersByName(query) {
    const q = query.toLowerCase().trim();
    const tbody = document.querySelector('#ordersTable tbody');
    if (!tbody) return;
    const mainRows = Array.from(tbody.querySelectorAll('.order-row'));
    let visibleCount = 0;
    mainRows.forEach(row => {
        // Get customer name from the 3rd <td> (index 2)
        const cells = row.querySelectorAll('td');
        const nameTd = cells[2];
        const name = nameTd ? nameTd.textContent.toLowerCase() : '';
        const show = q === '' || name.includes(q);
        row.style.display = show ? '' : 'none';
        // Also hide/show the detail row
        const orderId = row.dataset.id;
        const detailRow = document.getElementById('detail-' + orderId);
        if (detailRow) detailRow.style.display = 'none'; // always collapse detail on filter
        if (show) visibleCount++;
    });
    const countEl = document.getElementById('orderFilterCount');
    if (countEl) {
        countEl.textContent = q ? `Showing ${visibleCount} of ${mainRows.length} orders` : '';
    }
}

// ── Stock Tab JS (incremental edit modal) ────────────────────
let stockEditProductId = null;

function openStockEdit(productId, productName, curTotal, curDamage, curOffline) {
    stockEditProductId = productId;
    document.getElementById('stockEditTitle').textContent = '✏️ Edit Stock — ' + productName;
    document.getElementById('stockAddQty').value    = 0;
    document.getElementById('stockDamageQty').value = 0;
    document.getElementById('stockOfflineQty').value = 0;
    document.getElementById('stockEditMsg').textContent = '';
    document.getElementById('stockEditModal').style.display = 'flex';
    setTimeout(() => document.getElementById('stockAddQty').focus(), 50);
}

function closeStockEdit() {
    document.getElementById('stockEditModal').style.display = 'none';
    stockEditProductId = null;
}

function saveStockEdit() {
    if (!stockEditProductId) return;
    const addQty     = Math.max(0, parseInt(document.getElementById('stockAddQty').value,    10) || 0);
    const damageQty  = Math.max(0, parseInt(document.getElementById('stockDamageQty').value, 10) || 0);
    const offlineQty = Math.max(0, parseInt(document.getElementById('stockOfflineQty').value,10) || 0);
    const msgEl = document.getElementById('stockEditMsg');

    if (addQty === 0 && damageQty === 0 && offlineQty === 0) {
        msgEl.textContent = '⚠️ Enter at least one quantity greater than 0.';
        msgEl.style.color = '#f59e0b';
        return;
    }
    msgEl.textContent = 'Saving…';
    msgEl.style.color = 'var(--text-muted)';

    fetch('stock_handler.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=increment_stock&product_id=${stockEditProductId}&add_qty=${addQty}&damage_qty=${damageQty}&offline_qty=${offlineQty}`,
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const id = stockEditProductId;
            const tsEl  = document.getElementById('val-total_stock-'  + id);
            const insEl = document.getElementById('val-in_stock-'     + id);
            const dmgEl = document.getElementById('val-damage_stock-' + id);
            const offEl = document.getElementById('val-sold_offline-' + id);
            if (tsEl)  tsEl.textContent  = data.total_stock;
            if (insEl) { insEl.textContent = data.in_stock; insEl.style.color = data.in_stock > 0 ? '#10b981' : '#ef4444'; }
            if (dmgEl) dmgEl.textContent = data.damage_stock;
            if (offEl) offEl.textContent = data.sold_offline;
            closeStockEdit();
        } else {
            msgEl.textContent = '❌ ' + (data.message || 'Failed to save.');
            msgEl.style.color = '#ef4444';
        }
    })
    .catch(() => { msgEl.textContent = '❌ Network error.'; msgEl.style.color = '#ef4444'; });
}

document.getElementById('stockEditModal')?.addEventListener('click', function(e) {
    if (e.target === this) closeStockEdit();
});

// ── Delete Order ──────────────────────────────────────────────
function deleteOrder(orderId, orderCode) {
    if (!confirm(`Delete order ${orderCode}?\nThis cannot be undone.`)) return;
    fetch('update_order.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=delete_order&order_id=${orderId}`,
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            // Fade out and remove the main row + detail row
            const mainRow   = document.getElementById('row-'    + orderId);
            const detailRow = document.getElementById('detail-' + orderId);
            [mainRow, detailRow].forEach(el => {
                if (!el) return;
                el.style.transition = 'opacity 0.3s';
                el.style.opacity = '0';
            });
            setTimeout(() => {
                mainRow?.remove();
                detailRow?.remove();
            }, 320);
        } else {
            alert('Failed to delete order: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(() => alert('Network error. Please try again.'));
}

// ── Sorting for Orders ────────────────────────────────────────
let currentSortCol = 'date';
let currentSortDir = 'desc'; // default is newest first

function toggleSort(col) {
    if (currentSortCol === col) {
        currentSortDir = (currentSortDir === 'desc') ? 'asc' : 'desc';
    } else {
        currentSortCol = col;
        currentSortDir = (col === 'date') ? 'desc' : 'asc';
    }
    
    // Update sort icons
    const icons = ['date', 'payment', 'status'];
    icons.forEach(id => {
        const el = document.getElementById('sort-icon-' + id);
        if (!el) return;
        if (currentSortCol === id) {
            el.className = 'fa-solid ' + (currentSortDir === 'asc' ? 'fa-sort-up' : 'fa-sort-down');
            el.style.opacity = '1';
        } else {
            el.className = 'fa-solid fa-sort';
            el.style.opacity = '0.5';
        }
    });
    
    sortOrders();
}

function sortOrders() {
    const tbody = document.querySelector('#ordersTable tbody');
    if (!tbody) return;
    
    const mainRows = Array.from(tbody.querySelectorAll('.order-row'));
    const pairs = mainRows.map(row => {
        const orderId = row.dataset.id;
        const detailRow = document.getElementById('detail-' + orderId);
        return { main: row, detail: detailRow };
    });
    
    pairs.sort((a, b) => {
        if (currentSortCol === 'date') {
            const dateA = parseInt(a.main.dataset.date) || 0;
            const dateB = parseInt(b.main.dataset.date) || 0;
            return (currentSortDir === 'desc') ? (dateB - dateA) : (dateA - dateB);
        } else if (currentSortCol === 'payment') {
            const payA = a.main.dataset.paymentStatus || 'Unpaid';
            const payB = b.main.dataset.paymentStatus || 'Unpaid';
            const priority = { 'Paid': 1, 'Cash': 2, 'Unpaid': 3 };
            const pA = priority[payA] || 3;
            const pB = priority[payB] || 3;
            if (pA !== pB) {
                return (currentSortDir === 'asc') ? (pA - pB) : (pB - pA);
            }
            // Secondary sort by date
            const dateA = parseInt(a.main.dataset.date) || 0;
            const dateB = parseInt(b.main.dataset.date) || 0;
            return dateB - dateA;
        } else if (currentSortCol === 'status') {
            const stA = a.main.dataset.status || 'Pending';
            const stB = b.main.dataset.status || 'Pending';
            // Priority: Pending=1, Processing=2, Delivered=3, Cancelled=4
            const priority = { 'Pending': 1, 'Processing': 2, 'Delivered': 3, 'Cancelled': 4 };
            const pA = priority[stA] || 5;
            const pB = priority[stB] || 5;
            if (pA !== pB) {
                return (currentSortDir === 'asc') ? (pA - pB) : (pB - pA);
            }
            // Secondary sort by date descending
            const dateA = parseInt(a.main.dataset.date) || 0;
            const dateB = parseInt(b.main.dataset.date) || 0;
            return dateB - dateA;
        }
        return 0;
    });
    
    pairs.forEach(pair => {
        tbody.appendChild(pair.main);
        if (pair.detail) {
            tbody.appendChild(pair.detail);
        }
    });
}
</script>

<?php if ($activeTab === 'revenue'): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const isDark = document.documentElement.dataset.theme !== 'light';
    const textColor = isDark ? 'rgba(255,255,255,0.7)' : 'rgba(0,0,0,0.6)';
    const gridColor = isDark ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.07)';

    Chart.defaults.color = textColor;
    Chart.defaults.font.family = "'Inter', sans-serif";
    Chart.defaults.font.size   = 12;

    function buildChart(canvasId, labels, values, bgColor) {
        const canvas = document.getElementById(canvasId);
        if (!canvas) return;
        new Chart(canvas, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: 'Qty Sold',
                    data: values,
                    backgroundColor: bgColor,
                    borderRadius: 6,
                    borderSkipped: false,
                }]
            },
            options: {
                indexAxis: 'y',
                responsive: true,
                maintainAspectRatio: true,
                plugins: { legend: { display: false } },
                scales: {
                    x: { grid: { color: gridColor }, ticks: { precision: 0 } },
                    y: { grid: { display: false } }
                }
            }
        });
    }

    // Chart 1: All-time
    const atLabels = <?= json_encode(array_keys($revData['chart_alltime'] ?? [])) ?>;
    const atValues = <?= json_encode(array_values($revData['chart_alltime'] ?? [])) ?>;
    buildChart('chartAllTime', atLabels, atValues, 'rgba(139,92,246,0.7)');

    // Chart 2: This month
    const tmLabels = <?= json_encode(array_keys($revData['chart_thismonth'] ?? [])) ?>;
    const tmValues = <?= json_encode(array_values($revData['chart_thismonth'] ?? [])) ?>;
    buildChart('chartThisMonth', tmLabels, tmValues, 'rgba(16,185,129,0.7)');
});
</script>
<?php endif; ?>
</body>
</html>
