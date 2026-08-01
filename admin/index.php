<?php
// ============================================================
//  Creamy Bite – Admin Dashboard
//  Tabs: Orders | Products | Gallery | Categories
// ============================================================
require_once __DIR__ . '/_guard.php';   // session, admin check, CSRF helpers

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

$successMsg = '';
$errorMsg   = '';

// ── Handle product delete ─────────────────────────────────
if (isset($_GET['action']) && $_GET['action'] === 'delete_product' && isset($_GET['id'])) {
    // Deleting a product removes its image from disk and its row from the
    // database. Without a token, any page the admin visited could fire this
    // with a single <img src="…?action=delete_product&id=7">.
    csrfCheck();
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
    // Approving grants wholesale pricing; rejecting revokes an account.
    csrfCheck();
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
// Cancelled orders must not count as revenue — the money was refunded or
// never taken, but the row keeps its Paid/Cash payment_status.
$totalRevenue  = $pdo->query("SELECT COALESCE(SUM(total_price), 0) FROM orders WHERE payment_status IN ('Paid', 'Cash') AND status <> 'Cancelled'")->fetchColumn();
$totalProducts = $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();

// ── Active tab ────────────────────────────────────────────
$activeTab = $_GET['tab'] ?? 'orders';
$validTabs = ['orders','products','gallery','categories','promos','stock','revenue','inquiries','trade','invoices'];
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

// ── Invoices ────────────────────────────────────────────
require_once __DIR__ . '/../includes/invoice.php';
$invoices        = [];
$invoiceSettings = [];
$invoiceOutstanding = 0.0;
// Initialised here, not only inside the try: a failed invoice query would
// otherwise leave the rep panel referencing undefined variables.
$salesReps = [];
$repTotals = [];
try {
    // LEFT JOIN on the rep so an invoice with no rep, or one whose rep was
    // removed, still appears — an INNER JOIN would quietly hide invoices.
    $invoices = $pdo->query(
        "SELECT i.*, (i.total - i.amount_paid) AS balance_due, o.order_code, r.name AS rep_name
           FROM invoices i
      LEFT JOIN orders o     ON o.id = i.order_id
      LEFT JOIN sales_reps r ON r.id = i.sales_rep_id
       ORDER BY i.issue_date DESC, i.id DESC"
    )->fetchAll();
    $invoiceSettings = invoiceSettings($pdo);
    foreach ($invoices as $iv) {
        if ($iv['status'] !== 'void' && $iv['status'] !== 'paid') {
            $invoiceOutstanding += (float)$iv['balance_due'];
        }
    }

    // What each rep has sold and earned. Voided invoices are excluded — a
    // cancelled sale is not commission owed.
    $salesReps = invoiceSalesReps($pdo);
    $repTotals = [];
    foreach ($invoices as $iv) {
        $repId = (int)($iv['sales_rep_id'] ?? 0);
        if ($repId <= 0 || $iv['status'] === 'void') {
            continue;
        }
        if (!isset($repTotals[$repId])) {
            $repTotals[$repId] = ['count' => 0, 'sold' => 0.0, 'commission' => 0.0];
        }
        $repTotals[$repId]['count']++;
        $repTotals[$repId]['sold']       += (float)$iv['total'] - (float)$iv['vat_amount'];
        $repTotals[$repId]['commission'] += invoiceCommission($iv);
    }
} catch (PDOException $e) {
    error_log('Invoice load failed: ' . $e->getMessage());
}

$invoiceFlash = $_SESSION['invoice_flash'] ?? null;
unset($_SESSION['invoice_flash']);

// Orders that have no live invoice yet, for the "raise invoice" picker.
$uninvoicedOrders = [];
try {
    $uninvoicedOrders = $pdo->query(
        "SELECT o.id, o.order_code, o.customer_name, o.trade_business_name, o.trade_user_id, o.total_price, o.created_at
           FROM orders o
      LEFT JOIN invoices i ON i.order_id = o.id AND i.status <> 'void'
          WHERE i.id IS NULL
       ORDER BY o.created_at DESC
          LIMIT 101"
    )->fetchAll();
} catch (PDOException $e) {}

// The query asks for 101 so we can tell "exactly 100" from "more than 100".
// A picker that silently stops at 100 looks like a complete list, and an
// older order that is missing from it looks like it was already invoiced.
$uninvoicedCapped = count($uninvoicedOrders) > 100;
if ($uninvoicedCapped) {
    array_pop($uninvoicedOrders);
}

// ── Active tab ────────────────────────────────────────────
$activeTab = $_GET['tab'] ?? 'orders';
$validTabs = ['orders','products','gallery','categories','promos','stock','revenue','inquiries','trade','invoices'];
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

    // ── Split retail vs trade, and add invoices raised outside an order ──
    // Cancelled orders are excluded: the money was refunded or never taken,
    // but the row keeps its Paid/Cash payment_status.
    $revData['retail'] = $revData['trade'] = 0.0;
    $revData['retail_count'] = $revData['trade_count'] = 0;
    try {
        $split = $pdo->prepare(
            "SELECT CASE WHEN trade_user_id > 0 THEN 'trade' ELSE 'retail' END AS kind,
                    COALESCE(SUM(total_price), 0) AS total, COUNT(*) AS cnt
               FROM orders
              WHERE DATE(created_at) BETWEEN :f AND :t
                AND payment_status IN ('Paid','Cash')
                AND status <> 'Cancelled'
           GROUP BY kind"
        );
        $split->execute(['f' => $revFrom, 't' => $revTo]);
        while ($r = $split->fetch()) {
            $revData[$r['kind']]              = (float)$r['total'];
            $revData[$r['kind'] . '_count']   = (int)$r['cnt'];
        }
    } catch (PDOException $e) { error_log('Revenue split failed: ' . $e->getMessage()); }

    // Invoices created directly (not raised from an order) are real income
    // that no order row accounts for, so they must be added, not double
    // counted. Only the amount actually PAID counts as revenue.
    $revData['invoice_direct'] = 0.0;
    $revData['invoice_direct_count'] = 0;
    $revData['invoice_outstanding'] = 0.0;
    try {
        $ist = $pdo->prepare(
            "SELECT COALESCE(SUM(amount_paid), 0) AS paid,
                    COALESCE(SUM(total - amount_paid), 0) AS owing,
                    COUNT(*) AS cnt
               FROM invoices
              WHERE order_id IS NULL
                AND status <> 'void'
                AND DATE(issue_date) BETWEEN :f AND :t"
        );
        $ist->execute(['f' => $revFrom, 't' => $revTo]);
        if ($ir = $ist->fetch()) {
            $revData['invoice_direct']       = (float)$ir['paid'];
            $revData['invoice_direct_count'] = (int)$ir['cnt'];
            $revData['invoice_outstanding']  = (float)$ir['owing'];
        }
    } catch (PDOException $e) { error_log('Invoice revenue failed: ' . $e->getMessage()); }

    // Grand total = paid orders + paid standalone invoices.
    $revData['grand_total'] = $revData['retail'] + $revData['trade'] + $revData['invoice_direct'];

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
    $productRevenue      = [];
    $productMonthRevenue = [];
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
                // Count each SIZE separately. Aggregating on the bare product
                // name merged "500ml" and "1L" into one bar, hiding which size
                // actually sells.
                $nm  = trim(($it['name'] ?? 'Item') . ' ' . ($it['variant_name'] ?? ''));
                $qty = (int)$it['quantity'];
                $val = (float)($it['price'] ?? 0) * $qty;

                $productAllTime[$nm]  = ($productAllTime[$nm]  ?? 0) + $qty;
                $productRevenue[$nm]  = ($productRevenue[$nm]  ?? 0) + $val;
                if ($isThisMonth) {
                    $productThisMonth[$nm]    = ($productThisMonth[$nm]    ?? 0) + $qty;
                    $productMonthRevenue[$nm] = ($productMonthRevenue[$nm] ?? 0) + $val;
                }
            }
        }
    } catch (PDOException $e) {}
    arsort($productAllTime);
    arsort($productThisMonth);
    $revData['chart_alltime']   = array_slice($productAllTime,   0, 10, true);
    $revData['chart_thismonth'] = array_slice($productThisMonth, 0, 10, true);
    $revData['revenue_alltime']   = $productRevenue;
    $revData['revenue_thismonth'] = $productMonthRevenue;
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
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/responsive.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="assets/css/admin.css">
<!-- In-page dialogs. Every confirm/alert on this page goes through these, so
     they must load here — without them cbConfirm() is simply not defined and
     the delete buttons do nothing at all. -->
<link rel="stylesheet" href="../assets/css/modal.css">
<script src="../assets/js/modal.js" defer></script>
<script>
// Panels open and close on the `hidden` ATTRIBUTE, not a CSS class.
//
// A class only hides things while its stylesheet is present. When admin.css
// was missing (a partial upload), .is-hidden meant nothing: the panels sat
// permanently open and clicking the button toggled a class with no rule
// behind it, so the buttons looked broken. `hidden` is honoured by the
// browser itself, so these keep working even with no CSS at all.
function cbTogglePanel(id) {
    var el = document.getElementById(id);
    if (el) el.hidden = !el.hidden;
}
</script>
<?php include __DIR__ . '/_csrf_js.php'; ?>
</head>
<body class="admin-wrapper has-sidebar">
<?php
// ── The admin menu, defined ONCE ─────────────────────────────
// This used to be written out twice — a top navbar and a tab strip — kept
// in step by hand. They drifted: the navbar was missing Trade Accounts,
// Revenue and Inquiries entirely. One array, one render, no drift.
$adminNav = [
    ['group' => 'Sales'],
    ['tab' => 'orders',     'icon' => 'fa-clipboard-list',     'label' => 'Orders',
     'badge' => $pendingOrders > 0 ? (string)$pendingOrders : null],
    ['tab' => 'invoices',   'icon' => 'fa-file-invoice',       'label' => 'Invoices',
     'badge' => $invoiceOutstanding > 0 ? '£' . number_format($invoiceOutstanding, 0) : null, 'alert' => true],
    ['tab' => 'revenue',    'icon' => 'fa-chart-line',         'label' => 'Revenue'],

    ['group' => 'Catalogue'],
    ['tab' => 'products',   'icon' => 'fa-ice-cream',          'label' => 'Products'],
    ['tab' => 'stock',      'icon' => 'fa-boxes-stacked',      'label' => 'Stock'],
    ['tab' => 'categories', 'icon' => 'fa-tags',               'label' => 'Categories'],
    ['tab' => 'promos',     'icon' => 'fa-ticket',             'label' => 'Promos'],

    ['group' => 'Customers'],
    ['tab' => 'trade',      'icon' => 'fa-store',              'label' => 'Trade Accounts',
     'badge' => $pendingTradeCount > 0 ? (string)$pendingTradeCount : null, 'alert' => true],
    ['tab' => 'inquiries',  'icon' => 'fa-envelope-open-text', 'label' => 'Inquiries',
     'badge' => $unreadInquiries > 0 ? (string)$unreadInquiries : null, 'alert' => true],

    ['group' => 'Content'],
    ['tab' => 'gallery',    'icon' => 'fa-images',             'label' => 'Gallery'],
];

// Page heading for the topbar, taken from the same array.
$pageTitles = [
    'orders'     => ['📋 Orders',          'View and manage all customer orders'],
    'trade'      => ['🏪 Trade Accounts',  'Approve and manage wholesale partners'],
    'products'   => ['🍦 Products',        'Add, edit and price your range'],
    'stock'      => ['📦 Stock',           'Stock levels, damage and offline sales'],
    'invoices'   => ['🧾 Invoices',        'Create, amend and track invoices'],
    'revenue'    => ['📈 Revenue',         'Sales performance over time'],
    'gallery'    => ['🖼️ Gallery',         'Photos shown on the public gallery'],
    'categories' => ['🏷️ Categories',      'Organise the menu'],
    'promos'     => ['🎟️ Promos',          'Discount codes'],
    'inquiries'  => ['✉️ Inquiries',        'Messages from the contact form'],
];
[$pageTitle, $pageSub] = $pageTitles[$activeTab] ?? ['Admin', ''];
?>

<!-- ══ Sidebar ════════════════════════════════════════════ -->
<aside class="admin-sidebar" id="adminSidebar">
    <a href="../index.php" class="sb-brand" target="_blank" title="<?= SHOP_NAME ?> — open the shop">
        <img src="../assets/images/logo.png" alt="<?= SHOP_NAME ?>">
    </a>

    <nav class="sb-nav">
        <?php foreach ($adminNav as $item): ?>
            <?php if (isset($item['group'])): ?>
                <div class="sb-section"><?= htmlspecialchars($item['group']) ?></div>
            <?php else: ?>
                <a href="index.php?tab=<?= $item['tab'] ?>"
                   class="sb-link <?= $activeTab === $item['tab'] ? 'active' : '' ?>">
                    <i class="fa-solid <?= $item['icon'] ?>"></i>
                    <span class="sb-label"><?= htmlspecialchars($item['label']) ?></span>
                    <?php if (!empty($item['badge'])): ?>
                    <span class="sb-badge <?= !empty($item['alert']) ? 'alert' : '' ?>"><?= htmlspecialchars($item['badge']) ?></span>
                    <?php endif; ?>
                </a>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>

    <div class="sb-foot">
        <div class="sb-user">
            <i class="fa-solid fa-user-shield"></i>
            <span><?= htmlspecialchars(ADMIN_USERNAME) ?></span>
        </div>
        <a href="../index.php" target="_blank" class="sb-foot-btn shop">
            <i class="fa-solid fa-globe"></i> <span>View Shop</span>
        </a>
        <a href="logout.php" class="sb-foot-btn out">
            <i class="fa-solid fa-right-from-bracket"></i> <span>Logout</span>
        </a>
    </div>
</aside>
<div class="admin-sidebar-backdrop" id="sidebarBackdrop"></div>

<div class="admin-shell">
    <header class="admin-topbar">
        <button class="sb-toggle" id="sbToggle" aria-label="Open menu" aria-controls="adminSidebar" aria-expanded="false">
            <i class="fa-solid fa-bars"></i>
        </button>
        <div class="tb-title">
            <h1><?= $pageTitle ?></h1>
            <?php if ($pageSub): ?><div class="sub"><?= htmlspecialchars($pageSub) ?></div><?php endif; ?>
        </div>

        <?php // Per-tab primary action, kept beside the title rather than
              // floating in the content area. ?>
        <div class="tb-actions">
            <?php if ($activeTab === 'products'): ?>
            <a href="product_form.php" class="btn-primary">
                <i class="fa-solid fa-plus"></i> Add Product
            </a>
            <?php elseif ($activeTab === 'invoices'): ?>
            <form method="POST" action="handlers/invoice_handler.php" class="cbi-flush">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="create_blank">
                <button class="btn-primary"><i class="fa-solid fa-plus"></i> New Invoice</button>
            </form>
            <?php endif; ?>
        </div>
    </header>

<main class="admin-content">
    <div class="container">

        <!-- Alerts -->
        <?php if ($successMsg): ?>
        <div class="alert alert-success cbi-gap-24">
            <i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($successMsg) ?>
        </div>
        <?php endif; ?>
        <?php if ($errorMsg): ?>
        <div class="alert alert-danger cbi-gap-24">
            <i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($errorMsg) ?>
        </div>
        <?php endif; ?>

        <!-- Page header removed entirely: the title and subtitle come from
             $pageTitles in the topbar, and the per-tab action button now sits
             in the topbar too. Leaving an empty header row here put a tall
             blank band above the stat cards with a stranded button in it. -->

        <!-- Stats Cards.
             The invoices tab gets invoice figures instead of order figures:
             "Total Orders" and "Products" tell you nothing while you are
             chasing money, and what matters there is how much has been
             billed and how much is still owed. -->
        <?php if ($activeTab === 'invoices'): ?>
        <?php
            $invStats = ['count' => 0, 'billed' => 0.0, 'received' => 0.0, 'owed' => 0.0,
                         'paid' => 0, 'part' => 0, 'unpaid' => 0, 'draft' => 0, 'void' => 0];
            foreach ($invoices as $iv) {
                if ($iv['status'] === 'void') { $invStats['void']++; continue; }
                $invStats['count']++;
                $invStats['billed']   += (float)$iv['total'];
                $invStats['received'] += (float)$iv['amount_paid'];
                $invStats['owed']     += (float)$iv['balance_due'];
                if ($iv['status'] === 'paid')           { $invStats['paid']++; }
                elseif ($iv['status'] === 'part_paid')  { $invStats['part']++; }
                elseif ($iv['status'] === 'draft')      { $invStats['draft']++; }
                else                                    { $invStats['unpaid']++; }
            }
        ?>
        <div class="stats-grid cbi-gap-32">
            <div class="stat-card glass-panel">
                <div class="stat-card-icon">🧾</div>
                <div class="stat-label">Invoices</div>
                <div class="stat-value"><?= $invStats['count'] ?></div>
                <?php if ($invStats['void'] > 0): ?>
                <div class="cbi-stat-subnote"><?= $invStats['void'] ?> void (not counted)</div>
                <?php endif; ?>
            </div>
            <div class="stat-card glass-panel">
                <div class="stat-card-icon">💷</div>
                <div class="stat-label">Total Billed</div>
                <div class="stat-value cbi-stat-value-sm">£<?= number_format($invStats['billed'], 2) ?></div>
            </div>
            <div class="stat-card glass-panel">
                <div class="stat-card-icon">✅</div>
                <div class="stat-label">Received</div>
                <div class="stat-value cbi-stat-value-sm cbi-stat-good">£<?= number_format($invStats['received'], 2) ?></div>
                <div class="cbi-stat-subnote"><?= $invStats['paid'] ?> fully paid</div>
            </div>
            <div class="stat-card glass-panel">
                <div class="stat-card-icon">⏳</div>
                <div class="stat-label">Outstanding</div>
                <div class="stat-value cbi-stat-value-sm<?= $invStats['owed'] > 0.001 ? ' cbi-stat-bad' : '' ?>">£<?= number_format($invStats['owed'], 2) ?></div>
                <div class="cbi-stat-subnote"><?= $invStats['part'] ?> part paid</div>
            </div>
            <div class="stat-card glass-panel">
                <div class="stat-card-icon">📤</div>
                <div class="stat-label">Not Paid</div>
                <div class="stat-value"><?= $invStats['unpaid'] ?></div>
                <div class="cbi-stat-subnote"><?= $invStats['draft'] ?> still draft</div>
            </div>
        </div>
        <?php else: ?>
        <div class="stats-grid cbi-gap-32">
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
                <div class="stat-value cbi-stat-value-sm">£<?= number_format($totalRevenue, 2) ?></div>
            </div>
            <div class="stat-card glass-panel">
                <div class="stat-card-icon">🍦</div>
                <div class="stat-label">Products</div>
                <div class="stat-value"><?= $totalProducts ?></div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Navigation now lives in the sidebar (see $adminNav above). -->

        <!-- ═══════════════════ INVOICES TAB ═══════════════════ -->
        <?php if ($activeTab === 'invoices'): ?>
        <?php if ($invoiceFlash): ?>
        <div class="cbi-inv-flash flash-<?= htmlspecialchars($invoiceFlash['type'] === 'error' ? 'error' : ($invoiceFlash['type'] === 'warn' ? 'warn' : 'ok')) ?>">
            <?= htmlspecialchars($invoiceFlash['msg']) ?>
        </div>
        <?php endif; ?>

        <div class="glass-panel cbi-inv-panel">
            <div class="cbi-inv-header">
                <h2 class="cbi-inv-title">
                    <i class="fa-solid fa-file-invoice cbi-icon-primary"></i> Invoices
                    <span class="cbi-inv-count">
                        <?= count($invoices) ?> total<?= $invoiceOutstanding > 0 ? ' · £' . number_format($invoiceOutstanding, 2) . ' outstanding' : '' ?>
                    </span>
                </h2>
                <div class="cbi-btn-row">
                    <?php // "New invoice" lives in the topbar — not repeated here. ?>
                    <button type="button" class="btn-secondary cbi-inv-settings-btn" onclick="cbTogglePanel('salesRepPanel')">
                        <i class="fa-solid fa-user-tie"></i> Sales Reps
                    </button>
                    <button type="button" class="btn-secondary cbi-inv-settings-btn" onclick="cbTogglePanel('invSettings')">
                        <i class="fa-solid fa-gear"></i> Settings
                    </button>
                </div>
            </div>

            <!-- Raise from an order -->
            <?php if (!empty($uninvoicedOrders)): ?>
            <form method="POST" action="handlers/invoice_handler.php"
                  class="cbi-inv-raise-form">
        <?= csrfField() ?>
                <input type="hidden" name="action" value="create_from_order">
                <span class="cbi-inv-raise-label">
                    <i class="fa-solid fa-receipt cbi-icon-primary"></i> Raise invoice from order
                </span>

                <?php /* Searchable: the list grows with every order, so typing
                         filters it by code, customer or amount. */ ?>
                <input type="text" id="orderPickerSearch" class="form-control cbi-inv-order-search"
                       placeholder="Search order code, customer or amount…"
                       oninput="filterOrderPicker(this.value)" autocomplete="off">

                <select name="order_id" id="orderPicker" class="form-control cbi-inv-order-select">
                    <?php foreach ($uninvoicedOrders as $uo):
                        // trade_user_id is the only reliable marker: a retail
                        // customer can be called anything, including a shop name.
                        $uoIsTrade = (int)($uo['trade_user_id'] ?? 0) > 0;
                    ?>
                    <option value="<?= (int)$uo['id'] ?>" data-kind="<?= $uoIsTrade ? 'trade' : 'retail' ?>">
                        <?= $uoIsTrade ? '[TRADE]' : '[Retail]' ?>
                        <?= htmlspecialchars($uo['order_code']) ?> —
                        <?= htmlspecialchars($uo['trade_business_name'] ?: $uo['customer_name']) ?>
                        (£<?= number_format((float)$uo['total_price'], 2) ?>, <?= date('d M Y', strtotime($uo['created_at'])) ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
                <select id="orderKindFilter" class="form-control cbi-inv-kind-filter" onchange="filterOrderPicker(document.getElementById('orderSearch')?.value || '')">
                    <option value="all">All orders</option>
                    <option value="trade">Trade only</option>
                    <option value="retail">Retail only</option>
                </select>
                <button class="btn-secondary cbi-inv-create-btn">Create</button>
                <span id="orderPickerCount" class="cbi-inv-picker-count"></span>
                <?php
                    $uoTrade  = count(array_filter($uninvoicedOrders, fn($u) => (int)($u['trade_user_id'] ?? 0) > 0));
                    $uoRetail = count($uninvoicedOrders) - $uoTrade;
                ?>
                <span class="cbi-inv-picker-note">
                    <?= count($uninvoicedOrders) ?> waiting to be invoiced
                    (<?= $uoTrade ?> trade, <?= $uoRetail ?> retail)<?= $uninvoicedCapped ? ' — showing the 100 most recent' : '' ?>.
                    An order disappears from this list once it has an invoice.
                </span>
            </form>
            <?php endif; ?>

            <!-- Sales reps / agents -->
            <div id="salesRepPanel" class="cbi-inv-settings-panel" hidden>
                <h3 class="cbi-inv-settings-title">Sales reps &amp; agents</h3>
                <p class="cbi-rep-intro">
                    Anyone here can be picked on an invoice as who sold it. Their
                    name appears on the customer's copy; the commission does not.
                </p>

                <form method="POST" action="handlers/invoice_handler.php" class="cbi-rep-add-form">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="add_rep">
                    <input type="text" name="rep_name"  class="form-control cbi-rep-input" placeholder="Name *" required>
                    <input type="text" name="rep_phone" class="form-control cbi-rep-input" placeholder="Phone">
                    <input type="email" name="rep_email" class="form-control cbi-rep-input" placeholder="Email">
                    <button type="submit" class="btn-primary cbi-rep-add-btn">
                        <i class="fa-solid fa-plus"></i> Add
                    </button>
                </form>

                <?php if (empty($salesReps)): ?>
                <p class="cbi-rep-empty">No reps yet. Add one above.</p>
                <?php else: ?>
                <table class="data-table cbi-rep-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Contact</th>
                            <th class="cbi-col-right">Invoices</th>
                            <th class="cbi-col-right">Sold (ex-VAT)</th>
                            <th class="cbi-col-right">Commission</th>
                            <th class="cbi-col-right">Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($salesReps as $rep):
                            $rs = $repTotals[(int)$rep['id']] ?? ['count' => 0, 'sold' => 0.0, 'commission' => 0.0];
                        ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($rep['name']) ?></strong></td>
                            <td class="cbi-rep-contact">
                                <?= htmlspecialchars(trim($rep['phone'] . ' ' . $rep['email'])) ?: '<span class="cbi-muted">—</span>' ?>
                            </td>
                            <td class="cbi-col-right"><?= (int)$rs['count'] ?></td>
                            <td class="cbi-col-right">£<?= number_format($rs['sold'], 2) ?></td>
                            <td class="cbi-col-right"><strong>£<?= number_format($rs['commission'], 2) ?></strong></td>
                            <td class="cbi-col-right">
                                <form method="POST" action="handlers/invoice_handler.php" class="cbi-rep-toggle-form"
                                      data-confirm="<?= $rep['active'] ? 'Deactivate' : 'Reactivate' ?> <?= htmlspecialchars($rep['name'], ENT_QUOTES) ?>? Invoices they already sold keep their name either way."
                                      data-confirm-title="<?= $rep['active'] ? 'Deactivate rep?' : 'Reactivate rep?' ?>"
                                      data-confirm-ok="<?= $rep['active'] ? 'Deactivate' : 'Reactivate' ?>">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="toggle_rep">
                                    <input type="hidden" name="rep_id" value="<?= (int)$rep['id'] ?>">
                                    <button type="submit" class="cbi-rep-toggle <?= $rep['active'] ? 'is-active' : 'is-inactive' ?>">
                                        <?= $rep['active'] ? 'Active' : 'Inactive' ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>

            <!-- Settings -->
            <div id="invSettings" class="cbi-inv-settings-panel" hidden>
                <form method="POST" action="handlers/invoice_handler.php">
        <?= csrfField() ?>
                    <input type="hidden" name="action" value="save_settings">
                    <h3 class="cbi-inv-settings-title">Invoice defaults</h3>
                    <div class="cbi-inv-settings-grid">
                        <div><label class="form-label">Number prefix</label>
                            <input type="text" name="number_prefix" class="form-control" value="<?= htmlspecialchars($invoiceSettings['number_prefix'] ?? 'INV') ?>"></div>
                        <div><label class="form-label">Digits</label>
                            <input type="number" name="number_padding" class="form-control" min="1" max="10" value="<?= (int)($invoiceSettings['number_padding'] ?? 4) ?>"></div>
                        <div><label class="form-label">Next number</label>
                            <input type="number" name="next_number" class="form-control" min="1" value="<?= (int)($invoiceSettings['next_number'] ?? 1) ?>">
                            <small class="cbi-muted-xs">Next invoice will be <?= htmlspecialchars(($invoiceSettings['number_prefix'] ?? 'INV') . str_pad((string)($invoiceSettings['next_number'] ?? 1), (int)($invoiceSettings['number_padding'] ?? 4), '0', STR_PAD_LEFT)) ?></small></div>
                        <div><label class="form-label">Default terms</label>
                            <input type="text" name="default_terms" class="form-control" value="<?= htmlspecialchars($invoiceSettings['default_terms'] ?? 'On Receipt') ?>"></div>
                        <div><label class="form-label">Default VAT (%)</label>
                            <input type="number" step="0.01" min="0" name="default_vat_rate" class="form-control" value="<?= htmlspecialchars(rtrim(rtrim(number_format((float)($invoiceSettings['default_vat_rate'] ?? 0) * 100, 2, '.', ''), '0'), '.')) ?>"></div>
                    </div>
                    <div class="cbi-inv-settings-grid-wide">
                        <div><label class="form-label">Business name</label>
                            <input type="text" name="from_name" class="form-control" value="<?= htmlspecialchars($invoiceSettings['from_name'] ?? '') ?>"></div>
                        <div><label class="form-label">Phone</label>
                            <input type="text" name="from_phone" class="form-control" value="<?= htmlspecialchars($invoiceSettings['from_phone'] ?? '') ?>"></div>
                        <div><label class="form-label">Email</label>
                            <input type="text" name="from_email" class="form-control" value="<?= htmlspecialchars($invoiceSettings['from_email'] ?? '') ?>"></div>
                        <div><label class="form-label">Website</label>
                            <input type="text" name="from_website" class="form-control" value="<?= htmlspecialchars($invoiceSettings['from_website'] ?? '') ?>"></div>
                    </div>
                    <div class="cbi-inv-settings-grid-wide">
                        <div><label class="form-label">Address</label>
                            <textarea name="from_address" class="form-control" rows="3"><?= htmlspecialchars($invoiceSettings['from_address'] ?? '') ?></textarea></div>
                        <div><label class="form-label">Payment instructions</label>
                            <textarea name="payment_instructions" class="form-control" rows="3"><?= htmlspecialchars($invoiceSettings['payment_instructions'] ?? '') ?></textarea></div>
                    </div>
                    <button class="btn-primary cbi-inv-settings-save">Save settings</button>
                    <p class="cbi-inv-settings-note">
                        These fill in new invoices. Existing invoices keep the details they were issued with.
                    </p>
                </form>
            </div>

            <!-- Search -->
            <div class="cbi-inv-search-row">
                <i class="fa-solid fa-magnifying-glass cbi-muted-lg"></i>
                <input type="text" id="invoiceFilter" class="form-control cbi-inv-search-input" placeholder="Search invoice number, customer or amount…"
                       oninput="filterInvoices(this.value)">
                <span id="invoiceFilterCount" class="cbi-muted-sm"></span>
            </div>

            <?php if (empty($invoices)): ?>
            <div class="cbi-inv-empty">
                <div class="cbi-inv-empty-icon">🧾</div>
                <p class="cbi-inv-empty-text">No invoices yet. Create a blank one, or raise one from an order above.</p>
            </div>
            <?php else: ?>
            <div class="table-wrapper">
                <table class="data-table cbi-table-full" id="invoicesTable">
                    <thead>
                        <tr>
                            <?php /* Every column sorts. data-type tells the comparator whether
                                     to read the cell as text, a number or a date. */ ?>
                            <th class="inv-sort" data-col="0" data-type="text"   onclick="sortInvoices(0,'text',this)">Invoice <i class="fa-solid fa-sort"></i></th>
                            <th class="inv-sort" data-col="1" data-type="date"   onclick="sortInvoices(1,'date',this)">Date <i class="fa-solid fa-sort"></i></th>
                            <th class="inv-sort" data-col="2" data-type="text"   onclick="sortInvoices(2,'text',this)">Bill To <i class="fa-solid fa-sort"></i></th>
                            <th class="inv-sort cbi-col-right" data-col="3" data-type="number" onclick="sortInvoices(3,'number',this)">Total <i class="fa-solid fa-sort"></i></th>
                            <th class="inv-sort cbi-col-right" data-col="4" data-type="number" onclick="sortInvoices(4,'number',this)">Balance <i class="fa-solid fa-sort"></i></th>
                            <th class="inv-sort" data-col="5" data-type="text"   onclick="sortInvoices(5,'text',this)">Sold By <i class="fa-solid fa-sort"></i></th>
                            <th class="inv-sort" data-col="6" data-type="text"   onclick="sortInvoices(6,'text',this)">Status <i class="fa-solid fa-sort"></i></th>
                            <th class="cbi-col-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($invoices as $iv):
                        [$lbl, $bg, $fg, $bd] = invoiceStatusLabel($iv['status']);
                        $bal = (float)$iv['balance_due'];
                    ?>
                        <tr class="invoice-row"
                            data-sort1="<?= strtotime($iv['issue_date']) ?>"
                            data-sort3="<?= number_format((float)$iv['total'], 2, '.', '') ?>"
                            data-sort4="<?= number_format((float)$iv['balance_due'], 2, '.', '') ?>"
                            data-number="<?= htmlspecialchars($iv['invoice_number'], ENT_QUOTES) ?>"
                            data-to="<?= htmlspecialchars($iv['to_name'], ENT_QUOTES) ?>"
                            data-sort5="<?= htmlspecialchars($iv['rep_name'] ?: 'zzz', ENT_QUOTES) ?>"
                            data-total="<?= number_format((float)$iv['total'], 2, '.', '') ?>"
                            data-status="<?= htmlspecialchars($iv['status'], ENT_QUOTES) ?>">
                            <td class="cbi-inv-number-cell">
                                <?= htmlspecialchars($iv['invoice_number']) ?>
                                <?php if (!empty($iv['order_code'])): ?>
                                <div class="cbi-inv-from-order">from <?= htmlspecialchars($iv['order_code']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="cbi-inv-date-cell">
                                <?= date('d M Y', strtotime($iv['issue_date'])) ?>
                            </td>
                            <td class="cbi-inv-to-cell"><?= htmlspecialchars($iv['to_name']) ?: '<span class="cbi-muted">—</span>' ?></td>
                            <td class="cbi-inv-total-cell">£<?= number_format((float)$iv['total'], 2) ?></td>
                            <td class="cbi-inv-balance-cell <?= $bal > 0.001 ? 'is-due' : 'is-clear' ?>">
                                £<?= number_format($bal, 2) ?>
                            </td>
                            <td class="cbi-inv-rep-cell">
                                <?= $iv['rep_name'] !== null && $iv['rep_name'] !== ''
                                      ? htmlspecialchars($iv['rep_name'])
                                      : '<span class="cbi-muted">House</span>' ?>
                            </td>
                            <td>
                                <span class="cbi-inv-status-badge <?= invoiceStatusClass($iv['status']) ?>">
                                    <?= $lbl ?>
                                </span>
                            </td>
                            <td class="cbi-actions-cell">
                                <a href="invoice_view.php?id=<?= (int)$iv['id'] ?>" target="_blank" class="btn-sm cbi-inv-action-btn" title="Preview / print"><i class="fa-solid fa-print"></i></a>
                                <a href="invoice_edit.php?id=<?= (int)$iv['id'] ?>" class="btn-sm btn-sm-success cbi-inv-action-btn" title="Edit"><i class="fa-solid fa-pen"></i></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- ═══════════════════ ORDERS TAB ═══════════════════ -->
        <?php if ($activeTab === 'orders'): ?>
        <div class="glass-panel cbi-panel">
            <?php if (empty($orders)): ?>
            <div class="cbi-empty-state">
                <div class="cbi-empty-icon">📋</div>
                <p class="cbi-empty-text">No orders yet. Share your shop and orders will appear here!</p>
                <a href="../order.php" target="_blank" class="btn-primary cbi-empty-cta">
                    <i class="fa-solid fa-globe"></i> Open Shop
                </a>
            </div>
            <?php else: ?>

            <!-- Customer Name Filter -->
            <div class="cbi-ord-filter-bar">
                <i class="fa-solid fa-magnifying-glass cbi-muted-lg"></i>
                <input type="text" id="orderNameFilter"
                       placeholder="Search by order code, customer name, store or phone…"
                       class="form-control cbi-ord-search-input"
                       oninput="filterOrders(this.value)">
                <button type="button" id="orderFilterClear" onclick="clearOrderFilter()" title="Clear search"
                        class="cbi-ord-filter-clear">
                    <i class="fa-solid fa-circle-xmark"></i>
                </button>
                <span id="orderFilterCount" class="cbi-ord-filter-count"></span>
            </div>

            <div class="table-wrapper">
                <table class="data-table cbi-ord-table" id="ordersTable">
                    <colgroup>
                        <col class="cbi-ord-col-expand">
                        <col class="cbi-ord-col-code">
                        <col class="cbi-ord-col-customer">
                        <col class="cbi-ord-col-items">
                        <col class="cbi-ord-col-total">
                        <col class="cbi-ord-col-status">
                        <col class="cbi-ord-col-status">
                        <col class="cbi-ord-col-date">
                        <col class="cbi-ord-col-actions">
                    </colgroup>
                    <thead>
                        <tr>
                            <th></th>
                            <th>Order Code</th>
                            <th>Customer</th>
                            <th>Items</th>
                            <th>Total</th>
                            <th onclick="toggleSort('status')" class="cbi-ord-sort-th">
                                Status <i id="sort-icon-status" class="fa-solid fa-sort"></i>
                            </th>
                            <th onclick="toggleSort('payment')" class="cbi-ord-sort-th">
                                Payment <i id="sort-icon-payment" class="fa-solid fa-sort"></i>
                            </th>
                            <th onclick="toggleSort('date')" class="cbi-ord-sort-th cbi-ord-sort-th-active">
                                Date <i id="sort-icon-date" class="fa-solid fa-sort-down"></i>
                            </th>
                            <th class="cbi-col-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $order):
                            $items = json_decode($order['items_json'], true) ?? [];
                            $statusClass = 'status-' . strtolower(str_replace(' ', '-', $order['status']));
                            $orderNotes   = $order['notes'] ?? '';
                            $isTradeOrder = !empty($order['trade_business_name']) || strpos($orderNotes, 'TRADE B2B ORDER') !== false;
                            $tradeStore   = $order['trade_business_name'] ?? '';
                            if (empty($tradeStore) && preg_match('/Store:\s*([^\]]+)/i', $orderNotes, $m)) {
                                $tradeStore = trim($m[1]);
                            }
                        ?>
                        <tr id="row-<?= $order['id'] ?>" class="order-row" data-id="<?= $order['id'] ?>" data-date="<?= strtotime($order['created_at']) ?>" data-status="<?= htmlspecialchars($order['status']) ?>" data-payment-status="<?= htmlspecialchars($order['payment_status'] ?? 'Unpaid') ?>" data-payment-method="<?= htmlspecialchars($order['payment_method'] ?? 'later') ?>" data-order-code="<?= htmlspecialchars($order['order_code'], ENT_QUOTES) ?>" data-customer="<?= htmlspecialchars($order['customer_name'], ENT_QUOTES) ?>" data-phone="<?= htmlspecialchars($order['phone'] ?? '', ENT_QUOTES) ?>" data-store="<?= htmlspecialchars($order['trade_business_name'] ?? '', ENT_QUOTES) ?>">
                            <td>
                                <button class="expand-btn" onclick="toggleDetail(<?= $order['id'] ?>)" title="View details">
                                    <i class="fa-solid fa-chevron-down" id="icon-<?= $order['id'] ?>"></i>
                                </button>
                            </td>
                            <td>
                                <span class="cbi-ord-code">
                                    <?= htmlspecialchars($order['order_code']) ?>
                                </span>
                            </td>
                            <td class="cbi-ord-customer-cell">
                                <?php if ($isTradeOrder): ?>
                                <div class="cbi-ord-trade-chip">
                                    <i class="fa-solid fa-store cbi-ord-trade-icon"></i>
                                    TRADE: <?= htmlspecialchars($tradeStore ?: 'Wholesale Partner') ?>
                                </div>
                                <div class="cbi-ord-trade-customer"><?= htmlspecialchars($order['customer_name']) ?></div>
                                <?php else: ?>
                                <span><?= htmlspecialchars($order['customer_name']) ?></span>
                                <?php endif; ?>

                                <?php if (isset($repeatPhoneSet[$order['phone']])): ?>
                                <span class="cbi-ord-repeat-chip">🔁</span>
                                <?php endif; ?>
                            </td>
                            <td class="cbi-ord-items-cell">
                                <?php
                                    $totalQty = array_sum(array_column($items, 'quantity'));
                                    $firstItem = $items[0] ?? null;
                                ?>
                                <?php if ($firstItem): ?>
                                <span class="items-pill cbi-badge-sm"><?= htmlspecialchars($firstItem['emoji'] ?? '🍦') ?> ×<?= (int)$firstItem['quantity'] ?></span>
                                <?php if (count($items) > 1): ?>
                                <span class="cbi-ord-more-items">+<?= count($items) - 1 ?> more</span>
                                <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td class="cbi-ord-total-cell">
                                £<?= number_format($order['total_price'], 2) ?>
                            </td>
                            <td class="cbi-ord-status-cell">
                                <span class="status-badge <?= $statusClass ?>">
                                    <?= htmlspecialchars($order['status']) ?>
                                </span>
                            </td>
                            <td>
                                <?php
                                    $ps = $order['payment_status'] ?? 'Unpaid';
                                    $pm = $order['payment_method'] ?? 'later';
                                    // The badge's colours come from its state class in
                                    // admin.css, so only the icon, wording and state
                                    // are decided here.
                                    if ($ps === 'Paid') {
                                        $payIcon = '<i class="fa-solid fa-circle-check"></i>';
                                        $payLabel = 'Paid';
                                        $payStateClass = 'is-paid';
                                    } elseif ($ps === 'Cash') {
                                        $payIcon = '<i class="fa-solid fa-money-bill-wave"></i>';
                                        $payLabel = 'Cash';
                                        $payStateClass = 'is-cash';
                                    } else {
                                        $payIcon = '<i class="fa-solid fa-clock"></i>';
                                        $payLabel = 'Unpaid';
                                        $payStateClass = 'is-unpaid';
                                    }
                                ?>
                                <span id="pay-badge-<?= $order['id'] ?>" class="cbi-ord-pay-badge <?= $payStateClass ?>">
                                    <?= $payIcon ?> <?= $payLabel ?>
                                </span>
                            </td>
                            <td class="cbi-ord-date-cell">
                                <?= date('d M y', strtotime($order['created_at'])) ?>
                                <div class="cbi-muted-xs"><?= date('H:i', strtotime($order['created_at'])) ?></div>
                            </td>
                            <td class="cbi-col-right">
                                <div class="cbi-ord-actions">
                                    <a href="delivery_note.php?code=<?= urlencode($order['order_code']) ?>" target="_blank" class="btn-sm btn-sm-outline cbi-ord-action-btn" title="Print Delivery Note & Invoice">
                                        <i class="fa-solid fa-print"></i>
                                    </a>
                                    <button class="expand-btn btn-sm btn-sm-outline cbi-ord-action-btn" onclick="toggleDetail(<?= $order['id'] ?>)" title="Details">
                                        <i class="fa-solid fa-eye"></i>
                                    </button>
                                    <button class="btn-sm btn-sm-danger cbi-ord-action-btn" onclick="deleteOrder(<?= $order['id'] ?>, '<?= htmlspecialchars($order['order_code'], ENT_QUOTES) ?>')" title="Delete">
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
                                    <div class="cbi-ord-customer-strip">
                                        <div class="cbi-ord-customer-facts">
                                            <?php if ($isTradeOrder): ?>
                                            <div class="cbi-ord-store-chip">
                                                <i class="fa-solid fa-store"></i> STORE: <?= htmlspecialchars($tradeStore ?: 'Trade Partner') ?>
                                            </div>
                                            <?php endif; ?>
                                            <div class="cbi-ord-fact">
                                                <i class="fa-solid fa-user cbi-ord-icon-user"></i>
                                                <strong><?= htmlspecialchars($order['customer_name']) ?></strong>
                                            </div>
                                            <div class="cbi-ord-fact-muted">
                                                <i class="fa-solid fa-phone cbi-ord-icon-phone"></i>
                                                <?= htmlspecialchars($order['phone']) ?>
                                            </div>
                                            <?php if (!empty($order['customer_email'])): ?>
                                            <div class="cbi-ord-fact-muted">
                                                <i class="fa-solid fa-envelope cbi-ord-icon-email"></i>
                                                <a href="mailto:<?= htmlspecialchars($order['customer_email']) ?>" class="cbi-ord-email-link"><?= htmlspecialchars($order['customer_email']) ?></a>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                        <div>
                                            <a href="delivery_note.php?code=<?= urlencode($order['order_code']) ?>" target="_blank" class="btn-sm btn-sm-primary cbi-ord-print-btn">
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
                                            <p><?= !empty($order['notes']) ? nl2br(htmlspecialchars($order['notes'])) : '<span class="cbi-muted">None</span>' ?></p>
                                        </div>
                                    </div>
                                    <!-- Items table -->
                                    <table class="cbi-ord-items-table">
                                        <thead>
                                            <tr class="cbi-ord-items-head">
                                                <th class="cbi-ord-items-th-left">Item</th>
                                                <th class="cbi-ord-items-th-center">Qty</th>
                                                <th class="cbi-ord-items-th-right">Price</th>
                                                <th class="cbi-ord-items-th-right">Subtotal</th>
                                                <th class="cbi-ord-item-action-col"></th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                        <?php foreach ($items as $itemIdx => $it): ?>
                                        <tr>
                                            <td class="cbi-ord-item-name"><?= htmlspecialchars($it['emoji'] ?? '🍦') ?> <?= htmlspecialchars($it['name']) ?><?= !empty($it['variant_name']) ? ' <span class="cbi-muted-xs">('.htmlspecialchars($it['variant_name']).')</span>' : '' ?></td>
                                            <td class="cbi-ord-item-qty">× <?= (int)$it['quantity'] ?></td>
                                            <td class="cbi-ord-item-price">£<?= number_format($it['price'], 2) ?></td>
                                            <td class="cbi-ord-item-subtotal">£<?= number_format($it['price'] * $it['quantity'], 2) ?></td>
                                            <td class="cbi-ord-item-action">
                                                <?php if (count($items) > 1): ?>
                                                <button type="button" class="btn-danger cbi-ord-item-remove"
                                                        title="Can't supply this item — remove it from the order"
                                                        onclick="removeOrderItem(<?= (int)$order['id'] ?>, <?= (int)$itemIdx ?>, <?= htmlspecialchars(json_encode(trim(($it['name'] ?? '') . ' ' . ($it['variant_name'] ?? ''))), ENT_QUOTES) ?>)">
                                                    <i class="fa-solid fa-xmark"></i>
                                                </button>
                                                <?php endif; ?>
                                            </td>
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
                                            <tr class="cbi-ord-total-row">
                                                <td colspan="3" class="cbi-ord-foot-label">Subtotal</td>
                                                <td class="cbi-ord-subtotal-value">£<?= number_format($subtotal, 2) ?></td>
                                            </tr>
                                            <?php if (!empty($order['promo_code']) && $order['discount_amount'] > 0): ?>
                                            <tr>
                                                <td colspan="3" class="cbi-ord-foot-label">🎟️ Promo: <strong><?= htmlspecialchars($order['promo_code']) ?></strong></td>
                                                <td class="cbi-ord-discount-value">−£<?= number_format($order['discount_amount'], 2) ?></td>
                                            </tr>
                                            <?php endif; ?>
                                            <?php if ((float)($order['delivery_charge'] ?? 0) > 0): ?>
                                            <tr>
                                                <td colspan="3" class="cbi-ord-foot-label">🚚 Delivery Charge</td>
                                                <td class="cbi-ord-delivery-value">+£<?= number_format($order['delivery_charge'], 2) ?></td>
                                            </tr>
                                            <?php endif; ?>
                                            <tr class="cbi-ord-total-row">
                                                <td colspan="3" class="cbi-ord-total-label">Total</td>
                                                <td class="cbi-ord-grand-total">£<?= number_format($order['total_price'], 2) ?></td>
                                            </tr>
                                        </tfoot>
                                    </table>

                                    <!-- Status + Payment update row -->
                                    <div class="cbi-ord-update-bar">

                                        <!-- Order Status -->
                                        <div class="cbi-inline-row">
                                            <label class="cbi-ord-field-label">Order Status</label>
                                            <select class="status-select" id="status-<?= $order['id'] ?>">
                                                <?php foreach (['Pending','Processing','Delivered','Cancelled'] as $s): ?>
                                                <option value="<?= $s ?>" <?= $order['status']===$s ? 'selected' : '' ?>><?= $s ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button class="btn-sm btn-sm-primary" onclick="updateStatus(<?= $order['id'] ?>, '<?= $order['order_code'] ?>')">
                                                <i class="fa-solid fa-check"></i> Save
                                            </button>
                                        </div>

                                        <div class="cbi-ord-divider"></div>

                                        <!-- Payment Status -->
                                        <?php if (($order['payment_status'] ?? '') === 'Paid'): ?>
                                        <div class="cbi-ord-paid-locked">
                                            <i class="fa-solid fa-lock cbi-badge-sm"></i> ✅ Paid Online (Locked)
                                        </div>
                                        <?php else: ?>
                                        <div class="cbi-inline-row">
                                            <label class="cbi-ord-field-label">Payment</label>
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

                                        <span id="status-msg-<?= $order['id'] ?>" class="cbi-ord-status-msg"></span>
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
        <div class="glass-panel cbi-panel-lg">
            <div class="cbi-trade-header">
                <div>
                    <h2 class="cbi-trade-title">
                        <span>🏪</span> Trade Partner Applications & Wholesale Accounts
                    </h2>
                    <p class="cbi-trade-subtitle">Review, approve, or reject retail store applications for wholesale access.</p>
                </div>
                <a href="../trade_register.php" target="_blank" class="btn-secondary cbi-trade-register-btn">
                    <i class="fa-solid fa-arrow-up-right-from-square"></i> Open Registration Form
                </a>
            </div>

            <?php
            $tradePending  = count(array_filter($tradeUsers, fn($u) => $u['status'] === 'pending'));
            $tradeApproved = count(array_filter($tradeUsers, fn($u) => $u['status'] === 'approved'));
            $tradeRejected = count(array_filter($tradeUsers, fn($u) => $u['status'] === 'rejected'));
            ?>

            <!-- Trade Quick Stats -->
            <div class="cbi-trade-stats-grid">
                <div class="cbi-trade-stat">
                    <div class="cbi-trade-stat-icon">🏪</div>
                    <div>
                        <div class="cbi-trade-stat-value"><?= count($tradeUsers) ?></div>
                        <div class="cbi-trade-stat-label">Total Applications</div>
                    </div>
                </div>
                <div class="cbi-trade-stat-pending">
                    <div class="cbi-trade-stat-icon">⏳</div>
                    <div>
                        <div class="cbi-trade-stat-value-pending"><?= $tradePending ?></div>
                        <div class="cbi-trade-stat-label-pending">Pending Review</div>
                    </div>
                </div>
                <div class="cbi-trade-stat-approved">
                    <div class="cbi-trade-stat-icon">✅</div>
                    <div>
                        <div class="cbi-trade-stat-value-approved"><?= $tradeApproved ?></div>
                        <div class="cbi-trade-stat-label-approved">Approved Partners</div>
                    </div>
                </div>
                <div class="cbi-trade-stat-rejected">
                    <div class="cbi-trade-stat-icon">❌</div>
                    <div>
                        <div class="cbi-trade-stat-value-rejected"><?= $tradeRejected ?></div>
                        <div class="cbi-trade-stat-label-rejected">Rejected</div>
                    </div>
                </div>
            </div>

            <?php if (empty($tradeUsers)): ?>
            <div class="cbi-trade-empty">
                <div class="cbi-trade-empty-icon">🏪</div>
                <h3 class="cbi-trade-empty-title">No trade applications yet</h3>
                <p class="cbi-trade-empty-text">
                    Store owners can apply for wholesale pricing at <br>
                    <a href="../trade_register.php" target="_blank" class="cbi-trade-empty-link">orders.creamybite.com/trade_register.php</a>
                </p>
            </div>
            <?php else: ?>
            
            <!-- Search by store or contact person -->
            <div class="cbi-trade-searchbar">
                <div class="cbi-trade-search-wrap">
                    <i class="fa-solid fa-magnifying-glass cbi-trade-search-icon"></i>
                    <input type="text" id="tradeSearch" class="form-control cbi-trade-search-input"
                           placeholder="Search by store name or contact person…"
                           autocomplete="off" oninput="filterTradeAccounts(this.value)">
                    <button type="button" id="tradeSearchClear" class="cbi-trade-search-clear is-hidden"
                            onclick="clearTradeSearch()" aria-label="Clear search">
                        <i class="fa-solid fa-xmark"></i>
                    </button>
                </div>
            </div>

            <!-- Applications Table -->
            <div class="table-wrapper">
                <table class="data-table cbi-table-full" id="tradeTable">
                    <thead>
                        <tr>
                            <?php /* Ten columns did not fit the content area once the sidebar
                                      took 248px — the approve/reject buttons ended up off-screen.
                                      Contact person now sits under the business name, and the
                                      applied date under the status, giving eight columns that fit. */ ?>
                            <th>ID</th>
                            <th class="inv-sort" onclick="sortTrade(1,'text',this)">Store / Business <i class="fa-solid fa-sort"></i></th>
                            <th>Email & Phone</th>
                            <th>🔑 Password</th>
                            <th>Delivery Address</th>
                            <th>VAT / Reg No</th>
                            <th class="inv-sort" onclick="sortTrade(6,'number',this)">Status <i class="fa-solid fa-sort"></i></th>
                            <th class="cbi-col-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tradeUsers as $tu):
                            $st = $tu['status'];
                            // Rank rather than label, so sorting by status puts the
                            // applications that need a decision at the top instead of
                            // ordering them alphabetically.
                            if ($st === 'approved') {
                                $stBadge = '<span class="cbi-trade-badge-approved"><i class="fa-solid fa-circle-check"></i> Approved</span>';
                                $stRank  = 1;
                            } elseif ($st === 'rejected') {
                                $stBadge = '<span class="cbi-trade-badge-rejected"><i class="fa-solid fa-circle-xmark"></i> Rejected</span>';
                                $stRank  = 2;
                            } else {
                                $stBadge = '<span class="cbi-trade-badge-pending"><i class="fa-solid fa-clock"></i> Pending Review</span>';
                                $stRank  = 0;
                            }
                            $rawPass = !empty($tu['raw_password']) ? htmlspecialchars($tu['raw_password']) : null;
                        ?>
                        <tr class="cbi-row-divider trade-row"
                            data-sort1="<?= htmlspecialchars($tu['business_name'], ENT_QUOTES) ?>"
                            data-sort6="<?= $stRank ?>"
                            data-search="<?= htmlspecialchars($tu['business_name'] . ' ' . $tu['contact_name'] . ' ' . $tu['email'], ENT_QUOTES) ?>">
                            <td class="cbi-trade-id">#<?= $tu['id'] ?></td>
                            <td>
                                <strong class="cbi-trade-business-name">
                                    <span>🏬</span> <?= htmlspecialchars($tu['business_name']) ?>
                                </strong>
                                <div class="cbi-trade-contact-person">
                                    <i class="fa-solid fa-user cbi-trade-contact-icon"></i>
                                    <?= htmlspecialchars($tu['contact_name']) ?>
                                </div>
                            </td>
                            <td class="cbi-trade-cell">
                                <a href="mailto:<?= htmlspecialchars($tu['email']) ?>" class="cbi-trade-email-link">
                                    <i class="fa-solid fa-envelope cbi-trade-link-icon"></i> <?= htmlspecialchars($tu['email']) ?>
                                </a>
                                <a href="tel:<?= htmlspecialchars($tu['phone']) ?>" class="cbi-trade-phone-link">
                                    <i class="fa-solid fa-phone cbi-trade-link-icon"></i> <?= htmlspecialchars($tu['phone']) ?>
                                </a>
                            </td>
                            <td class="cbi-trade-cell">
                                <?php if ($rawPass): ?>
                                <span class="cbi-trade-password">
                                    <i class="fa-solid fa-key cbi-badge-sm"></i> <?= $rawPass ?>
                                </span>
                                <?php else: ?>
                                <span class="cbi-muted-xs">(Hashed Password)</span>
                                <?php endif; ?>
                            </td>
                            <td class="cbi-trade-address-cell">
                                <div><?= htmlspecialchars($tu['address']) ?></div>
                                <span class="cbi-trade-postcode"><?= htmlspecialchars($tu['postcode']) ?></span>
                            </td>
                            <td class="cbi-trade-vat-cell">
                                <?= !empty($tu['vat_number']) ? '<span class="cbi-trade-vat">' . htmlspecialchars($tu['vat_number']) . '</span>' : '<span class="cbi-muted">None</span>' ?>
                            </td>
                            <td class="cbi-nowrap">
                                <?= $stBadge ?>
                                <div class="cbi-stat-subnote">
                                    Applied <?= date('d M Y', strtotime($tu['created_at'])) ?>
                                </div>
                            </td>
                            <td class="cbi-actions-cell">
                                <div class="cbi-trade-actions">
                                    <a href="trade_report.php?id=<?= (int)$tu['id'] ?>" class="btn-sm btn-sm-info cbi-trade-action-btn" title="Sales history, top products and order frequency">
                                        <i class="fa-solid fa-chart-line"></i> Report
                                    </a>
                                    <?php if ($st !== 'approved'): ?>
                                    <a href="<?= htmlspecialchars(csrfUrl('index.php?tab=trade&action=approve_trade&id=' . (int)$tu['id'])) ?>" class="btn-sm btn-sm-success cbi-trade-action-btn" data-confirm="Approve <?= htmlspecialchars($tu['business_name'], ENT_QUOTES) ?> as a trade partner? They will get wholesale pricing immediately." data-confirm-title="Approve trade account?" data-confirm-tone="success" data-confirm-ok="Approve">
                                        <i class="fa-solid fa-check"></i> Approve
                                    </a>
                                    <?php endif; ?>
                                    <?php if ($st !== 'rejected'): ?>
                                    <a href="<?= htmlspecialchars(csrfUrl('index.php?tab=trade&action=reject_trade&id=' . (int)$tu['id'])) ?>" class="btn-sm btn-sm-danger cbi-trade-action-btn" data-confirm="Reject the trade application from <?= htmlspecialchars($tu['business_name'], ENT_QUOTES) ?>? They will not get wholesale pricing." data-confirm-title="Reject trade account?" data-confirm-tone="danger" data-confirm-ok="Reject">
                                        <i class="fa-solid fa-xmark"></i> Reject
                                    </a>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <tr id="tradeNoMatch" class="is-hidden">
                            <td colspan="8" class="cbi-trade-nomatch">
                                No trade account matches that search.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- ═══════════════════ PRODUCTS TAB ═════════════════ -->
        <?php elseif ($activeTab === 'products'): ?>
        <div class="glass-panel cbi-panel">
            <?php if (empty($products)): ?>
            <div class="cbi-empty-state">
                <div class="cbi-empty-icon">🍦</div>
                <p class="cbi-empty-text">No products yet.</p>
                <a href="product_form.php" class="btn-primary cbi-empty-cta">
                    <i class="fa-solid fa-plus"></i> Add First Product
                </a>
            </div>
            <?php else: ?>
            
            <!-- Filter & Sort Bar -->
            <div class="cbi-prod-filter-bar">
                <div class="cbi-inline-row">
                    <label for="prodFilterCategory" class="cbi-filter-label">Category:</label>
                    <select id="prodFilterCategory" class="form-control cbi-select-compact" onchange="filterAndSortProducts()">
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
                
                <div class="cbi-inline-row">
                    <label for="prodSort" class="cbi-filter-label">Sort By:</label>
                    <select id="prodSort" class="form-control cbi-select-compact" onchange="filterAndSortProducts()">
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
                            <th class="cbi-col-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($products as $p): ?>
                        <tr class="product-row" data-id="<?= $p['id'] ?>" data-name="<?= htmlspecialchars($p['name'], ENT_QUOTES) ?>" data-category="<?= htmlspecialchars($p['category'], ENT_QUOTES) ?>" data-price="<?= $p['price'] ?>">
                            <td>
                                <?php if (!empty($p['image'])): ?>
                                <img src="../assets/images/products/<?= htmlspecialchars($p['image']) ?>"
                                     alt="<?= htmlspecialchars($p['name']) ?>"
                                     class="cbi-prod-thumb">
                                <?php else: ?>
                                <span class="cbi-prod-emoji"><?= htmlspecialchars($p['emoji'] ?? '🍦') ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="cbi-name-strong">
                                    <?= htmlspecialchars($p['name']) ?>
                                    <?php if (!empty($p['trade_only'])): ?>
                                    <span title="Hidden from the public website — trade partners only"
                                          class="cbi-prod-trade-only">
                                        <i class="fa-solid fa-store cbi-prod-trade-only-icon"></i> TRADE ONLY
                                    </span>
                                    <?php endif; ?>
                                </div>
                                <div class="cbi-prod-desc">
                                    <?= htmlspecialchars($p['description']) ?>
                                </div>
                            </td>
                            <td>
                                <span class="cbi-prod-category">
                                    <?= htmlspecialchars($p['category']) ?>
                                </span>
                            </td>
                            <td class="cbi-prod-price">
                                £<?= number_format($p['price'], 2) ?>
                            </td>
                            <td>
                                <?php if (!empty($p['badge'])): ?>
                                    <?php $bc = $p['badge']==='New' ? 'badge-new' : ($p['badge']==='Hot' ? 'badge-hot' : 'badge-best-seller'); ?>
                                    <span class="product-badge <?= $bc ?> cbi-prod-badge">
                                        <?= htmlspecialchars($p['badge']) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="cbi-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($p['available']): ?>
                                <span class="status-badge status-delivered cbi-badge-sm">✅ Yes</span>
                                <?php else: ?>
                                <span class="status-badge status-cancelled cbi-badge-sm">❌ No</span>
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
                                <span class="cbi-prod-stock-in">
                                    🟢 <?= $pIns ?> left
                                </span>
                                <?php else: ?>
                                <span class="cbi-prod-stock-out">🔴 Out of Stock</span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="cbi-muted-xs">∞ Unlimited</span>
                            <?php endif; ?>
                            </td>
                            <td class="cbi-col-right">
                                <div class="action-group cbi-actions-end">
                                    <a href="product_form.php?id=<?= $p['id'] ?>" class="btn-sm btn-sm-outline">
                                        <i class="fa-solid fa-pen"></i> Edit
                                    </a>
                                    <a href="<?= htmlspecialchars(csrfUrl('index.php?action=delete_product&tab=products&id=' . (int)$p['id'])) ?>"
                                       class="btn-sm btn-sm-danger"
                                       data-confirm="Delete &quot;<?= htmlspecialchars($p['name'], ENT_QUOTES) ?>&quot;? This cannot be undone, and any sizes on it go too." data-confirm-title="Delete product?" data-confirm-tone="danger" data-confirm-ok="Delete">
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
        <div class="glass-panel cbi-panel">

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
            <div class="cbi-stock-summary-grid">
                <div class="cbi-stock-card-total">
                    <div class="cbi-stock-card-icon">📦</div>
                    <div class="cbi-stock-card-value-total"><?= $grandTotal ?></div>
                    <div class="cbi-stock-card-label">Grand Total</div>
                </div>
                <div class="cbi-stock-card-instock">
                    <div class="cbi-stock-card-icon">🟢</div>
                    <div class="cbi-stock-card-value-instock"><?= $totalInStock ?></div>
                    <div class="cbi-stock-card-label">In Stock</div>
                </div>
                <div class="cbi-stock-card-damage">
                    <div class="cbi-stock-card-icon">⚠️</div>
                    <div class="cbi-stock-card-value-damage"><?= $totalDamage ?></div>
                    <div class="cbi-stock-card-label">Damage</div>
                </div>
                <div class="cbi-stock-card-offline">
                    <div class="cbi-stock-card-icon">🏪</div>
                    <div class="cbi-stock-card-value-offline"><?= $totalOffline ?></div>
                    <div class="cbi-stock-card-label">Sold Offline</div>
                </div>
                <div class="cbi-stock-card-online">
                    <div class="cbi-stock-card-icon">🛒</div>
                    <div class="cbi-stock-card-value-online"><?= $totalOnline ?></div>
                    <div class="cbi-stock-card-label">Sold Online</div>
                </div>
            </div>

            <?php if (empty($stockProducts)): ?>
            <div class="cbi-empty-state">
                <div class="cbi-empty-icon">📦</div>
                <p class="cbi-empty-text">No products yet. <a href="product_form.php" class="btn-primary cbi-stock-empty-cta"><i class="fa-solid fa-plus"></i> Add Product</a></p>
            </div>
            <?php else: ?>

            <!-- Setup warning -->
            <?php if (!$stockMigrationDone || !$stockV2Done): ?>
            <div class="cbi-stock-setup-warning">
                <div class="cbi-stock-warning-body">
                    <i class="fa-solid fa-triangle-exclamation cbi-stock-warning-icon"></i>
                    <div><strong class="cbi-stock-warning-title">Database setup required</strong><br>Some stock columns are missing. <a href="setup_stock.php">Run Setup Now →</a></div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Info note -->
            <div class="cbi-stock-info-note">
                <i class="fa-solid fa-circle-info cbi-stock-info-icon"></i>
                <span><strong>Click ‘Edit Stock’ to add new stock, damage, or offline sales.</strong> Grand Total, In Stock, Damage and Sold columns are all read-only — updated when you save via the Edit button. Sold Online auto-counts when an order is marked Delivered.</span>
            </div>

            <div class="table-wrapper">
                <table class="data-table" id="stockTable">
                    <thead>
                        <tr>
                            <?php /* Headers kept short — the "(auto-cumulative)" style hints
                                      used to sit under each one and made the table 114px wider
                                      than its column, clipping the Edit button. The banner
                                      above already explains which columns are read-only, so
                                      the detail lives in a tooltip now. */ ?>
                            <th>Product</th>
                            <th class="cbi-stock-col-center">Tracked</th>
                            <th class="cbi-stock-th-total" title="Grand total — cumulative, updated when you save via Edit Stock">📦 Total</th>
                            <th class="cbi-stock-th-instock" title="In stock — calculated as Total − Damage − Sold Offline − Sold Online">🟢 In Stock</th>
                            <th class="cbi-stock-th-damage" title="Damaged / written off — cumulative">⚠️ Damage</th>
                            <th class="cbi-stock-th-offline" title="Sold in person — cumulative">🏪 Offline</th>
                            <th class="cbi-stock-th-online" title="Sold through the website — counts automatically when an order is placed">🛒 Online</th>
                            <th class="cbi-stock-col-center">Actions</th>
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
                                <div class="cbi-stock-product-cell">
                                    <?php if (!empty($sp['image'])): ?>
                                    <img src="../assets/images/products/<?= htmlspecialchars($sp['image']) ?>"
                                         class="cbi-stock-thumb">
                                    <?php else: ?>
                                    <span class="cbi-stock-emoji"><?= htmlspecialchars($sp['emoji'] ?? '🍦') ?></span>
                                    <?php endif; ?>
                                    <div>
                                        <div class="cbi-stock-product-name"><?= htmlspecialchars($sp['name']) ?></div>
                                        <div class="cbi-stock-product-cat"><?= htmlspecialchars($sp['category']) ?></div>
                                    </div>
                                </div>
                            </td>
                            <!-- Tracked -->
                            <td class="cbi-stock-col-center">
                                <?php if ($sp['track_stock']): ?>
                                <span class="cbi-stock-tracked-yes">Yes</span>
                                <?php else: ?>
                                <span class="cbi-stock-tracked-no">&#8734; Unlimited</span>
                                <?php endif; ?>
                            </td>
                            <!-- Grand Total (read-only) -->
                            <td class="cbi-stock-col-center">
                                <span id="val-total_stock-<?= $sp['id'] ?>" class="cbi-stock-value-total"><?= $ts ?></span>
                            </td>
                            <!-- In Stock (auto, read-only) -->
                            <td class="cbi-stock-col-center">
                                <?php if ($sp['track_stock']): ?>
                                <span id="val-in_stock-<?= $sp['id'] ?>" class="cbi-stock-value-instock <?= $ins > 0 ? 'is-in' : 'is-out' ?>"><?= $ins ?></span>
                                <?php else: ?>
                                <span class="cbi-muted-md">—</span>
                                <?php endif; ?>
                            </td>
                            <!-- Damage (read-only) -->
                            <td class="cbi-stock-col-center">
                                <span id="val-damage_stock-<?= $sp['id'] ?>" class="cbi-stock-value-damage"><?= $dmg ?></span>
                            </td>
                            <!-- Sold Offline (read-only) -->
                            <td class="cbi-stock-col-center">
                                <span id="val-sold_offline-<?= $sp['id'] ?>" class="cbi-stock-value-offline"><?= $off ?></span>
                            </td>
                            <!-- Sold Online (auto, read-only) -->
                            <td class="cbi-stock-col-center">
                                <span id="val-sold_online-<?= $sp['id'] ?>" class="cbi-stock-value-online"><?= $sol ?></span>
                            </td>
                            <!-- Actions -->
                            <td class="cbi-stock-col-center">
                                <div class="cbi-stock-actions">
                                    <?php if ($sp['track_stock']): ?>
                                    <button class="btn-sm btn-primary cbi-stock-edit-btn" onclick="openStockEdit(<?= $sp['id'] ?>, '<?= htmlspecialchars($sp['name'], ENT_QUOTES) ?>', <?= $ts ?>, <?= $dmg ?>, <?= $off ?>)">
                                        <i class="fa-solid fa-pen-to-square"></i> Edit Stock
                                    </button>
                                    <?php endif; ?>
                                    <a href="product_form.php?id=<?= $sp['id'] ?>" class="btn-sm btn-sm-outline cbi-stock-product-btn" title="Edit product">
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
        <div id="stockEditModal" class="cbi-stock-modal">
            <div class="cbi-stock-modal-box">
                <button onclick="closeStockEdit()" class="cbi-stock-modal-close">&#x2715;</button>
                <h3 id="stockEditTitle" class="cbi-stock-modal-title">Edit Stock</h3>
                <p id="stockEditSubtitle" class="cbi-stock-modal-sub">All values are additive — enter 0 to skip a field.</p>

                <div class="form-group cbi-gap-16">
                    <label class="form-label cbi-text-violet">📦 Add New Stock</label>
                    <input type="number" id="stockAddQty" class="form-control cbi-stock-qty-input" min="0" value="0"
                           placeholder="e.g. 50">
                    <small class="cbi-stock-field-hint">Adds to Grand Total &rarr; increases In Stock</small>
                </div>

                <div class="form-group cbi-gap-16">
                    <label class="form-label cbi-text-red">⚠️ Add Damage Qty</label>
                    <input type="number" id="stockDamageQty" class="form-control cbi-stock-qty-input" min="0" value="0"
                           placeholder="e.g. 5">
                    <small class="cbi-stock-field-hint">Adds to Damage &rarr; reduces In Stock</small>
                </div>

                <div class="form-group cbi-gap-20">
                    <label class="form-label cbi-text-amber">🏪 Add Sold Offline Qty</label>
                    <input type="number" id="stockOfflineQty" class="form-control cbi-stock-qty-input" min="0" value="0"
                           placeholder="e.g. 10">
                    <small class="cbi-stock-field-hint">Adds to Sold Offline &rarr; reduces In Stock</small>
                </div>

                <div id="stockEditMsg" class="cbi-stock-modal-msg"></div>
                <div class="cbi-stock-modal-actions">
                    <button class="btn-primary cbi-flex-1" onclick="saveStockEdit()"><i class="fa-solid fa-check"></i> Save</button>
                    <button class="btn-secondary cbi-flex-1" onclick="closeStockEdit()">Cancel</button>
                </div>
            </div>
        </div>

        <!-- ═══════════════════ REVENUE TAB ═══════════════════ -->
        <?php elseif ($activeTab === 'revenue'): ?>
        <?php
            $rFrom = $revData['from'] ?? date('Y-m-01');
            $rTo   = $revData['to']   ?? date('Y-m-d');
        ?>
        <!-- Where the money came from -->
        <?php /* Retail orders, trade orders and invoices raised directly are
                 counted separately. The grand total adds standalone invoices,
                 which no order row accounts for — the old "Total Revenue"
                 missed them entirely. */ ?>
        <div class="cbi-rev-source-grid">
            <div class="stat-card glass-panel cbi-rev-card-total">
                <div class="stat-card-icon">💷</div>
                <div class="stat-label">Total Revenue</div>
                <div class="stat-value cbi-rev-value-total">£<?= number_format($revData['grand_total'] ?? 0, 2) ?></div>
                <div class="cbi-stat-subnote">orders + direct invoices</div>
            </div>
            <div class="stat-card glass-panel cbi-rev-card-retail">
                <div class="stat-card-icon">🛍️</div>
                <div class="stat-label">Retail Customers</div>
                <div class="stat-value cbi-rev-value-retail">£<?= number_format($revData['retail'] ?? 0, 2) ?></div>
                <div class="cbi-stat-subnote"><?= (int)($revData['retail_count'] ?? 0) ?> paid order(s)</div>
            </div>
            <div class="stat-card glass-panel cbi-rev-card-trade">
                <div class="stat-card-icon">🏪</div>
                <div class="stat-label">Trade Customers</div>
                <div class="stat-value cbi-rev-value-trade">£<?= number_format($revData['trade'] ?? 0, 2) ?></div>
                <div class="cbi-stat-subnote"><?= (int)($revData['trade_count'] ?? 0) ?> paid order(s)</div>
            </div>
            <div class="stat-card glass-panel cbi-rev-card-invoices">
                <div class="stat-card-icon">🧾</div>
                <div class="stat-label">Direct Invoices</div>
                <div class="stat-value cbi-rev-value-invoices">£<?= number_format($revData['invoice_direct'] ?? 0, 2) ?></div>
                <div class="cbi-stat-subnote">
                    <?= (int)($revData['invoice_direct_count'] ?? 0) ?> invoice(s) not from an order<?php
                    if (($revData['invoice_outstanding'] ?? 0) > 0): ?><br>
                    <span class="cbi-rev-owed">£<?= number_format($revData['invoice_outstanding'], 2) ?> still owed</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- How it was paid -->
        <div class="cbi-rev-method-grid">
            <div class="stat-card glass-panel cbi-rev-card-orders">
                <div class="stat-card-icon">📊</div>
                <div class="stat-label">Order Revenue</div>
                <div class="stat-value cbi-rev-value-orders">£<?= number_format($revData['total'] ?? 0, 2) ?></div>
                <div class="cbi-stat-subnote">paid orders only</div>
            </div>
            <div class="stat-card glass-panel cbi-rev-card-online">
                <div class="stat-card-icon">💳</div>
                <div class="stat-label">Online (Card)</div>
                <div class="stat-value cbi-rev-value-online">£<?= number_format($revData['online'] ?? 0, 2) ?></div>
            </div>
            <div class="stat-card glass-panel cbi-rev-card-cash">
                <div class="stat-card-icon">💵</div>
                <div class="stat-label">Cash</div>
                <div class="stat-value cbi-rev-value-cash">£<?= number_format($revData['cash'] ?? 0, 2) ?></div>
            </div>
            <div class="stat-card glass-panel cbi-rev-card-unpaid">
                <div class="stat-card-icon">⏳</div>
                <div class="stat-label">Unpaid (<?= $revData['unpaid_count'] ?? 0 ?> orders)</div>
                <div class="stat-value cbi-rev-value-unpaid">£<?= number_format($revData['unpaid_total'] ?? 0, 2) ?></div>
            </div>
        </div>

        <!-- Date Filters + Download -->
        <div class="glass-panel cbi-rev-filter-panel">
            <div class="cbi-rev-filter-row">
                <span class="cbi-rev-filter-label"><i class="fa-solid fa-calendar"></i> Filter:</span>
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

                <form method="GET" action="index.php" class="cbi-rev-date-form">
                    <input type="hidden" name="tab" value="revenue">
                    <input type="date" name="rev_from" value="<?= $rFrom ?>" class="form-control cbi-rev-date-input">
                    <span class="cbi-muted-md">to</span>
                    <input type="date" name="rev_to"   value="<?= $rTo ?>"   class="form-control cbi-rev-date-input">
                    <button type="submit" class="btn-primary cbi-rev-apply-btn"><i class="fa-solid fa-filter"></i> Apply</button>
                </form>

                <button type="button" class="btn-primary cbi-rev-reports-btn"
                        onclick="cbTogglePanel('reportPanel');">
                    <i class="fa-solid fa-file-arrow-down"></i> Reports
                </button>
            </div>

            <!-- ── Report builder ───────────────────────────── -->
            <div id="reportPanel" class="cbi-rev-report-panel" hidden>
                <form method="GET" action="reports.php" target="_blank" id="reportForm"
                      class="cbi-rev-report-grid">

                    <div>
                        <label class="form-label">Report</label>
                        <select name="type" class="form-control">
                            <option value="summary">Summary — everything at a glance</option>
                            <option value="sales">Sales — order by order</option>
                            <option value="clients">Clients — who spent what</option>
                            <option value="products">Products — what sold</option>
                            <option value="payments">Payments — how it was paid</option>
                            <option value="invoices">Invoices — issued and outstanding</option>
                            <option value="reps">Sales reps — sold, stores and commission</option>
                        </select>
                    </div>

                    <div>
                        <label class="form-label">Period</label>
                        <select name="period" id="reportPeriod" class="form-control"
                                onchange="document.getElementById('customRange').style.display = this.value==='custom' ? 'grid' : 'none';">
                            <option value="this_month">This month</option>
                            <option value="last_month">Last month</option>
                            <option value="this_quarter">This quarter</option>
                            <option value="this_year">This year</option>
                            <option value="last_year">Last year</option>
                            <option value="all_time">All time</option>
                            <option value="custom">Custom range…</option>
                        </select>
                    </div>

                    <div id="customRange" class="cbi-rev-custom-range">
                        <div>
                            <label class="form-label">From</label>
                            <input type="date" name="from" value="<?= $rFrom ?>" class="form-control">
                        </div>
                        <div>
                            <label class="form-label">To</label>
                            <input type="date" name="to" value="<?= $rTo ?>" class="form-control">
                        </div>
                    </div>

                    <div class="cbi-rev-report-actions">
                        <button type="submit" name="format" value="html" class="btn-primary cbi-rev-report-btn">
                            <i class="fa-solid fa-file-pdf"></i> View / PDF
                        </button>
                        <button type="submit" name="format" value="csv" class="btn-secondary cbi-rev-report-btn">
                            <i class="fa-solid fa-file-csv"></i> Excel
                        </button>
                    </div>
                </form>
                <p class="cbi-rev-report-note">
                    “View / PDF” opens a print-ready report — use your browser’s <strong>Print → Save as PDF</strong>.
                    “Excel” downloads a CSV that opens directly in Excel, Numbers or Google Sheets.
                </p>
            </div>
        </div>

        <div class="cbi-rev-two-col">
            <!-- Category Revenue Table -->
            <div class="glass-panel cbi-panel-md">
                <h3 class="cbi-rev-cat-heading">
                    <i class="fa-solid fa-tags cbi-icon-primary"></i> Revenue by Category
                    <span class="cbi-rev-cat-range"><?= date('d M', strtotime($rFrom)) ?> – <?= date('d M Y', strtotime($rTo)) ?></span>
                </h3>
                <?php if (empty($revData['cat_revenue'])): ?>
                <p class="cbi-muted-md">No paid orders in this period.</p>
                <?php else: ?>
                <table class="cbi-rev-cat-table">
                    <thead>
                        <tr class="cbi-rev-cat-head">
                            <th class="cbi-rev-th-left">Category</th>
                            <th class="cbi-rev-th-right">Revenue</th>
                            <th class="cbi-rev-th-right">Qty Sold</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php
                        $totalCatRev = array_sum(array_column($revData['cat_revenue'], 'revenue'));
                        foreach ($revData['cat_revenue'] as $cat => $cdata):
                            $pct = $totalCatRev > 0 ? round($cdata['revenue'] / $totalCatRev * 100) : 0;
                    ?>
                        <tr class="cbi-row-divider">
                            <td class="cbi-rev-cat-cell">
                                <div class="cbi-rev-cat-name"><?= htmlspecialchars($cat) ?></div>
                                <div class="cbi-rev-bar-track">
                                    <div class="cbi-rev-bar-fill" data-pct="<?= (float)$pct ?>"></div>
                                </div>
                            </td>
                            <td class="cbi-rev-cat-revenue">£<?= number_format($cdata['revenue'], 2) ?></td>
                            <td class="cbi-rev-cat-qty"><?= $cdata['qty'] ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
            </div>

            <!-- Product performance -->
            <?php
            /**
             * A product's colour, fixed by its name.
             *
             * The point is that the same product is the same colour in BOTH
             * tables — so you can see at a glance that this month's number one
             * is also the all-time number three. Deriving the hue from a hash of
             * the name means that holds across page loads and however the
             * rankings shuffle, with no colour column to maintain.
             */
            function cbProductHue(string $name): int
            {
                return (int)(hexdec(substr(md5($name), 0, 6)) % 360);
            }

            /** One product table: rank, colour, bar, units, revenue, share. */
            function cbRenderProductTable(array $rows, array $revenue, string $emptyText): void
            {
                if (empty($rows)) {
                    echo '<p class="cbi-muted-md">' . htmlspecialchars($emptyText) . '</p>';
                    return;
                }
                $top   = max($rows);
                $total = array_sum($rows);
                ?>
                <table class="cbi-prod-table">
                    <thead>
                        <tr>
                            <th class="cbi-prod-col-rank">#</th>
                            <th>Product</th>
                            <th class="cbi-prod-col-bar">Share of units</th>
                            <th class="cbi-prod-col-num">Units</th>
                            <th class="cbi-prod-col-num">Revenue</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php $i = 0; foreach ($rows as $name => $qty): $i++; ?>
                        <tr>
                            <td class="cbi-prod-rank"><?= $i ?></td>
                            <td class="cbi-prod-name">
                                <span class="cbi-prod-dot" data-hue="<?= cbProductHue($name) ?>"></span>
                                <?= htmlspecialchars($name) ?>
                            </td>
                            <td class="cbi-prod-col-bar">
                                <div class="cbi-prod-bar-track">
                                    <div class="cbi-prod-bar-fill"
                                         data-hue="<?= cbProductHue($name) ?>"
                                         data-pct="<?= $top > 0 ? round($qty / $top * 100, 1) : 0 ?>"></div>
                                </div>
                                <span class="cbi-prod-share">
                                    <?= $total > 0 ? number_format($qty / $total * 100, 1) : '0.0' ?>%
                                </span>
                            </td>
                            <td class="cbi-prod-col-num"><strong><?= (int)$qty ?></strong></td>
                            <td class="cbi-prod-col-num">£<?= number_format((float)($revenue[$name] ?? 0), 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="3">Top <?= count($rows) ?></td>
                            <td class="cbi-prod-col-num"><strong><?= (int)$total ?></strong></td>
                            <td class="cbi-prod-col-num">
                                £<?= number_format(array_sum(array_intersect_key($revenue, $rows)), 2) ?>
                            </td>
                        </tr>
                    </tfoot>
                </table>
                <?php
            }
            ?>
            <div class="cbi-rev-charts-col">
                <div class="glass-panel cbi-panel-sm">
                    <h3 class="cbi-rev-chart-heading">
                        <i class="fa-solid fa-trophy cbi-text-violet"></i> All-time top products
                        <span class="cbi-rev-chart-sub">by units sold, paid orders only</span>
                    </h3>
                    <?php cbRenderProductTable(
                        $revData['chart_alltime'] ?? [],
                        $revData['revenue_alltime'] ?? [],
                        'No paid orders yet.'
                    ); ?>
                </div>
                <div class="glass-panel cbi-panel-sm">
                    <h3 class="cbi-rev-chart-heading">
                        <i class="fa-solid fa-calendar-days cbi-text-green"></i> This month
                        <span class="cbi-rev-chart-sub">same colours as above, so you can compare</span>
                    </h3>
                    <?php cbRenderProductTable(
                        $revData['chart_thismonth'] ?? [],
                        $revData['revenue_thismonth'] ?? [],
                        'No sales this month yet.'
                    ); ?>
                </div>
            </div>
        </div>

        <!-- ═══════════════════ GALLERY TAB ══════════════════ -->
        <?php elseif ($activeTab === 'gallery'): ?>
        <div class="glass-panel cbi-panel-lg">

            <!-- Upload zone -->
            <h3 class="cbi-section-heading">
                <i class="fa-solid fa-cloud-arrow-up"></i> Upload New Photo
            </h3>

            <div id="galleryUploadResult"></div>

            <form id="galleryUploadForm" class="cbi-gap-32">
                <div class="gallery-upload-zone" id="galleryDropZone">
                    <input type="file" name="gallery_image" id="galleryFileInput"
                           accept="image/jpeg,image/png,image/webp,image/gif"
                           onchange="triggerGalleryUpload(this)">
                    <div class="cbi-gal-drop-icon">📸</div>
                    <p class="cbi-gal-drop-text">
                        <strong class="cbi-icon-primary">Click to upload</strong> or drag & drop<br>
                        JPG, PNG, WebP, or GIF — max 8MB
                    </p>
                </div>
                <div class="cbi-gal-caption-row">
                    <input type="text" id="galleryCaption" placeholder="Optional caption…"
                           class="form-control cbi-gal-caption-input">
                </div>
            </form>

            <h3 class="cbi-section-heading">
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
                <div id="galleryEmptyMsg" class="cbi-gal-empty">
                    No photos yet. Upload your first one above!
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ═══════════════════ CATEGORIES TAB ══════════════ -->
        <?php elseif ($activeTab === 'categories'): ?>
        <div class="glass-panel cbi-panel-lg">

            <!-- Add category form -->
            <h3 class="cbi-section-heading">
                <i class="fa-solid fa-plus"></i> Add New Category
            </h3>
            <div id="catFormMsg" class="cbi-gap-12"></div>
            <div class="cbi-cat-add-row">
                <input type="text" id="newCatName" placeholder="Category name e.g. Sorbets"
                       class="form-control cbi-cat-name-input">
                <button class="btn-primary cbi-nowrap" onclick="addCategory()">
                    <i class="fa-solid fa-plus"></i> Add Category
                </button>
            </div>

            <h3 class="cbi-section-heading">
                <i class="fa-solid fa-list"></i> Current Categories
            </h3>

            <!-- Category Search & Sort Bar -->
            <div class="cbi-cat-filter-bar">
                <div class="cbi-cat-search-group">
                    <i class="fa-solid fa-magnifying-glass cbi-cat-search-icon"></i>
                    <input type="text" id="catSearch" placeholder="Search categories..." class="form-control cbi-cat-search-input" oninput="filterAndSortCategories()">
                </div>
                
                <div class="cbi-inline-row">
                    <label for="catSort" class="cbi-cat-sort-label">Sort By:</label>
                    <select id="catSort" class="form-control cbi-select-compact" onchange="filterAndSortCategories()">
                        <option value="sort_order">Default (Sort Order)</option>
                        <option value="name_asc">Name (A-Z)</option>
                        <option value="name_desc">Name (Z-A)</option>
                    </select>
                </div>
            </div>

            <div class="glass-panel cbi-cat-list-panel" id="catListContainer">
                <?php foreach ($catList as $cat): ?>
                <div class="cat-list-item" id="catrow-<?= $cat['id'] ?>" data-order="<?= (int)$cat['sort_order'] ?>">
                    <div class="cbi-cat-row-left">
                        <i class="fa-solid fa-grip-vertical cbi-muted-md"></i>
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
                <div class="cbi-cat-empty">No categories yet.</div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- ═══════════════════ PROMOS TAB ════════════════════ -->
        <?php if ($activeTab === 'promos'): ?>
        <div class="glass-panel cbi-panel-lg">
            <div class="admin-page-header cbi-gap-24">
                <div>
                    <h2 class="admin-page-title cbi-flush">🎟️ Promo Codes</h2>
                    <p class="admin-page-subtitle cbi-subtitle-gap">Create and manage discount codes for your customers</p>
                </div>
            </div>

            <!-- Create new promo code -->
            <div class="cbi-promo-create-box">
                <h3 class="cbi-promo-create-title">
                    <i class="fa-solid fa-plus-circle cbi-icon-secondary"></i> Create New Promo Code
                </h3>
                <div class="cbi-promo-form-grid">
                    <div class="form-group cbi-flush">
                        <label class="form-label">Code *</label>
                        <input type="text" id="promoCode" class="form-control cbi-promo-code-input" placeholder="e.g. SUMMER20" oninput="this.value=this.value.toUpperCase()">
                    </div>
                    <div class="form-group cbi-flush">
                        <label class="form-label">Description (shown to customer)</label>
                        <input type="text" id="promoDesc" class="form-control" placeholder="e.g. Summer sale 20% off">
                    </div>
                    <div class="form-group cbi-flush">
                        <label class="form-label">Discount Type *</label>
                        <select id="promoType" class="form-control">
                            <option value="percentage">Percentage (e.g. 10%)</option>
                            <option value="fixed">Fixed Amount (e.g. £5 off)</option>
                        </select>
                    </div>
                    <div class="form-group cbi-flush">
                        <label class="form-label">Discount Value * <span class="cbi-muted-xs">(% or £)</span></label>
                        <input type="number" id="promoValue" class="form-control" placeholder="e.g. 10" min="0.01" step="0.01">
                    </div>
                    <div class="form-group cbi-flush">
                        <label class="form-label">Minimum Order (£) <span class="cbi-muted-xs">optional</span></label>
                        <input type="number" id="promoMin" class="form-control" placeholder="0.00" min="0" step="0.01">
                    </div>
                    <div class="form-group cbi-flush">
                        <label class="form-label">Max Uses <span class="cbi-muted-xs">optional — leave blank for unlimited</span></label>
                        <input type="number" id="promoMax" class="form-control" placeholder="Unlimited" min="1">
                    </div>
                    <div class="form-group cbi-flush">
                        <label class="form-label">Expires On <span class="cbi-muted-xs">optional</span></label>
                        <input type="date" id="promoExpires" class="form-control">
                    </div>
                    <div class="form-group cbi-promo-check-group">
                        <label class="cbi-promo-check-label">
                            <input type="checkbox" id="promoActive" checked class="cbi-promo-checkbox">
                            Active (available immediately)
                        </label>
                    </div>
                </div>
                <div class="cbi-promo-submit-row">
                    <button class="btn-primary cbi-promo-create-btn" onclick="createPromo()">
                        <i class="fa-solid fa-plus"></i> Create Promo Code
                    </button>
                </div>
            </div>

            <!-- Promo codes list -->
            <?php if (empty($promoCodes)): ?>
            <div class="cbi-promo-empty">
                <div class="cbi-promo-empty-icon">🎟️</div>
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
                                <code class="cbi-promo-code"><?= htmlspecialchars($p['code']) ?></code>
                                <?php if (!empty($p['description'])): ?>
                                <div class="cbi-promo-desc"><?= htmlspecialchars($p['description']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= $p['discount_type'] === 'percentage' ? '<i class="fa-solid fa-percent"></i> Percentage' : '<i class="fa-solid fa-sterling-sign"></i> Fixed' ?></td>
                            <td class="cbi-promo-value">
                                <?= $p['discount_type'] === 'percentage' ? (int)$p['discount_value'] . '%' : '£' . number_format($p['discount_value'], 2) ?>
                            </td>
                            <td><?= $p['min_order'] > 0 ? '£' . number_format($p['min_order'], 2) : '<span class="cbi-muted">—</span>' ?></td>
                            <td>
                                <?= $p['uses_count'] ?>
                                <?php if (!is_null($p['max_uses'])): ?>
                                / <?= $p['max_uses'] ?>
                                <?php else: ?>
                                <span class="cbi-muted-xs">/ ∞</span>
                                <?php endif; ?>
                            </td>
                            <td><?= !empty($p['expires_at']) ? date('d M Y', strtotime($p['expires_at'])) : '<span class="cbi-muted">Never</span>' ?></td>
                            <td>
                                <span id="promo-badge-<?= $p['id'] ?>" class="cbi-promo-badge <?= $p['active'] ? 'is-active' : 'is-inactive' ?>">
                                    <?= $p['active'] ? 'Active' : 'Disabled' ?>
                                </span>
                            </td>
                            <td>
                                <div class="cbi-btn-row">
                                    <button id="promo-toggle-<?= $p['id'] ?>" class="btn-secondary cbi-promo-btn" onclick="togglePromo(<?= $p['id'] ?>)">
                                        <i class="fa-solid fa-toggle-<?= $p['active'] ? 'on' : 'off' ?>"></i>
                                        <?= $p['active'] ? 'Disable' : 'Enable' ?>
                                    </button>
                                    <button class="btn-danger cbi-promo-btn" onclick="deletePromo(<?= $p['id'] ?>, '<?= htmlspecialchars($p['code']) ?>')">
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
        <div class="glass-panel cbi-panel-lg">
            <div class="admin-page-header cbi-gap-24">
                <div>
                    <h2 class="admin-page-title cbi-flush">📬 Customer Inquiries</h2>
                    <p class="admin-page-subtitle cbi-subtitle-gap">Messages submitted via the About / Contact page</p>
                </div>
                <div class="cbi-inq-header-actions">
                    <span class="cbi-inq-total">
                        Total: <strong><?= count($inquiries) ?></strong>
                    </span>
                    <a href="<?= htmlspecialchars(csrfUrl('index.php?tab=inquiries&delete_inquiry=all')) ?>"
                       data-confirm="Delete ALL inquiries? This cannot be undone." data-confirm-title="Delete every inquiry?" data-confirm-tone="danger" data-confirm-ok="Delete all"
                       class="btn-danger cbi-inq-btn">
                        <i class="fa-solid fa-trash"></i> Clear All
                    </a>
                </div>
            </div>

            <?php
            // ── Handle delete inquiry ─────────────────────────
            if (isset($_GET['delete_inquiry'])) {
                // "all" wipes every inquiry, so this needs a token like the rest.
                csrfCheck();
                try {
                    if ($_GET['delete_inquiry'] === 'all') {
                        $pdo->exec("DELETE FROM inquiries");
                        $inquiries = [];
                        echo '<div class="alert alert-success cbi-gap-20"><i class="fa-solid fa-check-circle"></i><div>All inquiries deleted.</div></div>';
                    } else {
                        $delId = (int)$_GET['delete_inquiry'];
                        $pdo->prepare("DELETE FROM inquiries WHERE id = :id")->execute(['id' => $delId]);
                        $inquiries = $pdo->query("SELECT * FROM inquiries ORDER BY created_at DESC")->fetchAll();
                        echo '<div class="alert alert-success cbi-gap-20"><i class="fa-solid fa-check-circle"></i><div>Inquiry deleted.</div></div>';
                    }
                } catch (PDOException $e) {
                    echo '<div class="alert alert-danger cbi-gap-20"><i class="fa-solid fa-triangle-exclamation"></i><div>Could not delete: ' . htmlspecialchars($e->getMessage()) . '</div></div>';
                }
            }
            ?>

            <?php if (empty($inquiries)): ?>
            <div class="cbi-empty-state">
                <div class="cbi-empty-icon">📭</div>
                <p class="cbi-empty-text">No inquiries yet. They'll appear here once customers submit the contact form.</p>
            </div>
            <?php else: ?>
            <div class="cbi-inq-list">
                <?php foreach ($inquiries as $inq): ?>
                <?php $isNew = !$inq['is_read']; ?>
                <div class="cbi-inq-card<?= $isNew ? ' is-new' : '' ?>">
                    <?php if ($isNew): ?>
                    <span class="cbi-inq-new-tag">NEW</span>
                    <?php endif; ?>
                    <a href="<?= htmlspecialchars(csrfUrl('index.php?tab=inquiries&delete_inquiry=' . (int)$inq['id'])) ?>"
                       data-confirm="Delete this inquiry?" data-confirm-title="Delete inquiry?" data-confirm-tone="danger" data-confirm-ok="Delete"
                       class="cbi-inq-delete"
                       title="Delete">
                        <i class="fa-solid fa-trash-can"></i>
                    </a>

                    <!-- Header row -->
                    <div class="cbi-inq-header-row">
                        <div class="cbi-inq-avatar">
                            <?= strtoupper(mb_substr($inq['name'], 0, 1)) ?>
                        </div>
                        <div>
                            <div class="cbi-name-strong"><?= htmlspecialchars($inq['name']) ?></div>
                            <div class="cbi-muted-sm">
                                <?= date('d M Y, H:i', strtotime($inq['created_at'])) ?>
                            </div>
                        </div>
                    </div>

                    <!-- Contact details -->
                    <div class="cbi-inq-contacts">
                        <span class="cbi-inq-contact">
                            <i class="fa-solid fa-envelope cbi-icon-primary"></i>
                            <a href="mailto:<?= htmlspecialchars($inq['email']) ?>" class="cbi-inq-link">
                                <?= htmlspecialchars($inq['email']) ?>
                            </a>
                        </span>
                        <?php if (!empty($inq['phone'])): ?>
                        <span class="cbi-inq-contact">
                            <i class="fa-solid fa-phone cbi-icon-secondary"></i>
                            <a href="tel:<?= htmlspecialchars($inq['phone']) ?>" class="cbi-inq-link">
                                <?= htmlspecialchars($inq['phone']) ?>
                            </a>
                        </span>
                        <?php endif; ?>
                    </div>

                    <!-- Message -->
                    <div class="cbi-inq-message">
                        <?= nl2br(htmlspecialchars($inq['message'])) ?>
                    </div>

                    <!-- Quick reply link -->
                    <div class="cbi-inq-reply-row">
                        <a href="mailto:<?= htmlspecialchars($inq['email']) ?>?subject=Re: Your Enquiry – Creamy Bite"
                           class="btn-primary cbi-inq-btn">
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

<!-- The footer belongs INSIDE .admin-shell. Left outside it, it started at
     left:0 and ran the full window width, sliding underneath the sidebar. -->
<footer class="admin-foot">
    <span>🍦 <?= SHOP_NAME ?> Admin</span>
    <span>© <?= date('Y') ?> <?= SHOP_NAME ?>. All rights reserved.</span>
</footer>
</div><!-- /admin-shell -->

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

    fetch('handlers/update_order.php', {
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

    fetch('handlers/update_order.php', {
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
                // Same three states the PHP above renders, so a badge updated
                // here looks identical to one rendered on a fresh page load.
                const map = {
                    'Paid':   { icon: '<i class="fa-solid fa-circle-check"></i>',    label: 'Paid Online',   cls: 'is-paid'   },
                    'Cash':   { icon: '<i class="fa-solid fa-money-bill-wave"></i>', label: 'Cash Received', cls: 'is-cash'   },
                    'Unpaid': { icon: '<i class="fa-solid fa-clock"></i>',           label: 'Not Paid',      cls: 'is-unpaid' },
                };
                const m = map[ps] || map['Unpaid'];
                badge.innerHTML = m.icon + ' ' + m.label;
                badge.classList.remove('is-paid', 'is-cash', 'is-unpaid');
                badge.classList.add(m.cls);
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

    fetch('handlers/gallery_handler.php', { method: 'POST', body: formData })
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

async function deleteGalleryItem(id) {
    if (!await cbConfirm('Delete this photo? This cannot be undone.', {title:'Delete photo?', tone:'danger', okText:'Delete'})) return;
    fetch('handlers/gallery_handler.php?action=delete&id=' + id)
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const el = document.getElementById('gitem-' + id);
            if (el) { el.style.opacity = '0'; el.style.transition = 'opacity 0.3s'; setTimeout(() => el.remove(), 300); }
        } else {
            cbAlert(data.message, {title:'Could not delete photo', tone:'danger'});
        }
    });
}

// ── Categories ──────────────────────────────────────────────
const catMsgEl = document.getElementById('catFormMsg');

function addCategory() {
    const name = document.getElementById('newCatName').value.trim();
    if (!name) { showCatMsg('Please enter a category name.', 'danger'); return; }

    fetch('handlers/category_handler.php', {
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
                <div class="cbi-cat-row">
                    <i class="fa-solid fa-grip-vertical cbi-cat-grip"></i>
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

async function startRename(id, currentName) {
    const newName = await cbPrompt('New name for this category:', currentName, {title:'Rename category'});
    if (!newName || newName.trim() === currentName) return;

    fetch('handlers/category_handler.php', {
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
            cbAlert(data.message, {title:'Could not rename', tone:'danger'});
        }
    });
}

async function deleteCategory(id, name) {
    if (!await cbConfirm('Delete the category "' + name + '"? Products using it are not deleted.', {title:'Delete category?', tone:'danger', okText:'Delete'})) return;
    fetch('handlers/category_handler.php', {
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
            cbAlert(data.message, {title:'Could not delete category', tone:'danger'});
        }
    });
}

function showCatMsg(msg, type) {
    if (!catMsgEl) return;
    catMsgEl.innerHTML = `<div class="alert alert-${type} cbi-cat-msg">${msg}</div>`;
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

    if (!code || !value) { cbAlert('Enter both a code and a discount value.', {title:'Missing details'}); return; }

    fetch('../promo_handler.php', {
        method: 'POST',
        headers: {'Content-Type':'application/x-www-form-urlencoded'},
        body: `action=create&code=${encodeURIComponent(code)}&description=${encodeURIComponent(desc)}&discount_type=${type}&discount_value=${value}&min_order=${min}&max_uses=${maxU}&expires_at=${exp}&active=${active}`,
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) { cbAlert(data.message || 'Could not save the promo code.', {title:'Could not save', tone:'danger'}); return; }
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

async function deletePromo(id, code) {
    if (!await cbConfirm(`Delete promo code "${code}"? This cannot be undone.`, {title:'Delete promo code?', tone:'danger', okText:'Delete'})) return;
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
// Filter the orders table by customer name OR order code (also matches the
// trade store name and phone number, which is what you have to hand when a
// customer rings up).
//
// Order codes are matched loosely: punctuation and spaces are stripped from
// both sides, so "CB-202994", "cb 202994" and plain "202994" all find the
// same order.
function filterOrders(query) {
    const raw   = query.toLowerCase().trim();
    const loose = raw.replace(/[^a-z0-9]/g, '');
    const tbody = document.querySelector('#ordersTable tbody');
    if (!tbody) return;

    const mainRows = Array.from(tbody.querySelectorAll('.order-row'));
    let visibleCount = 0;

    mainRows.forEach(row => {
        const code     = (row.dataset.orderCode || '').toLowerCase();
        const codeLoose= code.replace(/[^a-z0-9]/g, '');
        const name     = (row.dataset.customer  || '').toLowerCase();
        const store    = (row.dataset.store     || '').toLowerCase();
        const phone    = (row.dataset.phone     || '').replace(/[^0-9]/g, '');

        const show = raw === ''
            || name.includes(raw)
            || store.includes(raw)
            || code.includes(raw)
            || (loose !== '' && codeLoose.includes(loose))
            || (loose !== '' && phone !== '' && phone.includes(loose));

        row.style.display = show ? '' : 'none';

        // Always collapse the expanded detail row while filtering.
        const detailRow = document.getElementById('detail-' + row.dataset.id);
        if (detailRow) detailRow.style.display = 'none';

        if (show) visibleCount++;
    });

    const countEl = document.getElementById('orderFilterCount');
    if (countEl) {
        countEl.textContent = raw
            ? (visibleCount === 0
                ? 'No orders match “' + query.trim() + '”'
                : `Showing ${visibleCount} of ${mainRows.length} orders`)
            : '';
    }
}

// Kept so any older inline handler still works.
function filterOrdersByName(query) { filterOrders(query); }

// ── Remove an item we cannot supply from a placed order ──────
// Asks for a reason (it goes to the customer and onto the delivery note),
// then lets the server handle stock, totals, the invoice and the email.
async function removeOrderItem(orderId, itemIndex, itemName) {
    const reason = await cbPrompt(
        'Remove "' + itemName + '" from this order?\n\n' +
        'Give a short reason — it is shown to the customer and recorded on the order:',
        'Out of stock'
    );
    if (reason === null) return;   // cancelled

    const notify = await cbConfirm('Email the customer to tell them this item was removed?', {title:'Notify the customer?', okText:'Send email', cancelText:'Change quietly'});

    const body = new URLSearchParams({
        action: 'remove_item',
        order_id: orderId,
        item_index: itemIndex,
        reason: reason,
    });
    if (notify) body.append('notify', '1');

    fetch('handlers/order_item_handler.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString(),
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) { cbAlert(data.message || 'Could not remove that item.', {title:'Could not remove item', tone:'danger'}); return; }
        let msg = data.message;
        if (data.refund_due) msg += '\n\nThis order was already paid — £' + data.refund_due + ' is owed back to the customer.';
        cbAlert(msg, {title:'Item removed', tone:'success'});
        location.reload();
    })
    .catch(err => cbAlert('Could not reach the server: ' + err.message, {title:'Request failed', tone:'danger'}));
}

// ── Sidebar drawer (mobile) ─────────────────────────────────
(function () {
    var sidebar  = document.getElementById('adminSidebar');
    var backdrop = document.getElementById('sidebarBackdrop');
    var toggle   = document.getElementById('sbToggle');
    if (!sidebar || !backdrop || !toggle) return;

    function setOpen(open) {
        sidebar.classList.toggle('open', open);
        backdrop.classList.toggle('show', open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        // Stop the page behind scrolling while the drawer covers it.
        document.body.style.overflow = open ? 'hidden' : '';
    }

    toggle.addEventListener('click', function () {
        setOpen(!sidebar.classList.contains('open'));
    });
    backdrop.addEventListener('click', function () { setOpen(false); });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') setOpen(false);
    });
    // Tapping a menu item closes the drawer, otherwise it hides the page
    // you just navigated to.
    sidebar.querySelectorAll('.sb-link').forEach(function (a) {
        a.addEventListener('click', function () { setOpen(false); });
    });
    // Returning to desktop width must not leave the drawer state stuck on.
    window.addEventListener('resize', function () {
        if (window.innerWidth > 980) setOpen(false);
    });
})();

// ── Table sorting ───────────────────────────────────────────
// Sorts the rows already on the page. data-sortN holds a machine-readable
// value (timestamp, unpadded number, status rank) where the visible cell is
// formatted for people — sorting "£1,200.00" as text would put it before
// "£9.00", and "Pending Review" would sort after "Approved".
//
// Keyed by table id so two sortable tables on one page keep their own
// direction instead of fighting over a single shared flag.
const tableSortState = {};

function sortTable(tableId, rowClass, col, type, th) {
    const tbody = document.querySelector('#' + tableId + ' tbody');
    if (!tbody) return;

    const state = tableSortState[tableId] || (tableSortState[tableId] = { col: null, dir: 1 });
    state.dir = (state.col === col) ? -state.dir : 1;
    state.col = col;

    const rows = Array.from(tbody.querySelectorAll('.' + rowClass));
    const keyOf = (row) => {
        const pre = row.dataset['sort' + col];
        if (pre !== undefined) return type === 'text' ? pre.toLowerCase() : parseFloat(pre) || 0;
        const cell = row.children[col];
        const txt = (cell ? cell.textContent : '').trim();
        if (type === 'number') return parseFloat(txt.replace(/[^0-9.-]/g, '')) || 0;
        if (type === 'date')   return Date.parse(txt) || 0;
        return txt.toLowerCase();
    };

    rows.sort((a, b) => {
        const x = keyOf(a), y = keyOf(b);
        if (x < y) return -1 * state.dir;
        if (x > y) return  1 * state.dir;
        return 0;
    });
    rows.forEach(r => tbody.appendChild(r));

    // Reset every icon in THIS table, then mark the active one.
    document.querySelectorAll('#' + tableId + ' th.inv-sort i').forEach(i => {
        i.className = 'fa-solid fa-sort';
        i.classList.remove('is-sorted');
    });
    const icon = th.querySelector('i');
    if (icon) {
        icon.className = 'fa-solid fa-sort-' + (state.dir === 1 ? 'up' : 'down');
        icon.classList.add('is-sorted');
    }
}

function sortInvoices(col, type, th) { sortTable('invoicesTable', 'invoice-row', col, type, th); }
function sortTrade(col, type, th)    { sortTable('tradeTable',    'trade-row',   col, type, th); }

// ── Trade account search ────────────────────────────────────
// Matches the contact person as well as the store name: the admin often knows
// "Ravi" without remembering which shop that is.
function filterTradeAccounts(query) {
    const q = (query || '').toLowerCase().trim();
    let shown = 0;
    document.querySelectorAll('#tradeTable .trade-row').forEach(row => {
        const hay = (row.dataset.search || '').toLowerCase();
        const hit = !q || hay.includes(q);
        row.classList.toggle('is-hidden', !hit);
        if (hit) shown++;
    });
    const empty = document.getElementById('tradeNoMatch');
    if (empty) empty.classList.toggle('is-hidden', shown !== 0);
    const clear = document.getElementById('tradeSearchClear');
    if (clear) clear.classList.toggle('is-hidden', q === '');
}

function clearTradeSearch() {
    const input = document.getElementById('tradeSearch');
    if (input) { input.value = ''; filterTradeAccounts(''); input.focus(); }
}

// ── Searchable "raise invoice from order" picker ────────────
function filterOrderPicker(query) {
    const sel = document.getElementById('orderPicker');
    const q = (query || '').toLowerCase().trim();
    if (!sel) return;

    const kind = document.getElementById('orderKindFilter')?.value || 'all';

    let shown = 0;
    Array.from(sel.options).forEach(o => {
        const matchesText = q === '' || o.text.toLowerCase().includes(q);
        const matchesKind = kind === 'all' || o.dataset.kind === kind;
        const match = matchesText && matchesKind;
        o.hidden = !match;
        if (match) shown++;
    });

    // Keep the selection valid: if the chosen option is now hidden, move to
    // the first visible one, otherwise the Create button would post a
    // filtered-out order.
    if (sel.selectedOptions[0] && sel.selectedOptions[0].hidden) {
        const first = Array.from(sel.options).find(o => !o.hidden);
        sel.value = first ? first.value : '';
    }
    const c = document.getElementById('orderPickerCount');
    if (c) c.textContent = q ? shown + ' match' + (shown === 1 ? '' : 'es') : '';
}

// Invoice list search: number, customer, or amount.
function filterInvoices(query) {
    const q = query.toLowerCase().trim();
    const rows = Array.from(document.querySelectorAll('#invoicesTable .invoice-row'));
    let shown = 0;
    rows.forEach(r => {
        const hay = [r.dataset.number, r.dataset.to, r.dataset.total, r.dataset.status]
            .join(' ').toLowerCase();
        const loose = hay.replace(/[^a-z0-9. ]/g, '');
        const ok = q === '' || hay.includes(q) || loose.includes(q.replace(/[^a-z0-9. ]/g, ''));
        r.style.display = ok ? '' : 'none';
        if (ok) shown++;
    });
    const el = document.getElementById('invoiceFilterCount');
    if (el) el.textContent = q ? (shown ? `Showing ${shown} of ${rows.length}` : 'No invoices match') : '';
}

function clearOrderFilter() {
    const input = document.getElementById('orderNameFilter');
    if (input) { input.value = ''; input.focus(); }
    filterOrders('');
}

// Show the clear button only when there is something to clear, and let Escape
// reset the search from the box.
document.addEventListener('DOMContentLoaded', function () {
    const input = document.getElementById('orderNameFilter');
    const clear = document.getElementById('orderFilterClear');
    if (!input || !clear) return;

    const sync = () => { clear.style.display = input.value.trim() ? 'inline-block' : 'none'; };
    input.addEventListener('input', sync);
    input.addEventListener('keydown', e => { if (e.key === 'Escape') clearOrderFilter(); sync(); });
    sync();
});

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

    fetch('handlers/stock_handler.php', {
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
async function deleteOrder(orderId, orderCode) {
    if (!await cbConfirm(`Delete order ${orderCode}?\n\nThis cannot be undone. Any stock it used is returned.`, {title:'Delete order?', tone:'danger', okText:'Delete order'})) return;
    fetch('handlers/update_order.php', {
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
            cbAlert(data.message || 'Unknown error', {title:'Could not delete order', tone:'danger'});
        }
    })
    .catch(err => cbAlert('Could not reach the server: ' + err.message, {title:'Request failed', tone:'danger'}));
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

<script>
// Product colours: the hue comes from the product's name (computed server-side
// in cbProductHue), so the same product is the same colour in the all-time and
// this-month tables — that pairing is the whole point of colouring them.
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-hue]').forEach(function (el) {
        var hue = parseInt(el.getAttribute('data-hue'), 10);
        if (isNaN(hue)) return;
        el.style.backgroundColor = 'hsl(' + hue + ' 62% 55%)';
    });
    document.querySelectorAll('.cbi-prod-bar-fill[data-pct]').forEach(function (el) {
        var pct = parseFloat(el.getAttribute('data-pct'));
        el.style.width = (isNaN(pct) ? 0 : Math.max(0, Math.min(100, pct))) + '%';
    });
});

// Revenue bars: the width is data, not styling, so it rides on data-pct and
// is applied here rather than written into a style attribute in the markup.
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.cbi-rev-bar-fill[data-pct]').forEach(function (el) {
        var pct = parseFloat(el.getAttribute('data-pct'));
        el.style.width = (isNaN(pct) ? 0 : Math.max(0, Math.min(100, pct))) + '%';
    });
});
</script>
</body>
</html>
