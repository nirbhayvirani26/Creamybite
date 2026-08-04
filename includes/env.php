<?php
// ============================================================
//  Creamy Bite – .env loader
//
//  Secrets live in a .env file at the project root. That file is NOT in the
//  upload package and NOT in git, which is the entire point: every deployment
//  used to overwrite includes/secrets.php with whatever the developer had
//  locally, so a Stripe key rolled on the live server was silently replaced
//  by an old one on the next upload. The symptom was "Could not load payment
//  form" appearing after every deploy, with nothing in the code to blame.
//
//  A file that is never shipped cannot be overwritten by shipping.
//
//  Format is the usual one:
//      KEY=value
//      # comments and blank lines are ignored
//      QUOTED="value with spaces"
//
//  Values are read into a private array, not into $_ENV or getenv(), so a
//  stray phpinfo() or a var_dump($_ENV) cannot print the card keys.
// ============================================================

/**
 * Read .env once and answer lookups from it.
 *
 * Returns $default when the key is absent, so a server that has not been
 * given a .env yet keeps running on whatever includes/secrets.php holds
 * rather than failing outright the moment this is deployed.
 */
function cbEnv(string $key, ?string $default = null): ?string
{
    static $vars = null;

    if ($vars === null) {
        $vars = [];
        $path = __DIR__ . '/../.env';

        if (is_readable($path)) {
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                    continue;
                }

                [$k, $v] = explode('=', $line, 2);
                $k = trim($k);
                $v = trim($v);

                // Strip one matching pair of surrounding quotes. A password
                // containing '#' has to be quotable, so the comment rule is
                // applied to whole lines only, never mid-value.
                $len = strlen($v);
                if ($len >= 2 && (($v[0] === '"' && $v[$len - 1] === '"') || ($v[0] === "'" && $v[$len - 1] === "'"))) {
                    $v = substr($v, 1, -1);
                }

                if ($k !== '') {
                    $vars[$k] = $v;
                }
            }
        }
    }

    $val = $vars[$key] ?? null;
    return ($val === null || $val === '') ? $default : $val;
}

/** Is there a usable .env on this server? Used by the health check. */
function cbEnvLoaded(): bool
{
    return is_readable(__DIR__ . '/../.env');
}
