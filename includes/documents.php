<?php
/**
 * Documents & SOPs — the domain layer.
 *
 * The shop's own operating paperwork: HACCP records, cleaning schedules,
 * allergen procedures, supplier approvals, training logs. Staff need to find
 * and read these quickly — an EHO asking for the allergen procedure does not
 * want to wait while somebody searches a laptop.
 *
 * STORAGE
 *   Files live in assets/documents/, which carries a deny-all .htaccess. A
 *   document is only ever served through admin/document_download.php, which
 *   checks the admin session first. A SOP is not secret exactly, but supplier
 *   agreements and staff records are nobody's business but the shop's, and a
 *   filename is easy to guess.
 *
 *   The stored name is generated, never the uploaded one, for the same reason
 *   product images are: a file called "policy.php" must not land in a folder
 *   and execute.
 */

if (!function_exists('cbDocCategories')) {
    /**
     * The headings a UK ice cream maker's paperwork actually falls under.
     * Ordered the way an inspection tends to go, not alphabetically.
     */
    function cbDocCategories(): array
    {
        return [
            'Food Safety'   => 'HACCP, temperature control, cleaning, pest control',
            'Allergens'     => 'The 14 declarable allergens, labelling, cross-contact',
            'Production'    => 'Recipes, batch records, mix and freeze procedures',
            'Warehouse'     => 'Cold store, stock rotation, goods in and out',
            'Delivery'      => 'Vehicle temperatures, driver checks, cold chain',
            'Suppliers'     => 'Approved suppliers, specifications, certificates',
            'Staff'         => 'Training, fitness to work, hygiene rules',
            'Compliance'    => 'Registrations, insurance, audits, inspection reports',
            'General'       => 'Anything else',
        ];
    }
}

if (!function_exists('cbDocAllowedTypes')) {
    /**
     * What may be uploaded, by verified mime type -> the extension it is saved
     * with. The extension comes from the CONTENT, never from the filename the
     * browser sent.
     */
    function cbDocAllowedTypes(): array
    {
        return [
            'application/pdf'  => 'pdf',
            'image/jpeg'       => 'jpg',
            'image/png'        => 'png',
            'image/webp'       => 'webp',
            'text/plain'       => 'txt',
            'text/csv'         => 'csv',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'       => 'xlsx',
        ];
    }
}

if (!function_exists('cbDocDir')) {
    function cbDocDir(): string
    {
        return dirname(__DIR__) . '/assets/documents/';
    }
}

if (!function_exists('cbDocReady')) {
    /** Has the migration been run? Asks; never alters. */
    function cbDocReady(PDO $pdo): bool
    {
        static $ready = null;
        if ($ready !== null) { return $ready; }
        try {
            $st = $pdo->query(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'documents'"
            );
            $ready = (int)$st->fetchColumn() > 0;
        } catch (Throwable $e) {
            error_log('documents table check failed: ' . $e->getMessage());
            $ready = false;
        }
        return $ready;
    }
}

if (!function_exists('cbDocList')) {
    /** @return array<int,array> every document, grouped-friendly order */
    function cbDocList(PDO $pdo, string $category = ''): array
    {
        if (!cbDocReady($pdo)) { return []; }
        try {
            if ($category !== '') {
                $st = $pdo->prepare(
                    "SELECT * FROM documents WHERE category = :c ORDER BY sort_order, title"
                );
                $st->execute(['c' => $category]);
                return $st->fetchAll(PDO::FETCH_ASSOC);
            }
            return $pdo->query(
                "SELECT * FROM documents ORDER BY category, sort_order, title"
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('document list failed: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('cbDocSizeLabel')) {
    /** "2.4 MB" rather than 2517948, which tells a shop owner nothing. */
    function cbDocSizeLabel(int $bytes): string
    {
        if ($bytes < 1024)              { return $bytes . ' B'; }
        if ($bytes < 1024 * 1024)       { return round($bytes / 1024) . ' KB'; }
        return round($bytes / 1048576, 1) . ' MB';
    }
}

if (!function_exists('cbDocIcon')) {
    /** A Font Awesome FREE name for the file type. Verified against the set. */
    function cbDocIcon(string $mime): string
    {
        return match (true) {
            $mime === 'application/pdf'          => 'fa-file-pdf',
            str_starts_with($mime, 'image/')     => 'fa-file-image',
            str_contains($mime, 'word')          => 'fa-file-word',
            str_contains($mime, 'sheet'),
            str_contains($mime, 'excel'),
            $mime === 'text/csv'                 => 'fa-file-excel',
            $mime === 'text/plain'               => 'fa-file-lines',
            default                              => 'fa-file',
        };
    }
}

if (!function_exists('cbDocReviewState')) {
    /**
     * Food safety paperwork goes stale. A HACCP plan or an allergen matrix
     * that has not been looked at since the menu changed is worse than none,
     * because it is believed. Returns 'none' | 'ok' | 'soon' | 'overdue'.
     */
    function cbDocReviewState(?string $reviewDue): string
    {
        $d = trim((string)$reviewDue);
        if ($d === '' || $d === '0000-00-00') { return 'none'; }
        $due = strtotime($d);
        if ($due === false) { return 'none'; }
        $days = (int)floor(($due - strtotime('today')) / 86400);
        if ($days < 0)  { return 'overdue'; }
        if ($days <= 30) { return 'soon'; }
        return 'ok';
    }
}

if (!function_exists('cbDocStarterSet')) {
    /**
     * The paperwork a small UK ice cream maker is generally expected to hold.
     *
     * These are STARTING POINTS, not a compliance pack — the wording has to be
     * made true of this shop before it is worth anything, and an EHO will ask
     * about what actually happens rather than what a document says. Sources
     * worth reading alongside them: the FSA's "Safer Food, Better Business for
     * caterers" and its manufacturing equivalent, and SALSA or BRCGS if a
     * wholesale customer asks for certification.
     *
     * Each entry becomes a placeholder in the list with a short description of
     * what belongs in it, so the owner can see the shape of a complete set and
     * fill the gaps rather than starting from an empty page.
     */
    function cbDocStarterSet(): array
    {
        return [
            ['Food Safety', 'HACCP plan',
             'Your hazard analysis: each step from goods-in to dispatch, what could go wrong, the critical limits, who checks and what happens when a limit is missed. The document an EHO asks for first.'],
            ['Food Safety', 'Temperature monitoring record',
             'Daily readings for every freezer, cold store and display cabinet. Ice cream is normally held at -18°C or colder. Keep the signed sheets — the record is the evidence, not the thermometer.'],
            ['Food Safety', 'Cleaning schedule and chemical list',
             'What is cleaned, how often, with which product, at what dilution, and who signed it off. Include the safety data sheets for every chemical kept on site.'],
            ['Food Safety', 'Pest control policy and visit reports',
             'Your contractor, visit frequency, bait station map, and every report they leave. Gaps in this file are noticed.'],
            ['Food Safety', 'Glass, hard plastic and sharps policy',
             'What happens when something breaks near open product. Written before it happens, not after.'],

            ['Allergens', 'Allergen matrix',
             'Every product against the 14 allergens that must be declared under assimilated Regulation (EU) 1169/2011. Nuts, milk and soya matter most here. Update it the day a recipe changes, not at the next review.'],
            ['Allergens', 'Cross-contact controls',
             'Separate scoops and utensils, production order, cleaning between batches, and how a nut-free line stays nut-free. Say what is actually done.'],
            ['Allergens', 'PPDS labelling procedure',
             'Natasha\'s Law: food packed on the premises for direct sale needs the full ingredient list with allergens emphasised. Covers who writes labels and who checks them.'],

            ['Production', 'Batch production record',
             'Batch code, date, recipe version, quantities, mix and freezing times, operator, and where the batch went. This is what makes a recall possible.'],
            ['Production', 'Recipe and specification sheets',
             'The controlled version of each recipe, with declared weights and the ingredient list the label is built from.'],
            ['Production', 'Pasteurisation and mix records',
             'Time and temperature for each mix, and what happens to a batch that misses the target.'],

            ['Warehouse', 'Cold store procedure',
             'Target temperatures, alarm settings, who responds out of hours, stock layout, and what to do if the store fails overnight.'],
            ['Warehouse', 'Stock rotation and date coding',
             'First in, first out. How a date code is read, where it is written, and who checks for short-dated stock.'],
            ['Warehouse', 'Goods-in inspection',
             'What is checked on delivery — vehicle temperature, packaging, dates, damage — and the grounds for rejecting a load.'],

            ['Delivery', 'Cold chain procedure',
             'How product stays frozen from the freezer to the customer\'s door: pre-cooling, packing, insulated boxes, maximum time out, and what to do if a drop runs long.'],
            ['Delivery', 'Vehicle temperature check sheet',
             'A reading at load, and at each drop for a multi-drop round. Signed by the driver.'],

            ['Suppliers', 'Approved supplier list',
             'Who you buy from, what for, and the evidence you hold on them — certification, audit dates, specifications.'],
            ['Suppliers', 'Ingredient specifications',
             'The agreed spec for each ingredient, including its allergen declaration. What you rely on when writing your own labels.'],

            ['Staff', 'Fitness to work policy',
             'The 48-hour exclusion after sickness, return-to-work checks, and the declaration a new starter signs.'],
            ['Staff', 'Personal hygiene rules',
             'Handwashing, hair covering, jewellery, protective clothing. Displayed as well as filed.'],
            ['Staff', 'Training record',
             'Who has been trained in what, when, and when it is due again. Level 2 Food Safety is the usual baseline for anyone handling open product.'],

            ['Compliance', 'Food business registration',
             'Your registration with the local authority. Required before trading, and free.'],
            ['Compliance', 'Public and product liability insurance',
             'The current certificate. Wholesale customers usually ask for it before their first order.'],
            ['Compliance', 'Traceability and recall procedure',
             'How you would identify every affected batch and reach every customer within a few hours. Worth testing on paper once a year.'],
            ['Compliance', 'EHO inspection reports',
             'Every visit report and your food hygiene rating, with what you did about any actions raised.'],
        ];
    }
}
