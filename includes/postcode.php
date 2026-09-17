<?php
// ============================================================
//  Creamy Bite – UK postcode validation
//
//  One place that knows what a UK postcode looks like, so the answer cannot
//  drift between the forms that ask for one.
//
//  This matters more here than it looks. A trade application is typed once and
//  then acted on by a person: the postcode ends up on a delivery run, on an
//  invoice, and in the distance check that decides what delivery costs. A
//  typo — "HA1 2S", "HAI 2SP" with a letter I for a one — is not caught by
//  anything downstream. postcodes.io simply answers "not found", the distance
//  comes back null, and the application sits in the admin panel looking
//  perfectly normal until somebody tries to deliver to it.
//
//  WHAT THE PATTERN EXCLUDES, and why it is not just [A-Z0-9]:
//
//    * The first letter is never Q, V or X.
//    * The second letter is never I, J or Z.
//    * The final two letters never include C, I, K, M, O or V — they were
//      left out precisely because they are misread as one another and as
//      digits, which is the exact mistake this is here to catch.
//    * GIR 0AA is a real postcode and matches nothing else, so it is named.
//
//  THE TWO TRAILING-LETTER SETS ARE NOT THE SAME SET, and assuming they were
//  is how this rejected the whole of the City of London on its first outing.
//  An outward code ending in a letter comes in two shapes, and each allows a
//  different set in that last position:
//
//      A9A   (W1A, N1C, E1W)    ->  A B C D E F G H J K P S T U W
//      AA9A  (EC1V, SW1X, EC2Y) ->  A B E H M N P R V W X Y
//
//  Using the first set for both threw out EC1V, EC1M, EC1N, EC1R, EC1Y, the
//  EC2/EC3/EC4 ranges and SW1V/SW1X/SW1Y — real addresses, all of them, and
//  exactly the districts a London wholesale customer is likely to be at. A
//  rejected postcode on a signup form is a trade account that never opens,
//  and nobody finds out why.
//
//  A looser check exists in includes/pricing.php. It is deliberately left
//  alone: it decides only whether a postcode is worth spending an API call
//  on, and tightening it would change what customers are charged for
//  delivery — a different question from whether a form should be accepted.
// ============================================================

/**
 * The canonical form of a UK postcode, or null if it is not one.
 *
 * Accepts whatever spacing and case the customer typed and hands back the
 * printed form — "ha12sp", "HA1  2SP" and "Ha1 2sP" all give "HA1 2SP" — so
 * what is stored is consistent regardless of how it arrived. Storing the raw
 * input instead is how one business ends up in the database twice.
 */
function cbUkPostcodeNormalise(string $input): ?string
{
    // Non-breaking spaces get in when a postcode is pasted from a website or
    // a PDF, and they are invisible in the box that rejects them.
    $clean = str_replace(["\xC2\xA0", ' ', "\t", "\r", "\n", '-'], '', $input);
    $clean = strtoupper(trim($clean));

    if ($clean === '') {
        return null;
    }
    if ($clean === 'GIR0AA') {
        return 'GIR 0AA';
    }

    // Outward code is whatever precedes the final three characters; the
    // inward code is always exactly three. Splitting on that rule rather than
    // on the space the customer may or may not have typed is what lets this
    // accept "ha12sp".
    if (strlen($clean) < 5 || strlen($clean) > 7) {
        return null;
    }
    $outward = substr($clean, 0, -3);
    $inward  = substr($clean, -3);

    // One alternative per outward shape, rather than one pattern with optional
    // parts: the last position's allowed letters depend on whether there is a
    // second letter at the front, and a single pattern cannot say that.
    //
    //   A9 / A99 / A9A     a letter, a digit, then optionally a digit or a
    //                      letter from the A9A set
    //   AA9 / AA99 / AA9A  two letters, a digit, then optionally a digit or a
    //                      letter from the AA9A set
    $outwardOk = preg_match('/^[A-PR-UWYZ][0-9][0-9A-HJKPSTUW]?$/', $outward)
              || preg_match('/^[A-PR-UWYZ][A-HK-Y][0-9][0-9ABEHMNPRVWXY]?$/', $outward);
    if (!$outwardOk) {
        return null;
    }
    if (!preg_match('/^[0-9][ABD-HJLNP-UW-Z]{2}$/', $inward)) {
        return null;
    }

    return $outward . ' ' . $inward;
}

/** True when $input is a valid UK postcode in any spacing or case. */
function cbIsUkPostcode(string $input): bool
{
    return cbUkPostcodeNormalise($input) !== null;
}

/**
 * The same rule as a regex for the browser's `pattern` attribute.
 *
 * Kept beside the PHP so the two cannot drift into disagreeing — a form that
 * refuses in the browser what the server would have accepted, or the other
 * way round, is worse than having no client-side check at all. The server
 * still decides; this only saves a round trip.
 *
 * BOTH CASES ARE SPELLED OUT rather than using an inline (?i) flag. `pattern`
 * is compiled as a JavaScript regular expression, and JavaScript has no
 * inline flag syntax — "(?i)" makes the whole pattern fail to compile. A
 * pattern that does not compile is not an error the customer ever sees: the
 * browser drops the constraint and the box silently accepts anything, which
 * looks exactly like a working check right up until a bad postcode is saved.
 * The field is displayed uppercase by CSS, but text-transform changes only
 * what is drawn — the value posted is whatever was typed, so the lower-case
 * halves below are doing real work.
 */
function cbUkPostcodeHtmlPattern(): string
{
    $inward = ' ?[0-9][ABD-HJLNP-UW-Zabd-hjlnp-uw-z]{2}';

    return '([Gg][Ii][Rr] ?0[Aa]{2}'
         . '|[A-PR-UWYZa-pr-uwyz][0-9][0-9A-HJKPSTUWa-hjkpstuw]?' . $inward
         . '|[A-PR-UWYZa-pr-uwyz][A-HK-Ya-hk-y][0-9][0-9ABEHMNPRVWXYabehmnprvwxy]?' . $inward
         . ')';
}
