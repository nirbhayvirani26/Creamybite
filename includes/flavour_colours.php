<?php
// ============================================================
//  Creamy Bite – Per-flavour brand colours
//
//  Creamy Bite Brand Guidelines 2026, Secondary Colours. One shared
//  source so the home page teaser and the full menu can never drift
//  into different colours for the same flavour.
//
//  Matched by substring against the full product name ("Kesar Pista Ice
//  Cream Tub"), not an exact match, since the guideline only names the
//  bare flavour. A product that matches nothing gets no class and the
//  card stays plain — never guessed at.
// ============================================================

const CB_FLAVOUR_BRAND_COLOURS = [
    'Afghan Meva'         => 'afghan-meva',
    'Rajbhog'              => 'rajbhog',
    'Mava Malai'           => 'mava-malai',
    'American Dry Fruits'  => 'american-dry-fruits',
    'Kesar Pista'          => 'kesar-pista',
    'Cookies & Cream'      => 'cookies-cream',
    'Roasted Almond'       => 'roasted-almond',
    'Pan Masala'           => 'pan-masala',
    'Kaju Gulkand'         => 'kaju-gulkand',
    'Naked Coconut'        => 'naked-coconut',
    'Fresh Sitafal'        => 'fresh-sitafal',
    'Kaju Draksh'          => 'kaju-draksh',
];

function cbFlavourCardClass(string $productName): string
{
    foreach (CB_FLAVOUR_BRAND_COLOURS as $flavour => $slug) {
        if (stripos($productName, $flavour) !== false) {
            return 'cbhm-flavour-' . $slug;
        }
    }
    return '';
}
