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
 * WHY IT RETURNS AN ABSOLUTE PATH
 * -------------------------------
 * Callers still WRITE the URL relative to their own file — "assets/..." from
 * the site root, "../assets/..." from pages/ and admin/, "../../assets/..."
 * from admin/print/ — and this resolves it to a path from the site root:
 *
 *     ../assets/css/style.css   (called from pages/)   ->  /assets/css/style.css
 *     assets/css/admin.css      (called from admin/)   ->  /admin/assets/css/admin.css
 *
 * It used to pass the relative URL straight through, which was correct while
 * every page was served at the same URL depth as its own file. Clean URLs
 * ended that: /order is served BY pages/order.php but sits at the site root as
 * far as the browser is concerned, so "../assets/css/style.css" resolved to
 * "/../assets/css/style.css" and every page came out unstyled.
 *
 * Resolving against the RUNNING SCRIPT's directory rather than assuming the
 * site root is what keeps admin working: admin/ has its own assets/ folder, so
 * "assets/css/admin.css" on an admin page must mean admin/assets/..., not the
 * storefront's.
 *
 * The original worry — that absolute paths break when the site moves into a
 * subfolder — is handled by building on SITE_BASE, which is exactly the URL
 * prefix of the project root ("/orders" under MAMP, "" on the live domain).
 * If any of that cannot be worked out, the URL is returned relative, as before.
 *
 * FAILS SAFE
 * ----------
 * If the file cannot be found or stat'd, the path is still resolved but no
 * ?v= is added. A missing version parameter means a stale cache — an
 * inconvenience. A thrown error, or a mangled href, would mean an unstyled
 * page. If even the resolution cannot be done confidently (no SCRIPT_FILENAME,
 * no SITE_BASE, a script outside the project) the URL is returned exactly as
 * given, which is what the site did before clean URLs existed.
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
            // Still resolve the path, just without a version. Returning the
            // relative URL here would have been the old fail-safe, but under
            // clean URLs a relative asset path is not a stale cache — it is a
            // 404, because /order is not served from the folder the page lives
            // in. An unversioned absolute URL is the safe half of the two.
            return $cache[$relUrl] = cbAssetAbsolute($relUrl);
        }

        $sep = str_contains($relUrl, '?') ? '&' : '?';
        return $cache[$relUrl] = cbAssetAbsolute($relUrl) . $sep . 'v=' . $stamp;
    }
}

if (!function_exists('cbAssetAbsolute')) {
    /**
     * A caller-relative asset URL, as a path from the site root.
     *
     * Returns $relUrl untouched when it cannot be resolved with confidence. An
     * unversioned or relative URL is a stale cache at worst; a wrong absolute
     * one is a missing stylesheet on every page, so the uncertain case keeps
     * the old behaviour rather than guessing.
     */
    function cbAssetAbsolute(string $relUrl): string
    {
        if ($relUrl === '' || $relUrl[0] === '/') {
            return $relUrl;                       // already absolute
        }
        if (!defined('SITE_BASE')) {
            return $relUrl;                       // config.php not loaded
        }

        $projRoot  = str_replace('\\', '/', dirname(__DIR__));
        $script    = str_replace('\\', '/', (string)($_SERVER['SCRIPT_FILENAME'] ?? ''));
        if ($script === '') {
            return $relUrl;
        }
        $scriptDir = dirname($script);

        // The script has to sit inside this project for its URL directory to
        // be derivable from SITE_BASE. A symlinked or aliased script does not,
        // and gets the relative URL rather than a wrong absolute one.
        if ($scriptDir !== $projRoot && !str_starts_with($scriptDir . '/', $projRoot . '/')) {
            return $relUrl;
        }

        $urlDir = rtrim(SITE_BASE, '/') . substr($scriptDir, strlen($projRoot));

        // Resolve "." and ".." the way a browser would, rather than leaving
        // them in the href for it to work out — the whole point is that the
        // browser's idea of "here" is no longer the script's folder.
        $parts = [];
        foreach (explode('/', $urlDir . '/' . $relUrl) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($parts);
                continue;
            }
            $parts[] = $segment;
        }

        return '/' . implode('/', $parts);
    }
}
