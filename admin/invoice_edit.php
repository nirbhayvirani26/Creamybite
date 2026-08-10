<?php
// ============================================================
//  Creamy Bite – Admin: Invoice editor
//  URL: /admin/invoice_edit.php?id=12
//
//  Every field on the document is editable, including the bill-from block,
//  because an invoice is a snapshot: correcting a past invoice must not be
//  blocked by today's shop settings.
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}
require_once __DIR__ . '/_permissions.php';
adminRequire('invoices');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/invoice.php';

$invoiceId = (int)($_GET['id'] ?? 0);
$inv = $invoiceId > 0 ? loadInvoice($pdo, $invoiceId) : null;

if (!$inv) {
    header('Location: index.php?tab=invoices');
    exit;
}

$flash = $_SESSION['invoice_flash'] ?? null;
unset($_SESSION['invoice_flash']);

// A trade invoice prices its picker at wholesale.
$isTradeInvoice = ((int)$inv['trade_user_id'] > 0) || ((float)$inv['vat_rate'] > 0);
$productOptions = invoiceProductOptions($pdo, $isTradeInvoice);
$tradeCustomers = invoiceTradeCustomers($pdo);
$salesReps      = invoiceSalesReps($pdo);

// The order this invoice came from, shown by its CB- code rather than its
// database id — the code is what appears on the order and in emails.
$sourceOrderCode = null;
if (!empty($inv['order_id'])) {
    $oc = $pdo->prepare("SELECT order_code FROM orders WHERE id = :id");
    $oc->execute(['id' => (int)$inv['order_id']]);
    $sourceOrderCode = $oc->fetchColumn() ?: null;
}

[$stLabel, $stBg, $stFg, $stBd] = invoiceStatusLabel($inv['status']);
$locked = ($inv['status'] === 'void');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Edit <?= htmlspecialchars($inv['invoice_number']) ?> – <?= SHOP_NAME ?></title>
<?php require __DIR__ . '/../includes/favicon.php'; ?>
<link rel="stylesheet" href="<?= cbAsset('../assets/css/style.css') ?>">
<link rel="stylesheet" href="<?= cbAsset('../assets/css/responsive.css') ?>">
<!-- This page's own cbie-* layout classes live in admin.css. -->
<link rel="stylesheet" href="<?= cbAsset('assets/css/admin.css') ?>">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<?php include __DIR__ . '/_csrf_js.php'; ?>
<link rel="stylesheet" href="<?= cbAsset('../assets/css/modal.css') ?>">
<script src="<?= cbAsset('../assets/js/modal.js') ?>" defer></script>
</head>
<body class="admin-wrapper has-sidebar cbie-page">

<?php
// Same sidebar as every other admin page.
$cbSidebarCurrent = 'invoices';
require __DIR__ . '/_sidebar.php';
?>

<div class="admin-shell">
<div class="container cbie-shell">

    <div class="cbie-page-head">
        <div>
            <a href="index.php?tab=invoices" class="cbie-back-link">
                <i class="fa-solid fa-arrow-left"></i> All invoices
            </a>
            <h1 class="cbie-invoice-title">
                <?= htmlspecialchars($inv['invoice_number']) ?>
                <span class="cbie-status-pill <?= invoiceStatusClass($inv['status']) ?>">
                    <?= $stLabel ?>
                </span>
            </h1>
            <?php if ($sourceOrderCode): ?>
            <div class="cbie-source-note">
                Raised from order <strong class="cbie-order-code"><?= htmlspecialchars($sourceOrderCode) ?></strong>
            </div>
            <?php endif; ?>
        </div>
        <div class="cbie-head-actions">
            <a href="invoice_view.php?id=<?= (int)$inv['id'] ?>" target="_blank" class="btn-secondary cbie-btn-sm">
                <i class="fa-solid fa-eye"></i> Preview / Print
            </a>
            <button type="submit" form="invoiceForm" class="btn-primary cbie-btn-save">
                <i class="fa-solid fa-floppy-disk"></i> Save Invoice
            </button>
        </div>
    </div>

    <?php if ($flash): ?>
    <div class="flash flash-<?= htmlspecialchars($flash['type'] === 'error' ? 'error' : ($flash['type'] === 'warn' ? 'warn' : 'ok')) ?>">
        <?= htmlspecialchars($flash['msg']) ?>
    </div>
    <?php endif; ?>

    <?php if ($locked): ?>
    <div class="flash cbie-flash-void">
        This invoice is <strong>VOID</strong>. It is kept for the record and its number is never reused.
        Reopen it as a draft below if it was voided by mistake.
    </div>
    <?php endif; ?>

    <form method="POST" action="handlers/invoice_handler.php" id="invoiceForm">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="invoice_id" value="<?= (int)$inv['id'] ?>">

        <!-- ── Dates & terms ─────────────────────────── -->
        <div class="inv-card">
            <h3>Invoice details</h3>
            <div class="inv-grid">
                <div>
                    <label class="form-label">Issue Date</label>
                    <input type="date" name="issue_date" class="form-control" value="<?= htmlspecialchars($inv['issue_date']) ?>">
                </div>
                <div>
                    <label class="form-label">Payment Terms</label>
                    <input type="text" name="due_terms" class="form-control" value="<?= htmlspecialchars($inv['due_terms']) ?>" placeholder="On Receipt">
                    <small class="cbie-hint">Shown as “DUE” when no due date is set.</small>
                </div>
                <div>
                    <label class="form-label">Due Date <small class="cbie-muted">(3 weeks by default)</small></label>
                    <input type="date" name="due_date" id="fDueDate" class="form-control" value="<?= htmlspecialchars((string)$inv['due_date']) ?>">
                    <small class="cbie-hint">Moves with the issue date until you set it yourself.</small>
                </div>
            </div>
        </div>

        <!-- ── From / To ─────────────────────────────── -->
        <div class="inv-grid">
            <div class="inv-card">
                <h3>Bill From</h3>
                <label class="form-label">Business Name</label>
                <input type="text" name="from_name" class="form-control" value="<?= htmlspecialchars($inv['from_name']) ?>">
                <label class="form-label cbie-label-gap">Address</label>
                <textarea name="from_address" class="form-control" rows="3"><?= htmlspecialchars($inv['from_address']) ?></textarea>
                <label class="form-label cbie-label-gap">Phone</label>
                <input type="text" name="from_phone" class="form-control" value="<?= htmlspecialchars($inv['from_phone']) ?>">
                <label class="form-label cbie-label-gap">Email</label>
                <input type="text" name="from_email" class="form-control" value="<?= htmlspecialchars($inv['from_email']) ?>">
                <label class="form-label cbie-label-gap">Website</label>
                <input type="text" name="from_website" class="form-control" value="<?= htmlspecialchars($inv['from_website']) ?>">
            </div>

            <div class="inv-card">
                <h3>Bill To</h3>

                <?php if (!empty($tradeCustomers)): ?>
                <label class="form-label">Load a trade customer <small class="cbie-muted">(optional)</small></label>
                <select id="tradePicker" class="form-control cbie-trade-picker">
                    <option value="">— choose a trade account to fill these fields —</option>
                    <?php foreach ($tradeCustomers as $tc): ?>
                    <option value="<?= (int)$tc['id'] ?>"
                            data-name="<?= htmlspecialchars($tc['business_name'], ENT_QUOTES) ?>"
                            data-address="<?= htmlspecialchars(rtrim($tc['address']) . "\n" . $tc['postcode'], ENT_QUOTES) ?>"
                            data-email="<?= htmlspecialchars($tc['email'], ENT_QUOTES) ?>"
                            data-phone="<?= htmlspecialchars($tc['phone'], ENT_QUOTES) ?>"
                            data-vat="<?= htmlspecialchars($tc['vat_number'], ENT_QUOTES) ?>">
                        <?= htmlspecialchars($tc['business_name']) ?><?= $tc['contact_name'] ? ' — ' . htmlspecialchars($tc['contact_name']) : '' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <small class="cbie-hint cbie-hint-picker">
                    Fills the fields below. You can still edit anything afterwards — the invoice keeps
                    whatever is saved here, not a live link to the account.
                </small>
                <?php endif; ?>

                <label class="form-label">Customer / Business Name</label>
                <input type="text" name="to_name" id="toName" class="form-control" value="<?= htmlspecialchars($inv['to_name']) ?>">
                <label class="form-label cbie-label-gap">Address</label>
                <textarea name="to_address" id="toAddress" class="form-control" rows="3"><?= htmlspecialchars($inv['to_address']) ?></textarea>
                <label class="form-label cbie-label-gap">Phone</label>
                <input type="text" name="to_phone" id="toPhone" class="form-control" value="<?= htmlspecialchars($inv['to_phone']) ?>">
                <label class="form-label cbie-label-gap">Email</label>
                <input type="text" name="to_email" id="toEmail" class="form-control" value="<?= htmlspecialchars($inv['to_email']) ?>">
                <label class="form-label cbie-label-gap">VAT Number</label>
                <input type="text" name="to_vat_number" id="toVat" class="form-control" value="<?= htmlspecialchars($inv['to_vat_number']) ?>">
            </div>
        </div>

        <!-- ── Line items ────────────────────────────── -->
        <div class="inv-card">
            <h3>Line items</h3>

            <?php if (!empty($productOptions)): ?>
            <div class="cbie-picker-bar">
                <div class="cbie-picker-search">
                    <label class="form-label cbie-label-tight">Add from the menu</label>
                    <input type="text" id="productSearch" class="form-control" list="productList"
                           placeholder="Type to search products and sizes…" autocomplete="off">
                    <datalist id="productList">
                        <?php foreach ($productOptions as $po): ?>
                        <option value="<?= htmlspecialchars($po['label'], ENT_QUOTES) ?>">
                            <?= htmlspecialchars(invoicePriceLabel($po)) ?>
                        </option>
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <div class="cbie-picker-qty">
                    <label class="form-label cbie-label-tight">Qty</label>
                    <input type="number" id="productQty" class="form-control" value="1" step="0.001" min="0">
                </div>
                <button type="button" class="btn-primary cbie-btn-md" onclick="addPickedProduct()">
                    <i class="fa-solid fa-plus"></i> Add line
                </button>
                <span id="pickPrice" class="cbie-pick-price"></span>
            </div>
            <?php endif; ?>

            <table class="lines" id="lineTable">
                <thead>
                    <tr>
                        <th class="cbie-col-desc">Description</th>
                        <th class="cbie-col-rate">Rate</th>
                        <th class="cbie-col-note">Rate note</th>
                        <th class="cbie-col-qty">Qty</th>
                        <th class="cbie-col-unit">Unit</th>
                        <th class="cbie-col-amount">Amount</th>
                        <th class="cbie-col-remove"></th>
                    </tr>
                </thead>
                <tbody id="lineBody">
                <?php foreach ($inv['items'] as $it): ?>
                    <tr class="line-row">
                        <td><input type="text"   name="item_description[]" class="form-control line-desc" list="productList" autocomplete="off" value="<?= htmlspecialchars($it['description']) ?>"></td>
                        <td><input type="number" name="item_rate[]"        class="form-control line-rate" step="0.01" min="0" value="<?= htmlspecialchars(number_format((float)$it['rate'], 2, '.', '')) ?>"><small class="cbie-rate-hint"></small></td>
                        <td><input type="text"   name="item_rate_note[]"   class="form-control" value="<?= htmlspecialchars($it['rate_note']) ?>" placeholder="e.g. don't have 1L Tub"></td>
                        <td><input type="number" name="item_qty[]"         class="form-control line-qty" step="0.001" min="0" value="<?= htmlspecialchars(rtrim(rtrim(number_format((float)$it['qty'], 3, '.', ''), '0'), '.')) ?>"></td>
                        <td><input type="text"   name="item_qty_unit[]"    class="form-control" value="<?= htmlspecialchars($it['qty_unit']) ?>" placeholder="Litre"></td>
                        <td class="amt line-amount cbie-amount-cell">£<?= number_format((float)$it['amount'], 2) ?></td>
                        <td class="cbie-cell-pad-top">
                            <button type="button" class="btn-danger cbie-btn-mini" onclick="removeLine(this)"><i class="fa-solid fa-xmark"></i></button>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <button type="button" class="btn-secondary cbie-btn-add-line" onclick="addLine()">
                <i class="fa-solid fa-plus"></i> Add line
            </button>
        </div>

        <!-- ── Adjustments & totals ──────────────────── -->
        <div class="inv-grid">
            <div class="inv-card">
                <h3>Adjustments</h3>
                <label class="form-label">Discount</label>
                <div class="cbie-discount-row">
                    <select name="discount_type" id="fDiscountType" class="form-control cbie-input-narrow">
                        <option value="fixed"   <?= $inv['discount_type'] !== 'percent' ? 'selected' : '' ?>>Fixed (£)</option>
                        <option value="percent" <?= $inv['discount_type'] === 'percent' ? 'selected' : '' ?>>Percent (%)</option>
                    </select>
                    <input type="number" step="0.01" min="0" name="discount_value" id="fDiscount" class="form-control"
                           value="<?= htmlspecialchars(number_format((float)$inv['discount_value'], 2, '.', '')) ?>">
                </div>
                <small id="discountHint" class="cbie-hint cbie-hint-discount"></small>
                <label class="form-label cbie-label-gap">Delivery (£)</label>
                <input type="number" step="0.01" min="0" name="delivery" id="fDelivery" class="form-control" value="<?= htmlspecialchars(number_format((float)$inv['delivery'], 2, '.', '')) ?>">
                <label class="form-label cbie-label-gap">VAT rate (%)</label>
                <input type="number" step="0.01" min="0" name="vat_rate" id="fVatRate" class="form-control" value="<?= htmlspecialchars(rtrim(rtrim(number_format((float)$inv['vat_rate'] * 100, 2, '.', ''), '0'), '.')) ?>">
                <small class="cbie-hint">0 for no VAT. 20 for standard UK rate.</small>
            </div>

            <div class="inv-card">
                <h3>Totals</h3>
                <div class="totbox">
                    <div class="totrow"><span>Subtotal</span><strong id="tSubtotal">£<?= number_format((float)$inv['subtotal'], 2) ?></strong></div>
                    <div class="totrow"><span>Discount</span><span id="tDiscount">−£<?= number_format((float)$inv['discount'], 2) ?></span></div>
                    <div class="totrow"><span>Delivery</span><span id="tDelivery">£<?= number_format((float)$inv['delivery'], 2) ?></span></div>
                    <div class="totrow"><span>VAT</span><span id="tVat">£<?= number_format((float)$inv['vat_amount'], 2) ?></span></div>
                    <div class="totrow grand"><span>Total</span><span id="tTotal">£<?= number_format((float)$inv['total'], 2) ?></span></div>
                    <div class="totrow"><span>Paid</span><span>−£<?= number_format((float)$inv['amount_paid'], 2) ?></span></div>
                    <div class="totrow cbie-totrow-strong"><span>Balance due</span><span>£<?= number_format((float)$inv['balance_due'], 2) ?></span></div>
                </div>
                <small class="cbie-hint cbie-hint-totals">
                    Figures above update live; the stored totals are recalculated on save.
                </small>
            </div>
        </div>

        <!-- ── Sales rep & commission (internal) ──────── -->
        <div class="inv-card cbie-internal">
            <h3>
                Sold by
                <span class="cbie-internal-tag">
                    <i class="fa-solid fa-lock"></i> Internal only — never printed or emailed
                </span>
            </h3>
            <div class="inv-grid">
                <div>
                    <label class="form-label">Sales rep / agent</label>
                    <select name="sales_rep_id" id="fSalesRep" class="form-control">
                        <option value="0">— House sale (no rep) —</option>
                        <?php foreach ($salesReps as $rep): ?>
                        <option value="<?= (int)$rep['id'] ?>"
                            <?= (int)$inv['sales_rep_id'] === (int)$rep['id'] ? 'selected' : '' ?>
                            <?= (!$rep['active'] && (int)$inv['sales_rep_id'] !== (int)$rep['id']) ? 'disabled' : '' ?>>
                            <?= htmlspecialchars($rep['name']) ?><?= $rep['active'] ? '' : ' (inactive)' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (empty($salesReps)): ?>
                    <small class="cbie-hint">
                        No reps yet — add them from the Invoices tab.
                    </small>
                    <?php endif; ?>
                </div>
                <div>
                    <label class="form-label">Commission (%)</label>
                    <select name="commission_percent" id="fCommission" class="form-control">
                        <option value="0">— None —</option>
                        <?php
                        // 2–20% in whole points: the agreed band. A stored rate
                        // outside it (from older data) is added so editing an
                        // invoice never silently changes what the rep is owed.
                        $rates = range(2, 20);
                        $current = (float)$inv['commission_percent'];
                        if ($current > 0 && !in_array((int)$current, $rates, true)) {
                            $rates[] = $current;
                            sort($rates);
                        }
                        foreach ($rates as $r):
                        ?>
                        <option value="<?= $r ?>" <?= abs($current - $r) < 0.005 ? 'selected' : '' ?>>
                            <?= rtrim(rtrim(number_format((float)$r, 2, '.', ''), '0'), '.') ?>%
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <small class="cbie-hint">Charged on the goods, excluding VAT.</small>
                </div>
                <div>
                    <label class="form-label">Commission due</label>
                    <div class="cbie-commission-figure" id="tCommission">
                        £<?= number_format(invoiceCommission($inv), 2) ?>
                    </div>
                    <small class="cbie-hint" id="commissionBasis">
                        <?= number_format((float)$inv['total'] - (float)$inv['vat_amount'], 2) ?> ex-VAT
                    </small>
                </div>
            </div>
        </div>

        <!-- ── Payment instructions & notes ──────────── -->
        <div class="inv-grid">
            <div class="inv-card">
                <h3>Payment instructions</h3>
                <textarea name="payment_instructions" class="form-control" rows="4"><?= htmlspecialchars($inv['payment_instructions']) ?></textarea>
            </div>
            <div class="inv-card">
                <h3>Notes</h3>
                <textarea name="notes" class="form-control" rows="4" placeholder="Anything to print at the foot of the invoice"><?= htmlspecialchars($inv['notes']) ?></textarea>
            </div>
        </div>
    </form>

    <!-- ── Payments ──────────────────────────────────── -->
    <div class="inv-card">
        <h3>Payments received</h3>

        <?php if (empty($inv['payments'])): ?>
        <p class="cbie-empty-note">Nothing recorded yet.</p>
        <?php else: ?>
        <table class="lines cbie-pay-table">
            <thead><tr><th>Date</th><th>Amount</th><th>Method</th><th>Reference</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($inv['payments'] as $p): ?>
                <tr>
                    <td class="cbie-cell-pad-top cbie-pay-cell"><?= date('d/m/Y', strtotime($p['paid_on'])) ?></td>
                    <td class="cbie-cell-pad-top cbie-pay-amount">£<?= number_format((float)$p['amount'], 2) ?></td>
                    <td class="cbie-cell-pad-top cbie-pay-cell"><?= htmlspecialchars($p['method']) ?></td>
                    <td class="cbie-cell-pad-top cbie-pay-cell"><?= htmlspecialchars($p['reference']) ?: '—' ?></td>
                    <td>
                        <form method="POST" action="handlers/invoice_handler.php" data-confirm="Remove this payment? The balance goes back up by that amount." data-confirm-title="Remove payment?" data-confirm-tone="danger" data-confirm-ok="Remove" class="cbie-form-flat">
        <?= csrfField() ?>
                            <input type="hidden" name="action" value="delete_payment">
                            <input type="hidden" name="invoice_id" value="<?= (int)$inv['id'] ?>">
                            <input type="hidden" name="payment_id" value="<?= (int)$p['id'] ?>">
                            <button class="btn-danger cbie-btn-mini"><i class="fa-solid fa-xmark"></i></button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>

        <form method="POST" action="handlers/invoice_handler.php" class="cbie-payment-form">
        <?= csrfField() ?>
            <input type="hidden" name="action" value="add_payment">
            <input type="hidden" name="invoice_id" value="<?= (int)$inv['id'] ?>">
            <div><label class="form-label">Date</label><input type="date" name="paid_on" class="form-control" value="<?= date('Y-m-d') ?>"></div>
            <div><label class="form-label">Amount (£)</label><input type="number" step="0.01" min="0.01" name="amount" class="form-control cbie-input-narrow" value="<?= htmlspecialchars(number_format(max(0, (float)$inv['balance_due']), 2, '.', '')) ?>"></div>
            <div><label class="form-label">Method</label>
                <select name="method" class="form-control">
                    <option>Bank Transfer</option><option>Card</option><option>Cash</option><option>Cheque</option><option>Other</option>
                </select>
            </div>
            <div class="cbie-pay-ref-col"><label class="form-label">Reference</label><input type="text" name="reference" class="form-control" placeholder="optional"></div>
            <button class="btn-primary cbie-btn-md"><i class="fa-solid fa-plus"></i> Record payment</button>
        </form>
    </div>

    <!-- ── Document actions ──────────────────────────── -->
    <div class="inv-card">
        <h3>Document actions</h3>

        <?php
        // Sending needs the invoice to be a real document, so a draft is
        // promoted to "sent" as part of sending rather than needing a separate
        // click first — nobody sends a bill and then wants it still called a draft.
        $sendEmail = trim((string)$inv['to_email']);
        $canEmail  = $sendEmail !== '' && filter_var($sendEmail, FILTER_VALIDATE_EMAIL);
        $waNumber  = invoiceWhatsAppNumber((string)$inv['to_phone']);
        // Minted for drafts too. Waiting until the invoice was "sent" meant the
        // first WhatsApp click only prepared the link and reloaded the page —
        // the customer's chat never opened, and you had to click again. Now the
        // link is ready immediately and the click both opens WhatsApp and marks
        // the invoice sent.
        $shareLink = ($inv['status'] !== 'void') ? invoicePublicUrl($pdo, $inv) : '';
        $waText    = 'Hello ' . trim((string)$inv['to_name']) . ', here is your invoice '
                   . $inv['invoice_number'] . ' from ' . SHOP_NAME . ' for £'
                   . number_format((float)$inv['balance_due'], 2) . '.';
        ?>

        <?php if ($inv['status'] !== 'void'): ?>
        <div class="cbie-send-row">
            <form method="POST" action="handlers/invoice_handler.php" class="cbie-form-flat"
                  data-confirm="Email invoice <?= htmlspecialchars($inv['invoice_number'], ENT_QUOTES) ?> to <?= htmlspecialchars($sendEmail ?: 'the customer', ENT_QUOTES) ?>?<?= $inv['status'] === 'draft' ? ' It will be marked as sent.' : '' ?>"
                  data-confirm-title="Send this invoice?" data-confirm-ok="Send it">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="send_email">
                <input type="hidden" name="invoice_id" value="<?= (int)$inv['id'] ?>">
                <button class="btn-primary cbie-btn-sm" <?= $canEmail ? '' : 'disabled' ?>>
                    <i class="fa-solid fa-envelope"></i> Send by email
                </button>
            </form>

            <?php if ($shareLink !== '' && $waNumber !== ''): ?>
            <?php // A real link, so one click opens WhatsApp. Marking the invoice
                  // sent rides along as a background request rather than a page
                  // submit — a redirect here would cancel the window the click
                  // just opened. ?>
            <a href="https://wa.me/<?= htmlspecialchars($waNumber) ?>?text=<?= rawurlencode($waText . ' ' . $shareLink) ?>"
               target="_blank" rel="noopener" class="btn-secondary cbie-btn-sm cbie-wa-btn"
               onclick="cbMarkInvoiceShared(<?= (int)$inv['id'] ?>)">
                <i class="fa-brands fa-whatsapp"></i> Send by WhatsApp
            </a>
            <?php else: ?>
            <button class="btn-secondary cbie-btn-sm cbie-wa-btn" disabled
                    title="<?= $waNumber === '' ? 'No mobile number on this invoice' : 'This invoice is void' ?>">
                <i class="fa-brands fa-whatsapp"></i> Send by WhatsApp
            </button>
            <?php endif; ?>
        </div>

        <p class="cbie-hint cbie-send-note">
            <?php if (!$canEmail): ?>
                No email address on this invoice — add one in <strong>Bill To</strong> to enable sending.
            <?php elseif ($waNumber === ''): ?>
                Emails to <strong><?= htmlspecialchars($sendEmail) ?></strong>.
                Add a mobile number in <strong>Bill To</strong> to enable WhatsApp.
            <?php else: ?>
                Emails to <strong><?= htmlspecialchars($sendEmail) ?></strong>,
                WhatsApp to <strong><?= htmlspecialchars($inv['to_phone']) ?></strong>.
                Both send a link the customer opens and saves as a PDF.
            <?php endif; ?>
            <?php if (!empty($inv['sent_at'])): ?>
            <br>Last sent <?= date('d M Y, H:i', strtotime((string)$inv['sent_at'])) ?>.
            <?php endif; ?>
        </p>
        <?php endif; ?>

        <div class="cbie-doc-actions">
            <?php if ($inv['status'] === 'draft'): ?>
            <form method="POST" action="handlers/invoice_handler.php" class="cbie-form-flat">
        <?= csrfField() ?>
                <input type="hidden" name="action" value="set_status">
                <input type="hidden" name="invoice_id" value="<?= (int)$inv['id'] ?>">
                <input type="hidden" name="status" value="sent">
                <button class="btn-primary cbie-btn-sm"><i class="fa-solid fa-paper-plane"></i> Mark as sent</button>
            </form>
            <?php endif; ?>

            <?php if ($inv['status'] === 'void'): ?>
            <form method="POST" action="handlers/invoice_handler.php" class="cbie-form-flat">
        <?= csrfField() ?>
                <input type="hidden" name="action" value="set_status">
                <input type="hidden" name="invoice_id" value="<?= (int)$inv['id'] ?>">
                <input type="hidden" name="status" value="draft">
                <button class="btn-secondary cbie-btn-sm"><i class="fa-solid fa-rotate-left"></i> Reopen as draft</button>
            </form>
            <?php else: ?>
            <form method="POST" action="handlers/invoice_handler.php" class="cbie-form-flat" data-confirm="Void this invoice? It stays on record and its number is never reused." data-confirm-title="Void invoice?" data-confirm-tone="warn" data-confirm-ok="Void it">
        <?= csrfField() ?>
                <input type="hidden" name="action" value="set_status">
                <input type="hidden" name="invoice_id" value="<?= (int)$inv['id'] ?>">
                <input type="hidden" name="status" value="void">
                <button class="btn-secondary cbie-btn-sm"><i class="fa-solid fa-ban"></i> Void</button>
            </form>
            <?php endif; ?>

            <form method="POST" action="handlers/invoice_handler.php" class="cbie-form-flat">
        <?= csrfField() ?>
                <input type="hidden" name="action" value="duplicate">
                <input type="hidden" name="invoice_id" value="<?= (int)$inv['id'] ?>">
                <button class="btn-secondary cbie-btn-sm"><i class="fa-solid fa-copy"></i> Duplicate</button>
            </form>

            <?php if ($inv['status'] === 'draft'): ?>
            <form method="POST" action="handlers/invoice_handler.php" class="cbie-form-flat cbie-form-push-right" data-confirm="Delete this draft permanently? Only drafts can be deleted." data-confirm-title="Delete draft?" data-confirm-tone="danger" data-confirm-ok="Delete">
        <?= csrfField() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="invoice_id" value="<?= (int)$inv['id'] ?>">
                <button class="btn-danger cbie-btn-sm"><i class="fa-solid fa-trash"></i> Delete draft</button>
            </form>
            <?php endif; ?>
        </div>
        <p class="cbie-doc-note">
            Issued invoices cannot be deleted, only voided — invoice numbers must stay gapless for accounting.
        </p>
    </div>

</div>

<script>
// ── Live totals ────────────────────────────────────────────
// Mirrors recalcInvoice() in invoice.php. The server recomputes on save,
// so this is only for immediate feedback while editing.
function money(n){ return '£' + (Math.round(n * 100) / 100).toFixed(2); }

// Product catalogue for the picker.
//   p = the rate this invoice will use, t = trade price, r = retail price.
// Both prices travel with every entry so the hints can show the one that is
// NOT being charged as well — that is what makes a mispriced line obvious.
const PRODUCT_INFO = <?= json_encode(array_combine(
    array_column($productOptions, 'label'),
    array_map(fn($p) => [
        'p' => round((float)$p['price'], 2),
        't' => round((float)$p['wholesale'], 2),
        'r' => round((float)$p['retail'], 2),
    ], $productOptions)
) ?: new stdClass()) ?>;

// This invoice prices at <?= $isTradeInvoice ? 'trade' : 'retail' ?> rates.
const USES_TRADE = <?= $isTradeInvoice ? 'true' : 'false' ?>;

/** "Trade £3.50 · Retail £5.00" — mirrors invoicePriceLabel() in invoice.php. */
function priceHint(info) {
    if (!info) return '';
    if (info.t > 0 && Math.abs(info.t - info.r) > 0.005) {
        return 'Trade ' + money(info.t) + '  ·  Retail ' + money(info.r);
    }
    return money(info.r);
}

/** Put both prices under a line's Rate box, flagging the one in use. */
function showRateHint(row, info) {
    if (!row) return;
    var el = row.querySelector('.cbie-rate-hint');
    if (!el) return;
    if (!info) { el.textContent = ''; el.removeAttribute('data-using'); return; }
    el.textContent = priceHint(info);
    el.setAttribute('data-using', USES_TRADE && info.t > 0 ? 'trade' : 'retail');
}

// Show both prices as soon as a product is chosen, before it is added.
(function () {
    var search = document.getElementById('productSearch');
    var hint   = document.getElementById('pickPrice');
    if (!search || !hint) return;
    search.addEventListener('input', function () {
        var info = PRODUCT_INFO[search.value.trim()];
        hint.textContent = info ? priceHint(info) : '';
    });
})();

// Existing lines get their hint on load, so an invoice opened for editing
// shows both prices without having to retype anything.
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('#lineBody .line-row').forEach(function (row) {
        var desc = row.querySelector('.line-desc');
        if (desc) showRateHint(row, PRODUCT_INFO[desc.value.trim()]);
    });
});

function addPickedProduct() {
    var search = document.getElementById('productSearch');
    var qtyEl  = document.getElementById('productQty');
    var label  = (search.value || '').trim();
    if (!label) { search.focus(); return; }

    var info = PRODUCT_INFO[label];
    if (info === undefined) {
        cbAlert('"' + label + '" is not on the menu. You can still type a free-text line straight into the table below.', {title:'Not a listed product'});
        return;
    }

    addLine();
    var row = document.querySelector('#lineBody .line-row:last-child');
    row.querySelector('[name="item_description[]"]').value = label;
    row.querySelector('.line-rate').value = info.p.toFixed(2);
    row.querySelector('.line-qty').value  = parseFloat(qtyEl.value) || 1;
    showRateHint(row, info);

    search.value = ''; qtyEl.value = 1;
    document.getElementById('pickPrice').textContent = '';
    search.focus();
    recalc();
}

// Typing (or picking) a known product in a line's Description fills that
// line's rate. Delegated, so rows added later are covered too. The rate is
// only overwritten when it is still empty or zero — never clobber a price
// someone has deliberately edited.
document.addEventListener('change', function (e) {
    if (!e.target.classList || !e.target.classList.contains('line-desc')) return;

    const row  = e.target.closest('.line-row');
    const info = PRODUCT_INFO[e.target.value.trim()];

    // Unknown text is a legitimate free-text line — just clear the stale hint.
    showRateHint(row, info);
    if (info === undefined) return;

    const rate = row && row.querySelector('.line-rate');
    if (!rate) return;

    const current = parseFloat(rate.value) || 0;
    if (current === 0) {
        rate.value = info.p.toFixed(2);
        const qty = row.querySelector('.line-qty');
        if (qty && !(parseFloat(qty.value) > 0)) qty.value = 1;
        recalc();
    }
});

// Fill the bill-to block from a chosen trade account.
(function () {
    var picker = document.getElementById('tradePicker');
    if (!picker) return;
    picker.addEventListener('change', function () {
        var o = picker.selectedOptions[0];
        if (!o || !o.value) return;
        var set = function (id, v) { var el = document.getElementById(id); if (el) el.value = v || ''; };
        set('toName',    o.dataset.name);
        set('toAddress', o.dataset.address);
        set('toEmail',   o.dataset.email);
        set('toPhone',   o.dataset.phone);
        set('toVat',     o.dataset.vat);
    });
})();

function recalc() {
    let subtotal = 0;
    document.querySelectorAll('#lineBody .line-row').forEach(row => {
        const rate = parseFloat(row.querySelector('.line-rate').value) || 0;
        const qty  = parseFloat(row.querySelector('.line-qty').value)  || 0;
        const amt  = Math.round(rate * qty * 100) / 100;
        row.querySelector('.line-amount').textContent = money(amt);
        subtotal += amt;
    });
    subtotal = Math.round(subtotal * 100) / 100;

    // Mirrors recalcInvoice() in invoice.php: a percentage discount is taken
    // off the current subtotal, a fixed one is the amount itself.
    const dType = (document.getElementById('fDiscountType') || {}).value || 'fixed';
    const dVal  = parseFloat(document.getElementById('fDiscount').value) || 0;
    let discount = (dType === 'percent')
        ? Math.round(subtotal * (dVal / 100) * 100) / 100
        : dVal;

    const hint = document.getElementById('discountHint');
    if (hint) {
        hint.textContent = (dType === 'percent')
            ? dVal + '% of ' + money(subtotal) + ' = ' + money(Math.min(discount, subtotal))
            : 'Fixed amount off the subtotal.';
    }

    const delivery = parseFloat(document.getElementById('fDelivery').value) || 0;
    const vatPct   = parseFloat(document.getElementById('fVatRate').value)  || 0;

    if (discount > subtotal) discount = subtotal;
    const base  = Math.max(0, Math.round((subtotal - discount + delivery) * 100) / 100);
    const vat   = Math.round(base * (vatPct / 100) * 100) / 100;
    const total = Math.round((base + vat) * 100) / 100;

    document.getElementById('tSubtotal').textContent = money(subtotal);
    document.getElementById('tDiscount').textContent = '−' + money(discount);
    document.getElementById('tDelivery').textContent = money(delivery);
    document.getElementById('tVat').textContent      = money(vat);
    document.getElementById('tTotal').textContent    = money(total);

    // Commission tracks the goods, never the VAT — the same basis the server
    // uses in invoiceCommission(), so the figure shown here is the figure that
    // gets stored.
    const commEl = document.getElementById('tCommission');
    if (commEl) {
        const pct   = parseFloat((document.getElementById('fCommission') || {}).value) || 0;
        const exVat = Math.max(0, Math.round((total - vat) * 100) / 100);
        commEl.textContent = money(Math.round(exVat * (pct / 100) * 100) / 100);
        const basis = document.getElementById('commissionBasis');
        if (basis) {
            basis.textContent = pct > 0
                ? pct + '% of ' + money(exVat) + ' ex-VAT'
                : money(exVat) + ' ex-VAT';
        }
    }
}

function addLine() {
    const tbody = document.getElementById('lineBody');
    const tr = document.createElement('tr');
    tr.className = 'line-row';
    tr.innerHTML =
        '<td><input type="text"   name="item_description[]" class="form-control line-desc" list="productList" autocomplete="off"></td>' +
        '<td><input type="number" name="item_rate[]" class="form-control line-rate" step="0.01" min="0" value="0.00"><small class="cbie-rate-hint"></small></td>' +
        '<td><input type="text"   name="item_rate_note[]" class="form-control" placeholder="e.g. don\'t have 1L Tub"></td>' +
        '<td><input type="number" name="item_qty[]" class="form-control line-qty" step="0.001" min="0" value="1"></td>' +
        '<td><input type="text"   name="item_qty_unit[]" class="form-control" placeholder="Litre"></td>' +
        '<td class="amt line-amount">£0.00</td>' +
        '<td class="cbie-line-actions"><button type="button" class="btn-danger cbie-btn-xs" onclick="removeLine(this)"><i class="fa-solid fa-xmark"></i></button></td>';
    tbody.appendChild(tr);
    tr.querySelector('input').focus();
    recalc();
}

function removeLine(btn) {
    btn.closest('tr').remove();
    recalc();
}

document.addEventListener('input', e => {
    if (e.target.closest('#lineBody') || ['fDiscount','fDelivery','fVatRate'].includes(e.target.id)) recalc();
});
// The commission and discount-type pickers are <select>s, which fire change
// rather than input — without this the figure would lag a step behind.
document.addEventListener('change', e => {
    if (['fCommission','fDiscountType'].includes(e.target.id)) recalc();
});
recalc();

// ── Due date follows the issue date ─────────────────────────
// Three weeks is the house term, so moving the issue date moves the due date
// with it. It stops doing that the moment someone picks their own date —
// tracked with a flag rather than by comparing values, so deliberately
// choosing the same date the suggestion would have produced still counts as
// a manual choice.
(function () {
    var issue = document.querySelector('[name="issue_date"]');
    var due   = document.getElementById('fDueDate');
    if (!issue || !due) return;

    var manual = false;
    due.addEventListener('change', function () { manual = true; });

    function threeWeeksAfter(dateStr) {
        var d = new Date(dateStr + 'T00:00:00');
        if (isNaN(d)) return '';
        d.setDate(d.getDate() + 21);
        return d.getFullYear() + '-' +
               String(d.getMonth() + 1).padStart(2, '0') + '-' +
               String(d.getDate()).padStart(2, '0');
    }

    issue.addEventListener('change', function () {
        if (manual && due.value !== '') return;
        var next = threeWeeksAfter(issue.value);
        if (next) due.value = next;
    });
})();

// Record that an invoice was shared, without navigating.
//
// The WhatsApp button is a real link so one click opens the chat. A form
// submit would redirect this page and cancel the window that click just
// opened — which is why the old version needed two clicks and looked broken.
// keepalive lets the request finish as the browser switches away.
function cbMarkInvoiceShared(invoiceId) {
    try {
        fetch('handlers/invoice_handler.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=mark_shared&invoice_id=' + encodeURIComponent(invoiceId),
            keepalive: true,
        }).catch(function () {});
    } catch (e) { /* sharing must not fail because recording it did */ }
}
</script>
</div><!-- /admin-shell -->
</body>
</html>
