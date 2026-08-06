<?php
// ============================================================
//  Creamy Bite – Accounting CSV export
//
//  One handler for every CSV this module offers, rather than a separate file
//  per report — they all share the same header/BOM/escape setup, and a
//  second file drifting out of sync with the first is exactly how you get a
//  spreadsheet that opens fine in one export and corrupted in another.
//
//  ?type=vat_return|expenses|purchases|profit_loss|ledger, plus a period —
//  vat_return uses ?on= (matches vat_return.php's own period picker),
//  everything else uses ?from=&to=.
// ============================================================
require_once __DIR__ . '/_layout.php';

/** fputcsv with the escape character pinned — see admin/reports.php for why. */
function cbCsv($out, array $fields): void
{
    fputcsv($out, $fields, ',', '"', '');
}

$type = $_GET['type'] ?? '';
$allowedTypes = ['vat_return', 'expenses', 'purchases', 'refunds', 'profit_loss', 'ledger'];
if (!in_array($type, $allowedTypes, true)) {
    http_response_code(400);
    exit('Unknown export type.');
}

$period = acctPeriodFor($pdo);
if ($type === 'vat_return') {
    $onDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['on'] ?? '') ? $_GET['on'] : date('Y-m-d');
    $p      = acctPeriodFor($pdo, $onDate);
} else {
    $p = [
        'from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : $period['from'],
        'to'   => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to']   ?? '') ? $_GET['to']   : $period['to'],
    ];
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="creamybite_' . $type . '_' . $p['from'] . '_to_' . $p['to'] . '.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");   // BOM so Excel opens £ correctly

switch ($type) {

    // ── VAT return: the 9 boxes, then the workings behind each ──
    case 'vat_return': {
        // A submitted period is frozen — export what was actually filed, not
        // a fresh recompute that later edits could have since changed.
        $filed = null;
        try {
            $st = $pdo->prepare("SELECT * FROM vat_returns WHERE period_from = ? AND period_to = ?");
            $st->execute([$p['from'], $p['to']]);
            $filed = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (PDOException $e) { }

        $computed = acctComputeReturn($pdo, $p['from'], $p['to']);
        $labels   = acctBoxLabels();

        if ($filed && $filed['status'] === 'submitted') {
            $boxes = [];
            for ($i = 1; $i <= 9; $i++) { $boxes[$i] = (float)$filed['box' . $i]; }
            cbCsv($out, ['VAT Return', acctPeriodLabel($p), 'SUBMITTED ' . $filed['submitted_at'] . ' by ' . $filed['submitted_by']]);
        } else {
            $boxes = $computed['boxes'];
            cbCsv($out, ['VAT Return', acctPeriodLabel($p), 'Draft — not yet submitted']);
        }

        cbCsv($out, []);
        cbCsv($out, ['Box', 'Description', 'Amount (£)']);
        foreach ($labels as $n => $label) {
            cbCsv($out, [$n, $label, number_format($boxes[$n], 2)]);
        }

        cbCsv($out, []);
        cbCsv($out, ['=== WORKINGS ===']);
        foreach ($computed['workings'] as $box => $lines) {
            cbCsv($out, ['Box ' . $box]);
            foreach ($lines as [$what, $amt]) {
                cbCsv($out, ['', $what, number_format((float)$amt, 2)]);
            }
        }
        break;
    }

    // ── Expenses / Purchases: same columns, and same category filter, as
    //    the on-screen table — the export should match what was on screen. ──
    case 'expenses': {
        $catFilt = trim($_GET['category'] ?? '');
        $sql = "SELECT * FROM expenses WHERE expense_date BETWEEN :f AND :t";
        $args = ['f' => $p['from'], 't' => $p['to']];
        if ($catFilt !== '') { $sql .= " AND category = :c"; $args['c'] = $catFilt; }
        $sql .= " ORDER BY expense_date, id";
        $st  = $pdo->prepare($sql);
        $st->execute($args);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        cbCsv($out, ['Date', 'Supplier', 'Category', 'Description', 'Net (£)', 'VAT (£)', 'Gross (£)', 'Reclaimable', 'Paid by', 'Reference']);
        $totNet = $totVat = 0.0;
        foreach ($rows as $r) {
            $totNet += (float)$r['net']; $totVat += (float)$r['vat_amount'];
            cbCsv($out, [
                $r['expense_date'], $r['supplier_name'], $r['category'], $r['description'],
                number_format((float)$r['net'], 2), number_format((float)$r['vat_amount'], 2),
                number_format((float)$r['net'] + (float)$r['vat_amount'], 2),
                $r['recoverable'] ? 'Yes' : 'No', $r['payment_method'], $r['reference'],
            ]);
        }
        cbCsv($out, []);
        cbCsv($out, ['TOTAL', '', '', '', number_format($totNet, 2), number_format($totVat, 2), number_format($totNet + $totVat, 2)]);
        break;
    }

    case 'purchases': {
        $catFilt = trim($_GET['category'] ?? '');
        $sql = "SELECT p.*, s.name AS supplier_name FROM purchase_invoices p
                LEFT JOIN suppliers s ON s.id = p.supplier_id
                WHERE p.invoice_date BETWEEN :f AND :t";
        $args = ['f' => $p['from'], 't' => $p['to']];
        if ($catFilt !== '') { $sql .= " AND p.category = :c"; $args['c'] = $catFilt; }
        $sql .= " ORDER BY p.invoice_date, p.id";
        $st  = $pdo->prepare($sql);
        $st->execute($args);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        cbCsv($out, ['Date', 'Supplier', 'Invoice #', 'Category', 'Net (£)', 'VAT (£)', 'Gross (£)', 'Reclaimable', 'Paid (£)', 'Owing (£)']);
        $totNet = $totVat = $totOwing = 0.0;
        foreach ($rows as $r) {
            $gross = (float)$r['net'] + (float)$r['vat_amount'];
            $owing = max(0, round($gross - (float)$r['paid_amount'], 2));
            $totNet += (float)$r['net']; $totVat += (float)$r['vat_amount']; $totOwing += $owing;
            cbCsv($out, [
                $r['invoice_date'], $r['supplier_name'], $r['invoice_number'], $r['category'],
                number_format((float)$r['net'], 2), number_format((float)$r['vat_amount'], 2),
                number_format($gross, 2), $r['recoverable'] ? 'Yes' : 'No',
                number_format((float)$r['paid_amount'], 2), number_format($owing, 2),
            ]);
        }
        cbCsv($out, []);
        cbCsv($out, ['TOTAL', '', '', '', number_format($totNet, 2), number_format($totVat, 2), number_format($totNet + $totVat, 2), '', '', number_format($totOwing, 2)]);
        break;
    }

    // ── Refunds: every card/cash/bank refund, with its Stripe ref ──
    case 'refunds': {
        $st = $pdo->prepare(
            "SELECT r.amount, r.reason, r.stripe_refund_id, r.created_by, r.created_at,
                    o.order_code, o.customer_name, o.payment_status, o.payment_method, o.status AS order_status
               FROM order_refunds r
               JOIN orders o ON o.id = r.order_id
              WHERE DATE(r.created_at) BETWEEN :f AND :t
              ORDER BY r.created_at DESC, r.id DESC"
        );
        $st->execute(['f' => $p['from'], 't' => $p['to']]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        cbCsv($out, ['Refund date', 'Order', 'Customer', 'How refunded', 'Amount (£)', 'Reason', 'Stripe reference', 'Staff']);
        $tot = 0.0;
        foreach ($rows as $r) {
            $kind = trim((string)$r['stripe_refund_id']) !== '' ? 'Card (Stripe)'
                  : ($r['payment_status'] === 'Bank' ? 'Bank transfer' : ($r['payment_status'] === 'Cash' ? 'Cash' : $r['payment_method']));
            $tot += (float)$r['amount'];
            cbCsv($out, [
                $r['created_at'], $r['order_code'], $r['customer_name'], $kind,
                number_format((float)$r['amount'], 2), $r['reason'], $r['stripe_refund_id'], $r['created_by'],
            ]);
        }
        cbCsv($out, []);
        cbCsv($out, ['TOTAL', '', '', '', number_format($tot, 2)]);
        break;
    }

    // ── Profit & Loss statement ──
    case 'profit_loss': {
        $pl = acctProfitLoss($pdo, $p['from'], $p['to']);
        cbCsv($out, ['Profit & Loss', acctPeriodLabel($p)]);
        cbCsv($out, []);
        cbCsv($out, ['Revenue (excl. VAT, net of refunds)', number_format($pl['revenue'], 2)]);
        cbCsv($out, ['Cost of Sales (Stock)', '-' . number_format($pl['cost_of_sales'], 2)]);
        cbCsv($out, ['Gross Profit', number_format($pl['gross_profit'], 2), number_format($pl['gross_margin'], 1) . '% margin']);
        cbCsv($out, []);
        cbCsv($out, ['Operating Expenses']);
        foreach ($pl['opex'] as $group => $amt) {
            cbCsv($out, [$group, '-' . number_format($amt, 2)]);
        }
        cbCsv($out, ['Total Operating Expenses', '-' . number_format($pl['total_opex'], 2)]);
        cbCsv($out, []);
        cbCsv($out, ['Net Profit', number_format($pl['net_profit'], 2), number_format($pl['net_margin'], 1) . '% margin']);
        if ($pl['refunded'] > 0) {
            cbCsv($out, []);
            cbCsv($out, ['Refunds issued this period (already deducted from Revenue above)', number_format($pl['refunded'], 2)]);
        }
        break;
    }

    // ── Ledger: the trial balance ──
    case 'ledger': {
        $rows = acctTrialBalance($pdo, $p['from'], $p['to']);
        cbCsv($out, ['Trial Balance', acctPeriodLabel($p)]);
        cbCsv($out, []);
        cbCsv($out, ['Code', 'Account', 'Type', 'Debit (£)', 'Credit (£)']);
        $totDebit = $totCredit = 0.0;
        foreach ($rows as $r) {
            $totDebit += (float)$r['debit']; $totCredit += (float)$r['credit'];
            cbCsv($out, [$r['code'], $r['name'], ucfirst($r['type']), number_format((float)$r['debit'], 2), number_format((float)$r['credit'], 2)]);
        }
        cbCsv($out, []);
        cbCsv($out, ['TOTAL', '', '', number_format($totDebit, 2), number_format($totCredit, 2)]);
        break;
    }
}

fclose($out);
