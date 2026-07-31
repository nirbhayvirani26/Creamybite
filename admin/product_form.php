<?php
// ============================================================
//  Creamy Bite – Admin: Add / Edit Product Form
// ============================================================
session_start();
if (empty($_SESSION['admin_logged_in'])) {
    header('Location: login.php'); exit;
}

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

$isEdit  = isset($_GET['id']) || (isset($_POST['product_id']) && (int)$_POST['product_id'] > 0);
$product = null;
$errors  = [];

// ── Load existing product for edit ───────────────────────
if (isset($_GET['id'])) {
    $stmt = $pdo->prepare("SELECT * FROM products WHERE id = :id");
    $stmt->execute(['id' => (int)$_GET['id']]);
    $product = $stmt->fetch();
    if (!$product) { header('Location: index.php?tab=products'); exit; }
}

// ── Compute current in-stock for display ─────────────────
$displayInStock = null;
if ($product && !empty($product['track_stock'])) {
    $ts  = (int)($product['total_stock']  ?? $product['stock_qty'] ?? 0);
    $dmg = (int)($product['damage_stock'] ?? 0);
    $off = (int)($product['sold_offline'] ?? 0);
    $sol = (int)($product['sold_online']  ?? 0);
    $displayInStock = max(0, $ts - $dmg - $off - $sol);
}

// ── Load categories from DB ──────────────────────────────
$categories = [];
try {
    $cats = $pdo->query("SELECT * FROM categories ORDER BY sort_order ASC, name ASC")->fetchAll();
    foreach ($cats as $c) { $categories[] = $c['name']; }
} catch (PDOException $e) {
    $categories = ['Ice Cream', 'Cocoa Drink'];
}
$badges = ['', 'New', 'Hot', 'Best Seller'];

// ── Load existing variants for edit ──────────────────────
$existingVariants = [];
if (isset($_GET['id']) || (isset($_POST['product_id']) && (int)$_POST['product_id'] > 0)) {
    $editPid = (int)($_GET['id'] ?? $_POST['product_id'] ?? 0);
    if ($editPid > 0) {
        try {
            $vRows = $pdo->prepare("SELECT * FROM product_variants WHERE product_id = :pid ORDER BY sort_order ASC, id ASC");
            $vRows->execute(['pid' => $editPid]);
            $existingVariants = $vRows->fetchAll();
        } catch (PDOException $e) { }
    }
}

// ── Handle form submission ────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $productId   = (int)($_POST['product_id'] ?? 0);
    $name        = trim($_POST['name']        ?? '');
    $description = trim($_POST['description'] ?? '');
    $price           = (float)($_POST['price']           ?? 0);
    $wholesale_price = (float)($_POST['wholesale_price'] ?? 0);
    $category        = trim($_POST['category']           ?? '');
    $emoji           = trim($_POST['emoji']              ?? '🍦');
    $badge           = trim($_POST['badge']              ?? '');
    $available       = isset($_POST['available'])        ? 1 : 0;
    $nuts_allergy    = isset($_POST['nuts_allergy'])     ? 1 : 0;
    $trade_only      = isset($_POST['trade_only'])       ? 1 : 0;
    $track_stock     = isset($_POST['track_stock'])      ? 1 : 0;
    $stock_qty       = max(0, (int)($_POST['stock_qty']  ?? 0));

    // Keep existing image unless a new one is uploaded.
    // Read from the hidden field so we don't lose it when $product is null on POST.
    //
    // basename() is essential: this value is later concatenated onto the
    // uploads directory and passed to unlink(). Without it, posting
    // existing_image=../../secrets.php would delete an arbitrary file
    // anywhere the web user can write.
    $imageName = basename(trim($_POST['existing_image'] ?? ($product['image'] ?? '')));

    // Handle image upload
    if (!empty($_FILES['product_image']['name'])) {
        $file    = $_FILES['product_image'];
        $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        $finfo   = finfo_open(FILEINFO_MIME_TYPE);
        $mime    = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mime, $allowed)) {
            $errors[] = 'Image must be JPG, PNG, WebP, or GIF.';
        } elseif ($file['size'] > 8 * 1024 * 1024) {
            $errors[] = 'Image file too large (max 8MB).';
        } else {
            // Extension from the verified mime type, never from the uploaded
            // filename — otherwise an image-shaped file named "evil.php" is
            // saved as .php in a web-reachable directory and executes.
            $extByMime = [
                'image/jpeg' => 'jpg',
                'image/png'  => 'png',
                'image/webp' => 'webp',
                'image/gif'  => 'gif',
            ];
            $ext      = $extByMime[$mime];
            $filename = 'product_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $destDir  = __DIR__ . '/../assets/images/products/';
            if (!is_dir($destDir)) { mkdir($destDir, 0755, true); }

            if (move_uploaded_file($file['tmp_name'], $destDir . $filename)) {
                // Delete old image if replacing
                if (!empty($imageName) && file_exists($destDir . $imageName)) {
                    unlink($destDir . $imageName);
                }
                $imageName = $filename;
            } else {
                $errors[] = 'Failed to save image file.';
            }
        }
    }

    // Validate
    if (strlen($name) < 2)        $errors[] = 'Product name is required.';
    if (strlen($description) < 5) $errors[] = 'Description is required.';
    if ($price <= 0)               $errors[] = 'Price must be greater than 0.';
    if (empty($category))         $errors[] = 'Please select a category.';

    if (empty($errors)) {
        try {
            if ($productId > 0) {
                // UPDATE
                $stmt = $pdo->prepare("UPDATE products SET name=:name, description=:description, price=:price, wholesale_price=:wholesale_price,
                    category=:category, emoji=:emoji, image=:image, badge=:badge, available=:available, nuts_allergy=:nuts_allergy,
                    trade_only=:trade_only, track_stock=:track_stock, stock_qty=:stock_qty
                    WHERE id=:id");
                $stmt->execute(compact('name','description','price','wholesale_price','category','emoji','badge','available') + ['image' => $imageName, 'nuts_allergy' => $nuts_allergy, 'trade_only' => $trade_only, 'track_stock' => $track_stock, 'stock_qty' => $stock_qty, 'id' => $productId]);
                header('Location: index.php?tab=products&product_updated=1'); exit;
            } else {
                // INSERT
                $stmt = $pdo->prepare("INSERT INTO products (name, description, price, wholesale_price, category, emoji, image, badge, available, nuts_allergy, trade_only, track_stock, stock_qty)
                    VALUES (:name, :description, :price, :wholesale_price, :category, :emoji, :image, :badge, :available, :nuts_allergy, :trade_only, :track_stock, :stock_qty)");
                $stmt->execute(compact('name','description','price','wholesale_price','category','emoji','badge','available') + ['image' => $imageName, 'nuts_allergy' => $nuts_allergy, 'trade_only' => $trade_only, 'track_stock' => $track_stock, 'stock_qty' => $stock_qty]);
                header('Location: index.php?tab=products&product_added=1'); exit;
            }
        } catch (PDOException $e) {
            $errors[] = 'Database error: ' . $e->getMessage();
        }
    }

    // Re-populate if errors
    $product = [
        'id' => $productId, 'name' => $name, 'description' => $description,
        'price' => $price, 'category' => $category, 'emoji' => $emoji,
        'image' => $imageName, 'badge' => $badge, 'available' => $available,
        'nuts_allergy' => $nuts_allergy,
        'track_stock' => $track_stock,
        'stock_qty'   => 0, // managed via Stock tab
    ];
    $isEdit = $productId > 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $isEdit ? 'Edit Product' : 'Add Product' ?> – <?= SHOP_NAME ?></title>
    <link rel="stylesheet" href="../assets/css/style.css">
    <link rel="stylesheet" href="../assets/css/responsive.css">
    <!-- This page's own cbpf-* layout classes live in admin.css. -->
    <link rel="stylesheet" href="assets/css/admin.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<?php include __DIR__ . '/_csrf_js.php'; ?>
<link rel="stylesheet" href="../assets/css/modal.css">
<script src="../assets/js/modal.js" defer></script>
</head>
<body>

<header class="navbar">
    <div class="container nav-container">
        <a href="../index.php" class="logo"><span class="logo-emoji">🍦</span> <?= SHOP_NAME ?></a>
        <nav><ul class="nav-links">
            <li><a href="index.php?tab=orders">Orders</a></li>
            <li><a href="index.php?tab=products" class="active">Products</a></li>
            <li><a href="index.php?tab=gallery">Gallery</a></li>
            <li><a href="index.php?tab=categories">Categories</a></li>
        </ul></nav>
        <div class="nav-actions">
            <a href="logout.php" class="btn-secondary cbpf-nav-btn">
                <i class="fa-solid fa-right-from-bracket"></i> Logout
            </a>
        </div>
    </div>
</header>

<main class="cbpf-main">
    <div class="container cbpf-page-wrap">

        <div class="admin-page-header">
            <div>
                <h1 class="admin-page-title"><?= $isEdit ? '✏️ Edit Product' : '➕ Add New Product' ?></h1>
                <p class="admin-page-subtitle"><?= $isEdit ? 'Update product details and image' : 'Add a new item to your menu' ?></p>
            </div>
            <a href="index.php?tab=products" class="btn-secondary cbpf-back-btn">
                <i class="fa-solid fa-arrow-left"></i> Back
            </a>
        </div>

        <?php if (!empty($errors)): ?>
        <div class="alert alert-danger cbpf-form-alert">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <div><?php foreach ($errors as $e) echo '<div>'. htmlspecialchars($e) .'</div>'; ?></div>
        </div>
        <?php endif; ?>

        <form action="product_form.php" method="POST" enctype="multipart/form-data">
        <?= csrfField() ?>
            <?php if ($isEdit): ?>
            <input type="hidden" name="product_id" value="<?= (int)$product['id'] ?>">
            <input type="hidden" name="existing_image" value="<?= htmlspecialchars($product['image'] ?? '') ?>">
            <?php endif; ?>

            <div class="product-form-grid cbpf-form-grid">

                <!-- ── Form Fields ───────────────────────── -->
                <div>
                    <div class="glass-panel form-section">
                        <h3><i class="fa-solid fa-circle-info"></i> Basic Info</h3>

                        <div class="form-group">
                            <label class="form-label">Product Name *</label>
                            <input type="text" name="name" class="form-control"
                                placeholder="e.g. Classic Vanilla Scoop"
                                value="<?= htmlspecialchars($product['name'] ?? '') ?>" required
                                oninput="updatePreviewName(this.value)">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Description *</label>
                            <textarea name="description" class="form-control" rows="3"
                                placeholder="Describe the flavour, ingredients, texture…" required><?= htmlspecialchars($product['description'] ?? '') ?></textarea>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Retail Price (£) *</label>
                                <input type="number" name="price" class="form-control"
                                    step="0.01" min="0.01" placeholder="e.g. 6.99"
                                    value="<?= htmlspecialchars($product['price'] ?? '') ?>" required
                                    oninput="updatePreviewPrice(this.value)">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Wholesale Price (£) <small class="cbpf-label-accent">(Trade B2B)</small></label>
                                <input type="number" name="wholesale_price" class="form-control"
                                    step="0.01" min="0.00" placeholder="e.g. 4.50"
                                    value="<?= htmlspecialchars($product['wholesale_price'] ?? '0.00') ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label">Category *</label>
                                <select name="category" class="form-control" required>
                                    <option value="">— Select —</option>
                                    <?php foreach ($categories as $cat): ?>
                                    <option value="<?= $cat ?>" <?= ($product['category'] ?? '') === $cat ? 'selected' : '' ?>>
                                        <?= $cat ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Badge</label>
                                <select name="badge" class="form-control">
                                    <?php foreach ($badges as $b): ?>
                                    <option value="<?= $b ?>" <?= ($product['badge'] ?? '') === $b ? 'selected' : '' ?>>
                                        <?= $b ?: '— None —' ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group cbpf-check-group">
                                <label class="cbpf-check-label">
                                    <input type="checkbox" name="available" value="1" class="cbpf-checkbox"
                                        <?= ($product['available'] ?? 1) ? 'checked' : '' ?>>
                                    Available on menu
                                </label>
                            </div>
                            <div class="form-group cbpf-check-group">
                                <label class="cbpf-check-label cbpf-check-label-nuts">
                                    <input type="checkbox" name="nuts_allergy" value="1" class="cbpf-checkbox cbpf-checkbox-nuts"
                                        <?= !empty($product['nuts_allergy']) ? 'checked' : '' ?>>
                                    🥜 Contains Nuts
                                </label>
                            </div>
                            <div class="form-group cbpf-group-full">
                                <label class="cbpf-trade-label">
                                    <input type="checkbox" name="trade_only" value="1" class="cbpf-checkbox cbpf-checkbox-nudge"
                                        <?= !empty($product['trade_only']) ? 'checked' : '' ?>>
                                    <span>
                                        🏪 Trade customers only
                                        <small class="cbpf-trade-note">
                                            Hides this product from the public website and the home page. Only logged-in
                                            trade partners can see or order it.
                                        </small>
                                    </span>
                                </label>
                            </div>
                        </div>
                    </div>
                    <!-- Stock Management -->
                    <div class="form-row cbpf-stock-row">
                        <div class="form-group cbpf-check-group">
                            <label class="cbpf-check-label">
                                <input type="checkbox" name="track_stock" value="1" id="trackStockCb" class="cbpf-checkbox"
                                    <?= !empty($product['track_stock']) ? 'checked' : '' ?>>
                                📦 Track Stock
                            </label>
                            <?php if ($displayInStock !== null): ?>
                            <span class="cbpf-stock-pill">
                                🟢 In Stock: <?= $displayInStock ?>
                            </span>
                            <?php endif; ?>
                        </div>
                        <!-- Stock quantity is managed via the Stock tab, not here -->
                        <input type="hidden" name="stock_qty" value="<?= (int)($product['stock_qty'] ?? 0) ?>">
                    </div>

                    <!-- ── Image Upload ───────────────────── -->
                    <div class="glass-panel form-section">
                        <h3><i class="fa-solid fa-image"></i> Product Image</h3>

                        <?php if (!empty($product['image'])): ?>
                        <div class="cbpf-current-image">
                            <p class="cbpf-current-image-label">Current image:</p>
                            <img src="../assets/images/products/<?= htmlspecialchars($product['image']) ?>"
                                 alt="Current product image"
                                 class="image-current-thumb cbpf-current-thumb"
                                 id="previewCurrentImg">
                        </div>
                        <?php endif; ?>

                        <div class="image-upload-box" id="uploadBox">
                            <input type="file" name="product_image" accept="image/jpeg,image/png,image/webp,image/gif"
                                id="productImageInput" onchange="previewUpload(this)">
                            <div class="image-upload-icon">📷</div>
                            <p class="image-upload-label">
                                <strong>Click to upload</strong> or drag & drop<br>
                                JPG, PNG, WebP, or GIF — max 8MB
                            </p>
                        </div>

                        <img src="" alt="New image preview" id="newImgPreview" class="cbpf-new-preview">

                        <!-- Fallback emoji (shown if no image) -->
                        <div class="form-group cbpf-emoji-group">
                            <label class="form-label cbpf-emoji-label">Emoji (shown if no image)</label>
                            <input type="text" name="emoji" id="emojiInput" class="form-control cbpf-emoji-input"
                                value="<?= htmlspecialchars($product['emoji'] ?? '🍦') ?>">
                        </div>
                    </div>

                    <button type="submit" class="btn-primary cbpf-submit-btn">
                        <i class="fa-solid fa-<?= $isEdit ? 'floppy-disk' : 'plus' ?>"></i>
                        <?= $isEdit ? 'Save Changes' : 'Add Product' ?>
                    </button>
                </div>

                <!-- ── Live Preview ───────────────────────── -->
                <div class="cbpf-preview-col">
                    <div class="glass-panel preview-card">
                        <p class="cbpf-preview-title">Live Preview</p>
                        <div class="preview-img-wrap" id="previewWrap">
                            <?php if (!empty($product['image'])): ?>
                            <img src="../assets/images/products/<?= htmlspecialchars($product['image']) ?>" alt="Preview" id="previewImg">
                            <?php else: ?>
                            <span id="previewEmoji" class="cbpf-preview-emoji"><?= htmlspecialchars($product['emoji'] ?? '🍦') ?></span>
                            <?php endif; ?>
                        </div>
                        <h3 id="previewName" class="cbpf-preview-name"><?= htmlspecialchars($product['name'] ?? 'Product Name') ?></h3>
                        <div id="previewPrice" class="cbpf-preview-price">
                            £<?= number_format((float)($product['price'] ?? 0), 2) ?>
                        </div>
                    </div>
                </div>
            </div>
        </form>

        <?php if ($isEdit && !empty($product['id'])): ?>
        <!-- ── Variants / Sizes Section ─────────────────── -->
        <div class="glass-panel form-section cbpf-variants-panel">
            <h3 class="cbpf-variants-heading">
                <i class="fa-solid fa-tags cbpf-icon-accent"></i>
                Variants / Sizes
                <span class="cbpf-variants-hint">Optional — e.g. 500ml, 1L, Small, Large</span>
            </h3>
            <p class="cbpf-variants-intro">
                Add size options with different prices. If no variants, the base price above is used.
            </p>

            <div id="variantsList">
                <?php foreach ($existingVariants as $v): ?>
                <div class="variant-row cbpf-variant-row" id="vrow-<?= $v['id'] ?>">
                    <i class="fa-solid fa-grip-vertical cbpf-variant-grip"></i>
                    <input type="text" value="<?= htmlspecialchars($v['name']) ?>" placeholder="Size name (e.g. 500ml)"
                        class="form-control cbpf-variant-name"
                        onchange="updateVariant(<?= $v['id'] ?>, this.value, document.getElementById('vp-<?= $v['id'] ?>').value, document.getElementById('vwp-<?= $v['id'] ?>').value, document.getElementById('va-<?= $v['id'] ?>').checked)">
                    <div class="cbpf-price-field">
                        <span class="cbpf-price-label">Retail: £</span>
                        <input type="number" id="vp-<?= $v['id'] ?>" value="<?= number_format($v['price'], 2) ?>" step="0.01" min="0.01" placeholder="Retail"
                            class="form-control cbpf-price-input"
                            onchange="updateVariant(<?= $v['id'] ?>, document.querySelector('#vrow-<?= $v['id'] ?> input[type=text]').value, this.value, document.getElementById('vwp-<?= $v['id'] ?>').value, document.getElementById('va-<?= $v['id'] ?>').checked)">
                    </div>
                    <div class="cbpf-price-field">
                        <span class="cbpf-price-label cbpf-price-label-trade">Trade: £</span>
                        <input type="number" id="vwp-<?= $v['id'] ?>" value="<?= number_format($v['wholesale_price'] ?? 0, 2) ?>" step="0.01" min="0.00" placeholder="Trade"
                            class="form-control cbpf-price-input cbpf-price-input-trade"
                            onchange="updateVariant(<?= $v['id'] ?>, document.querySelector('#vrow-<?= $v['id'] ?> input[type=text]').value, document.getElementById('vp-<?= $v['id'] ?>').value, this.value, document.getElementById('va-<?= $v['id'] ?>').checked)">
                    </div>
                    <label class="cbpf-variant-avail">
                        <input type="checkbox" id="va-<?= $v['id'] ?>" <?= $v['available'] ? 'checked' : '' ?>
                            onchange="updateVariant(<?= $v['id'] ?>, document.querySelector('#vrow-<?= $v['id'] ?> input[type=text]').value, document.getElementById('vp-<?= $v['id'] ?>').value, document.getElementById('vwp-<?= $v['id'] ?>').value, this.checked)">
                        Available
                    </label>
                    <button type="button" class="btn-danger cbpf-variant-delete" onclick="deleteVariant(<?= $v['id'] ?>, <?= (int)$product['id'] ?>)">
                        <i class="fa-solid fa-trash"></i>
                    </button>
                </div>
                <?php endforeach; ?>
            </div>

            <div class="cbpf-variant-add">
                <i class="fa-solid fa-plus-circle cbpf-icon-accent cbpf-variant-add-icon"></i>
                <input type="text" id="newVariantName" placeholder="Size name (e.g. 500ml, 1L, Small)" class="form-control cbpf-newvariant-name">
                <div class="cbpf-price-field">
                    <span class="cbpf-price-label">Retail: £</span>
                    <input type="number" id="newVariantPrice" placeholder="0.00" step="0.01" min="0.01" class="form-control cbpf-price-input">
                </div>
                <div class="cbpf-price-field">
                    <span class="cbpf-price-label cbpf-price-label-trade">Trade: £</span>
                    <input type="number" id="newVariantWholesalePrice" placeholder="0.00" step="0.01" min="0.00" class="form-control cbpf-price-input">
                </div>
                <button type="button" class="btn-primary cbpf-add-variant-btn" onclick="addVariant(<?= (int)$product['id'] ?>)">
                    <i class="fa-solid fa-plus"></i> Add Variant
                </button>
            </div>
            <p class="cbpf-variants-foot">
                <i class="fa-solid fa-circle-info"></i>
                Variants are saved instantly. Customers must select a size when ordering a product that has variants.
            </p>
        </div>
        <?php endif; ?>

    </div>
</main>

<footer class="footer">
    <div class="container footer-inner">
        <span class="footer-logo">🍦 <?= SHOP_NAME ?> Admin</span>
        <span class="footer-copy">© <?= date('Y') ?> <?= SHOP_NAME ?></span>
    </div>
</footer>

<script>
function previewUpload(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = e => {
            const newPrev = document.getElementById('newImgPreview');
            newPrev.src = e.target.result;
            newPrev.style.display = 'block';

            // Update the preview card
            const wrap = document.getElementById('previewWrap');
            let img = document.getElementById('previewImg');
            if (!img) {
                img = document.createElement('img');
                img.id = 'previewImg';
                img.alt = 'Preview';
                wrap.innerHTML = '';
                wrap.appendChild(img);
            }
            img.src = e.target.result;
        };
        reader.readAsDataURL(input.files[0]);
    }
}

function updatePreviewName(val) {
    document.getElementById('previewName').textContent = val || 'Product Name';
}

function updatePreviewPrice(val) {
    const p = parseFloat(val) || 0;
    document.getElementById('previewPrice').textContent = '£' + p.toFixed(2);
}

// Drag-and-drop highlight
const box = document.getElementById('uploadBox');
box.addEventListener('dragover', e => { e.preventDefault(); box.classList.add('dragover'); });
box.addEventListener('dragleave', () => box.classList.remove('dragover'));
box.addEventListener('drop', e => {
    e.preventDefault(); box.classList.remove('dragover');
    const input = document.getElementById('productImageInput');
    input.files = e.dataTransfer.files;
    previewUpload(input);
});

// ── Variant Management ────────────────────────────────────────
// The product these variants belong to. variant_handler.php scopes every
// update/delete with "AND product_id = :pid", so this must be the real id —
// sending 0 makes the UPDATE match no rows and silently change nothing.
const PRODUCT_ID = <?= (int)($product['id'] ?? 0) ?>;

function addVariant(productId) {
    const nameEl  = document.getElementById('newVariantName');
    const priceEl = document.getElementById('newVariantPrice');
    const wpEl    = document.getElementById('newVariantWholesalePrice');
    const name    = nameEl.value.trim();
    const price   = parseFloat(priceEl.value);
    const wp      = parseFloat(wpEl.value) || 0;

    if (!name) { alert('Please enter a size name (e.g. 500ml)'); nameEl.focus(); return; }
    if (!price || price <= 0) { alert('Please enter a valid price'); priceEl.focus(); return; }

    fetch('handlers/variant_handler.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=add&product_id=${productId}&name=${encodeURIComponent(name)}&price=${price}&wholesale_price=${wp}`,
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) { alert(data.message || 'Error adding variant'); return; }
        // Append new row to list
        const list = document.getElementById('variantsList');
        const id = data.id;
        const row = document.createElement('div');
        row.className = 'variant-row';
        row.id = 'vrow-' + id;
        row.style.cssText = 'display:flex; align-items:center; gap:12px; padding:12px 16px; background:var(--bg-main); border-radius:var(--radius-sm); margin-bottom:10px; border:1px solid var(--border-light); flex-wrap:wrap;';
        row.innerHTML = `
            <i class="fa-solid fa-grip-vertical" style="color:var(--text-muted); font-size:14px;"></i>
            <input type="text" value="${escHtml(name)}" placeholder="Size name"
                class="form-control" style="flex:1; min-width:120px;"
                onchange="updateVariant(${id}, this.value, document.getElementById('vp-${id}').value, document.getElementById('vwp-${id}').value, document.getElementById('va-${id}').checked)">
            <div style="display:flex; align-items:center; gap:4px;">
                <span style="font-size:12px; font-weight:700; color:var(--text-secondary);">Retail: £</span>
                <input type="number" id="vp-${id}" value="${price.toFixed(2)}" step="0.01" min="0.01" placeholder="Retail"
                    class="form-control" style="width:85px;"
                    onchange="updateVariant(${id}, document.querySelector('#vrow-${id} input[type=text]').value, this.value, document.getElementById('vwp-${id}').value, document.getElementById('va-${id}').checked)">
            </div>
            <div style="display:flex; align-items:center; gap:4px;">
                <span style="font-size:12px; font-weight:700; color:var(--color-primary);">Trade: £</span>
                <input type="number" id="vwp-${id}" value="${wp.toFixed(2)}" step="0.01" min="0.00" placeholder="Trade"
                    class="form-control" style="width:85px; border-color:var(--color-primary-light);"
                    onchange="updateVariant(${id}, document.querySelector('#vrow-${id} input[type=text]').value, document.getElementById('vp-${id}').value, this.value, document.getElementById('va-${id}').checked)">
            </div>
            <label style="display:flex; align-items:center; gap:6px; font-size:13px; color:var(--text-secondary); white-space:nowrap; cursor:pointer;">
                <input type="checkbox" id="va-${id}" checked
                    onchange="updateVariant(${id}, document.querySelector('#vrow-${id} input[type=text]').value, document.getElementById('vp-${id}').value, document.getElementById('vwp-${id}').value, this.checked)">
                Available
            </label>
            <button type="button" class="btn-danger" style="padding:6px 12px; flex-shrink:0;" onclick="deleteVariant(${id}, ${productId})">
                <i class="fa-solid fa-trash"></i>
            </button>`;
        list.appendChild(row);
        nameEl.value  = '';
        priceEl.value = '';
        wpEl.value    = '';
        nameEl.focus();
    })
    .catch(() => alert('Network error. Please try again.'));
}

let updateTimer = {};
function updateVariant(id, name, price, wholesalePrice, available) {
    clearTimeout(updateTimer[id]);
    updateTimer[id] = setTimeout(() => {
        fetch('handlers/variant_handler.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=update&id=${id}&product_id=${PRODUCT_ID}&name=${encodeURIComponent(name)}&price=${parseFloat(price)||0}&wholesale_price=${parseFloat(wholesalePrice)||0}&${available ? 'available=1' : ''}`,
        })
        .then(r => r.json())
        .then(data => {
            const row = document.getElementById('vrow-' + id);
            if (row) {
                row.style.borderColor = data.success ? 'rgba(16,185,129,0.4)' : 'rgba(239,68,68,0.4)';
                setTimeout(() => { row.style.borderColor = ''; }, 1200);
            }
        });
    }, 600);
}

function deleteVariant(id, productId) {
    if (!confirm('Remove this variant? This cannot be undone.')) return;
    fetch('handlers/variant_handler.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=delete&id=${id}&product_id=${productId}`,
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const row = document.getElementById('vrow-' + id);
            if (row) { row.style.opacity = '0'; row.style.transition = 'opacity 0.3s'; setTimeout(() => row.remove(), 300); }
        }
    });
}

function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

</script>
</body>
</html>
