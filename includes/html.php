<?php
// ============================================================
//  Creamy Bite – view helpers
// ============================================================

/**
 * A PHP value, safe to drop straight into an inline handler attribute.
 *
 *     <button onclick="openStockEdit(<?= (int)$id ?>, <?= cbJsAttr($name) ?>)">
 *
 * Note there are NO hand-written quotes around it — this produces its own.
 *
 * Why it exists: passing a name into an onclick with
 * htmlspecialchars($name, ENT_QUOTES) inside hand-written single quotes looks
 * safe and is not. The browser HTML-decodes the attribute BEFORE handing it to
 * the JavaScript parser, so &#039; turns back into an apostrophe and closes
 * the string early. A product called "Grandma's Kulfi" rendered as
 *
 *     onclick="openStockEdit(20, 39, 'Grandma&#039;s Kulfi — 500ml')"
 *
 * which the browser parsed as openStockEdit(20, 39, 'Grandma's Kulfi…') — a
 * syntax error, so the button did nothing at all. Silently: no dialog, no
 * console message a shopkeeper would ever see, just a dead button. The same
 * flaw killed the lightbox on the public gallery page for any photo whose
 * caption contained an apostrophe.
 *
 * addslashes() is not the answer either. It escapes the apostrophe for
 * JavaScript but leaves double quotes and angle brackets alone, so a name
 * containing " ends the HTML attribute instead, and a name containing a tag is
 * an injection.
 *
 * json_encode produces the quotes and the escaping together, and the three HEX
 * flags push ' < > & out of the HTML layer entirely, so nothing survives
 * HTML-decoding that could break out of the JavaScript string. The
 * htmlspecialchars() around it escapes json's own " delimiters, which is what
 * makes the result safe inside a double-quoted attribute — the form nearly
 * every handler in this project is written in.
 */
function cbJsAttr($value): string
{
    return htmlspecialchars(
        json_encode($value, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE),
        ENT_QUOTES,
        'UTF-8'
    );
}
