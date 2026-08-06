<?php
// ============================================================
//  Creamy Bite – VAT & Accounting dashboard
//
//  Reads from the sales, invoice, expense and purchase tables; owns none of
//  them. That is the separation the brief asked for: this section is its own
//  module but it does not keep a second copy of the money.
//
//  Charts are drawn with inline SVG rather than a charting library. The site
//  has no build step and no bundler, so a library would mean a CDN script on
//  an admin page — one more thing to break when a CDN is slow, for a bar
//  chart that is a dozen rectangles.
// ============================================================
require_once __DIR__ . '/_layout.php';

$period    = acctPeriodFor($pdo);
$vatOn     = acctVatEnabled($pdo);
$ret       = acctComputeReturn($pdo, $period['from'], $period['to']);
$sales     = $ret['source']['sales'];
$invoices  = $ret['source']['invoices'];
$purchases = $ret['source']['purchases'];

// ── Outstanding money, both directions ──────────────────────
$owedToUs = 0.0;
try {
    $owedToUs = (float)$pdo->query(
        "SELECT COALESCE(SUM(total - amount_paid), 0) FROM invoices
          WHERE status IN ('sent','part_paid') AND status <> 'void'"
    )->fetchColumn();
} catch (PDOException $e) { }

$owedByUs = 0.0;
try {
    $owedByUs = (float)$pdo->query(
        "SELECT COALESCE(SUM(net + vat_amount - paid_amount), 0) FROM purchase_invoices
          WHERE (net + vat_amount) > paid_amount"
    )->fetchColumn();
} catch (PDOException $e) { }

// ── Twelve months of history for the charts ─────────────────
$months = [];
for ($i = 11; $i >= 0; $i--) {
    $m    = date('Y-m-01', strtotime("-{$i} months"));
    $from = $m;
    $to   = date('Y-m-t', strtotime($m));
    $s    = acctSalesTotals($pdo, $from, $to);
    $p    = acctPurchaseTotals($pdo, $from, $to);
    $months[] = [
        'label'     => date('M', strtotime($m)),
        'year'      => date('Y', strtotime($m)),
        'sales'     => $s['net'],
        'vat_out'   => $s['vat'],
        'vat_in'    => $p['vat'],
        'costs'     => $p['net'],
        'profit'    => round($s['net'] - $p['net'], 2),
    ];
}

// ── Expenses by category, this period ───────────────────────
$byCategory = [];
try {
    $st = $pdo->prepare(
        "SELECT category, COALESCE(SUM(net), 0) AS net FROM expenses
          WHERE expense_date BETWEEN :f AND :t GROUP BY category ORDER BY net DESC"
    );
    $st->execute(['f' => $period['from'], 't' => $period['to']]);
    $byCategory = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { }

$grossProfit = round($sales['net'] + $invoices['net'] - $purchases['net'], 2);

/** A simple bar chart as inline SVG. */
function acctBars(array $rows, string $key, string $colour): string
{
    $max = 0.0;
    foreach ($rows as $r) { $max = max($max, (float)$r[$key]); }
    if ($max <= 0) {
        return '<p class="cbac-chart-empty">Nothing recorded in the last 12 months.</p>';
    }
    $w = 100 / max(1, count($rows));
    $out = '<svg viewBox="0 0 100 44" preserveAspectRatio="none" class="cbac-chart" role="img">';
    foreach ($rows as $i => $r) {
        $h = ($max > 0) ? ((float)$r[$key] / $max) * 34 : 0;
        $x = $i * $w;
        $out .= sprintf(
            '<rect x="%.2f" y="%.2f" width="%.2f" height="%.2f" fill="%s" rx="0.6"><title>%s %s: £%s</title></rect>',
            $x + $w * 0.18, 38 - $h, $w * 0.64, max(0.4, $h), $colour,
            htmlspecialchars($r['label']), htmlspecialchars($r['year']), number_format((float)$r[$key], 2)
        );
    }
    // Month initials along the bottom.
    foreach ($rows as $i => $r) {
        $out .= sprintf('<text x="%.2f" y="43" font-size="2.6" text-anchor="middle" fill="#8a7376">%s</text>',
            $i * $w + $w / 2, htmlspecialchars(substr($r['label'], 0, 1)));
    }
    return $out . '</svg>';
}

acctPageStart('index', 'VAT & Accounting',
    'Current period: ' . acctPeriodLabel($period) . ' · return due ' . date('j M Y', strtotime(acctPeriodDue($period))));
?>

<?php if ($vatOn): ?>
<?php
    $due  = acctPeriodDue($period);
    $days = (int)floor((strtotime($due) - strtotime(date('Y-m-d'))) / 86400);
    if ($days <= 30):
?>
<div class="cbac-banner <?= $days < 0 ? 'is-warn' : 'is-info' ?>">
    <i class="fa-solid fa-calendar-day"></i>
    <?php if ($days < 0): ?>
        <strong>The VAT return for <?= htmlspecialchars(acctPeriodLabel($period)) ?> was due
        <?= abs($days) ?> day<?= abs($days) === 1 ? '' : 's' ?> ago.</strong>
    <?php else: ?>
        <strong>VAT return due in <?= $days ?> day<?= $days === 1 ? '' : 's' ?></strong>
        (<?= date('j F Y', strtotime($due)) ?>).
    <?php endif; ?>
    <a href="vat_return.php">Open the return</a>
</div>
<?php endif; ?>
<?php endif; ?>

<!-- ── VAT position ───────────────────────────────────── -->
<h2 class="cbac-section-title">VAT this period</h2>
<div class="cbac-cards">
    <div class="cbac-card">
        <span class="cbac-card-label">VAT collected on sales</span>
        <span class="cbac-card-value"><?= acctMoney($ret['boxes'][1]) ?></span>
        <span class="cbac-card-note">Box 1</span>
    </div>
    <div class="cbac-card">
        <span class="cbac-card-label">VAT paid on purchases</span>
        <span class="cbac-card-value"><?= acctMoney($ret['boxes'][4]) ?></span>
        <span class="cbac-card-note">Box 4 — reclaimable only</span>
    </div>
    <div class="cbac-card <?= $ret['boxes'][5] >= 0 ? 'is-owed' : 'is-refund' ?>">
        <span class="cbac-card-label"><?= $ret['boxes'][5] >= 0 ? 'Net VAT owed to HMRC' : 'VAT refund due to you' ?></span>
        <span class="cbac-card-value"><?= acctMoney(abs($ret['boxes'][5])) ?></span>
        <span class="cbac-card-note">Box 5</span>
    </div>
    <div class="cbac-card">
        <span class="cbac-card-label">Scheme</span>
        <span class="cbac-card-value cbac-card-value-sm">
            <?= htmlspecialchars(ucwords(str_replace('_', ' ', $ret['scheme']))) ?>
        </span>
        <span class="cbac-card-note"><?= $ret['basis'] === 'cash' ? 'counted when paid' : 'counted when invoiced' ?></span>
    </div>
</div>

<!-- ── Trading ────────────────────────────────────────── -->
<h2 class="cbac-section-title">Trading this period</h2>
<div class="cbac-cards">
    <div class="cbac-card">
        <span class="cbac-card-label">Total sales (excl. VAT)</span>
        <span class="cbac-card-value"><?= acctMoney($sales['net'] + $invoices['net']) ?></span>
        <span class="cbac-card-note"><?= $sales['count'] + $invoices['count'] ?> transactions</span>
    </div>
    <div class="cbac-card">
        <span class="cbac-card-label">Purchases &amp; expenses</span>
        <span class="cbac-card-value"><?= acctMoney($purchases['net']) ?></span>
        <span class="cbac-card-note"><?= $purchases['count'] ?> recorded</span>
    </div>
    <div class="cbac-card <?= $grossProfit >= 0 ? '' : 'is-refund' ?>">
        <span class="cbac-card-label">Profit (summary)</span>
        <span class="cbac-card-value"><?= acctMoney($grossProfit) ?></span>
        <span class="cbac-card-note">sales less recorded costs — <a href="reports.php">full P&amp;L</a></span>
    </div>
    <div class="cbac-card">
        <span class="cbac-card-label">Owed to you</span>
        <span class="cbac-card-value"><?= acctMoney($owedToUs) ?></span>
        <span class="cbac-card-note">unpaid customer invoices</span>
    </div>
    <div class="cbac-card">
        <span class="cbac-card-label">Owed by you</span>
        <span class="cbac-card-value"><?= acctMoney($owedByUs) ?></span>
        <span class="cbac-card-note">unpaid supplier bills</span>
    </div>
    <?php if ($sales['refunded'] > 0): ?>
    <div class="cbac-card is-refund">
        <span class="cbac-card-label">Refunded this period</span>
        <span class="cbac-card-value"><?= acctMoney($sales['refunded']) ?></span>
        <span class="cbac-card-note">already subtracted from sales &amp; VAT above</span>
    </div>
    <?php endif; ?>
</div>

<?php if ($purchases['count'] === 0): ?>
<div class="cbac-banner is-info">
    <i class="fa-solid fa-circle-info"></i>
    <strong>No purchases or expenses recorded yet.</strong>
    Until they are, Box 4 is zero and the profit figure is sales only — it will look
    far better than the business really did.
    <a href="expenses.php">Add an expense</a>
</div>
<?php endif; ?>

<!-- ── Charts ─────────────────────────────────────────── -->
<h2 class="cbac-section-title">Last 12 months</h2>
<div class="cbac-chart-grid">
    <div class="cbac-panel">
        <h3 class="cbac-chart-title">Monthly sales (excl. VAT)</h3>
        <?= acctBars($months, 'sales', '#5C1D24') ?>
    </div>
    <div class="cbac-panel">
        <h3 class="cbac-chart-title">VAT collected</h3>
        <?= acctBars($months, 'vat_out', '#C78829') ?>
    </div>
    <div class="cbac-panel">
        <h3 class="cbac-chart-title">VAT reclaimed</h3>
        <?= acctBars($months, 'vat_in', '#1a7a4c') ?>
    </div>
    <div class="cbac-panel">
        <h3 class="cbac-chart-title">Gross profit</h3>
        <?= acctBars($months, 'profit', '#8C3A43') ?>
    </div>
</div>

<div class="cbac-panel">
    <h3 class="cbac-chart-title">Expenses by category, this period</h3>
    <?php if (!$byCategory): ?>
        <p class="cbac-chart-empty">No expenses recorded in this period.</p>
    <?php else: ?>
    <table class="cbac-table">
        <thead><tr><th>Category</th><th class="r">Net</th><th class="cbac-bar-col">Share</th></tr></thead>
        <tbody>
        <?php
        $catTotal = array_sum(array_map(static fn($c) => (float)$c['net'], $byCategory));
        foreach ($byCategory as $c):
            $pct = $catTotal > 0 ? ((float)$c['net'] / $catTotal) * 100 : 0;
        ?>
            <tr>
                <td><?= htmlspecialchars($c['category']) ?></td>
                <td class="r"><?= acctMoney((float)$c['net']) ?></td>
                <td class="cbac-bar-col">
                    <span class="cbac-bar"><span class="cbac-bar-fill" style="width:<?= round($pct) ?>%"></span></span>
                    <span class="cbac-bar-pct"><?= number_format($pct, 1) ?>%</span>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<?php acctPageEnd(); ?>
