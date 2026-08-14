<?php
// ============================================================
//  Creamy Bite – Gallery Page
// ============================================================
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/config.php';

$gallery = [];
try {
    $gallery = $pdo->query("SELECT * FROM gallery ORDER BY sort_order ASC, created_at DESC")->fetchAll();
} catch (PDOException $e) { }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gallery – <?= SHOP_NAME ?></title>
<?php require __DIR__ . '/../includes/seo_head.php'; ?>
    <meta name="description" content="Browse our gallery of handcrafted ice cream and cocoa drinks at <?= SHOP_NAME ?>.">
    <link rel="stylesheet" href="<?= cbAsset('../assets/css/style.css') ?>">
    <link rel="stylesheet" href="<?= cbAsset('../assets/css/responsive.css') ?>">
    <link rel="stylesheet" href="<?= cbAsset('../assets/css/animations.css') ?>">
    <link rel="stylesheet" href="<?= cbAsset('../assets/css/components.css') ?>">
    <link rel="stylesheet" href="<?= cbAsset('../assets/css/modal.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>

<!-- ══ Navbar ══════════════════════════════════════════════ -->
<?php
$cbNavActive = 'gallery';
ob_start(); ?>
<a href="order.php" class="btn-primary cb-gal-cta">
    <i class="fa-solid fa-bolt"></i> Order Now
</a>
<?php $cbNavRight = ob_get_clean();
ob_start(); ?>
<a href="order.php" class="btn-primary cb-gal-drawer-cta">
    <i class="fa-solid fa-bolt"></i> Order Now
</a>
<?php $cbNavDrawerRight = ob_get_clean();
require __DIR__ . '/../includes/site_header.php';
?>

<!-- ══ Gallery Hero ════════════════════════════════════════ -->
<section class="about-hero">
    <div class="container cb-gal-layer" >
        <div class="about-hero-eyebrow"><i class="fa-solid fa-camera"></i> Our Gallery</div>
        <h1>A Feast for the Eyes</h1>
        <p>A glimpse into our world of handcrafted ice cream and cocoa drinks, captured beautifully.</p>
    </div>
</section>

<!-- ══ Gallery Grid ════════════════════════════════════════ -->
<section class="gallery-page">
    <div class="container">

        <?php if (empty($gallery)): ?>
        <div class="gallery-empty">
            <div class="gallery-empty-icon"><i class="fa-solid fa-camera-retro"></i></div>
            <p class="cb-gal-lead">No photos yet — check back soon!</p>
            <a href="order.php" class="btn-primary">
                <i class="fa-solid fa-arrow-right"></i> Browse Our Menu
            </a>
        </div>
        <?php else: ?>
        <div class="gallery-grid" id="galleryGrid">
            <?php foreach ($gallery as $img): ?>
            <div class="gallery-item" onclick="openLightbox('../assets/images/gallery/<?= htmlspecialchars($img['filename']) ?>', '<?= addslashes(htmlspecialchars($img['caption'])) ?>')">
                <img
                    src="../assets/images/gallery/<?= htmlspecialchars($img['filename']) ?>"
                    alt="<?= htmlspecialchars($img['caption'] ?: 'Creamy Bite') ?>"
                    loading="lazy">
                <div class="gallery-item-overlay">
                    <?php if (!empty($img['caption'])): ?>
                    <span class="gallery-item-caption"><?= htmlspecialchars($img['caption']) ?></span>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

    </div>
</section>

<!-- ══ Lightbox ════════════════════════════════════════════ -->
<div class="lightbox-overlay" id="lightbox" onclick="closeLightbox()">
    <div class="lightbox-inner" onclick="event.stopPropagation()">
        <button class="lightbox-close" onclick="closeLightbox()">
            <i class="fa-solid fa-xmark"></i>
        </button>
        <img src="" alt="" id="lightboxImg">
        <p class="lightbox-caption" id="lightboxCaption"></p>
    </div>
</div>

<!-- ══ Footer ══════════════════════════════════════════════ -->
<?php // One shared footer — it used to be copied into five pages, so adding a
      // link meant editing all five and hoping none were missed. ?>
<?php require __DIR__ . '/../includes/site_footer.php'; ?>

<script>
function openLightbox(src, caption) {
    document.getElementById('lightboxImg').src = src;
    document.getElementById('lightboxCaption').textContent = caption || '';
    document.getElementById('lightbox').classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeLightbox() {
    document.getElementById('lightbox').classList.remove('open');
    document.body.style.overflow = '';
    setTimeout(() => { document.getElementById('lightboxImg').src = ''; }, 350);
}
// Close on Escape. closeMobileMenu() itself now lives in includes/site_header.php,
// shared by every page — this just also closes the lightbox on the same key.
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') { closeLightbox(); closeMobileMenu(); }
});
</script>
<script src="<?= cbAsset('../assets/js/modal.js') ?>" defer></script>
<script src="<?= cbAsset('../assets/js/animations.js') ?>" defer></script>
</body>
</html>
