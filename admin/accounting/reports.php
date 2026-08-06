<?php
// ============================================================
//  Creamy Bite – Profit & Loss
//
//  Revenue less Cost of Sales gives Gross Profit; Gross Profit less
//  Operating Expenses gives Net Profit. Cost of Sales is the "Stock"
//  category group (ingredients, packaging, etc.) — the closest honest
//  substitute for cost of goods sold, since no product in this app carries
//  a cost field to build a true COGS figure from. See acctProfitLoss() in
//  includes/accounting.php for the full reasoning.
//
//  Revenue here is already net of refunds (acctSalesTotals() subtracts them
//  at the source), so a refund reduces this period's profit exactly once,
//  the same way it reduces the VAT return.
// ============================================================
require_once __DIR__ . '/_layout.php';

$period = acctPeriodFor($pdo);
$from   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : $period['from'];
$to     = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '')   ? $_GET['to']   : $period['to'];

$pl = acctProfitLoss($pdo, $from, $to);

acctPageStart('reports', 'Profit & Loss', acctPeriodLabel(['from' => $from, 'to' => $to]));
?>

<div class="cbac-panel">
    <form method="get" class="cbac-filter-bar cbac-noprint">
        <div class="form-group"><label class="form-label" for="ff">From</label>
            <input type="date" id="ff" name="from" class="form-control" value="<?= htmlspecialchars($from) ?>"></div>
        <div class="form-group"><label class="form-label" for="tt">To</label>
            <input type="date" id="tt" name="to" class="form-control" value="<?= htmlspecialchars($to) ?>"></div>
        <button type="submit" class="btn-sm"><i class="fa-solid fa-filter"></i> Update</button>
        <span class="cbac-spacer"></span>
        <a class="btn-sm" href="export.php?type=profit_loss&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>"><i class="fa-solid fa-file-csv"></i> CSV</a>
        <button type="button" class="btn-sm" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
    </form>
</div>

<?php if ($pl['refunded'] > 0): ?>
<div class="cbac-banner is-info">
    <i class="fa-solid fa-circle-info"></i>
    Revenue below is already net of <?= acctMoney($pl['refunded']) ?> refunded this period.
</div>
<?php endif; ?>

<div class="cbac-panel">
    <table class="cbac-table cbac-boxes">
        <tbody>
            <tr class="is-total"><td colspan="2">Revenue</td></tr>
            <tr><td>Sales &amp; invoices, excluding VAT</td><td class="r"><?= acctMoney($pl['revenue']) ?></td></tr>

            <tr class="is-total"><td colspan="2">Cost of Sales</td></tr>
            <tr><td>Stock (ingredients, packaging, etc.)</td><td class="r">−<?= acctMoney($pl['cost_of_sales']) ?></td></tr>

            <tr class="is-total <?= $pl['gross_profit'] >= 0 ? '' : 'cbac-neg' ?>">
                <td>Gross Profit</td>
                <td class="r"><?= acctMoney($pl['gross_profit']) ?> <small>(<?= number_format($pl['gross_margin'], 1) ?>% margin)</small></td>
            </tr>

            <tr class="is-total"><td colspan="2">Operating Expenses</td></tr>
            <?php if (!$pl['opex']): ?>
            <tr><td colspan="2" class="cbac-empty">Nothing recorded in this period.</td></tr>
            <?php else: foreach ($pl['opex'] as $group => $amt): ?>
            <tr><td><?= htmlspecialchars($group) ?></td><td class="r">−<?= acctMoney($amt) ?></td></tr>
            <?php endforeach; endif; ?>
            <tr><td><strong>Total Operating Expenses</strong></td><td class="r">−<?= acctMoney($pl['total_opex']) ?></td></tr>

            <tr class="is-total <?= $pl['net_profit'] >= 0 ? '' : 'cbac-neg' ?>">
                <td>Net Profit</td>
                <td class="r"><?= acctMoney($pl['net_profit']) ?> <small>(<?= number_format($pl['net_margin'], 1) ?>% margin)</small></td>
            </tr>
        </tbody>
    </table>
</div>

<div class="cbac-panel cbac-panel-note cbac-noprint">
    <h2 class="cbac-panel-title">What this is, and isn't</h2>
    <p>
        "Cost of Sales" is everything logged under the Stock category — ingredients, packaging and
        similar — because nothing in this app records what a tub actually costs to make. A true
        cost-of-goods-sold figure would need that tracked per product; this is the closest honest
        substitute available today, not a stand-in for it.
    </p>
    <p>
        This is profit before tax — Corporation Tax or Income Tax are not calculated here.
        Speak to your accountant for that figure.
    </p>
</div>

<?php acctPageEnd(); ?>
