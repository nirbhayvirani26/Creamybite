<?php
// ============================================================
//  Creamy Bite – Admin: Gallery Handler
//  Handles upload (POST) and delete (GET?action=delete&id=N)
// ============================================================
$GLOBALS['ADMIN_GUARD_JSON'] = true;   // reply in JSON, not a redirect
require_once __DIR__ . '/_guard.php';
header('Content-Type: application/json');
csrfCheckJson();

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ── DELETE ───────────────────────────────────────────────────
if ($action === 'delete') {
    $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
    if ($id <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid ID']); exit; }

    $stmt = $pdo->prepare("SELECT filename FROM gallery WHERE id = :id");
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();

    if ($row) {
        $filePath = __DIR__ . '/../assets/images/gallery/' . $row['filename'];
        if (file_exists($filePath)) { unlink($filePath); }
        $pdo->prepare("DELETE FROM gallery WHERE id = :id")->execute(['id' => $id]);
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Not found']);
    }
    exit;
}

// ── UPLOAD ───────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['gallery_image'])) {
    $caption = trim($_POST['caption'] ?? '');
    $file    = $_FILES['gallery_image'];

    // Validate
    $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
    $finfo   = finfo_open(FILEINFO_MIME_TYPE);
    $mime    = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mime, $allowed)) {
        echo json_encode(['success' => false, 'message' => 'Only JPG, PNG, WebP, or GIF files allowed.']);
        exit;
    }
    if ($file['size'] > 8 * 1024 * 1024) {
        echo json_encode(['success' => false, 'message' => 'File too large (max 8MB).']);
        exit;
    }

    // Derive the extension from the VERIFIED mime type, never from the
    // uploaded filename. Taking the user's extension meant a file whose
    // contents pass as an image but which is named "evil.php" was saved as
    // .php inside a web-reachable directory and would execute — remote code
    // execution from the gallery upload form.
    $extByMime = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];
    $ext      = $extByMime[$mime];
    $filename = 'gallery_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $destDir  = __DIR__ . '/../assets/images/gallery/';
    $destPath = $destDir . $filename;

    if (!is_dir($destDir)) { mkdir($destDir, 0755, true); }

    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
        echo json_encode(['success' => false, 'message' => 'Failed to save file.']);
        exit;
    }

    // Get next sort_order
    $maxOrder = $pdo->query("SELECT COALESCE(MAX(sort_order),0) FROM gallery")->fetchColumn();
    $stmt = $pdo->prepare("INSERT INTO gallery (filename, caption, sort_order) VALUES (:filename, :caption, :sort_order)");
    $stmt->execute(['filename' => $filename, 'caption' => $caption, 'sort_order' => $maxOrder + 1]);
    $newId = $pdo->lastInsertId();

    echo json_encode([
        'success'  => true,
        'id'       => $newId,
        'filename' => $filename,
        'caption'  => $caption,
    ]);
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action']);
