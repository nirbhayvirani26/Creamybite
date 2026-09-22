<?php
// ============================================================
//  Creamy Bite – The full story of one product
//
//  The order page showed a name, one line of description, a price and a
//  "Contains Nuts" tag. Everything else the shop knows about a product —
//  the ingredients, the full allergen list, the nutrition panel, the size,
//  how to store it, how long it keeps — was already in the database and on
//  the allergen sheet, and a customer deciding what to buy never saw any of
//  it without leaving the menu.
//
//  This renders that. It is deliberately a plain function returning markup
//  rather than a page: the menu, and later the trade catalogue, should show
//  the same panel rather than growing two versions that drift apart.
//
//  NOTHING IS INVENTED. Every section disappears when its data is absent —
//  an empty nutrition table would read as "no calories", and a blank
//  ingredients heading reads as "no ingredients", which for a food business
//  is worse than saying nothing. What is missing is named as missing, with a
//  pointer to the one place that can answer it.
// ============================================================

require_once __DIR__ . '/product_spec.php';

if (!function_exists('cbProductDetailHtml')) {

    /**
     * The detail panel for one product.
     *
     * @param array  $product  A full products row.
     * @param array  $related  Other products to offer as related flavours.
     * @param string $shopEmail Where to send someone whose question is not answered here.
     */
    function cbProductDetailHtml(array $product, array $related = [], string $shopEmail = ''): string
    {
        $h  = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $id = (int)($product['id'] ?? 0);
        $out = '';

        // ── Description ─────────────────────────────────────
        $desc = trim((string)($product['description'] ?? ''));
        if ($desc !== '') {
            $out .= '<p class="cbpd-desc">' . $h($desc) . '</p>';
        }

        // ── The facts that fit on one line each ─────────────
        $facts = [];
        $size = trim((string)($product['case_size'] ?? ''));
        if ($size !== '') {
            $facts[] = ['box', 'Size', $size];
        }
        $shelf = trim((string)($product['shelf_life'] ?? ''));
        if ($shelf !== '') {
            $facts[] = ['calendar-day', 'Best before', $shelf];
        }
        $cat = trim((string)($product['category'] ?? ''));
        if ($cat !== '') {
            $facts[] = ['tag', 'Range', $cat];
        }
        if ($facts !== []) {
            $out .= '<ul class="cbpd-facts">';
            foreach ($facts as [$icon, $label, $value]) {
                $out .= '<li><i class="fa-solid fa-' . $h($icon) . '" aria-hidden="true"></i>'
                      . '<span class="cbpd-fact-label">' . $h($label) . '</span>'
                      . '<span class="cbpd-fact-value">' . $h($value) . '</span></li>';
            }
            $out .= '</ul>';
        }

        // ── Allergens ───────────────────────────────────────
        //
        // Three states, and they are not the same thing. "Signed off and
        // contains these" is information. "Signed off and contains none of
        // the fourteen" is also information, and worth printing. "Nobody has
        // checked yet" is neither, and must never be shown as the second —
        // an empty allergen list that means "not reviewed" is how a customer
        // with an allergy gets hurt.
        $status = cbAllergenStatus($product);
        $labels = cbAllergenLabels($product['allergens'] ?? null);

        $out .= '<div class="cbpd-block">';
        $out .= '<h4 class="cbpd-h">Allergens</h4>';
        if ($status === 'unreviewed') {
            // The text is wrapped in its own element on purpose: .cbpd-warn is a
            // flex row, and a bare <a> among bare text nodes becomes a second
            // flex ITEM — the address then sits beside the paragraph instead of
            // finishing the sentence it belongs to.
            $out .= '<p class="cbpd-warn"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>'
                  . '<span>We have not finished checking the allergens for this one, so we are not going to guess. '
                  . 'Please ask before ordering'
                  . ($shopEmail !== '' ? ' — <a href="mailto:' . $h($shopEmail) . '">' . $h($shopEmail) . '</a>' : '')
                  . '.</span></p>';
        } elseif ($labels === []) {
            $out .= '<p class="cbpd-ok"><i class="fa-solid fa-circle-check" aria-hidden="true"></i> '
                  . 'None of the 14 declarable allergens.</p>';
        } else {
            $out .= '<ul class="cbpd-allergens">';
            foreach ($labels as $label) {
                $out .= '<li>' . $h($label) . '</li>';
            }
            $out .= '</ul>';
        }
        $note = trim((string)($product['allergen_notes'] ?? ''));
        if ($note !== '') {
            $out .= '<p class="cbpd-note">' . $h($note) . '</p>';
        }
        $out .= '<p class="cbpd-note cbpd-crosscontact">' . $h(CB_CROSS_CONTACT_NOTICE) . '</p>';
        $out .= '</div>';

        // ── Ingredients ─────────────────────────────────────
        $ingredients = trim((string)($product['ingredients'] ?? ''));
        $out .= '<div class="cbpd-block">';
        $out .= '<h4 class="cbpd-h">Ingredients</h4>';
        if ($ingredients !== '') {
            $out .= '<p class="cbpd-ingredients">' . nl2br($h($ingredients)) . '</p>';
        } else {
            // Named as absent rather than left blank, and pointed somewhere.
            $out .= '<p class="cbpd-missing">Not published yet for this flavour. '
                  . ($shopEmail !== ''
                        ? 'Email <a href="mailto:' . $h($shopEmail) . '">' . $h($shopEmail) . '</a> and we will send it over.'
                        : 'Please ask and we will send it over.')
                  . '</p>';
        }
        $out .= '</div>';

        // ── Nutrition ───────────────────────────────────────
        if (cbHasNutrition($product)) {
            $basis = trim((string)($product['nutrition_basis'] ?? ''));
            $out .= '<div class="cbpd-block">';
            $out .= '<h4 class="cbpd-h">Nutrition' . ($basis !== '' ? ' <span class="cbpd-basis">per ' . $h($basis) . '</span>' : '') . '</h4>';
            $out .= '<table class="cbpd-nutri"><tbody>';
            foreach (cbNutritionRows() as $key => [$label, $unit, $indent]) {
                $value = cbNutritionValue($product, $key);
                if ($value === null) {
                    continue;   // a figure nobody entered is not a zero
                }
                $out .= '<tr' . ($indent ? ' class="is-sub"' : '') . '>'
                      . '<td>' . $h($label) . '</td>'
                      . '<td class="cbpd-nutri-v">' . $h($value) . ' ' . $h($unit) . '</td></tr>';
            }
            $out .= '</tbody></table></div>';
        }

        // ── Storage ─────────────────────────────────────────
        $out .= '<div class="cbpd-block">';
        $out .= '<h4 class="cbpd-h">Storage</h4>';
        $out .= '<p class="cbpd-storage"><i class="fa-solid fa-snowflake" aria-hidden="true"></i> '
              . $h(cbStorageInstructions($product)) . '</p>';
        $out .= '</div>';

        // ── Related flavours ────────────────────────────────
        if ($related !== []) {
            $out .= '<div class="cbpd-block cbpd-related">';
            $out .= '<h4 class="cbpd-h">You might also like</h4><div class="cbpd-related-row">';
            foreach ($related as $r) {
                $rid = (int)($r['id'] ?? 0);
                if ($rid === 0 || $rid === $id) {
                    continue;
                }
                $out .= '<a class="cbpd-related-item" href="#pcard-' . $rid . '" data-cbpd-jump="' . $rid . '">'
                      . $h((string)($r['name'] ?? '')) . '</a>';
            }
            $out .= '</div></div>';
        }

        return $out;
    }
}

if (!function_exists('cbRelatedProducts')) {
    /**
     * A few other flavours worth offering alongside this one.
     *
     * Same category first, because "you might also like" should mean
     * something. Falls back to anything else rather than showing an empty
     * row — a heading with nothing under it looks broken.
     */
    function cbRelatedProducts(array $product, array $all, int $limit = 4): array
    {
        $id  = (int)($product['id'] ?? 0);
        $cat = (string)($product['category'] ?? '');
        $same = [];
        $rest = [];
        foreach ($all as $p) {
            if ((int)($p['id'] ?? 0) === $id) {
                continue;
            }
            if ($cat !== '' && (string)($p['category'] ?? '') === $cat) {
                $same[] = $p;
            } else {
                $rest[] = $p;
            }
        }
        return array_slice(array_merge($same, $rest), 0, $limit);
    }
}
