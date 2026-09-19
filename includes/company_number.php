<?php
// ============================================================
//  Creamy Bite – UK company number
//
//  The registration number Companies House gives a limited company or an LLP.
//  Eight characters, and it comes in two shapes:
//
//      12345678    England & Wales limited company — eight digits
//      SC123456    a two-character prefix and six digits (SC Scotland,
//                  NI Northern Ireland, OC an LLP, FC an overseas company,
//                  and a long tail of others)
//
//  VALIDATED BY SHAPE, NOT BY A LIST OF PREFIXES
//
//  The obvious approach is to enumerate the prefixes — SC, NI, OC, FC, LP,
//  SO, NC, R0 and so on — and refuse anything else. This deliberately does
//  not, because that is the exact mistake the postcode check made: a
//  hand-written list of allowed letters looked authoritative, was missing
//  entries, and silently refused real customers in the City of London. The
//  registrar adds prefixes (R0 for royal charter bodies is not even two
//  letters — it is a letter and a zero), and a list here would be wrong the
//  day one is added, with no way to find out except a customer giving up.
//
//  So the rule is the part that does not drift: eight characters, the last
//  six of them digits. That catches every mistake anyone actually makes — a
//  digit short, a VAT number typed in by accident, a company name — without
//  pretending to know the registrar's full prefix list.
//
//  NOT CHECKED AGAINST COMPANIES HOUSE. Their API would confirm the company
//  exists, but it needs an account and a key, and it would put a signup form
//  at the mercy of someone else's uptime. The shop owner approves every trade
//  account by hand and can look the number up; this is here to catch typing,
//  not to verify incorporation.
// ============================================================

/**
 * The canonical form of a UK company number, or null if it is not one.
 *
 * Accepts the spacing and case people actually type — "sc 123456",
 * "12345678", "OC301010" — and returns the printed form: uppercase, no
 * spaces. Companies House prints them unspaced, so that is what is stored.
 *
 * An eight-digit number is returned as-is INCLUDING ANY LEADING ZEROS, which
 * is why this works in strings throughout and never casts to int. "00445790"
 * is Marks & Spencer; as an integer it becomes 445790, which is a different
 * company's number and would be wrong on any paperwork it reached.
 */
function cbCompanyNumberNormalise(string $input): ?string
{
    // Non-breaking spaces and hyphens get in when a number is pasted from a
    // letterhead or a PDF, and they are invisible in the box that rejects them.
    $clean = str_replace(["\xC2\xA0", ' ', "\t", "\r", "\n", '-', '.', '/'], '', $input);
    $clean = strtoupper(trim($clean));

    if ($clean === '') {
        return null;
    }
    if (strlen($clean) !== 8) {
        return null;
    }

    // The last six are always digits. The first two are either digits (a plain
    // English or Welsh number) or a prefix — which may be two letters, or a
    // letter and a digit, so they are checked as "alphanumeric, not both
    // digits unless the whole thing is digits".
    if (!preg_match('/^[0-9]{6}$/', substr($clean, 2))) {
        return null;
    }
    if (!preg_match('/^[A-Z0-9]{2}$/', substr($clean, 0, 2))) {
        return null;
    }

    return $clean;
}

/** True when $input is a plausible UK company number in any spacing or case. */
function cbIsCompanyNumber(string $input): bool
{
    return cbCompanyNumberNormalise($input) !== null;
}

/**
 * The same rule as a regex for the browser's `pattern` attribute.
 *
 * Both cases are spelled out rather than using an inline (?i) flag, because
 * `pattern` is compiled as JavaScript and JavaScript has no inline flags — a
 * pattern that fails to compile is dropped by the browser without a word,
 * which looks exactly like a working check until something bad is accepted.
 * Spaces are allowed here because the server strips them before validating,
 * so the browser must not refuse what the server would happily take.
 */
function cbCompanyNumberHtmlPattern(): string
{
    return '[A-Za-z0-9]{2} ?[0-9]{3} ?[0-9]{3}';
}
