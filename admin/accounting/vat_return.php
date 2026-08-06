<?php
// ============================================================
//  Creamy Bite – UK VAT Return (the nine boxes)
//
//  Every box shows the figures that produced it. A number an accountant
//  cannot take apart is no use during an HMRC enquiry, and "the software said
//  so" is not an answer anyone wants to give.
//
//  Adjustments require a reason. HMRC expects a trail for any figure that
//  differs from the accounting records, so the note is mandatory rather than
//  encouraged — the form will not save without one.
//
//  A submitted return is a SNAPSHOT. The boxes are stored as they stood when
//  it was marked submitted, never recomputed on view: editing an old invoice
//  must not silently change a return that has already gone to HMRC.
// ============================================================
require_once __DIR__ . '/_layout.php';

$msg = ''; $err = '';

// Which period are we looking at? Defaults to the current one, but any past
// period can be opened by passing its start date.
$onDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['on'] ?? '') ? $_GET['on'] : date('Y-m-d');
$period = acctPeriodFor($pdo, $onDate);

// Has this period already been filed?
$filed = null;
try {
    $st = $pdo->prepare("SELECT * FROM vat_returns WHERE period_from = ? AND period_to = ?");
    $st->execute([$period['from'], $period['to']]);
    $filed = $st->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (PDOException $e) { }

// ── Actions ─────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $action = $_POST['action'] ?? '';

    if ($action === 'adjust') {
        $box    = (int)($_POST['box'] ?? 0);
        $amount = (float)($_POST['amount'] ?? 0);
        $reason = trim($_POST['reason'] ?? '');

        if ($box < 1 || $box > 9)          { $err = 'Pick a box between 1 and 9.'; }
        elseif ($reason === '')            { $err = 'An adjustment needs a reason — HMRC expects one and so does your accountant.'; }
        elseif (abs($amount) < 0.005)      { $err = 'An adjustment of zero changes nothing.'; }
        elseif ($filed && $filed['status'] === 'submitted') {
            $err = 'This return has been submitted. Adjust the next one instead — a filed return is a record of what was sent.';
        } else {
            // The draft has to exist before an adjustment can attach to it.
            if (!$filed) {
                $pdo->prepare("INSERT INTO vat_returns (period_from, period_to, status) VALUES (?,?,'draft')")
                    ->execute([$period['from'], $period['to']]);
                $st = $pdo->prepare("SELECT * FROM vat_returns WHERE period_from = ? AND period_to = ?");
                $st->execute([$period['from'], $period['to']]);
                $filed = $st->fetch(PDO::FETCH_ASSOC);
            }
            $pdo->prepare(
                "INSERT INTO vat_adjustments (return_id, box, amount, reason, created_by) VALUES (?,?,?,?,?)"
            )->execute([$filed['id'], $box, round($amount, 2), mb_substr($reason, 0, 255), adminStaffName()]);

            acctAudit($pdo, 'vat.adjustment', 'vat_returns', (int)$filed['id'], null,
                json_encode(['box' => $box, 'amount' => $amount, 'reason' => $reason]));
            $msg = 'Adjustment recorded against Box ' . $box . '.';
        }
    }

    if ($action === 'submit') {
        if (!adminIsOwner() && !adminCan('accounting_file')) {
            $err = 'Only the owner or an accountant may mark a return as submitted.';
        } elseif ($filed && $filed['status'] === 'submitted') {
            $err = 'This return is already marked submitted.';
        } else {
            $computed = acctComputeReturn($pdo, $period['from'], $period['to']);
            $boxes    = $computed['boxes'];

            // Fold in any adjustments before freezing the snapshot.
            if ($filed) {
                $adj = $pdo->prepare("SELECT box, SUM(amount) AS total FROM vat_adjustments WHERE return_id = ? GROUP BY box");
                $adj->execute([$filed['id']]);
                foreach ($adj->fetchAll(PDO::FETCH_ASSOC) as $a) {
                    $boxes[(int)$a['box']] = round($boxes[(int)$a['box']] + (float)$a['total'], 2);
                }
                // Box 3 and 5 are derived, so they are recomputed after the
                // adjustments rather than adjusted directly.
                $boxes[3] = round($boxes[1] + $boxes[2], 2);
                $boxes[5] = round($boxes[3] - $boxes[4], 2);
            }

            $sql = "INSERT INTO vat_returns (period_from, period_to, status, box1,box2,box3,box4,box5,box6,box7,box8,box9, submitted_at, submitted_by)
                    VALUES (?,?, 'submitted', ?,?,?,?,?,?,?,?,?, NOW(), ?)
                    ON DUPLICATE KEY UPDATE status='submitted',
                        box1=VALUES(box1), box2=VALUES(box2), box3=VALUES(box3), box4=VALUES(box4),
                        box5=VALUES(box5), box6=VALUES(box6), box7=VALUES(box7), box8=VALUES(box8),
                        box9=VALUES(box9), submitted_at=NOW(), submitted_by=VALUES(submitted_by)";
            $pdo->prepare($sql)->execute(array_merge(
                [$period['from'], $period['to']],
                array_values($boxes),
                [adminStaffName()]
            ));

            acctAudit($pdo, 'vat.return_submitted', 'vat_returns', 0, null, json_encode($boxes));
            $msg = 'Return marked as submitted and frozen. Remember it still has to be FILED with HMRC through MTD software.';

            $st = $pdo->prepare("SELECT * FROM vat_returns WHERE period_from = ? AND period_to = ?");
            $st->execute([$period['from'], $period['to']]);
            $filed = $st->fetch(PDO::FETCH_ASSOC);
        }
    }
}

// ── Figures ─────────────────────────────────────────────────
$isSubmitted = $filed && $filed['status'] === 'submitted';

if ($isSubmitted) {
    // Read the frozen snapshot, not a fresh calculation.
    $boxes = [];
    for ($i = 1; $i <= 9; $i++) { $boxes[$i] = (float)$filed['box' . $i]; }
    $computed = acctComputeReturn($pdo, $period['from'], $period['to']);   // for the workings only
    $workings = $computed['workings'];
    $scheme   = $computed['scheme'];
} else {
    $computed = acctComputeReturn($pdo, $period['from'], $period['to']);
    $boxes    = $computed['boxes'];
    $workings = $computed['workings'];
    $scheme   = $computed['scheme'];

    if ($filed) {
        $adj = $pdo->prepare("SELECT box, SUM(amount) AS total FROM vat_adjustments WHERE return_id = ? GROUP BY box");
        $adj->execute([$filed['id']]);
        foreach ($adj->fetchAll(PDO::FETCH_ASSOC) as $a) {
            $boxes[(int)$a['box']] = round($boxes[(int)$a['box']] + (float)$a['total'], 2);
        }
        $boxes[3] = round($boxes[1] + $boxes[2], 2);
        $boxes[5] = round($boxes[3] - $boxes[4], 2);
    }
}

$adjustments = [];
if ($filed) {
    $st = $pdo->prepare("SELECT * FROM vat_adjustments WHERE return_id = ? ORDER BY created_at DESC");
    $st->execute([$filed['id']]);
    $adjustments = $st->fetchAll(PDO::FETCH_ASSOC);
}

$labels = acctBoxLabels();
$prev   = date('Y-m-d', strtotime($period['from'] . ' -1 day'));
$next   = date('Y-m-d', strtotime($period['to'] . ' +1 day'));

acctPageStart('vat_return', 'VAT Return', acctPeriodLabel($period));
?>

<?php if ($msg): ?><div class="cbac-banner is-ok"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="cbac-banner is-warn"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($err) ?></div><?php endif; ?>

<div class="cbac-period-bar cbac-noprint">
    <a class="btn-sm" href="?on=<?= $prev ?>"><i class="fa-solid fa-chevron-left"></i> Previous period</a>
    <strong><?= htmlspecialchars(acctPeriodLabel($period)) ?></strong>
    <a class="btn-sm" href="?on=<?= $next ?>">Next period <i class="fa-solid fa-chevron-right"></i></a>
    <span class="cbac-spacer"></span>
    <button type="button" class="btn-sm" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
    <a class="btn-sm" href="export.php?type=vat_return&on=<?= urlencode($period['from']) ?>"><i class="fa-solid fa-file-csv"></i> CSV</a>
</div>

<?php if ($isSubmitted): ?>
<div class="cbac-banner is-ok">
    <i class="fa-solid fa-lock"></i>
    <strong>Marked submitted <?= htmlspecialchars(date('j M Y', strtotime($filed['submitted_at']))) ?></strong>
    by <?= htmlspecialchars($filed['submitted_by']) ?>. These figures are frozen — later edits to
    invoices or expenses will not change them.
</div>
<?php endif; ?>

<div class="cbac-banner is-info cbac-noprint">
    <i class="fa-solid fa-circle-info"></i>
    <strong>This prepares the return; it does not file it.</strong>
    Since April 2022 VAT returns must reach HMRC through Making Tax Digital software.
    Give these figures to your accountant or bridging tool — typing them into the HMRC
    website is not compliant.
</div>

<!-- ── The nine boxes ─────────────────────────────────── -->
<div class="cbac-panel">
    <table class="cbac-table cbac-boxes">
        <thead>
            <tr><th class="cbac-box-n">Box</th><th>Description</th><th class="r">Amount</th></tr>
        </thead>
        <tbody>
        <?php foreach ($labels as $n => $label):
            $isTotal = in_array($n, [3, 5], true);
            $isValue = in_array($n, [6, 7, 8, 9], true);
        ?>
            <tr class="<?= $isTotal ? 'is-total' : '' ?>">
                <td class="cbac-box-n"><?= $n ?></td>
                <td>
                    <?= htmlspecialchars($label) ?>
                    <?php if (!empty($workings[$n])): ?>
                    <details class="cbac-working">
                        <summary>Show the calculation</summary>
                        <table class="cbac-working-table">
                            <?php foreach ($workings[$n] as [$what, $amt]): ?>
                            <tr><td><?= htmlspecialchars($what) ?></td><td class="r"><?= acctMoney((float)$amt) ?></td></tr>
                            <?php endforeach; ?>
                            <tr class="is-total"><td>Box <?= $n ?></td><td class="r"><?= acctMoney($boxes[$n]) ?></td></tr>
                        </table>
                    </details>
                    <?php elseif (in_array($n, [2, 8, 9], true)): ?>
                        <small class="cbac-hint">Nil — this business does not trade with the EU.</small>
                    <?php endif; ?>
                </td>
                <td class="r cbac-box-amount"><?= $isValue ? acctMoney($boxes[$n]) : acctMoney($boxes[$n]) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="cbac-box-summary <?= $boxes[5] >= 0 ? 'is-owed' : 'is-refund' ?>">
        <?php if ($boxes[5] >= 0): ?>
            <strong><?= acctMoney($boxes[5]) ?></strong> to pay HMRC
        <?php else: ?>
            <strong><?= acctMoney(abs($boxes[5])) ?></strong> to reclaim from HMRC
        <?php endif; ?>
        <span>by <?= htmlspecialchars(date('j F Y', strtotime(acctPeriodDue($period)))) ?></span>
    </div>
</div>

<?php if ($scheme === 'cash'): ?>
<div class="cbac-banner is-info">
    <i class="fa-solid fa-circle-info"></i>
    <strong>Cash Accounting.</strong> Only settled sales are counted, on the date the order
    was placed — the orders table has no separate payment date, so that date is used as the
    closest available. If a payment regularly lands in a different period from the order,
    tell your accountant so it can be adjusted.
</div>
<?php endif; ?>

<!-- ── Adjustments ────────────────────────────────────── -->
<div class="cbac-panel cbac-noprint">
    <h2 class="cbac-panel-title">Adjustments</h2>
    <p class="cbac-note">
        Use these for corrections the records cannot show on their own — bad debt relief,
        a partial-exemption calculation, or an error carried forward from a previous return.
        Every one needs a reason, and every one is kept.
    </p>

    <?php if ($adjustments): ?>
    <table class="cbac-table">
        <thead><tr><th>Box</th><th class="r">Amount</th><th>Reason</th><th>By</th><th>When</th></tr></thead>
        <tbody>
        <?php foreach ($adjustments as $a): ?>
            <tr>
                <td>Box <?= (int)$a['box'] ?></td>
                <td class="r"><?= acctMoney((float)$a['amount']) ?></td>
                <td><?= htmlspecialchars($a['reason']) ?></td>
                <td><?= htmlspecialchars($a['created_by']) ?></td>
                <td><?= htmlspecialchars(date('j M Y', strtotime($a['created_at']))) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>

    <?php if (!$isSubmitted): ?>
    <form method="post" class="cbac-adjust-form">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="adjust">
        <div class="form-group">
            <label class="form-label" for="abox">Box</label>
            <select id="abox" name="box" class="form-control">
                <?php foreach ([1, 4, 6, 7] as $b): ?>
                <option value="<?= $b ?>">Box <?= $b ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label class="form-label" for="aamt">Amount (− to reduce)</label>
            <input type="number" id="aamt" name="amount" class="form-control" step="0.01" required>
        </div>
        <div class="form-group cbac-grow">
            <label class="form-label" for="arsn">Reason <span class="cbac-req">required</span></label>
            <input type="text" id="arsn" name="reason" class="form-control" maxlength="255" required
                   placeholder="e.g. Bad debt relief on invoice INV0231">
        </div>
        <button type="submit" class="btn-secondary">Add adjustment</button>
    </form>
    <?php endif; ?>
</div>

<!-- ── Submit ─────────────────────────────────────────── -->
<?php if (!$isSubmitted): ?>
<div class="cbac-panel cbac-noprint">
    <h2 class="cbac-panel-title">Mark as submitted</h2>
    <p class="cbac-note">
        Once marked, these nine figures are frozen as a record of what was filed. Later
        edits to invoices or expenses will show up in the <em>next</em> return, which is
        how a correction is supposed to work.
    </p>
    <form method="post" data-confirm="Freeze this return? The nine boxes are stored exactly as they stand now and cannot be recalculated afterwards."
          data-confirm-title="Mark as submitted?" data-confirm-ok="Mark submitted">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="submit">
        <button type="submit" class="btn-primary"><i class="fa-solid fa-lock"></i> Mark as submitted</button>
    </form>
</div>
<?php endif; ?>

<?php acctPageEnd(); ?>
