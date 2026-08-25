<?php
// ============================================================
// Creamy Bite – B2B Trade Delivery Note & Invoice
// URL: /admin/delivery_note.php?code=CB-123456
//
// Both the shop and the trade customer open this one, which is why the gate
// below accepts either session.
// ============================================================
require_once __DIR__ . '/../includes/session.php';
$isAdmin     = !empty($_SESSION['admin_logged_in']);
$isTradeUser = !empty($_SESSION['trade_user']);

if (!$isAdmin && !$isTradeUser) {
    // ../pages/trade_login.php, not ../trade_login.php. The trade login has
    // lived in pages/ for some time; the old flat address only still works
    // because .htaccess 301s it, so this was relying on a legacy redirect to
    // reach a page one directory along — and went nowhere at all on any setup
    // where that rewrite is not active.
    header('Location: ../pages/trade_login.php');
    exit;
}
if ($isAdmin) {
    // Never checked on the trade-user path — a trade user has no
    // admin_staff_id either, and adminRequire() would otherwise mistake
    // them for the owner.
    require_once __DIR__ . '/_permissions.php';
    adminRequire('orders');
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

/**
 * Small inline SVG marks for this document.
 *
 * Deliberately not Font Awesome. This page is printed and PDF'd, and an icon
 * font is the one thing that reliably does not survive that — the webfont has
 * to download before the print dialog opens, and a PDF generator that misses
 * it prints an empty box where a payment status used to be. These are plain
 * vector shapes in the flow of the document, drawn in currentColor so they
 * take the colour of the text they sit in, and every one of them sits next to
 * a word that already says the same thing.
 */
function dnIcon(string $name): string
{
    $shapes = [
        'print' =>
            '<path d="M4.5 1.5h7v3h-7z"/>'
          . '<path d="M2 5.5h12a1 1 0 0 1 1 1v4a1 1 0 0 1-1 1h-1.5V9h-9v2.5H2a1 1 0 0 1-1-1v-4a1 1 0 0 1 1-1z"/>'
          . '<path d="M4.5 10h7v4.5h-7z"/>',
        'check' =>
            '<circle cx="8" cy="8" r="6.9" fill="none" stroke="currentColor" stroke-width="1.5"/>'
          . '<path d="M4.7 8.2 6.9 10.4 11.3 5.9" fill="none" stroke="currentColor" stroke-width="1.7"'
          . ' stroke-linecap="round" stroke-linejoin="round"/>',
        'cash' =>
            '<rect x="1" y="3.5" width="14" height="9" rx="1.4" fill="none" stroke="currentColor" stroke-width="1.5"/>'
          . '<circle cx="8" cy="8" r="2.1"/>',
        'clock' =>
              '<circle cx="8" cy="8" r="6.9" fill="none" stroke="currentColor" stroke-width="1.5"/>'
            . '<path d="M8 4.2V8l2.6 1.9" fill="none" stroke="currentColor" stroke-width="1.6"'
            . ' stroke-linecap="round" stroke-linejoin="round"/>',
        'shop' =>
            '<path d="M1.5 2.2h13l1 3.3H0.5z"/>'
          . '<path d="M2.3 6.5v7.3h11.4V6.5" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>'
          . '<path d="M5.8 13.8V9.3h3.2v4.5" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/>',
    ];
    if (!isset($shapes[$name])) {
        return '';
    }
    return '<svg class="cbdn-ico" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true" focusable="false">'
         . $shapes[$name] . '</svg>';
}

// Proper status codes on these three. They answered 200 OK with the words
// "Order code required." in the body, which tells a browser, a cache and any
// uptime monitor that the request succeeded — so a broken link to this page
// looked healthy from the outside. The other exports in this folder already
// do this correctly.
$code = trim($_GET['code'] ?? '');
if (empty($code)) {
    http_response_code(400);
    die('Order code required.');
}

$stmt = $pdo->prepare("SELECT * FROM orders WHERE order_code = :code LIMIT 1");
$stmt->execute(['code' => $code]);
$order = $stmt->fetch();

if (!$order) {
    http_response_code(404);
    die('Order not found.');
}

// Refunds against this order, for the credit note at the bottom.
//
// Looked up separately rather than joined so the note still prints on a
// server whose order_refunds table has not been created yet — a delivery note
// that will not print is worse than one missing a credit line.
$refundRows = [];
try {
    $rfStmt = $pdo->prepare("SELECT amount, reason, created_at FROM order_refunds WHERE order_id = ? ORDER BY created_at");
    $rfStmt->execute([(int)$order['id']]);
    $refundRows = $rfStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $refundRows = [];
}

// Who delivered it, if it has been marked Delivered against a named person.
//
// Looked up separately rather than joined so the note still prints on a
// server whose sales_reps table has not been created yet — a delivery note
// that will not print is worse than one missing a name.
$deliveredBy = '';
if (!empty($order['sales_rep_id'])) {
    try {
        $repStmt = $pdo->prepare("SELECT name FROM sales_reps WHERE id = :id");
        $repStmt->execute(['id' => (int)$order['sales_rep_id']]);
        $deliveredBy = (string)($repStmt->fetchColumn() ?: '');
    } catch (PDOException $e) {
        $deliveredBy = '';
    }
}

// Security: trade users can only view their OWN orders.
//
// This used to also accept a matching customer_email or phone. Those are
// values the partner controls — editing their profile phone number to match
// another customer's order was enough to print that customer's delivery note,
// with their name and full address on it. Ownership is trade_user_id alone.
if (!$isAdmin) {
    if (!$isTradeUser) {
        http_response_code(403);
        die('Access denied.');
    }
    $tu      = $_SESSION['trade_user'];
    $ownerId = (int)($order['trade_user_id'] ?? 0);
    if ($ownerId <= 0 || $ownerId !== (int)$tu['id']) {
        http_response_code(403);
        die('Access denied: You do not have permission to view this delivery note.');
    }
}

$items = json_decode($order['items_json'], true) ?? [];
$isTrade = !empty($order['trade_business_name']) || strpos($order['notes'], 'TRADE B2B ORDER') !== false;

$tradeStoreName = $order['trade_business_name'] ?: 'B2B Trade Customer';
if (empty($order['trade_business_name']) && preg_match('/Store:\s*([^\]]+)/i', $order['notes'], $m)) {
    $tradeStoreName = trim($m[1]);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Delivery Note & Invoice – <?= htmlspecialchars($order['order_code']) ?></title>
    <?php require __DIR__ . '/../includes/favicon.php'; ?>
    <!-- Shared .cbdn-* classes come from admin.css; this page's own print rules
         follow in delivery-note.css, so they win any equal-specificity tie. -->
    <link rel="stylesheet" href="<?= cbAsset('assets/css/admin.css') ?>">
    <link rel="stylesheet" href="<?= cbAsset('assets/css/delivery-note.css') ?>">
</head>
<body>

<div class="no-print cbdn-print-bar">
    <button onclick="window.print()" class="cbdn-print-btn">
        <?= dnIcon('print') ?> Print Delivery Note / Invoice
    </button>
</div>

<div class="dn-container">
    <div class="dn-header">
        <div>
            <?php
            // Logo, with the shop name as the fallback if the file is missing —
            // same approach as the invoice (admin/invoice_view.php).
            $dnLogo = __DIR__ . '/../assets/images/logo.png';
            if (is_file($dnLogo)):
            ?>
            <img src="../assets/images/logo.png" alt="<?= htmlspecialchars(SHOP_NAME) ?>" class="dn-logo-img">
            <?php else: ?>
            <div class="dn-logo"><?= htmlspecialchars(SHOP_NAME) ?></div>
            <?php endif; ?>
            <div class="cbdn-company-tagline">Authentic Artisanal Ice Cream Wholesale</div>
            <div class="cbdn-muted-note">Unit E5 Phoenix Business Centre, HA1 2SP | Tel: +44 7497 779997</div>
        </div>
        <div class="cbdn-align-right">
            <h1 class="cbdn-doc-title">B2B DELIVERY NOTE</h1>
            <div class="dn-badge"><?= $isTrade ? 'TRADE WHOLESALE' : 'RETAIL ORDER' ?></div>
            <div class="cbdn-doc-code">
                <?= htmlspecialchars($order['order_code']) ?>
            </div>
            <div class="cbdn-doc-date">
                Date: <?= date('d F Y - H:i', strtotime($order['created_at'])) ?>
            </div>
        </div>
    </div>

    <div class="dn-grid">
        <div class="dn-card">
            <h3>DELIVER TO (RETAIL STORE)</h3>
            <strong class="cbdn-store-name">
                <?= dnIcon('shop') ?> <?= htmlspecialchars($tradeStoreName) ?>
            </strong>
            <div class="cbdn-contact-line">Contact Person: <?= htmlspecialchars($order['customer_name']) ?></div>
            <div class="cbdn-contact-phone">Phone: <?= htmlspecialchars($order['phone']) ?></div>
            <?php if (!empty($order['customer_email'])): ?>
            <div class="cbdn-contact-email">Email: <?= htmlspecialchars($order['customer_email']) ?></div>
            <?php endif; ?>
            <div class="cbdn-address-block">
                <strong>Store Address:</strong><br>
                <?= htmlspecialchars($order['address']) ?><br>
                <strong class="cbdn-postcode"><?= htmlspecialchars($order['postcode']) ?></strong>
            </div>
        </div>

        <div class="dn-card">
            <h3>ORDER & PAYMENT DETAILS</h3>
            <div class="cbdn-field">
                <span class="cbdn-field-label">Payment Status:</span><br>
                <?php if ($order['payment_status'] === 'Paid'): ?>
                <strong class="cbdn-pay-badge cbdn-pay-badge-paid"><?= dnIcon('check') ?> PAID ONLINE</strong>
                <?php elseif ($order['payment_status'] === 'Cash'): ?>
                <strong class="cbdn-pay-badge cbdn-pay-badge-pending"><?= dnIcon('cash') ?> CASH ON DELIVERY</strong>
                <?php else: ?>
                <strong class="cbdn-pay-badge cbdn-pay-badge-pending"><?= dnIcon('clock') ?> INVOICE PENDING (PAY ON DELIVERY)</strong>
                <?php endif; ?>
            </div>
            <div class="cbdn-notes-block">
                <strong class="cbdn-notes-label"><?= dnIcon('clock') ?> Opening Times & Instructions:</strong>
                <div class="cbdn-notes-box">
                    <?= !empty($order['notes']) ? htmlspecialchars($order['notes']) : 'Standard store delivery' ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Items Table -->
    <table class="dn-table">
        <thead>
            <tr>
                <th class="cbdn-col-index">#</th>
                <th>Item / Flavour Description</th>
                <th class="cbdn-col-qty">Qty Tubs</th>
                <th class="cbdn-col-price">Trade Price</th>
                <th class="cbdn-col-subtotal">Subtotal</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $i = 1;
            foreach ($items as $item):
                $lineTotal = (float)$item['price'] * (int)$item['quantity'];
            ?>
            <tr>
                <td><?= $i++ ?></td>
                <td>
                    <?php // No glyph here at all. This document does not load Font Awesome,
                          // so an icon would print as an empty box, and the emoji it replaced
                          // printed at a different weight to the text beside it. The product
                          // name is what anyone packing the order actually reads. ?>
                    <strong class="cbdn-item-name"><?= htmlspecialchars($item['name']) ?></strong>
                    <?php if (!empty($item['variant_name'])): ?>
                    <div class="cbdn-muted-note"><?= htmlspecialchars($item['variant_name']) ?></div>
                    <?php endif; ?>
                </td>
                <td class="cbdn-qty-cell"><?= (int)$item['quantity'] ?></td>
                <td class="cbdn-align-right">£<?= number_format((float)$item['price'], 2) ?></td>
                <td class="cbdn-amount-cell">£<?= number_format($lineTotal, 2) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="dn-totals">
        <div class="cbdn-row-split cbdn-total-row">
            <span>Subtotal:</span>
            <span>£<?= number_format((float)$order['total_price'] - (float)($order['delivery_charge'] ?? 0), 2) ?></span>
        </div>
        <div class="cbdn-row-split cbdn-total-row">
            <span>Delivery:</span>
            <span><?= (float)($order['delivery_charge'] ?? 0) > 0 ? '£' . number_format((float)$order['delivery_charge'], 2) : 'FREE' ?></span>
        </div>
        <div class="grand-total cbdn-row-split">
            <span>TOTAL DUE:</span>
            <span>£<?= number_format((float)$order['total_price'], 2) ?></span>
        </div>

        <?php
        // Refunds shown on the document itself, with the VAT split out.
        //
        // A trade customer who has reclaimed VAT on this order needs a credit
        // note to reverse it — a smaller number with no explanation is not
        // something their accountant can post. The VAT share is worked out
        // proportionally from the order's own VAT, the same way the VAT return
        // does it, so the two can never disagree.
        if (!empty($refundRows)):
            $refundTotal = array_sum(array_map(static fn($r) => (float)$r['amount'], $refundRows));
            $orderTotal  = (float)$order['total_price'];
            $orderVat    = (float)($order['vat_amount'] ?? 0);
            $refundVat   = ($orderTotal > 0 && $orderVat > 0)
                ? round($orderVat * min(1.0, $refundTotal / $orderTotal), 2) : 0.00;
        ?>
        <div class="cbdn-credit">
            <div class="cbdn-credit-head">CREDIT NOTE — REFUNDS AGAINST THIS ORDER</div>
            <?php foreach ($refundRows as $r): ?>
            <div class="cbdn-row-split cbdn-total-row">
                <span>
                    <?= htmlspecialchars(date('j M Y', strtotime($r['created_at']))) ?>
                    <?= $r['reason'] !== '' ? ' — ' . htmlspecialchars($r['reason']) : '' ?>
                </span>
                <span>−£<?= number_format((float)$r['amount'], 2) ?></span>
            </div>
            <?php endforeach; ?>
            <?php if ($refundVat > 0): ?>
            <div class="cbdn-row-split cbdn-total-row cbdn-credit-vat">
                <span>of which VAT</span>
                <span>−£<?= number_format($refundVat, 2) ?></span>
            </div>
            <?php endif; ?>
            <div class="grand-total cbdn-row-split">
                <span>NET AFTER REFUNDS:</span>
                <span>£<?= number_format(max(0, $orderTotal - $refundTotal), 2) ?></span>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Signatures Block -->
    <div class="dn-signatures">
        <div>
            <div class="cbdn-sig-label">DRIVER / DELIVERED BY</div>
            <?php // Printed into the box when the order has been marked
                  // Delivered against a named person, so the note leaving the
                  // warehouse already says who is taking it. Still leaves room
                  // for the signature underneath. ?>
            <div class="sig-box<?= !empty($deliveredBy) ? ' cbdn-sig-filled' : '' ?>">
                <?= !empty($deliveredBy) ? htmlspecialchars($deliveredBy) : '' ?>
            </div>
            <div class="cbdn-sig-hint">
                <?= !empty($deliveredBy) ? 'Signature &amp; Date' : 'Print Name, Signature &amp; Date' ?>
            </div>
        </div>
        <div>
            <div class="cbdn-sig-label">RECEIVED BY (STORE ACCEPTANCE)</div>
            <div class="sig-box"></div>
            <div class="cbdn-sig-hint">Print Name & Signature</div>
        </div>
    </div>
</div>

</body>
</html>
