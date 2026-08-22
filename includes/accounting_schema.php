<?php
// ============================================================
// Accounting & Financial Ledger - self-installing schema
// ------------------------------------------------------------
// Double-entry bookkeeping for the retail business, following the
// same self-installing pattern as the inventory/catalog/POS
// modules: CREATE TABLE IF NOT EXISTS plus guarded ALTERs,
// version-flagged in inv_settings.
//
// Design notes
// ------------
// * Every financial event is a JOURNAL ENTRY (acc_journal) made of
//   two or more LINES (acc_journal_lines). Lines carry a debit and
//   a credit column; an entry may only be posted when total debits
//   equal total credits (enforced in accounting_functions.php).
// * Account balances are NEVER stored as a running total. They are
//   summed from the ledger lines on demand, so the books cannot
//   drift out of step with their own history.
// * A POS sale posts automatically at checkout (see
//   postSaleToLedger). The `source_type` + `source_id` pair on the
//   journal header makes that posting idempotent: a sale can only
//   ever be posted once.
// * Daily close (Z-report) snapshots the day's takings so an
//   already-closed day reads the same next year as it did on the
//   night, even if a later correction touches the ledger.
// ============================================================

// v2: stock movements outside the till and purchase orders now post
// automatically too (damage, loss, expiry, count adjustments, internal
// issues). That needs somewhere for consumed stock to land, so account
// 5200 "Store Consumables Used" is added.
// v3: split tender. A sale may settle across cash, mobile money and
// bank, so the daily close records a bank column alongside the others
// and 1020 Bank Account becomes a system account the till posts to.
// v4: departments are defined by the administrator, so a day's takings
// can no longer be described by three fixed columns. acc_daily_close
// keeps those columns - a closed day is immutable and its stored figures
// must never move - and gains a child table holding one row per
// department, however many the shop has.
if (!defined('ACCOUNTING_SCHEMA_VERSION')) {
    define('ACCOUNTING_SCHEMA_VERSION', '4');
}

/** The five account types, and which side increases them. */
function accAccountTypes(): array {
    return [
        'asset'     => ['label' => 'Asset',     'normal' => 'debit'],
        'liability' => ['label' => 'Liability', 'normal' => 'credit'],
        'equity'    => ['label' => 'Equity',    'normal' => 'credit'],
        'revenue'   => ['label' => 'Revenue',   'normal' => 'credit'],
        'expense'   => ['label' => 'Expense',   'normal' => 'debit'],
    ];
}

/**
 * Account codes the system posts to automatically. Referenced by
 * name everywhere else so a renamed account never breaks a posting.
 */
function accSystemAccounts(): array {
    return [
        'cash'            => '1000', // Cash on Hand (till)
        'mobile_money'    => '1010', // Mobile Money (Lipa Namba)
        'bank'            => '1020', // Bank Account (transfers, card settlement)
        'receivable'      => '1100', // Accounts Receivable
        'inventory'       => '1200', // Inventory Asset
        'payable'         => '2000', // Accounts Payable
        'capital'         => '3000', // Owner's Capital
        'sales'           => '4000', // Sales Revenue
        'sales_returns'   => '4100', // Sales Returns & Allowances (contra)
        'cogs'            => '5000', // Cost of Goods Sold
        'shrinkage'       => '5100', // Stock Loss & Shrinkage
        'consumables'     => '5200', // Store Consumables Used
        'other_income'    => '4900', // Other Income
        'tax_payable'     => '2100', // Taxes Payable
    ];
}

function ensureAccountingSchema(mysqli $conn): void {
    // Fast path - already installed.
    $check = @$conn->query("SELECT setting_value FROM inv_settings WHERE setting_key = 'accounting_schema_version' LIMIT 1");
    if ($check instanceof mysqli_result) {
        $row = $check->fetch_assoc();
        $check->free();
        if ($row && (string)$row['setting_value'] === (string)ACCOUNTING_SCHEMA_VERSION) {
            return;
        }
    }

    $charset = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci';
    $tables = [];

    // --- Chart of accounts ------------------------------------------------
    // is_system marks accounts the automatic postings rely on. Those can
    // be renamed but never deleted, or a checkout would have nowhere to post.
    $tables[] = "CREATE TABLE IF NOT EXISTS `acc_accounts` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `code` VARCHAR(20) NOT NULL,
        `name` VARCHAR(120) NOT NULL,
        `type` ENUM('asset','liability','equity','revenue','expense') NOT NULL,
        `parent_id` INT(11) DEFAULT NULL,
        `description` VARCHAR(255) DEFAULT NULL,
        `is_system` TINYINT(1) NOT NULL DEFAULT 0,
        `is_active` TINYINT(1) NOT NULL DEFAULT 1,
        `deleted_at` DATETIME DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_acc_account_code` (`code`),
        KEY `idx_acc_account_type` (`type`, `is_active`)
    ) $charset";

    // --- Journal (entry header) -------------------------------------------
    // source_type/source_id link an entry back to what caused it
    // ('pos_sale', 'pos_void', 'expense', 'purchase_order', 'manual').
    // The unique key on that pair is what makes automatic posting
    // idempotent - a second attempt to post the same sale fails loudly
    // instead of double-counting revenue.
    $tables[] = "CREATE TABLE IF NOT EXISTS `acc_journal` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `entry_no` VARCHAR(30) NOT NULL,
        `entry_date` DATE NOT NULL,
        `memo` VARCHAR(255) DEFAULT NULL,
        `source_type` VARCHAR(40) NOT NULL DEFAULT 'manual',
        `source_id` INT(11) DEFAULT NULL,
        `department` VARCHAR(32) NOT NULL DEFAULT 'general',
        `total_debit` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        `total_credit` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        `status` ENUM('posted','reversed') NOT NULL DEFAULT 'posted',
        `reversed_by` INT(11) DEFAULT NULL,
        `reversal_of` INT(11) DEFAULT NULL,
        `created_by` INT(11) DEFAULT NULL,
        `created_by_name` VARCHAR(100) DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_acc_journal_no` (`entry_no`),
        UNIQUE KEY `uq_acc_journal_source` (`source_type`, `source_id`),
        KEY `idx_acc_journal_date` (`entry_date`),
        KEY `idx_acc_journal_status` (`status`)
    ) $charset";

    // --- Journal lines -----------------------------------------------------
    $tables[] = "CREATE TABLE IF NOT EXISTS `acc_journal_lines` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `journal_id` INT(11) NOT NULL,
        `account_id` INT(11) NOT NULL,
        `debit` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        `credit` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        `memo` VARCHAR(255) DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_acc_line_journal` (`journal_id`),
        KEY `idx_acc_line_account` (`account_id`),
        CONSTRAINT `fk_acc_line_journal` FOREIGN KEY (`journal_id`) REFERENCES `acc_journal` (`id`) ON DELETE CASCADE,
        CONSTRAINT `fk_acc_line_account` FOREIGN KEY (`account_id`) REFERENCES `acc_accounts` (`id`)
    ) $charset";

    // --- Expenses ----------------------------------------------------------
    // An expense is a small form over a journal entry: it debits the
    // chosen expense account and credits cash/mobile money/payable.
    // journal_id links to the entry it produced.
    $tables[] = "CREATE TABLE IF NOT EXISTS `acc_expenses` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `reference_no` VARCHAR(30) NOT NULL,
        `expense_date` DATE NOT NULL,
        `account_id` INT(11) NOT NULL,
        `paid_from_account_id` INT(11) NOT NULL,
        `department` VARCHAR(32) NOT NULL DEFAULT 'general',
        `payee` VARCHAR(150) DEFAULT NULL,
        `description` VARCHAR(255) DEFAULT NULL,
        `amount` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        `receipt_file` VARCHAR(255) DEFAULT NULL,
        `journal_id` INT(11) DEFAULT NULL,
        `recorded_by` INT(11) DEFAULT NULL,
        `recorded_by_name` VARCHAR(100) DEFAULT NULL,
        `deleted_at` DATETIME DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_acc_expense_ref` (`reference_no`),
        KEY `idx_acc_expense_date` (`expense_date`),
        KEY `idx_acc_expense_account` (`account_id`)
    ) $charset";

    // --- Daily close / Z-report -------------------------------------------
    // One row per closed business day. The figures are snapshots taken at
    // close time, so a historical Z-report never changes retroactively.
    // counted_cash vs expected_cash gives the over/short figure that
    // matters at the drawer.
    $tables[] = "CREATE TABLE IF NOT EXISTS `acc_daily_close` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `close_date` DATE NOT NULL,
        `opening_float` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        `sales_count` INT(11) NOT NULL DEFAULT 0,
        `voided_count` INT(11) NOT NULL DEFAULT 0,
        `gross_sales` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        `discounts` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        `tax_collected` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        `net_sales` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        `cost_of_sales` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        `gross_profit` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        `cash_sales` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        `mobile_sales` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        `bank_sales` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        `card_sales` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        `supermarket_sales` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        `stationery_sales` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        `general_sales` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        `cash_expenses` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        `expected_cash` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        `counted_cash` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        `variance` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        `notes` VARCHAR(255) DEFAULT NULL,
        `closed_by` INT(11) DEFAULT NULL,
        `closed_by_name` VARCHAR(100) DEFAULT NULL,
        `closed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_acc_close_date` (`close_date`),
        KEY `idx_acc_close_date` (`close_date`)
    ) $charset";

    // One row per department per closed day. The parent's
    // supermarket/stationery/general columns are left in place and still
    // written when those departments exist, so every close recorded
    // before this table existed still reads exactly as it did.
    $tables[] = "CREATE TABLE IF NOT EXISTS `acc_daily_close_departments` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `close_id` INT(11) NOT NULL,
        `dept_key` VARCHAR(32) NOT NULL,
        `dept_name` VARCHAR(60) NOT NULL,
        `sales` DECIMAL(14,2) NOT NULL DEFAULT 0.00,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_close_dept` (`close_id`, `dept_key`),
        CONSTRAINT `fk_close_dept` FOREIGN KEY (`close_id`) REFERENCES `acc_daily_close` (`id`) ON DELETE CASCADE
    ) $charset";

    foreach ($tables as $sql) {
        if (!@$conn->query($sql)) {
            error_log('accounting_schema: ' . $conn->error);
        }
    }

    // --- v2 -> v3: bank column on existing daily closes -----------------
    // CREATE TABLE IF NOT EXISTS leaves an existing table alone, so the
    // new column is added explicitly when missing.
    $col = @$conn->query("SHOW COLUMNS FROM `acc_daily_close` LIKE 'bank_sales'");
    if ($col instanceof mysqli_result) {
        if ($col->num_rows === 0) {
            @$conn->query("ALTER TABLE `acc_daily_close`
                ADD COLUMN `bank_sales` DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER `mobile_sales`");
        }
        $col->free();
    }

    // --- Seed the chart of accounts ---------------------------------------
    // A standard small-retail chart. INSERT IGNORE means an accountant
    // can rename or add accounts freely without this installer undoing
    // their work on the next page load.
    $accounts = [
        // code, name, type, is_system
        ['1000', 'Cash on Hand',                'asset',     1],
        ['1010', 'Mobile Money (Lipa Namba)',   'asset',     1],
        ['1020', 'Bank Account',                'asset',     1],
        ['1100', 'Accounts Receivable',         'asset',     1],
        ['1200', 'Inventory Asset',             'asset',     1],
        ['1500', 'Shop Fittings & Equipment',   'asset',     0],

        ['2000', 'Accounts Payable',            'liability', 1],
        ['2100', 'Taxes Payable',               'liability', 0],
        ['2200', 'Salaries Payable',            'liability', 0],

        ['3000', "Owner's Capital",             'equity',    1],
        ['3100', "Owner's Drawings",            'equity',    0],
        ['3900', 'Retained Earnings',           'equity',    0],

        ['4000', 'Sales Revenue',               'revenue',   1],
        ['4100', 'Sales Returns & Allowances',  'revenue',   1],
        ['4900', 'Other Income',                'revenue',   0],

        ['5000', 'Cost of Goods Sold',          'expense',   1],
        ['5100', 'Stock Loss & Shrinkage',      'expense',   1],
        ['5200', 'Store Consumables Used',      'expense',   1],
        ['6000', 'Rent',                        'expense',   0],
        ['6100', 'Salaries & Wages',            'expense',   0],
        ['6200', 'Electricity & Water',         'expense',   0],
        ['6300', 'Transport & Fuel',            'expense',   0],
        ['6400', 'Packaging & Carrier Bags',    'expense',   0],
        ['6500', 'Cleaning & Consumables',      'expense',   0],
        ['6600', 'Repairs & Maintenance',       'expense',   0],
        ['6700', 'Airtime & Internet',          'expense',   0],
        ['6800', 'Licences & Government Fees',  'expense',   0],
        ['6900', 'Bank & Mobile Money Charges', 'expense',   0],
        ['6950', 'Miscellaneous Expenses',      'expense',   0],
    ];
    $stmt = $conn->prepare("INSERT IGNORE INTO acc_accounts (code, name, type, is_system) VALUES (?, ?, ?, ?)");
    if ($stmt) {
        foreach ($accounts as $a) {
            $stmt->bind_param('sssi', $a[0], $a[1], $a[2], $a[3]);
            $stmt->execute();
        }
        $stmt->close();
    }

    // --- Default settings ---------------------------------------------------
    $settings = [
        ['acc_opening_float', '0'],          // cash left in the drawer overnight
        ['accounting_schema_version', (string)ACCOUNTING_SCHEMA_VERSION],
    ];
    $setStmt = $conn->prepare("INSERT INTO inv_settings (setting_key, setting_value) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_value = IF(setting_key = 'accounting_schema_version', VALUES(setting_value), setting_value)");
    if ($setStmt) {
        foreach ($settings as $s) { $setStmt->bind_param('ss', $s[0], $s[1]); $setStmt->execute(); }
        $setStmt->close();
    }
}
