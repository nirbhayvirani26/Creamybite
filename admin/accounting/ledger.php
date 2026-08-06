<?php
// ============================================================
//  Creamy Bite – General ledger, journal and audit trail
//
//  Read-only by design. Entries arrive from the things that actually happen —
//  an expense saved, a purchase recorded — and a manual journal is added
//  through its own form rather than by editing history.
//
//  Nothing is ever deleted. A mistake is corrected by posting the reverse,
//  which is what an auditor expects to see and what keeps the trail intact.
// ============================================================
require_once __DIR__ . '/_layout.php';

$msg = ''; $err = '';

// ── Manual journal ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'journal') {
    csrfCheck();
    if (!adminIsOwner() && !adminCan('accounting_file')) {
        $err = 'Only the owner or an accountant may post a manual journal.';
    } else {
        $date  = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['entry_date'] ?? '') ? $_POST['entry_date'] : date('Y-m-d');
        $memo  = trim($_POST['memo'] ?? '');
        $ref   = trim($_POST['reference'] ?? '');
        $codes = (array)($_POST['code'] ?? []);
        $debs  = (array)($_POST['debit'] ?? []);
        $creds = (array)($_POST['credit'] ?? []);

        $lines = [];
        foreach ($codes as $i => $code) {
            $d = round((float)($debs[$i] ?? 0), 2);
            $c = round((float)($creds[$i] ?? 0), 2);
            if ($code === '' || ($d == 0 && $c == 0)) { continue; }
            $lines[] = ['code' => $code, 'debit' => $d, 'credit' => $c, 'description' => $memo];
        }

        if ($memo === '')        { $err = 'A journal needs a description — six months from now it is the only clue to why.'; }
        elseif (count($lines) < 2) { $err = 'A journal needs at least two lines.'; }
        else {
            try {
                // acctPostJournal refuses anything that does not balance, so
                // the check lives in one place rather than being repeated here.
                $id = acctPostJournal($pdo, $date, $memo, $lines, 'manual', 0, $ref, adminStaffName());
                acctAudit($pdo, 'journal.manual', 'journal_entries', $id, null, json_encode($lines));
                $msg = 'Journal #' . $id . ' posted.';
            } catch (Throwable $e) {
                $err = $e->getMessage();
            }
        }
    }
}

$period = acctPeriodFor($pdo);
$from   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : $period['from'];
$to     = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '')   ? $_GET['to']   : $period['to'];

$entries = [];
try {
    $st = $pdo->prepare(
        "SELECT e.*, COALESCE(SUM(l.debit),0) AS total
           FROM journal_entries e
      LEFT JOIN journal_lines l ON l.entry_id = e.id
          WHERE e.entry_date BETWEEN :f AND :t
       GROUP BY e.id
       ORDER BY e.entry_date DESC, e.id DESC
          LIMIT 200"
    );
    $st->execute(['f' => $from, 't' => $to]);
    $entries = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { }

$linesByEntry = [];
if ($entries) {
    $ids = implode(',', array_map(static fn($e) => (int)$e['id'], $entries));
    try {
        foreach ($pdo->query(
            "SELECT l.*, a.code, a.name FROM journal_lines l
               JOIN ledger_accounts a ON a.id = l.account_id
              WHERE l.entry_id IN ({$ids}) ORDER BY l.id"
        ) as $l) {
            $linesByEntry[(int)$l['entry_id']][] = $l;
        }
    } catch (PDOException $e) { }
}

$trial    = acctTrialBalance($pdo, $from, $to);
$accounts = [];
try { $accounts = $pdo->query("SELECT code, name FROM ledger_accounts WHERE active = 1 ORDER BY code")->fetchAll(PDO::FETCH_ASSOC); }
catch (PDOException $e) { }

$audit = [];
try { $audit = $pdo->query("SELECT * FROM accounting_audit ORDER BY occurred_at DESC LIMIT 40")->fetchAll(PDO::FETCH_ASSOC); }
catch (PDOException $e) { }

acctPageStart('ledger', 'Ledger', acctPeriodLabel(['from' => $from, 'to' => $to]));
?>

<?php if ($msg): ?><div class="cbac-banner is-ok"><i class="fa-solid fa-circle-check"></i> <?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="cbac-banner is-warn"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($err) ?></div><?php endif; ?>

<form method="get" class="cbac-filter-bar cbac-noprint">
    <div class="form-group"><label class="form-label" for="ff">From</label>
        <input type="date" id="ff" name="from" class="form-control" value="<?= htmlspecialchars($from) ?>"></div>
    <div class="form-group"><label class="form-label" for="tt">To</label>
        <input type="date" id="tt" name="to" class="form-control" value="<?= htmlspecialchars($to) ?>"></div>
    <button type="submit" class="btn-sm"><i class="fa-solid fa-filter"></i> Filter</button>
    <span class="cbac-spacer"></span>
    <a class="btn-sm" href="export.php?type=ledger&from=<?= urlencode($from) ?>&to=<?= urlencode($to) ?>"><i class="fa-solid fa-file-csv"></i> CSV</a>
    <button type="button" class="btn-sm" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
</form>

<!-- ── Trial balance ──────────────────────────────────── -->
<div class="cbac-panel">
    <h2 class="cbac-panel-title">Trial balance</h2>
    <?php if (!$trial): ?>
        <p class="cbac-empty">Nothing posted in this period.</p>
    <?php else: ?>
    <?php $td = $tc = 0.0; ?>
    <table class="cbac-table">
        <thead><tr><th>Code</th><th>Account</th><th>Type</th><th class="r">Debit</th><th class="r">Credit</th></tr></thead>
        <tbody>
        <?php foreach ($trial as $t): $td += (float)$t['debit']; $tc += (float)$t['credit']; ?>
            <tr>
                <td class="cbac-mono"><?= htmlspecialchars($t['code']) ?></td>
                <td><?= htmlspecialchars($t['name']) ?></td>
                <td><?= htmlspecialchars($t['type']) ?></td>
                <td class="r"><?= (float)$t['debit'] != 0 ? acctMoney((float)$t['debit']) : '—' ?></td>
                <td class="r"><?= (float)$t['credit'] != 0 ? acctMoney((float)$t['credit']) : '—' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr class="is-total">
                <td colspan="3">Total</td>
                <td class="r"><?= acctMoney($td) ?></td>
                <td class="r"><?= acctMoney($tc) ?></td>
            </tr>
            <tr class="<?= abs($td - $tc) < 0.005 ? 'cbac-balanced' : 'cbac-unbalanced' ?>">
                <td colspan="5">
                    <?= abs($td - $tc) < 0.005
                        ? '✓ Debits equal credits — the ledger balances.'
                        : '✗ Out by ' . acctMoney($td - $tc) . ' — this should never happen; tell your developer.' ?>
                </td>
            </tr>
        </tfoot>
    </table>
    <?php endif; ?>
</div>

<!-- ── Journal ────────────────────────────────────────── -->
<div class="cbac-panel">
    <h2 class="cbac-panel-title">Journal entries</h2>
    <?php if (!$entries): ?>
        <p class="cbac-empty">No entries in this period.</p>
    <?php else: ?>
        <?php foreach ($entries as $e): ?>
        <details class="cbac-journal">
            <summary>
                <span class="cbac-journal-date"><?= htmlspecialchars(date('j M Y', strtotime($e['entry_date']))) ?></span>
                <span class="cbac-journal-memo"><?= htmlspecialchars($e['memo']) ?></span>
                <span class="cbac-journal-src"><?= htmlspecialchars($e['source']) ?></span>
                <span class="cbac-journal-amt"><?= acctMoney((float)$e['total']) ?></span>
            </summary>
            <table class="cbac-working-table">
                <thead><tr><th>Account</th><th class="r">Debit</th><th class="r">Credit</th></tr></thead>
                <tbody>
                <?php foreach ($linesByEntry[(int)$e['id']] ?? [] as $l): ?>
                    <tr>
                        <td><span class="cbac-mono"><?= htmlspecialchars($l['code']) ?></span> <?= htmlspecialchars($l['name']) ?></td>
                        <td class="r"><?= (float)$l['debit'] != 0 ? acctMoney((float)$l['debit']) : '' ?></td>
                        <td class="r"><?= (float)$l['credit'] != 0 ? acctMoney((float)$l['credit']) : '' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </details>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- ── Manual journal ─────────────────────────────────── -->
<?php if (adminIsOwner()): ?>
<div class="cbac-panel cbac-noprint">
    <h2 class="cbac-panel-title">Post a manual journal</h2>
    <p class="cbac-note">
        Debits must equal credits — anything else is refused rather than saved and
        found later. Nothing posted here can be deleted; correct a mistake by posting
        the reverse.
    </p>
    <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="journal">
        <div class="cbac-form-grid">
            <div class="form-group"><label class="form-label" for="jd">Date</label>
                <input type="date" id="jd" name="entry_date" class="form-control" value="<?= date('Y-m-d') ?>"></div>
            <div class="form-group"><label class="form-label" for="jr">Reference</label>
                <input type="text" id="jr" name="reference" class="form-control" maxlength="120"></div>
            <div class="form-group cbac-span-2"><label class="form-label" for="jm">Description *</label>
                <input type="text" id="jm" name="memo" class="form-control" maxlength="255" required></div>
        </div>

        <table class="cbac-table">
            <thead><tr><th>Account</th><th class="r">Debit</th><th class="r">Credit</th></tr></thead>
            <tbody>
            <?php for ($i = 0; $i < 4; $i++): ?>
                <tr>
                    <td>
                        <select name="code[]" class="form-control">
                            <option value="">—</option>
                            <?php foreach ($accounts as $a): ?>
                            <option value="<?= htmlspecialchars($a['code']) ?>"><?= htmlspecialchars($a['code'] . ' · ' . $a['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td><input type="number" name="debit[]" class="form-control r" step="0.01" min="0"></td>
                    <td><input type="number" name="credit[]" class="form-control r" step="0.01" min="0"></td>
                </tr>
            <?php endfor; ?>
            </tbody>
        </table>

        <div class="cbac-actions">
            <button type="submit" class="btn-primary"><i class="fa-solid fa-book"></i> Post journal</button>
        </div>
    </form>
</div>
<?php endif; ?>

<!-- ── Audit trail ────────────────────────────────────── -->
<div class="cbac-panel">
    <h2 class="cbac-panel-title">Audit trail</h2>
    <?php if (!$audit): ?>
        <p class="cbac-empty">Nothing recorded yet.</p>
    <?php else: ?>
    <table class="cbac-table">
        <thead><tr><th>When</th><th>Who</th><th>Action</th><th>Record</th><th>IP</th></tr></thead>
        <tbody>
        <?php foreach ($audit as $a): ?>
            <tr>
                <td><?= htmlspecialchars(date('j M Y H:i', strtotime($a['occurred_at']))) ?></td>
                <td><?= htmlspecialchars($a['user_name']) ?></td>
                <td class="cbac-mono"><?= htmlspecialchars($a['action']) ?></td>
                <td><?= htmlspecialchars($a['entity'] . ($a['entity_id'] ? ' #' . $a['entity_id'] : '')) ?></td>
                <td class="cbac-mono"><?= htmlspecialchars($a['ip_address']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<?php acctPageEnd(); ?>
