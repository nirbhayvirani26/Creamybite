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
            // Is the shop taking delivery orders? Collection orders?
            //
            // BOTH DEFAULT TO OPEN, AND THAT IS DELIBERATE. This array is what
            // the whole site falls back to when the database cannot be read,
            // so these two numbers decide what a database outage does to the
            // shop. Open means a blip costs the owner an order they have to
            // ring up about. Closed would mean a blip quietly turns customers
            // away with a message saying the shop has stopped taking orders —
            // no error, no alert, nothing on screen to say anything is wrong.
            // The second failure is far more expensive and far harder to
            // notice, so this file fails OPEN. Do not "harden" these to 0.
            'delivery_open'            => 1,
            'collection_open'          => 1,
            'trade_delivery_open'      => 1,
            'trade_collection_open'    => 1,
            // What customers are told when a channel is off. NULL means the
            // owner has not written their own wording, and
            // cbOrderingClosedNote() supplies a sentence that fits the
            // situation.
            'delivery_closed_note'     => null,
            'collection_closed_note'   => null,
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
     *   delivery_open:int, collection_open:int,
     *   delivery_closed_note:?string, collection_closed_note:?string,
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

        // Each figure above is checked on its own, which cannot catch a pair
        // that is individually fine and jointly nonsense: a free radius LARGER
        // than the maximum radius means every address the shop will drive to
        // gets free delivery, and the charge is never applied to anyone. The
        // admin form refuses that combination, but a row edited straight into
        // the database — or a restored backup — would not go through the form.
        // Fall back to the pair from the defaults rather than guessing which of
        // the two the owner meant.
        if ($settings['free_delivery_miles'] > $settings['delivery_radius_miles']) {
            error_log(sprintf(
                'store_settings: free_delivery_miles (%s) exceeds delivery_radius_miles (%s); using the defaults for both.',
                $settings['free_delivery_miles'],
                $settings['delivery_radius_miles']
            ));
            $fallback = cbStoreSettingDefaults();
            $settings['free_delivery_miles']   = $fallback['free_delivery_miles'];
            $settings['delivery_radius_miles'] = $fallback['delivery_radius_miles'];
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

        // ── Are we taking orders? ────────────────────────────
        //
        // Read from the SAME row as everything above — one query per request,
        // memoised below, exactly as before. These fields cost nothing extra;
        // they ride along on the SELECT * that was already happening.
        //
        // Note the shape of the test: a channel is CLOSED only when the row
        // says so in as many words. Every other reading — the column missing
        // because this database has not had the migration run on it, the value
        // NULL, the value a string the shop does not recognise — leaves the
        // channel OPEN.
        //
        // That asymmetry is the whole point. isset() is doing real work here:
        // (int)null is 0, so a plain `(int)$row[$key] === 0` would read a NULL
        // as "closed" and shut the shop over a column that had merely never
        // been filled in. Closing a real business needs a real instruction.
        // The trade pair rides along on the same row, under the same rule.
        // They were being fetched by the SELECT * and then dropped here, which
        // is why the switches on the Delivery & Offers page saved, displayed,
        // and changed nothing a trade customer ever met.
        foreach (['delivery_open', 'collection_open',
                  'trade_delivery_open', 'trade_collection_open'] as $key) {
            $settings[$key] = (isset($row[$key]) && (int)$row[$key] === 0) ? 0 : 1;
        }

        // The owner's own wording for each. Blank stays NULL so that
        // cbOrderingClosedNote() knows to write the sentence itself rather
        // than showing a customer an empty space where an explanation
        // should be.
        foreach (['delivery_closed_note', 'collection_closed_note',
                  'trade_delivery_closed_note', 'trade_collection_closed_note'] as $key) {
            $note = trim((string)($row[$key] ?? ''));
            $settings[$key] = $note === '' ? null : $note;
        }

        $settings['id']         = (int)($row['id'] ?? 1);
        $settings['updated_at'] = isset($row['updated_at']) ? (string)$row['updated_at'] : null;
        $settings['updated_by'] = trim((string)($row['updated_by'] ?? ''));

        return $cache = $settings;
    }
}

// ════════════════════════════════════════════════════════════
//  "Open for orders" / "Closed now"
// ════════════════════════════════════════════════════════════
//
//  Two switches the owner flips by hand from the admin panel — one for
//  delivery, one for collection — with immediate effect. Not a rota: the
//  reason a shop stops taking deliveries at half past six is that the van
//  would not get back in time, and no timetable knows that in advance.
//
//  The two are INDEPENDENT. Closing deliveries must leave collection running,
//  because the shop is still open and people are still walking in.
//
//  Everything below reads the row cbStoreSettings() already fetched. No second
//  query, no second connection — the flags came down with the delivery charge
//  on the same SELECT, and they are memoised for the request along with it.

if (!function_exists('cbOrderingAudience')) {
    /**
     * Which pair of switches applies to the person currently browsing.
     *
     * A signed-in trade account is governed by trade_delivery_open /
     * trade_collection_open; everybody else by the original pair. Passing the
     * answer in explicitly overrides the session, which the admin's own
     * Delivery & Offers page needs: it renders BOTH pairs, and must show the
     * public switches as the public sees them even though an owner is the one
     * looking at the screen.
     */
    function cbOrderingAudience(?bool $isTrade = null): string
    {
        if ($isTrade === null) {
            // Guarded: this file is included by pages that have not started a
            // session, and touching $_SESSION there is a warning printed into
            // the middle of the shop. No session means nobody is signed in as
            // trade, which is the right answer anyway.
            $isTrade = (session_status() === PHP_SESSION_ACTIVE)
                    && !empty($_SESSION['trade_user']);
        }
        return $isTrade ? 'trade_' : '';
    }
}

if (!function_exists('cbOrderingOpen')) {
    /**
     * Is the shop taking orders on this channel right now?
     *
     * @param string $type 'delivery' or 'collection'. Exactly those two —
     *                     anything else is false. See below for why this is
     *                     strict when almost everything else here is forgiving.
     */
    function cbOrderingOpen(string $type, ?bool $isTrade = null): bool
    {
        // No case-folding, no near-misses, no guessing. Every caller that
        // gates an order has ALREADY decided which channel that order is on —
        // checkout_handler.php, for one, treats anything that is not exactly
        // 'collection' as a delivery. If this function were lenient where the
        // caller is strict, the two would disagree about what is being asked:
        // 'Collection' would be waved through as an open collection order and
        // then written to the database as a delivery, on an evening when
        // delivery is precisely what the owner has switched off.
        //
        // So callers canonicalise first and pass one of these two words. An
        // unrecognised channel is not a channel this shop can take an order
        // on, and false is the honest answer.
        //
        // This is NOT the fail-open rule bending. Fail-open is about the
        // DATABASE being unreachable, which is a fault of ours and must not
        // cost the owner business. A channel name the shop does not use is a
        // different thing entirely, and refusing it is correct.
        if ($type !== 'delivery' && $type !== 'collection') {
            return false;
        }

        $settings = cbStoreSettings();
        $prefix   = cbOrderingAudience($isTrade);

        // ?? 1 for the same reason the read in cbStoreSettings() leans this
        // way: if the key is somehow absent, the shop stays open.
        return (int)($settings[$prefix . $type . '_open'] ?? 1) === 1;
    }
}

if (!function_exists('cbOrderingClosedNote')) {
    /**
     * The line to show a customer about a channel that is switched off.
     *
     * Returns the owner's own wording if they typed any, and otherwise writes
     * a sentence that fits the situation. Empty string when there is nothing
     * to say — either the channel is running, or the channel is not one this
     * shop has.
     *
     * THE DEFAULT WORDING LOOKS AT THE OTHER CHANNEL. Sending someone to come
     * and collect when collection is shut as well is worse than saying
     * nothing: they read a helpful sentence, drive over, and find the door
     * closed. So when both are off, neither message points anywhere — and both
     * calls return the SAME sentence, which a page showing both channels
     * should print once rather than twice.
     *
     * Admin note: this is the customer's view, not the editor's. A settings
     * page wanting to show the owner what they themselves typed — including
     * "nothing yet" — should read $settings['delivery_closed_note'] /
     * ['collection_closed_note'] straight off cbStoreSettings(), which is NULL
     * until they write something.
     *
     * @param string $type 'delivery' or 'collection'.
     */
    function cbOrderingClosedNote(string $type, ?bool $isTrade = null): string
    {
        if ($type !== 'delivery' && $type !== 'collection') {
            return '';
        }

        // Nothing to explain about a channel that is taking orders. Guarding
        // here means a page that calls this without checking first cannot
        // announce a closure to a customer of a shop that is wide open — the
        // one mistake in this whole feature that costs money silently.
        if (cbOrderingOpen($type, $isTrade)) {
            return '';
        }

        $settings = cbStoreSettings();
        $prefix   = cbOrderingAudience($isTrade);

        // What the owner wrote always wins. They know why they closed. A trade
        // account gets the trade wording, and falls back to the public one if
        // the owner only wrote that — better a sentence meant for someone else
        // than a blank space where the reason should be.
        $own = trim((string)($settings[$prefix . $type . '_closed_note'] ?? ''));
        if ($own === '' && $prefix !== '') {
            $own = trim((string)($settings[$type . '_closed_note'] ?? ''));
        }
        if ($own !== '') {
            return $own;
        }

        $otherOpen = cbOrderingOpen($type === 'delivery' ? 'collection' : 'delivery', $isTrade);

        // Warm and calm, and never shouted. A customer reading this was about
        // to give the shop money; they should come away thinking "fair enough,
        // I'll try tomorrow", not "what a shambles".
        if (!$otherOpen) {
            return 'We have paused orders for the moment. Please do check back a little later — we are sorry to keep you waiting.';
        }

        return $type === 'delivery'
            ? 'We have stopped taking delivery orders for now. Collection is still available.'
            : 'We have stopped taking collection orders for now. Please choose delivery.';
    }
}

if (!function_exists('cbAnyOrderingOpen')) {
    /**
     * Can the shop take an order at all, by any means?
     *
     * False only when the owner has switched BOTH channels off — the point at
     * which the checkout has nothing to offer and should say so plainly
     * instead of showing a customer a form that cannot be submitted.
     */
    function cbAnyOrderingOpen(?bool $isTrade = null): bool
    {
        return cbOrderingOpen('delivery', $isTrade) || cbOrderingOpen('collection', $isTrade);
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
