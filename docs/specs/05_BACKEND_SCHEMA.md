# Backend Schema

33 tables, database `retailer_shop`, MariaDB 10.4. Every table is created and versioned by a self-installing schema file (`includes/*_schema.php`) — this document describes the **result**, not how to write the migrations; see TRD §3.1 for that.

Money: `DECIMAL(14,2)`. Quantities: `DECIMAL(14,3)`. Unit costs: `DECIMAL(14,4)`. Timezone `Africa/Dar_es_Salaam` throughout.

---

## 1. Identity, access, configuration

| Table | Purpose | Key relationships |
|---|---|---|
| `admin` | Staff accounts | `role` ∈ {admin, manager, storekeeper, cashier} |
| `admin_remember_tokens` | Rotating persistent-login tokens (selector/validator, hashed) | → `admin.id` |
| `customer` | name + phone only, identified by phone | referenced by `sales_transactions.customer_id` (nullable — most sales are walk-in) |
| `inv_settings` | Flat key/value store — schema versions, tax rate, expiry-discount config, currency, etc. | none (global) |
| `shop_payment_methods` | Payment instructions printed on receipts (provider, number, account name, order, enabled) | independent of `sales_payments` (that's *how a sale was paid*; this is *what to tell a customer to pay to*) |
| `inv_audit_log` | Who did what, when, to which entity | generic `(entity_type, entity_id)` |

## 2. Catalogue and configuration-as-data

| Table | Purpose | Notes |
|---|---|---|
| `retail_departments` | The shop's own department structure — administrator-defined | `dept_key` generated once, immutable; `department` columns elsewhere store this key as `VARCHAR(32)` |
| `inv_categories` | Groups within a department | `department` FK-equivalent to `retail_departments.dept_key` |
| `inv_units` | Units of measure (pieces, kg, litres, …) | shared across all departments |
| `inv_locations` | Named storage locations | **label only** — stock is not tracked per-location (`inv_items.current_stock` is one global figure); a location field is descriptive, not a second stock ledger |
| `inv_suppliers` | Supplier master | soft-deleted (`deleted_at`), deactivated-not-deleted when in use |

## 3. Products / inventory

```
inv_items  (the product/stock record — ONE row is both "the stock item" and "the sellable product")
 ├─ 1:1 → retail_product_details   (selling_price, promo_price/active, brand, package_size,
 │                                   usage_type: internal|sale|both, is_enabled, is_pos_visible)
 ├─ 1:N → retail_product_images    (file_path, sort_order — generated placeholder tiles or real photos)
 ├─ N:1 → inv_categories, inv_units, inv_suppliers, inv_locations
 └─ 1:N → inv_stock_movements      (the full audit trail of every quantity change)
```

`inv_items` carries: `name, sku, barcode (unique, nullable), department, category_id, unit_id, supplier_id, location_id, current_stock, average_cost, purchase_price, min_stock, reorder_level, status, expiry_date, is_perishable, deleted_at`.

**Why one table for "product" and "stock item":** the product-management screen and the inventory screen are two views of the same row — this is the design decision that prevents the two ever disagreeing (documented as a hard rule: "never duplicate data across modules").

`inv_stock_movements`: `item_id, movement_type` (`receive|issue|transfer|damage|expire|loss|adjust|return|opening`), `quantity_before, quantity_after, signed_qty, unit_cost, reason, reference_type, reference_id, created_by, created_at`. Written **only** by `recordStockMovement()` — see App Flow §4.

`inv_batches` — table exists in the schema but is **never written to** by any code path today (documented limitation, not a bug).

## 4. Purchasing

```
inv_suppliers
    │
    ▼
inv_purchase_orders  (po_number, supplier_id, status: draft→approved→partially_received→
    │                  received→cancelled, approved_by, invoice_file)
    │
    ├─ 1:N → inv_purchase_order_lines   (item_id, quantity_ordered, unit_cost, quantity_received)
    ├─ 1:N → inv_po_receipts             (one row per RECEIVING EVENT — a PO can be received
    │                                     in stages; each receipt event is its own idempotency
    │                                     source for the ledger)
    └─ 1:N → inv_po_payments             (one row per PAYMENT EVENT — same reasoning: a PO can
                                          be paid in instalments)
```

Two child event tables (receipts, payments) exist specifically because the ledger's idempotency key is `(source_type, source_id)` — anything that can legitimately happen more than once against the same parent needs its own row to key against.

## 5. Point of sale

```
pos_terminals  (name, code, department, location_id, is_active)
    │
    ▼
sales_transactions  (receipt_no [MRT-YYYYMMDD-NNNXXX], terminal_id, department, cashier_id/name,
    │                 customer_type, customer_id/name/phone, subtotal, discount, tax_rate,
    │                 tax_amount, total, total_cost, gross_profit, amount_paid, change_due,
    │                 payment_method|'split', status: completed|voided, void_reason, voided_by/at)
    │
    ├─ 1:N → sales_transaction_items  (item_id, item_name, barcode, department, quantity,
    │                                  unit_price, unit_cost, line_discount, line_total —
    │                                  name/barcode/department SNAPSHOTTED at sale time so a
    │                                  later product edit or department rename never rewrites history)
    │
    └─ 1:N → sales_payments  (method, amount, reference — one row per TENDER, so a split
                              cash+mobile sale is fully reconstructable)

pos_held_sales  (cart_json, label, total, terminal_id, cashier — parked sales, no stock/ledger
                 effect until resumed and completed through the normal checkout path)
```

**Receipt numbering is intentionally NOT a compliance-grade sequence:** it resets daily and includes 3 random characters so a customer cannot guess another customer's receipt number. (This becomes directly relevant if TRA fiscal integration is ever built — TRA's counters must be new, independent columns; see the TRA audit document §11.)

## 6. Accounting

```
acc_accounts  (code, name, type: asset|liability|equity|revenue|expense, is_system)
    │            balances are NEVER stored — always SUM()'d from acc_journal_lines on demand
    ▼
acc_journal  (entry_no, entry_date, memo, source_type, source_id, department, total_debit,
    │          total_credit, status, reversed_by)
    │          UNIQUE (source_type, source_id)  ← the idempotency guarantee
    ▼
acc_journal_lines  (journal_id, account_id, debit, credit, memo)
    │               accPostEntry() refuses to insert unless SUM(debit) = SUM(credit)

acc_expenses  (expense_date, account_id, amount, paid_from_account_id, department, receipt_file
               [column exists, never populated — no UI writes it])

acc_daily_close  (close_date [UNIQUE], opening_float, sales_count, voided_count, gross/net sales,
    │              cost_of_sales, gross_profit, cash/mobile/bank/card_sales,
    │              supermarket/stationery/general_sales [legacy fixed columns, kept for
    │              backward-read compatibility with closes recorded before departments
    │              became configurable], cash_expenses, expected_cash, counted_cash, variance)
    │
    └─ 1:N → acc_daily_close_departments  (close_id, dept_key, dept_name, sales — the CURRENT,
             department-agnostic per-department split; dept_name is snapshotted so a report
             reprinted after a department is renamed still shows what it was called on the day)
```

## 7. Entity-relationship summary (text form)

```
admin ──< admin_remember_tokens
admin ──< sales_transactions (cashier)
admin ──< inv_audit_log

retail_departments ──< inv_categories, inv_items, sales_transactions,
                        sales_transaction_items, pos_terminals, acc_journal, acc_expenses
                        (via the `department` VARCHAR key, not a formal FK — see TRD §5)

inv_suppliers ──< inv_purchase_orders ──< inv_purchase_order_lines
                                       ──< inv_po_receipts
                                       ──< inv_po_payments

inv_categories ──< inv_items ──1:1─ retail_product_details
                             ──< retail_product_images
                             ──< inv_stock_movements
inv_units      ──< inv_items
inv_locations  ──< inv_items

customer ──< sales_transactions
pos_terminals ──< sales_transactions ──< sales_transaction_items
                                      ──< sales_payments

acc_accounts ──< acc_journal_lines >── acc_journal ──(source_type,source_id)── {sales_transactions,
                                                                                 inv_purchase_orders,
                                                                                 inv_stock_movements,
                                                                                 acc_expenses,
                                                                                 acc_daily_close}
acc_daily_close ──< acc_daily_close_departments
```

## 8. Deliberately absent (and why)

| Not in the schema | Reason |
|---|---|
| Per-location stock quantity | `inv_items.current_stock` is a single global figure by design; `inv_locations` is descriptive only |
| Refund/credit-note table | Only whole-sale void exists; a partial refund workflow is unbuilt |
| Multi-tenant columns (`tenant_id`, `shop_id`, …) | Verified absent from every table — one shop per installation |
| Per-item tax category | **Required for any future TRA fiscal work** — flagged as a gap in the TRA audit, not yet built |
| Job/queue table | No background-worker infrastructure exists yet (relevant to any external integration with retry needs) |
