<?php
// ============================================================
//  Creamy Bite – Trade B2B Account Hub
//  URL: /trade_profile.php[?tab=profile|orders|payments|invoices]
//
//  Tabs:
//    profile  – view and edit contact name, phone, address, postcode.
//               Email and business name are read-only.
//    orders   – every order this trade account has placed.
//    payments – the payment side of those orders: method, status,
//               amount paid vs still outstanding.
//    invoices – printable invoice per order.
//
//  Orders are scoped by orders.trade_user_id ONLY. Matching on phone or
//  email would show this partner other customers' orders whenever a
//  detail happens to coincide.
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

if (empty($_SESSION['trade_user'])) {
    header('Location: trade_login.php');
    exit;
}

$userId = (int)($_SESSION['trade_user']['id'] ?? 0);

// Re-read the account from the database rather than trusting the session.
// This also re-checks approval, so revoking an account takes effect on the
// partner's next page load instead of lasting until they log out.
$stmt = $pdo->prepare("SELECT * FROM trade_users WHERE id = :id");
$stmt->execute(['id' => $userId]);
$account = $stmt->fetch();

if (!$account || $account['status'] !== 'approved') {
    unset($_SESSION['trade_user']);
    $_SESSION['cart'] = [];
    header('Location: trade_login.php?revoked=1');
    exit;
}

$tab       = $_GET['tab'] ?? 'profile';
$allowed   = ['profile', 'orders', 'payments', 'invoices'];
if (!in_array($tab, $allowed, true)) {
    $tab = 'profile';
}

$savedMsg = '';
$errorMsg = '';

// ── Handle profile update ────────────────────────────────────
// Email and business name are deliberately not read from POST: they
// identify the account, so they cannot be changed here.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_profile') {
    $contactName = trim($_POST['contact_name'] ?? '');
    $phone       = trim($_POST['phone'] ?? '');
    $address     = trim($_POST['address'] ?? '');
    $postcode    = strtoupper(trim($_POST['postcode'] ?? ''));
    // VAT number is optional. Having one is what makes the account VAT
    // registered, which is what triggers VAT on their orders.
    $vatNumber   = strtoupper(preg_replace('/\s+/', '', $_POST['vat_number'] ?? ''));

    if (mb_strlen($contactName) < 2) {
        $errorMsg = 'Please enter the contact name.';
    } elseif (mb_strlen($phone) < 7) {
        $errorMsg = 'Please enter a valid contact number.';
    } elseif (mb_strlen($address) < 5) {
        $errorMsg = 'Please enter the store address.';
    } elseif (mb_strlen($postcode) < 3) {
        $errorMsg = 'Please enter the postcode.';
    } else {
        try {
            $pdo->prepare(
                "UPDATE trade_users
                    SET contact_name = :cn, phone = :ph, address = :ad, postcode = :pc, vat_number = :vat
                  WHERE id = :id"
            )->execute([
                'cn'  => $contactName,
                'ph'  => $phone,
                'ad'  => $address,
                'pc'  => $postcode,
                'vat' => $vatNumber,
                'id'  => $userId,
            ]);

            // Keep the session copy in step — checkout prefills from it and
            // reads vat_number to decide whether to charge VAT.
            $_SESSION['trade_user']['contact_name'] = $contactName;
            $_SESSION['trade_user']['phone']        = $phone;
            $_SESSION['trade_user']['address']      = $address;
            $_SESSION['trade_user']['postcode']     = $postcode;
            $_SESSION['trade_user']['vat_number']   = $vatNumber;

            $account['contact_name'] = $contactName;
            $account['phone']        = $phone;
            $account['address']      = $address;
            $account['postcode']     = $postcode;
            $account['vat_number']   = $vatNumber;

            $savedMsg = $vatNumber !== ''
                ? 'Your details have been updated. VAT at ' . (int)(TRADE_VAT_RATE * 100) . '% will be applied to your orders.'
                : 'Your details have been updated.';
        } catch (PDOException $e) {
            error_log('Trade profile update failed: ' . $e->getMessage());
            $errorMsg = 'Could not save your details. Please try again.';
        }
    }
}

// ── Load this partner's orders ───────────────────────────────
$orders = [];
try {
    $oStmt = $pdo->prepare("SELECT * FROM orders WHERE trade_user_id = :uid ORDER BY created_at DESC");
    $oStmt->execute(['uid' => $userId]);
    $orders = $oStmt->fetchAll();
} catch (PDOException $e) {
    error_log('Trade order load failed: ' . $e->getMessage());
    $errorMsg = $errorMsg ?: 'Could not load your order history right now.';
}

// ── Account totals ───────────────────────────────────────────
$totalSpent   = 0.0;   // money actually received
$totalOutstanding = 0.0;
foreach ($orders as $o) {
    $amount = (float)$o['total_price'];
    if (($o['payment_status'] ?? 'Unpaid') === 'Unpaid') {
        $totalOutstanding += $amount;
    } else {
        $totalSpent += $amount;
    }
}

/** Human-facing trade customer number, e.g. TC-00007. */
function tradeCustomerNumber(int $id): string
{
    return 'TC-' . str_pad((string)$id, 5, '0', STR_PAD_LEFT);
}

$customerNo = tradeCustomerNumber($userId);

/**
 * Small helper for the status pills, so the four tabs stay consistent.
 *
 * Returns a CSS class rather than three colours: the colours belong in the
 * stylesheet, and a class also means the pill can be restyled without
 * touching PHP.
 */
function statusPill(string $status): array
{
    return match ($status) {
        'Delivered'  => ['is-delivered',  'fa-circle-check', 'Delivered'],
        'Processing' => ['is-processing', 'fa-spinner',      'Processing'],
        'Cancelled'  => ['is-cancelled',  'fa-ban',          'Cancelled'],
        default      => ['is-pending',    'fa-clock',        $status ?: 'Pending'],
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Trade Account – <?= htmlspecialchars($account['business_name']) ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/responsive.css">
    <link rel="stylesheet" href="../assets/css/animations.css">
    <link rel="stylesheet" href="../assets/css/components.css">
    <link rel="stylesheet" href="../assets/css/modal.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="trade-page">

<header class="navbar">
    <div class="container nav-container-centered">
        <nav class="nav-left">
            <ul class="nav-links">
                <li><a href="../index.php">Home</a></li>
                <li><a href="order.php">Order Menu</a></li>
                <li><a href="gallery.php">Gallery</a></li>
                <li><a href="about.php">About Us</a></li>
            </ul>
        </nav>
        <a href="../index.php" class="logo logo-center">
            <img src="../assets/images/logo.png" alt="<?= SHOP_NAME ?>" class="logo-img">
        </a>
        <div class="nav-actions nav-right">
            <a href="order.php" class="btn-primary cbtp-nav-btn">
                <i class="fa-solid fa-basket-shopping"></i> Place Order
            </a>
            <a href="trade_logout.php" class="btn-secondary cbtp-nav-btn-out">
                <i class="fa-solid fa-right-from-bracket"></i> Logout
            </a>
            <button class="nav-hamburger" id="navHamburger" aria-label="Open menu"><span></span><span></span><span></span></button>
        </div>
    </div>
</header>

<main class="cbtp-main">
    <div class="container cbtp-shell">

        <!-- Account header -->
        <div class="cbtp-account-card">
            <div>
                <span class="cbtp-verified-badge">
                    🏪 Verified B2B Trade Partner
                </span>
                <h1 class="cbtp-account-name">
                    <?= htmlspecialchars($account['business_name']) ?>
                </h1>
                <div class="cbtp-account-meta">
                    Customer Number:
                    <strong class="cbtp-customer-no">
                        <?= htmlspecialchars($customerNo) ?>
                    </strong>
                </div>
            </div>
            <div class="cbtp-text-right">
                <div class="cbtp-stat-label">Total Orders</div>
                <div class="cbtp-stat-value"><?= count($orders) ?></div>
                <?php if ($totalOutstanding > 0): ?>
                <div class="cbtp-stat-note">
                    Outstanding: <strong>£<?= number_format($totalOutstanding, 2) ?></strong>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Tabs -->
        <div class="cbtp-tabbar">
            <?php
            $tabs = [
                'profile'  => ['fa-user',           'My Profile'],
                'orders'   => ['fa-box',            'Previous Orders'],
                'payments' => ['fa-credit-card',    'Payments'],
                'invoices' => ['fa-file-invoice',   'Invoices'],
            ];
            foreach ($tabs as $key => [$icon, $label]):
                $active = ($tab === $key);
            ?>
            <a href="?tab=<?= $key ?>"
               class="<?= $active ? 'btn-primary' : 'btn-secondary' ?> cbtp-tab-btn">
                <i class="fa-solid <?= $icon ?>"></i> <?= $label ?>
            </a>
            <?php endforeach; ?>
        </div>

        <?php if ($savedMsg): ?>
        <div class="cbtp-alert cbtp-tone-success cbtp-ink-success">
            <i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($savedMsg) ?>
        </div>
        <?php endif; ?>
        <?php if ($errorMsg): ?>
        <div class="cbtp-alert cbtp-tone-danger cbtp-ink-danger">
            <i class="fa-solid fa-triangle-exclamation"></i> <?= htmlspecialchars($errorMsg) ?>
        </div>
        <?php endif; ?>

        <div class="glass-panel cbtp-panel">

        <?php if ($tab === 'profile'): ?>
            <!-- ── PROFILE ─────────────────────────────────── -->
            <h2 class="cbtp-section-title">
                <i class="fa-solid fa-user cbtp-accent"></i> My Profile
            </h2>
            <p class="cbtp-section-sub cbtp-section-sub-wide">
                Keep your delivery address and contact details up to date — we use them for every wholesale delivery.
            </p>

            <form method="POST" action="?tab=profile">
                <input type="hidden" name="action" value="update_profile">

                <div class="cbtp-form-grid">

                    <div>
                        <label class="form-label">Store / Business Name</label>
                        <input type="text" class="form-control cbtp-input-locked" value="<?= htmlspecialchars($account['business_name']) ?>" disabled>
                        <small class="cbtp-hint">
                            <i class="fa-solid fa-lock"></i> Contact us to change your registered business name.
                        </small>
                    </div>

                    <div>
                        <label class="form-label">Customer Number</label>
                        <input type="text" class="form-control cbtp-input-locked cbtp-input-mono" value="<?= htmlspecialchars($customerNo) ?>" disabled>
                        <small class="cbtp-hint">
                            Quote this number when you contact us.
                        </small>
                    </div>

                    <div>
                        <label class="form-label">Email Address</label>
                        <input type="email" class="form-control cbtp-input-locked" value="<?= htmlspecialchars($account['email']) ?>" disabled>
                        <small class="cbtp-hint">
                            <i class="fa-solid fa-lock"></i> Your email is your login and cannot be changed here.
                        </small>
                    </div>

                    <div>
                        <label class="form-label">Contact Name <span class="cbtp-accent">*</span></label>
                        <input type="text" name="contact_name" class="form-control" required
                               value="<?= htmlspecialchars($account['contact_name']) ?>">
                    </div>

                    <div>
                        <label class="form-label">Contact Number <span class="cbtp-accent">*</span></label>
                        <input type="tel" name="phone" class="form-control" required
                               value="<?= htmlspecialchars($account['phone']) ?>">
                    </div>

                    <div>
                        <label class="form-label">Postcode <span class="cbtp-accent">*</span></label>
                        <input type="text" name="postcode" class="form-control cbtp-upper" required
                               value="<?= htmlspecialchars($account['postcode']) ?>">
                    </div>
                </div>

                <div class="cbtp-field-block">
                    <label class="form-label">Store Address <span class="cbtp-accent">*</span></label>
                    <textarea name="address" class="form-control" rows="3" required><?= htmlspecialchars($account['address']) ?></textarea>
                </div>

                <div class="cbtp-field-block">
                    <label class="form-label">VAT Number <small class="cbtp-label-optional">(optional)</small></label>
                    <input type="text" name="vat_number" class="form-control cbtp-upper cbtp-vat-input"
                           value="<?= htmlspecialchars($account['vat_number']) ?>"
                           placeholder="e.g. GB123456789">
                    <small class="cbtp-help-block">
                        <i class="fa-solid fa-circle-info"></i>
                        If you add a VAT number, your wholesale orders will be charged
                        <strong><?= (int)(TRADE_VAT_RATE * 100) ?>% VAT</strong>. Leave it blank if you are not VAT registered.
                    </small>
                </div>

                <button type="submit" class="btn-primary cbtp-btn-save">
                    <i class="fa-solid fa-floppy-disk"></i> Save Changes
                </button>
            </form>

        <?php elseif ($tab === 'orders'): ?>
            <!-- ── PREVIOUS ORDERS ─────────────────────────── -->
            <h2 class="cbtp-section-title cbtp-section-title-spaced">
                <i class="fa-solid fa-box cbtp-accent"></i> Previous Orders
            </h2>

            <?php if (empty($orders)): ?>
            <div class="cbtp-empty cbtp-empty-tall">
                <div class="cbtp-empty-icon">📦</div>
                <h3 class="cbtp-empty-title">No orders yet</h3>
                <a href="order.php" class="btn-primary cbtp-empty-cta">Browse Wholesale Menu</a>
            </div>
            <?php else: ?>
            <div class="table-wrapper">
                <table class="data-table cbtp-table-full">
                    <thead>
                        <tr>
                            <th>Order Code</th>
                            <th>Date</th>
                            <th>Items</th>
                            <th>Total</th>
                            <th>Status</th>
                            <th class="cbtp-text-right">Invoice</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($orders as $o):
                        $items    = json_decode($o['items_json'] ?? '', true) ?? [];
                        $totalQty = array_sum(array_column($items, 'quantity'));
                        $first    = $items[0] ?? null;
                        [$pillClass, $ic, $lbl] = statusPill($o['status'] ?? '');
                    ?>
                        <tr class="cbtp-row">
                            <td class="cbtp-cell-code">
                                <?= htmlspecialchars($o['order_code']) ?>
                            </td>
                            <td class="cbtp-cell-date">
                                <?= date('d M Y', strtotime($o['created_at'])) ?>
                                <div class="cbtp-hint"><?= date('H:i', strtotime($o['created_at'])) ?></div>
                            </td>
                            <td class="cbtp-cell-sm">
                                <?php if ($first): ?>
                                <strong><?= htmlspecialchars($first['emoji'] ?? '🍦') ?> <?= htmlspecialchars($first['name'] ?? '') ?><?php
                                    if (!empty($first['variant_name'])): ?> <span class="cbtp-variant-name"><?= htmlspecialchars($first['variant_name']) ?></span><?php
                                    endif; ?></strong> ×<?= (int)($first['quantity'] ?? 0) ?>
                                <?php if (count($items) > 1): ?>
                                <span class="cbtp-more-count">(+<?= count($items) - 1 ?> more)</span>
                                <?php endif; ?>
                                <div class="cbtp-hint">Total tubs: <?= $totalQty ?></div>
                                <?php else: ?>
                                <span class="cbtp-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="cbtp-cell-amount">
                                £<?= number_format((float)$o['total_price'], 2) ?>
                            </td>
                            <td>
                                <span class="cbtp-status-pill <?= $pillClass ?>">
                                    <i class="fa-solid <?= $ic ?>"></i> <?= htmlspecialchars($lbl) ?>
                                </span>
                            </td>
                            <td class="cbtp-text-right">
                                <a href="trade_invoice.php?code=<?= urlencode($o['order_code']) ?>" target="_blank"
                                   class="btn-secondary cbtp-btn-xs">
                                    <i class="fa-solid fa-file-invoice"></i> View
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

        <?php elseif ($tab === 'payments'): ?>
            <!-- ── PAYMENTS ────────────────────────────────── -->
            <h2 class="cbtp-section-title">
                <i class="fa-solid fa-credit-card cbtp-accent"></i> Payment Transactions
            </h2>
            <p class="cbtp-section-sub">
                One line per order, showing how it was paid and whether anything is still owed.
            </p>

            <div class="cbtp-summary-grid">
                <div class="cbtp-card cbtp-tone-success">
                    <div class="cbtp-card-label cbtp-ink-success">Total Paid</div>
                    <div class="cbtp-card-value cbtp-ink-success">£<?= number_format($totalSpent, 2) ?></div>
                </div>
                <div class="cbtp-card<?= $totalOutstanding > 0 ? ' is-owing' : '' ?>">
                    <div class="cbtp-card-label">Outstanding</div>
                    <div class="cbtp-card-value">£<?= number_format($totalOutstanding, 2) ?></div>
                </div>
                <div class="cbtp-card cbtp-tone-plain">
                    <div class="cbtp-card-label cbtp-muted">Transactions</div>
                    <div class="cbtp-card-value cbtp-ink-primary"><?= count($orders) ?></div>
                </div>
            </div>

            <?php if (empty($orders)): ?>
            <div class="cbtp-empty">
                No payment transactions yet.
            </div>
            <?php else: ?>
            <div class="table-wrapper">
                <table class="data-table cbtp-table-full">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Order Code</th>
                            <th>Method</th>
                            <th>Amount</th>
                            <th>Payment Status</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($orders as $o):
                        $ps     = $o['payment_status'] ?? 'Unpaid';
                        $method = $o['payment_method'] ?? 'later';
                        $methodLabel = match ($method) {
                            'card', 'stripe' => ['fa-credit-card', 'Card (Stripe)'],
                            'cash'           => ['fa-money-bill-wave', 'Cash'],
                            default          => ['fa-file-invoice-dollar', 'Pay on Invoice'],
                        };
                    ?>
                        <tr class="cbtp-row">
                            <td class="cbtp-cell-date">
                                <?= date('d M Y, H:i', strtotime($o['created_at'])) ?>
                            </td>
                            <td class="cbtp-cell-code">
                                <?= htmlspecialchars($o['order_code']) ?>
                            </td>
                            <td class="cbtp-cell-sm">
                                <i class="fa-solid <?= $methodLabel[0] ?> cbtp-muted"></i>
                                <?= htmlspecialchars($methodLabel[1]) ?>
                            </td>
                            <td class="cbtp-cell-amount">
                                £<?= number_format((float)$o['total_price'], 2) ?>
                            </td>
                            <td>
                                <?php if ($ps === 'Paid'): ?>
                                <span class="cbtp-pay-state cbtp-ink-success"><i class="fa-solid fa-circle-check"></i> Paid Online</span>
                                <?php elseif ($ps === 'Cash'): ?>
                                <span class="cbtp-pay-state cbtp-ink-warn"><i class="fa-solid fa-money-bill-wave"></i> Paid in Cash</span>
                                <?php else: ?>
                                <span class="cbtp-pay-state cbtp-ink-danger"><i class="fa-solid fa-clock"></i> Unpaid</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>

        <?php else: ?>
            <!-- ── INVOICES ────────────────────────────────── -->
            <h2 class="cbtp-section-title">
                <i class="fa-solid fa-file-invoice cbtp-accent"></i> Invoices
            </h2>
            <p class="cbtp-section-sub">
                Open an invoice to view or print it. Each one is numbered from its order code.
            </p>

            <?php if (empty($orders)): ?>
            <div class="cbtp-empty">
                No invoices yet — they appear here once you place an order.
            </div>
            <?php else: ?>
            <div class="cbtp-invoice-grid">
                <?php foreach ($orders as $o):
                    $ps = $o['payment_status'] ?? 'Unpaid';
                    $paid = ($ps !== 'Unpaid');
                ?>
                <div class="cbtp-card cbtp-tone-plain cbtp-invoice-card">
                    <div class="cbtp-invoice-head">
                        <div>
                            <div class="cbtp-invoice-eyebrow">Invoice</div>
                            <div class="cbtp-invoice-code">
                                <?= htmlspecialchars($o['order_code']) ?>
                            </div>
                        </div>
                        <span class="cbtp-pay-badge <?= $paid ? 'is-paid' : 'is-unpaid' ?>">
                            <?= $paid ? 'PAID' : 'UNPAID' ?>
                        </span>
                    </div>
                    <div class="cbtp-invoice-date">
                        <?= date('d M Y', strtotime($o['created_at'])) ?>
                    </div>
                    <div class="cbtp-invoice-total">
                        £<?= number_format((float)$o['total_price'], 2) ?>
                    </div>
                    <a href="trade_invoice.php?code=<?= urlencode($o['order_code']) ?>" target="_blank"
                       class="btn-primary cbtp-btn-block">
                        <i class="fa-solid fa-arrow-up-right-from-square"></i> Open Invoice
                    </a>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        <?php endif; ?>

        </div>
    </div>
</main>
<script src="../assets/js/modal.js" defer></script>
<script src="../assets/js/animations.js" defer></script>

</body>
</html>
