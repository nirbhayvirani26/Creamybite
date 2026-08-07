<?php
/**
 * Product & category icon resolution — the single source of truth.
 *
 * products.emoji and categories.icon still hold emoji characters, and the shop
 * owner types emoji into the product form, so the column cannot simply be
 * reinterpreted as a Font Awesome class name. Two earlier helpers disagreed
 * about which the column held: one treated the value as an icon name and
 * printed anything else literally, the other mapped emoji to icons. With emoji
 * in the database that meant the admin form showed icons while the public shop
 * still showed emoji.
 *
 * This resolver accepts BOTH forms, so no migration is required and a future
 * one would not break anything:
 *   - a known emoji            -> its mapped icon
 *   - a Font Awesome name      -> that icon ("crown", "fa-crown", "fa-solid fa-crown")
 *   - anything else            -> printed as escaped text, so a brand-new
 *                                 flavour keeps working the day it is added
 *
 * Every render site must call cbProductIcon(). cbProductIconMapJson() feeds the
 * identical rules to the client-side copies the cart and variant picker build.
 */

if (!function_exists('cbProductIconMap')) {
    /** @return array<string,string> emoji => Font Awesome class */
    function cbProductIconMap(): array
    {
        return [
            '🍦' => 'fa-solid fa-ice-cream',    // house fallback / Roasted Almond
            '🍨' => 'fa-solid fa-ice-cream',
            '🌾' => 'fa-solid fa-wheat-awn',    // Kesar Pista
            '🇦🇫' => 'fa-solid fa-mountain-sun',  // Afghan Meva
            '🥜' => 'fa-solid fa-bowl-food',    // American Dry Fruits
            '🍇' => 'fa-solid fa-holly-berry',  // Kaju Draksh
            '🍈' => 'fa-solid fa-apple-whole',  // Fresh Sitafal / Fruit Delights
            '🥥' => 'fa-solid fa-droplet',      // Naked Coconut
            '🌹' => 'fa-solid fa-spa',          // Kaju Gulkand
            '🥛' => 'fa-solid fa-glass-water',  // Mava Malai
            '🍪' => 'fa-solid fa-cookie-bite',  // Cookies & Cream
            '🍫' => 'fa-solid fa-cubes',        // Choco Chips
            '🍃' => 'fa-solid fa-leaf',         // Pan Masala / Exotic Speciality
            '🌰' => 'fa-solid fa-jar-wheat',    // Nutty Delights
            '👑' => 'fa-solid fa-crown',        // Rajbhog / Royal Flavours
        ];
    }
}

if (!function_exists('cbProductIconClass')) {
    /**
     * Resolve a stored value to a Font Awesome class, or null if it is neither
     * a known emoji nor a plausible icon name.
     */
    function cbProductIconClass(?string $value): ?string
    {
        // U+FE0F (emoji presentation selector) is invisible but would miss the key.
        $raw = trim(str_replace("\u{FE0F}", '', (string)$value));
        if ($raw === '') {
            return null;
        }

        $map = cbProductIconMap();
        if (isset($map[$raw])) {
            return $map[$raw];
        }

        // Accept an icon name in any of the forms someone might store:
        // "crown", "fa-crown", "fa-solid fa-crown".
        if (preg_match('/^(?:fa-(?:solid|regular|brands)\s+)?(?:fa-)?([a-z][a-z0-9-]*)$/', $raw, $m)) {
            return 'fa-solid fa-' . $m[1];
        }

        return null;
    }
}

if (!function_exists('cbProductIcon')) {
    /**
     * Icon markup for a stored product/category value.
     *
     * Unrecognised values are escaped and printed as-is, so an un-migrated or
     * hand-typed catalogue looks exactly as it did before rather than showing
     * an empty box or leaking markup.
     */
    function cbProductIcon(?string $value, string $fallback = 'ice-cream'): string
    {
        $raw = trim((string)$value);
        if ($raw === '') {
            return '<i class="fa-solid fa-' . $fallback . '" aria-hidden="true"></i>';
        }

        $cls = cbProductIconClass($raw);
        return $cls !== null
            ? '<i class="' . $cls . '" aria-hidden="true"></i>'
            : htmlspecialchars($raw, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('cbProductIconMapJson')) {
    /** The same map, for the client-side twin. Safe to embed in a <script>. */
    function cbProductIconMapJson(): string
    {
        return json_encode(
            cbProductIconMap(),
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );
    }
}
