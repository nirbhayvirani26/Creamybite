<?php
// ============================================================
//  Creamy Bite – Admin: home page banner handler
//
//  Upload, edit, reorder, toggle and delete the slides on the home page
//  banner. Same shape as the gallery handler so there is one way to think
//  about image management in this admin, not two.
//
//  Files go to assets/images/banners/ — note the ../../ : this file lives in
//  admin/handlers/, so a single ../ only reaches admin/ and would save into a
//  folder the site never reads. That exact mistake made every gallery upload
//  vanish, so it is spelled out here rather than left to be repeated.
// ============================================================
$GLOBALS['ADMIN_GUARD_JSON'] = true;   // reply in JSON, not a redirect
require_once __DIR__ . '/../_guard.php';
header('Content-Type: application/json');
csrfCheckJson();
require_once __DIR__ . '/../_permissions.php';
adminRequire('banners');

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';

$BANNER_DIR = dirname(__DIR__, 2) . '/assets/images/banners/';
$action     = $_POST['action'] ?? $_GET['action'] ?? '';

/** Move an uploaded image into the banner folder, or return '' / an error. */
function bannerStoreUpload(array $file, string $dir, ?string &$error): string
{
    $error = null;
    if (empty($file['name'])) {
        return '';
    }
    // Extension from the VERIFIED mime type, never from the filename — an
    // image-shaped file called "evil.php" must not land as .php in a folder
    // the web server will execute.
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!isset($allowed[$mime]))          { $error = 'Banners must be a JPG, PNG, WebP or GIF.'; return ''; }
    if ($file['size'] > 8 * 1024 * 1024)  { $error = 'That image is over 8MB — please resize it.'; return ''; }

    if (!is_dir($dir)) { mkdir($dir, 0755, true); }
    $name = 'banner_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($file['tmp_name'], $dir . $name)) {
        $error = 'Could not save that image.';
        return '';
    }
    return $name;
}

/** Only 'left' or 'right' are real positions; anything else means 'left'. */
function bannerCleanTextPosition(string $pos): string
{
    return $pos === 'right' ? 'right' : 'left';
}

/** A link must stay on this site, or be a plain http(s) address. */
function bannerCleanUrl(string $url): string
{
    $url = trim($url);
    if ($url === '') { return ''; }
    // Anything with a scheme that is not http/https — javascript:, data: — is
    // dropped. A banner is clickable by every visitor, so this is not the
    // place to trust a pasted string.
    if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) && !preg_match('#^https?://#i', $url)) {
        return '';
    }
    return mb_substr($url, 0, 255);
}

try {
    // ── ADD ──────────────────────────────────────────────────
    if ($action === 'add') {
        $err = null;
        $image = bannerStoreUpload($_FILES['banner_image'] ?? [], $BANNER_DIR, $err);
        if ($err)        { echo json_encode(['success' => false, 'message' => $err]); exit; }
        if ($image === '') { echo json_encode(['success' => false, 'message' => 'Choose an image for the banner.']); exit; }

        $max = (int)$pdo->query("SELECT COALESCE(MAX(sort_order), 0) FROM banners")->fetchColumn();

        $st = $pdo->prepare(
            "INSERT INTO banners (image, headline, subtext, text_position, link_url, link_text, sort_order, active, starts_on, ends_on)
             VALUES (:i, :h, :s, :tp, :lu, :lt, :so, 1, :sd, :ed)"
        );
        $st->execute([
            'i'  => $image,
            'h'  => mb_substr(trim($_POST['headline']  ?? ''), 0, 120),
            's'  => mb_substr(trim($_POST['subtext']   ?? ''), 0, 255),
            'tp' => bannerCleanTextPosition($_POST['text_position'] ?? ''),
            'lu' => bannerCleanUrl($_POST['link_url']  ?? ''),
            'lt' => mb_substr(trim($_POST['link_text'] ?? ''), 0, 60),
            'so' => $max + 1,
            'sd' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['starts_on'] ?? '') ? $_POST['starts_on'] : null,
            'ed' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['ends_on']   ?? '') ? $_POST['ends_on']   : null,
        ]);

        echo json_encode(['success' => true, 'id' => (int)$pdo->lastInsertId(), 'image' => $image]);
        exit;
    }

    // ── UPDATE (text, link and dates; image only if a new one is sent) ──
    if ($action === 'update') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) { echo json_encode(['success' => false, 'message' => 'Invalid banner.']); exit; }

        $err = null;
        $newImage = bannerStoreUpload($_FILES['banner_image'] ?? [], $BANNER_DIR, $err);
        if ($err) { echo json_encode(['success' => false, 'message' => $err]); exit; }

        $sql = "UPDATE banners SET headline=:h, subtext=:s, text_position=:tp, link_url=:lu, link_text=:lt,
                       starts_on=:sd, ends_on=:ed";
        $args = [
            'h'  => mb_substr(trim($_POST['headline']  ?? ''), 0, 120),
            's'  => mb_substr(trim($_POST['subtext']   ?? ''), 0, 255),
            'tp' => bannerCleanTextPosition($_POST['text_position'] ?? ''),
            'lu' => bannerCleanUrl($_POST['link_url']  ?? ''),
            'lt' => mb_substr(trim($_POST['link_text'] ?? ''), 0, 60),
            'sd' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['starts_on'] ?? '') ? $_POST['starts_on'] : null,
            'ed' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['ends_on']   ?? '') ? $_POST['ends_on']   : null,
            'id' => $id,
        ];
        if ($newImage !== '') {
            // Replace the file and delete the old one, so the folder does not
            // silently fill with images nothing points at.
            $old = $pdo->prepare("SELECT image FROM banners WHERE id = ?");
            $old->execute([$id]);
            $oldName = (string)$old->fetchColumn();

            $sql .= ", image=:i";
            $args['i'] = $newImage;

            if ($oldName !== '' && is_file($BANNER_DIR . $oldName)) {
                unlink($BANNER_DIR . $oldName);
            }
        }
        $sql .= " WHERE id=:id";
        $pdo->prepare($sql)->execute($args);

        echo json_encode(['success' => true, 'image' => $newImage]);
        exit;
    }

    // ── TOGGLE ───────────────────────────────────────────────
    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
        $pdo->prepare("UPDATE banners SET active = 1 - active WHERE id = ?")->execute([$id]);
        $st = $pdo->prepare("SELECT active FROM banners WHERE id = ?");
        $st->execute([$id]);
        echo json_encode(['success' => true, 'active' => (int)$st->fetchColumn()]);
        exit;
    }

    // ── REORDER ──────────────────────────────────────────────
    if ($action === 'reorder') {
        $ids = (array)($_POST['ids'] ?? []);
        $st  = $pdo->prepare("UPDATE banners SET sort_order = ? WHERE id = ?");
        foreach (array_values($ids) as $i => $id) {
            $st->execute([$i + 1, (int)$id]);
        }
        echo json_encode(['success' => true]);
        exit;
    }

    // ── DELETE ───────────────────────────────────────────────
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
        $st = $pdo->prepare("SELECT image FROM banners WHERE id = ?");
        $st->execute([$id]);
        $img = (string)$st->fetchColumn();

        $pdo->prepare("DELETE FROM banners WHERE id = ?")->execute([$id]);
        // Delete the file too — the gallery used to drop the row and leave the
        // image behind forever, which is how an uploads folder gets to 2GB.
        if ($img !== '' && is_file($BANNER_DIR . $img)) {
            unlink($BANNER_DIR . $img);
        }
        echo json_encode(['success' => true]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action.']);

} catch (Throwable $e) {
    error_log('Banner handler error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Something went wrong: ' . $e->getMessage()]);
}
