<?php
// ============================================================
// Retail Catalog - self-installing schema
// ------------------------------------------------------------
// Replaces the old shop module's schema. The catalog does NOT
// duplicate inventory: a product IS an `inv_items` row, extended
// 1:1 by `retail_product_details` (selling price, description,
// brand, pack size, till visibility) and illustrated by
// `retail_product_images`. Stock always lives on
// inv_items.current_stock and only ever moves through
// recordStockMovement().
//
// Legacy installs carry these as `shop_product_details` /
// `shop_product_images`. This installer RENAMES them in place when
// it finds them, so an existing database upgrades itself with no
// manual migration and no data loss.
//
// A version flag in inv_settings keeps the per-request cost to one
// cheap SELECT once installed.
// ============================================================

// v1: renamed from the shop module; SEO columns (slug, meta_title,
// meta_description) and the online-storefront tables (orders, order
// items, status log, reviews) are gone - this is an in-store retail
// system with no public catalogue.
// getInvSetting()/setInvSetting() live in inventory_functions.php. It
// pulls in inventory_schema and core_schema, neither of which knows
// about the catalog, so there is no include cycle here.
require_once __DIR__ . '/inventory_functions.php';

// v3: departments are DATA, not code. They live in `retail_departments`
// and the administrator creates whatever structure the business needs -
// "Food / Beverages / Household" for a supermarket, "Plumbing /
// Electrical / Tools" for a hardware shop, "Medicines / Personal Care"
// for a duka la dawa. The seven `department` columns were ENUMs of three
// fixed values; v3 widens them to VARCHAR(32) in place, which keeps every
// stored value byte-for-byte and loses nothing.
//
// v2 (superseded): the three departments were fixed in code and could
// only be switched on and off via dept_*_enabled in inv_settings. Those
// settings are read once during the upgrade so an administrator's
// existing choices carry over, and are then obsolete.
if (!defined('CATALOG_SCHEMA_VERSION')) {
    define('CATALOG_SCHEMA_VERSION', '3');
}

/** Tables carrying a `department` column, widened from ENUM in v3. */
function catalogDepartmentColumns(): array {
    return ['inv_items', 'inv_categories', 'sales_transactions',
            'sales_transaction_items', 'pos_terminals', 'acc_journal', 'acc_expenses'];
}

/** Fallback used when a business has not defined anything yet. */
function catalogDefaultDepartmentKey(): string { return 'general'; }

/**
 * Every department the business has defined, active or not, in display
 * order: `key => ['label', 'icon', 'colour', 'sort', 'active']`.
 *
 * Use this for anything HISTORICAL - the ledger, past sales, closed
 * Z-reports, the P&L - so trade that already happened keeps rendering
 * its department name after that department is switched off. Use
 * catalogActiveDepartments() for anything forward-looking.
 *
 * $conn is optional purely so the ~30 existing call sites that had no
 * connection to hand keep working: the registry is cached per request,
 * and the cache is filled by ensureCatalogSchema() during boot.
 */
function catalogDepartments(?mysqli $conn = null): array {
    // Cached in a global rather than a static so a page that edits the
    // registry can clear it (catalogDepartmentsReload) and immediately
    // see its own change.
    if (isset($GLOBALS['__catalog_dept_cache']) && is_array($GLOBALS['__catalog_dept_cache'])) {
        return $GLOBALS['__catalog_dept_cache'];
    }

    if (!($conn instanceof mysqli) && isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof mysqli) {
        $conn = $GLOBALS['conn'];
    }
    if (!($conn instanceof mysqli)) {
        // No database to read - never fatal, never empty.
        return [catalogDefaultDepartmentKey() => catalogDepartmentDefaults('General')];
    }

    $rows = @$conn->query("SELECT dept_key, name, icon, colour, sort_order, is_active
                           FROM retail_departments ORDER BY sort_order, name");
    if (!($rows instanceof mysqli_result)) {
        // Table not installed yet (first request of a fresh install).
        return [catalogDefaultDepartmentKey() => catalogDepartmentDefaults('General')];
    }

    $out = [];
    while ($r = $rows->fetch_assoc()) {
        $out[(string)$r['dept_key']] = [
            'label'  => (string)$r['name'],
            'icon'   => (string)$r['icon'],
            'colour' => (string)$r['colour'],
            'sort'   => (int)$r['sort_order'],
            'active' => ((int)$r['is_active'] === 1),
        ];
    }
    $rows->free();

    // An install mid-upgrade could momentarily have an empty table; never
    // hand back nothing, or every product dropdown would be unusable.
    if (!$out) { $out = [catalogDefaultDepartmentKey() => catalogDepartmentDefaults('General')]; }

    $GLOBALS['__catalog_dept_cache'] = $out;
    return $out;
}

/** Refresh the per-request cache after any write to the registry. */
function catalogDepartmentsReload(mysqli $conn): array {
    unset($GLOBALS['__catalog_dept_cache']);
    return catalogDepartments($conn);
}

/** Read the registry straight from the database, bypassing the cache. */
function catalogDepartmentsFresh(mysqli $conn): array {
    $out  = [];
    $rows = @$conn->query("SELECT dept_key, name, icon, colour, sort_order, is_active
                           FROM retail_departments ORDER BY sort_order, name");
    if ($rows instanceof mysqli_result) {
        while ($r = $rows->fetch_assoc()) {
            $out[(string)$r['dept_key']] = [
                'label'  => (string)$r['name'],
                'icon'   => (string)$r['icon'],
                'colour' => (string)$r['colour'],
                'sort'   => (int)$r['sort_order'],
                'active' => ((int)$r['is_active'] === 1),
            ];
        }
        $rows->free();
    }
    return $out;
}

/** The shape every department entry has, so callers can rely on it. */
function catalogDepartmentDefaults(string $label): array {
    return ['label' => $label, 'icon' => 'fa-boxes-stacked', 'colour' => '#8b9aa2',
            'sort' => 0, 'active' => true];
}

/**
 * Human label for a department key.
 *
 * A key that is no longer in the registry still has to render - it may
 * be stamped on a sale from last year - so the key itself is humanised
 * rather than replaced with a wrong label.
 */
function catalogDepartmentLabel(?string $key): string {
    if ($key === null || $key === '') { return 'Unassigned'; }
    $all = catalogDepartments();
    if (isset($all[$key])) { return $all[$key]['label']; }
    return ucwords(str_replace(['_', '-'], ' ', $key));
}

/** Icon for a department key, with a neutral fallback. */
function catalogDepartmentIcon(?string $key): string {
    $all = catalogDepartments();
    return $all[$key]['icon'] ?? 'fa-boxes-stacked';
}

/** Colour for a department key, with a neutral fallback. */
function catalogDepartmentColour(?string $key): string {
    $all = catalogDepartments();
    return $all[$key]['colour'] ?? '#8b9aa2';
}

/**
 * Is this department currently trading?
 * A key that is not in the registry counts as enabled, so a value
 * already stored on a historical row can never be hidden from a report.
 */
function catalogDepartmentEnabled(mysqli $conn, string $key): bool {
    $all = catalogDepartments($conn);
    if (!isset($all[$key])) { return true; }
    return (bool)$all[$key]['active'];
}

/**
 * Departments currently switched on, same shape as catalogDepartments().
 * Use for anything forward-looking: the till grid, department tabs,
 * new-product dropdowns, pickers.
 */
function catalogActiveDepartments(mysqli $conn): array {
    $out = [];
    foreach (catalogDepartments($conn) as $key => $meta) {
        if (!empty($meta['active'])) { $out[$key] = $meta; }
    }
    return $out;
}

/**
 * The department a new record should default to: the first active one.
 * Nothing in the system may assume a department called "general" exists,
 * because the administrator can rename or remove it.
 */
function catalogFirstActiveDepartment(mysqli $conn): string {
    $active = catalogActiveDepartments($conn);
    if ($active) { return (string)array_key_first($active); }
    $all = catalogDepartments($conn);
    return $all ? (string)array_key_first($all) : catalogDefaultDepartmentKey();
}

/**
 * Turn a department name into a stable storage key.
 *
 * The key is what lands in seven tables and on every historical row, so
 * it is generated once at creation and never changes afterwards - an
 * administrator renaming "Medicines" to "Pharmacy Stock" must not
 * orphan a year of sales.
 */
function catalogDepartmentKeyFromName(mysqli $conn, string $name): string {
    $base = strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '_', $name), '_'));
    if ($base === '') { $base = 'dept'; }
    $base = substr($base, 0, 28);

    $existing = catalogDepartmentsFresh($conn);
    if (!isset($existing[$base])) { return $base; }
    for ($i = 2; $i < 100; $i++) {
        if (!isset($existing[$base . '_' . $i])) { return $base . '_' . $i; }
    }
    return $base . '_' . time();
}

/**
 * Create a department. Returns [ok, message, key].
 */
function catalogCreateDepartment(mysqli $conn, string $name, string $icon = '', string $colour = ''): array {
    $name = trim($name);
    if ($name === '')            { return [false, 'A department needs a name.', '']; }
    if (mb_strlen($name) > 60)   { return [false, 'That name is too long (60 characters maximum).', '']; }

    foreach (catalogDepartmentsFresh($conn) as $meta) {
        if (mb_strtolower($meta['label']) === mb_strtolower($name)) {
            return [false, 'There is already a department called "' . $name . '".', ''];
        }
    }

    $key    = catalogDepartmentKeyFromName($conn, $name);
    $icon   = $icon !== ''   ? $icon   : 'fa-boxes-stacked';
    $colour = $colour !== '' ? $colour : '#8b9aa2';

    $next = 0;
    $res  = @$conn->query("SELECT COALESCE(MAX(sort_order), 0) + 1 AS n FROM retail_departments");
    if ($res instanceof mysqli_result) { $next = (int)($res->fetch_assoc()['n'] ?? 1); $res->free(); }

    $stmt = $conn->prepare("INSERT INTO retail_departments (dept_key, name, icon, colour, sort_order, is_active)
                            VALUES (?, ?, ?, ?, ?, 1)");
    if (!$stmt) { return [false, 'The department could not be saved.', '']; }
    $stmt->bind_param('ssssi', $key, $name, $icon, $colour, $next);
    $ok = $stmt->execute();
    $stmt->close();
    if (!$ok) { return [false, 'The department could not be saved.', '']; }

    catalogDepartmentsReload($conn);
    return [true, '"' . $name . '" was added.', $key];
}

/**
 * Rename a department or change how it looks. The key is deliberately
 * not editable - see catalogDepartmentKeyFromName().
 */
function catalogUpdateDepartment(mysqli $conn, string $key, string $name, string $icon, string $colour): array {
    $name = trim($name);
    if ($name === '')          { return [false, 'A department needs a name.']; }
    if (mb_strlen($name) > 60) { return [false, 'That name is too long (60 characters maximum).']; }

    $all = catalogDepartmentsFresh($conn);
    if (!isset($all[$key])) { return [false, 'Unknown department.']; }
    foreach ($all as $k => $meta) {
        if ($k !== $key && mb_strtolower($meta['label']) === mb_strtolower($name)) {
            return [false, 'There is already a department called "' . $name . '".'];
        }
    }

    $icon   = $icon !== ''   ? $icon   : 'fa-boxes-stacked';
    $colour = $colour !== '' ? $colour : '#8b9aa2';

    $stmt = $conn->prepare("UPDATE retail_departments SET name = ?, icon = ?, colour = ? WHERE dept_key = ?");
    if (!$stmt) { return [false, 'The change could not be saved.']; }
    $stmt->bind_param('ssss', $name, $icon, $colour, $key);
    $ok = $stmt->execute();
    $stmt->close();
    if (!$ok) { return [false, 'The change could not be saved.']; }

    catalogDepartmentsReload($conn);
    return [true, 'Saved.'];
}

/**
 * Delete a department, but only when nothing refers to it.
 *
 * This follows the same rule as categories, units and suppliers: a
 * record that is in use is deactivated, never deleted, so history
 * survives. The caller is told which it got.
 */
function catalogDeleteDepartment(mysqli $conn, string $key): array {
    $all = catalogDepartmentsFresh($conn);
    if (!isset($all[$key])) { return [false, 'Unknown department.']; }
    if (count($all) <= 1)    { return [false, 'This is the only department - the shop needs at least one.']; }

    $refs = catalogDepartmentReferences($conn, $key);
    $total = array_sum($refs);

    if ($total > 0) {
        $parts = [];
        foreach ($refs as $what => $n) { if ($n > 0) { $parts[] = $n . ' ' . $what; } }
        $stillOn = 0;
        foreach ($all as $k => $meta) { if ($k !== $key && !empty($meta['active'])) { $stillOn++; } }
        if ($stillOn === 0) {
            return [false, '"' . $all[$key]['label'] . '" is used by ' . implode(', ', $parts)
                         . ', and it is the only active department, so it cannot be removed.'];
        }
        $stmt = $conn->prepare("UPDATE retail_departments SET is_active = 0 WHERE dept_key = ?");
        if ($stmt) { $stmt->bind_param('s', $key); $stmt->execute(); $stmt->close(); }
        catalogDepartmentsReload($conn);
        return [true, '"' . $all[$key]['label'] . '" is used by ' . implode(', ', $parts)
                    . ', so it was disabled rather than deleted. Its history is kept.'];
    }

    $stmt = $conn->prepare("DELETE FROM retail_departments WHERE dept_key = ?");
    if (!$stmt) { return [false, 'The department could not be removed.']; }
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $stmt->close();

    catalogDepartmentsReload($conn);
    return [true, '"' . $all[$key]['label'] . '" was removed.'];
}

/**
 * Everything that would be orphaned by deleting a department, counted
 * across both operational and historical tables.
 */
function catalogDepartmentReferences(mysqli $conn, string $key): array {
    $counts = [
        'product(s)'      => "SELECT COUNT(*) c FROM inv_items WHERE department = ? AND deleted_at IS NULL",
        'category(ies)'   => "SELECT COUNT(*) c FROM inv_categories WHERE department = ?",
        'sale(s)'         => "SELECT COUNT(*) c FROM sales_transactions WHERE department = ?",
        'till(s)'         => "SELECT COUNT(*) c FROM pos_terminals WHERE department = ?",
        'journal entries' => "SELECT COUNT(*) c FROM acc_journal WHERE department = ?",
        'expense(s)'      => "SELECT COUNT(*) c FROM acc_expenses WHERE department = ?",
    ];
    $out = [];
    foreach ($counts as $label => $sql) {
        $out[$label] = 0;
        $stmt = @$conn->prepare($sql);
        if (!$stmt) { continue; }
        $stmt->bind_param('s', $key);
        if ($stmt->execute()) {
            $out[$label] = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
        }
        $stmt->close();
    }
    return $out;
}

/** Apply a new display order. Keys not listed keep their place at the end. */
function catalogReorderDepartments(mysqli $conn, array $orderedKeys): array {
    $all = catalogDepartmentsFresh($conn);
    $stmt = $conn->prepare("UPDATE retail_departments SET sort_order = ? WHERE dept_key = ?");
    if (!$stmt) { return [false, 'The order could not be saved.']; }
    $i = 0;
    foreach ($orderedKeys as $key) {
        $key = (string)$key;
        if (!isset($all[$key])) { continue; }
        $i++;
        $stmt->bind_param('is', $i, $key);
        $stmt->execute();
    }
    $stmt->close();
    catalogDepartmentsReload($conn);
    return [true, 'Order saved.'];
}

/**
 * A SQL fragment restricting a query to departments that are trading,
 * e.g. " AND i.department IN ('supermarket','general')".
 *
 * Use this on OPERATIONAL queries - anything describing what the shop
 * currently stocks or sells (product lists, stock levels, reorder
 * alerts, pickers, valuations).
 *
 * Do NOT use it on FINANCIAL history - the ledger, past sales, closed
 * Z-reports and the P&L must keep reporting a department after it is
 * switched off, or last month's figures would change retroactively and
 * the books would stop balancing.
 *
 * The values are department keys generated by the system from a
 * restricted character set (catalogDepartmentKeyFromName), never raw
 * user input, and they are escaped again here.
 */
function catalogDepartmentFilterSql(mysqli $conn, string $alias = 'i'): string {
    $active = array_keys(catalogActiveDepartments($conn));
    if (!$active) { return ' AND 1=0'; }                 // nothing trades
    if (count($active) === count(catalogDepartments())) {
        return '';                                        // all on - no filter needed
    }
    $safe = array_map(function ($d) use ($conn) {
        return "'" . $conn->real_escape_string($d) . "'";
    }, $active);
    return ' AND ' . $alias . '.department IN (' . implode(',', $safe) . ')';
}

/**
 * Turn a department on or off.
 *
 * Refuses to switch off the last enabled one - a shop with no active
 * department has a till that can sell nothing at all, and there would
 * be no way back through the UI.
 *
 * Returns [ok, message].
 */
function catalogSetDepartmentEnabled(mysqli $conn, string $key, bool $enabled): array {
    $all = catalogDepartmentsFresh($conn);
    if (!isset($all[$key])) { return [false, 'Unknown department.']; }

    if (!$enabled) {
        $stillOn = 0;
        foreach ($all as $k => $meta) { if ($k !== $key && !empty($meta['active'])) { $stillOn++; } }
        if ($stillOn === 0) {
            return [false, 'At least one department must stay enabled - otherwise the till would have nothing to sell.'];
        }
    }

    $flag = $enabled ? 1 : 0;
    $stmt = $conn->prepare("UPDATE retail_departments SET is_active = ? WHERE dept_key = ?");
    if (!$stmt) { return [false, 'The change could not be saved.']; }
    $stmt->bind_param('is', $flag, $key);
    $ok = $stmt->execute();
    $stmt->close();
    if (!$ok) { return [false, 'The change could not be saved.']; }

    catalogDepartmentsReload($conn);
    return [true, $all[$key]['label'] . ' is now ' . ($enabled ? 'enabled' : 'disabled') . '.'];
}

/**
 * How much trade a department carries, so an administrator can see the
 * consequence of switching it off before they do it.
 */
function catalogDepartmentStats(mysqli $conn, string $key): array {
    $stats = ['items' => 0, 'on_till' => 0, 'stock_value' => 0.0, 'sales_count' => 0];

    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS items,
                COALESCE(SUM(i.current_stock * i.average_cost), 0) AS stock_value,
                SUM(CASE WHEN d.is_enabled = 1 AND d.is_pos_visible = 1
                          AND d.usage_type IN ('sale','both') THEN 1 ELSE 0 END) AS on_till
         FROM inv_items i
         LEFT JOIN retail_product_details d ON d.item_id = i.id
         WHERE i.deleted_at IS NULL AND i.status = 'active' AND i.department = ?");
    if ($stmt) {
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            $stats['items']       = (int)$row['items'];
            $stats['on_till']     = (int)$row['on_till'];
            $stats['stock_value'] = (float)$row['stock_value'];
        }
    }

    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM sales_transactions WHERE department = ? AND status = 'completed'");
    if ($stmt) {
        $stmt->bind_param('s', $key);
        $stmt->execute();
        $stats['sales_count'] = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
        $stmt->close();
    }

    return $stats;
}

/**
 * Ensure the catalog tables exist and are up to date.
 * Idempotent and safe to call on every request.
 */
function ensureCatalogSchema(mysqli $conn): void {
    // Fast path - already installed.
    $check = @$conn->query("SELECT setting_value FROM inv_settings WHERE setting_key = 'catalog_schema_version' LIMIT 1");
    if ($check instanceof mysqli_result) {
        $row = $check->fetch_assoc();
        $check->free();
        if ($row && (string)$row['setting_value'] === (string)CATALOG_SCHEMA_VERSION) {
            return;
        }
    }

    $charset = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci';

    // --- Upgrade path: rename the old shop tables in place --------------
    // Done before the CREATEs so an existing install keeps every row
    // (prices, images) instead of silently starting from empty tables.
    catalogRenameLegacyTable($conn, 'shop_product_details', 'retail_product_details');
    catalogRenameLegacyTable($conn, 'shop_product_images',  'retail_product_images');

    // --- Product details (1:1 extension of inv_items) -------------------
    // usage_type answers "who is this item for?":
    //   internal -> store consumables, never rung up on the till
    //   sale     -> sold to customers only
    //   both     -> used in store AND sold to customers
    @$conn->query("CREATE TABLE IF NOT EXISTS `retail_product_details` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `item_id` INT(11) NOT NULL,
        `usage_type` ENUM('internal','sale','both') NOT NULL DEFAULT 'sale',
        `description` TEXT DEFAULT NULL,
        `package_size` VARCHAR(40) DEFAULT NULL,
        `brand` VARCHAR(80) DEFAULT NULL,
        `selling_price` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        `promo_price` DECIMAL(14,2) DEFAULT NULL,
        `promo_active` TINYINT(1) NOT NULL DEFAULT 0,
        `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
        `is_pos_visible` TINYINT(1) NOT NULL DEFAULT 1,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_retail_detail_item` (`item_id`),
        KEY `idx_retail_detail_usage` (`usage_type`, `is_enabled`),
        CONSTRAINT `fk_retail_detail_item` FOREIGN KEY (`item_id`) REFERENCES `inv_items` (`id`) ON DELETE CASCADE
    ) $charset") || error_log('catalog_schema retail_product_details: ' . $conn->error);

    // --- Product images (multiple per product, used by the till grid) ---
    @$conn->query("CREATE TABLE IF NOT EXISTS `retail_product_images` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `item_id` INT(11) NOT NULL,
        `file_path` VARCHAR(255) NOT NULL,
        `sort_order` INT(11) NOT NULL DEFAULT 0,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_retail_img_item` (`item_id`),
        CONSTRAINT `fk_retail_img_item` FOREIGN KEY (`item_id`) REFERENCES `inv_items` (`id`) ON DELETE CASCADE
    ) $charset") || error_log('catalog_schema retail_product_images: ' . $conn->error);

    // --- Guarded column work on renamed legacy tables --------------------
    // A renamed shop_product_details still carries the online-storefront
    // SEO columns and may be missing is_pos_visible / package_size / brand.
    catalogAddColumn($conn, 'retail_product_details', 'is_pos_visible',
        "ADD COLUMN `is_pos_visible` TINYINT(1) NOT NULL DEFAULT 1 AFTER `is_enabled`");
    catalogAddColumn($conn, 'retail_product_details', 'package_size',
        "ADD COLUMN `package_size` VARCHAR(40) DEFAULT NULL AFTER `description`");
    catalogAddColumn($conn, 'retail_product_details', 'brand',
        "ADD COLUMN `brand` VARCHAR(80) DEFAULT NULL AFTER `package_size`");

    // SEO columns belonged to the public storefront that no longer exists.
    catalogDropColumn($conn, 'retail_product_details', 'slug');
    catalogDropColumn($conn, 'retail_product_details', 'meta_title');
    catalogDropColumn($conn, 'retail_product_details', 'meta_description');

    // A laundry install defaulted new products to 'internal'. In a shop,
    // the sensible default is 'sale' - existing rows keep their value.
    @$conn->query("ALTER TABLE `retail_product_details` ALTER `usage_type` SET DEFAULT 'sale'");

    // --- Department registry (v3) ---------------------------------------
    @$conn->query("CREATE TABLE IF NOT EXISTS `retail_departments` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `dept_key` VARCHAR(32) NOT NULL,
        `name` VARCHAR(60) NOT NULL,
        `icon` VARCHAR(40) NOT NULL DEFAULT 'fa-boxes-stacked',
        `colour` VARCHAR(9) NOT NULL DEFAULT '#8b9aa2',
        `sort_order` INT(11) NOT NULL DEFAULT 0,
        `is_active` TINYINT(1) NOT NULL DEFAULT 1,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_dept_key` (`dept_key`),
        KEY `idx_dept_active` (`is_active`, `sort_order`)
    ) $charset") || error_log('catalog_schema retail_departments: ' . $conn->error);

    catalogSeedDepartmentRegistry($conn);
    catalogWidenDepartmentColumns($conn);
    unset($GLOBALS['__catalog_dept_cache']);

    // Mark installed.
    $stmt = $conn->prepare("INSERT INTO inv_settings (setting_key, setting_value) VALUES ('catalog_schema_version', ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    if ($stmt) {
        $v = (string)CATALOG_SCHEMA_VERSION;
        $stmt->bind_param('s', $v);
        $stmt->execute();
        $stmt->close();
    }
}

/**
 * Fill the department registry the first time it exists.
 *
 * Three cases, and the difference matters:
 *
 *  * An EXISTING installation already has departments stamped on its
 *    products, sales and ledger. Those keys are read straight out of the
 *    data and become registry rows, carrying over the on/off state the
 *    administrator had already chosen in dept_*_enabled. Nothing about
 *    the shop changes; the same three departments simply become editable.
 *
 *  * A FRESH installation gets exactly one neutral department, "General".
 *    The application must not assume it is a supermarket, a pharmacy or
 *    anything else - the setup wizard (admin/setup.php) is where the
 *    administrator describes the business.
 *
 *  * A registry that already has rows is left completely alone.
 */
function catalogSeedDepartmentRegistry(mysqli $conn): void {
    if (!catalogTableExists($conn, 'retail_departments')) { return; }

    $res = @$conn->query("SELECT COUNT(*) AS c FROM retail_departments");
    if (!($res instanceof mysqli_result)) { return; }
    $already = (int)($res->fetch_assoc()['c'] ?? 0);
    $res->free();
    if ($already > 0) { return; }

    // What the data already uses. A department in use must exist in the
    // registry or its history would render without a name.
    $found = [];
    foreach (catalogDepartmentColumns() as $table) {
        if (!catalogTableExists($conn, $table)) { continue; }
        $rows = @$conn->query("SELECT DISTINCT department FROM `$table` WHERE department IS NOT NULL AND department <> ''");
        if ($rows instanceof mysqli_result) {
            while ($r = $rows->fetch_row()) { $found[(string)$r[0]] = true; }
            $rows->free();
        }
    }

    // Departments the administrator had switched on or off under v2.
    $enabled = [];
    $rows = @$conn->query("SELECT setting_key, setting_value FROM inv_settings WHERE setting_key LIKE 'dept\\_%\\_enabled'");
    if ($rows instanceof mysqli_result) {
        while ($r = $rows->fetch_assoc()) {
            if (preg_match('/^dept_(.+)_enabled$/', (string)$r['setting_key'], $m)) {
                $found[$m[1]] = true;
                $enabled[$m[1]] = ((string)$r['setting_value'] === '1');
            }
        }
        $rows->free();
    }

    // How the three original departments looked, so an upgrade is invisible.
    $legacy = [
        'supermarket' => ['Supermarket', 'fa-basket-shopping', '#42c3cf', 1],
        'stationery'  => ['Stationery',  'fa-pen-ruler',       '#f6c23e', 2],
        'general'     => ['General',     'fa-boxes-stacked',   '#8b9aa2', 3],
    ];

    if (!$found) { $found = ['general' => true]; }   // fresh install

    $stmt = $conn->prepare("INSERT INTO retail_departments (dept_key, name, icon, colour, sort_order, is_active)
                            VALUES (?, ?, ?, ?, ?, ?)");
    if (!$stmt) { return; }

    $sort = 100;
    foreach (array_keys($found) as $key) {
        $meta   = $legacy[$key] ?? [ucwords(str_replace(['_', '-'], ' ', $key)), 'fa-boxes-stacked', '#8b9aa2', ++$sort];
        $name   = $meta[0];
        $icon   = $meta[1];
        $colour = $meta[2];
        $order  = (int)$meta[3];
        $active = array_key_exists($key, $enabled) ? ($enabled[$key] ? 1 : 0) : 1;
        $stmt->bind_param('ssssii', $key, $name, $icon, $colour, $order, $active);
        $stmt->execute();
    }
    $stmt->close();
}

/**
 * Widen the seven `department` columns from ENUM to VARCHAR(32).
 *
 * This is what makes administrator-defined departments possible at all:
 * an ENUM can only ever hold the three values compiled into it, so
 * "Plumbing" or "Prescription Medicines" would be silently rejected.
 *
 * It is not a destructive change - every existing value is a string that
 * survives the widening unchanged, and the column keeps its NOT NULL and
 * its default - so it belongs in the installer rather than in a manual
 * migration. Each column is checked first, so this runs once and then
 * costs nothing.
 */
function catalogWidenDepartmentColumns(mysqli $conn): void {
    foreach (catalogDepartmentColumns() as $table) {
        if (!catalogTableExists($conn, $table)) { continue; }

        $res = @$conn->query("SHOW COLUMNS FROM `$table` LIKE 'department'");
        if (!($res instanceof mysqli_result)) { continue; }
        $col = $res->fetch_assoc();
        $res->free();
        if (!$col) { continue; }

        // Already widened by a previous run.
        if (stripos((string)$col['Type'], 'enum') !== 0) { continue; }

        if (!@$conn->query("ALTER TABLE `$table`
                MODIFY `department` VARCHAR(32) NOT NULL DEFAULT '" . catalogDefaultDepartmentKey() . "'")) {
            error_log("catalog_schema: could not widen $table.department - " . $conn->error);
        }
    }
}

// ---------- Small schema helpers ---------------------------------------

/**
 * Rename $from to $to when $from exists and $to does not. Anything else
 * (both present, neither present) is left alone, so this is safe to run
 * on every install state.
 */
function catalogRenameLegacyTable(mysqli $conn, string $from, string $to): void {
    if (!catalogTableExists($conn, $from) || catalogTableExists($conn, $to)) {
        return;
    }
    if (!@$conn->query("RENAME TABLE `$from` TO `$to`")) {
        error_log("catalog_schema: could not rename $from to $to - " . $conn->error);
    }
}

function catalogTableExists(mysqli $conn, string $table): bool {
    $res = @$conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($table) . "'");
    $exists = ($res instanceof mysqli_result) && $res->num_rows > 0;
    if ($res instanceof mysqli_result) { $res->free(); }
    return $exists;
}

function catalogColumnExists(mysqli $conn, string $table, string $column): bool {
    $res = @$conn->query("SHOW COLUMNS FROM `$table` LIKE '" . $conn->real_escape_string($column) . "'");
    $exists = ($res instanceof mysqli_result) && $res->num_rows > 0;
    if ($res instanceof mysqli_result) { $res->free(); }
    return $exists;
}

/** Run an ALTER clause only when $column is missing. */
function catalogAddColumn(mysqli $conn, string $table, string $column, string $alterClause): void {
    if (!catalogTableExists($conn, $table) || catalogColumnExists($conn, $table, $column)) {
        return;
    }
    @$conn->query("ALTER TABLE `$table` $alterClause");
}

/** Drop $column only when it is actually there. */
function catalogDropColumn(mysqli $conn, string $table, string $column): void {
    if (!catalogTableExists($conn, $table) || !catalogColumnExists($conn, $table, $column)) {
        return;
    }
    @$conn->query("ALTER TABLE `$table` DROP COLUMN `$column`");
}
