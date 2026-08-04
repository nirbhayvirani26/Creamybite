<?php
// ============================================================
//  Creamy Bite – Staff section permissions
//
//  Authorization only — this assumes the caller has ALREADY verified
//  $_SESSION['admin_logged_in'] (via _guard.php or an inline check). It does
//  not authenticate anyone; it only decides WHICH admin sections a session
//  that is already logged in may use.
//
//  require_once __DIR__ . '/_permissions.php'; then call adminRequire('tab')
//  right after the existing login check, in any admin file or handler.
// ============================================================
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';

/** True for the one shared owner login (no staff row attached to the session). */
function adminIsOwner(): bool
{
    return empty($_SESSION['admin_staff_id']);
}

/**
 * Granted section keys for the current staff session, refreshed from the
 * database once per request — not just cached at login — so revoking a
 * permission or deactivating the account takes effect on the very next
 * page load rather than waiting for the staff member to log out.
 */
function adminStaffPerms(): array
{
    static $done = false;
    if (adminIsOwner()) {
        return [];
    }
    if (!$done) {
        $done = true;
        global $pdo;

        $row = $pdo->prepare("SELECT active, name FROM staff WHERE id = ?");
        $row->execute([$_SESSION['admin_staff_id']]);
        $staff = $row->fetch();

        if (!$staff || !$staff['active']) {
            // Deleted or deactivated mid-session — end it now rather than
            // letting a revoked account keep working until it happens to
            // log out.
            $_SESSION = [];
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_destroy();
            }
            header('Location: ' . adminBaseUrl() . '/login.php');
            exit;
        }

        $_SESSION['admin_staff_name'] = $staff['name'];

        $perms = $pdo->prepare("SELECT section_key FROM staff_permissions WHERE staff_id = ?");
        $perms->execute([$_SESSION['admin_staff_id']]);
        $_SESSION['admin_staff_perms'] = $perms->fetchAll(PDO::FETCH_COLUMN);
    }
    return $_SESSION['admin_staff_perms'] ?? [];
}

/**
 * Can the current session use $section (an admin tab key, e.g. 'products')?
 *
 * 'staff' is hard-coded to owner-only and never checked against
 * staff_permissions at all — so a stray or mistaken row in that table can
 * never grant staff-management access to a non-owner account.
 */
function adminCan(string $section): bool
{
    if ($section === 'staff') {
        return adminIsOwner();
    }
    if (adminIsOwner()) {
        return true;
    }
    return in_array($section, adminStaffPerms(), true);
}

/** Display name for the sidebar footer — the staff member's name, or the owner's. */
function adminStaffName(): string
{
    return $_SESSION['admin_staff_name'] ?? ADMIN_USERNAME;
}

/** Same DOCUMENT_ROOT-relative resolution _guard.php uses, reused here. */
function adminBaseUrl(): string
{
    $p = str_replace('\\', '/', substr(__DIR__, strlen(rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/'))));
    return ($p !== '' && $p[0] === '/') ? $p : '';
}

/** Refuse the request (403 JSON, or redirect for HTML) unless adminCan($section). */
function adminRequire(string $section): void
{
    if (adminCan($section)) {
        return;
    }
    $wantsJson = (
        (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))
        || !empty($GLOBALS['ADMIN_GUARD_JSON'])
    );
    if ($wantsJson) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'You do not have access to this section.']);
    } else {
        header('Location: ' . adminBaseUrl() . '/index.php?access_denied=1');
    }
    exit;
}
