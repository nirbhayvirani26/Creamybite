<?php
// ============================================================
//  Creamy Bite – VAT settings
//
//  The switch for the whole module. Until "VAT registered" is ticked, no VAT
//  is charged, no return is prepared, and the other pages say so plainly
//  rather than showing zeroes that look like a quiet month.
//
//  Changing a VAT setting is an accounting event, so every save is written to
//  the audit log with the old and new values.
// ============================================================
require_once __DIR__ . '/_layout.php';

$saved = false;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();

    $before = acctSettings($pdo);

    $isReg   = isset($_POST['is_registered']) ? 1 : 0;
    $name    = trim($_POST['business_name'] ?? '');
    $vatNo   = strtoupper(preg_replace('/\s+/', '', $_POST['vat_number'] ?? ''));
    $scheme  = in_array($_POST['scheme'] ?? '', ['standard','flat_rate','cash','annual'], true) ? $_POST['scheme'] : 'standard';
    $freq    = in_array($_POST['frequency'] ?? '', ['monthly','quarterly','annual'], true) ? $_POST['frequency'] : 'quarterly';
    $flatPct = max(0, min(100, (float)($_POST['flat_rate_pct'] ?? 0)));
    $start   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['period_start'] ?? '') ? $_POST['period_start'] : date('Y-04-01');
    $defRate = (int)($_POST['default_rate_id'] ?? 0);

    // A UK VAT number is 9 digits, optionally prefixed GB, optionally with a
    // 3-digit branch suffix. Checked rather than accepted blindly because it
    // is printed on every invoice, and a wrong one makes those invoices
    // invalid for the customer to reclaim against.
    if ($isReg) {
        $bare = preg_replace('/^GB/', '', $vatNo);
        if (!preg_match('/^\d{9}(\d{3})?$/', $bare)) {
            $errors[] = 'That VAT number does not look right. A UK number is 9 digits, for example GB123456789.';
        }
        if ($name === '') {
            $errors[] = 'A registered business needs its registered name on the invoices.';
        }
        if ($scheme === 'flat_rate' && $flatPct <= 0) {
            $errors[] = 'The Flat Rate Scheme needs the percentage HMRC gave you.';
        }
    }

    if (!$errors) {
        $st = $pdo->prepare(
            "UPDATE vat_settings SET is_registered=:r, business_name=:n, vat_number=:v, scheme=:s,
                    flat_rate_pct=:fp, frequency=:f, period_start=:ps, default_rate_id=:dr WHERE id=1"
        );
        $st->execute([
            'r' => $isReg, 'n' => $name, 'v' => $vatNo, 's' => $scheme,
            'fp' => $flatPct, 'f' => $freq, 'ps' => $start, 'dr' => $defRate,
        ]);

        acctAudit($pdo, 'vat_settings.update', 'vat_settings', 1,
            json_encode(array_intersect_key($before, array_flip(['is_registered','vat_number','scheme','frequency','period_start','flat_rate_pct']))),
            json_encode(['is_registered'=>$isReg,'vat_number'=>$vatNo,'scheme'=>$scheme,'frequency'=>$freq,'period_start'=>$start,'flat_rate_pct'=>$flatPct]));

        $saved = true;
        // acctSettings() caches per request; re-read so the form shows what was saved.
        $GLOBALS['__acct_settings_dirty'] = true;
    }
}

// Re-read after a save (acctSettings caches, so query directly).
$s = $saved
    ? $pdo->query("SELECT * FROM vat_settings WHERE id = 1")->fetch(PDO::FETCH_ASSOC)
    : acctSettings($pdo);

$rates = acctRates($pdo, false);

acctPageStart('settings', 'VAT Settings', 'How this business is registered and how VAT is calculated');
?>

<?php if ($saved): ?>
<div class="cbac-banner is-ok"><i class="fa-solid fa-circle-check"></i> Settings saved.</div>
<?php endif; ?>
<?php foreach ($errors as $e): ?>
<div class="cbac-banner is-warn"><i class="fa-solid fa-circle-exclamation"></i> <?= htmlspecialchars($e) ?></div>
<?php endforeach; ?>

<form method="post" class="cbac-panel">
    <?= csrfField() ?>

    <h2 class="cbac-panel-title">Registration</h2>

    <label class="cbac-switch">
        <input type="checkbox" name="is_registered" value="1" <?= $s['is_registered'] ? 'checked' : '' ?>>
        <span>
            <strong>This business is registered for VAT</strong>
            <small>
                Leave this off if you are below the threshold or have not registered yet.
                With it off, no VAT is charged anywhere and no return is prepared — which is
                the correct behaviour, not a fault.
            </small>
        </span>
    </label>

    <div class="cbac-form-grid">
        <div class="form-group">
            <label class="form-label" for="bn">Registered business name</label>
            <input type="text" id="bn" name="business_name" class="form-control" maxlength="180"
                   value="<?= htmlspecialchars($s['business_name']) ?>">
        </div>
        <div class="form-group">
            <label class="form-label" for="vn">VAT registration number</label>
            <input type="text" id="vn" name="vat_number" class="form-control" maxlength="20"
                   placeholder="GB123456789" value="<?= htmlspecialchars($s['vat_number']) ?>">
            <small class="cbac-hint">Printed on every invoice. 9 digits, optionally prefixed GB.</small>
        </div>
    </div>

    <h2 class="cbac-panel-title">Scheme</h2>

    <div class="cbac-form-grid">
        <div class="form-group">
            <label class="form-label" for="sch">VAT scheme</label>
            <select id="sch" name="scheme" class="form-control" onchange="acctToggleFlat()">
                <?php foreach ([
                    'standard'  => 'Standard VAT — charge and reclaim as invoiced',
                    'cash'      => 'Cash Accounting — VAT falls due when paid',
                    'flat_rate' => 'Flat Rate Scheme — fixed % of gross turnover',
                    'annual'    => 'Annual Accounting — one return a year',
                ] as $k => $lbl): ?>
                <option value="<?= $k ?>" <?= $s['scheme'] === $k ? 'selected' : '' ?>><?= htmlspecialchars($lbl) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group" id="flatRow" <?= $s['scheme'] === 'flat_rate' ? '' : 'hidden' ?>>
            <label class="form-label" for="fp">Flat rate percentage</label>
            <input type="number" id="fp" name="flat_rate_pct" class="form-control" step="0.01" min="0" max="100"
                   value="<?= htmlspecialchars((string)$s['flat_rate_pct']) ?>">
            <small class="cbac-hint">
                The rate HMRC gave you for your trade sector. On this scheme you pay this
                percentage of your VAT-inclusive turnover and reclaim nothing on ordinary
                purchases, so Box 4 will normally be zero.
            </small>
        </div>

        <div class="form-group">
            <label class="form-label" for="fr">Return frequency</label>
            <select id="fr" name="frequency" class="form-control">
                <?php foreach (['monthly' => 'Monthly', 'quarterly' => 'Quarterly', 'annual' => 'Annual'] as $k => $lbl): ?>
                <option value="<?= $k ?>" <?= $s['frequency'] === $k ? 'selected' : '' ?>><?= $lbl ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-group">
            <label class="form-label" for="ps">First VAT period started</label>
            <input type="date" id="ps" name="period_start" class="form-control"
                   value="<?= htmlspecialchars($s['period_start'] ?? '') ?>">
            <small class="cbac-hint">
                Periods are counted forward from this date, not from the calendar year —
                a business registered in May files Feb/May/Aug/Nov.
            </small>
        </div>
    </div>

    <h2 class="cbac-panel-title">VAT rates</h2>
    <p class="cbac-note">
        Zero rated and Exempt both charge nothing and are <em>not</em> the same:
        zero-rated sales count towards Box 6, exempt ones do not. Most cold takeaway
        food is zero rated; anything eaten in, or hot, is standard rated.
    </p>

    <table class="cbac-table">
        <thead><tr><th>Rate</th><th class="r">%</th><th>Treatment</th><th>Default for new products</th></tr></thead>
        <tbody>
        <?php foreach ($rates as $r): ?>
            <tr>
                <td><strong><?= htmlspecialchars($r['label']) ?></strong></td>
                <td class="r"><?= rtrim(rtrim(number_format((float)$r['rate'] * 100, 2), '0'), '.') ?>%</td>
                <td><?= htmlspecialchars(str_replace('_', ' ', $r['kind'])) ?></td>
                <td>
                    <label class="cbac-radio">
                        <input type="radio" name="default_rate_id" value="<?= (int)$r['id'] ?>"
                               <?= (int)$s['default_rate_id'] === (int)$r['id'] ? 'checked' : '' ?>>
                        <span>Use this</span>
                    </label>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="cbac-actions">
        <button type="submit" class="btn-primary"><i class="fa-solid fa-floppy-disk"></i> Save settings</button>
    </div>
</form>

<div class="cbac-panel cbac-panel-note">
    <h2 class="cbac-panel-title">Filing with HMRC</h2>
    <p>
        Since April 2022 every VAT-registered business must file through software that
        sends the return to HMRC's API directly. Copying figures from a screen into the
        HMRC website is not compliant, however accurate those figures are.
    </p>
    <p>
        This module <strong>prepares</strong> the nine boxes and shows the workings behind
        each one, which is what an accountant or a bridging tool needs. It does not submit
        them — that requires registering with HMRC as a software vendor and going through
        their approval process, which is a separate piece of work.
    </p>
</div>

<script>
function acctToggleFlat() {
    var isFlat = document.getElementById('sch').value === 'flat_rate';
    document.getElementById('flatRow').hidden = !isFlat;
}
</script>

<?php acctPageEnd(); ?>
