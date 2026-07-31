<?php
// ============================================================
//  Creamy Bite – CSRF protection
//
//  Why this exists
//  ---------------
//  Without a token, any page the admin visits while logged in can make their
//  browser fire a request at this site, and the session cookie rides along.
//  A single <img src="…/admin/index.php?action=delete_product&id=7"> on an
//  unrelated page was enough to delete a product.
//
//  How to use it
//  -------------
//    In a form:    echo csrfField();   (short-echo tag also fine)
//    In JS:        body.append('csrf_token', CSRF_TOKEN);   // see csrfMeta()
//    In a handler: csrfCheck();        // dies with a clear message on failure
//                  csrfCheck(false);   // returns bool instead, for JSON handlers
//
//  NOTE: never write a PHP close tag inside these comments — it would end
//  PHP mode and dump the rest of this file to the browser as plain text.
//
//  The token is per-session and rotates only on login, so a user with several
//  admin tabs open does not get spurious failures.
//
//  Requires an open session.
// ============================================================

/** The session's CSRF token, minted on first use. */
function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Hidden input for a form. */
function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken(), ENT_QUOTES) . '">';
}

/** Meta tag + JS constant, so fetch() calls can read the token. */
function csrfMeta(): string
{
    $t = htmlspecialchars(csrfToken(), ENT_QUOTES);
    return '<meta name="csrf-token" content="' . $t . '">' . "\n"
         . '<script>const CSRF_TOKEN = ' . json_encode(csrfToken()) . ';</script>';
}

/** Append the token to a same-site URL, for links that must stay GET. */
function csrfUrl(string $url): string
{
    $sep = str_contains($url, '?') ? '&' : '?';
    return $url . $sep . 'csrf_token=' . urlencode(csrfToken());
}

/** Is the submitted token valid? Accepts POST body, query string, or header. */
function csrfValid(): bool
{
    $sent = $_POST['csrf_token']
        ?? $_GET['csrf_token']
        ?? $_SERVER['HTTP_X_CSRF_TOKEN']
        ?? '';

    if (!is_string($sent) || $sent === '' || empty($_SESSION['csrf_token'])) {
        return false;
    }
    // hash_equals is constant-time: a near-miss token must not be
    // distinguishable from a wild one by how long the comparison takes.
    return hash_equals($_SESSION['csrf_token'], $sent);
}

/**
 * Enforce the token.
 *
 * @param bool $html true  -> stop with a human-readable page (normal forms)
 *                   false -> return false so a JSON handler can reply in JSON
 */
function csrfCheck(bool $html = true): bool
{
    if (csrfValid()) {
        return true;
    }

    error_log('CSRF check failed for ' . ($_SERVER['REQUEST_URI'] ?? '?')
              . ' from ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

    if (!$html) {
        return false;
    }

    http_response_code(419);   // "Authentication Timeout" — the usual choice here
    // Self-contained page: it must render correctly even when no stylesheet
    // loads, so the CSS lives in a <style> block here rather than as inline
    // style attributes or an external file.
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Request blocked</title>'
       . '<style>'
       . 'body{font-family:-apple-system,BlinkMacSystemFont,sans-serif;padding:40px;max-width:560px;'
       . 'margin:40px auto;background:#fff7ef;border-radius:16px;border:1px solid #f0e6ee}'
       . 'h2{margin-top:0;color:#5C1D24}'
       . 'p{color:#555;font-size:14px;line-height:1.6}'
       . 'p.small{font-size:13px;color:#777}'
       . 'a.back{display:inline-block;margin-top:8px;background:#5C1D24;color:#fff;padding:10px 20px;'
       . 'border-radius:8px;text-decoration:none;font-size:13px;font-weight:700}'
       . '</style></head><body>'
       . '<h2>&#9888;&#65039; Request blocked</h2>'
       . '<p>This request did not carry a valid security token, so it was not carried out.</p>'
       . '<p>Usually this just means the page was open for a long time and your session expired. '
       . 'Go back, reload the page and try again.</p>'
       . '<p class="small">If you did not start this action, ignore it &mdash; nothing was changed.</p>'
       . '<a class="back" href="javascript:history.back()">Go back</a>'
       . '</body></html>';
    exit;
}

/** JSON-flavoured guard for AJAX handlers. Call after the auth check. */
function csrfCheckJson(): void
{
    if (!csrfValid()) {
        error_log('CSRF check failed (json) for ' . ($_SERVER['REQUEST_URI'] ?? '?'));
        http_response_code(419);
        echo json_encode([
            'success' => false,
            'message' => 'Your session has expired. Please reload the page and try again.',
        ]);
        exit;
    }
}
