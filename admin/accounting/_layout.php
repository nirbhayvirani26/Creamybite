<?php
// ============================================================
//  Creamy Bite – VAT & Accounting: shared page frame
//
//  Every page in this module opens with acctPageStart() and closes with
//  acctPageEnd(). One frame means the sub-navigation, the "not registered
//  yet" state and the "migration not run" state are handled once rather than
//  copied into each page and drifting apart.
//
//  The module lives in its own directory rather than as another tab inside
//  admin/index.php. That file is already 3,300 lines, and an accounting
//  module bolted into it would make both harder to work on — the brief asked
//  for a section that is logically separate, and separate files are what that
//  means in practice.
// ============================================================

require_once __DIR__ . '/../_guard.php';
require_once __DIR__ . '/../_permissions.php';
adminRequire('accounting');

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
require_once __DIR__ . '/../../includes/accounting.php';

/** Money, always with the sign and two places. */
function acctMoney(float $v): string
{
    return ($v < 0 ? '−£' : '£') . number_format(abs($v), 2);
}

/** The module's own sub-navigation. */
function acctNav(): array
{
    return [
        'index'      => ['Dashboard',   'fa-gauge-high'],
        'vat_return' => ['VAT Return',  'fa-file-invoice-dollar'],
        'expenses'   => ['Expenses',    'fa-receipt'],
        'purchases'  => ['Purchases',   'fa-truck-field'],
        'refunds'    => ['Refunds',     'fa-rotate-left'],
        'ledger'     => ['Ledger',      'fa-book'],
        'reports'    => ['Reports',     'fa-chart-column'],
        'settings'   => ['Settings',    'fa-gear'],
    ];
}

function acctPageStart(string $current, string $title, string $subtitle = ''): void
{
    global $pdo;
    $nav = acctNav();
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?> – VAT &amp; Accounting</title>
    <?php require __DIR__ . '/../../includes/favicon.php'; ?>
    <link rel="stylesheet" href="../../assets/css/style.css">
    <?php // admin.css lives under admin/assets/, one level up from here. ?>
    <link rel="stylesheet" href="../assets/css/admin.css">
    <link rel="stylesheet" href="../../assets/css/components.css">
    <link rel="stylesheet" href="../../assets/css/accounting.css">
    <link rel="stylesheet" href="../../assets/css/modal.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="cbac-body">

<div class="cbac-wrap">

    <header class="cbac-head">
        <div>
            <a href="../index.php" class="cbac-back">
                <i class="fa-solid fa-arrow-left"></i> Admin
            </a>
            <h1 class="cbac-title">📊 <?= htmlspecialchars($title) ?></h1>
            <?php if ($subtitle !== ''): ?>
            <p class="cbac-sub"><?= htmlspecialchars($subtitle) ?></p>
            <?php endif; ?>
        </div>
    </header>

    <nav class="cbac-nav">
        <?php foreach ($nav as $file => [$label, $icon]): ?>
        <a href="<?= $file ?>.php" class="cbac-nav-link<?= $file === $current ? ' is-active' : '' ?>">
            <i class="fa-solid <?= $icon ?>"></i> <?= htmlspecialchars($label) ?>
        </a>
        <?php endforeach; ?>
    </nav>

    <?php
    // Two states every page has to handle before showing figures.
    if (!acctInstalled($pdo)):
    ?>
    <div class="cbac-banner is-warn">
        <strong>The accounting tables are not installed yet.</strong>
        Run <a href="../migrations/update_db.php">Update DB</a>, then come back.
    </div>
    <?php
        // Nothing below this can work without the schema.
        acctPageEnd();
        exit;
    endif;

    if (!acctVatEnabled($pdo) && $current !== 'settings'):
    ?>
    <div class="cbac-banner is-info">
        <strong>VAT is switched off.</strong>
        This business is not marked as VAT registered, so no VAT is charged and no
        return is prepared. If you are registered, turn it on in
        <a href="settings.php">Settings</a> — nothing here will show real figures until you do.
    </div>
    <?php endif; ?>
<?php
}

function acctPageEnd(): void
{
    ?>
</div>
<script src="../../assets/js/modal.js" defer></script>
</body>
</html>
<?php
}
