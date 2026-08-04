<?php
// ============================================================
//  Creamy Bite – Admin: Category Handler
//  Actions: add | rename | delete
// ============================================================
$GLOBALS['ADMIN_GUARD_JSON'] = true;   // reply in JSON, not a redirect
require_once __DIR__ . '/../_guard.php';
header('Content-Type: application/json');
csrfCheckJson();
require_once __DIR__ . '/../_permissions.php';
adminRequire('categories');

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';

$action = $_POST['action'] ?? '';

// ── ADD ───────────────────────────────────────────────────────
if ($action === 'add') {
    $name = trim($_POST['name'] ?? '');
    if (strlen($name) < 2) { echo json_encode(['success' => false, 'message' => 'Name too short.']); exit; }

    $maxOrder = $pdo->query("SELECT COALESCE(MAX(sort_order),0) FROM categories")->fetchColumn();
    $newOrder = $maxOrder + 1;
    $stmt = $pdo->prepare("INSERT INTO categories (name, sort_order) VALUES (:name, :sort_order)");
    $stmt->execute(['name' => $name, 'sort_order' => $newOrder]);
    echo json_encode(['success' => true, 'id' => $pdo->lastInsertId(), 'name' => $name, 'sort_order' => $newOrder]);
    exit;
}

// ── RENAME ───────────────────────────────────────────────────
if ($action === 'rename') {
    $id   = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    if ($id <= 0 || strlen($name) < 2) { echo json_encode(['success' => false, 'message' => 'Invalid.']); exit; }
    $pdo->prepare("UPDATE categories SET name=:name WHERE id=:id")->execute(['name' => $name, 'id' => $id]);
    echo json_encode(['success' => true]);
    exit;
}

// ── DELETE ───────────────────────────────────────────────────
if ($action === 'delete') {
    $id = (int)($_POST['id'] ?? 0);
    if ($id <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid ID.']); exit; }

    // Check if any products use this category name
    $catName = $pdo->prepare("SELECT name FROM categories WHERE id = :id");
    $catName->execute(['id' => $id]);
    $cat = $catName->fetch();
    if (!$cat) { echo json_encode(['success' => false, 'message' => 'Not found.']); exit; }

    $count = $pdo->prepare("SELECT COUNT(*) FROM products WHERE category = :cat");
    $count->execute(['cat' => $cat['name']]);
    if ($count->fetchColumn() > 0) {
        echo json_encode(['success' => false, 'message' => 'Cannot delete: products are using this category. Reassign them first.']);
        exit;
    }

    $pdo->prepare("DELETE FROM categories WHERE id = :id")->execute(['id' => $id]);
    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action']);
