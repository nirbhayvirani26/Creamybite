<?php
// ============================================================
//  Creamy Bite – Store Settings
//
//  WHAT THIS IS
//  ------------
//  The delivery charge, the free-delivery radius, how far we will drive at
//  all, and the smallest basket we will deliver used to be typed into
//  config.php and could only be changed by editing a PHP file and uploading
//  it. They are now a single row in the database (store_settings, id = 1) so
//  the owner can change them from the admin panel.
//
//  config.php still defines the same five constants it always did:
//
//      DELIVERY_CHARGE, FREE_DELIVERY_MILES, DELIVERY_RADIUS_MILES,
//      DELIVERY_DISTANCE_FACTOR, MIN_DELIVERY_ORDER
//
//  It just reads the numbers from here first. Every existing call site —
//  the checkout page, the Stripe intent, the order handler, pricing.php, the
//  catalogue, the FAQ, the shipping page and the terms page — keeps working
//  with no change at all.
//
//  WHY IT OPENS ITS OWN DATABASE CONNECTION
//  ----------------------------------------
//  Four files read those constants WITHOUT ever loading includes/db.php:
//  includes/pricing.php, pages/faq.php, pages/shipping.php and
//  pages/terms.php. And config.php cannot simply require db.php, because
//  db.php requires config.php on its line 6 — that is a loop, and PHP would
//  half-run one of them.
//
//  So this file opens its own small connection, reads one row by primary key,
//  and closes it when the request ends. One indexed read on one row.
//
//  IT MUST NEVER TAKE THE SITE DOWN
//  --------------------------------
//  Every failure — no database login yet, MySQL unreachable, the table not
//  created because the migration has not been run — falls back to the figures
//  below, which are exactly what config.php used to hard-code. A shop that
//  cannot reach its database still has to render its terms page with sensible
//  numbers on it rather than a white screen.
//
//  Everything is wrapped in function_exists() because config.php is reachable
//  under two spellings of the same folder on a case-insensitive Mac
//  (/orders and /Orders), which makes PHP include a file twice.
// ============================================================

if (!function_exists('cbStoreSettingDefaults')) {
    /**
     * The figures the shop falls back to when the database cannot be read.
     *
     * These are the values config.php hard-coded before the settings row
     * existed, so a database that is unreachable — or simply has not been
     * migrated yet — behaves exactly like the site did before.
     *
     * This is NOT a second way to read the settings. Ask cbStoreSettings()
     * for the live figures; this is only the safety net underneath it, and
     * the seed the migration writes into the row on a fresh install.
     */
    function cbStoreSettingDefaults(): array
    {
        return [
            'id'                       => 1,
            // Charged between the free radius and the maximum radius.
            'delivery_charge'          => 1.99,
            // Free inside this many miles.
            'free_delivery_miles'      => 3.0,
            // The furthest we will drive at all.
            'delivery_radius_miles'    => 6.0,
            // Straight-line distance is scaled by this to estimate the real
            // driving distance — see config.php for where 1.3 comes from.
            'delivery_distance_factor' => 1.3,
            // Smallest basket we will deliver. Collection has no minimum.
            'min_delivery_order'       => 20.00,
            // Spend this much and delivery is free wherever you are inside
            // the radius. NULL means the shop does not offer that.
            'free_delivery_over'       => null,
            // The standing message shown in the cart, and whether it is on.
            'cart_message'             => null,
            'cart_message_active'      => 0,
            'updated_at'               => null,
            'updated_by'               => '',
        ];
    }
}

if (!function_exists('cbStoreSettings')) {
    /**
     * The shop's settings row, read once per request.
     *
     * Never throws and never prints. On any problem at all it returns
     * cbStoreSettingDefaults(), so the caller always gets a complete array
     * with every key present and every number a real number.
     *
     * READ ONCE PER REQUEST. The row is fetched the first time it is asked
     * for and kept for the rest of the page — the five delivery constants are
     * defined from it in config.php, and a figure that changed halfway down a
     * checkout would be worse than one that is a moment out of date. A page
     * that has just SAVED new settings therefore still sees the old ones in
     * that same request; they take effect on the next one, which is what the
     * admin page should show the owner.
     *
     * @return array{
     *   id:int, delivery_charge:float, free_delivery_miles:float,
     *   delivery_radius_miles:float, delivery_distance_factor:float,
     *   min_delivery_order:float, free_delivery_over:?float,
     *   cart_message:?string, cart_message_active:int,
     *   updated_at:?string, updated_by:string
     * }
     */
    function cbStoreSettings(): array
    {
        // Memoised for the whole request — including the failure, so a
        // database that is down is not dialled four more times on the way
        // down the page.
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $settings = cbStoreSettingDefaults();

        // No database login at all. This is the normal state of a live server
        // that has not been given its .env yet, and of anything that loads
        // this file before config.php has worked out which database to use.
        if (!defined('DB_NAME') || !defined('DB_USER') || DB_NAME === '') {
            return $cache = $settings;
        }

        try {
            $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT
                 . ';dbname=' . DB_NAME . ';charset=utf8mb4';
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // Three seconds, not PHP's default. This read happens before
                // anything is on screen, so a database that is refusing
                // connections must fail fast rather than hold the page open
                // until the request times out.
                PDO::ATTR_TIMEOUT            => 3,
            ]);
            $row = $pdo->query("SELECT * FROM `store_settings` WHERE `id` = 1")->fetch();
        } catch (Throwable $e) {
            // Includes "table doesn't exist" — i.e. the migration has not been
            // run on this database yet. The site carries on with the figures
            // it always used.
            error_log('Store settings unavailable, using the built-in figures: ' . $e->getMessage());
            return $cache = $settings;
        }

        if (!is_array($row) || $row === []) {
            return $cache = $settings;
        }

        // ── Numbers ──────────────────────────────────────────
        // A value is only taken from the row if it is genuinely a number and
        // not negative. Anything else keeps the default, because a blank or
        // nonsense figure edited straight into the database must not become a
        // charge on a customer's card.
        foreach ([
            'delivery_charge',
            'free_delivery_miles',
            'delivery_radius_miles',
            'delivery_distance_factor',
            'min_delivery_order',
        ] as $key) {
            if (isset($row[$key]) && is_numeric($row[$key]) && (float)$row[$key] >= 0) {
                $settings[$key] = round((float)$row[$key], 2);
            }
        }

        // The distance factor multiplies every distance the shop measures, so
        // it is clamped rather than trusted. Below 1.0 it would claim driving
        // is shorter than a straight line, which is not possible; above 2.0 it
        // would put half of London outside the radius.
        $settings['delivery_distance_factor'] =
            min(2.0, max(1.0, $settings['delivery_distance_factor']));

        // Optional: spend over this and delivery is free. Zero would mean
        // "free delivery on everything", which is not what an empty box means,
        // so only a figure above zero switches it on.
        $settings['free_delivery_over'] = null;
        if (isset($row['free_delivery_over'])
            && is_numeric($row['free_delivery_over'])
            && (float)$row['free_delivery_over'] > 0) {
            $settings['free_delivery_over'] = round((float)$row['free_delivery_over'], 2);
        }

        // ── The standing cart message ────────────────────────
        $message = trim((string)($row['cart_message'] ?? ''));
        $settings['cart_message'] = $message === '' ? null : $message;
        // Switched on but with nothing written in it shows the customer an
        // empty box, so an empty message is off whatever the flag says.
        $settings['cart_message_active'] =
            ($settings['cart_message'] !== null && (int)($row['cart_message_active'] ?? 0) === 1) ? 1 : 0;

        $settings['id']         = (int)($row['id'] ?? 1);
        $settings['updated_at'] = isset($row['updated_at']) ? (string)$row['updated_at'] : null;
        $settings['updated_by'] = trim((string)($row['updated_by'] ?? ''));

        return $cache = $settings;
    }
}

// ── Bootstrap, for anything that includes this file on its own ──
//
// config.php requires this file near its end and then defines the delivery
// constants from cbStoreSettings(). If some other file gets here FIRST,
// config.php has not run, so DB_NAME and friends do not exist yet and every
// call would quietly return the defaults.
//
// Loading config.php here fixes that. It has to be at the BOTTOM, after the
// functions above are declared: config.php calls cbStoreSettings() itself, and
// a function guarded by function_exists() only comes into being when execution
// reaches it. Requiring config.php from the top of this file would therefore
// reach that call before the function existed.
if (!defined('CB_CONFIG_LOADED')) {
    require_once __DIR__ . '/config.php';
}
