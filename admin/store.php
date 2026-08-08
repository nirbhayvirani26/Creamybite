<?php
// ============================================================
//  Creamy Bite – Delivery & Offers
//
//  The one page that controls what a customer is charged to have ice cream
//  brought to their door, what offers are running, and what the basket says
//  to them while they shop.
//
//  Four sections, in the order the owner thinks about them:
//
//    1  Delivery & minimums   the charge, the two distances, the smallest
//                             basket, and free delivery over a certain spend
//    2  Offers                buy one get one free, money off, free delivery,
//                             a free gift — created from dropdowns
//    3  Cart message          the standing note shown in every basket
//    4  What a customer pays  the saved figures worked through in words, so
//                             the effect of a change can be read without
//                             placing a test order
//
//  NOTHING IS CALCULATED TWICE
//  ---------------------------
//  The preview in section 4 does not re-implement the delivery rules. It calls
//  cbstDeliveryOutcome() below, which is written once and follows exactly what
//  the live site does:
//
//      checkout_handler.php  measures the distance, refuses anything outside
//                            DELIVERY_RADIUS_MILES, then refuses a basket
//                            under MIN_DELIVERY_ORDER
//      pricing.php           charges DELIVERY_CHARGE unless the address is
//                            inside FREE_DELIVERY_MILES, or the basket has
//                            reached the "free delivery over" figure
//
//  Both of those compare against the distance AFTER it has been multiplied by
//  DELIVERY_DISTANCE_FACTOR, so this page does the same. A preview that
//  rounded differently from the checkout would be worse than no preview.
//
//  The cart messages shown in section 4 are not a mock-up either — they are
//  the real return value of cbCartMessages(), the same function the basket
//  calls.
//
//  SAVING
//  ------
//  Everything posts to admin/handlers/store_handler.php, which does all the
//  checking. The boxes below are plain text rather than number boxes on
//  purpose: the handler understands "£1.99" and "1,000", and a browser that
//  silently refuses to submit a box teaches the owner nothing about why.
// ============================================================

require_once __DIR__ . '/_guard.php';
require_once __DIR__ . '/_permissions.php';
adminRequire('store');

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/store_settings.php';
require_once __DIR__ . '/../includes/offers.php';

// ────────────────────────────────────────────────────────────
//  Small helpers for showing figures
//
//  Named apart from the handler's helpers (cbstShow, cbstNumber and friends)
//  even though the two files are never loaded together — a name that is free
//  today is one less thing to trip over later.
// ────────────────────────────────────────────────────────────

/** £1.99 */
function cbstFmtMoney(float $value): string
{
    return '£' . number_format($value, 2);
}

/** 3 rather than 3.00, but 3.5 stays 3.5 — how a person writes a distance. */
function cbstFmtMiles(float $value): string
{
    return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
}

/** The value to put in a text box: blank for "not set", not "0.00". */
function cbstBox(?float $value, bool $isMoney): string
{
    if ($value === null) {
        return '';
    }
    return $isMoney ? number_format($value, 2, '.', '') : cbstFmtMiles($value);
}

// ────────────────────────────────────────────────────────────
//  What one customer would actually pay
//
//  THE ONE PLACE the delivery rules are worked out on this page. Section 4
//  calls it; nothing else re-states the rules in its own words.
//
//  $straightMiles is the distance on a map in a straight line, which is what
//  the postcode lookup measures. The site then multiplies it by the distance
//  factor to estimate the real driving distance, and it is THAT figure both
//  the free-delivery distance and the maximum radius are compared against.
//
//  @return array{road:float, status:string, charge:float, headline:string, detail:string}
// ────────────────────────────────────────────────────────────
function cbstDeliveryOutcome(array $s, float $straightMiles, float $spend): array
{
    $road   = round($straightMiles * (float)$s['delivery_distance_factor'], 2);
    $radius = (float)$s['delivery_radius_miles'];
    $free   = (float)$s['free_delivery_miles'];
    $min    = (float)$s['min_delivery_order'];
    $charge = (float)$s['delivery_charge'];
    $over   = $s['free_delivery_over'] !== null ? (float)$s['free_delivery_over'] : null;

    $out = ['road' => $road, 'status' => 'charged', 'charge' => $charge, 'headline' => '', 'detail' => ''];

    // 1. Too far to deliver at all. Checked first, exactly as
    //    checkout_handler.php checks it first — there is no point telling
    //    somebody their basket is too small if we would not drive there anyway.
    if ($road > $radius) {
        $out['status']   = 'too_far';
        $out['charge']   = 0.0;
        $out['headline'] = 'Cannot order delivery';
        $out['detail']   = 'That is about ' . cbstFmtMiles($road) . ' miles by road, which is past your '
                         . cbstFmtMiles($radius) . '-mile limit. They are offered collection instead.';
        return $out;
    }

    // 2. Basket too small.
    if ($spend + 0.005 < $min) {
        $out['status']   = 'below_minimum';
        $out['charge']   = 0.0;
        $out['headline'] = 'Cannot order delivery yet';
        $out['detail']   = 'Your smallest delivery basket is ' . cbstFmtMoney($min) . ', so they are asked to add '
                         . cbstFmtMoney($min - $spend) . ' more before they can check out.';
        return $out;
    }

    // 3. Close enough that delivery is free.
    if ($road <= $free) {
        $out['status']   = 'free_near';
        $out['charge']   = 0.0;
        $out['headline'] = 'Delivery is free';
        $out['detail']   = 'About ' . cbstFmtMiles($road) . ' miles by road, which is inside your '
                         . cbstFmtMiles($free) . '-mile free delivery distance.';
        return $out;
    }

    // 4. Far enough to be charged, but they have spent enough to earn it free.
    //    Measured against the basket total before any discount, which is what
    //    pricing.php compares and what the basket tells the customer.
    if ($over !== null && $over > 0 && cbOfferAtLeast($spend, $over)) {
        $out['status']   = 'free_spend';
        $out['charge']   = 0.0;
        $out['headline'] = 'Delivery is free';
        $out['detail']   = 'It would normally be ' . cbstFmtMoney($charge) . ' at about ' . cbstFmtMiles($road)
                         . ' miles, but they have spent ' . cbstFmtMoney($over) . ' or more.';
        return $out;
    }

    // 5. The ordinary case.
    $out['headline'] = 'Pays ' . cbstFmtMoney($charge) . ' delivery';
    $out['detail']   = 'About ' . cbstFmtMiles($road) . ' miles by road, which is past your '
                     . cbstFmtMiles($free) . '-mile free delivery distance but inside the '
                     . cbstFmtMiles($radius) . '-mile limit.';
    if ($over !== null && $over > 0) {
        $out['detail'] .= ' They would need to spend ' . cbstFmtMoney($over) . ' to have it free.';
    }
    return $out;
}

/** How an offer row reads in plain English, for the list. */
function cbstOfferWords(array $o): string
{
    $type  = (string)$o['type'];
    $buy   = (int)($o['buy_qty'] ?? 0);
    $get   = (int)($o['get_qty'] ?? 0);
    $amt   = $o['amount'] !== null ? (float)$o['amount'] : null;
    $min   = $o['min_spend'] !== null ? (float)$o['min_spend'] : null;
    $badge = trim((string)($o['badge_text'] ?? ''));

    switch ($type) {
        case 'bogo':
            $words = 'Buy one, get one free';
            break;
        case 'buy_x_get_y':
            $words = 'Buy ' . $buy . ', get ' . $get . ' free';
            break;
        case 'percent_off':
            $words = ($amt !== null ? cbstFmtMiles($amt) : '0') . '% off';
            break;
        case 'fixed_off':
            $words = ($amt !== null ? cbstFmtMoney($amt) : '£0.00') . ' off';
            break;
        case 'free_delivery':
            $words = 'Free delivery';
            break;
        case 'free_item_over':
            $words = 'A free ' . ($badge !== '' ? $badge : 'gift');
            break;
        default:
            $words = 'Offer';
    }

    // What it is on.
    $scope = (string)$o['scope'];
    $value = trim((string)($o['scope_value'] ?? ''));
    if ($scope === 'category' && $value !== '') {
        $words .= ' on ' . $value;
    } elseif ($scope === 'product' && $value !== '') {
        $words .= ' on one product';
    } elseif ($type !== 'free_delivery' && $type !== 'free_item_over') {
        $words .= ' on everything';
    }

    if ($min !== null && $min > 0) {
        $words .= ', when they spend ' . cbstFmtMoney($min) . ' or more';
    }

    return $words;
}

/** Running / switched off / not started / finished — with the reason. */
function cbstOfferStatus(array $o, string $today): array
{
    if ((int)$o['active'] !== 1) {
        return ['off', 'Switched off'];
    }
    if (!empty($o['starts_on']) && $o['starts_on'] > $today) {
        return ['waiting', 'Starts ' . date('j M Y', strtotime((string)$o['starts_on']))];
    }
    if (!empty($o['ends_on']) && $o['ends_on'] < $today) {
        return ['ended', 'Finished ' . date('j M Y', strtotime((string)$o['ends_on']))];
    }
    return ['live', 'Running now'];
}

// ────────────────────────────────────────────────────────────
//  What the page needs
// ────────────────────────────────────────────────────────────

$settings = cbStoreSettings();
$today    = date('Y-m-d');

$offers     = [];
$offersFail = '';
try {
    $offers = $pdo->query("SELECT * FROM `offers` ORDER BY `sort_order` ASC, `id` ASC")->fetchAll();
} catch (Throwable $e) {
    // Almost always "table doesn't exist" — the migration has not been run on
    // this server yet. Say so plainly rather than showing an empty list, which
    // would read as "you have no offers" and invite the owner to create one
    // that cannot be saved.
    $offersFail = $e->getMessage();
}

// For the two "what is this offer on?" dropdowns.
$categories = [];
$products   = [];
try {
    $categories = $pdo->query("SELECT `name` FROM `categories` ORDER BY `sort_order` ASC, `name` ASC")->fetchAll(PDO::FETCH_COLUMN);
    $products   = $pdo->query("SELECT `id`, `name` FROM `products` ORDER BY `name` ASC")->fetchAll();
} catch (Throwable $e) {
    // A missing catalogue is not this page's problem to solve; the dropdowns
    // simply come up empty and every other section still works.
    error_log('Store page could not list the catalogue: ' . $e->getMessage());
}

// ── The what-if figures for section 4 ────────────────────────
// Driven by the query string so the preview works with JavaScript switched
// off, and so a particular example can be bookmarked or sent to somebody.
//
// The starting example is worked out from the shop's own figures rather than
// being fixed. A basket under the minimum is refused at EVERY distance, so
// hard-coding a spend of £18 against a £20 minimum would fill the panel with
// four identical refusals and show nothing about the delivery charge at all.
$pvDefaultSpend = max(18.0, round((float)$settings['min_delivery_order'], 2));
// Far enough out to be charged rather than free, but inside the radius.
$pvDefaultMiles = round(
    (((float)$settings['free_delivery_miles'] + (float)$settings['delivery_radius_miles']) / 2)
        / max(1.0, (float)$settings['delivery_distance_factor']),
    1
);

$pvMiles = isset($_GET['pv_miles']) && is_numeric($_GET['pv_miles'])
    ? max(0.0, min(200.0, round((float)$_GET['pv_miles'], 2)))
    : $pvDefaultMiles;
$pvSpend = isset($_GET['pv_spend']) && is_numeric($_GET['pv_spend'])
    ? max(0.0, min(100000.0, round((float)$_GET['pv_spend'], 2)))
    : $pvDefaultSpend;

$pvResult = cbstDeliveryOutcome($settings, $pvMiles, $pvSpend);

// The real basket messages for a basket of that value, from the same function
// the shop's own basket calls. One item is used rather than a pretend spread of
// products, and the wording below says so, because an offer that needs two of
// something genuinely would not fire on this basket.
$pvMessages = [];
try {
    $pvMessages = cbCartMessages(
        [['name' => 'Sample tub', 'price' => $pvSpend, 'quantity' => 1, 'product_id' => 0]],
        $pvSpend,
        'delivery'
    );
} catch (Throwable $e) {
    error_log('Store page could not build the basket messages: ' . $e->getMessage());
}

// A short ladder of distances, so the shape of the settings is visible at a
// glance without typing anything in.
//
// The distances are worked BACK from the shop's own two boundaries rather than
// being a fixed 1/2/4/6, which would say nothing useful to a shop that delivers
// within 20 miles. Dividing by the roads allowance turns a road distance into
// the straight-line distance that produces it, which is the figure the box
// above asks for — so every row is in the same unit as the box.
//
// Each distance is rounded to one decimal place FIRST and the outcome is then
// worked out from the rounded figure, so the number on screen and the verdict
// beside it can never disagree at a boundary.
$pvFactor = max(1.0, (float)$settings['delivery_distance_factor']);
$pvFree   = (float)$settings['free_delivery_miles'];
$pvRadius = (float)$settings['delivery_radius_miles'];

$pvLadder = [];
$pvSeen   = [];
foreach ([
    ($pvFree / 2) / $pvFactor,           // comfortably inside the free distance
    $pvFree / $pvFactor,                 // right on the free-delivery boundary
    (($pvFree + $pvRadius) / 2) / $pvFactor,  // charged
    $pvRadius / $pvFactor,               // right on the edge of the area
    ($pvRadius * 1.2) / $pvFactor,       // just outside it
] as $m) {
    $m = round(max(0.0, $m), 1);
    if (isset($pvSeen[(string)$m])) {
        continue;   // a shop whose two distances are equal would repeat itself
    }
    $pvSeen[(string)$m] = true;
    $pvLadder[] = ['miles' => $m, 'out' => cbstDeliveryOutcome($settings, $m, $pvSpend)];
}

[$pageTitle, $pageSub] = ['Delivery &amp; Offers', 'Delivery charges, offers and the message in the basket'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delivery &amp; Offers – <?= htmlspecialchars(SHOP_NAME) ?> Admin</title>
    <?php require __DIR__ . '/../includes/favicon.php'; ?>
    <link rel="stylesheet" href="<?= cbAsset('../assets/css/style.css') ?>">
    <link rel="stylesheet" href="<?= cbAsset('assets/css/admin.css') ?>">
    <link rel="stylesheet" href="<?= cbAsset('../assets/css/components.css') ?>">
    <link rel="stylesheet" href="<?= cbAsset('../assets/css/modal.css') ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <?php require __DIR__ . '/_csrf_js.php'; ?>
</head>
<body class="cbst-body">

<div class="cbst-wrap">

    <header class="cbst-head">
        <a href="index.php" class="cbst-back"><i class="fa-solid fa-arrow-left"></i> Admin</a>
        <h1 class="cbst-title"><i class="fa-solid fa-truck-fast" aria-hidden="true"></i> <?= $pageTitle ?></h1>
        <p class="cbst-sub"><?= htmlspecialchars($pageSub) ?></p>
    </header>

    <?php if ($offersFail !== ''): ?>
    <div class="cbst-banner is-warn">
        <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
        <div>
            <strong>The offers table is not on this server yet.</strong>
            Delivery settings below still work. To use offers, run
            <a href="migrations/update_db.php">Update DB</a> once, then come back to this page.
        </div>
    </div>
    <?php endif; ?>

    <div class="cbst-flash" id="cbstFlash" data-tone="ok" hidden>
        <i class="fa-solid fa-check" aria-hidden="true"></i>
        <span id="cbstFlashText"></span>
    </div>

    <nav class="cbst-jump">
        <a href="#s-delivery" class="cbst-jump-link"><i class="fa-solid fa-truck-fast"></i> Delivery</a>
        <a href="#s-offers" class="cbst-jump-link"><i class="fa-solid fa-tags"></i> Offers</a>
        <a href="#s-message" class="cbst-jump-link"><i class="fa-solid fa-basket-shopping"></i> Basket message</a>
        <a href="#s-preview" class="cbst-jump-link"><i class="fa-solid fa-eye"></i> What a customer pays</a>
    </nav>


    <!-- ══ 1. Delivery & minimums ═══════════════════════════ -->
    <section class="cbst-panel" id="s-delivery">
        <h2 class="cbst-panel-title"><i class="fa-solid fa-truck-fast" aria-hidden="true"></i> Delivery and minimums</h2>
        <p class="cbst-note">
            These six figures decide what every customer is charged to have an order
            brought to them, and how far you are willing to drive. Change one and it
            takes effect on the shop straight away — there is nothing else to press.
        </p>

        <form class="cbst-form" id="cbstSettingsForm" novalidate>

            <div class="cbst-field">
                <label class="cbst-label" for="f_delivery_charge">What you charge for delivery</label>
                <div class="cbst-input-wrap">
                    <span class="cbst-prefix">£</span>
                    <input type="text" inputmode="decimal" class="cbst-input has-prefix"
                           id="f_delivery_charge" name="delivery_charge"
                           value="<?= htmlspecialchars(cbstBox((float)$settings['delivery_charge'], true)) ?>">
                </div>
                <small class="cbst-hint">
                    Added to the order when someone lives further away than your free
                    delivery distance. Set it to 0 to deliver free to everyone you cover.
                </small>
                <p class="cbst-err" data-err="delivery_charge" hidden></p>
            </div>

            <div class="cbst-field">
                <label class="cbst-label" for="f_free_delivery_miles">Deliver free within this many miles</label>
                <div class="cbst-input-wrap">
                    <input type="text" inputmode="decimal" class="cbst-input has-suffix"
                           id="f_free_delivery_miles" name="free_delivery_miles"
                           value="<?= htmlspecialchars(cbstBox((float)$settings['free_delivery_miles'], false)) ?>">
                    <span class="cbst-suffix">miles</span>
                </div>
                <small class="cbst-hint">
                    Anyone closer than this pays nothing for delivery. Anyone further
                    away pays the charge above.
                </small>
                <p class="cbst-err" data-err="free_delivery_miles" hidden></p>
            </div>

            <div class="cbst-field">
                <label class="cbst-label" for="f_delivery_radius_miles">Furthest you will deliver</label>
                <div class="cbst-input-wrap">
                    <input type="text" inputmode="decimal" class="cbst-input has-suffix"
                           id="f_delivery_radius_miles" name="delivery_radius_miles"
                           value="<?= htmlspecialchars(cbstBox((float)$settings['delivery_radius_miles'], false)) ?>">
                    <span class="cbst-suffix">miles</span>
                </div>
                <small class="cbst-hint">
                    Customers further away than this cannot order delivery at all — the
                    checkout asks them to collect instead. This has to be at least as far
                    as your free delivery distance above.
                </small>
                <p class="cbst-err" data-err="delivery_radius_miles" hidden></p>
            </div>

            <div class="cbst-field">
                <label class="cbst-label" for="f_min_delivery_order">Smallest basket you will deliver</label>
                <div class="cbst-input-wrap">
                    <span class="cbst-prefix">£</span>
                    <input type="text" inputmode="decimal" class="cbst-input has-prefix"
                           id="f_min_delivery_order" name="min_delivery_order"
                           value="<?= htmlspecialchars(cbstBox((float)$settings['min_delivery_order'], true)) ?>">
                </div>
                <small class="cbst-hint">
                    Someone with less than this in their basket is asked to add a little
                    more before they can check out. Collection has no minimum.
                </small>
                <p class="cbst-err" data-err="min_delivery_order" hidden></p>
            </div>

            <div class="cbst-field">
                <label class="cbst-label" for="f_free_delivery_over">Free delivery once they spend</label>
                <div class="cbst-input-wrap">
                    <span class="cbst-prefix">£</span>
                    <input type="text" inputmode="decimal" class="cbst-input has-prefix"
                           id="f_free_delivery_over" name="free_delivery_over"
                           placeholder="leave empty for none"
                           value="<?= htmlspecialchars(cbstBox($settings['free_delivery_over'] !== null ? (float)$settings['free_delivery_over'] : null, true)) ?>">
                </div>
                <small class="cbst-hint">
                    A big basket gets delivery free wherever they are, as long as they are
                    inside the distance above. Leave this box empty if you do not want to
                    offer that.
                </small>
                <p class="cbst-err" data-err="free_delivery_over" hidden></p>
            </div>

            <div class="cbst-field">
                <label class="cbst-label" for="f_delivery_distance_factor">Roads allowance</label>
                <div class="cbst-input-wrap">
                    <span class="cbst-prefix">&times;</span>
                    <input type="text" inputmode="decimal" class="cbst-input has-prefix"
                           id="f_delivery_distance_factor" name="delivery_distance_factor"
                           value="<?= htmlspecialchars(cbstBox((float)$settings['delivery_distance_factor'], false)) ?>">
                </div>
                <small class="cbst-hint">
                    A postcode is measured in a straight line, but nobody drives in a
                    straight line. This stretches that distance to something more like the
                    real drive. 1.3 means "a third further than the map says". Leave it
                    alone unless deliveries are coming out noticeably wrong.
                </small>
                <p class="cbst-err" data-err="delivery_distance_factor" hidden></p>
            </div>

            <?php // The basket message lives in the same database row and the same
                  // save, so it is posted from this form. It is EDITED in section 3
                  // below — these two hidden boxes are what section 3 writes into,
                  // so there is one save button for one row rather than two forms
                  // quietly fighting over it. ?>
            <input type="hidden" name="cart_message" id="f_cart_message"
                   value="<?= htmlspecialchars((string)($settings['cart_message'] ?? '')) ?>">
            <input type="hidden" name="cart_message_active" id="f_cart_message_active"
                   value="<?= (int)$settings['cart_message_active'] === 1 ? '1' : '' ?>">

            <div class="cbst-form-foot">
                <button type="submit" class="cbst-btn is-primary" id="cbstSaveSettings">
                    <i class="fa-solid fa-check" aria-hidden="true"></i> Save delivery settings
                </button>
                <?php if (!empty($settings['updated_at'])): ?>
                <span class="cbst-saved-note">
                    Last saved <?= htmlspecialchars(date('j M Y \a\t g:ia', strtotime((string)$settings['updated_at']))) ?><?php
                        if (trim((string)$settings['updated_by']) !== ''):
                            ?> by <?= htmlspecialchars((string)$settings['updated_by']) ?><?php
                        endif; ?>
                </span>
                <?php endif; ?>
            </div>
        </form>
    </section>


    <!-- ══ 2. Offers ════════════════════════════════════════ -->
    <section class="cbst-panel" id="s-offers">
        <div class="cbst-panel-head">
            <h2 class="cbst-panel-title"><i class="fa-solid fa-tags" aria-hidden="true"></i> Offers</h2>
            <button type="button" class="cbst-btn is-primary" id="cbstNewOffer" <?= $offersFail !== '' ? 'disabled' : '' ?>>
                <i class="fa-solid fa-plus" aria-hidden="true"></i> Add an offer
            </button>
        </div>
        <p class="cbst-note">
            Offers come off the basket automatically — the customer does not type
            anything in. They are applied top to bottom, and together they can never
            take off more than the basket is worth.
        </p>

        <?php if ($offersFail === ''): ?>
        <div class="cbst-offer-list" id="cbstOfferList">
            <?php if ($offers === []): ?>
            <p class="cbst-empty" id="cbstOfferEmpty">
                No offers yet. Press <strong>Add an offer</strong> to set one up — buy one
                get one free, money off, free delivery or a free gift.
            </p>
            <?php else: ?>
                <?php foreach ($offers as $i => $o): ?>
                    <?php [$statusKey, $statusText] = cbstOfferStatus($o, $today); ?>
                    <article class="cbst-offer is-<?= $statusKey ?>" data-offer-id="<?= (int)$o['id'] ?>">
                        <div class="cbst-offer-main">
                            <div class="cbst-offer-top">
                                <h3 class="cbst-offer-name"><?= htmlspecialchars((string)$o['name']) ?></h3>
                                <span class="cbst-pill is-<?= $statusKey ?>"><?= htmlspecialchars($statusText) ?></span>
                                <?php if ((string)$o['applies_to'] !== 'retail'): ?>
                                <span class="cbst-pill is-who">
                                    <?= (string)$o['applies_to'] === 'trade' ? 'Trade only' : 'Shop and trade' ?>
                                </span>
                                <?php endif; ?>
                            </div>
                            <p class="cbst-offer-words"><?= htmlspecialchars(cbstOfferWords($o)) ?></p>
                            <?php if (trim((string)($o['cart_message'] ?? '')) !== ''): ?>
                            <p class="cbst-offer-msg">
                                <i class="fa-solid fa-basket-shopping" aria-hidden="true"></i>
                                &ldquo;<?= htmlspecialchars((string)$o['cart_message']) ?>&rdquo;
                            </p>
                            <?php endif; ?>
                        </div>

                        <div class="cbst-offer-acts">
                            <button type="button" class="cbst-icon-btn" data-act="up" title="Apply this one earlier"
                                    <?= $i === 0 ? 'disabled' : '' ?>>
                                <i class="fa-solid fa-arrow-up" aria-hidden="true"></i>
                                <span class="cbst-sr">Move up</span>
                            </button>
                            <button type="button" class="cbst-icon-btn" data-act="down" title="Apply this one later"
                                    <?= $i === count($offers) - 1 ? 'disabled' : '' ?>>
                                <i class="fa-solid fa-arrow-down" aria-hidden="true"></i>
                                <span class="cbst-sr">Move down</span>
                            </button>
                            <button type="button" class="cbst-icon-btn" data-act="toggle"
                                    title="<?= (int)$o['active'] === 1 ? 'Switch this offer off' : 'Switch this offer on' ?>">
                                <i class="fa-solid <?= (int)$o['active'] === 1 ? 'fa-toggle-on' : 'fa-toggle-off' ?>" aria-hidden="true"></i>
                                <span class="cbst-sr">Switch on or off</span>
                            </button>
                            <button type="button" class="cbst-icon-btn" data-act="edit" title="Edit this offer">
                                <i class="fa-solid fa-pen" aria-hidden="true"></i>
                                <span class="cbst-sr">Edit</span>
                            </button>
                            <button type="button" class="cbst-icon-btn is-danger" data-act="delete" title="Delete this offer">
                                <i class="fa-solid fa-trash" aria-hidden="true"></i>
                                <span class="cbst-sr">Delete</span>
                            </button>
                        </div>

                        <?php // Everything the edit form needs, so opening it does not
                              // cost another trip to the server. ?>
                        <script type="application/json" class="cbst-offer-data"><?= json_encode([
                            'id'           => (int)$o['id'],
                            'name'         => (string)$o['name'],
                            'type'         => (string)$o['type'],
                            'scope'        => (string)$o['scope'],
                            'scope_value'  => (string)($o['scope_value'] ?? ''),
                            'buy_qty'      => $o['buy_qty'] !== null ? (int)$o['buy_qty'] : '',
                            'get_qty'      => $o['get_qty'] !== null ? (int)$o['get_qty'] : '',
                            'amount'       => $o['amount'] !== null ? number_format((float)$o['amount'], 2, '.', '') : '',
                            'min_spend'    => $o['min_spend'] !== null ? number_format((float)$o['min_spend'], 2, '.', '') : '',
                            'cart_message' => (string)($o['cart_message'] ?? ''),
                            'badge_text'   => (string)($o['badge_text'] ?? ''),
                            'applies_to'   => (string)$o['applies_to'],
                            'active'       => (int)$o['active'],
                            'starts_on'    => (string)($o['starts_on'] ?? ''),
                            'ends_on'      => (string)($o['ends_on'] ?? ''),
                        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </section>


    <!-- ══ 3. Basket message ════════════════════════════════ -->
    <section class="cbst-panel" id="s-message">
        <h2 class="cbst-panel-title"><i class="fa-solid fa-basket-shopping" aria-hidden="true"></i> The message in the basket</h2>
        <p class="cbst-note">
            One short line shown to everybody at the top of their basket, whatever they
            have put in it. Good for something that is always true — where the ice cream
            is made, or that today's orders go out tomorrow. Offers add their own
            messages underneath this one, so there is no need to mention them here.
        </p>

        <div class="cbst-field">
            <label class="cbst-switch">
                <input type="checkbox" id="cbstMsgOn" <?= (int)$settings['cart_message_active'] === 1 ? 'checked' : '' ?>>
                <span>
                    <strong>Show this message in every basket</strong>
                    <small>Untick to hide it without losing what you have written.</small>
                </span>
            </label>
        </div>

        <div class="cbst-field">
            <label class="cbst-label" for="cbstMsgText">What it says</label>
            <textarea class="cbst-input cbst-textarea" id="cbstMsgText" rows="2" maxlength="255"
                      placeholder="Every tub is churned in small batches in Harrow, the morning it is packed."><?= htmlspecialchars((string)($settings['cart_message'] ?? '')) ?></textarea>
            <small class="cbst-hint">
                Keep it to one sentence. <span id="cbstMsgCount">0</span> of 255 characters used.
            </small>
            <p class="cbst-err" data-err="cart_message" hidden></p>
        </div>

        <div class="cbst-field">
            <span class="cbst-label">What the customer sees</span>
            <div class="cbst-cart-preview" id="cbstMsgPreview">
                <div class="cbst-cart-strip" id="cbstMsgStrip">
                    <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
                    <span id="cbstMsgStripText"></span>
                </div>
                <p class="cbst-cart-off" id="cbstMsgOff">
                    Nothing is shown at the top of the basket at the moment.
                </p>
            </div>
        </div>

        <div class="cbst-form-foot">
            <button type="button" class="cbst-btn is-primary" id="cbstSaveMessage">
                <i class="fa-solid fa-check" aria-hidden="true"></i> Save the message
            </button>
            <span class="cbst-saved-note">Saved together with the delivery settings above.</span>
        </div>
    </section>


    <!-- ══ 4. What a customer pays ══════════════════════════ -->
    <section class="cbst-panel" id="s-preview">
        <h2 class="cbst-panel-title"><i class="fa-solid fa-eye" aria-hidden="true"></i> What a customer pays</h2>
        <p class="cbst-note">
            Worked out from the settings <strong>as they are saved right now</strong>, using
            the same rules as the real checkout. Change the two boxes to try a
            different customer. If you have just changed something above, save it
            first — this panel does not read the boxes, it reads the shop.
        </p>

        <form class="cbst-whatif" method="get" action="store.php">
            <div class="cbst-field cbst-whatif-field">
                <label class="cbst-label" for="pv_miles">A customer this far away</label>
                <div class="cbst-input-wrap">
                    <input type="text" inputmode="decimal" class="cbst-input has-suffix" id="pv_miles"
                           name="pv_miles" value="<?= htmlspecialchars(cbstFmtMiles($pvMiles)) ?>">
                    <span class="cbst-suffix">miles</span>
                </div>
                <small class="cbst-hint">Straight line on the map, as a postcode measures it.</small>
            </div>

            <div class="cbst-field cbst-whatif-field">
                <label class="cbst-label" for="pv_spend">Spending this much</label>
                <div class="cbst-input-wrap">
                    <span class="cbst-prefix">£</span>
                    <input type="text" inputmode="decimal" class="cbst-input has-prefix" id="pv_spend"
                           name="pv_spend" value="<?= htmlspecialchars(number_format($pvSpend, 2, '.', '')) ?>">
                </div>
                <small class="cbst-hint">The basket total, before any offer comes off.</small>
            </div>

            <div class="cbst-field cbst-whatif-field is-action">
                <button type="submit" class="cbst-btn is-ghost">
                    <i class="fa-solid fa-eye" aria-hidden="true"></i> Work it out
                </button>
            </div>
        </form>

        <div class="cbst-verdict is-<?= htmlspecialchars($pvResult['status']) ?>">
            <p class="cbst-verdict-line">
                A customer <strong><?= htmlspecialchars(cbstFmtMiles($pvMiles)) ?> miles away</strong>
                spending <strong><?= htmlspecialchars(cbstFmtMoney($pvSpend)) ?></strong>:
                <strong class="cbst-verdict-answer"><?= htmlspecialchars($pvResult['headline']) ?></strong>
            </p>
            <p class="cbst-verdict-why"><?= htmlspecialchars($pvResult['detail']) ?></p>
        </div>

        <h3 class="cbst-sub-title">The same basket at other distances</h3>
        <div class="cbst-ladder">
            <?php foreach ($pvLadder as $row): ?>
            <div class="cbst-ladder-row is-<?= htmlspecialchars($row['out']['status']) ?>">
                <span class="cbst-ladder-miles"><?= htmlspecialchars(cbstFmtMiles($row['miles'])) ?> miles</span>
                <span class="cbst-ladder-answer"><?= htmlspecialchars($row['out']['headline']) ?></span>
                <span class="cbst-ladder-why"><?= htmlspecialchars($row['out']['detail']) ?></span>
            </div>
            <?php endforeach; ?>
        </div>

        <h3 class="cbst-sub-title">What that basket would say</h3>
        <?php if ($pvMessages === []): ?>
        <p class="cbst-empty">
            Nothing at all — this basket has earned no offer and there is no standing
            message switched on.
        </p>
        <?php else: ?>
        <div class="cbst-cart-preview">
            <?php foreach ($pvMessages as $m): ?>
            <div class="cbst-cart-strip">
                <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
                <span><?= htmlspecialchars($m) ?></span>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        <p class="cbst-foot-note">
            Worked out for a basket holding one item at
            <?= htmlspecialchars(cbstFmtMoney($pvSpend)) ?>. An offer that needs two of
            something will not appear here, because this basket genuinely would not earn it.
        </p>
    </section>

</div>


<!-- ══ The offer form ═══════════════════════════════════════ -->
<div class="cbst-sheet" id="cbstOfferSheet" hidden>
    <div class="cbst-sheet-box" role="dialog" aria-modal="true" aria-labelledby="cbstOfferHeading">
        <div class="cbst-sheet-head">
            <h2 class="cbst-sheet-title" id="cbstOfferHeading">Add an offer</h2>
            <button type="button" class="cbst-icon-btn" id="cbstOfferClose" title="Close without saving">
                <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                <span class="cbst-sr">Close</span>
            </button>
        </div>

        <form class="cbst-sheet-body" id="cbstOfferForm" novalidate>
            <input type="hidden" name="id" id="o_id" value="">

            <div class="cbst-field">
                <label class="cbst-label" for="o_name">Call this offer</label>
                <input type="text" class="cbst-input" id="o_name" name="name" maxlength="120"
                       placeholder="Summer buy one get one free">
                <small class="cbst-hint">Only you see this. It is how you will find the offer in the list.</small>
                <p class="cbst-err" data-err="name" hidden></p>
            </div>

            <div class="cbst-field">
                <label class="cbst-label" for="o_type">What kind of offer is it?</label>
                <select class="cbst-input" id="o_type" name="type">
                    <option value="bogo">Buy one, get one free</option>
                    <option value="buy_x_get_y">Buy a few, get some free</option>
                    <option value="percent_off">A percentage off</option>
                    <option value="fixed_off">A set amount off</option>
                    <option value="free_delivery">Free delivery</option>
                    <option value="free_item_over">A free gift when they spend enough</option>
                </select>
                <p class="cbst-err" data-err="type" hidden></p>
            </div>

            <?php // Only the boxes that mean something for the chosen kind are shown. ?>
            <div class="cbst-field cbst-row" data-when="buy_x_get_y">
                <div class="cbst-row-item">
                    <label class="cbst-label" for="o_buy_qty">They buy</label>
                    <input type="text" inputmode="numeric" class="cbst-input" id="o_buy_qty" name="buy_qty" value="2">
                    <p class="cbst-err" data-err="buy_qty" hidden></p>
                </div>
                <div class="cbst-row-item">
                    <label class="cbst-label" for="o_get_qty">They get free</label>
                    <input type="text" inputmode="numeric" class="cbst-input" id="o_get_qty" name="get_qty" value="1">
                    <p class="cbst-err" data-err="get_qty" hidden></p>
                </div>
            </div>

            <div class="cbst-field" data-when="percent_off">
                <label class="cbst-label" for="o_amount_pc">How much comes off</label>
                <div class="cbst-input-wrap">
                    <input type="text" inputmode="decimal" class="cbst-input has-suffix" id="o_amount_pc" name="amount_pc" value="10">
                    <span class="cbst-suffix">% off</span>
                </div>
                <small class="cbst-hint">10 means a tenth off the price of everything the offer covers.</small>
                <?php // Both money boxes carry the handler's own field name, so a
                      // refusal lands under whichever one is on screen. ?>
                <p class="cbst-err" data-err="amount" hidden></p>
            </div>

            <div class="cbst-field" data-when="fixed_off">
                <label class="cbst-label" for="o_amount_fx">How much comes off</label>
                <div class="cbst-input-wrap">
                    <span class="cbst-prefix">£</span>
                    <input type="text" inputmode="decimal" class="cbst-input has-prefix" id="o_amount_fx" name="amount_fx" value="5.00">
                </div>
                <p class="cbst-err" data-err="amount" hidden></p>
            </div>

            <div class="cbst-field" data-when="free_item_over">
                <label class="cbst-label" for="o_badge_gift">What the free gift is</label>
                <input type="text" class="cbst-input" id="o_badge_gift" name="badge_gift" maxlength="40"
                       placeholder="tub of pistachio kulfi">
                <small class="cbst-hint">
                    Written as it should read after the word &ldquo;free&rdquo; — so
                    &ldquo;tub of pistachio kulfi&rdquo; becomes &ldquo;a free tub of pistachio kulfi&rdquo;.
                </small>
                <p class="cbst-err" data-err="badge_text" hidden></p>
            </div>

            <div class="cbst-field">
                <label class="cbst-label" for="o_scope">What is it on?</label>
                <select class="cbst-input" id="o_scope" name="scope">
                    <option value="all">Everything in the shop</option>
                    <option value="category">One category</option>
                    <option value="product">One product</option>
                </select>
                <p class="cbst-err" data-err="scope" hidden></p>
            </div>

            <div class="cbst-field" data-when-scope="category">
                <label class="cbst-label" for="o_category">Which category</label>
                <select class="cbst-input" id="o_category">
                    <option value="">Choose a category…</option>
                    <?php foreach ($categories as $c): ?>
                    <option value="<?= htmlspecialchars((string)$c) ?>"><?= htmlspecialchars((string)$c) ?></option>
                    <?php endforeach; ?>
                </select>
                <small class="cbst-hint cbst-warn-note">
                    <i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
                    Category offers are not live yet — a basket does not record which
                    category an item came from, so an offer set this way will safely do
                    nothing. Use &ldquo;Everything&rdquo; or &ldquo;One product&rdquo; for now.
                </small>
                <p class="cbst-err" data-err="scope_value" hidden></p>
            </div>

            <div class="cbst-field" data-when-scope="product">
                <label class="cbst-label" for="o_product">Which product</label>
                <select class="cbst-input" id="o_product">
                    <option value="">Choose a product…</option>
                    <?php foreach ($products as $p): ?>
                    <option value="<?= (int)$p['id'] ?>"><?= htmlspecialchars((string)$p['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="cbst-err" data-err="scope_value" hidden></p>
            </div>

            <div class="cbst-field">
                <label class="cbst-label" for="o_min_spend">Only once they have spent</label>
                <div class="cbst-input-wrap">
                    <span class="cbst-prefix">£</span>
                    <input type="text" inputmode="decimal" class="cbst-input has-prefix" id="o_min_spend"
                           name="min_spend" placeholder="leave empty for no minimum">
                </div>
                <small class="cbst-hint" id="o_min_hint">
                    Leave empty and the offer applies to any basket.
                </small>
                <p class="cbst-err" data-err="min_spend" hidden></p>
            </div>

            <div class="cbst-field">
                <label class="cbst-label" for="o_applies_to">Who gets it?</label>
                <select class="cbst-input" id="o_applies_to" name="applies_to">
                    <option value="retail">Shop customers only</option>
                    <option value="trade">Trade customers only</option>
                    <option value="both">Everybody</option>
                </select>
                <small class="cbst-hint">
                    Trade partners already buy at wholesale prices, so offers usually stay
                    with shop customers.
                </small>
                <p class="cbst-err" data-err="applies_to" hidden></p>
            </div>

            <div class="cbst-field">
                <label class="cbst-label" for="o_cart_message">Message in the basket</label>
                <input type="text" class="cbst-input" id="o_cart_message" name="cart_message" maxlength="255"
                       placeholder="Your second tub is on us.">
                <small class="cbst-hint">
                    Shown when this offer is earned. Leave empty and the basket writes its
                    own line about it.
                </small>
                <p class="cbst-err" data-err="cart_message" hidden></p>
            </div>

            <div class="cbst-field" data-when-not="free_item_over">
                <label class="cbst-label" for="o_badge_text">Short label on the product</label>
                <input type="text" class="cbst-input" id="o_badge_text" name="badge_text" maxlength="40"
                       placeholder="2 for 1">
                <small class="cbst-hint">A few words for the little flash on the product card. Optional.</small>
                <p class="cbst-err" data-err="badge_text" hidden></p>
            </div>

            <div class="cbst-field cbst-row">
                <div class="cbst-row-item">
                    <label class="cbst-label" for="o_starts_on">Starts on</label>
                    <input type="date" class="cbst-input" id="o_starts_on" name="starts_on">
                    <small class="cbst-hint">Empty means it is on as soon as you switch it on.</small>
                    <p class="cbst-err" data-err="starts_on" hidden></p>
                </div>
                <div class="cbst-row-item">
                    <label class="cbst-label" for="o_ends_on">Finishes on</label>
                    <input type="date" class="cbst-input" id="o_ends_on" name="ends_on">
                    <small class="cbst-hint">Empty means it runs until you switch it off.</small>
                    <p class="cbst-err" data-err="ends_on" hidden></p>
                </div>
            </div>

            <div class="cbst-field">
                <label class="cbst-switch">
                    <input type="checkbox" id="o_active" name="active" checked>
                    <span>
                        <strong>Switch this offer on</strong>
                        <small>Untick to set it up now and start it later.</small>
                    </span>
                </label>
            </div>

            <?php // The restatement. Rebuilt on every change so the owner reads back
                  // what they have actually set, not what they meant to set. ?>
            <div class="cbst-restate" id="cbstRestate">
                <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
                <span id="cbstRestateText"></span>
            </div>

            <p class="cbst-err is-top" data-err="_form" hidden></p>

            <div class="cbst-sheet-foot">
                <button type="button" class="cbst-btn is-ghost" id="cbstOfferCancel">Cancel</button>
                <button type="submit" class="cbst-btn is-primary" id="cbstOfferSave">
                    <i class="fa-solid fa-check" aria-hidden="true"></i> Save offer
                </button>
            </div>
        </form>
    </div>
</div>

<script src="<?= cbAsset('../assets/js/modal.js') ?>"></script>
<script src="<?= cbAsset('assets/js/store.js') ?>"></script>
</body>
</html>
