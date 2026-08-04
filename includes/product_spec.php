<?php
// ============================================================
//  Creamy Bite – Product specification helpers
//
//  One definition of the allergen list, the nutrition rows and the storage
//  wording, shared by the product editor, the catalogue, the allergen sheet
//  and the nutrition sheet.
//
//  The list below is the 14 allergens that assimilated Regulation (EU)
//  1169/2011 requires a UK food business to declare. It is not a list anyone
//  should be shortening to whatever seems relevant to ice cream — a customer
//  reading an allergen sheet needs to see that soya was considered and ruled
//  out, not that it was never on the form.
//
//  The single most important rule in this file is cbAllergenStatus(): a
//  product whose allergens have never been reviewed is NOT the same as a
//  product confirmed to contain none, and nothing generated from this data
//  is allowed to blur the two.
// ============================================================

if (defined('CB_PRODUCT_SPEC_LOADED')) {
    return;
}
define('CB_PRODUCT_SPEC_LOADED', true);

/**
 * The 14 declarable allergens: slug => label shown to customers.
 *
 * Slugs are stored in products.allergens as a comma-separated list. They are
 * short and stable so that renaming a label never orphans stored data.
 */
function cbAllergens(): array
{
    return [
        'cereals_gluten' => 'Cereals containing gluten',
        'crustaceans'    => 'Crustaceans',
        'eggs'           => 'Eggs',
        'fish'           => 'Fish',
        'peanuts'        => 'Peanuts',
        'soybeans'       => 'Soybeans',
        'milk'           => 'Milk',
        'nuts'           => 'Tree nuts',
        'celery'         => 'Celery',
        'mustard'        => 'Mustard',
        'sesame'         => 'Sesame',
        'sulphites'      => 'Sulphur dioxide & sulphites',
        'lupin'          => 'Lupin',
        'molluscs'       => 'Molluscs',
    ];
}

/** Turn the stored comma list into an array of valid slugs. */
function cbAllergenList(?string $stored): array
{
    if ($stored === null || trim($stored) === '') {
        return [];
    }
    $valid = cbAllergens();
    $out   = [];
    foreach (explode(',', $stored) as $slug) {
        $slug = trim($slug);
        // Unknown slugs are dropped rather than shown. A typo in the database
        // should print nothing, never an empty bullet a reader takes for an
        // allergen they cannot identify.
        if ($slug !== '' && isset($valid[$slug]) && !in_array($slug, $out, true)) {
            $out[] = $slug;
        }
    }
    return $out;
}

/** Customer-facing labels for a stored allergen list. */
function cbAllergenLabels(?string $stored): array
{
    $all = cbAllergens();
    return array_map(static fn($s) => $all[$s], cbAllergenList($stored));
}

/**
 * The three genuinely different states a product's allergen data can be in.
 *
 * This exists because 'unreviewed' and 'none' are the same empty string in the
 * database, and printing them the same way is how an allergen sheet becomes
 * dangerous. Callers must branch on this, not on empty($product['allergens']).
 *
 *   'unreviewed' – nobody has checked. Make NO claim either way.
 *   'none'       – checked, and genuinely contains none of the 14.
 *   'listed'     – checked, and contains the allergens listed.
 */
function cbAllergenStatus(array $product): string
{
    $reviewed = !empty($product['allergen_reviewed_at']);
    $has      = cbAllergenList($product['allergens'] ?? '') !== [];

    if ($has) {
        // Data present but never signed off still beats printing nothing, so
        // it is reported as listed. The sheet notes it is unconfirmed.
        return 'listed';
    }
    return $reviewed ? 'none' : 'unreviewed';
}

/** Has this product had its allergens formally signed off? */
function cbAllergenReviewed(array $product): bool
{
    return !empty($product['allergen_reviewed_at']);
}

/**
 * The nutrition rows, in the order the regulations require them printed.
 *
 * key => [label, unit, indent]. Saturates and sugars are indented because
 * they are declared as "of which" components of the row above.
 */
function cbNutritionRows(): array
{
    return [
        'energy_kj'   => ['Energy',            'kJ',   false],
        'energy_kcal' => ['Energy',            'kcal', false],
        'fat_g'       => ['Fat',               'g',    false],
        'saturates_g' => ['of which saturates', 'g',   true],
        'carbs_g'     => ['Carbohydrate',      'g',    false],
        'sugars_g'    => ['of which sugars',   'g',    true],
        'fibre_g'     => ['Fibre',             'g',    false],
        'protein_g'   => ['Protein',           'g',    false],
        'salt_g'      => ['Salt',              'g',    false],
    ];
}

/** Does this product have any nutrition figures at all? */
function cbHasNutrition(array $product): bool
{
    foreach (array_keys(cbNutritionRows()) as $key) {
        if (isset($product[$key]) && $product[$key] !== null && $product[$key] !== '') {
            return true;
        }
    }
    return false;
}

/**
 * Format one nutrition figure.
 *
 * Returns null — not '0' and not '—' — when the value was never entered, so
 * the caller has to decide how to present a missing figure rather than
 * accidentally printing a blank that reads as zero.
 */
function cbNutritionValue(array $product, string $key): ?string
{
    $v = $product[$key] ?? null;
    if ($v === null || $v === '') {
        return null;
    }
    $f = (float)$v;
    // Energy is whole numbers; the rest carry the decimal a label would show.
    if ($key === 'energy_kj' || $key === 'energy_kcal') {
        return number_format($f, 0);
    }
    if ($key === 'salt_g') {
        return number_format($f, 2);
    }
    return rtrim(rtrim(number_format($f, 1), '0'), '.');
}

/** Storage wording for a product, falling back to the shop-wide default. */
function cbStorageInstructions(array $product): string
{
    $own = trim((string)($product['storage_instructions'] ?? ''));
    return $own !== '' ? $own : CB_DEFAULT_STORAGE;
}

// The default every frozen product gets unless it says otherwise. Defined
// once so the FAQ, the catalogue and the storage sheet cannot disagree about
// the temperature — the one number on the page a customer might act on.
define('CB_DEFAULT_STORAGE', 'Keep frozen at −18°C or colder. Do not refreeze once thawed.');

// Printed on every allergen and nutrition document. A shared kitchen cannot
// promise absence of cross-contact, and saying so once, in one place, is more
// reliable than remembering to say it on each sheet.
define('CB_CROSS_CONTACT_NOTICE',
    'All of our ice cream is produced in a single kitchen that handles milk, tree nuts, '
  . 'peanuts and cereals containing gluten. We cannot guarantee any product is free from '
  . 'cross-contact. If you have a serious allergy, please speak to us before ordering.');

/** Case size for a variant, falling back to the product's own. */
function cbCaseSize(array $product, ?array $variant = null): string
{
    if ($variant !== null && trim((string)($variant['case_size'] ?? '')) !== '') {
        return trim($variant['case_size']);
    }
    return trim((string)($product['case_size'] ?? ''));
}
