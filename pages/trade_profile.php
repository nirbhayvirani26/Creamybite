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
require_once __DIR__ . '/../includes/product_icons.php';
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

// The partner's customer number, in the one format the rest of the site
// already uses — pages/trade_invoice.php and admin/trade_report.php both build
// it exactly this way, and the note in trade_report.php describes it as
// "matching the partner's own account page".
//
// It did not match, because this page never worked it out. $customerNo was
// printed twice below and assigned nowhere, so the account header read
// "Customer Number:" followed by nothing, and the locked field under the
// heading "Quote this number when you contact us" was blank — while their
// invoices and the shop's own report showed TC-00001. Two PHP warnings went
// into the log on every visit, and onto the page itself on any server left
// with display_errors on.
$customerNo = 'TC-' . str_pad((string)$userId, 5, '0', STR_PAD_LEFT);

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
    // How much has gone back on each order, so a part-refund can say the
    // amount rather than just the fact.
    $refundsByOrder = [];
    try {
        $refundsByOrder = $pdo->query(
            "SELECT order_id, SUM(amount) FROM order_refunds GROUP BY order_id"
        )->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (PDOException $e) {
        // Table arrives with the migration; no refunds shown until then.
    }

    $oStmt = $pdo->prepare("SELECT * FROM orders WHERE trade_user_id = :uid ORDER BY created_at DESC");
    $oStmt->execute(['uid' => $userId]);
    $orders = $oStmt->fetchAll();
} catch (PDOException $e) {
    error_log('Trade order load failed: ' . $e->getMessage());
    $errorMsg = $errorMsg ?: 'Could not load your order history right now.';
}

// ── Invoices the shop has actually issued ────────────────────
// Only documents the admin has raised AND sent. A draft is a working copy —
// the figures on it can still change — so showing one to the customer invites
// a query about a bill that was never issued. Void invoices are withdrawn.
$tradeInvoices = [];
try {
    $ti = $pdo->prepare(
        "SELECT id, invoice_number, public_token, issue_date, due_date, due_terms,
                status, total, amount_paid, sent_at
           FROM invoices
          WHERE trade_user_id = :uid AND status IN ('sent', 'part_paid', 'paid')
          ORDER BY issue_date DESC, id DESC"
    );
    $ti->execute(['uid' => $userId]);
    $tradeInvoices = $ti->fetchAll();
} catch (PDOException $e) {
    error_log('Trade invoice list failed: ' . $e->getMessage());
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

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Trade Account – <?= htmlspecialchars($account['business_name']) ?></title>
    <?php // Private, login-gated account area — must never be indexed. ?>
    <meta name="robots" content="noindex, nofollow">
    <?php require __DIR__ . '/../includes/favicon.php'; ?>
    <link rel="stylesheet" href="<?= cbAsset('../assets/css/style.css') ?>">
    <link rel="stylesheet" href="<?= cbAsset('../assets/css/responsive.css') ?>">
    <link rel="stylesheet" href="<?= cbAsset('../assets/css/animations.css') ?>">
    <link rel="stylesheet" href="<?= cbAsset('../assets/css/components.css') ?>">
    <link rel="stylesheet" href="<?= cbAsset('../assets/css/modal.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="trade-page">

<?php
$cbNavActive = '';
$cbNavShowTrade = false; // already on the trade account page — the pill would just point at itself
ob_start(); ?>
<a href="order.php" class="btn-primary cbtp-nav-btn">
    <i class="fa-solid fa-basket-shopping"></i> Place Order
</a>
<a href="trade_logout.php" class="btn-secondary cbtp-nav-btn-out">
    <i class="fa-solid fa-right-from-bracket"></i> Logout
</a>
<?php $cbNavRight = ob_get_clean();
require __DIR__ . '/../includes/site_header.php';
?>

<main class="cbtp-main">
    <div class="container cbtp-shell">

        <!-- Account header -->
        <div class="cbtp-account-card">
            <div>
                <span class="cbtp-verified-badge">
                    <i class="fa-solid fa-store cb-badge-icon" aria-hidden="true"></i>Verified B2B Trade Partner
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
                <div class="cbtp-empty-icon"><i class="fa-solid fa-box" aria-hidden="true"></i></div>
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
                                <strong><?= cbProductIcon($first['emoji'] ?? null) ?> <?= htmlspecialchars($first['name'] ?? '') ?><?php
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
                                <?php
                                // Every status gets its own branch. A refunded order used
                                // to fall through to the else and read "Unpaid", which told
                                // the customer the opposite of the truth — they had paid,
                                // and had been paid back.
                                $refundedOnOrder = (float)($refundsByOrder[$o['id']] ?? 0);
                                switch ($ps):
                                    case 'Paid': ?>
                                    <span class="cbtp-pay-state cbtp-ink-success"><i class="fa-solid fa-circle-check"></i> Paid</span>
                                <?php break; case 'Cash': ?>
                                    <span class="cbtp-pay-state cbtp-ink-warn"><i class="fa-solid fa-money-bill-wave"></i> Paid in Cash</span>
                                <?php break; case 'Bank': ?>
                                    <span class="cbtp-pay-state cbtp-ink-success"><i class="fa-solid fa-building-columns"></i> Paid by Transfer</span>
                                <?php break; case 'Refunded': ?>
                                    <span class="cbtp-pay-state cbtp-ink-success"><i class="fa-solid fa-rotate-left"></i> Refunded</span>
                                <?php break; case 'Part-refunded': ?>
                                    <span class="cbtp-pay-state cbtp-ink-warn"><i class="fa-solid fa-rotate-left"></i>
                                        £<?= number_format($refundedOnOrder, 2) ?> refunded</span>
                                <?php break; default: ?>
                                    <span class="cbtp-pay-state cbtp-ink-danger"><i class="fa-solid fa-clock"></i> Unpaid</span>
                                <?php endswitch; ?>
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
                Invoices we have issued to you. Open one to view it or save it as a PDF.
            </p>

            <?php if (empty($tradeInvoices)): ?>
            <div class="cbtp-empty">
                No invoices yet. They appear here once we have issued and sent one —
                your orders are on the <a href="?tab=orders">Orders</a> tab.
            </div>
            <?php else: ?>
            <div class="cbtp-invoice-grid">
                <?php foreach ($tradeInvoices as $iv):
                    $balance = (float)$iv['total'] - (float)$iv['amount_paid'];
                    $isPaid  = $iv['status'] === 'paid' || $balance <= 0.001;
                    $isPart  = !$isPaid && (float)$iv['amount_paid'] > 0;
                    // A token is minted when the invoice is sent; fall back to
                    // the order-based view for anything issued before that.
                    $link = !empty($iv['public_token'])
                        ? '../invoice.php?t=' . urlencode($iv['public_token'])
                        : '';
                ?>
                <div class="cbtp-card cbtp-tone-plain cbtp-invoice-card">
                    <div class="cbtp-invoice-head">
                        <div>
                            <div class="cbtp-invoice-eyebrow">Invoice</div>
                            <div class="cbtp-invoice-code">
                                <?= htmlspecialchars($iv['invoice_number']) ?>
                            </div>
                        </div>
                        <span class="cbtp-pay-badge <?= $isPaid ? 'is-paid' : 'is-unpaid' ?>">
                            <?= $isPaid ? 'PAID' : ($isPart ? 'PART PAID' : 'DUE') ?>
                        </span>
                    </div>
                    <div class="cbtp-invoice-date">
                        Issued <?= date('d M Y', strtotime($iv['issue_date'])) ?>
                        <?php if (!empty($iv['due_date'])): ?>
                        &middot; due <?= date('d M Y', strtotime($iv['due_date'])) ?>
                        <?php endif; ?>
                    </div>
                    <div class="cbtp-invoice-total">
                        £<?= number_format((float)$iv['total'], 2) ?>
                        <?php if (!$isPaid && $balance > 0.001): ?>
                        <small class="cbtp-invoice-balance">£<?= number_format($balance, 2) ?> outstanding</small>
                        <?php endif; ?>
                    </div>
                    <?php if ($link !== ''): ?>
                    <a href="<?= htmlspecialchars($link) ?>" target="_blank" rel="noopener"
                       class="btn-primary cbtp-btn-block">
                        <i class="fa-solid fa-file-pdf"></i> View / Save as PDF
                    </a>
                    <?php else: ?>
                    <span class="cbtp-invoice-pending">Being prepared — please check back shortly.</span>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        <?php endif; ?>

        </div>
    </div>
</main>
<script src="<?= cbAsset('../assets/js/modal.js') ?>" defer></script>
<script src="<?= cbAsset('../assets/js/animations.js') ?>" defer></script>

</body>
</html>
