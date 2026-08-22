<?php
// ============================================================
// Retail Catalog - shared helper functions
// ------------------------------------------------------------
// Product pricing, customer identity and small view helpers used
// by the till, the products page and the reports.
// Requires an active mysqli $conn from includes/db.php.
// ============================================================

require_once __DIR__ . '/inventory_schema.php';
require_once __DIR__ . '/inventory_functions.php';
require_once __DIR__ . '/catalog_schema.php';

/** Include at the top of any page that reads or writes products. */
function catalogBoot(mysqli $conn): void {
    ensureInventorySchema($conn);
    ensureCoreSchema($conn);
    ensureCatalogSchema($conn);
    require_once __DIR__ . '/shop_settings.php';
    require_once __DIR__ . '/business_types.php';
    ensureShopSettingsSchema($conn);
    businessSyncSetupState($conn);
}

// ---------- Customer identity ------------------------------------------

/**
 * Normalise a Tanzanian phone number to +255XXXXXXXXX.
 * The phone number is the customer's identity across the system, so
 * every entry point must normalise the same way or the same person
 * ends up with two records.
 */
function retailNormalizePhone(string $phone): string {
    $digits = preg_replace('/[^0-9]/', '', $phone);
    if ($digits === '') { return ''; }

    if (str_starts_with($digits, '255')) {
        return '+' . $digits;
    }
    if (str_starts_with($digits, '0')) {
        return '+255' . substr($digits, 1);
    }
    // Bare 9-digit local number (e.g. 743820033).
    if (strlen($digits) === 9) {
        return '+255' . $digits;
    }
    return '+' . $digits;
}

/**
 * Find the customer with this phone number, or create one.
 * Returns the customer id, or 0 on failure.
 */
function retailFindOrCreateCustomer(mysqli $conn, string $phone, string $name): int {
    $phone = retailNormalizePhone($phone);
    if ($phone === '') { return 0; }

    $stmt = $conn->prepare("SELECT id FROM customer WHERE phone_number = ? LIMIT 1");
    if (!$stmt) { return 0; }
    $stmt->bind_param('s', $phone);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) { return (int)$row['id']; }

    $name = trim($name) !== '' ? trim($name) : 'Walk-in customer';
    $ins = $conn->prepare("INSERT INTO customer (name, phone_number, registration_date) VALUES (?, ?, NOW())");
    if (!$ins) { return 0; }
    $ins->bind_param('ss', $name, $phone);
    $ok = $ins->execute();
    $id = (int)$conn->insert_id;
    $ins->close();
    return $ok ? $id : 0;
}

// ---------- Reference numbers ------------------------------------------

/**
 * Random alphanumeric suffix. Used to make receipt numbers
 * unpredictable so a customer can't guess another receipt's number
 * from their own.
 */
function retailRandomAlnum(int $len = 3): string {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no I/O/0/1 - misread on paper
    $out = '';
    for ($i = 0; $i < $len; $i++) {
        $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $out;
}

// ---------- Pricing -----------------------------------------------------

/** Effective unit price for a product row (an active promo wins). */
function retailEffectivePrice(array $product): float {
    $price = (float)($product['selling_price'] ?? 0);

    // A promotion the shopkeeper set by hand.
    if (!empty($product['promo_active'])
        && isset($product['promo_price'])
        && $product['promo_price'] !== null
        && (float)$product['promo_price'] > 0) {
        $price = (float)$product['promo_price'];
    }

    // Automatic mark-down on stock approaching its expiry date. Applied
    // here, in the one function every screen and the checkout itself
    // call, so the shelf, the till, the receipt and the ledger cannot
    // disagree about what the customer pays.
    $exp = retailExpiryDiscount($product);
    if ($exp !== null) {
        $marked = round((float)($product['selling_price'] ?? 0) * (1 - $exp['percent'] / 100), 2);
        // The customer gets whichever is cheaper: a hand-set promotion
        // is never overridden upwards by the automatic rule.
        $price = min($price, $marked);
    }

    return $price;
}

/**
 * Has this stock passed its expiry date?
 *
 * The date itself counts as still good: a "best before 15 August" item
 * is sellable all through the 15th and expired from the 16th.
 */
function retailIsExpired(array $product): bool {
    $raw = $product['expiry_date'] ?? null;
    if ($raw === null || $raw === '' || $raw === '0000-00-00') { return false; }

    $expiry = strtotime((string)$raw . ' 00:00:00');
    if ($expiry === false) { return false; }

    return $expiry < strtotime(date('Y-m-d') . ' 00:00:00');
}

/**
 * Should the till refuse to sell expired stock?
 *
 * On by default. It is a setting rather than a hard rule because "best
 * before" and "use by" are not the same thing - a shop may legitimately
 * sell bread the day after its best-before date, while nobody should be
 * selling expired milk. Turning it off is a decision the owner makes
 * deliberately on the settings screen, not something a cashier can do
 * at the till.
 */
function retailBlockExpiredSales(?mysqli $conn = null): bool {
    return (string)retailExpirySetting($conn, 'expiry_block_sales', '1') === '1';
}

/**
 * The reason this product cannot be rung up, or null when it can.
 * Written for a customer to overhear, not for a developer.
 */
function retailExpiryBlockReason(array $product): ?string {
    if (!retailBlockExpiredSales()) { return null; }
    if (!retailIsExpired($product))  { return null; }

    $when = strtotime((string)$product['expiry_date']);
    return '"' . ($product['name'] ?? 'This item') . '" expired on '
         . date('j M Y', $when) . ' and cannot be sold. Please remove it from the shelf.';
}

/** Is the automatic near-expiry mark-down switched on? */
function retailExpiryDiscountEnabled(?mysqli $conn = null): bool {
    return (string)retailExpirySetting($conn, 'expiry_discount_enabled', '1') === '1';
}

/** How many days before expiry the mark-down starts. */
function retailExpiryDiscountDays(?mysqli $conn = null): int {
    return max(1, (int)retailExpirySetting($conn, 'expiry_discount_days', '30'));
}

/** How much is taken off, as a percentage. */
function retailExpiryDiscountPercent(?mysqli $conn = null): float {
    $p = (float)retailExpirySetting($conn, 'expiry_discount_percent', '5');
    return max(0.0, min(90.0, $p));
}

/**
 * Read one of the three settings, cached for the request.
 *
 * retailEffectivePrice() is called once per line on a busy till, so this
 * must not cost a query each time.
 */
function retailExpirySetting(?mysqli $conn, string $key, string $default): string {
    static $cache = [];
    if (array_key_exists($key, $cache)) { return $cache[$key]; }

    if (!($conn instanceof mysqli) && isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli) {
        $conn = $GLOBALS['conn'];
    }
    if (!($conn instanceof mysqli)) { return $default; }

    $cache[$key] = (string)getInvSetting($conn, $key, $default);
    return $cache[$key];
}

/**
 * The near-expiry mark-down for a product, or null when none applies.
 *
 * Returns ['percent' => 5.0, 'days_left' => 12, 'expiry' => '2026-08-25'].
 *
 * Deliberately NOT applied to stock that has already expired: expired
 * goods should be pulled off the shelf and written off, not sold at a
 * discount, so the rule stops at the expiry date rather than carrying
 * on past it. The product needs $product['expiry_date'] to be present -
 * every query that prices a product selects it.
 */
function retailExpiryDiscount(array $product): ?array {
    if (!retailExpiryDiscountEnabled()) { return null; }

    $raw = $product['expiry_date'] ?? null;
    if ($raw === null || $raw === '' || $raw === '0000-00-00') { return null; }

    $expiry = strtotime((string)$raw . ' 00:00:00');
    if ($expiry === false) { return null; }

    $today = strtotime(date('Y-m-d') . ' 00:00:00');
    $days  = (int)round(($expiry - $today) / 86400);

    if ($days < 0)  { return null; }   // already expired - not a promotion
    if ($days > retailExpiryDiscountDays()) { return null; }

    $percent = retailExpiryDiscountPercent();
    if ($percent <= 0) { return null; }

    return ['percent' => $percent, 'days_left' => $days, 'expiry' => (string)$raw];
}

/**
 * Everything currently marked down for expiry, soonest first.
 *
 * Feeds the customer display's idle slideshow, and is the same list a
 * cashier can be shown. Only stock that is actually sellable qualifies:
 * on the shelf, enabled, visible at the till, in a trading department
 * and with something left to sell - there is no point advertising an
 * offer the till would then refuse.
 */
function retailExpiryOffers(mysqli $conn, int $limit = 24): array {
    if (!retailExpiryDiscountEnabled($conn)) { return []; }

    $days = retailExpiryDiscountDays($conn);
    $deptFilter = catalogDepartmentFilterSql($conn, 'i');

    $sql = "SELECT i.id, i.name, i.expiry_date, i.current_stock,
                   u.abbreviation AS unit,
                   c.name AS category_name,
                   d.selling_price, d.promo_price, d.promo_active, d.brand, d.package_size,
                   (SELECT file_path FROM retail_product_images img
                     WHERE img.item_id = i.id ORDER BY img.sort_order, img.id LIMIT 1) AS image
            FROM inv_items i
            JOIN retail_product_details d ON d.item_id = i.id
            LEFT JOIN inv_units u ON u.id = i.unit_id
            LEFT JOIN inv_categories c ON c.id = i.category_id
            WHERE i.deleted_at IS NULL AND i.status = 'active'
              AND d.is_enabled = 1 AND d.is_pos_visible = 1
              AND d.usage_type IN ('sale','both')
              AND i.current_stock > 0
              AND i.expiry_date IS NOT NULL
              AND i.expiry_date >= CURDATE()
              AND i.expiry_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
              $deptFilter
            ORDER BY i.expiry_date ASC, d.selling_price DESC
            LIMIT ?";

    $stmt = @$conn->prepare($sql);
    if (!$stmt) { return []; }
    $stmt->bind_param('ii', $days, $limit);
    if (!$stmt->execute()) { $stmt->close(); return []; }
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $out = [];
    foreach ($rows as $r) {
        $disc = retailExpiryDiscount($r);
        if ($disc === null) { continue; }          // settings changed under us
        $was = (float)$r['selling_price'];
        $now = retailEffectivePrice($r);
        if ($now >= $was) { continue; }            // nothing to advertise
        $out[] = [
            'id'        => (int)$r['id'],
            'name'      => (string)$r['name'],
            'brand'     => (string)($r['brand'] ?? ''),
            'pack'      => (string)($r['package_size'] ?? ''),
            'category'  => (string)($r['category_name'] ?? ''),
            'image'     => (string)($r['image'] ?? ''),
            'was'       => $was,
            'now'       => $now,
            'saving'    => round($was - $now, 2),
            'percent'   => $disc['percent'],
            'days_left' => $disc['days_left'],
            'expiry'    => $disc['expiry'],
            'stock'     => (float)$r['current_stock'],
            'unit'      => (string)($r['unit'] ?? ''),
        ];
    }
    return $out;
}

/** Margin percentage of a sale line, given price and average cost. */
function retailMarginPercent(float $price, float $cost): ?float {
    if ($price <= 0) { return null; }
    return round((($price - $cost) / $price) * 100, 1);
}

// ---------- Product queries --------------------------------------------

/**
 * Products for the admin products screen. Returns one row per item
 * with its catalog details, category, unit and primary image.
 */
function retailGetProducts(mysqli $conn, string $search = '', string $department = '', int $categoryId = 0): array {
    $sql = "SELECT i.id, i.sku, i.barcode, i.name, i.department, i.category_id, i.unit_id,
                   i.current_stock, i.reorder_level, i.min_stock, i.average_cost, i.purchase_price, i.status,
                   c.name AS category_name, u.abbreviation AS unit,
                   d.id AS detail_id, d.usage_type, d.description, d.package_size, d.brand,
                   d.selling_price, d.promo_price, d.promo_active, d.is_enabled, d.is_pos_visible,
                   (SELECT file_path FROM retail_product_images img
                     WHERE img.item_id = i.id ORDER BY img.sort_order, img.id LIMIT 1) AS image
            FROM inv_items i
            LEFT JOIN retail_product_details d ON d.item_id = i.id
            LEFT JOIN inv_categories c ON c.id = i.category_id
            LEFT JOIN inv_units u ON u.id = i.unit_id
            WHERE i.deleted_at IS NULL";
    $params = [];
    $types = '';

    if ($search !== '') {
        $sql .= " AND (i.name LIKE ? OR i.sku LIKE ? OR i.barcode LIKE ? OR d.brand LIKE ?)";
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like);
        $types .= 'ssss';
    }
    if ($department !== '' && isset(catalogDepartments()[$department])) {
        $sql .= " AND i.department = ?";
        $params[] = $department;
        $types .= 's';
    }
    if ($categoryId > 0) {
        $sql .= " AND i.category_id = ?";
        $params[] = $categoryId;
        $types .= 'i';
    }

    $sql .= " ORDER BY i.name ASC";
    $stmt = $conn->prepare($sql);
    if (!$stmt) { return []; }
    if ($types !== '') { $stmt->bind_param($types, ...$params); }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/** A single product with its catalog details, or null. */
function retailGetProduct(mysqli $conn, int $itemId): ?array {
    $stmt = $conn->prepare(
        "SELECT i.*, c.name AS category_name, u.abbreviation AS unit, u.name AS unit_name,
                d.usage_type, d.description, d.package_size, d.brand, d.selling_price,
                d.promo_price, d.promo_active, d.is_enabled, d.is_pos_visible
         FROM inv_items i
         LEFT JOIN retail_product_details d ON d.item_id = i.id
         LEFT JOIN inv_categories c ON c.id = i.category_id
         LEFT JOIN inv_units u ON u.id = i.unit_id
         WHERE i.id = ? AND i.deleted_at IS NULL LIMIT 1");
    if (!$stmt) { return null; }
    $stmt->bind_param('i', $itemId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/**
 * Create the catalog details row for an item if it doesn't have one,
 * so a product created from the inventory screen is immediately
 * sellable once a price is set.
 */
function retailEnsureDetails(mysqli $conn, int $itemId): void {
    $stmt = $conn->prepare("INSERT IGNORE INTO retail_product_details (item_id, usage_type) VALUES (?, 'sale')");
    if (!$stmt) { return; }
    $stmt->bind_param('i', $itemId);
    $stmt->execute();
    $stmt->close();
}

/**
 * Save the sellable attributes of a product. Prices are stored as
 * given here (this is the admin screen); the till always re-reads
 * them from the database at checkout and never trusts the browser.
 */
function retailSaveProductDetails(mysqli $conn, int $itemId, array $data): array {
    if ($itemId <= 0) { return [false, 'Unknown product.']; }

    retailEnsureDetails($conn, $itemId);

    $usageType   = in_array($data['usage_type'] ?? 'sale', ['internal', 'sale', 'both'], true)
                 ? $data['usage_type'] : 'sale';
    $description = trim((string)($data['description'] ?? '')) ?: null;
    $packageSize = trim((string)($data['package_size'] ?? '')) ?: null;
    $brand       = trim((string)($data['brand'] ?? '')) ?: null;
    $price       = max(0.0, round((float)($data['selling_price'] ?? 0), 2));
    $promoPrice  = ($data['promo_price'] ?? '') === '' ? null : max(0.0, round((float)$data['promo_price'], 2));
    $promoActive = !empty($data['promo_active']) ? 1 : 0;
    $isEnabled   = !empty($data['is_enabled']) ? 1 : 0;
    $posVisible  = !empty($data['is_pos_visible']) ? 1 : 0;

    // A promo that isn't cheaper than the shelf price would quietly
    // overcharge, so refuse it rather than storing it.
    if ($promoActive && ($promoPrice === null || $promoPrice <= 0)) {
        return [false, 'Enter a promotional price before switching the promotion on.'];
    }
    if ($promoActive && $promoPrice >= $price && $price > 0) {
        return [false, 'The promotional price must be lower than the normal selling price.'];
    }

    $stmt = $conn->prepare(
        "UPDATE retail_product_details
            SET usage_type = ?, description = ?, package_size = ?, brand = ?,
                selling_price = ?, promo_price = ?, promo_active = ?,
                is_enabled = ?, is_pos_visible = ?
          WHERE item_id = ?");
    if (!$stmt) { return [false, 'Could not save the product.']; }
    $stmt->bind_param('ssssddiiii',
        $usageType, $description, $packageSize, $brand,
        $price, $promoPrice, $promoActive, $isEnabled, $posVisible, $itemId);
    $ok = $stmt->execute();
    $stmt->close();

    return $ok ? [true, 'Product saved.'] : [false, 'Could not save the product.'];
}

// ---------- View helpers -----------------------------------------------

/** Stock badge text and Bootstrap colour for a quantity. */
function retailStockStatus(float $stock, float $reorderLevel = 0): array {
    if ($stock <= 0) { return ['Out of stock', 'danger']; }
    if ($reorderLevel > 0 && $stock <= $reorderLevel) { return ['Reorder', 'warning']; }
    if ($stock <= 5) { return ['Low', 'warning']; }
    return ['In stock', 'success'];
}

/** Flash a one-off message to the next page load. */
function retailFlash(string $type, string $msg): void {
    $_SESSION['inv_flash'] = ['type' => $type, 'msg' => $msg];
}
