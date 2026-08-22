<?php
// ============================================================
// Business types and setup templates
// ------------------------------------------------------------
// WHAT THIS IS
// This system is a general retail management and POS application.
// It is not a supermarket system, and it is not a pharmacy system.
// A business type is a CLASSIFICATION plus an optional set of
// SUGGESTED departments, categories and units - nothing else.
//
// WHAT A BUSINESS TYPE DOES NOT DO
// It does not change the database, the till, the ledger, the
// permissions or any workflow. Choosing "Pharmacy" does not add
// prescriptions, patient records, dispensing or controlled-drug
// tracking, and does not make this a pharmacy management system.
// Industry-specific features would be separate modules, and none
// exist today. See docs/TECHNICAL_DOCUMENTATION.md.
//
// Everything a template suggests is editable before, during and
// after setup: the administrator can rename, add, remove, disable
// and reorder every department and category it creates. A template
// is a starting point, never a structure the shop is locked into.
// ============================================================

require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/shop_settings.php';

/**
 * The business types offered during setup.
 *
 * Adding one here is the whole job - no core code branches on these
 * keys, so a new type never means a new version of the application.
 * `departments` is a suggestion; `categories` is keyed by department
 * name; `units` are units of measure that suit the trade.
 */
function businessTypeCatalogue(): array {
    return [
        'general_retail' => [
            'label'       => 'General Retail',
            'icon'        => 'fa-shop',
            'description' => 'A shop selling a broad mix of everyday goods.',
            'departments' => [
                ['Groceries',     'fa-basket-shopping', '#42c3cf'],
                ['Household',     'fa-house',           '#8b5cf6'],
                ['Personal Care', 'fa-pump-soap',       '#ec4899'],
                ['General',       'fa-boxes-stacked',   '#8b9aa2'],
            ],
            'categories'  => [
                'Groceries'     => ['Staples & Cereals', 'Cooking Oil & Spices', 'Snacks', 'Beverages'],
                'Household'     => ['Cleaning', 'Kitchenware', 'Plastics'],
                'Personal Care' => ['Soap & Bathing', 'Hair Care', 'Oral Care'],
                'General'       => ['Airtime & Vouchers', 'Miscellaneous'],
            ],
            'units'       => [['Pieces','pcs'], ['Packs','pack'], ['Boxes','box'], ['Cartons','ctn'],
                              ['Kilograms','kg'], ['Liters','L'], ['Bottles','btl'], ['Dozens','dz']],
        ],

        'supermarket' => [
            'label'       => 'Supermarket',
            'icon'        => 'fa-cart-shopping',
            'description' => 'Food, drink and household goods at scale, usually with several counters.',
            'departments' => [
                ['Food',          'fa-bowl-food',       '#42c3cf'],
                ['Beverages',     'fa-bottle-water',    '#38bdf8'],
                ['Household',     'fa-house',           '#8b5cf6'],
                ['Personal Care', 'fa-pump-soap',       '#ec4899'],
                ['General',       'fa-boxes-stacked',   '#8b9aa2'],
            ],
            'categories'  => [
                'Food'          => ['Bakery', 'Dairy & Eggs', 'Dry Goods & Cereals', 'Cooking Oil & Spices',
                                    'Canned & Packaged Food', 'Snacks & Confectionery'],
                'Beverages'     => ['Soft Drinks', 'Water', 'Juices', 'Tea & Coffee'],
                'Household'     => ['Household Cleaning', 'Kitchenware', 'Paper Products'],
                'Personal Care' => ['Toiletries', 'Baby Products', 'Hair Care'],
                'General'       => ['Airtime & Vouchers', 'Miscellaneous'],
            ],
            'units'       => [['Pieces','pcs'], ['Packs','pack'], ['Cartons','ctn'], ['Crates','crate'],
                              ['Kilograms','kg'], ['Grams','g'], ['Liters','L'], ['Milliliters','ml'],
                              ['Bottles','btl'], ['Sachets','sct'], ['Dozens','dz']],
        ],

        'pharmacy' => [
            'label'       => 'Pharmacy / Duka la Dawa',
            'icon'        => 'fa-mortar-pestle',
            'description' => 'Medicines and health goods sold over the counter.',
            'note'        => 'This configures the shop structure only. The system remains a retail POS - '
                           . 'it does not provide prescriptions, patient records, dispensing or '
                           . 'controlled-drug tracking.',
            'departments' => [
                ['Medicines',        'fa-pills',        '#22c55e'],
                ['Personal Care',    'fa-pump-soap',    '#ec4899'],
                ['Medical Supplies', 'fa-briefcase-medical', '#ef4444'],
                ['Baby & Mother',    'fa-baby',         '#f59e0b'],
            ],
            'categories'  => [
                'Medicines'        => ['Pain Relief', 'Antibiotics', 'Antimalarials', 'Cough & Cold', 'Vitamins & Supplements'],
                'Personal Care'    => ['Soap & Antiseptics', 'Skin Care', 'Oral Care'],
                'Medical Supplies' => ['Dressings & Bandages', 'Gloves & Masks', 'Testing & Devices'],
                'Baby & Mother'    => ['Baby Food', 'Nappies', 'Maternal Care'],
            ],
            'units'       => [['Pieces','pcs'], ['Tablets','tab'], ['Strips','strip'], ['Bottles','btl'],
                              ['Tubes','tube'], ['Sachets','sct'], ['Packs','pack'], ['Boxes','box'],
                              ['Milliliters','ml']],
        ],

        'stationery' => [
            'label'       => 'Stationery',
            'icon'        => 'fa-pen-ruler',
            'description' => 'Writing materials, books and office supplies.',
            'departments' => [
                ['Writing Materials', 'fa-pen',          '#f6c23e'],
                ['Books',             'fa-book',         '#8b5cf6'],
                ['Office Supplies',   'fa-paperclip',    '#42c3cf'],
                ['School Supplies',   'fa-graduation-cap','#22c55e'],
            ],
            'categories'  => [
                'Writing Materials' => ['Pens', 'Pencils', 'Markers', 'Art & Drawing'],
                'Books'             => ['Exercise Books', 'Notebooks', 'Textbooks'],
                'Office Supplies'   => ['Paper & Reams', 'Files & Folders', 'Printing & Toner', 'Desk Accessories'],
                'School Supplies'   => ['School Bags & Cases', 'Mathematical Sets', 'Uniform Accessories'],
            ],
            'units'       => [['Pieces','pcs'], ['Packs','pack'], ['Reams','ream'], ['Boxes','box'],
                              ['Cartons','ctn'], ['Dozens','dz'], ['Sets','set'], ['Bundles','bdl']],
        ],

        'cosmetics' => [
            'label'       => 'Cosmetics',
            'icon'        => 'fa-spray-can-sparkles',
            'description' => 'Beauty and personal grooming products.',
            'departments' => [
                ['Skin Care',   'fa-hand-sparkles',       '#ec4899'],
                ['Hair Care',   'fa-scissors',            '#8b5cf6'],
                ['Make-up',     'fa-wand-magic-sparkles', '#f43f5e'],
                ['Fragrances',  'fa-spray-can',           '#f59e0b'],
            ],
            'categories'  => [
                'Skin Care'  => ['Lotions & Creams', 'Cleansers', 'Sun Care'],
                'Hair Care'  => ['Shampoo & Conditioner', 'Relaxers & Dyes', 'Braids & Extensions'],
                'Make-up'    => ['Face', 'Eyes', 'Lips', 'Nails'],
                'Fragrances' => ['Perfumes', 'Body Sprays', 'Deodorants'],
            ],
            'units'       => [['Pieces','pcs'], ['Bottles','btl'], ['Tubes','tube'], ['Jars','jar'],
                              ['Packs','pack'], ['Sets','set'], ['Milliliters','ml']],
        ],

        'electronics' => [
            'label'       => 'Electronics',
            'icon'        => 'fa-plug',
            'description' => 'Phones, computing and electrical accessories.',
            'departments' => [
                ['Phones & Accessories', 'fa-mobile-screen', '#42c3cf'],
                ['Computing',            'fa-laptop',        '#8b5cf6'],
                ['Audio & Video',        'fa-headphones',    '#f59e0b'],
                ['Electrical',           'fa-bolt',          '#ef4444'],
            ],
            'categories'  => [
                'Phones & Accessories' => ['Phones', 'Chargers & Cables', 'Cases & Covers', 'Power Banks'],
                'Computing'            => ['Laptops', 'Printers', 'Storage', 'Peripherals'],
                'Audio & Video'        => ['Speakers', 'Headphones', 'Televisions'],
                'Electrical'           => ['Bulbs & Lighting', 'Extension Cables', 'Batteries'],
            ],
            'units'       => [['Pieces','pcs'], ['Sets','set'], ['Boxes','box'], ['Meters','m'], ['Pairs','pr']],
        ],

        'hardware' => [
            'label'       => 'Hardware',
            'icon'        => 'fa-screwdriver-wrench',
            'description' => 'Building materials, tools and fittings.',
            'departments' => [
                ['Plumbing',           'fa-faucet',              '#38bdf8'],
                ['Electrical',         'fa-bolt',                '#f59e0b'],
                ['Tools',              'fa-screwdriver-wrench',  '#8b9aa2'],
                ['Building Materials', 'fa-trowel-bricks',       '#b45309'],
            ],
            'categories'  => [
                'Plumbing'           => ['Pipes & Fittings', 'Taps & Valves', 'Tanks'],
                'Electrical'         => ['Cables', 'Switches & Sockets', 'Lighting', 'Conduits'],
                'Tools'              => ['Hand Tools', 'Power Tools', 'Measuring'],
                'Building Materials' => ['Cement & Aggregates', 'Paints', 'Nails & Fasteners', 'Roofing'],
            ],
            'units'       => [['Pieces','pcs'], ['Meters','m'], ['Rolls','roll'], ['Bags','bag'],
                              ['Kilograms','kg'], ['Liters','L'], ['Bundles','bdl'], ['Lengths','len']],
        ],

        'general_merchandise' => [
            'label'       => 'General Merchandise',
            'icon'        => 'fa-boxes-stacked',
            'description' => 'A mixed range of goods that does not fit one trade.',
            'departments' => [
                ['General',   'fa-boxes-stacked', '#8b9aa2'],
                ['Household', 'fa-house',         '#8b5cf6'],
                ['Clothing',  'fa-shirt',         '#ec4899'],
            ],
            'categories'  => [
                'General'   => ['Miscellaneous', 'Seasonal'],
                'Household' => ['Kitchenware', 'Plastics', 'Cleaning'],
                'Clothing'  => ['Adults', 'Children', 'Footwear'],
            ],
            'units'       => [['Pieces','pcs'], ['Packs','pack'], ['Dozens','dz'], ['Sets','set'], ['Pairs','pr']],
        ],

        'other' => [
            'label'       => 'Other',
            'icon'        => 'fa-pen-to-square',
            'description' => 'Any other retail business. You name the type and build the structure yourself.',
            'departments' => [],
            'categories'  => [],
            'units'       => [['Pieces','pcs'], ['Packs','pack'], ['Boxes','box'], ['Kilograms','kg'], ['Liters','L']],
        ],
    ];
}

/** One business type, or null. */
function businessType(string $key): ?array {
    return businessTypeCatalogue()[$key] ?? null;
}

/**
 * What this shop calls itself.
 * "Other" carries a free-text name the administrator typed.
 */
function businessTypeLabel(mysqli $conn): string {
    $key = shopSetting($conn, 'shop_business_type');
    if ($key === 'other' || $key === '') {
        $custom = trim(shopSetting($conn, 'shop_business_type_other'));
        if ($custom !== '') { return $custom; }
    }
    $type = businessType($key);
    return $type ? $type['label'] : 'Retail';
}

// ---------- Setup state -------------------------------------------------

/** Has the administrator finished (or skipped past) initial setup? */
function businessSetupComplete(mysqli $conn): bool {
    return (string)getInvSetting($conn, 'setup_completed', '0') === '1';
}

/** Record that setup is finished. Configuration stays editable afterwards. */
function businessMarkSetupComplete(mysqli $conn): void {
    setInvSetting($conn, 'setup_completed', '1');
    setInvSetting($conn, 'setup_completed_at', date('Y-m-d H:i:s'));
}

/**
 * Is this database already running a real shop?
 *
 * An installation with products, sales or purchase history must never
 * be pushed into a setup wizard - it is already configured, whatever
 * the flag says. This is what stops an upgrade from looking like a
 * factory reset.
 */
function businessHasOperationalData(mysqli $conn): bool {
    $probes = [
        "SELECT COUNT(*) c FROM inv_items WHERE deleted_at IS NULL",
        "SELECT COUNT(*) c FROM sales_transactions",
        "SELECT COUNT(*) c FROM inv_stock_movements",
        "SELECT COUNT(*) c FROM inv_purchase_orders",
    ];
    foreach ($probes as $sql) {
        $res = @$conn->query($sql);
        if ($res instanceof mysqli_result) {
            $n = (int)($res->fetch_assoc()['c'] ?? 0);
            $res->free();
            if ($n > 0) { return true; }
        }
    }
    return false;
}

/**
 * Decide, once, whether this installation needs the wizard.
 *
 * Called during boot. An existing shop is marked complete silently so
 * nothing about its day changes; only a genuinely empty database is
 * ever offered the wizard.
 */
function businessSyncSetupState(mysqli $conn): void {
    if (businessSetupComplete($conn)) { return; }
    if (businessHasOperationalData($conn)) {
        setInvSetting($conn, 'setup_completed', '1');
        setInvSetting($conn, 'setup_completed_at', date('Y-m-d H:i:s'));
        setInvSetting($conn, 'setup_skipped_existing_data', '1');
    }
}

// ---------- Applying a template ----------------------------------------

/**
 * Create the departments, categories and units a template suggests.
 *
 * Additive and idempotent: anything already present by name is left
 * exactly as it is, nothing is renamed, and nothing is ever deleted. An
 * administrator can run a template over an existing structure without
 * losing what they have already built.
 *
 * Returns a count of what it created.
 */
function businessApplyTemplate(mysqli $conn, string $typeKey, bool $withCategories = true, bool $withUnits = true): array {
    $type = businessType($typeKey);
    $made = ['departments' => 0, 'categories' => 0, 'units' => 0];
    if (!$type) { return $made; }

    // --- Departments ---------------------------------------------------
    $deptKeyByName = [];
    foreach (catalogDepartmentsFresh($conn) as $key => $meta) {
        $deptKeyByName[mb_strtolower($meta['label'])] = $key;
    }

    foreach ($type['departments'] as $d) {
        [$name, $icon, $colour] = $d;
        $lookup = mb_strtolower($name);
        if (isset($deptKeyByName[$lookup])) { continue; }
        [$ok, , $key] = catalogCreateDepartment($conn, $name, $icon, $colour);
        if ($ok) {
            $deptKeyByName[$lookup] = $key;
            $made['departments']++;
        }
    }

    // --- Categories ----------------------------------------------------
    if ($withCategories && !empty($type['categories'])) {
        $existing = [];
        $res = @$conn->query("SELECT LOWER(name) AS n FROM inv_categories");
        if ($res instanceof mysqli_result) {
            while ($r = $res->fetch_assoc()) { $existing[(string)$r['n']] = true; }
            $res->free();
        }
        $stmt = $conn->prepare("INSERT INTO inv_categories (name, department, is_active) VALUES (?, ?, 1)");
        if ($stmt) {
            foreach ($type['categories'] as $deptName => $cats) {
                $deptKey = $deptKeyByName[mb_strtolower($deptName)] ?? catalogFirstActiveDepartment($conn);
                foreach ($cats as $catName) {
                    if (isset($existing[mb_strtolower($catName)])) { continue; }
                    $stmt->bind_param('ss', $catName, $deptKey);
                    if ($stmt->execute()) {
                        $existing[mb_strtolower($catName)] = true;
                        $made['categories']++;
                    }
                }
            }
            $stmt->close();
        }
    }

    // --- Units ---------------------------------------------------------
    if ($withUnits && !empty($type['units'])) {
        $existing = [];
        $res = @$conn->query("SELECT LOWER(name) AS n FROM inv_units");
        if ($res instanceof mysqli_result) {
            while ($r = $res->fetch_assoc()) { $existing[(string)$r['n']] = true; }
            $res->free();
        }
        $stmt = $conn->prepare("INSERT INTO inv_units (name, abbreviation, is_active) VALUES (?, ?, 1)");
        if ($stmt) {
            foreach ($type['units'] as $u) {
                if (isset($existing[mb_strtolower($u[0])])) { continue; }
                $stmt->bind_param('ss', $u[0], $u[1]);
                if ($stmt->execute()) {
                    $existing[mb_strtolower($u[0])] = true;
                    $made['units']++;
                }
            }
            $stmt->close();
        }
    }

    catalogDepartmentsReload($conn);
    return $made;
}
