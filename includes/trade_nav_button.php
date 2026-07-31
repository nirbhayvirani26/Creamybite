<?php
// ============================================================
//  Creamy Bite – Trade account button for the site header
//
//  Included inside .nav-actions on every public page. Renders nothing
//  at all for retail visitors, so their header is unchanged.
//
//  Usage: include __DIR__ . '/trade_nav_button.php';
//  Requires an open session.
// ============================================================
if (!empty($_SESSION['trade_user'])):
    $tradeNavName = trim((string)($_SESSION['trade_user']['business_name'] ?? 'My Account'));
?>
<a href="<?= SITE_BASE ?>/pages/trade_profile.php" class="trade-nav-btn"
   title="My Trade Account — <?= htmlspecialchars($tradeNavName) ?>"
   aria-label="My trade account">
    <span class="trade-nav-avatar"><i class="fa-solid fa-user"></i></span>
    <span class="trade-nav-text">
        <span class="trade-nav-label">Trade Account</span>
        <span class="trade-nav-name"><?= htmlspecialchars($tradeNavName) ?></span>
    </span>
</a>
<?php endif; ?>
