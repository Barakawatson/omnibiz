-- ============================================================
-- RESET TO A FRESH INSTALL
-- ------------------------------------------------------------
-- DESTRUCTIVE. This empties every table in `retailer_shop` and
-- leaves the database looking like a brand-new installation:
--
--   * no products, stock, sales, movements, purchase orders,
--     customers, categories, expenses or ledger entries
--   * no shop identity, payment methods or receipt settings
--   * no departments, tills or staff accounts
--
-- It does NOT drop the tables. The application's self-installing
-- schemas (includes/*_schema.php) then rebuild everything that a
-- new install gets on the next page load:
--
--   * the default administrator (admin / 12345 - CHANGE IT)
--   * the chart of accounts
--   * one neutral department, "General"
--   * one neutral till, "Main Till"
--   * the generic unit list
--   * the setup wizard, from step 1
--
-- ------------------------------------------------------------
-- BEFORE RUNNING THIS, TAKE A BACKUP:
--
--   "C:\xampp\mysql\bin\mysqldump.exe" -u root -h 127.0.0.1 -P 3306 ^
--       --routines --single-transaction retailer_shop ^
--       > C:\xampp\backups\retailer_shop_backup.sql
--
-- There is no undo. Restoring that file is the only way back.
--
-- TO RUN:
--   "C:\xampp\mysql\bin\mysql.exe" -u root -h 127.0.0.1 -P 3306 ^
--       retailer_shop < sql\reset_to_fresh_install.sql
--
-- Then open http://localhost:8081/Home/ and sign in as admin/12345.
-- ------------------------------------------------------------
-- This lives in sql/ rather than in a schema installer on purpose:
-- destructive statements must never run automatically on a page
-- load. A schema installer creates and adds; deleting is always a
-- deliberate act by whoever runs the shop.
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;

-- ---------- Accounting -------------------------------------------------
-- Children before parents even with the checks off, so the intent of the
-- order is readable and the script is safe to run with them back on.
TRUNCATE TABLE `acc_daily_close_departments`;
TRUNCATE TABLE `acc_daily_close`;
TRUNCATE TABLE `acc_journal_lines`;
TRUNCATE TABLE `acc_journal`;
TRUNCATE TABLE `acc_expenses`;
TRUNCATE TABLE `acc_accounts`;          -- reseeded by accounting_schema.php

-- ---------- Sales ------------------------------------------------------
TRUNCATE TABLE `sales_payments`;
TRUNCATE TABLE `sales_transaction_items`;
TRUNCATE TABLE `sales_transactions`;
TRUNCATE TABLE `pos_held_sales`;
TRUNCATE TABLE `pos_terminals`;         -- reseeded: one "Main Till"

-- ---------- Purchasing -------------------------------------------------
TRUNCATE TABLE `inv_po_payments`;
TRUNCATE TABLE `inv_po_receipts`;
TRUNCATE TABLE `inv_purchase_order_lines`;
TRUNCATE TABLE `inv_purchase_orders`;

-- ---------- Stock and catalogue ---------------------------------------
TRUNCATE TABLE `inv_stock_movements`;
TRUNCATE TABLE `inv_stock_request_lines`;
TRUNCATE TABLE `inv_stock_requests`;
TRUNCATE TABLE `inv_batches`;
TRUNCATE TABLE `retail_product_images`;
TRUNCATE TABLE `retail_product_details`;
TRUNCATE TABLE `inv_items`;
TRUNCATE TABLE `inv_categories`;
TRUNCATE TABLE `inv_units`;             -- reseeded with the generic list
TRUNCATE TABLE `inv_suppliers`;
TRUNCATE TABLE `inv_locations`;
TRUNCATE TABLE `retail_departments`;    -- reseeded: one "General"

-- ---------- People -----------------------------------------------------
TRUNCATE TABLE `customer`;
TRUNCATE TABLE `admin_remember_tokens`;
TRUNCATE TABLE `admin`;                 -- reseeded: admin / 12345
TRUNCATE TABLE `inv_audit_log`;

-- ---------- Configuration ----------------------------------------------
-- Everything goes, including the schema version flags: clearing those is
-- what makes the installers run in full on the next page load rather
-- than taking their fast path and leaving the tables empty.
TRUNCATE TABLE `shop_payment_methods`;
TRUNCATE TABLE `inv_settings`;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- Nothing else to do. Open the application and it rebuilds itself.
-- ============================================================
