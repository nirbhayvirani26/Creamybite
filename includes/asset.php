<?php
/**
 * Cache-busting for stylesheets and scripts.
 *
 * THE PROBLEM THIS SOLVES
 * ----------------------
 * A browser that has already fetched /assets/css/style.css has no reason to ask
 * for it again — the URL has not changed, so the copy it saved is, as far as it
 * knows, still current. Upload a new stylesheet and visitors keep seeing the old
 * design until they happen to hard-refresh. That is why every upload needed
 * Ctrl+Shift+R, several times, and why a change could look like it had "not
 * worked" when it had uploaded perfectly.
 *
 * THE FIX
 * -------
 * Append the file's last-modified time to the URL:
 *
 *     assets/css/style.css   ->   assets/css/style.css?v=1786124019
 *
 * Edit the file and the number changes, so the URL changes, so the browser has
 * no saved copy for it and must fetch the new one. Nobody has to remember to
 * hard-refresh, and customers get the update the moment it lands rather than
 * whenever their cache happens to expire.
 *
 * The version is the mtime rather than a hand-kept number precisely so that no
 * one has to remember to bump it — uploading the file IS the bump.
 *
 * WHY IT TAKES THE URL AS WRITTEN
 * -------------------------------
 * Pages sit at three depths and link assets relative to themselves:
 * "assets/..." from the site root, "../assets/..." from pages/ and admin/, and
 * "../../assets/..." from admin/print/. Rewriting those to absolute paths would
 * break the site the moment it moved into a subfolder, so the relative URL is
 * passed straight through and only the ?v= is added. The filesystem path is
 * worked out separately, from this file's own location.
 *
 * FAILS SAFE
 * ----------
 * If the file cannot be found or stat'd, the URL is returned exactly as given.
 * A missing version parameter means a stale cache — an inconvenience. A thrown
 * error, or a mangled href, would mean an unstyled page.
 */

if (!function_exists('cbAsset')) {
    function cbAsset(string $relUrl): string
    {
        static $cache = [];
        if (isset($cache[$relUrl])) {
            return $cache[$relUrl];
        }

        // Leave anything absolute alone — a CDN URL is not ours to version, and
        // its own filename already carries a version (…/6.4.0/css/all.min.css).
        if (preg_match('#^(https?:)?//#i', $relUrl) || str_starts_with($relUrl, 'data:')) {
            return $cache[$relUrl] = $relUrl;
        }

        // The path part only — never let an existing query string reach the
        // filesystem lookup.
        $path = (string)(parse_url($relUrl, PHP_URL_PATH) ?? '');
        if ($path === '') {
            return $cache[$relUrl] = $relUrl;
        }

        // Resolve exactly the way the BROWSER will: a relative href resolves
        // against the page's own URL, so resolve against the running script's
        // own directory. Guessing instead that every asset lives under the site
        // root gets admin wrong — admin/ has its own assets/ folder, so
        // "assets/css/admin.css" on an admin page means admin/assets/..., not
        // the storefront's.
        $candidates = [];

        $script = $_SERVER['SCRIPT_FILENAME'] ?? '';
        if ($script !== '') {
            $candidates[] = dirname($script) . '/' . $path;
        }

        // Fallbacks for anything with no script context (CLI, or an odd SAPI):
        // treat the hops as leading back to the site root, which is right for
        // every storefront path.
        $root = dirname(__DIR__);
        $candidates[] = $root . '/' . ltrim((string)preg_replace('#^(?:\.\.?/)+#', '', $path), '/');

        $stamp = false;
        foreach ($candidates as $candidate) {
            $real = realpath($candidate);
            if ($real !== false && is_file($real)) {
                $stamp = @filemtime($real);
                if ($stamp !== false) {
                    break;
                }
            }
        }
        if ($stamp === false) {
            return $cache[$relUrl] = $relUrl;
        }

        $sep = str_contains($relUrl, '?') ? '&' : '?';
        return $cache[$relUrl] = $relUrl . $sep . 'v=' . $stamp;
    }
}
