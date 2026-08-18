<?php
/**
 * Production — batch records.
 *
 * What was made, how much came out, what went in, what went wrong. The
 * products come straight from the catalogue, so a new flavour appears here the
 * moment it is added on the Products page.
 */

require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_permissions.php';
adminRequire('production');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/production.php';

$pageTitle = 'Production';
$pageSub   = 'Batch records — what was made, and how it went';

$ready     = cbProdReady($pdo);
$statuses  = cbProdStatuses();
$catalogue = $ready ? cbProdCatalogue($pdo) : [];
$filter    = (string)($_GET['status'] ?? '');
$runs      = $ready ? cbProdList($pdo, isset($statuses[$filter]) ? $filter : '') : [];
$summary   = $ready ? cbProdSummary($pdo, 30) : [];
$nextCode  = $ready ? cbProdNextBatchCode($pdo) : '';

$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $h($pageTitle) ?> – <?= $h(SHOP_NAME) ?> Admin</title>
    <?php require __DIR__ . '/../includes/favicon.php'; ?>
    <link rel="stylesheet" href="<?= cbAsset('../assets/css/style.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="<?= cbAsset('assets/css/admin.css') ?>">
    <link rel="stylesheet" href="<?= cbAsset('../assets/css/modal.css') ?>">
</head>
<?php include __DIR__ . '/_csrf_js.php'; ?>
<body class="admin-wrapper has-sidebar">

<?php
$cbSidebarCurrent = 'production';
require __DIR__ . '/_sidebar.php';
?>

<div class="admin-shell">
<header class="admin-topbar cbat-toggle-only">
    <button class="sb-toggle" id="sbToggle" aria-label="Open menu" aria-controls="adminSidebar" aria-expanded="false">
        <i class="fa-solid fa-bars"></i>
    </button>
</header>

<div class="cbpr-wrap">

    <header class="cbpr-head">
        <h1 class="cbpr-title"><i class="fa-solid fa-industry" aria-hidden="true"></i> <?= $h($pageTitle) ?></h1>
        <p class="cbpr-sub"><?= $h($pageSub) ?></p>
    </header>

    <?php if (!$ready): ?>
    <div class="cbpr-banner is-warn">
        <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
        <div>
            <strong>Production is not set up on this server yet.</strong>
            Run <a href="migrations/update_db.php">Update DB</a> once, then come back.
        </div>
    </div>
    <?php else: ?>

    <!-- ── Last 30 days ───────────────────────────────────── -->
    <section class="cbpr-stats">
        <div class="cbpr-stat">
            <div class="cbpr-stat-val"><?= (int)($summary['runs'] ?? 0) ?></div>
            <div class="cbpr-stat-lab">runs in 30 days</div>
        </div>
        <div class="cbpr-stat">
            <div class="cbpr-stat-val"><?= (int)($summary['output'] ?? 0) ?></div>
            <div class="cbpr-stat-lab">units made</div>
        </div>
        <div class="cbpr-stat">
            <div class="cbpr-stat-val"><?= (int)($summary['rejects'] ?? 0) ?></div>
            <div class="cbpr-stat-lab">rejected</div>
        </div>
        <div class="cbpr-stat">
            <div class="cbpr-stat-val"><?= $summary['yield'] !== null ? $h($summary['yield']) . '%' : '—' ?></div>
            <div class="cbpr-stat-lab">yield against plan</div>
        </div>
        <div class="cbpr-stat <?= (int)($summary['problems'] ?? 0) > 0 ? 'is-flag' : '' ?>">
            <div class="cbpr-stat-val"><?= (int)($summary['problems'] ?? 0) ?></div>
            <div class="cbpr-stat-lab">runs with problems</div>
        </div>
    </section>

    <!-- ── Record a run ───────────────────────────────────── -->
    <section class="cbpr-panel">
        <h2 class="cbpr-panel-title">
            <i class="fa-solid fa-circle-plus" aria-hidden="true"></i> Record a production run
            <span class="cbpr-batch-hint">next batch: <strong id="prNextCode"><?= $h($nextCode) ?></strong></span>
        </h2>

        <form id="prForm" class="cbpr-form">
            <input type="hidden" name="action" value="create">
            <input type="hidden" name="id" id="prId" value="">

            <div class="cbpr-field">
                <label class="cbpr-label" for="prProduct">Which product</label>
                <select id="prProduct" name="product_id" class="cbpr-input" required>
                    <option value="">Choose a flavour…</option>
                    <?php foreach ($catalogue as $p): ?>
                    <option value="<?= (int)$p['id'] ?>"><?= $h($p['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <small class="cbpr-hint">Comes straight from your Products page.</small>
            </div>

            <div class="cbpr-field">
                <label class="cbpr-label" for="prVariant">Size</label>
                <select id="prVariant" name="variant_id" class="cbpr-input">
                    <option value="">Whole batch / no size</option>
                </select>
            </div>

            <div class="cbpr-field">
                <label class="cbpr-label" for="prDate">Made on</label>
                <input type="date" id="prDate" name="produced_on" class="cbpr-input" value="<?= $h(date('Y-m-d')) ?>">
            </div>

            <div class="cbpr-field">
                <label class="cbpr-label" for="prExternal">Batch number (on the tub)</label>
                <div class="cbpr-batchrow">
                    <input type="text" id="prExternal" name="external_batch" class="cbpr-input"
                           maxlength="40" autocomplete="off" spellcheck="false"
                           placeholder="choose a flavour and date first">
                    <button type="button" id="prExternalRefresh" class="btn-sm btn-sm-outline"
                            title="Suggest the next number for this flavour and date">
                        <i class="fa-solid fa-wand-magic-sparkles" aria-hidden="true"></i> Suggest
                    </button>
                </div>
                <small class="cbpr-hint">
                    Initials of the flavour, the date, then the run number — American Dry Fruits made
                    on 18 Aug 2026 gives <strong>AD26081801</strong>. Suggested automatically, but type
                    over it if the tubs are already coded. The <?= $h('PR-') ?> number below is kept as well.
                </small>
            </div>

            <div class="cbpr-field">
                <label class="cbpr-label" for="prStatus">How is it going</label>
                <select id="prStatus" name="status" class="cbpr-input">
                    <?php foreach ($statuses as $k => $lab): ?>
                    <option value="<?= $h($k) ?>" <?= $k === 'in_progress' ? 'selected' : '' ?>><?= $h($lab) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="cbpr-field cbpr-third">
                <label class="cbpr-label" for="prPlanned">Planned</label>
                <input type="number" id="prPlanned" name="planned_qty" class="cbpr-input" min="0" value="0">
            </div>
            <div class="cbpr-field cbpr-third">
                <label class="cbpr-label" for="prOutput">Good output</label>
                <input type="number" id="prOutput" name="output_qty" class="cbpr-input" min="0" value="0">
            </div>
            <div class="cbpr-field cbpr-third">
                <label class="cbpr-label" for="prReject">Rejected</label>
                <input type="number" id="prReject" name="reject_qty" class="cbpr-input" min="0" value="0">
                <small class="cbpr-hint" id="prYieldHint"></small>
            </div>

            <div class="cbpr-field">
                <label class="cbpr-label" for="prUnit">Counted in</label>
                <input type="text" id="prUnit" name="unit_label" class="cbpr-input" maxlength="40" value="tubs"
                       placeholder="tubs, litres, cases">
            </div>
            <div class="cbpr-field">
                <label class="cbpr-label" for="prBB">Best before</label>
                <input type="date" id="prBB" name="best_before" class="cbpr-input">
            </div>

            <div class="cbpr-field cbpr-third">
                <label class="cbpr-label" for="prStart">Started</label>
                <input type="time" id="prStart" name="started_at" class="cbpr-input">
            </div>
            <div class="cbpr-field cbpr-third">
                <label class="cbpr-label" for="prFinish">Finished</label>
                <input type="time" id="prFinish" name="finished_at" class="cbpr-input">
            </div>
            <div class="cbpr-field cbpr-third">
                <label class="cbpr-label" for="prOperator">Who made it</label>
                <input type="text" id="prOperator" name="operator" class="cbpr-input" maxlength="120">
            </div>

            <div class="cbpr-field cbpr-third">
                <label class="cbpr-label" for="prMix">Mix temp °C</label>
                <input type="number" step="0.1" id="prMix" name="mix_temp_c" class="cbpr-input">
            </div>
            <div class="cbpr-field cbpr-third">
                <label class="cbpr-label" for="prPastT">Pasteurised at °C</label>
                <input type="number" step="0.1" id="prPastT" name="pasteurise_temp_c" class="cbpr-input">
            </div>
            <div class="cbpr-field cbpr-third">
                <label class="cbpr-label" for="prPastM">held for (mins)</label>
                <input type="number" id="prPastM" name="pasteurise_mins" class="cbpr-input" min="0">
                <small class="cbpr-hint">Optional, but this is the pair an inspector asks to see.</small>
            </div>

            <div class="cbpr-field cbpr-wide">
                <label class="cbpr-label" for="prMaterials">What we used</label>
                <textarea id="prMaterials" name="materials_used" class="cbpr-input cbpr-area" rows="3"
                          placeholder="Ingredients and quantities. Include the supplier's batch or lot number for each — that is what makes a recall possible."></textarea>
            </div>

            <div class="cbpr-field cbpr-wide">
                <label class="cbpr-label" for="prChanges">What we changed</label>
                <textarea id="prChanges" name="changes_made" class="cbpr-area cbpr-input" rows="2"
                          placeholder="Anything done differently from the recipe — a substituted ingredient, a longer churn, a different supplier."></textarea>
            </div>

            <div class="cbpr-field cbpr-wide">
                <label class="cbpr-label" for="prProblems">Problems</label>
                <textarea id="prProblems" name="problems" class="cbpr-area cbpr-input" rows="2"
                          placeholder="What went wrong, and what was done about it. A blank here on a low-yield run is a gap in the record."></textarea>
            </div>

            <div class="cbpr-field cbpr-wide">
                <label class="cbpr-label" for="prNotes">Other notes</label>
                <textarea id="prNotes" name="notes" class="cbpr-area cbpr-input" rows="2"></textarea>
            </div>

            <div class="cbpr-field cbpr-wide cbpr-actions">
                <button type="submit" class="btn-primary" id="prSaveBtn">
                    <i class="fa-solid fa-circle-plus" aria-hidden="true"></i> Save run
                </button>
                <button type="button" class="btn-secondary" id="prCancelBtn" hidden>Cancel edit</button>
                <span class="cbpr-saved" id="prSaved" hidden></span>
                <span class="cbpr-err" id="prErr" hidden></span>
            </div>
        </form>
    </section>

    <!-- ── The log ────────────────────────────────────────── -->
    <section class="cbpr-panel">
        <h2 class="cbpr-panel-title"><i class="fa-solid fa-clipboard-list" aria-hidden="true"></i> Batch log
            <span class="cbpr-filter">
                <a href="?" class="<?= $filter === '' ? 'is-on' : '' ?>">All</a>
                <?php foreach ($statuses as $k => $lab): ?>
                <a href="?status=<?= $h($k) ?>" class="<?= $filter === $k ? 'is-on' : '' ?>"><?= $h(explode(' —', $lab)[0]) ?></a>
                <?php endforeach; ?>
            </span>
        </h2>

        <?php if ($runs === []): ?>
        <div class="cbpr-empty">
            <div class="cbpr-empty-icon"><i class="fa-solid fa-industry" aria-hidden="true"></i></div>
            <h3 class="cbpr-empty-title">No runs recorded yet</h3>
            <p class="cbpr-empty-note">
                Record one above. Every batch gets a code like <strong><?= $h($nextCode) ?></strong>
                — put it on the tub, and a customer question three months from now
                leads straight back to what went into it.
            </p>
        </div>
        <?php else: ?>
        <div class="cbpr-list">
            <?php foreach ($runs as $r):
                $yield = cbProdYield($r);
                $lowYield = $yield !== null && $yield < 85; ?>
            <article class="cbpr-run" data-run='<?= $h(json_encode($r, JSON_HEX_APOS | JSON_HEX_QUOT)) ?>'>
                <div class="cbpr-run-top">
                    <div>
                        <span class="cbpr-code"><?= $h($r['batch_code']) ?></span>
                        <?php /* The number the world quotes back at you. Shown first in
                                 weight because it is what is printed on the tub and what a
                                 customer or an EHO will read out. */ ?>
                        <?php if (!empty($r['external_batch'])): ?>
                        <span class="cbpr-extcode" title="Batch number printed on the tub"><?= $h($r['external_batch']) ?></span>
                        <?php endif; ?>
                        <span class="cbpr-state is-<?= $h($r['status']) ?>"><?= $h(explode(' —', $statuses[$r['status']] ?? $r['status'])[0]) ?></span>
                        <?php if ((int)$r['stock_added'] === 1): ?>
                        <span class="cbpr-instock"><i class="fa-solid fa-box-open" aria-hidden="true"></i> in stock</span>
                        <?php endif; ?>
                    </div>
                    <div class="cbpr-run-date"><?= $h(date('j M Y', strtotime((string)$r['produced_on']))) ?></div>
                </div>

                <div class="cbpr-run-name">
                    <?= $h($r['product_name']) ?><?php if (!empty($r['variant_name'])): ?>
                    <span class="cbpr-variant"><?= $h($r['variant_name']) ?></span><?php endif; ?>
                </div>

                <div class="cbpr-figures">
                    <span><strong><?= (int)$r['output_qty'] ?></strong> <?= $h($r['unit_label']) ?> out</span>
                    <?php if ((int)$r['planned_qty'] > 0): ?>
                    <span>of <?= (int)$r['planned_qty'] ?> planned</span>
                    <?php endif; ?>
                    <?php if ((int)$r['reject_qty'] > 0): ?>
                    <span class="cbpr-reject"><?= (int)$r['reject_qty'] ?> rejected</span>
                    <?php endif; ?>
                    <?php if ($yield !== null): ?>
                    <span class="cbpr-yield <?= $lowYield ? 'is-low' : '' ?>"><?= $h($yield) ?>% yield</span>
                    <?php endif; ?>
                    <?php if (!empty($r['best_before'])): ?>
                    <span>best before <?= $h(date('j M Y', strtotime((string)$r['best_before']))) ?></span>
                    <?php endif; ?>
                </div>

                <?php foreach ([
                    ['materials_used', 'Used',    'fa-flask'],
                    ['changes_made',   'Changed', 'fa-pen'],
                    ['problems',       'Problems','fa-triangle-exclamation'],
                    ['notes',          'Notes',   'fa-note-sticky'],
                ] as [$key, $lab, $icon]): if (empty($r[$key])) continue; ?>
                <div class="cbpr-note <?= $key === 'problems' ? 'is-problem' : '' ?>">
                    <span class="cbpr-note-lab"><i class="fa-solid <?= $h($icon) ?>" aria-hidden="true"></i> <?= $h($lab) ?></span>
                    <span class="cbpr-note-txt"><?= nl2br($h($r[$key])) ?></span>
                </div>
                <?php endforeach; ?>

                <?php if ($r['pasteurise_temp_c'] !== null || $r['mix_temp_c'] !== null): ?>
                <div class="cbpr-note">
                    <span class="cbpr-note-lab"><i class="fa-solid fa-temperature-half" aria-hidden="true"></i> Temps</span>
                    <span class="cbpr-note-txt">
                        <?php if ($r['mix_temp_c'] !== null): ?>mix <?= $h($r['mix_temp_c']) ?>°C<?php endif; ?>
                        <?php if ($r['pasteurise_temp_c'] !== null): ?>
                            <?= $r['mix_temp_c'] !== null ? ' · ' : '' ?>pasteurised <?= $h($r['pasteurise_temp_c']) ?>°C<?php
                            if (!empty($r['pasteurise_mins'])): ?> for <?= (int)$r['pasteurise_mins'] ?> min<?php endif;
                        endif; ?>
                    </span>
                </div>
                <?php endif; ?>

                <div class="cbpr-run-actions">
                    <?php if (!empty($r['operator'])): ?>
                    <span class="cbpr-by">made by <?= $h($r['operator']) ?></span>
                    <?php endif; ?>
                    <?php if ($r['status'] === 'completed' && (int)$r['stock_added'] === 0 && (int)$r['output_qty'] > 0): ?>
                    <button class="btn-sm btn-sm-info cbpr-stock" data-id="<?= (int)$r['id'] ?>"
                            data-qty="<?= (int)$r['output_qty'] ?>" data-name="<?= $h($r['product_name']) ?>">
                        <i class="fa-solid fa-box-open" aria-hidden="true"></i> Add <?= (int)$r['output_qty'] ?> to stock
                    </button>
                    <?php endif; ?>
                    <button class="btn-sm btn-sm-secondary cbpr-edit" data-id="<?= (int)$r['id'] ?>">Edit</button>
                    <?php if ((int)$r['stock_added'] === 0): ?>
                    <button class="btn-sm btn-sm-danger cbpr-del" data-id="<?= (int)$r['id'] ?>"
                            data-code="<?= $h($r['batch_code']) ?>"><i class="fa-solid fa-trash" aria-hidden="true"></i></button>
                    <?php endif; ?>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </section>

    <?php endif; ?>
</div>
</div><!-- /admin-shell -->

<script>
// The catalogue, so choosing a flavour fills its sizes without another request.
var CBPR_CATALOGUE = <?= json_encode($catalogue, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
</script>
<script src="<?= cbAsset('../assets/js/modal.js') ?>"></script>
<script src="<?= cbAsset('assets/js/production.js') ?>"></script>
</body>
</html>
