<?php
// ============================================================
//  Creamy Bite – Admin: Variant Handler
//  Actions: list | add | update | delete
// ============================================================
$GLOBALS['ADMIN_GUARD_JSON'] = true;   // reply in JSON, not a redirect
require_once __DIR__ . '/../_guard.php';
header('Content-Type: application/json');
csrfCheckJson();

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';

$action    = $_POST['action'] ?? $_GET['action'] ?? '';
$productId = (int)($_POST['product_id'] ?? $_GET['product_id'] ?? 0);

// ── LIST all variants for a product ──────────────────────────
if ($action === 'list') {
    if ($productId <= 0) { echo json_encode(['success' => false]); exit; }
    $rows = $pdo->prepare("SELECT * FROM product_variants WHERE product_id = :pid ORDER BY sort_order ASC, id ASC");
    $rows->execute(['pid' => $productId]);
    echo json_encode(['success' => true, 'variants' => $rows->fetchAll()]);
    exit;
}

// ── ADD variant ───────────────────────────────────────────────
if ($action === 'add') {
    $name           = trim($_POST['name']  ?? '');
    $price          = (float)($_POST['price'] ?? 0);
    $wholesalePrice = (float)($_POST['wholesale_price'] ?? 0);
    if ($productId <= 0 || strlen($name) < 1 || $price <= 0) {
        echo json_encode(['success' => false, 'message' => 'Name and a valid price are required.']);
        exit;
    }
    $maxOrder = (int)$pdo->prepare("SELECT COALESCE(MAX(sort_order),0) FROM product_variants WHERE product_id = :pid")
        ->execute(['pid' => $productId]) ? $pdo->query("SELECT COALESCE(MAX(sort_order),0) FROM product_variants WHERE product_id = $productId")->fetchColumn() : 0;

    $stmt = $pdo->prepare("INSERT INTO product_variants (product_id, name, price, wholesale_price, sort_order) VALUES (:pid, :name, :price, :wp, :sort_order)");
    $stmt->execute(['pid' => $productId, 'name' => $name, 'price' => $price, 'wp' => $wholesalePrice, 'sort_order' => $maxOrder + 1]);
    echo json_encode(['success' => true, 'id' => $pdo->lastInsertId(), 'name' => $name, 'price' => number_format($price, 2), 'wholesale_price' => number_format($wholesalePrice, 2)]);
    exit;
}

// ── UPDATE variant ───────────────────────────────────────────
if ($action === 'update') {
    $id             = (int)($_POST['id']    ?? 0);
    $name           = trim($_POST['name']   ?? '');
    $price          = (float)($_POST['price'] ?? 0);
    $wholesalePrice = (float)($_POST['wholesale_price'] ?? 0);
    $avail          = isset($_POST['available']) ? 1 : 0;
    if ($id <= 0 || strlen($name) < 1 || $price <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid data.']);
        exit;
    }
    $stmt = $pdo->prepare("UPDATE product_variants SET name=:name, price=:price, wholesale_price=:wp, available=:available WHERE id=:id AND product_id=:pid");
    $stmt->execute(['name' => $name, 'price' => $price, 'wp' => $wholesalePrice, 'available' => $avail, 'id' => $id, 'pid' => $productId]);

    // Report a no-op as a failure. MySQL also returns 0 affected rows when the
    // submitted values are identical to what is stored, so confirm the row is
    // actually gone/mismatched before calling it an error.
    if ($stmt->rowCount() === 0) {
        $exists = $pdo->prepare("SELECT COUNT(*) FROM product_variants WHERE id = :id AND product_id = :pid");
        $exists->execute(['id' => $id, 'pid' => $productId]);
        if ((int)$exists->fetchColumn() === 0) {
            echo json_encode(['success' => false, 'message' => 'Variant not found for this product — nothing was saved.']);
            exit;
        }
    }
    echo json_encode(['success' => true, 'price' => number_format($price, 2), 'wholesale_price' => number_format($wholesalePrice, 2)]);
    exit;
}

// ── UPDATE just the case size ────────────────────────────────
//
// Its own action rather than a sixth argument on 'update'. That call is made
// from five different onchange handlers in two places (the server-rendered row
// and the one JavaScript builds), and every one of them passes its arguments
// positionally — widening the signature means changing all five in step or
// silently writing the wrong column. This touches one field and cannot
// disturb a price.
if ($action === 'update_case') {
    $id   = (int)($_POST['id'] ?? 0);
    $case = trim($_POST['case_size'] ?? '');
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid variant.']);
        exit;
    }
    $stmt = $pdo->prepare("UPDATE product_variants SET case_size=:cs WHERE id=:id AND product_id=:pid");
    $stmt->execute(['cs' => $case, 'id' => $id, 'pid' => $productId]);
    echo json_encode(['success' => true, 'case_size' => $case]);
    exit;
}

// ── DELETE variant ───────────────────────────────────────────
if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) { echo json_encode(['success' => false]); exit; }
    $pdo->prepare("DELETE FROM product_variants WHERE id=:id AND product_id=:pid")
        ->execute(['id' => $id, 'pid' => $productId]);
    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action']);
