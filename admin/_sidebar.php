<?php
// The sidebar markup itself. Split from _sidebar_nav.php so admin/index.php can
// take the $adminNav array early — it needs it before <body> exists — while the
// markup is emitted in place further down the page.
if (!isset($adminNav)) {
    require_once __DIR__ . '/_sidebar_nav.php';
}
$cbSidebarCurrent = $cbSidebarCurrent ?? ($tab ?? '');
?>
<aside class="admin-sidebar" id="adminSidebar">
    <a href="../index.php" class="sb-brand" target="_blank" title="<?= SHOP_NAME ?> — open the shop">
        <img src="../assets/images/logo.png" alt="<?= SHOP_NAME ?>">
    </a>

    <nav class="sb-nav">
        <?php foreach ($adminNav as $item): ?>
            <?php if (isset($item['group'])): ?>
                <div class="sb-section"><?= htmlspecialchars($item['group']) ?></div>
            <?php elseif (isset($item['href'])): ?>
                <?php // An external page rather than a tab in this file. Hidden
                      // entirely from staff who do not hold the permission, so
                      // the sidebar never offers a door that will not open. ?>
                <?php if (empty($item['perm']) || adminCan($item['perm'])): ?>
                <a href="<?= htmlspecialchars($item['href']) ?>" class="sb-link">
                    <i class="fa-solid <?= $item['icon'] ?>"></i>
                    <span class="sb-label"><?= htmlspecialchars($item['label']) ?></span>
                </a>
                <?php endif; ?>
            <?php else: ?>
                <a href="index.php?tab=<?= $item['tab'] ?>"
                   class="sb-link <?= $activeTab === $item['tab'] ? 'active' : '' ?>">
                    <i class="fa-solid <?= $item['icon'] ?>"></i>
                    <span class="sb-label"><?= htmlspecialchars($item['label']) ?></span>
                    <?php if (!empty($item['badge'])): ?>
                    <span class="sb-badge <?= !empty($item['alert']) ? 'alert' : '' ?>"><?= htmlspecialchars($item['badge']) ?></span>
                    <?php endif; ?>
                </a>
            <?php endif; ?>
        <?php endforeach; ?>
    </nav>

    <div class="sb-foot">
        <div class="sb-user">
            <i class="fa-solid fa-user-shield"></i>
            <span><?= htmlspecialchars(adminStaffName()) ?></span>
        </div>
        <a href="../index.php" target="_blank" class="sb-foot-btn shop">
            <i class="fa-solid fa-globe"></i> <span>View Shop</span>
        </a>
        <a href="logout.php" class="sb-foot-btn out">
            <i class="fa-solid fa-right-from-bracket"></i> <span>Logout</span>
        </a>
    </div>
</aside>
<div class="admin-sidebar-backdrop" id="sidebarBackdrop"></div>

<script>
// The drawer, for phones. It travels WITH the markup: leaving it behind in
// admin/index.php's script block is how the other pages would have ended up
// with a hamburger button that did nothing at all.
(function () {
    var sidebar  = document.getElementById('adminSidebar');
    var backdrop = document.getElementById('sidebarBackdrop');
    var toggle   = document.getElementById('sbToggle');
    if (!sidebar || !backdrop || !toggle) { return; }
    if (sidebar.dataset.cbBound === '1') { return; }   // never bind twice
    sidebar.dataset.cbBound = '1';

    function setOpen(open) {
        sidebar.classList.toggle('open', open);
        backdrop.classList.toggle('show', open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        document.body.style.overflow = open ? 'hidden' : '';
    }
    toggle.addEventListener('click', function () {
        setOpen(!sidebar.classList.contains('open'));
    });
    backdrop.addEventListener('click', function () { setOpen(false); });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { setOpen(false); }
    });
})();
</script>
