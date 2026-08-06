<?php
// ============================================================
//  Creamy Bite – Refunds report
//
//  Every refund issued against an order, in one place — card, cash and bank
//  alike — so the shop can see at a glance how much money has gone back out
//  and how, without hunting through individual orders.
//
//  Keyed on order_refunds.created_at (when the refund was GIVEN), not the
//  order date — a refund can land weeks after the sale, and reporting it in
//  the sale's period would quietly restate old figures. This matches
//  acctRefundTotals(), which feeds the dashboard's "Refunded this period"
//  card and the VAT return.
//
//  How a refund was made is read from the record, not guessed: a non-empty
//  stripe_refund_id means Stripe actually moved the money, so it is a card
//  refund; otherwise the order's payment_status at the time tells us whether
//  it was cash or a bank transfer that a human already handed back.
// ============================================================
require_once __DIR__ . '/_layout.php';

$period = acctPeriodFor($pdo);
$from   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : $period['from'];
$to     = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '')   ? $_GET['to']   : $period['to'];

// ── One query for everything: the refund row joined to the order it sits
//    on, so the report shows a human who and what was refunded. ──
$rows = [];
try {
    $st = $pdo->prepare(
        "SELECT r.id, r.order_id, r.amount, r.reason, r.stripe_refund_id, r.created_by, r.created_at,
                o.order_code, o.customer_name, o.payment_status, o.payment_method, o.status AS order_status
           FROM order_refunds r
           JOIN orders o ON o.id = r.order_id
          WHERE DATE(r.created_at) BETWEEN :f AND :t
          ORDER BY r.created_at DESC, r.id DESC"
    );
    $st->execute(['f' => $from, 't' => $to]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // order_refunds may be missing on a pre-migration server — show the
    // empty state rather than fataling the page.
    $rows = [];
}

$totRefunded = array_sum(array_map(static fn($r) => (float)$r['amount'], $rows));
$totCard     = array_sum(array_map(
    static fn($r) => trim((string)($r['stripe_refund_id'] ?? '')) !== '' ? (float)$r['amount'] : 0.0, $rows
));
$totManual   = round($totRefunded - $totCard, 2);

/** How a single refund was paid back: card, cash or bank transfer. */
function refundKind(array $r): array
{
    if (trim((string)($r['stripe_refund_id'] ?? '')) !== '') {
        return ['card', 'Card (Stripe)', 'is-ok'];
    }
    $ps = $r['payment_status'] ?? '';
    if ($ps === 'Bank') {
        return ['bank', 'Bank transfer', 'is-warn'];
    }
    if ($ps === 'Cash') {
        return ['cash', 'Cash', 'is-warn'];
    }
    // No Stripe ref and the order no longer says Cash/Bank — say what the
    // order's own method field records rather than inventing a kind.
    $pm = $r['payment_method'] ?? '';
    if (in_array($pm, ['online', 'card', 'stripe'], true)) {
        return ['card', 'Card (Stripe)', 'is-ok'];
    }
    return ['other', $pm !== '' ? ucfirst($pm) : 'Other', ''];
}

acctPageStart('refunds', 'Refunds', acctPeriodLabel(['from' => $from, 'to' => $to]));
?>

<div class="cbac-cards">
    <div class="cbac-card">
        <span class="cbac-card-label">Refunded this period</span>
        <span class="cbac-card-value"><?= acctMoney($totRefunded) ?></span>
        <span class="cbac-card-note"><?= count($rows) ?> refund<?= count($rows) === 1 ? '' : 's' ?> issued</span>
    </div>
    <div class="cbac-card">
        <span class="cbac-card-label">Via Stripe (card)</span>
        <span class="cbac-card-value"><?= acctMoney($totCard) ?></span>
        <span class="cbac-card-note">money actually moved by Stripe</span>
    </div>
    <div class="cbac-card">
        <span class="cbac-card-label">Cash &amp; bank</span>
        <span class="cbac-card-value"><?= acctMoney($totManual) ?></span>
        <span class="cbac-card-note">handed back by a human — recorded here</span>
    </div>
</div>

<div class="cbac-panel">
    <form method="get" class="cbac-filter-bar cbac-noprint">
        <div class="form-group"><label class="form-label" for="ff">From</label>
            <input type="date" id="ff" name="from" class="form-control" value="<?= htmlspecialchars($from) ?>"></div>
        <div class="form-group"><label class="form-label" for="tt">To</label>
            <input type="date" id="tt" name="to" class="form-control" value="<?= htmlspecialchars($to) ?>"></div>
        <button type="submit" class="btn-sm"><i class="fa-solid fa-filter"></i> Filter</button>
        <span class="cbac-spacer"></span>
        <a class="btn-sm" href="export.php?type=refunds&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>"><i class="fa-solid fa-file-csv"></i> CSV</a>
        <button type="button" class="btn-sm" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
    </form>

    <table class="cbac-table">
        <thead>
            <tr><th>Refund date</th><th>Order</th><th>Customer</th><th>How refunded</th>
                <th class="r">Amount</th><th>Reason</th><th>Stripe reference</th><th>Staff</th></tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r):
            [$kind, $kindLabel, $kindClass] = refundKind($r);
        ?>
            <tr>
                <td><?= htmlspecialchars(date('j M Y H:i', strtotime($r['created_at']))) ?></td>
                <td>
                    <a href="../index.php#row-<?= (int)$r['order_id'] ?>"><?= htmlspecialchars($r['order_code'] ?: '—') ?></a>
                    <?php if (($r['order_status'] ?? '') === 'Cancelled'): ?>
                    <small class="cbac-blocked">cancelled</small>
                    <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($r['customer_name'] ?: '—') ?></td>
                <td><span class="cbac-badge <?= $kindClass ?>"><?= htmlspecialchars($kindLabel) ?></span></td>
                <td class="r"><?= acctMoney((float)$r['amount']) ?></td>
                <td><?= htmlspecialchars($r['reason'] ?: '—') ?></td>
                <td>
                    <?php if (trim((string)$r['stripe_refund_id']) !== ''): ?>
                    <a href="https://dashboard.stripe.com/refunds/<?= urlencode($r['stripe_refund_id']) ?>"
                       target="_blank" rel="noopener"><?= htmlspecialchars($r['stripe_refund_id']) ?></a>
                    <?php else: ?>—<?php endif; ?>
                </td>
                <td><?= htmlspecialchars($r['created_by'] ?: '—') ?></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?>
            <tr><td colspan="8" class="cbac-empty">No refunds in this period.</td></tr>
        <?php endif; ?>
        </tbody>
        <?php if ($rows): ?>
        <tfoot>
            <tr class="is-total">
                <td colspan="4">Total (<?= count($rows) ?>)</td>
                <td class="r"><?= acctMoney($totRefunded) ?></td>
                <td colspan="3">
                    <?= acctMoney($totCard) ?> via Stripe
                    <?= $totManual > 0 ? ' · ' . acctMoney($totManual) . ' cash/bank' : '' ?>
                </td>
            </tr>
        </tfoot>
        <?php endif; ?>
    </table>
</div>

<div class="cbac-panel cbac-panel-note cbac-noprint">
    <h2 class="cbac-panel-title">What this is, and isn't</h2>
    <p>
        A card refund is a real Stripe transaction — the reference links straight to it in your
        Stripe dashboard, and the money was actually returned to the customer's card. Cash and
        bank refunds have no rail this app controls: they are records of money a human already
        handed back, so the shop can see them on the order and here.
    </p>
    <p>
        Refunds are reported in the period they were issued, not the period the order was placed —
        the same rule the VAT return and the dashboard's refunded card follow, so this page can
        never disagree with either.
    </p>
</div>

<?php acctPageEnd(); ?>
