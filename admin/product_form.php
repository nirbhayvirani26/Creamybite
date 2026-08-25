<?php
// ============================================================
//  Creamy Bite – Admin: Add / Edit Product Form
// ============================================================
require_once __DIR__ . '/../includes/session.php';
if (empty($_SESSION['admin_logged_in'])) {
    header('Location: login.php'); exit;
}
require_once __DIR__ . '/_permissions.php';
require_once __DIR__ . '/../includes/product_icons.php';
adminRequire('products');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/product_spec.php';

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
    $category        = trim($_POST['category']           ?? '');
    $emoji           = trim($_POST['emoji']              ?? '🍦');
    $badge           = trim($_POST['badge']              ?? '');
    $available       = isset($_POST['available'])        ? 1 : 0;
    $nuts_allergy    = isset($_POST['nuts_allergy'])     ? 1 : 0;
    $trade_only      = isset($_POST['trade_only'])       ? 1 : 0;
    $track_stock     = isset($_POST['track_stock'])      ? 1 : 0;
    $stock_qty       = max(0, (int)($_POST['stock_qty']  ?? 0));

    // ── Catalogue / allergen / nutrition specification ──────────
    $case_size            = trim($_POST['case_size']            ?? '');
    $case_qty             = max(0, (int)($_POST['case_qty']      ?? 0));
    $ingredients          = trim($_POST['ingredients']          ?? '');
    $allergen_notes       = trim($_POST['allergen_notes']       ?? '');
    $storage_instructions = trim($_POST['storage_instructions'] ?? '');
    $shelf_life           = trim($_POST['shelf_life']           ?? '');
    $nutrition_basis      = trim($_POST['nutrition_basis']      ?? '') ?: '100 ml';

    // Only slugs we recognise are stored, so a tampered form cannot inject
    // an allergen name that then prints unescaped onto a public sheet.
    $allergenPosted = (array)($_POST['allergens'] ?? []);
    $allergens = implode(',', array_values(array_intersect(
        array_keys(cbAllergens()),
        array_map('strval', $allergenPosted)
    )));

    // The sign-off. Ticking the box stamps the time; clearing it puts the
    // product back to "not reviewed" rather than silently keeping an old
    // date that no longer reflects the current recipe.
    //
    // The date is kept when the box stays ticked across an edit, so routine
    // changes (a price, a photo) do not look like a fresh allergen review.
    if (isset($_POST['allergen_reviewed'])) {
        $existingReview = '';
        if ($productId > 0) {
            $rstmt = $pdo->prepare("SELECT allergen_reviewed_at FROM products WHERE id = ?");
            $rstmt->execute([$productId]);
            $existingReview = (string)($rstmt->fetchColumn() ?: '');
        }
        $allergen_reviewed_at = $existingReview !== '' ? $existingReview : date('Y-m-d H:i:s');
    } else {
        $allergen_reviewed_at = null;
    }

    // Nutrition figures. An empty box stays NULL — never 0 — because 0 g of
    // sugar is a claim and a blank field is not.
    $nutrition = [];
    foreach (array_keys(cbNutritionRows()) as $nkey) {
        $raw = trim((string)($_POST[$nkey] ?? ''));
        $nutrition[$nkey] = ($raw === '') ? null : (float)$raw;
    }

    // Keep nuts_allergy in step with the allergen list so the "Contains Nuts"
    // badge on the order page cannot contradict the allergen sheet.
    $allergenSlugs = $allergens === '' ? [] : explode(',', $allergens);
    if (in_array('nuts', $allergenSlugs, true) || in_array('peanuts', $allergenSlugs, true)) {
        $nuts_allergy = 1;
    }

    // Keep existing image unless a new one is uploaded.
    // Read from the hidden field so we don't lose it when $product is null on POST.
    //
    // basename() is essential: this value is later concatenated onto the
    // uploads directory and passed to unlink(). Without it, posting
    // existing_image=../../secrets.php would delete an arbitrary file
    // anywhere the web user can write.
    $imageName = basename(trim($_POST['existing_image'] ?? ($product['image'] ?? '')));

    // A photo larger than post_max_size never reaches PHP at all: $_POST and
    // $_FILES both arrive EMPTY, so the block below simply does not run and the
    // product saves with no image and nothing said about it. That is the most
    // confusing failure of the lot — the form looks like it worked. Detect it
    // by the one clue available: a POST whose body was thrown away.
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && empty($_FILES)) {
        $limit = ini_get('post_max_size');
        $errors[] = 'That photo was too large for the server to accept (the limit here is '
                  . htmlspecialchars((string)$limit) . '). Nothing was saved. Please try a '
                  . 'smaller image — a photo taken on a phone is often several times the limit.';
    }

    // Handle image upload
    if (!empty($_FILES['product_image']['name'])) {
        $file    = $_FILES['product_image'];
        $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

        // Did the upload actually arrive? An oversized file leaves tmp_name
        // empty, and finfo_file('') is a FATAL error in PHP 8 — the page dies
        // rather than telling anyone what went wrong. Check the error code
        // first and say something useful.
        $upErr = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($upErr !== UPLOAD_ERR_OK || $file['tmp_name'] === '' || !is_uploaded_file($file['tmp_name'])) {
            $errors[] = match ($upErr) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
                    'That photo is too large for the server (limit ' . ini_get('upload_max_filesize')
                    . '). Please use a smaller image.',
                UPLOAD_ERR_PARTIAL   => 'The photo only partly uploaded. Please try again.',
                UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE =>
                    'The server could not save the photo. Please tell your developer — '
                    . 'the uploads folder may not be writable.',
                default              => 'The photo could not be uploaded. Please try again.',
            };
            $mime = null;
        } else {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
        }

        if ($mime === null) {
            // Already explained above; fall through without a second message.
        } elseif (!in_array($mime, $allowed)) {
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
                //
                // wholesale_price is deliberately absent: the form no longer
                // offers it, so posting it would write 0 over whatever trade
                // price the product already had. Leaving the column out of the
                // statement keeps the stored value untouched.
                $stmt = $pdo->prepare("UPDATE products SET name=:name, description=:description, price=:price,
                    category=:category, emoji=:emoji, image=:image, badge=:badge, available=:available, nuts_allergy=:nuts_allergy,
                    trade_only=:trade_only, track_stock=:track_stock, stock_qty=:stock_qty,
                    case_size=:case_size, case_qty=:case_qty, ingredients=:ingredients, allergens=:allergens,
                    allergen_notes=:allergen_notes, allergen_reviewed_at=:allergen_reviewed_at,
                    storage_instructions=:storage_instructions, shelf_life=:shelf_life,
                    nutrition_basis=:nutrition_basis, energy_kj=:energy_kj, energy_kcal=:energy_kcal,
                    fat_g=:fat_g, saturates_g=:saturates_g, carbs_g=:carbs_g, sugars_g=:sugars_g,
                    fibre_g=:fibre_g, protein_g=:protein_g, salt_g=:salt_g
                    WHERE id=:id");
                $stmt->execute(compact('name','description','price','category','emoji','badge','available') + ['image' => $imageName, 'nuts_allergy' => $nuts_allergy, 'trade_only' => $trade_only, 'track_stock' => $track_stock, 'stock_qty' => $stock_qty, 'id' => $productId] + [
                    'case_size' => $case_size, 'case_qty' => $case_qty, 'ingredients' => $ingredients, 'allergens' => $allergens,
                    'allergen_notes' => $allergen_notes, 'allergen_reviewed_at' => $allergen_reviewed_at,
                    'storage_instructions' => $storage_instructions, 'shelf_life' => $shelf_life,
                    'nutrition_basis' => $nutrition_basis,
                ] + $nutrition);
                header('Location: index.php?tab=products&product_updated=1'); exit;
            } else {
                // INSERT — a new product starts with no trade price of its own;
                // trade pricing is set per size on the Sizes panel.
                $stmt = $pdo->prepare("INSERT INTO products (name, description, price, category, emoji, image, badge, available, nuts_allergy, trade_only, track_stock, stock_qty,
                    case_size, case_qty, ingredients, allergens, allergen_notes, allergen_reviewed_at,
                    storage_instructions, shelf_life, nutrition_basis,
                    energy_kj, energy_kcal, fat_g, saturates_g, carbs_g, sugars_g, fibre_g, protein_g, salt_g)
                    VALUES (:name, :description, :price, :category, :emoji, :image, :badge, :available, :nuts_allergy, :trade_only, :track_stock, :stock_qty,
                    :case_size, :case_qty, :ingredients, :allergens, :allergen_notes, :allergen_reviewed_at,
                    :storage_instructions, :shelf_life, :nutrition_basis,
                    :energy_kj, :energy_kcal, :fat_g, :saturates_g, :carbs_g, :sugars_g, :fibre_g, :protein_g, :salt_g)");
                $stmt->execute(compact('name','description','price','category','emoji','badge','available') + ['image' => $imageName, 'nuts_allergy' => $nuts_allergy, 'trade_only' => $trade_only, 'track_stock' => $track_stock, 'stock_qty' => $stock_qty] + [
                    'case_size' => $case_size, 'case_qty' => $case_qty, 'ingredients' => $ingredients, 'allergens' => $allergens,
                    'allergen_notes' => $allergen_notes, 'allergen_reviewed_at' => $allergen_reviewed_at,
                    'storage_instructions' => $storage_instructions, 'shelf_life' => $shelf_life,
                    'nutrition_basis' => $nutrition_basis,
                ] + $nutrition);
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
        'case_size' => $case_size, 'case_qty' => $case_qty, 'ingredients' => $ingredients, 'allergens' => $allergens,
        'allergen_notes' => $allergen_notes, 'allergen_reviewed_at' => $allergen_reviewed_at,
        'storage_instructions' => $storage_instructions, 'shelf_life' => $shelf_life,
        'nutrition_basis' => $nutrition_basis,
    ] + $nutrition;
    $isEdit = $productId > 0;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $isEdit ? 'Edit Product' : 'Add Product' ?> – <?= SHOP_NAME ?></title>
    <?php require __DIR__ . '/../includes/favicon.php'; ?>
    <link rel="stylesheet" href="<?= cbAsset('../assets/css/style.css') ?>">
    <link rel="stylesheet" href="<?= cbAsset('../assets/css/responsive.css') ?>">
    <!-- This page's own cbpf-* layout classes live in admin.css. -->
    <link rel="stylesheet" href="<?= cbAsset('assets/css/admin.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<?php include __DIR__ . '/_csrf_js.php'; ?>
<link rel="stylesheet" href="<?= cbAsset('../assets/css/modal.css') ?>">
<script src="<?= cbAsset('../assets/js/modal.js') ?>" defer></script>
</head>
<body class="admin-wrapper has-sidebar">

<?php
// Replaces a hand-written four-link navbar (Orders, Products, Gallery,
// Categories) that had drifted well out of date with the real menu — it was
// missing Trade Accounts, Invoices, Revenue, Delivery & Offers and the rest.
$cbSidebarCurrent = 'products';
require __DIR__ . '/_sidebar.php';
?>

<div class="admin-shell">
<header class="admin-topbar cbat-toggle-only">
    <button class="sb-toggle" id="sbToggle" aria-label="Open menu" aria-controls="adminSidebar" aria-expanded="false">
        <i class="fa-solid fa-bars"></i>
    </button>
</header>

<main class="cbpf-main">
    <div class="container cbpf-page-wrap">

        <div class="admin-page-header">
            <div>
                <h1 class="admin-page-title"><?= $isEdit
                    ? '<i class="fa-solid fa-pen-to-square" aria-hidden="true"></i> Edit Product'
                    : '<i class="fa-solid fa-plus" aria-hidden="true"></i> Add New Product' ?></h1>
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
                            <?php // The "Contains Nuts" badge on the shop is now driven by the
                                  // allergen list below, not by its own tickbox. Two independent
                                  // switches for one fact is how a product ends up flagged on the
                                  // menu but absent from the allergen sheet — or the reverse. ?>
                            <div class="form-group cbpf-check-group">
                                <span class="cbpf-derived-note">
                                    <?php // Food-safety signal: the icon only decorates it, the
                                          // words "Contains Nuts" stay and carry the meaning. ?>
                                    <i class="fa-solid fa-triangle-exclamation cbpf-warn-icon" aria-hidden="true"></i>
                                    <strong>Contains Nuts</strong> badge is set automatically from
                                    the Allergens panel below.
                                </span>
                            </div>
                            <div class="form-group cbpf-group-full">
                                <label class="cbpf-trade-label">
                                    <input type="checkbox" name="trade_only" value="1" class="cbpf-checkbox cbpf-checkbox-nudge"
                                        <?= !empty($product['trade_only']) ? 'checked' : '' ?>>
                                    <span>
                                        <i class="fa-solid fa-store" aria-hidden="true"></i> Trade customers only
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
                                <i class="fa-solid fa-box" aria-hidden="true"></i> Track Stock
                            </label>
                            <?php if ($displayInStock !== null): ?>
                            <?php // "In Stock" stays in words — the pill's green is not the only cue. ?>
                            <span class="cbpf-stock-pill">
                                <i class="fa-solid fa-circle-check" aria-hidden="true"></i> In Stock: <?= $displayInStock ?>
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
                            <div class="image-upload-icon"><i class="fa-solid fa-camera" aria-hidden="true"></i></div>
                            <p class="image-upload-label">
                                <strong>Click to upload</strong> or drag & drop<br>
                                JPG, PNG, WebP, or GIF — max 8MB
                            </p>
                        </div>

                        <img src="" alt="New image preview" id="newImgPreview" class="cbpf-new-preview">

                        <!-- Fallback icon (shown if no image) -->
                        <?php // Still an emoji field: the emoji is what products.emoji stores and
                              // what the public templates read. The shop draws the matching icon
                              // instead, so the readout below says which one this will be. ?>
                        <div class="form-group cbpf-emoji-group">
                            <label class="form-label cbpf-emoji-label" for="emojiInput">Fallback icon — type an emoji (shown if no image)</label>
                            <input type="text" name="emoji" id="emojiInput" class="form-control cbpf-emoji-input"
                                value="<?= htmlspecialchars($product['emoji'] ?? '🍦') ?>">
                            <span class="cbpf-emoji-resolved">
                                Saved as typed; shown on the shop as
                                <span class="cbpf-emoji-resolved-icon"><?= cbProductIcon($product['emoji'] ?? null) ?></span>
                            </span>
                        </div>
                    </div>

                    <!-- ── Catalogue, allergens, nutrition, storage ────────── -->
                    <?php
                        $selectedAllergens = cbAllergenList($product['allergens'] ?? '');
                        $reviewedAt        = $product['allergen_reviewed_at'] ?? null;
                    ?>
                    <div class="glass-panel form-section">
                        <h3><i class="fa-solid fa-clipboard-list"></i> Catalogue &amp; Product Information</h3>
                        <p class="cbpf-spec-intro">
                            This is what appears on the downloadable catalogue and the allergen,
                            nutrition and storage sheets. Anything left blank is shown as
                            <em>not provided</em> on those documents — never as zero, and never as
                            &ldquo;free from&rdquo;.
                        </p>

                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Units per case</label>
                                <input type="number" min="0" step="1" name="case_qty" class="form-control"
                                       placeholder="0"
                                       value="<?= (int)($product['case_qty'] ?? 0) ?>">
                                <small class="cbpf-field-hint">
                                    Trade baskets add and remove this many at a time — a trade
                                    customer cannot buy a single tub of a product that cases.
                                    Leave <strong>0</strong> if this one is sold singly.
                                    If the product has sizes, set it per size on the Sizes panel
                                    instead: the size's own figure wins.
                                </small>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Case label</label>
                                <input type="text" name="case_size" class="form-control"
                                       placeholder="e.g. 6 &times; 1L per case"
                                       value="<?= htmlspecialchars($product['case_size'] ?? '') ?>">
                                <small class="cbpf-field-hint">
                                    How the case is described on the catalogue. Wording only —
                                    the number above is what the basket counts in.
                                </small>
                            </div>
                            <div class="form-group">
                                <label class="form-label">Shelf life</label>
                                <input type="text" name="shelf_life" class="form-control"
                                       placeholder="e.g. 12 months from production"
                                       value="<?= htmlspecialchars($product['shelf_life'] ?? '') ?>">
                            </div>
                        </div>

                        <div class="form-group cbpf-group-full">
                            <label class="form-label">Ingredients</label>
                            <textarea name="ingredients" class="form-control" rows="3"
                                      placeholder="In descending order by weight, as they appear on the tub."><?= htmlspecialchars($product['ingredients'] ?? '') ?></textarea>
                        </div>

                        <div class="form-group cbpf-group-full">
                            <label class="form-label">Storage instructions</label>
                            <input type="text" name="storage_instructions" class="form-control"
                                   placeholder="<?= htmlspecialchars(CB_DEFAULT_STORAGE) ?>"
                                   value="<?= htmlspecialchars($product['storage_instructions'] ?? '') ?>">
                            <small class="cbpf-field-hint">
                                Leave blank to use the standard wording shown above.
                            </small>
                        </div>

                        <!-- ── Allergens ───────────────────────────────── -->
                        <h4 class="cbpf-spec-heading">
                            <i class="fa-solid fa-triangle-exclamation"></i> Allergens
                        </h4>
                        <p class="cbpf-spec-intro">
                            Tick every allergen this product contains. These are the 14 that UK law
                            requires a food business to declare.
                        </p>

                        <div class="cbpf-allergen-grid">
                            <?php foreach (cbAllergens() as $slug => $label): ?>
                            <label class="cbpf-allergen-option">
                                <input type="checkbox" name="allergens[]" value="<?= htmlspecialchars($slug) ?>"
                                       class="cbpf-checkbox"
                                       <?= in_array($slug, $selectedAllergens, true) ? 'checked' : '' ?>>
                                <span><?= htmlspecialchars($label) ?></span>
                            </label>
                            <?php endforeach; ?>
                        </div>

                        <div class="form-group cbpf-group-full">
                            <label class="form-label">Allergen notes</label>
                            <input type="text" name="allergen_notes" class="form-control"
                                   placeholder="e.g. May contain traces of other tree nuts"
                                   value="<?= htmlspecialchars($product['allergen_notes'] ?? '') ?>">
                        </div>

                        <?php // The sign-off. Until this is ticked the allergen sheet refuses
                              // to say the product is free from anything — it says the
                              // information has not been confirmed and to ask. That is the
                              // difference between "we checked, there are none" and "nobody
                              // has filled this in yet", which are identical in the database
                              // and must never look identical to a customer with an allergy. ?>
                        <div class="form-group cbpf-group-full">
                            <label class="cbpf-review-label">
                                <input type="checkbox" name="allergen_reviewed" value="1" class="cbpf-checkbox"
                                       <?= !empty($reviewedAt) ? 'checked' : '' ?>>
                                <span>
                                    <strong>I have checked this product&rsquo;s allergens against the recipe</strong>
                                    <small class="cbpf-trade-note">
                                        <?php if (!empty($reviewedAt)): ?>
                                            Confirmed <?= htmlspecialchars(date('j M Y', strtotime($reviewedAt))) ?>.
                                            Untick if the recipe has changed and needs checking again.
                                        <?php else: ?>
                                            Until this is ticked, the allergen sheet will show this product as
                                            <strong>not yet confirmed</strong> and tell customers to ask. A product
                                            with no allergens ticked and no confirmation is never printed as
                                            allergen-free.
                                        <?php endif; ?>
                                    </small>
                                </span>
                            </label>
                        </div>

                        <!-- ── Nutrition ───────────────────────────────── -->
                        <h4 class="cbpf-spec-heading">
                            <i class="fa-solid fa-chart-simple"></i> Nutrition
                        </h4>

                        <div class="form-group">
                            <label class="form-label">Figures are per</label>
                            <input type="text" name="nutrition_basis" class="form-control cbpf-basis-input"
                                   value="<?= htmlspecialchars($product['nutrition_basis'] ?? '100 ml') ?>">
                        </div>

                        <div class="cbpf-nutrition-grid">
                            <?php foreach (cbNutritionRows() as $key => [$label, $unit, $indent]): ?>
                            <div class="form-group cbpf-nutrition-field<?= $indent ? ' cbpf-nutrition-indent' : '' ?>">
                                <label class="form-label"><?= htmlspecialchars($label) ?> (<?= htmlspecialchars($unit) ?>)</label>
                                <?php // step="any" so 0.05 g of salt is enterable; blank stays blank. ?>
                                <input type="number" step="any" min="0" name="<?= htmlspecialchars($key) ?>"
                                       class="form-control" placeholder="—"
                                       value="<?= htmlspecialchars((string)($product[$key] ?? '')) ?>">
                            </div>
                            <?php endforeach; ?>
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
                            <span id="previewEmoji" class="cbpf-preview-emoji"><?= cbProductIcon($product['emoji'] ?? null) ?></span>
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
                        <?php
                        // A trade price at or above retail is almost always a
                        // typo, and it silently costs the partner money on
                        // every order until someone notices.
                        $tradeAboveRetail = (float)($v['wholesale_price'] ?? 0) > 0
                                         && (float)$v['wholesale_price'] >= (float)$v['price'];
                        ?>
                        <input type="number" id="vwp-<?= $v['id'] ?>" value="<?= number_format($v['wholesale_price'] ?? 0, 2) ?>" step="0.01" min="0.00" placeholder="Trade"
                            <?= $tradeAboveRetail ? 'title="Trade price is not below the retail price — check this."' : '' ?>
                            class="form-control cbpf-price-input cbpf-price-input-trade<?= $tradeAboveRetail ? ' is-suspect' : '' ?>"
                            onchange="updateVariant(<?= $v['id'] ?>, document.querySelector('#vrow-<?= $v['id'] ?> input[type=text]').value, document.getElementById('vp-<?= $v['id'] ?>').value, this.value, document.getElementById('va-<?= $v['id'] ?>').checked)">
                    </div>
                    <div class="cbpf-price-field">
                        <span class="cbpf-price-label">Case of:</span>
                        <?php // A number, not "6 × 1L": trade baskets add and remove
                              // this many at a time, so it has to be arithmetic.
                              // 0 means this size is sold as singles. ?>
                        <input type="number" min="0" step="1" id="vcs-<?= $v['id'] ?>"
                            value="<?= (int)($v['case_qty'] ?? 0) ?>"
                            placeholder="0" title="Units per case — trade orders move in these steps. 0 = sold singly."
                            class="form-control cbpf-case-input"
                            onchange="updateVariantCase(<?= $v['id'] ?>, <?= (int)$product['id'] ?>, this.value)">
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
        <span class="footer-logo"><i class="fa-solid fa-ice-cream" aria-hidden="true"></i> <?= SHOP_NAME ?> Admin</span>
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

    if (!name) { cbAlert('Please enter a size name, for example 500ml or 1L.', {title:'Size name needed'}); nameEl.focus(); return; }
    if (!price || price <= 0) { cbAlert('Please enter a price greater than zero.', {title:'Price needed'}); priceEl.focus(); return; }

    fetch('handlers/variant_handler.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `action=add&product_id=${productId}&name=${encodeURIComponent(name)}&price=${price}&wholesale_price=${wp}`,
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) { cbAlert(data.message || 'Could not add that size.', {title:'Could not add size', tone:'danger'}); return; }
        // Append new row to list
        const list = document.getElementById('variantsList');
        const id = data.id;
        const row = document.createElement('div');
        // Same classes as the server-rendered rows above, so a size added
        // without a reload is indistinguishable from one that was there when
        // the page loaded.
        row.className = 'variant-row cbpf-variant-row';
        row.id = 'vrow-' + id;
        row.innerHTML = `
            <i class="fa-solid fa-grip-vertical cbpf-variant-grip"></i>
            <input type="text" value="${escHtml(name)}" placeholder="Size name"
                class="form-control cbpf-variant-name"
                onchange="updateVariant(${id}, this.value, document.getElementById('vp-${id}').value, document.getElementById('vwp-${id}').value, document.getElementById('va-${id}').checked)">
            <div class="cbpf-price-field">
                <span class="cbpf-price-label">Retail: £</span>
                <input type="number" id="vp-${id}" value="${price.toFixed(2)}" step="0.01" min="0.01" placeholder="Retail"
                    class="form-control cbpf-price-input"
                    onchange="updateVariant(${id}, document.querySelector('#vrow-${id} input[type=text]').value, this.value, document.getElementById('vwp-${id}').value, document.getElementById('va-${id}').checked)">
            </div>
            <div class="cbpf-price-field">
                <span class="cbpf-price-label cbpf-price-label-trade">Trade: £</span>
                <input type="number" id="vwp-${id}" value="${wp.toFixed(2)}" step="0.01" min="0.00" placeholder="Trade"
                    class="form-control cbpf-price-input cbpf-price-input-trade"
                    onchange="updateVariant(${id}, document.querySelector('#vrow-${id} input[type=text]').value, document.getElementById('vp-${id}').value, this.value, document.getElementById('va-${id}').checked)">
            </div>
            <div class="cbpf-price-field">
                <span class="cbpf-price-label">Case of:</span>
                <input type="number" min="0" step="1" id="vcs-${id}" value="0" placeholder="0"
                    title="Units per case — trade orders move in these steps. 0 = sold singly."
                    class="form-control cbpf-case-input"
                    onchange="updateVariantCase(${id}, ${productId}, this.value)">
            </div>
            <label class="cbpf-variant-avail">
                <input type="checkbox" id="va-${id}" checked
                    onchange="updateVariant(${id}, document.querySelector('#vrow-${id} input[type=text]').value, document.getElementById('vp-${id}').value, document.getElementById('vwp-${id}').value, this.checked)">
                Available
            </label>
            <button type="button" class="btn-danger cbpf-variant-delete" onclick="deleteVariant(${id}, ${productId})">
                <i class="fa-solid fa-trash"></i>
            </button>`;
        list.appendChild(row);
        nameEl.value  = '';
        priceEl.value = '';
        wpEl.value    = '';
        nameEl.focus();
    })
    .catch(err => cbAlert('Could not reach the server: ' + err.message, {title:'Request failed', tone:'danger'}));
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

// Case size saves on its own, so a typo here can never land in a price field.
//
// Note the row now holds two text inputs — the size name and this one. The
// price handlers above find the name with
// `querySelector('#vrow-N input[type=text]')`, which takes the FIRST match, so
// this field must stay after the name input. Anything inserted before the name
// would silently start saving the wrong value as the size name.
function updateVariantCase(id, productId, caseSize) {
    clearTimeout(updateTimer['c' + id]);
    updateTimer['c' + id] = setTimeout(() => {
        fetch('handlers/variant_handler.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=update_case&id=${id}&product_id=${productId}&case_size=${encodeURIComponent(caseSize)}`,
        })
        .then(r => r.json())
        .then(data => {
            const row = document.getElementById('vrow-' + id);
            if (row) {
                row.style.borderColor = data.success ? 'rgba(16,185,129,0.4)' : 'rgba(239,68,68,0.4)';
                setTimeout(() => { row.style.borderColor = ''; }, 1200);
            }
        })
        .catch(() => {});
    }, 600);
}

async function deleteVariant(id, productId) {
    if (!await cbConfirm('Remove this size? Any price set on it is lost.', {title:'Remove size?', tone:'danger', okText:'Remove'})) return;
    
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
</div><!-- /admin-shell -->
</body>
</html>
