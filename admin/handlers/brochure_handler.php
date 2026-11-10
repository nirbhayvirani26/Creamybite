<?php
// ============================================================
//  Creamy Bite – Admin: catalogue images
//
//  Upload or remove the two photos the printed catalogue can use instead of
//  its defaults:
//
//    cover  – the big photo on the front page (default: a group of tubs)
//    story  – the photo beside "Our story" (default: the About page photo)
//
//  Stored as files, not database rows: assets/images/catalogue/cover.<ext>
//  and story.<ext>. One file per slot — a new upload removes the old one
//  whatever its extension, so a .png can never sit behind a newer .jpg.
//
//  Note the ../../ below: this file lives in admin/handlers/, so a single
//  ../ only reaches admin/ — the mistake that once made gallery uploads
//  vanish into a folder the site never reads.
// ============================================================
require_once __DIR__ . '/../_guard.php';
csrfCheck();
require_once __DIR__ . '/../_permissions.php';
adminRequire('products');

$DIR   = dirname(__DIR__, 2) . '/assets/images/catalogue/';
$slots = ['cover', 'story'];

$slot   = (string)($_POST['slot'] ?? '');
$action = (string)($_POST['action'] ?? '');

// Back to the catalogue, keeping whichever price view was open.
$back = 'brochure.php';
$keep = [];
if (in_array($_POST['prices'] ?? '', ['retail', 'trade'], true)) { $keep['prices'] = $_POST['prices']; }
$pv = (string)($_POST['valid'] ?? '');
if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $pv, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) { $keep['valid'] = $pv; }

function cbbrBack(string $back, array $keep, string $msg, bool $ok): void
{
    $keep['img_msg'] = $msg;
    $keep['img_ok']  = $ok ? '1' : '0';
    header('Location: ../' . $back . '?' . http_build_query($keep));
    exit;
}

/** Delete every stored file for one slot, whatever its extension. */
function cbbrClear(string $dir, string $slot): void
{
    foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
        if (is_file($dir . $slot . '.' . $ext)) { @unlink($dir . $slot . '.' . $ext); }
    }
}

if (!in_array($slot, $slots, true)) { cbbrBack($back, $keep, 'Which photo?', false); }
$label = $slot === 'cover' ? 'Cover photo' : 'Story photo';

if ($action === 'remove') {
    cbbrClear($DIR, $slot);
    cbbrBack($back, $keep, $label . ' removed — the catalogue is back to its default.', true);
}

if ($action !== 'upload') { cbbrBack($back, $keep, 'Unknown action.', false); }

$file = $_FILES['image'] ?? null;
if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
    cbbrBack($back, $keep, 'Choose a photo first.', false);
}
if (($file['error'] ?? 0) === UPLOAD_ERR_INI_SIZE || ($file['error'] ?? 0) === UPLOAD_ERR_FORM_SIZE) {
    cbbrBack($back, $keep, 'That photo is too big for the server. Resize it under 10MB and try again.', false);
}
if (($file['error'] ?? 0) !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
    cbbrBack($back, $keep, 'The upload did not complete. Please try again.', false);
}

// Extension from the VERIFIED type, never the filename: an image-shaped file
// called "x.php" must not land as .php in a folder the web server serves.
$allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime  = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);
if (!isset($allowed[$mime]))            { cbbrBack($back, $keep, 'Use a JPG, PNG or WebP photo.', false); }
if ($file['size'] > 10 * 1024 * 1024)   { cbbrBack($back, $keep, 'That photo is over 10MB — please resize it.', false); }

// Too small prints as a blurry smudge on an A4 page. Warn, but still save:
// it is the owner's call, and a small photo beats none.
// Judged on the LONGER side: a wide banner of 2048×768 is plenty for a page,
// and measuring its short side flagged it as too small.
$dim  = @getimagesize($file['tmp_name']);
$warn = ($dim && max($dim[0], $dim[1]) < 1500)
      ? ' It is only ' . $dim[0] . '×' . $dim[1] . ' pixels, so it may look soft when printed — about 2000 pixels on the longer side is ideal.'
      : '';

if (!is_dir($DIR)) { mkdir($DIR, 0755, true); }
cbbrClear($DIR, $slot);
if (!move_uploaded_file($file['tmp_name'], $DIR . $slot . '.' . $allowed[$mime])) {
    cbbrBack($back, $keep, 'Could not save that photo on the server.', false);
}

cbbrBack($back, $keep, $label . ' saved.' . $warn, true);
