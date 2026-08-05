<?php
// ============================================================
//  Creamy Bite – Admin: Staff Handler
//  Actions: create, update (permissions + optional password + active flag),
//           owner_change_password (the owner's own password)
//
//  Owner-only. adminRequire('staff') never consults staff_permissions for
//  that key (see admin/_permissions.php) — no staff account can ever reach
//  this file, regardless of what it's been granted.
// ============================================================
$GLOBALS['ADMIN_GUARD_JSON'] = true;   // reply in JSON, not a redirect
require_once __DIR__ . '/../_guard.php';
header('Content-Type: application/json');
csrfCheckJson();
require_once __DIR__ . '/../_permissions.php';
adminRequire('staff');

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';

// Same set the Staff tab renders checkboxes for — kept in sync by hand,
// same as $validTabs in admin/index.php. 'staff' is deliberately absent:
// staff management is owner-only, enforced above, not by this list.
const CBI_GRANTABLE_SECTIONS = [
    'orders', 'invoices', 'revenue', 'products', 'stock',
    'categories', 'promos', 'trade', 'inquiries', 'gallery', 'reviews',
    'accounting',
];

$action = $_POST['action'] ?? '';

if ($action === 'create') {
    $username = trim($_POST['username'] ?? '');
    $name     = trim($_POST['name'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    if ($username === '' || $name === '' || strlen($password) < 8) {
        echo json_encode(['success' => false, 'message' => 'Username, name and an 8+ character password are all required.']);
        exit;
    }
    // A staff username must never shadow the one shared owner login — the
    // staff table is checked first at login, so a collision here would
    // silently swallow the owner account.
    if (hash_equals(ADMIN_USERNAME, $username)) {
        echo json_encode(['success' => false, 'message' => 'That username is reserved for the owner login.']);
        exit;
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO staff (username, password_hash, name) VALUES (?, ?, ?)");
        $stmt->execute([
            mb_substr($username, 0, 60),
            password_hash($password, PASSWORD_DEFAULT),
            mb_substr($name, 0, 150),
        ]);
        echo json_encode(['success' => true, 'id' => $pdo->lastInsertId()]);
    } catch (PDOException $e) {
        $msg = str_contains($e->getMessage(), 'uq_staff_username')
            ? 'That username is already in use.'
            : 'Could not create that staff account: ' . $e->getMessage();
        echo json_encode(['success' => false, 'message' => $msg]);
    }
    exit;
}

if ($action === 'update') {
    $staffId  = (int)($_POST['staff_id'] ?? 0);
    $active   = !empty($_POST['active']) ? 1 : 0;
    $password = (string)($_POST['password'] ?? '');
    $sections = json_decode($_POST['sections'] ?? '[]', true);
    if (!is_array($sections)) $sections = [];
    // Only ever legal grantable keys — 'staff' (or anything invented client
    // side) can never end up in staff_permissions no matter what is posted.
    $sections = array_values(array_intersect($sections, CBI_GRANTABLE_SECTIONS));

    if ($staffId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Missing staff id.']);
        exit;
    }
    if ($password !== '' && strlen($password) < 8) {
        echo json_encode(['success' => false, 'message' => 'Password must be at least 8 characters.']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        if ($password !== '') {
            $pdo->prepare("UPDATE staff SET password_hash = ?, active = ? WHERE id = ?")
                ->execute([password_hash($password, PASSWORD_DEFAULT), $active, $staffId]);
        } else {
            $pdo->prepare("UPDATE staff SET active = ? WHERE id = ?")->execute([$active, $staffId]);
        }

        $pdo->prepare("DELETE FROM staff_permissions WHERE staff_id = ?")->execute([$staffId]);
        if ($sections) {
            $ins = $pdo->prepare("INSERT INTO staff_permissions (staff_id, section_key) VALUES (?, ?)");
            foreach ($sections as $key) {
                $ins->execute([$staffId, $key]);
            }
        }

        $pdo->commit();
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        $pdo->rollBack();
        echo json_encode(['success' => false, 'message' => 'Could not save: ' . $e->getMessage()]);
    }
    exit;
}

if ($action === 'owner_change_password') {
    $current = (string)($_POST['current_password'] ?? '');
    $new     = (string)($_POST['new_password'] ?? '');

    if (strlen($new) < 8) {
        echo json_encode(['success' => false, 'message' => 'New password must be at least 8 characters.']);
        exit;
    }

    // "Current password" is whichever one actually works right now: a DB
    // override if set, else .env ADMIN_PASSWORD — the same fallback login.php
    // itself uses, so this can never disagree with login.
    $existingHash = null;
    try {
        $stmt = $pdo->prepare("SELECT password_hash FROM owner_settings WHERE id = 1");
        $stmt->execute();
        $row = $stmt->fetch();
        $existingHash = $row ? $row['password_hash'] : null;
    } catch (PDOException $e) {
        $existingHash = null;
    }

    $currentOk = $existingHash !== null
        ? password_verify($current, $existingHash)
        : hash_equals(ADMIN_PASSWORD, $current);

    // Re-verified server-side even though this session is already logged in as
    // owner — a session left open on a shared machine must not be enough on
    // its own to change the master credential.
    if (!$currentOk) {
        echo json_encode(['success' => false, 'message' => 'Current password is incorrect.']);
        exit;
    }

    try {
        $pdo->prepare("INSERT INTO owner_settings (id, password_hash, updated_at) VALUES (1, ?, NOW())
                        ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), updated_at = VALUES(updated_at)")
            ->execute([password_hash($new, PASSWORD_DEFAULT)]);
        error_log('Owner password changed from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'message' => 'Could not save: ' . $e->getMessage()]);
    }
    exit;
}

echo json_encode(['success' => false, 'message' => 'Unknown action.']);
