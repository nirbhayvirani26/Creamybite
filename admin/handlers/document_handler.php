<?php
/**
 * Documents & SOPs — upload, edit, delete.
 *
 * Same upload discipline as the product images: the type is read from the
 * file's CONTENT, the extension comes from that verified type rather than the
 * name the browser sent, and the stored name is generated. A file called
 * "policy.php" cannot land in a folder and execute.
 *
 * The upload errors are reported properly too. A phone photo or a scanned PDF
 * is routinely larger than a shared host allows, and the failure PHP produces
 * is silent — the product form used to save with no image and say nothing,
 * which is exactly the confusion worth not repeating here.
 */

$GLOBALS['ADMIN_GUARD_JSON'] = true;
require_once __DIR__ . '/../_guard.php';
header('Content-Type: application/json');
csrfCheckJson();
require_once __DIR__ . '/../_permissions.php';
adminRequire('documents');

require_once __DIR__ . '/../../includes/documents.php';

/** Reply and stop. */
function cbdocOut(array $payload, int $code = 200): never
{
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

if (!cbDocReady($pdo)) {
    cbdocOut([
        'success' => false,
        'message' => 'The documents area is not set up on this server yet. '
                   . 'Run the database update once (admin/migrations/update_db.php), then come back.',
    ], 503);
}

// A file larger than post_max_size never reaches PHP: $_POST and $_FILES both
// arrive empty, and without this the request looks like a malformed call
// rather than what it is.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && empty($_FILES)) {
    cbdocOut([
        'success' => false,
        'message' => 'That file is too large for the server to accept (the limit is '
                   . ini_get('post_max_size') . '). Nothing was saved.',
    ], 413);
}

$action = trim((string)($_POST['action'] ?? ''));
$user   = (string)($_SESSION['admin_username'] ?? ($_SESSION['staff_name'] ?? 'admin'));

switch ($action) {

    // ── Upload a new document ────────────────────────────────
    case 'upload': {
        $title    = trim((string)($_POST['title'] ?? ''));
        $category = trim((string)($_POST['category'] ?? 'General'));
        $desc     = trim((string)($_POST['description'] ?? ''));
        $review   = trim((string)($_POST['review_due'] ?? ''));

        $cats = array_keys(cbDocCategories());
        if (!in_array($category, $cats, true)) { $category = 'General'; }

        if ($title === '')            { cbdocOut(['success' => false, 'message' => 'Give the document a name.'], 422); }
        if (mb_strlen($title) > 180)  { cbdocOut(['success' => false, 'message' => 'That name is too long — keep it under 180 characters.'], 422); }
        if (mb_strlen($desc) > 500)   { cbdocOut(['success' => false, 'message' => 'The note is too long — keep it under 500 characters.'], 422); }
        if ($review !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $review)) {
            cbdocOut(['success' => false, 'message' => 'The review date needs to be a real date.'], 422);
        }

        $file  = $_FILES['document'] ?? null;
        $upErr = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($file === null || $upErr === UPLOAD_ERR_NO_FILE) {
            cbdocOut(['success' => false, 'message' => 'Choose a file to upload.'], 422);
        }

        // Check the upload arrived BEFORE touching tmp_name — finfo_file('') is
        // a fatal error in PHP 8, not a warning.
        if ($upErr !== UPLOAD_ERR_OK || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            cbdocOut(['success' => false, 'message' => match ($upErr) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE =>
                    'That file is too large for the server (limit ' . ini_get('upload_max_filesize') . ').',
                UPLOAD_ERR_PARTIAL => 'The file only partly uploaded. Please try again.',
                UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE =>
                    'The server could not save the file. The documents folder may not be writable.',
                default => 'The file could not be uploaded. Please try again.',
            }], 422);
        }

        $allowed = cbDocAllowedTypes();
        $finfo   = finfo_open(FILEINFO_MIME_TYPE);
        $mime    = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!isset($allowed[$mime])) {
            cbdocOut([
                'success' => false,
                'message' => 'That file type is not accepted. Use a PDF, Word, Excel, image, CSV or text file.',
            ], 422);
        }

        if ((int)$file['size'] > 20 * 1024 * 1024) {
            cbdocOut(['success' => false, 'message' => 'Keep files under 20MB.'], 422);
        }

        $dir = cbDocDir();
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            cbdocOut(['success' => false, 'message' => 'The documents folder could not be created on the server.'], 500);
        }

        // Generated name, verified extension. Never the uploaded filename.
        $stored = 'doc_' . time() . '_' . bin2hex(random_bytes(5)) . '.' . $allowed[$mime];

        if (!move_uploaded_file($file['tmp_name'], $dir . $stored)) {
            cbdocOut(['success' => false, 'message' => 'The file could not be saved. Check the folder permissions.'], 500);
        }

        try {
            $st = $pdo->prepare(
                "INSERT INTO documents
                    (title, category, description, stored_name, original_name, mime,
                     size_bytes, review_due, sort_order, uploaded_by, created_at)
                 VALUES
                    (:t, :c, :d, :s, :o, :m, :sz, :r, 0, :u, NOW())"
            );
            $st->execute([
                't'  => $title,
                'c'  => $category,
                'd'  => $desc !== '' ? $desc : null,
                's'  => $stored,
                'o'  => mb_substr((string)$file['name'], 0, 255),
                'm'  => $mime,
                'sz' => (int)$file['size'],
                'r'  => $review !== '' ? $review : null,
                'u'  => mb_substr($user, 0, 120),
            ]);
        } catch (Throwable $e) {
            // Do not leave an orphan on disk if the row could not be written.
            @unlink($dir . $stored);
            error_log('Document insert failed: ' . $e->getMessage());
            cbdocOut(['success' => false, 'message' => 'The file uploaded but could not be recorded. Nothing was kept.'], 500);
        }

        cbdocOut(['success' => true, 'message' => 'Saved.', 'id' => (int)$pdo->lastInsertId()]);
    }

    // ── Edit the details, not the file ───────────────────────
    case 'update': {
        $id       = (int)($_POST['id'] ?? 0);
        $title    = trim((string)($_POST['title'] ?? ''));
        $category = trim((string)($_POST['category'] ?? 'General'));
        $desc     = trim((string)($_POST['description'] ?? ''));
        $review   = trim((string)($_POST['review_due'] ?? ''));

        if ($id <= 0)      { cbdocOut(['success' => false, 'message' => 'Which document?'], 422); }
        if ($title === '') { cbdocOut(['success' => false, 'message' => 'Give the document a name.'], 422); }
        if (!in_array($category, array_keys(cbDocCategories()), true)) { $category = 'General'; }
        if ($review !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $review)) {
            cbdocOut(['success' => false, 'message' => 'The review date needs to be a real date.'], 422);
        }

        $st = $pdo->prepare(
            "UPDATE documents SET title = :t, category = :c, description = :d, review_due = :r WHERE id = :id"
        );
        $st->execute([
            't'  => mb_substr($title, 0, 180),
            'c'  => $category,
            'd'  => $desc !== '' ? mb_substr($desc, 0, 500) : null,
            'r'  => $review !== '' ? $review : null,
            'id' => $id,
        ]);
        cbdocOut(['success' => true, 'message' => 'Saved.']);
    }

    // ── Delete, file and row ─────────────────────────────────
    case 'delete': {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) { cbdocOut(['success' => false, 'message' => 'Which document?'], 422); }

        $st = $pdo->prepare("SELECT stored_name FROM documents WHERE id = :id");
        $st->execute(['id' => $id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) { cbdocOut(['success' => false, 'message' => 'That document is already gone.'], 404); }

        // basename before unlink, for the same reason the product form does it:
        // this value is concatenated onto a directory and passed to unlink().
        $path = cbDocDir() . basename((string)$row['stored_name']);
        if (is_file($path)) { @unlink($path); }

        $pdo->prepare("DELETE FROM documents WHERE id = :id")->execute(['id' => $id]);
        cbdocOut(['success' => true, 'message' => 'Deleted.']);
    }

    default:
        cbdocOut(['success' => false, 'message' => 'Unknown action.'], 400);
}
