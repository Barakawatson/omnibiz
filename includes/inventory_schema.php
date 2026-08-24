<?php
// ============================================================
// Inventory & Stock Management - self-installing schema
// ------------------------------------------------------------
// All inventory tables live in their own `inv_` namespace and are
// created with CREATE TABLE IF NOT EXISTS, so this file can be
// included on any inventory page and will bring the schema up to
// date WITHOUT touching, altering or renaming any existing table.
// Existing data (customers, orders, packages, admins, etc.) is
// never modified here - the module only references those tables by
// id, and does so with plain indexed int columns rather than hard
// foreign keys, so a mismatch in a legacy table's engine/collation
// can never break the install on the live database.
//
// A tiny version flag in inv_settings lets us skip the whole
// install on every page load once the tables already exist, so the
// per-request cost is a single cheap SELECT.
// ============================================================

// v2: stock request workflow (inv_stock_requests + lines) - staff
// request stock, the storekeeper approves, and only approved quantities
// are deducted.
// v3: retail conversion. Items and categories gain a `department`
// (supermarket / stationery / general) so the till, reports and the
// accounting ledger can split trade by department. Retail units of
// measure (reams, packs, dozens, cartons...) and retail product
// categories are seeded. The laundry-only usage-template tables are no
// longer created; dropping the existing ones is a destructive change
// and therefore lives in sql/retail_migration.sql, not here.
// v4: purchasing is now double-entry. Receiving goods and paying a
// supplier each get their own record (inv_po_receipts,
// inv_po_payments) carrying the journal entry they produced. Those
// tables exist mainly so each receipt/payment EVENT has its own id:
// the ledger's idempotency key is (source_type, source_id), and a
// purchase order can legitimately be received and paid many times, so
// keying on the PO id alone would refuse the second receipt.
// v5: expired-goods disposal workflow (inv_disposal_requests + lines).
// Three states, not two: a storekeeper requests disposal of currently
// expired stock, an admin/manager APPROVES or rejects (authorization
// only - no stock movement here), and only a later, separate "confirm
// disposal" step actually deducts the approved quantity and posts the
// loss to the ledger, via the existing recordStockMovement('expire', ...)
// path (see executeDisposalRequest() in inventory_functions.php). Kept
// deliberately separate from approval so a paperwork sign-off is never
// the same click as the physical, irreversible act of throwing stock away.
// v6: batch/lot-level expiry (FEFO). `inv_batches` existed but was never
// written to; it gains `status`/`reference_type`/`reference_id` so a
// batch's lifecycle (active -> depleted/expired/disposed) and its origin
// (which PO receipt, or a legacy backfill) can be tracked the same way
// inv_stock_movements already tracks its own reference. A one-time,
// idempotent backfill gives every item that already has stock a single
// "LEGACY-<id>" batch (using its existing expiry_date/average_cost), so
// FEFO deduction has something to allocate against from day one instead
// of a gap across the whole pre-existing catalogue. inv_disposal_request_lines
// gains a nullable batch_id so disposal can target one specific batch
// instead of an item's blended stock (see Milestone 5 of the plan).
// v7: `inv_po_payments.efd_receipt_file`. Recording the payment that
// fully settles a purchase order now requires the supplier's TRA EFD
// receipt to be attached first - see poRecordPayment(). A partial
// payment may still carry one if the cashier has it, but isn't blocked
// without it; only the payment that zeroes the outstanding balance is.
if (!defined('INV_SCHEMA_VERSION')) {
    define('INV_SCHEMA_VERSION', '7');
}

/**
 * Ensure every inventory table exists and default reference data is
 * seeded. Idempotent and safe to call on every request.
 */
function ensureInventorySchema(mysqli $conn): void {
    // Fast path: if the settings table already reports the current
    // schema version, there is nothing to do.
    $needsInstall = true;
    $previousVersion = 0;
    $check = @$conn->query("SELECT setting_value FROM inv_settings WHERE setting_key = 'schema_version' LIMIT 1");
    if ($check instanceof mysqli_result) {
        $row = $check->fetch_assoc();
        if ($row) {
            $previousVersion = (int)$row['setting_value'];
            if ((string)$row['setting_value'] === (string)INV_SCHEMA_VERSION) {
                $needsInstall = false;
            }
        }
        $check->free();
    }
    if (!$needsInstall) {
        return;
    }

    $charset = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci';

    $tables = [];

    // --- Reference data -------------------------------------------------
    $tables[] = "CREATE TABLE IF NOT EXISTS `inv_categories` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `name` VARCHAR(100) NOT NULL,
        `description` VARCHAR(255) DEFAULT NULL,
        `department` VARCHAR(32) NOT NULL DEFAULT 'general',
        `is_active` TINYINT(1) NOT NULL DEFAULT 1,
        `deleted_at` DATETIME DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_inv_category_name` (`name`)
    ) $charset";

    $tables[] = "CREATE TABLE IF NOT EXISTS `inv_units` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `name` VARCHAR(50) NOT NULL,
        `abbreviation` VARCHAR(15) NOT NULL,
        `is_active` TINYINT(1) NOT NULL DEFAULT 1,
        `deleted_at` DATETIME DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_inv_unit_name` (`name`)
    ) $charset";

    // Locations/branches - future multi-branch & warehouse support.
    $tables[] = "CREATE TABLE IF NOT EXISTS `inv_locations` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `name` VARCHAR(100) NOT NULL,
        `type` ENUM('branch','warehouse','storage') NOT NULL DEFAULT 'branch',
        `address` VARCHAR(255) DEFAULT NULL,
        `is_active` TINYINT(1) NOT NULL DEFAULT 1,
        `deleted_at` DATETIME DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_inv_location_name` (`name`)
    ) $charset";

    // --- Suppliers ------------------------------------------------------
    $tables[] = "CREATE TABLE IF NOT EXISTS `inv_suppliers` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `name` VARCHAR(150) NOT NULL,
        `contact_person` VARCHAR(100) DEFAULT NULL,
        `phone` VARCHAR(30) DEFAULT NULL,
        `email` VARCHAR(120) DEFAULT NULL,
        `address` VARCHAR(255) DEFAULT NULL,
        `opening_balance` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        `notes` TEXT DEFAULT NULL,
        `is_active` TINYINT(1) NOT NULL DEFAULT 1,
        `deleted_at` DATETIME DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_inv_supplier_active` (`is_active`, `deleted_at`)
    ) $charset";

    // --- Items ----------------------------------------------------------
    // barcode/sku/location/expiry columns are present from day one so
    // barcode scanning, label printing, multi-location and expiry
    // management can be layered on later without a schema migration.
    $tables[] = "CREATE TABLE IF NOT EXISTS `inv_items` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `sku` VARCHAR(60) DEFAULT NULL,
        `barcode` VARCHAR(80) DEFAULT NULL,
        `name` VARCHAR(150) NOT NULL,
        `department` VARCHAR(32) NOT NULL DEFAULT 'general',
        `category_id` INT(11) DEFAULT NULL,
        `unit_id` INT(11) DEFAULT NULL,
        `supplier_id` INT(11) DEFAULT NULL,
        `location_id` INT(11) DEFAULT NULL,
        `purchase_price` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        `average_cost` DECIMAL(14,4) NOT NULL DEFAULT 0.0000,
        `current_stock` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
        `opening_stock` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
        `min_stock` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
        `reorder_level` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
        `max_stock` DECIMAL(14,3) DEFAULT NULL,
        `is_perishable` TINYINT(1) NOT NULL DEFAULT 0,
        `expiry_date` DATE DEFAULT NULL,
        `last_purchased_at` DATETIME DEFAULT NULL,
        `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
        `deleted_at` DATETIME DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_inv_item_sku` (`sku`),
        KEY `idx_inv_item_category` (`category_id`),
        KEY `idx_inv_item_department` (`department`),
        KEY `idx_inv_item_supplier` (`supplier_id`),
        KEY `idx_inv_item_barcode` (`barcode`),
        KEY `idx_inv_item_active` (`status`, `deleted_at`),
        CONSTRAINT `fk_inv_item_category` FOREIGN KEY (`category_id`) REFERENCES `inv_categories` (`id`) ON DELETE SET NULL,
        CONSTRAINT `fk_inv_item_unit` FOREIGN KEY (`unit_id`) REFERENCES `inv_units` (`id`) ON DELETE SET NULL,
        CONSTRAINT `fk_inv_item_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `inv_suppliers` (`id`) ON DELETE SET NULL,
        CONSTRAINT `fk_inv_item_location` FOREIGN KEY (`location_id`) REFERENCES `inv_locations` (`id`) ON DELETE SET NULL
    ) $charset";

    // Batch / lot tracking - future expansion (expiry per batch).
    $tables[] = "CREATE TABLE IF NOT EXISTS `inv_batches` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `item_id` INT(11) NOT NULL,
        `batch_no` VARCHAR(80) DEFAULT NULL,
        `quantity` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
        `unit_cost` DECIMAL(14,4) NOT NULL DEFAULT 0.0000,
        `expiry_date` DATE DEFAULT NULL,
        `received_at` DATETIME DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_inv_batch_item` (`item_id`),
        CONSTRAINT `fk_inv_batch_item` FOREIGN KEY (`item_id`) REFERENCES `inv_items` (`id`) ON DELETE CASCADE
    ) $charset";

    // --- Stock movements (audit trail) ---------------------------------
    // `user_id`, `order_id` reference admin/orders by id but WITHOUT a
    // hard FK, so legacy-table differences can never fail the install.
    $tables[] = "CREATE TABLE IF NOT EXISTS `inv_stock_movements` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `item_id` INT(11) NOT NULL,
        `batch_id` INT(11) DEFAULT NULL,
        `movement_type` ENUM('receive','issue','transfer','damage','expire','loss','adjust','return','opening') NOT NULL,
        `quantity` DECIMAL(14,3) NOT NULL,
        `qty_before` DECIMAL(14,3) NOT NULL,
        `qty_after` DECIMAL(14,3) NOT NULL,
        `unit_cost` DECIMAL(14,4) NOT NULL DEFAULT 0.0000,
        `from_location_id` INT(11) DEFAULT NULL,
        `to_location_id` INT(11) DEFAULT NULL,
        `reference_type` VARCHAR(40) DEFAULT NULL,
        `reference_id` INT(11) DEFAULT NULL,
        `order_id` INT(11) DEFAULT NULL,
        `reason` VARCHAR(255) DEFAULT NULL,
        `user_id` INT(11) DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_inv_move_item` (`item_id`),
        KEY `idx_inv_move_type` (`movement_type`),
        KEY `idx_inv_move_created` (`created_at`),
        KEY `idx_inv_move_order` (`order_id`),
        CONSTRAINT `fk_inv_move_item` FOREIGN KEY (`item_id`) REFERENCES `inv_items` (`id`) ON DELETE CASCADE
    ) $charset";

    // --- Purchase orders ------------------------------------------------
    $tables[] = "CREATE TABLE IF NOT EXISTS `inv_purchase_orders` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `po_number` VARCHAR(40) NOT NULL,
        `supplier_id` INT(11) DEFAULT NULL,
        `status` ENUM('draft','approved','received','partially_received','cancelled') NOT NULL DEFAULT 'draft',
        `order_date` DATE DEFAULT NULL,
        `expected_date` DATE DEFAULT NULL,
        `received_date` DATE DEFAULT NULL,
        `total_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        `payment_status` ENUM('unpaid','partial','paid') NOT NULL DEFAULT 'unpaid',
        `invoice_file` VARCHAR(255) DEFAULT NULL,
        `notes` TEXT DEFAULT NULL,
        `created_by` INT(11) DEFAULT NULL,
        `approved_by` INT(11) DEFAULT NULL,
        `deleted_at` DATETIME DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_inv_po_number` (`po_number`),
        KEY `idx_inv_po_supplier` (`supplier_id`),
        KEY `idx_inv_po_status` (`status`)
    ) $charset";

    $tables[] = "CREATE TABLE IF NOT EXISTS `inv_purchase_order_lines` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `po_id` INT(11) NOT NULL,
        `item_id` INT(11) NOT NULL,
        `quantity` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
        `unit_price` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        `received_qty` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_inv_poline_po` (`po_id`),
        KEY `idx_inv_poline_item` (`item_id`),
        CONSTRAINT `fk_inv_poline_po` FOREIGN KEY (`po_id`) REFERENCES `inv_purchase_orders` (`id`) ON DELETE CASCADE
    ) $charset";

    // --- Goods receipts (v4) ---------------------------------------------
    // One row per receiving EVENT, not per purchase order: a PO received
    // in three deliveries produces three rows, each with its own ledger
    // entry debiting Inventory Asset and crediting Accounts Payable.
    $tables[] = "CREATE TABLE IF NOT EXISTS `inv_po_receipts` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `po_id` INT(11) NOT NULL,
        `receipt_no` VARCHAR(30) NOT NULL,
        `total_value` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        `line_count` INT(11) NOT NULL DEFAULT 0,
        `journal_id` INT(11) DEFAULT NULL,
        `note` VARCHAR(255) DEFAULT NULL,
        `received_by` INT(11) DEFAULT NULL,
        `received_by_name` VARCHAR(100) DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_inv_receipt_no` (`receipt_no`),
        KEY `idx_inv_receipt_po` (`po_id`),
        CONSTRAINT `fk_inv_receipt_po` FOREIGN KEY (`po_id`) REFERENCES `inv_purchase_orders` (`id`) ON DELETE CASCADE
    ) $charset";

    // --- Supplier payments (v4) -------------------------------------------
    // Settling what was owed for received goods: debits Accounts Payable
    // and credits whichever account actually paid. payment_status on the
    // purchase order is derived from the sum of these, never typed in.
    $tables[] = "CREATE TABLE IF NOT EXISTS `inv_po_payments` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `po_id` INT(11) NOT NULL,
        `payment_no` VARCHAR(30) NOT NULL,
        `amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        `paid_from_account_id` INT(11) NOT NULL,
        `payment_date` DATE NOT NULL,
        `reference` VARCHAR(120) DEFAULT NULL,
        `efd_receipt_file` VARCHAR(255) DEFAULT NULL,
        `journal_id` INT(11) DEFAULT NULL,
        `paid_by` INT(11) DEFAULT NULL,
        `paid_by_name` VARCHAR(100) DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_inv_payment_no` (`payment_no`),
        KEY `idx_inv_payment_po` (`po_id`),
        CONSTRAINT `fk_inv_payment_po` FOREIGN KEY (`po_id`) REFERENCES `inv_purchase_orders` (`id`) ON DELETE CASCADE
    ) $charset";

    // --- Stock requests (v2) ---------------------------------------------
    // Shop floor staff request stock for store use (cleaning supplies,
    // till rolls, display samples); the storekeeper approves, rejects or
    // partially approves. Inventory is deducted ONLY for approved
    // quantities (see reviewStockRequest in inventory_functions.php).
    // Full history is kept forever.
    $tables[] = "CREATE TABLE IF NOT EXISTS `inv_stock_requests` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `request_no` VARCHAR(30) NOT NULL,
        `requested_by` INT(11) DEFAULT NULL,
        `requested_by_name` VARCHAR(100) DEFAULT NULL,
        `purpose` VARCHAR(255) DEFAULT NULL,
        `status` ENUM('pending','approved','partially_approved','rejected') NOT NULL DEFAULT 'pending',
        `review_note` VARCHAR(255) DEFAULT NULL,
        `reviewed_by` INT(11) DEFAULT NULL,
        `reviewed_at` DATETIME DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_inv_request_no` (`request_no`),
        KEY `idx_inv_request_status` (`status`),
        KEY `idx_inv_request_created` (`created_at`)
    ) $charset";

    $tables[] = "CREATE TABLE IF NOT EXISTS `inv_stock_request_lines` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `request_id` INT(11) NOT NULL,
        `item_id` INT(11) NOT NULL,
        `qty_requested` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
        `qty_approved` DECIMAL(14,3) DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_inv_reqline_request` (`request_id`),
        KEY `idx_inv_reqline_item` (`item_id`),
        CONSTRAINT `fk_inv_reqline_request` FOREIGN KEY (`request_id`) REFERENCES `inv_stock_requests` (`id`) ON DELETE CASCADE,
        CONSTRAINT `fk_inv_reqline_item` FOREIGN KEY (`item_id`) REFERENCES `inv_items` (`id`) ON DELETE CASCADE
    ) $charset";

    // --- Expired-goods disposal (v5) --------------------------------------
    // pending -> approved -> disposed, or pending -> rejected (terminal).
    // approveDisposalRequest() only ever touches this table; it never
    // calls recordStockMovement(). executeDisposalRequest() is the only
    // place stock actually moves, and only when status is already
    // 'approved' - see inventory_functions.php.
    $tables[] = "CREATE TABLE IF NOT EXISTS `inv_disposal_requests` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `request_no` VARCHAR(30) NOT NULL,
        `requested_by` INT(11) DEFAULT NULL,
        `requested_by_name` VARCHAR(100) DEFAULT NULL,
        `reason` VARCHAR(255) DEFAULT NULL,
        `status` ENUM('pending','approved','rejected','disposed') NOT NULL DEFAULT 'pending',
        `review_note` VARCHAR(255) DEFAULT NULL,
        `reviewed_by` INT(11) DEFAULT NULL,
        `reviewed_by_name` VARCHAR(100) DEFAULT NULL,
        `reviewed_at` DATETIME DEFAULT NULL,
        `disposed_by` INT(11) DEFAULT NULL,
        `disposed_by_name` VARCHAR(100) DEFAULT NULL,
        `disposed_at` DATETIME DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_inv_disposal_no` (`request_no`),
        KEY `idx_inv_disposal_status` (`status`),
        KEY `idx_inv_disposal_created` (`created_at`)
    ) $charset";

    $tables[] = "CREATE TABLE IF NOT EXISTS `inv_disposal_request_lines` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `request_id` INT(11) NOT NULL,
        `item_id` INT(11) NOT NULL,
        `qty_requested` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
        `qty_approved` DECIMAL(14,3) DEFAULT NULL,
        `expiry_date` DATE DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_inv_disline_request` (`request_id`),
        KEY `idx_inv_disline_item` (`item_id`),
        CONSTRAINT `fk_inv_disline_request` FOREIGN KEY (`request_id`) REFERENCES `inv_disposal_requests` (`id`) ON DELETE CASCADE,
        CONSTRAINT `fk_inv_disline_item` FOREIGN KEY (`item_id`) REFERENCES `inv_items` (`id`) ON DELETE CASCADE
    ) $charset";

    // --- Settings (key/value) ------------------------------------------
    $tables[] = "CREATE TABLE IF NOT EXISTS `inv_settings` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `setting_key` VARCHAR(60) NOT NULL,
        `setting_value` VARCHAR(255) DEFAULT NULL,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_inv_setting_key` (`setting_key`)
    ) $charset";

    // --- General activity / audit log ----------------------------------
    $tables[] = "CREATE TABLE IF NOT EXISTS `inv_audit_log` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `user_id` INT(11) DEFAULT NULL,
        `action` VARCHAR(60) NOT NULL,
        `entity_type` VARCHAR(40) DEFAULT NULL,
        `entity_id` INT(11) DEFAULT NULL,
        `details` TEXT DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_inv_audit_entity` (`entity_type`, `entity_id`),
        KEY `idx_inv_audit_created` (`created_at`)
    ) $charset";

    foreach ($tables as $sql) {
        // Suppress warnings: a failed CREATE IF NOT EXISTS (e.g. table
        // already exists in a slightly different form) must never take
        // down a page. Errors are logged for review instead.
        if (!@$conn->query($sql)) {
            error_log('inventory_schema: ' . $conn->error);
        }
    }

    // --- v2 -> v3: department columns on existing installs ---------------
    // CREATE TABLE IF NOT EXISTS never touches an existing table, so the
    // new columns are added explicitly when missing.
    $deptCol = "VARCHAR(32) NOT NULL DEFAULT 'general'";
    $col = @$conn->query("SHOW COLUMNS FROM `inv_items` LIKE 'department'");
    if ($col instanceof mysqli_result) {
        if ($col->num_rows === 0) {
            @$conn->query("ALTER TABLE `inv_items` ADD COLUMN `department` $deptCol AFTER `name`,
                           ADD KEY `idx_inv_item_department` (`department`)");
        }
        $col->free();
    }
    $col = @$conn->query("SHOW COLUMNS FROM `inv_categories` LIKE 'department'");
    if ($col instanceof mysqli_result) {
        if ($col->num_rows === 0) {
            @$conn->query("ALTER TABLE `inv_categories` ADD COLUMN `department` $deptCol AFTER `description`");
        }
        $col->free();
    }

    // --- v5 -> v6: batch lifecycle + origin columns -----------------------
    $col = @$conn->query("SHOW COLUMNS FROM `inv_batches` LIKE 'status'");
    if ($col instanceof mysqli_result) {
        if ($col->num_rows === 0) {
            @$conn->query("ALTER TABLE `inv_batches`
                ADD COLUMN `status` ENUM('active','depleted','expired','disposed') NOT NULL DEFAULT 'active' AFTER `expiry_date`,
                ADD COLUMN `reference_type` VARCHAR(40) DEFAULT NULL AFTER `status`,
                ADD COLUMN `reference_id` INT(11) DEFAULT NULL AFTER `reference_type`,
                ADD KEY `idx_inv_batch_expiry` (`expiry_date`),
                ADD KEY `idx_inv_batch_item_status` (`item_id`, `status`)");
        }
        $col->free();
    }

    // --- v6 -> v7: EFD receipt attachment on a supplier payment ------------
    $col = @$conn->query("SHOW COLUMNS FROM `inv_po_payments` LIKE 'efd_receipt_file'");
    if ($col instanceof mysqli_result) {
        if ($col->num_rows === 0) {
            @$conn->query("ALTER TABLE `inv_po_payments`
                ADD COLUMN `efd_receipt_file` VARCHAR(255) DEFAULT NULL AFTER `reference`");
        }
        $col->free();
    }

    // --- v5 -> v6: disposal can target one specific batch ------------------
    $col = @$conn->query("SHOW COLUMNS FROM `inv_disposal_request_lines` LIKE 'batch_id'");
    if ($col instanceof mysqli_result) {
        if ($col->num_rows === 0) {
            @$conn->query("ALTER TABLE `inv_disposal_request_lines`
                ADD COLUMN `batch_id` INT(11) DEFAULT NULL AFTER `item_id`,
                ADD KEY `idx_inv_disline_batch` (`batch_id`)");
        }
        $col->free();
    }

    // --- v5 -> v6: legacy backfill - every item with stock gets one batch -
    // FEFO has nothing to allocate against until every already-stocked item
    // has at least one batch row. Purely additive: an item that already has
    // a batch (e.g. received after this version shipped) is left alone.
    @$conn->query("INSERT INTO inv_batches (item_id, batch_no, quantity, unit_cost, expiry_date, status, reference_type, received_at)
        SELECT i.id, CONCAT('LEGACY-', i.id), i.current_stock, i.average_cost, i.expiry_date, 'active', 'legacy_backfill', NOW()
        FROM inv_items i
        WHERE i.deleted_at IS NULL AND i.current_stock > 0
          AND NOT EXISTS (SELECT 1 FROM inv_batches b WHERE b.item_id = i.id)")
        || error_log('inventory_schema: legacy batch backfill failed - ' . $conn->error);

    // --- Seed reference data (INSERT IGNORE = safe to re-run) ------------
    // NO categories are seeded here, deliberately.
    //
    // This application is a general retail system, not a supermarket
    // system: what a shop sells is the administrator's decision, made in
    // the setup wizard (admin/setup.php) by picking a business type
    // template or by building the structure by hand. Seeding "Bakery" and
    // "Pens & Pencils" into every new install would hardcode one kind of
    // business into the core, which is exactly what must not happen.
    //
    // Installations created before this change keep the categories they
    // already have - nothing is removed here.

    // Retail units of measure - pieces, packs, reams, boxes and weight.
    $units = [
        ['Pieces','pcs'], ['Packs','pack'], ['Reams','ream'], ['Boxes','box'],
        ['Cartons','ctn'], ['Dozens','dz'], ['Bundles','bdl'], ['Sets','set'],
        ['Bottles','btl'], ['Sachets','sct'], ['Rolls','roll'], ['Pairs','pr'],
        ['Kilograms','kg'], ['Grams','g'], ['Liters','L'], ['Milliliters','ml'],
    ];
    $unitStmt = $conn->prepare("INSERT IGNORE INTO inv_units (name, abbreviation) VALUES (?, ?)");
    if ($unitStmt) {
        foreach ($units as $u) { $unitStmt->bind_param('ss', $u[0], $u[1]); $unitStmt->execute(); }
        $unitStmt->close();
    }

    // Default outlet/location.
    @$conn->query("INSERT IGNORE INTO inv_locations (id, name, type, address) VALUES (1, 'Main Store', 'branch', NULL)");

    // Default settings.
    $settings = [
        ['currency', 'Tsh'],
        ['expiry_alert_days', '30'],
        ['default_location_id', '1'],
        ['schema_version', (string)INV_SCHEMA_VERSION],
    ];
    $setStmt = $conn->prepare("INSERT INTO inv_settings (setting_key, setting_value) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_value = IF(setting_key = 'schema_version', VALUES(setting_value), setting_value)");
    if ($setStmt) {
        foreach ($settings as $s) { $setStmt->bind_param('ss', $s[0], $s[1]); $setStmt->execute(); }
        $setStmt->close();
    }

    // --- v2 -> v3: retire the laundry auto-deduction switches ------------
    // Stock now leaves only through a till sale or an approved stock
    // request; there is no order-completion hook left to configure.
    if ($previousVersion < 3) {
        @$conn->query("DELETE FROM inv_settings WHERE setting_key IN ('auto_deduction_enabled','deduction_trigger_stage')");
    }
}
