<?php
// ============================================================
//  Creamy Bite – "Do you deliver to me?"
//  URL: /postcode_check.php?postcode=HA1+2SP   →  JSON
//
//  A customer could only find out whether their address was in range by
//  filling in the whole checkout and reaching the payment step. Anyone
//  outside the radius did that work for nothing, and anyone unsure whether
//  they were inside it mostly did not start.
//
//  This answers it from the shipping page in one field.
//
//  It uses the SAME cbPostcodeLookup() the charge is calculated from, so the
//  answer here and the answer at checkout cannot disagree. That mattered:
//  the order page used to quote a delivery radius typed into its own markup.
//
//  Reads only. No order is created, nothing is stored, and the postcode is
//  not written anywhere — it is looked up and the answer returned.
// ============================================================
require_once __DIR__ . '/includes/session.php';
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/pricing.php';
require_once __DIR__ . '/includes/postcode.php';
require_once __DIR__ . '/includes/store_settings.php';

header('Content-Type: application/json; charset=utf-8');
// An answer about the shop's own delivery area, for this visitor's postcode.
// Nothing to cache: the radius is a setting the owner can change.
header('Cache-Control: no-store');

$raw = trim((string)($_GET['postcode'] ?? $_POST['postcode'] ?? ''));

/** One shape of reply, so the page never has to guess which keys are present. */
function cbPcReply(string $status, string $message, array $extra = []): void
{
    echo json_encode(array_merge([
        'status'  => $status,     // ok | outside | invalid | unavailable | closed
        'message' => $message,
    ], $extra));
    exit;
}

if ($raw === '') {
    cbPcReply('invalid', 'Enter your postcode and we will check.');
}

// Checked for shape BEFORE the network call. postcodes.io answers a typo with
// a 404, which arrives here as a failed request and would otherwise be
// reported as "our checker is down" — blaming the shop for the customer's
// slip and hiding a real outage among the typos.
$norm = cbUkPostcodeNormalise($raw);
if ($norm === null) {
    cbPcReply('invalid', 'That does not look like a UK postcode. Try again — for example, HA1 2SP.');
}

// Nothing to promise about a service that is switched off. Said before the
// lookup, because "yes we deliver to you" is exactly the wrong answer on a
// day when the shop is not delivering to anybody.
if (!cbOrderingOpen('delivery')) {
    cbPcReply('closed',
        cbOrderingOpen('collection')
            ? 'We have paused deliveries for the moment — collection is still running, with no minimum order.'
            : 'We have paused orders for the moment. Please do check back a little later.',
        ['postcode' => $norm, 'collection_open' => cbOrderingOpen('collection')]
    );
}

$look = cbPostcodeLookup($norm);

if ($look['status'] === 'unavailable') {
    cbPcReply('unavailable',
        'We could not check just now. Please try again in a moment, or call us and we will tell you.',
        ['postcode' => $norm]
    );
}
if ($look['status'] === 'unknown' || $look['miles'] === null) {
    cbPcReply('invalid', 'We could not find that postcode. Please check it and try again.',
        ['postcode' => $norm]);
}

$miles  = (float)$look['miles'];
$radius = (float)DELIVERY_RADIUS_MILES;
$free   = (float)FREE_DELIVERY_MILES;
$num    = fn(float $v) => rtrim(rtrim(number_format($v, 1), '0'), '.');

if ($miles > $radius) {
    cbPcReply('outside',
        'You are about ' . $num($miles) . ' miles away, and we deliver up to '
        . $num($radius) . '. Collection from our Harrow unit is free and has no minimum order.',
        ['postcode' => $norm, 'miles' => round($miles, 1)]
    );
}

$charge = $miles <= $free ? 0.0 : (float)DELIVERY_CHARGE;
cbPcReply('ok',
    'Yes — you are about ' . $num($miles) . ' miles away. '
    . ($charge <= 0
        ? 'Delivery is free to you.'
        : 'Delivery is £' . number_format($charge, 2) . '.')
    . ' Minimum order £' . number_format((float)MIN_DELIVERY_ORDER, 2) . '.',
    [
        'postcode' => $norm,
        'miles'    => round($miles, 1),
        'charge'   => round($charge, 2),
        'minimum'  => round((float)MIN_DELIVERY_ORDER, 2),
    ]
);
