<?php
// ============================================================
// Point of Sale (POS) - self-installing schema
// ------------------------------------------------------------
// Follows the same pattern as inventory_schema.php /
// catalog_schema.php: CREATE TABLE IF NOT EXISTS + guarded
// ALTERs, version-flagged in inv_settings, so including this file
// on any POS page brings the schema up to date without touching
// existing data.
//
// POS does NOT duplicate products or stock: it sells the same
// inv_items rows the catalog and inventory modules use, and every
// sale deducts stock through recordStockMovement()'s audit trail
// and posts to the ledger through accPostSale().
// ============================================================

// v1: sales_transactions + lines + held sales.
// v2: retail conversion - terminals/outlets (pos_terminals), a
// terminal_id and department stamp on every sale, and cost/profit
// snapshots on the header so a Z-report never has to recompute
// margin from items that may since have been edited.
// v3: split tender. A sale can now be settled with more than one
// payment method (cash + mobile, mobile + bank, ...), so each tender
// gets its own row in `sales_payments`. sales_transactions keeps its
// `payment_method` column - it records the single method, or 'split'
// when several were used - so existing reads never break.
// v4: batch/FEFO attribution (sales_transaction_item_batches). One sale
// line can legitimately be fulfilled by more than one batch when the
// oldest active batch doesn't fully cover it, so this is its own table
// rather than a single batch_id on sales_transaction_items - and it is
// what lets a void re-credit the exact batches a sale actually consumed.
// Lives here, not in inventory_schema.php, because it references
// sales_transaction_items, which ensureInventorySchema() runs before
// this file's installer does (see posBoot()) - the FK would fail on a
// fresh install if this table were created before its own target exists.
// v5: institutional customer details snapshotted onto the sale
// (customer_tin/address/email). A receipt must render what was true at
// sale time, so these are copied at checkout rather than joined from
// the customer table - a later edit to the customer record must never
// rewrite an old receipt.
// v6: cancelled_carts - a cart that never became a sale (Clear Cart
// emptying it, or a held sale discarded) is otherwise invisible to
// everyone but the cashier who saw it happen. Every cancellation now
// requires a reason and leaves a permanent, admin-visible row, covering
// both the live in-progress cart and a discarded held sale (the same
// gap one step removed - posDeleteHeldSale() used to be a silent hard
// delete with no trace at all).
// v7: `cancelled_carts.held_since`. For a discarded HELD sale, the
// moment that actually matters to a manager reviewing the audit report
// is when the sale was first parked, not when it was later discarded -
// two sales cancelled back-to-back look identical otherwise, even
// though one sat held for a day and the other for a minute. Captured
// from pos_held_sales.created_at at cancellation time, since nothing
// else remembers it once that row is deleted.
// v8: session-locking columns on `pos_terminals` (locked_by_user_id,
// locked_by_username, locked_at, last_activity_at) so only one cashier
// can hold a given till at a time. Deliberately separate from
// `is_active`, which already means "enabled/deactivated" - a lock is a
// live session, not a configuration toggle. `terminal_lock_timeout_minutes`
// (in inv_settings) is how stale a lock has to get before another
// cashier's claim can take it over - one setting, read everywhere a
// staleness check happens, never a literal repeated in code.
// v9: `pos_held_sales` gets a real lifecycle instead of "row exists =
// held, row gone = anything else". `status` (held/stale/expired/
// orphaned/resumed/completed/cancelled) means a held sale is never
// hard-deleted again - every terminal state is an UPDATE, so the
// history survives. `resulting_txn_id` links a completed held sale
// forward to the sale it became (and back again, if that sale is later
// voided). `resumed_at`/`orphaned_at` and the three `recovered_*`
// columns are the timestamps a manager reviewing a stuck cart actually
// needs on the row itself, without joining inv_audit_log for them.
// `held_sale_stale_minutes` / `held_sale_expiry_minutes` (in
// inv_settings) are the two configurable thresholds, same pattern as
// `terminal_lock_timeout_minutes`.
if (!defined('POS_SCHEMA_VERSION')) {
    define('POS_SCHEMA_VERSION', '9');
}

/** Payment methods a sale can be settled with, and where each lands. */
function posPaymentMethods(): array {
    return [
        'cash'       => ['label' => 'Cash',              'icon' => 'fa-money-bill-wave', 'account' => '1000'],
        'lipa_namba' => ['label' => 'Lipa Namba/Mobile', 'icon' => 'fa-mobile-screen',   'account' => '1010'],
        'bank'       => ['label' => 'Bank transfer',     'icon' => 'fa-building-columns','account' => '1020'],
        'card'       => ['label' => 'Card',              'icon' => 'fa-credit-card',     'account' => '1020'],
    ];
}

/** Human label for a stored method, including the 'split' marker. */
function posPaymentLabel(string $method): string {
    if ($method === 'split') { return 'Split payment'; }
    return posPaymentMethods()[$method]['label'] ?? ucfirst(str_replace('_', ' ', $method));
}

function ensurePosSchema(mysqli $conn): void {
    // Fast path - already installed.
    $needsInstall = true;
    $check = @$conn->query("SELECT setting_value FROM inv_settings WHERE setting_key = 'pos_schema_version' LIMIT 1");
    if ($check instanceof mysqli_result) {
        $row = $check->fetch_assoc();
        if ($row && (string)$row['setting_value'] === (string)POS_SCHEMA_VERSION) {
            $needsInstall = false;
        }
        $check->free();
    }
    if (!$needsInstall) {
        return;
    }

    $charset = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci';

    // --- Terminals / outlets ---------------------------------------------
    // A till belongs to a department, so the supermarket counter and the
    // stationery counter can each default to their own product mix while
    // still being able to sell anything in the shop.
    @$conn->query("CREATE TABLE IF NOT EXISTS `pos_terminals` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `name` VARCHAR(80) NOT NULL,
        `code` VARCHAR(20) NOT NULL,
        `department` VARCHAR(32) NOT NULL DEFAULT 'general',
        `location_id` INT(11) DEFAULT NULL,
        `is_active` TINYINT(1) NOT NULL DEFAULT 1,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_pos_terminal_code` (`code`),
        KEY `idx_pos_terminal_active` (`is_active`)
    ) $charset") || error_log('pos_schema pos_terminals: ' . $conn->error);

    // --- Sales transactions (one row per completed POS sale) ----------
    // customer_type: 'cash' = anonymous walk-in, 'registered' = linked
    // to the existing customer table by phone (same identity rule as
    // the rest of the system).
    @$conn->query("CREATE TABLE IF NOT EXISTS `sales_transactions` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `receipt_no` VARCHAR(30) NOT NULL,
        `terminal_id` INT(11) DEFAULT NULL,
        `department` VARCHAR(32) NOT NULL DEFAULT 'general',
        `cashier_id` INT(11) DEFAULT NULL,
        `cashier_name` VARCHAR(100) DEFAULT NULL,
        `customer_type` ENUM('cash','registered') NOT NULL DEFAULT 'cash',
        `customer_id` INT(11) DEFAULT NULL,
        `customer_name` VARCHAR(150) DEFAULT NULL,
        `customer_phone` VARCHAR(30) DEFAULT NULL,
        `customer_tin` VARCHAR(30) DEFAULT NULL,
        `customer_address` VARCHAR(255) DEFAULT NULL,
        `customer_email` VARCHAR(120) DEFAULT NULL,
        `subtotal` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        `discount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        `tax_rate` DECIMAL(6,3) NOT NULL DEFAULT 0.000,
        `tax_amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        `total` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        `total_cost` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        `gross_profit` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        `amount_paid` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        `change_due` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        `payment_method` VARCHAR(30) NOT NULL DEFAULT 'cash',
        `status` ENUM('completed','voided') NOT NULL DEFAULT 'completed',
        `void_reason` VARCHAR(255) DEFAULT NULL,
        `voided_by` INT(11) DEFAULT NULL,
        `voided_at` DATETIME DEFAULT NULL,
        `note` VARCHAR(255) DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_sales_receipt_no` (`receipt_no`),
        KEY `idx_sales_created` (`created_at`),
        KEY `idx_sales_cashier` (`cashier_id`),
        KEY `idx_sales_status` (`status`),
        KEY `idx_sales_customer` (`customer_id`),
        KEY `idx_sales_terminal` (`terminal_id`),
        KEY `idx_sales_department` (`department`)
    ) $charset") || error_log('pos_schema sales_transactions: ' . $conn->error);

    // --- Sale lines ----------------------------------------------------
    // item_name / unit_price / unit_cost are snapshots so later price or
    // item edits never rewrite sales history (profit stays accurate).
    @$conn->query("CREATE TABLE IF NOT EXISTS `sales_transaction_items` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `transaction_id` INT(11) NOT NULL,
        `item_id` INT(11) NOT NULL,
        `item_name` VARCHAR(150) NOT NULL,
        `barcode` VARCHAR(80) DEFAULT NULL,
        `department` VARCHAR(32) NOT NULL DEFAULT 'general',
        `quantity` DECIMAL(14,3) NOT NULL DEFAULT 1.000,
        `unit_price` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        `unit_cost` DECIMAL(14,4) NOT NULL DEFAULT 0.0000,
        `line_discount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        `line_total` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_saleline_txn` (`transaction_id`),
        KEY `idx_saleline_item` (`item_id`),
        CONSTRAINT `fk_saleline_txn` FOREIGN KEY (`transaction_id`) REFERENCES `sales_transactions` (`id`) ON DELETE CASCADE
    ) $charset") || error_log('pos_schema sales_transaction_items: ' . $conn->error);

    // --- Batch attribution per sale line (v4) ---------------------------
    // Which batch(es) a sale line was actually deducted from, and how much
    // of each - needed because FEFO can split one line across more than
    // one batch. posVoidSale() reads this to re-credit the exact batches;
    // a sale with no rows here (everything before this version, or a line
    // that fell back to the item's blended average_cost) is simply
    // credited back the old way.
    @$conn->query("CREATE TABLE IF NOT EXISTS `sales_transaction_item_batches` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `sale_item_id` INT(11) NOT NULL,
        `batch_id` INT(11) NOT NULL,
        `qty` DECIMAL(14,3) NOT NULL DEFAULT 0.000,
        `unit_cost` DECIMAL(14,4) NOT NULL DEFAULT 0.0000,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_stib_sale_item` (`sale_item_id`),
        KEY `idx_stib_batch` (`batch_id`),
        CONSTRAINT `fk_stib_sale_item` FOREIGN KEY (`sale_item_id`) REFERENCES `sales_transaction_items` (`id`) ON DELETE CASCADE,
        CONSTRAINT `fk_stib_batch` FOREIGN KEY (`batch_id`) REFERENCES `inv_batches` (`id`) ON DELETE CASCADE
    ) $charset") || error_log('pos_schema sales_transaction_item_batches: ' . $conn->error);

    // --- Payments / tenders (v3) ---------------------------------------
    // One row per tender, so a sale settled with cash AND mobile money
    // records both. `amount` is what the customer handed over for that
    // method; any change given back is held on the transaction header,
    // not netted off here, so the tender history stays literal.
    @$conn->query("CREATE TABLE IF NOT EXISTS `sales_payments` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `transaction_id` INT(11) NOT NULL,
        `method` VARCHAR(30) NOT NULL,
        `amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        `reference` VARCHAR(120) DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_salespay_txn` (`transaction_id`),
        KEY `idx_salespay_method` (`method`),
        CONSTRAINT `fk_salespay_txn` FOREIGN KEY (`transaction_id`) REFERENCES `sales_transactions` (`id`) ON DELETE CASCADE
    ) $charset") || error_log('pos_schema sales_payments: ' . $conn->error);

    // --- Held (parked) sales ------------------------------------------
    // Cart JSON parked by a cashier so the till can serve the next
    // customer and resume this one later. Nothing is deducted while held.
    @$conn->query("CREATE TABLE IF NOT EXISTS `pos_held_sales` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `label` VARCHAR(60) DEFAULT NULL,
        `terminal_id` INT(11) DEFAULT NULL,
        `cashier_id` INT(11) DEFAULT NULL,
        `cashier_name` VARCHAR(100) DEFAULT NULL,
        `cart_json` MEDIUMTEXT NOT NULL,
        `item_count` INT(11) NOT NULL DEFAULT 0,
        `total_estimate` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_held_cashier` (`cashier_id`)
    ) $charset") || error_log('pos_schema pos_held_sales: ' . $conn->error);

    // --- Cancelled carts (v6) -------------------------------------------
    // A cart that never became a sale, discarded either live (Clear Cart)
    // or as a parked held sale, with the reason the cashier gave. This is
    // the only record such a cart ever existed - itemized_cart_json is
    // the same shape pos_held_sales.cart_json already uses, kept as a
    // snapshot rather than a join because the cart itself is gone.
    @$conn->query("CREATE TABLE IF NOT EXISTS `cancelled_carts` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `cashier_id` INT(11) DEFAULT NULL,
        `cashier_name` VARCHAR(100) DEFAULT NULL,
        `terminal_id` INT(11) DEFAULT NULL,
        `source` ENUM('live_cart','held_sale') NOT NULL DEFAULT 'live_cart',
        `held_sale_id` INT(11) DEFAULT NULL,
        `held_since` DATETIME DEFAULT NULL,
        `reason_code` VARCHAR(40) NOT NULL,
        `reason_detail` VARCHAR(255) DEFAULT NULL,
        `itemized_cart_json` MEDIUMTEXT NOT NULL,
        `item_count` INT(11) NOT NULL DEFAULT 0,
        `total_value` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_cancelled_cashier` (`cashier_id`),
        KEY `idx_cancelled_created` (`created_at`)
    ) $charset") || error_log('pos_schema cancelled_carts: ' . $conn->error);

    // --- v1 -> v2 column additions (guarded) ----------------------------
    $deptCol = "VARCHAR(32) NOT NULL DEFAULT 'general'";
    posAddColumn($conn, 'sales_transactions', 'terminal_id',  "ADD COLUMN `terminal_id` INT(11) DEFAULT NULL AFTER `receipt_no`");
    posAddColumn($conn, 'sales_transactions', 'department',   "ADD COLUMN `department` $deptCol AFTER `terminal_id`");
    posAddColumn($conn, 'sales_transactions', 'total_cost',   "ADD COLUMN `total_cost` DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER `total`");
    posAddColumn($conn, 'sales_transactions', 'gross_profit', "ADD COLUMN `gross_profit` DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER `total_cost`");
    posAddColumn($conn, 'sales_transaction_items', 'department', "ADD COLUMN `department` $deptCol AFTER `barcode`");
    posAddColumn($conn, 'pos_held_sales', 'terminal_id', "ADD COLUMN `terminal_id` INT(11) DEFAULT NULL AFTER `label`");

    // --- v2 -> v3 backfill: one tender row per existing sale ------------
    // Sales recorded before split tender existed have a single method on
    // the header. Copying them into sales_payments means every report can
    // read payments from one place instead of branching on schema age.
    // Guarded by NOT EXISTS so re-running never duplicates a tender.
    @$conn->query("INSERT INTO sales_payments (transaction_id, method, amount, created_at)
        SELECT t.id, t.payment_method, t.amount_paid, t.created_at
        FROM sales_transactions t
        WHERE t.payment_method <> 'split'
          AND NOT EXISTS (SELECT 1 FROM sales_payments p WHERE p.transaction_id = t.id)");

    // Backfill profit on sales recorded before v2, so historical
    // Z-reports and margin figures aren't all zero.
    @$conn->query("UPDATE sales_transactions t
        SET t.total_cost = COALESCE((SELECT SUM(li.quantity * li.unit_cost)
                                     FROM sales_transaction_items li
                                     WHERE li.transaction_id = t.id), 0)
        WHERE t.total_cost = 0");
    @$conn->query("UPDATE sales_transactions
        SET gross_profit = ROUND(total - tax_amount - total_cost, 2)
        WHERE gross_profit = 0");

    // inv_items.barcode already exists (created by inventory_schema) but
    // only as a plain index. Promote it to UNIQUE so a scan can never be
    // ambiguous. Blank barcodes are stored as NULL, and MySQL allows any
    // number of NULLs in a unique index, so unbarcoded items are fine.
    @$conn->query("UPDATE `inv_items` SET `barcode` = NULL WHERE `barcode` = ''");
    $hasUnique = false;
    $idx = @$conn->query("SHOW INDEX FROM `inv_items` WHERE Column_name = 'barcode'");
    if ($idx instanceof mysqli_result) {
        while ($r = $idx->fetch_assoc()) {
            if ((int)$r['Non_unique'] === 0) { $hasUnique = true; }
        }
        $idx->free();
    }
    if (!$hasUnique) {
        // Fails harmlessly (and is logged) if duplicates already exist;
        // the duplicate report on the barcode station helps clean them.
        if (!@$conn->query("ALTER TABLE `inv_items` ADD UNIQUE KEY `uq_inv_item_barcode` (`barcode`)")) {
            error_log('pos_schema: could not add unique barcode index - ' . $conn->error);
        }
    }

    // --- v4 -> v5: institutional customer snapshot columns ----------------
    $col = @$conn->query("SHOW COLUMNS FROM `sales_transactions` LIKE 'customer_tin'");
    if ($col instanceof mysqli_result) {
        if ($col->num_rows === 0) {
            @$conn->query("ALTER TABLE `sales_transactions`
                ADD COLUMN `customer_tin` VARCHAR(30) DEFAULT NULL AFTER `customer_phone`,
                ADD COLUMN `customer_address` VARCHAR(255) DEFAULT NULL AFTER `customer_tin`,
                ADD COLUMN `customer_email` VARCHAR(120) DEFAULT NULL AFTER `customer_address`");
        }
        $col->free();
    }

    // --- v6 -> v7: when a discarded held sale was first held --------------
    posAddColumn($conn, 'cancelled_carts', 'held_since', "ADD COLUMN `held_since` DATETIME DEFAULT NULL AFTER `held_sale_id`");

    // --- v7 -> v8: session-locking columns -------------------------------
    // Nullable and additive - a terminal with no lock is simply free, the
    // same as every terminal was before this version existed.
    posAddColumn($conn, 'pos_terminals', 'locked_by_user_id',
        "ADD COLUMN `locked_by_user_id` INT(11) DEFAULT NULL AFTER `is_active`,
         ADD KEY `idx_pos_terminal_locked_by` (`locked_by_user_id`)");
    posAddColumn($conn, 'pos_terminals', 'locked_by_username', "ADD COLUMN `locked_by_username` VARCHAR(100) DEFAULT NULL AFTER `locked_by_user_id`");
    posAddColumn($conn, 'pos_terminals', 'locked_at', "ADD COLUMN `locked_at` DATETIME DEFAULT NULL AFTER `locked_by_username`");
    posAddColumn($conn, 'pos_terminals', 'last_activity_at', "ADD COLUMN `last_activity_at` DATETIME DEFAULT NULL AFTER `locked_at`");

    // --- v8 -> v9: pos_held_sales lifecycle --------------------------------
    // `status` DEFAULT 'held' means every row that already exists at
    // upgrade time is correctly read as still-held (which is exactly what
    // it was under the old "row exists = held" rule) - no backfill needed.
    posAddColumn($conn, 'pos_held_sales', 'status',
        "ADD COLUMN `status` VARCHAR(20) NOT NULL DEFAULT 'held' AFTER `cashier_name`,
         ADD KEY `idx_held_status` (`status`)");
    posAddColumn($conn, 'pos_held_sales', 'resumed_at', "ADD COLUMN `resumed_at` DATETIME DEFAULT NULL AFTER `status`");
    posAddColumn($conn, 'pos_held_sales', 'orphaned_at', "ADD COLUMN `orphaned_at` DATETIME DEFAULT NULL AFTER `resumed_at`");
    posAddColumn($conn, 'pos_held_sales', 'resulting_txn_id', "ADD COLUMN `resulting_txn_id` INT(11) DEFAULT NULL AFTER `orphaned_at`");
    posAddColumn($conn, 'pos_held_sales', 'recovered_by', "ADD COLUMN `recovered_by` INT(11) DEFAULT NULL AFTER `resulting_txn_id`");
    posAddColumn($conn, 'pos_held_sales', 'recovery_reason', "ADD COLUMN `recovery_reason` VARCHAR(255) DEFAULT NULL AFTER `recovered_by`");
    posAddColumn($conn, 'pos_held_sales', 'recovered_at', "ADD COLUMN `recovered_at` DATETIME DEFAULT NULL AFTER `recovery_reason`");

    // --- Seed one till ---------------------------------------------------
    // A single neutral till, so a fresh install can ring up a sale before
    // anything is configured. It is deliberately NOT named after a trade -
    // the departments a shop has are the administrator's decision, and a
    // till can be pointed at one of them later on the terminals screen.
    //
    // Only seeded when there are no tills at all, so an existing shop's
    // counters are never touched.
    $have = @$conn->query("SELECT COUNT(*) AS c FROM pos_terminals");
    $haveCount = ($have instanceof mysqli_result) ? (int)($have->fetch_assoc()['c'] ?? 0) : 1;
    if ($have instanceof mysqli_result) { $have->free(); }
    if ($haveCount === 0) {
        $stmt = $conn->prepare("INSERT IGNORE INTO pos_terminals (name, code, department, location_id)
                                VALUES ('Main Till', 'TILL-01', ?, 1)");
        if ($stmt) {
            $dept = 'general';
            $res  = @$conn->query("SELECT dept_key FROM retail_departments WHERE is_active = 1 ORDER BY sort_order, name LIMIT 1");
            if ($res instanceof mysqli_result) {
                $row = $res->fetch_assoc();
                if ($row) { $dept = (string)$row['dept_key']; }
                $res->free();
            }
            $stmt->bind_param('s', $dept);
            $stmt->execute();
            $stmt->close();
        }
    }

    // --- Default settings ----------------------------------------------
    $settings = [
        ['pos_tax_rate', '0'],            // percent; 0 = no tax (current pricing)
        ['pos_tax_inclusive', '0'],       // 1 = prices already include tax
        ['pos_receipt_footer', 'Thank you for shopping with us!'],
        ['terminal_lock_timeout_minutes', '20'], // no heartbeat for longer than this = stale, reclaimable
        ['held_sale_stale_minutes', '30'],  // still in the active queue, but flagged as ageing
        ['held_sale_expiry_minutes', '120'], // removed from the active queue; manager recovery only from here
        ['pos_schema_version', (string)POS_SCHEMA_VERSION],
    ];
    $stmt = $conn->prepare("INSERT INTO inv_settings (setting_key, setting_value) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_value = IF(setting_key = 'pos_schema_version', VALUES(setting_value), setting_value)");
    if ($stmt) {
        foreach ($settings as $s) { $stmt->bind_param('ss', $s[0], $s[1]); $stmt->execute(); }
        $stmt->close();
    }

    // The old laundry receipt footer named a business that no longer
    // trades under that name - replace it, but leave any other custom
    // footer the shop has since set.
    @$conn->query("UPDATE inv_settings
        SET setting_value = 'Thank you for shopping with us!'
        WHERE setting_key = 'pos_receipt_footer'
          AND setting_value LIKE '%Mira Cleaning%'");
}

/** Add a column only when it is missing. */
function posAddColumn(mysqli $conn, string $table, string $column, string $alterClause): void {
    $res = @$conn->query("SHOW COLUMNS FROM `$table` LIKE '" . $conn->real_escape_string($column) . "'");
    if (!($res instanceof mysqli_result)) { return; }
    $missing = $res->num_rows === 0;
    $res->free();
    if ($missing) {
        @$conn->query("ALTER TABLE `$table` $alterClause");
    }
}
