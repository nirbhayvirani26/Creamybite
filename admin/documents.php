<?php
/**
 * Documents & SOPs.
 *
 * Where the shop's operating paperwork lives: HACCP, allergen matrix, cleaning
 * schedules, supplier certificates, training records, inspection reports. The
 * point is that an EHO asking for the allergen procedure gets it in ten
 * seconds rather than while somebody searches a laptop.
 *
 * Files are stored outside the reach of the web (assets/documents/ denies all)
 * and served only through admin/document_download.php, which checks the
 * session first.
 */

require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_permissions.php';
adminRequire('documents');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/documents.php';

$pageTitle = 'Documents & SOPs';
$pageSub   = 'Procedures, records and certificates, in one place';

$ready      = cbDocReady($pdo);
$categories = cbDocCategories();
$docs       = $ready ? cbDocList($pdo) : [];

// Group for display, keeping the category order from cbDocCategories().
$byCat = [];
foreach (array_keys($categories) as $c) { $byCat[$c] = []; }
foreach ($docs as $d) {
    $c = $d['category'] ?? 'General';
    if (!isset($byCat[$c])) { $byCat[$c] = []; }
    $byCat[$c][] = $d;
}

$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');

// How many need attention, so the heading can say it rather than making the
// owner read every row.
$overdue = 0; $soon = 0;
foreach ($docs as $d) {
    $st = cbDocReviewState($d['review_due'] ?? null);
    if ($st === 'overdue') { $overdue++; }
    elseif ($st === 'soon') { $soon++; }
}
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
$cbSidebarCurrent = 'documents';
require __DIR__ . '/_sidebar.php';
?>

<div class="admin-shell">
<header class="admin-topbar cbat-toggle-only">
    <button class="sb-toggle" id="sbToggle" aria-label="Open menu" aria-controls="adminSidebar" aria-expanded="false">
        <i class="fa-solid fa-bars"></i>
    </button>
</header>

<div class="cbdoc-wrap">

    <header class="cbdoc-head">
        <h1 class="cbdoc-title"><i class="fa-solid fa-folder-open" aria-hidden="true"></i> <?= $h($pageTitle) ?></h1>
        <p class="cbdoc-sub"><?= $h($pageSub) ?></p>
    </header>

    <?php if (!$ready): ?>
    <div class="cbdoc-banner is-warn">
        <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
        <div>
            <strong>This area is not set up on this server yet.</strong>
            Run <a href="migrations/update_db.php">Update DB</a> once, then come back.
        </div>
    </div>
    <?php else: ?>

    <?php if ($overdue > 0 || $soon > 0): ?>
    <div class="cbdoc-banner <?= $overdue > 0 ? 'is-warn' : 'is-info' ?>">
        <i class="fa-solid fa-clock" aria-hidden="true"></i>
        <div>
            <?php if ($overdue > 0): ?>
            <strong><?= (int)$overdue ?> document<?= $overdue === 1 ? '' : 's' ?> past review.</strong>
            A procedure nobody has checked since the recipes changed is worse than none, because it is believed.
            <?php else: ?>
            <strong><?= (int)$soon ?> document<?= $soon === 1 ? '' : 's' ?> due for review within a month.</strong>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Add ────────────────────────────────────────────── -->
    <section class="cbdoc-panel">
        <h2 class="cbdoc-panel-title"><i class="fa-solid fa-upload" aria-hidden="true"></i> Add a document</h2>
        <p class="cbdoc-panel-note">
            PDF, Word, Excel, image, CSV or text. Up to 20MB. Only people signed
            in to this admin panel can open what you put here.
        </p>

        <form id="docForm" class="cbdoc-form" enctype="multipart/form-data">
            <input type="hidden" name="action" value="upload">

            <div class="cbdoc-field cbdoc-field-wide">
                <label class="cbdoc-label" for="dTitle">What is it called?</label>
                <input type="text" id="dTitle" name="title" class="cbdoc-input" maxlength="180"
                       placeholder="e.g. HACCP plan, or Allergen matrix" required>
                <p class="cbdoc-err" data-err="title" hidden></p>
            </div>

            <div class="cbdoc-field">
                <label class="cbdoc-label" for="dCat">Which part of the business?</label>
                <select id="dCat" name="category" class="cbdoc-input">
                    <?php foreach ($categories as $key => $blurb): ?>
                    <option value="<?= $h($key) ?>"><?= $h($key) ?></option>
                    <?php endforeach; ?>
                </select>
                <small class="cbdoc-hint" id="dCatHint"></small>
            </div>

            <div class="cbdoc-field">
                <label class="cbdoc-label" for="dReview">Review it again on</label>
                <input type="date" id="dReview" name="review_due" class="cbdoc-input">
                <small class="cbdoc-hint">Optional. Food safety paperwork goes stale — a reminder here is worth having.</small>
            </div>

            <div class="cbdoc-field cbdoc-field-wide">
                <label class="cbdoc-label" for="dDesc">A short note (optional)</label>
                <input type="text" id="dDesc" name="description" class="cbdoc-input" maxlength="500"
                       placeholder="What it covers, or who wrote it">
            </div>

            <div class="cbdoc-field cbdoc-field-wide">
                <label class="cbdoc-label" for="dFile">The file</label>
                <input type="file" id="dFile" name="document" class="cbdoc-input cbdoc-file" required>
                <p class="cbdoc-err" data-err="file" hidden></p>
            </div>

            <div class="cbdoc-field cbdoc-field-wide cbdoc-actions">
                <button type="submit" class="btn-primary" id="docSaveBtn">
                    <i class="fa-solid fa-upload" aria-hidden="true"></i> Upload
                </button>
                <span class="cbdoc-saved" id="docSaved" hidden></span>
            </div>
        </form>
    </section>

    <!-- ── The library ────────────────────────────────────── -->
    <?php if ($docs === []): ?>
    <section class="cbdoc-panel">
        <div class="cbdoc-empty">
            <div class="cbdoc-empty-icon"><i class="fa-solid fa-folder-open" aria-hidden="true"></i></div>
            <h3 class="cbdoc-empty-title">Nothing here yet</h3>
            <p class="cbdoc-empty-note">
                Below is the paperwork a UK ice cream maker is generally expected
                to hold. It is a checklist, not a compliance pack — upload your
                own version of each as you write it.
            </p>
        </div>
    </section>
    <?php else: ?>
        <?php foreach ($byCat as $cat => $rows): if ($rows === []) continue; ?>
        <section class="cbdoc-panel">
            <h2 class="cbdoc-panel-title"><?= $h($cat) ?>
                <span class="cbdoc-count"><?= count($rows) ?></span>
            </h2>
            <div class="cbdoc-list">
                <?php foreach ($rows as $d):
                    $state = cbDocReviewState($d['review_due'] ?? null); ?>
                <div class="cbdoc-item" data-id="<?= (int)$d['id'] ?>">
                    <div class="cbdoc-item-icon"><i class="fa-solid <?= $h(cbDocIcon($d['mime'])) ?>" aria-hidden="true"></i></div>
                    <div class="cbdoc-item-main">
                        <div class="cbdoc-item-title"><?= $h($d['title']) ?></div>
                        <?php if (!empty($d['description'])): ?>
                        <div class="cbdoc-item-desc"><?= $h($d['description']) ?></div>
                        <?php endif; ?>
                        <div class="cbdoc-item-meta">
                            <?= $h(cbDocSizeLabel((int)$d['size_bytes'])) ?>
                            · added <?= $h(date('j M Y', strtotime((string)$d['created_at']))) ?>
                            <?php if (!empty($d['uploaded_by'])): ?> by <?= $h($d['uploaded_by']) ?><?php endif; ?>
                            <?php if ($state !== 'none'): ?>
                            · <span class="cbdoc-review is-<?= $h($state) ?>">
                                <?= $state === 'overdue' ? 'review overdue' : ($state === 'soon' ? 'review due soon' : 'reviewed') ?>
                                (<?= $h(date('j M Y', strtotime((string)$d['review_due']))) ?>)
                              </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="cbdoc-item-actions">
                        <a class="btn-sm btn-sm-info" href="document_download.php?id=<?= (int)$d['id'] ?>" target="_blank"
                           title="Open it">
                            <i class="fa-solid fa-eye" aria-hidden="true"></i> View
                        </a>
                        <a class="btn-sm btn-sm-secondary" href="document_download.php?id=<?= (int)$d['id'] ?>&dl=1"
                           title="Download it">
                            <i class="fa-solid fa-download" aria-hidden="true"></i>
                        </a>
                        <button class="btn-sm btn-sm-danger cbdoc-del" data-id="<?= (int)$d['id'] ?>"
                                data-title="<?= $h($d['title']) ?>" title="Delete">
                            <i class="fa-solid fa-trash" aria-hidden="true"></i>
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endforeach; ?>
    <?php endif; ?>

    <!-- ── The suggested set ──────────────────────────────── -->
    <section class="cbdoc-panel">
        <h2 class="cbdoc-panel-title"><i class="fa-solid fa-clipboard-check" aria-hidden="true"></i>
            What a UK ice cream business is usually expected to hold
        </h2>
        <p class="cbdoc-panel-note">
            A checklist to work through, not a compliance pack. Each of these has
            to be made true of <em>this</em> shop before it is worth anything —
            an inspector asks what actually happens, not what a document says.
            The Food Standards Agency's <em>Safer Food, Better Business</em> pack
            is the usual starting point, and SALSA or BRCGS matter only if a
            wholesale customer asks for certification.
        </p>

        <?php
        $starter = cbDocStarterSet();
        $have    = [];
        foreach ($docs as $d) { $have[strtolower(trim((string)$d['title']))] = true; }
        $grouped = [];
        foreach ($starter as [$cat, $title, $blurb]) { $grouped[$cat][] = [$title, $blurb]; }
        ?>

        <?php foreach ($grouped as $cat => $items): ?>
        <div class="cbdoc-starter-group">
            <h3 class="cbdoc-starter-cat"><?= $h($cat) ?></h3>
            <?php foreach ($items as [$title, $blurb]):
                $held = isset($have[strtolower($title)]); ?>
            <div class="cbdoc-starter <?= $held ? 'is-held' : '' ?>">
                <div class="cbdoc-starter-mark">
                    <i class="fa-solid <?= $held ? 'fa-circle-check' : 'fa-circle' ?>" aria-hidden="true"></i>
                </div>
                <div>
                    <div class="cbdoc-starter-title"><?= $h($title) ?><?= $held ? ' — you have this' : '' ?></div>
                    <div class="cbdoc-starter-blurb"><?= $h($blurb) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endforeach; ?>

        <p class="cbdoc-footnote">
            Nothing here is legal advice. Your local authority's environmental
            health team is the authority on what this shop specifically needs,
            and they would rather answer a question than write an improvement
            notice.
        </p>
    </section>

    <?php endif; ?>
</div>
</div><!-- /admin-shell -->

<script src="<?= cbAsset('../assets/js/modal.js') ?>"></script>
<script src="<?= cbAsset('assets/js/documents.js') ?>"></script>
</body>
</html>
