<?php
// ============================================================
//  Creamy Bite – Admin: Store Control handler
//
//  Everything admin/store.php saves comes through here:
//
//      set_channel_open  open or close delivery orders, or collection orders,
//                        and the line customers are shown while it is closed
//      save_settings   the delivery charge, the two distances, the distance
//                      factor, the minimum order, free delivery over £X and
//                      the standing cart message
//      offer_add       create an offer
//      offer_update    edit one
//      offer_toggle    switch one on or off
//      offer_delete    remove one
//      offer_reorder   change the order they are applied in
//
//  WHY THE CHECKING IS ALL DOWN HERE
//  ---------------------------------
//  The browser also checks these boxes, because telling somebody the moment
//  they type is kinder than telling them after they press Save. But a browser
//  check is a courtesy, not a guard: it can be switched off, edited, or simply
//  bypassed by posting to this file directly. So every rule is enforced HERE,
//  and the page's own checks are only there to save the owner a round trip.
//
//  These figures are what customers get charged. A radius smaller than the
//  free-delivery radius would give free delivery to everyone we deliver to; a
//  minus delivery charge would pay people to order. Neither is a typo worth
//  finding out about from a bank statement.
//
//  HOW A REFUSAL COMES BACK
//  ------------------------
//      { success: false,
//        message: "one plain sentence for the top of the page",
//        errors:  { "delivery_charge": "the sentence to show under that box" } }
//
//  The page puts each sentence under the box it names, so the owner is looking
//  at the thing that is wrong rather than hunting for it.
// ============================================================

$GLOBALS['ADMIN_GUARD_JSON'] = true;   // reply in JSON, not an HTML redirect
require_once __DIR__ . '/../_guard.php';
header('Content-Type: application/json');
csrfCheckJson();
require_once __DIR__ . '/../_permissions.php';
adminRequire('store');

require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/db.php';
// cbStoreSettingDefaults() — used by set_channel_open below to build a complete
// settings row if this database somehow has none. config.php already pulls this
// file in; naming it here says out loud that this handler depends on it.
require_once __DIR__ . '/../../includes/store_settings.php';

// The three enums, spelled exactly as the columns declare them in
// admin/migrations/update_db.php. Anything not in these lists never reaches
// the database, so a hand-crafted POST cannot store a value the offer maths
// in includes/offers.php would not recognise.
const CBST_TYPES     = ['bogo', 'buy_x_get_y', 'percent_off', 'fixed_off', 'free_delivery', 'free_item_over'];
const CBST_SCOPES    = ['all', 'category', 'product'];
const CBST_AUDIENCES = ['retail', 'trade', 'both'];

// The two ways a customer can order, spelled EXACTLY as the columns spell them
// and exactly as includes/store_settings.php spells them. cbOrderingOpen()
// matches these two words and nothing else, so a third spelling accepted here
// would be a channel the owner could close from this page and the checkout
// would never enforce. It is also what makes it safe for the SQL below to put
// the channel name into a column name.
const CBST_CHANNELS = ['delivery', 'collection'];

// How each channel reads in a sentence, so the confirmation the owner gets back
// says what actually happened rather than "Saved."
const CBST_CHANNEL_WORDS = [
    'delivery'   => ['label' => 'Delivery',   'verb' => 'have an order delivered'],
    'collection' => ['label' => 'Collection', 'verb' => 'come and collect an order'],
];

// Upper limits. The columns themselves would take more (DECIMAL(8,2) holds
// nearly a million), but a £900,000 delivery charge is a slipped finger, not
// a business decision, and it is far kinder to refuse it than to store it.
const CBST_MAX_CHARGE = 500.00;    // delivery charge
const CBST_MAX_MILES  = 500.00;    // either distance
const CBST_MAX_MONEY  = 10000.00;  // minimums, thresholds and money-off amounts

// ────────────────────────────────────────────────────────────
//  Replying
// ────────────────────────────────────────────────────────────

/** Send a reply and stop. */
function cbstReply(array $payload): void
{
    echo json_encode($payload);
    exit;
}

/**
 * Refuse, with each problem attached to the box that caused it.
 *
 * When there is only one thing wrong, that one sentence is also the headline —
 * repeating it twice on the same screen reads as two separate faults.
 */
function cbstRefuse(array $errors, string $message = ''): void
{
    if ($message === '') {
        $message = (count($errors) === 1)
            ? (string)reset($errors)
            : 'Please have a look at the boxes marked below.';
    }
    cbstReply(['success' => false, 'message' => $message, 'errors' => $errors]);
}

// ────────────────────────────────────────────────────────────
//  Reading what was typed
// ────────────────────────────────────────────────────────────

/** A figure the way the owner would write it: "£1.99", or "3" not "3.00". */
function cbstShow(float $value, bool $isMoney): string
{
    return $isMoney
        ? '£' . number_format($value, 2)
        : rtrim(rtrim(number_format($value, 2), '0'), '.');
}

/**
 * Read a number out of a form field, or explain what is wrong with it.
 *
 * Accepts what a person actually types — "£1.99", "1,000", " 3 " — because
 * refusing a pound sign in a box labelled "delivery charge" is the computer
 * being difficult about something it can plainly understand.
 *
 * Returns null and fills $error when the value cannot be used.
 */
function cbstNumber($raw, bool $isMoney, float $min, float $max, ?string &$error): ?float
{
    $error = null;

    $text = str_replace(['£', ',', ' '], '', trim((string)$raw));
    if ($text === '') {
        $error = 'This one cannot be left empty.';
        return null;
    }
    if (!is_numeric($text)) {
        $error = 'That is not a number. Write it in figures — for example ' . ($isMoney ? '1.99' : '3') . '.';
        return null;
    }

    $value = round((float)$text, 2);

    // Called out separately from the range check below: "cannot be a minus
    // number" tells the owner what they did, where "between £0.00 and £500.00"
    // leaves them working it out.
    if ($value < 0 && $min >= 0) {
        $error = 'This cannot be a minus number.';
        return null;
    }
    if ($value < $min || $value > $max) {
        $error = 'Enter something between ' . cbstShow($min, $isMoney)
               . ' and ' . cbstShow($max, $isMoney) . '.';
        return null;
    }

    return $value;
}

/**
 * Read a date box. Empty means "no date set", which is allowed everywhere a
 * date appears here — an offer with no start date has always been running and
 * one with no end date runs until it is switched off.
 */
function cbstDate($raw, ?string &$error): ?string
{
    $error = null;
    $text  = trim((string)$raw);
    if ($text === '') {
        return null;
    }
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $text, $m)
        || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
        $error = 'That is not a real date. Please pick one from the calendar.';
        return null;
    }
    return $text;
}

/** A whole number of items, for the "buy 2, get 1" boxes. */
function cbstWholeNumber($raw, int $min, int $max, ?string &$error): ?int
{
    $error = null;
    $text  = trim((string)$raw);
    if ($text === '') {
        $error = 'This one cannot be left empty.';
        return null;
    }
    if (!preg_match('/^\d+$/', $text)) {
        $error = 'This has to be a whole number of items — 1, 2, 3 and so on.';
        return null;
    }
    $value = (int)$text;
    if ($value < $min) {
        $error = 'This has to be at least ' . $min . '.';
        return null;
    }
    if ($value > $max) {
        $error = 'That is more than ' . $max . ' items, which is almost certainly a slip.';
        return null;
    }
    return $value;
}

// ────────────────────────────────────────────────────────────
//  Checking an offer
// ────────────────────────────────────────────────────────────

/**
 * Check everything posted for one offer and turn it into the exact set of
 * columns the offers table wants.
 *
 * Used by BOTH offer_add and offer_update, so an offer can never be created
 * under one set of rules and then edited under a looser one.
 *
 * Columns that have no meaning for the chosen kind of offer are set to NULL
 * rather than left at whatever the form happened to send. A "10% off" row
 * carrying a leftover buy quantity of 3 is a row that invites somebody to
 * write code that reads it.
 *
 * @param array       $in      the posted fields
 * @param array<string,string> $errors  filled in with problems, by field name
 * @return array|null the columns to write, or null when something is wrong
 */
function cbstValidateOffer(array $in, array &$errors): ?array
{
    $errors = [];

    // ── What it is called ───────────────────────────────────
    $name = trim((string)($in['name'] ?? ''));
    if ($name === '') {
        $errors['name'] = 'Give this offer a name so you can recognise it in the list later.';
    } elseif (mb_strlen($name) > 120) {
        $errors['name'] = 'That name is too long — keep it under 120 characters.';
    }

    // ── What kind of offer ──────────────────────────────────
    $type = (string)($in['type'] ?? '');
    if (!in_array($type, CBST_TYPES, true)) {
        $errors['type'] = 'Choose what kind of offer this is.';
        // Everything below depends on knowing the kind, so there is nothing
        // sensible left to check.
        return null;
    }

    // ── What it applies to ──────────────────────────────────
    $scope      = (string)($in['scope'] ?? 'all');
    $scopeValue = trim((string)($in['scope_value'] ?? ''));
    if (!in_array($scope, CBST_SCOPES, true)) {
        $errors['scope'] = 'Choose whether this is on everything, one category or one product.';
        $scope = 'all';
    }
    if ($scope === 'all') {
        $scopeValue = null;
    } elseif ($scopeValue === '') {
        $errors['scope_value'] = $scope === 'category'
            ? 'Choose which category this offer is on.'
            : 'Choose which product this offer is on.';
    } elseif (mb_strlen($scopeValue) > 120) {
        $errors['scope_value'] = 'That is too long to store — please pick from the list.';
    }

    // ── Who gets it ─────────────────────────────────────────
    $appliesTo = (string)($in['applies_to'] ?? 'retail');
    if (!in_array($appliesTo, CBST_AUDIENCES, true)) {
        $errors['applies_to'] = 'Choose who this offer is for.';
        $appliesTo = 'retail';
    }

    // ── The spend threshold, where there is one ─────────────
    // Optional on most kinds and required on the free-gift one, so it is read
    // first and then checked again below where it matters.
    $minSpend = null;
    $rawMin   = trim((string)($in['min_spend'] ?? ''));
    if ($rawMin !== '') {
        $err      = null;
        $minSpend = cbstNumber($rawMin, true, 0, CBST_MAX_MONEY, $err);
        if ($err !== null) {
            $errors['min_spend'] = $err;
        }
    }

    // ── The numbers that belong to this kind of offer ───────
    $buyQty = null;
    $getQty = null;
    $amount = null;

    switch ($type) {

        case 'bogo':
            // Buy one, get one free. The quantities are fixed at one and one —
            // includes/offers.php treats this kind as [1, 1] whatever is stored,
            // so writing anything else here would create a row that disagrees
            // with what the shop actually does.
            $buyQty = 1;
            $getQty = 1;
            break;

        case 'buy_x_get_y':
            $err    = null;
            $buyQty = cbstWholeNumber($in['buy_qty'] ?? '', 1, 99, $err);
            if ($err !== null) {
                $errors['buy_qty'] = 'How many they buy: ' . lcfirst($err);
            }
            $err    = null;
            $getQty = cbstWholeNumber($in['get_qty'] ?? '', 1, 99, $err);
            if ($err !== null) {
                $errors['get_qty'] = 'How many they get free: ' . lcfirst($err);
            }
            break;

        case 'percent_off':
            $err    = null;
            $amount = cbstNumber($in['amount'] ?? '', false, 0, 100, $err);
            if ($err !== null) {
                $errors['amount'] = $err;
            } elseif ($amount <= 0) {
                $errors['amount'] = 'A percentage off has to be more than 0 — otherwise nothing comes off.';
            }
            break;

        case 'fixed_off':
            $err    = null;
            $amount = cbstNumber($in['amount'] ?? '', true, 0, CBST_MAX_MONEY, $err);
            if ($err !== null) {
                $errors['amount'] = $err;
            } elseif ($amount <= 0) {
                $errors['amount'] = 'Say how much comes off — it has to be more than £0.00.';
            }
            break;

        case 'free_delivery':
            // Nothing else to set. A threshold is optional and was read above.
            break;

        case 'free_item_over':
            // The threshold IS this offer — without one it would give a free
            // gift to a basket with a single tub in it.
            if ($minSpend === null && !isset($errors['min_spend'])) {
                $errors['min_spend'] = 'Say how much they need to spend before the gift is earned.';
            } elseif ($minSpend !== null && $minSpend <= 0) {
                $errors['min_spend'] = 'The spend has to be more than £0.00, or everybody gets one.';
            }
            // One gift per qualifying basket. offers.php reads the quantity
            // from get_qty, so it is stored rather than assumed.
            $getQty = 1;
            break;
    }

    // ── The wording ─────────────────────────────────────────
    $badge = trim((string)($in['badge_text'] ?? ''));
    if (mb_strlen($badge) > 40) {
        $errors['badge_text'] = 'Keep this under 40 characters — it has to fit on a small label.';
    }
    if ($type === 'free_item_over' && $badge === '' && !isset($errors['badge_text'])) {
        $errors['badge_text'] = 'Say what the free gift is, so the customer knows what they have earned.';
    }

    $cartMessage = trim((string)($in['cart_message'] ?? ''));
    if (mb_strlen($cartMessage) > 255) {
        $errors['cart_message'] = 'That message is too long — keep it under 255 characters.';
    }

    // ── When it runs ────────────────────────────────────────
    $err      = null;
    $startsOn = cbstDate($in['starts_on'] ?? '', $err);
    if ($err !== null) {
        $errors['starts_on'] = $err;
    }
    $err    = null;
    $endsOn = cbstDate($in['ends_on'] ?? '', $err);
    if ($err !== null) {
        $errors['ends_on'] = $err;
    }
    if ($startsOn !== null && $endsOn !== null && $endsOn < $startsOn) {
        $errors['ends_on'] = 'The finish date is before the start date, so this offer would never run.';
    }

    if ($errors !== []) {
        return null;
    }

    return [
        'name'         => $name,
        'type'         => $type,
        'scope'        => $scope,
        'scope_value'  => $scopeValue,
        'buy_qty'      => $buyQty,
        'get_qty'      => $getQty,
        'amount'       => $amount,
        'min_spend'    => $minSpend,
        'cart_message' => $cartMessage === '' ? null : $cartMessage,
        'badge_text'   => $badge === '' ? null : $badge,
        'applies_to'   => $appliesTo,
        'active'       => !empty($in['active']) ? 1 : 0,
        'starts_on'    => $startsOn,
        'ends_on'      => $endsOn,
    ];
}

// ────────────────────────────────────────────────────────────
//  The actions
// ────────────────────────────────────────────────────────────

$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');

try {

    // ── Open or close a way of ordering ─────────────────────
    //
    // "We are closing early tonight, stop taking delivery orders." One channel
    // at a time and with immediate effect: the flag is read fresh on every
    // request by cbStoreSettings(), so the next customer to reach the checkout
    // is refused, and the one already sitting on the page is refused when they
    // press the button.
    //
    // The two channels are INDEPENDENT. Nothing here reads or writes the other
    // one's columns, so closing deliveries can never disturb collection.
    //
    // The optional note travels with the switch rather than having a save of
    // its own, because it is the SAME thought: the owner is closing a channel
    // and saying why. It means a note typed but not yet saved is kept when the
    // switch is flipped, instead of being quietly thrown away.
    if ($action === 'set_channel_open') {

        $channel = (string)($_POST['channel'] ?? '');
        if (!in_array($channel, CBST_CHANNELS, true)) {
            // Not something a person can do from the page — only a hand-made
            // POST reaches here, so there is no field to hang a sentence on.
            cbstRefuse([], 'That is not a way of ordering this shop has.');
        }

        // Exactly "1" or "0". Not "on", not "true", not empty — this decides
        // whether a real shop is taking money tonight, and a value nobody meant
        // must not be read as either answer.
        $rawOpen = (string)($_POST['open'] ?? '');
        if ($rawOpen !== '0' && $rawOpen !== '1') {
            cbstRefuse([], 'It was not clear whether that should be opened or closed, so nothing has been changed. Reload the page and try again.');
        }
        $open = (int)$rawOpen;

        // Blank is normal and means "no wording of my own" — the shop then
        // writes a sentence that fits, in cbOrderingClosedNote(). The column is
        // VARCHAR(255), so anything longer would be cut off by MySQL and the
        // owner would never know which half a customer was reading.
        $note = trim((string)($_POST['note'] ?? ''));
        if (mb_strlen($note) > 255) {
            cbstRefuse([
                'note_' . $channel => 'That message is too long — keep it under 255 characters.',
            ]);
        }

        // Read BEFORE writing, so the confirmation can tell the owner what
        // actually changed: flipping the switch and saving a message are the
        // same action here, and "Saved" would not say which one they just did.
        $before = $pdo->query(
            "SELECT `delivery_open`, `collection_open`, `delivery_closed_note`, `collection_closed_note`
               FROM `store_settings` WHERE `id` = 1"
        )->fetch();
        $before = is_array($before) ? $before : [];

        $wasOpen  = (isset($before[$channel . '_open']) && (int)$before[$channel . '_open'] === 0) ? 0 : 1;
        $wasNote  = trim((string)($before[$channel . '_closed_note'] ?? ''));

        // INSERT ... ON DUPLICATE KEY for the same reason save_settings uses it:
        // the settings row is always id 1, so this can only ever touch that one
        // row, and a database whose seed row went missing gets a complete and
        // sensible one rather than a half-filled row of zeroes. The figures in
        // the INSERT half are the shop's own built-in fallbacks — the same ones
        // the site would have been running on with no row at all — so nothing
        // about delivery pricing changes on that path either.
        //
        // On the ordinary path (the row exists) ONLY this channel's two columns
        // are written. The other channel is not named anywhere in the UPDATE.
        //
        // The column names are interpolated, which is safe and is the reason
        // $channel is checked against CBST_CHANNELS above and never used
        // otherwise: a column name cannot be a bound parameter.
        $defaults = cbStoreSettingDefaults();

        $pdo->prepare(
            "INSERT INTO `store_settings`
                (id, delivery_charge, free_delivery_miles, delivery_radius_miles,
                 delivery_distance_factor, min_delivery_order,
                 `{$channel}_open`, `{$channel}_closed_note`, updated_at, updated_by)
             VALUES
                (1, :charge, :free_miles, :radius, :factor, :min_order,
                 :open, :note, NOW(), :who)
             ON DUPLICATE KEY UPDATE
                `{$channel}_open`        = VALUES(`{$channel}_open`),
                `{$channel}_closed_note` = VALUES(`{$channel}_closed_note`),
                updated_at               = VALUES(updated_at),
                updated_by               = VALUES(updated_by)"
        )->execute([
            'charge'     => $defaults['delivery_charge'],
            'free_miles' => $defaults['free_delivery_miles'],
            'radius'     => $defaults['delivery_radius_miles'],
            'factor'     => $defaults['delivery_distance_factor'],
            'min_order'  => $defaults['min_delivery_order'],
            'open'       => $open,
            'note'       => $note === '' ? null : $note,
            'who'        => mb_substr(adminStaffName(), 0, 120),
        ]);

        // Read the row back rather than reporting what we hoped we wrote. What
        // the page paints on screen afterwards comes from THIS, so the switches
        // an owner is looking at are the switches the shop is actually running.
        $after = $pdo->query(
            "SELECT `delivery_open`, `collection_open`, `delivery_closed_note`, `collection_closed_note`,
                    `updated_at`, `updated_by`
               FROM `store_settings` WHERE `id` = 1"
        )->fetch();
        $after = is_array($after) ? $after : [];

        // Read exactly the way includes/store_settings.php reads it — a channel
        // is closed only when the row says so in as many words, and anything
        // else (missing, NULL, unreadable) leaves it open. The admin page must
        // never show "Closed" for a shop the checkout is still serving.
        $deliveryOpen   = (isset($after['delivery_open'])   && (int)$after['delivery_open']   === 0) ? 0 : 1;
        $collectionOpen = (isset($after['collection_open']) && (int)$after['collection_open'] === 0) ? 0 : 1;
        $nowOpen        = $channel === 'delivery' ? $deliveryOpen : $collectionOpen;
        $nowNote        = trim((string)($after[$channel . '_closed_note'] ?? ''));
        $anyOpen        = ($deliveryOpen === 1 || $collectionOpen === 1);

        $label = CBST_CHANNEL_WORDS[$channel]['label'];
        $verb  = CBST_CHANNEL_WORDS[$channel]['verb'];

        if ($wasOpen !== $nowOpen) {
            $message = $nowOpen === 1
                ? $label . ' orders are open again — customers can ' . $verb . '.'
                : $label . ' orders are closed. Nobody can ' . $verb . ' until you switch this back on.';
            if (!$anyOpen) {
                $message .= ' Both ways of ordering are off now, so your shop is not taking any orders at all.';
            }
        } elseif ($wasNote !== $nowNote) {
            $message = 'Your message is saved. ' . $label . ' orders are still '
                     . ($nowOpen === 1 ? 'open.' : 'closed.');
        } else {
            $message = $label . ' orders are ' . ($nowOpen === 1 ? 'open' : 'closed')
                     . '. Nothing needed changing.';
        }

        cbstReply([
            'success'         => true,
            'channel'         => $channel,
            'open'            => $nowOpen,
            'note'            => $nowNote,
            'delivery_open'   => $deliveryOpen,
            'collection_open' => $collectionOpen,
            'any_open'        => $anyOpen,
            'message'         => $message,
        ]);
    }

    // ── Delivery, minimums and the standing cart message ────
    //
    // Saved as one lot rather than field by field, because the rules that
    // matter run BETWEEN the fields: the maximum distance has to be checked
    // against the free-delivery distance, and the cart message against whether
    // it has been switched on. Saving them one at a time would let the shop
    // pass through a state where those did not hold.
    if ($action === 'save_settings') {
        $errors = [];

        $err    = null;
        $charge = cbstNumber($_POST['delivery_charge'] ?? '', true, 0, CBST_MAX_CHARGE, $err);
        if ($err !== null) { $errors['delivery_charge'] = $err; }

        $err       = null;
        $freeMiles = cbstNumber($_POST['free_delivery_miles'] ?? '', false, 0, CBST_MAX_MILES, $err);
        if ($err !== null) { $errors['free_delivery_miles'] = $err; }

        $err    = null;
        $radius = cbstNumber($_POST['delivery_radius_miles'] ?? '', false, 0, CBST_MAX_MILES, $err);
        if ($err !== null) { $errors['delivery_radius_miles'] = $err; }

        // 1.0 would mean driving in a perfectly straight line through
        // buildings; above 2.0 every road would have to be more than twice as
        // long as the crow flies, which no road is.
        $err    = null;
        $factor = cbstNumber($_POST['delivery_distance_factor'] ?? '', false, 1.0, 2.0, $err);
        if ($err !== null) {
            $errors['delivery_distance_factor'] =
                'This has to be between 1 and 2. Leave it at 1.3 unless you have a reason to change it.';
        }

        $err = null;
        $min = cbstNumber($_POST['min_delivery_order'] ?? '', true, 0, CBST_MAX_MONEY, $err);
        if ($err !== null) { $errors['min_delivery_order'] = $err; }

        // Blank means the shop does not offer free delivery on big baskets.
        // Zero would mean free delivery on everything, which is not what
        // somebody clearing the box is asking for.
        $freeOver = null;
        $rawOver  = trim((string)($_POST['free_delivery_over'] ?? ''));
        if ($rawOver !== '') {
            $err      = null;
            $freeOver = cbstNumber($rawOver, true, 0, CBST_MAX_MONEY, $err);
            if ($err !== null) {
                $errors['free_delivery_over'] = $err;
            } elseif ($freeOver <= 0) {
                $errors['free_delivery_over'] =
                    'Leave this box empty to switch free delivery over a certain spend off. £0.00 would give everybody free delivery.';
            }
        }

        // Everyone we deliver to would get free delivery, and the charge above
        // could never apply to anybody.
        if ($radius !== null && $freeMiles !== null && $radius < $freeMiles) {
            $errors['delivery_radius_miles'] =
                'This has to be at least as far as your free delivery distance ('
                . cbstShow($freeMiles, false) . ' miles), otherwise every delivery would be free.';
        }

        $cartMessage = trim((string)($_POST['cart_message'] ?? ''));
        if (mb_strlen($cartMessage) > 255) {
            $errors['cart_message'] = 'That message is too long — keep it under 255 characters.';
        }
        $cartActive = !empty($_POST['cart_message_active']) ? 1 : 0;
        if ($cartActive === 1 && $cartMessage === '') {
            $errors['cart_message'] =
                'Write the message you want customers to see, or untick the box above to show nothing.';
        }

        if ($errors !== []) {
            cbstRefuse($errors);
        }

        // INSERT ... ON DUPLICATE KEY so this still works on a database where
        // the seed row is missing — the settings row is always id 1, so this
        // can only ever write the one row.
        //
        // updated_at is set by hand rather than left to the column's ON UPDATE:
        // saving a form without changing a figure leaves the row untouched as
        // far as MySQL is concerned, and "last saved" would then be wrong.
        $pdo->prepare(
            "INSERT INTO `store_settings`
                (id, delivery_charge, free_delivery_miles, delivery_radius_miles,
                 delivery_distance_factor, min_delivery_order, free_delivery_over,
                 cart_message, cart_message_active, updated_at, updated_by)
             VALUES
                (1, :charge, :free_miles, :radius, :factor, :min_order, :free_over,
                 :message, :message_on, NOW(), :who)
             ON DUPLICATE KEY UPDATE
                delivery_charge          = VALUES(delivery_charge),
                free_delivery_miles      = VALUES(free_delivery_miles),
                delivery_radius_miles    = VALUES(delivery_radius_miles),
                delivery_distance_factor = VALUES(delivery_distance_factor),
                min_delivery_order       = VALUES(min_delivery_order),
                free_delivery_over       = VALUES(free_delivery_over),
                cart_message             = VALUES(cart_message),
                cart_message_active      = VALUES(cart_message_active),
                updated_at               = VALUES(updated_at),
                updated_by               = VALUES(updated_by)"
        )->execute([
            'charge'     => $charge,
            'free_miles' => $freeMiles,
            'radius'     => $radius,
            'factor'     => $factor,
            'min_order'  => $min,
            'free_over'  => $freeOver,
            'message'    => $cartMessage === '' ? null : $cartMessage,
            'message_on' => $cartActive,
            'who'        => mb_substr(adminStaffName(), 0, 120),
        ]);

        cbstReply(['success' => true, 'message' => 'Your delivery settings are saved.']);
    }

    // ── Create an offer ─────────────────────────────────────
    if ($action === 'offer_add') {
        $errors = [];
        $offer  = cbstValidateOffer($_POST, $errors);
        if ($offer === null) {
            cbstRefuse($errors);
        }

        // New offers go on the end. Order matters: offers are applied in this
        // order, and each one can only take money off what the ones before it
        // have left.
        $offer['sort_order'] = 1 + (int)$pdo->query("SELECT COALESCE(MAX(sort_order), 0) FROM `offers`")->fetchColumn();

        $pdo->prepare(
            "INSERT INTO `offers`
                (name, type, scope, scope_value, buy_qty, get_qty, amount, min_spend,
                 cart_message, badge_text, applies_to, active, starts_on, ends_on,
                 sort_order, created_at)
             VALUES
                (:name, :type, :scope, :scope_value, :buy_qty, :get_qty, :amount, :min_spend,
                 :cart_message, :badge_text, :applies_to, :active, :starts_on, :ends_on,
                 :sort_order, NOW())"
        )->execute($offer);

        cbstReply([
            'success' => true,
            'id'      => (int)$pdo->lastInsertId(),
            'message' => 'Offer saved.',
        ]);
    }

    // ── Edit an offer ───────────────────────────────────────
    if ($action === 'offer_update') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            cbstRefuse([], 'That offer could not be found. Reload the page and try again.');
        }

        $errors = [];
        $offer  = cbstValidateOffer($_POST, $errors);
        if ($offer === null) {
            cbstRefuse($errors);
        }

        // sort_order is deliberately left alone — it is changed by the up and
        // down arrows, not by editing an offer, so saving an edit must not
        // shuffle the offer back to the end of the list.
        $offer['id'] = $id;
        $st = $pdo->prepare(
            "UPDATE `offers` SET
                name = :name, type = :type, scope = :scope, scope_value = :scope_value,
                buy_qty = :buy_qty, get_qty = :get_qty, amount = :amount, min_spend = :min_spend,
                cart_message = :cart_message, badge_text = :badge_text, applies_to = :applies_to,
                active = :active, starts_on = :starts_on, ends_on = :ends_on
              WHERE id = :id"
        );
        $st->execute($offer);

        if ($st->rowCount() === 0) {
            // Either nothing changed, or the row is gone. Only the second is a
            // problem, so check rather than guess.
            $exists = $pdo->prepare("SELECT COUNT(*) FROM `offers` WHERE id = ?");
            $exists->execute([$id]);
            if ((int)$exists->fetchColumn() === 0) {
                cbstRefuse([], 'That offer has already been deleted. Reload the page.');
            }
        }

        cbstReply(['success' => true, 'message' => 'Offer saved.']);
    }

    // ── Switch an offer on or off ───────────────────────────
    if ($action === 'offer_toggle') {
        $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
        if ($id <= 0) {
            cbstRefuse([], 'That offer could not be found. Reload the page and try again.');
        }
        $pdo->prepare("UPDATE `offers` SET active = 1 - active WHERE id = ?")->execute([$id]);

        $st = $pdo->prepare("SELECT active FROM `offers` WHERE id = ?");
        $st->execute([$id]);
        $row = $st->fetch();
        if (!$row) {
            cbstRefuse([], 'That offer has already been deleted. Reload the page.');
        }

        cbstReply([
            'success' => true,
            'active'  => (int)$row['active'],
            'message' => (int)$row['active'] === 1
                ? 'That offer is now running.'
                : 'That offer is switched off.',
        ]);
    }

    // ── Delete an offer ─────────────────────────────────────
    if ($action === 'offer_delete') {
        $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
        if ($id <= 0) {
            cbstRefuse([], 'That offer could not be found. Reload the page and try again.');
        }
        $pdo->prepare("DELETE FROM `offers` WHERE id = ?")->execute([$id]);
        cbstReply(['success' => true, 'message' => 'Offer deleted.']);
    }

    // ── Change the order they are applied in ────────────────
    if ($action === 'offer_reorder') {
        $ids = (array)($_POST['ids'] ?? []);
        if ($ids === []) {
            cbstRefuse([], 'Nothing to reorder.');
        }

        // One transaction: half-applied ordering would leave two offers
        // claiming the same position, and the list would appear to jump about
        // on the next load.
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare("UPDATE `offers` SET sort_order = ? WHERE id = ?");
            foreach (array_values($ids) as $position => $id) {
                $id = (int)$id;
                if ($id > 0) {
                    $st->execute([$position + 1, $id]);
                }
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        cbstReply(['success' => true, 'message' => 'Order updated.']);
    }

    cbstRefuse([], 'That is not something this page knows how to do.');

} catch (Throwable $e) {
    // The guard in admin/_guard.php also catches uncaught throwables and
    // replies as JSON, but catching here lets the message say which page the
    // owner was on when it happened.
    error_log('Store handler error: ' . $e->getMessage());
    cbstReply([
        'success' => false,
        'message' => 'Something went wrong while saving: ' . $e->getMessage(),
    ]);
}
