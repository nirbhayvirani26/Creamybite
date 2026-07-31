<?php
// ============================================================
//  Creamy Bite – Invoice (print view)
//  URL: /admin/invoice_view.php?id=12
//
//  Layout follows the shop's real invoice (INV0225):
//    letterhead left, INVOICE / number / date / due / balance right,
//    BILL TO block, then DESCRIPTION | RATE | QTY | AMOUNT,
//    payment instructions bottom-left, TOTAL and BALANCE DUE bottom-right.
// ============================================================
session_start();
if (empty($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/invoice.php';

$invoiceId = (int)($_GET['id'] ?? 0);
$inv = $invoiceId > 0 ? loadInvoice($pdo, $invoiceId) : null;

if (!$inv) {
    http_response_code(404);
    echo '<p class="nf-body">Invoice not found. <a href="index.php?tab=invoices">Back to invoices</a></p>';
    exit;
}

[$stLabel, $stBg, $stFg, $stBd] = invoiceStatusLabel($inv['status']);
$symbol  = $inv['currency'] === 'GBP' ? '£' : '';
$balance = (float)$inv['balance_due'];

/** Print a multi-line stored field as HTML. */
function invLines(string $text): string
{
    return nl2br(htmlspecialchars(trim($text)));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Invoice <?= htmlspecialchars($inv['invoice_number']) ?> – <?= SHOP_NAME ?></title>
<style>
    *{ box-sizing:border-box; }
    body{
        margin:0; padding:28px 16px; background:#eef0f3;
        font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Helvetica,Arial,sans-serif;
        color:#1f2937; font-size:14px;
    }
    .bar{ max-width:820px; margin:0 auto 16px; display:flex; gap:10px; flex-wrap:wrap; }
    .btn{ border:none; border-radius:8px; padding:10px 18px; font-size:13px; font-weight:700;
          cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; gap:7px; }
    .btn-primary{ background:#5C1D24; color:#fff; }
    .btn-plain{ background:#fff; color:#5C1D24; border:1px solid #d9dde3; }

    .sheet{ max-width:820px; margin:0 auto; background:#fff; padding:52px 54px;
            box-shadow:0 10px 34px rgba(0,0,0,.09); }

    /* ── letterhead ─────────────────────────────── */
    .top{ display:flex; justify-content:space-between; gap:30px; flex-wrap:wrap; }
    .brand{ font-size:27px; font-weight:800; letter-spacing:-.02em; color:#5C1D24; margin:0 0 10px; }
    .brand-logo{ max-height:62px; max-width:240px; width:auto; display:block; margin:0 0 12px;
                 -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    .from{ font-size:12.5px; line-height:1.65; color:#4b5563; }
    .doc{ text-align:right; min-width:230px; }
    .doc h1{ font-size:34px; letter-spacing:.06em; margin:0 0 2px; color:#111827; font-weight:800; }
    .doc .num{ font-size:14px; color:#6b7280; letter-spacing:.04em; margin-bottom:16px; }

    .meta{ width:100%; border-collapse:collapse; font-size:12.5px; }
    .meta td{ padding:3px 0; }
    .meta .k{ text-align:left;  color:#6b7280; text-transform:uppercase;
              letter-spacing:.09em; font-size:10.5px; font-weight:800; }
    .meta .v{ text-align:right; font-weight:600; white-space:nowrap; }
    .balance-row .k, .balance-row .v{ padding-top:9px; font-size:13px; }
    .balance-row .v{ font-size:16px; font-weight:800; color:#5C1D24; }

    .pill{ display:inline-block; padding:3px 11px; border-radius:20px; font-size:10.5px;
           font-weight:800; letter-spacing:.06em; margin-top:8px; }

    /* ── bill to ────────────────────────────────── */
    .billto{ margin:34px 0 22px; }
    .cap{ font-size:10.5px; font-weight:800; letter-spacing:.11em;
          text-transform:uppercase; color:#9ca3af; margin-bottom:6px; }
    .billto .who{ font-size:15px; font-weight:700; color:#111827; }
    .billto .addr{ font-size:12.5px; line-height:1.65; color:#4b5563; margin-top:3px; }

    /* ── items ──────────────────────────────────── */
    table.items{ width:100%; border-collapse:collapse; margin-top:6px; }
    table.items thead th{
        background:#5C1D24; color:#fff; font-size:10.5px; font-weight:800;
        letter-spacing:.1em; text-transform:uppercase; padding:10px 12px; text-align:left;
    }
    table.items thead th.r{ text-align:right; }
    table.items tbody td{ padding:11px 12px; border-bottom:1px solid #eef0f3; font-size:13px; vertical-align:top; }
    table.items tbody td.r{ text-align:right; white-space:nowrap; }
    .ratenote{ color:#6b7280; font-size:11.5px; }

    /* ── footer ─────────────────────────────────── */
    .foot{ display:flex; justify-content:space-between; gap:34px; margin-top:26px; flex-wrap:wrap; }
    .pay{ flex:1 1 300px; font-size:12.5px; line-height:1.7; color:#4b5563; }
    .totals{ flex:0 0 290px; }
    .totals table{ width:100%; border-collapse:collapse; }
    .totals td{ padding:6px 0; font-size:13px; }
    .totals td.r{ text-align:right; white-space:nowrap; }
    .totals .grand td{ border-top:2px solid #5C1D24; padding-top:11px; font-size:16px; font-weight:800; color:#5C1D24; }
    .totals .due td{ background:#faf6f2; font-size:15px; font-weight:800; color:#5C1D24; padding:10px 8px; }

    .notes{ margin-top:26px; padding-top:16px; border-top:1px solid #eef0f3;
            font-size:12.5px; color:#4b5563; line-height:1.65; }

    @media print{
        body{ background:#fff; padding:0; }
        .sheet{ box-shadow:none; padding:0; max-width:none; }
        .bar{ display:none; }
        table.items thead th{ -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    }

    /* extracted from inline style attributes */
    .col-desc{ width:52% }
    .col-num{ width:16% }
    .pos{ color:#047857 }
    .empty-cell{ text-align:center; color:#9ca3af; padding:26px }
    .cap-gap{ margin:18px 0 8px }
    .cap-tight{ margin-bottom:8px }
    .nf-body{ font-family:sans-serif;padding:40px }
</style>
</head>
<body>

<div class="bar">
    <a href="index.php?tab=invoices" class="btn btn-plain">&larr; Back to Invoices</a>
    <a href="invoice_edit.php?id=<?= (int)$inv['id'] ?>" class="btn btn-plain">Edit</a>
    <button class="btn btn-primary" onclick="window.print()">Print / Save as PDF</button>
</div>

<div class="sheet">

    <div class="top">
        <div>
            <?php
            // Logo, with the business name as the fallback if the file is
            // missing. print-color-adjust keeps it from dropping out when the
            // browser strips backgrounds for printing.
            $logoPath = __DIR__ . '/../assets/images/logo.png';
            if (is_file($logoPath)):
            ?>
            <img src="../assets/images/logo.png" alt="<?= htmlspecialchars($inv['from_name']) ?>" class="brand-logo">
            <?php else: ?>
            <div class="brand"><?= htmlspecialchars($inv['from_name']) ?></div>
            <?php endif; ?>
            <div class="from">
                <?= invLines($inv['from_address']) ?><br>
                <?php if ($inv['from_phone'] !== ''): ?><?= htmlspecialchars($inv['from_phone']) ?><br><?php endif; ?>
                <?php if ($inv['from_website'] !== ''): ?><?= htmlspecialchars($inv['from_website']) ?><br><?php endif; ?>
                <?php if ($inv['from_email'] !== ''): ?><?= htmlspecialchars($inv['from_email']) ?><?php endif; ?>
            </div>
        </div>

        <div class="doc">
            <h1>INVOICE</h1>
            <div class="num"><?= htmlspecialchars($inv['invoice_number']) ?></div>

            <table class="meta">
                <tr>
                    <td class="k">Date</td>
                    <td class="v"><?= date('d/m/Y', strtotime($inv['issue_date'])) ?></td>
                </tr>
                <tr>
                    <td class="k">Due</td>
                    <td class="v">
                        <?= $inv['due_date']
                              ? date('d/m/Y', strtotime($inv['due_date']))
                              : htmlspecialchars($inv['due_terms']) ?>
                    </td>
                </tr>
                <tr class="balance-row">
                    <td class="k">Balance Due</td>
                    <td class="v"><?= htmlspecialchars($inv['currency']) ?> <?= $symbol ?><?= number_format($balance, 2) ?></td>
                </tr>
            </table>

            <span class="pill" style="background:<?= $stBg ?>; color:<?= $stFg ?>; border:1px solid <?= $stBd ?>;">
                <?= $stLabel ?>
            </span>
        </div>
    </div>

    <div class="billto">
        <div class="cap">Bill To</div>
        <div class="who"><?= htmlspecialchars($inv['to_name']) ?></div>
        <div class="addr">
            <?= invLines($inv['to_address']) ?>
            <?php if ($inv['to_phone'] !== ''): ?><br><?= htmlspecialchars($inv['to_phone']) ?><?php endif; ?>
            <?php if ($inv['to_email'] !== ''): ?><br><?= htmlspecialchars($inv['to_email']) ?><?php endif; ?>
            <?php if ($inv['to_vat_number'] !== ''): ?><br>VAT: <?= htmlspecialchars($inv['to_vat_number']) ?><?php endif; ?>
        </div>
    </div>

    <table class="items">
        <thead>
            <tr>
                <th class="col-desc">Description</th>
                <th class="r col-num" >Rate</th>
                <th class="r col-num" >Qty</th>
                <th class="r col-num" >Amount</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($inv['items'])): ?>
            <tr><td colspan="4" class="empty-cell">No line items yet.</td></tr>
        <?php else: foreach ($inv['items'] as $it): ?>
            <tr>
                <td><?= htmlspecialchars($it['description']) ?></td>
                <td class="r">
                    <?= $symbol ?><?= number_format((float)$it['rate'], 2) ?>
                    <?php if ($it['rate_note'] !== ''): ?>
                    <span class="ratenote">(<?= htmlspecialchars($it['rate_note']) ?>)</span>
                    <?php endif; ?>
                </td>
                <td class="r"><?= htmlspecialchars(formatInvoiceQty((float)$it['qty'], $it['qty_unit'])) ?></td>
                <td class="r"><?= $symbol ?><?= number_format((float)$it['amount'], 2) ?></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>

    <div class="foot">
        <div class="pay">
            <?php if (trim($inv['payment_instructions']) !== ''): ?>
            <div class="cap cap-tight" >Payment Instructions</div>
            <?= invLines($inv['payment_instructions']) ?>
            <?php endif; ?>

            <?php if (!empty($inv['payments'])): ?>
            <div class="cap cap-gap" >Payments Received</div>
            <?php foreach ($inv['payments'] as $p): ?>
                <?= date('d/m/Y', strtotime($p['paid_on'])) ?> —
                <?= $symbol ?><?= number_format((float)$p['amount'], 2) ?>
                (<?= htmlspecialchars($p['method']) ?><?= $p['reference'] !== '' ? ', ' . htmlspecialchars($p['reference']) : '' ?>)<br>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <div class="totals">
            <table>
                <tr>
                    <td>Subtotal</td>
                    <td class="r"><?= $symbol ?><?= number_format((float)$inv['subtotal'], 2) ?></td>
                </tr>
                <?php if ((float)$inv['discount'] > 0): ?>
                <tr>
                    <td>
                        Discount<?= ($inv['discount_type'] ?? 'fixed') === 'percent'
                            ? ' (' . rtrim(rtrim(number_format((float)$inv['discount_value'], 2), '0'), '.') . '%)'
                            : '' ?>
                    </td>
                    <td class="r pos" >&minus; <?= $symbol ?><?= number_format((float)$inv['discount'], 2) ?></td>
                </tr>
                <?php endif; ?>
                <?php if ((float)$inv['delivery'] > 0): ?>
                <tr>
                    <td>Delivery</td>
                    <td class="r"><?= $symbol ?><?= number_format((float)$inv['delivery'], 2) ?></td>
                </tr>
                <?php endif; ?>
                <?php if ((float)$inv['vat_amount'] > 0): ?>
                <tr>
                    <td>VAT @ <?= rtrim(rtrim(number_format((float)$inv['vat_rate'] * 100, 2), '0'), '.') ?>%</td>
                    <td class="r"><?= $symbol ?><?= number_format((float)$inv['vat_amount'], 2) ?></td>
                </tr>
                <?php endif; ?>
                <tr class="grand">
                    <td>Total</td>
                    <td class="r"><?= $symbol ?><?= number_format((float)$inv['total'], 2) ?></td>
                </tr>
                <?php if ((float)$inv['amount_paid'] > 0): ?>
                <tr>
                    <td>Paid</td>
                    <td class="r pos" >&minus; <?= $symbol ?><?= number_format((float)$inv['amount_paid'], 2) ?></td>
                </tr>
                <?php endif; ?>
                <tr class="due">
                    <td>Balance Due</td>
                    <td class="r"><?= htmlspecialchars($inv['currency']) ?> <?= $symbol ?><?= number_format($balance, 2) ?></td>
                </tr>
            </table>
        </div>
    </div>

    <?php if (trim($inv['notes']) !== ''): ?>
    <div class="notes"><?= invLines($inv['notes']) ?></div>
    <?php endif; ?>

</div>
</body>
</html>
