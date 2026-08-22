# Inventory & Stock Management Module

This document describes the inventory module of the OmniBiz system,
every file that makes it up, and why it is built the way it is. The
module integrates with the procedural-PHP / mysqli codebase using
self-installing schemas.

> **Note on history.** This module predates the conversion from the old
> laundry system. Since v3 of `inventory_schema.php`, items and
> categories carry a `department` (`supermarket` / `stationery` /
> `general`) and the seeded categories and units are retail ones. The
> laundry usage-template tables (`inv_usage_templates`,
> `inv_usage_template_lines`, `inv_order_consumption`) are no longer
> created, and the automatic per-order deduction they drove no longer
> exists — retail stock leaves through a till sale or a recorded
> movement, never through an order recipe.

> **Depth beyond this file:** `docs/TECHNICAL_DOCUMENTATION.md` §11
> (inventory architecture) and §6 (full table-by-table schema) are the
> authoritative reference. This document covers the module's own design
> reasoning and file inventory.

---

## 1. Design principles

- **Self-contained tables.** Every table is prefixed `inv_` and created
  with `CREATE TABLE IF NOT EXISTS`, so including the schema file on any
  page brings the database up to date without a manual migration.
- **No hard foreign keys onto tables owned by other modules.**
  References to `admin(id)` and sales rows are stored as plain indexed
  integer columns rather than enforced foreign keys, so a difference in
  another table's storage engine or collation can never cause the
  install to fail on the live database.
- **Self-installing schema.** The schema installs itself on first use
  (guarded by a version flag so the cost is one cheap `SELECT` per
  request thereafter). You do **not** need to import any SQL manually.
- **Same conventions as the rest of the app.** Pages use
  `require_once '../includes/auth.php'` + `requireModule('inventory')`,
  the `Africa/Dar_es_Salaam` timezone, `include '../includes/db.php'`,
  the shared admin stylesheets (`styles.css` then `ui.css`),
  `sidebar-admin.php`, Bootstrap 5, Font Awesome 6, Poppins, and the
  design tokens in `ui.css` (primary `--color-primary`, `#0f9aa8`).
- **Safety.** Stock changes are transaction-wrapped with row locking
  (`SELECT ... FOR UPDATE`), soft-delete is used everywhere, all writes
  are prepared statements, and every stock change writes an audit row.
- **Every stock change also moves the books.** `recordStockMovement()`
  posts the matching journal entry through `includes/stock_ledger.php`,
  so the value of stock in the ledger tracks the value on the shelf.

---

## 2. Files

### Core / shared
| File | Purpose |
|------|---------|
| `includes/inventory_schema.php` | Self-installing, idempotent schema for all `inv_` tables + seed data (retail categories, units, default location, settings). Canonical definition of the data model. Currently **v4**. |
| `includes/inventory_functions.php` | Shared helpers: `inventoryBoot()`, `recordStockMovement()` (transaction-safe, row-locking, writes the audit trail and maintains weighted-average cost), `createStockRequest()` / `reviewStockRequest()`, `getInvSetting()` / `setInvSetting()`, formatting helpers, `invStockStatus()`, `invAudit()`. |
| `includes/stock_ledger.php` | Maps each movement type to its journal entry. Required **at runtime inside** `recordStockMovement()`, not at the top of the file, to avoid an include cycle with accounting. |
| `includes/purchasing_functions.php` | Sits **above** inventory and accounting: `poReceiveStock()`, `poRecordPayment()` and the receipt/payment helpers. The only layer that knows a goods receipt is simultaneously a stock movement and a ledger entry. |
| `admin/inventory-header.php` | Shared page head + sidebar + content wrapper + flash messages, so inventory pages stay DRY and on-brand. |
| `admin/inventory-footer.php` | Shared closing markup + Bootstrap JS + per-page script hook (`$pageScript`). |

### Pages (under `admin/`)
| File | Feature area |
|------|--------------|
| `inventory-dashboard.php` | Cards (total value, low/out of stock, month purchases, pending POs), low-stock & expiring alerts, recent movements, and Chart.js charts. The only page in the app that loads Chart.js. |
| `inventory-items.php` | Item CRUD with department, category, unit, supplier, purchase price, average cost, current value, min/reorder levels, status, soft delete, search & filters. |
| `inventory-categories.php` | Category CRUD. A category in use is deactivated, never deleted. |
| `inventory-units.php` | Unit-of-measure CRUD, same in-use protection. |
| `inventory-suppliers.php` | Supplier CRUD (contact, phone, email, address, opening balance, notes) with outstanding-balance figures read from the purchasing tables. A supplier with products or purchase orders is deactivated, never deleted. |
| `inventory-movements.php` | Record stock movements (receive, issue, adjust, damage, expire, loss, return) and a filterable audit history (date, user, qty before/after, reason). |
| `inventory-requests.php` | Internal stock requests: staff request stock for store use, a storekeeper approves, partially approves or rejects. Stock is deducted **only** for approved quantities. |
| `barcode-station.php` | Three scan modes — Stock In, Physical Audit, Assign Barcodes — all driven by one global scanner listener. |
| `barcode-labels.php` | Printable Code128-B sticker sheets, rendered client-side as SVG so no internet connection is needed. |
| `inventory-settings.php` | Currency label and expiry-alert window. Admin/manager only. |
| `inventory-purchase-orders.php` | Purchase order list + create (supplier, line items, dates, notes); auto-generates PO numbers. |
| `inventory-po-view.php` | PO detail: approve, receive (increases stock via a `receive` movement + weighted-average cost, supports partial receipts, posts DR Inventory / CR Payable), invoice upload, payments, cancel. Transaction-safe. |
| `inventory-reports.php` | 9 reports: valuation, most-consumed, slow-moving, supplier purchases, waste, adjustments, sales by department, best sellers, transaction history. |

### JSON endpoint
| File | Purpose |
|------|---------|
| `admin/api/inventory-stock-in.php` | `POST` — receive stock or record a physical count by barcode. `mode: add \| set`. Used by the Barcode Station. |

---

## 3. Tables

- `inv_categories` — product categories, with `department`.
- `inv_units` — units of measure.
- `inv_locations` — branches / warehouses / storage. Schema only; there
  is no management screen.
- `inv_suppliers` — supplier profiles.
- `inv_items` — items, including `sku`, `barcode`, `department`,
  `location_id`, `expiry_date`, `average_cost`, `current_stock`,
  `min_stock`, `reorder_level`, `max_stock`, `status`, soft-delete.
- `inv_batches` — batch/lot tracking with per-batch expiry. Schema only;
  nothing in the application writes to it.
- `inv_stock_movements` — the audit trail: every movement with
  `qty_before`, `qty_after`, `unit_cost`, `reason`, `user_id`,
  `reference_type` / `reference_id`, timestamps.
- `inv_stock_requests` + `inv_stock_request_lines` — internal requests
  and their approved quantities.
- `inv_purchase_orders` + `inv_purchase_order_lines` — purchase order
  header and lines.
- `inv_po_receipts` + `inv_po_payments` — goods-received notes and
  supplier payments. These exist as separate event rows because the
  ledger's idempotency key is `(source_type, source_id)`, and a PO can
  legitimately be received and paid in stages.
- `inv_settings` — key/value configuration, including every module's
  schema version flag.
- `inv_audit_log` — general activity log.

The columns for locations, batches and expiry dates are present
specifically so **future expansion** (multi-branch, warehouse
management, batch/lot tracking) can be layered on without a disruptive
migration.

---

## 4. How stock leaves and enters

| Direction | Path |
|---|---|
| **In** — with a purchase order | `inventory-po-view.php` → `poReceiveStock()` → `recordStockMovement('receive')` |
| **In** — without one | Barcode Station → `api/inventory-stock-in.php` → `posStockIn()` |
| **In/out** — correction | `inventory-movements.php`, or Barcode Station → Physical Audit |
| **Out** — sold | `posCheckout()` → `recordStockMovement('issue')`, inside the sale's transaction |
| **Out** — used by the shop | Approved stock request → `recordStockMovement('issue')` |
| **Out** — damage, expiry, loss, return | `inventory-movements.php` |

**Everything goes through `recordStockMovement()`.** Nothing else in the
codebase writes `inv_items.current_stock`. That single gate is what
makes the audit trail complete and guarantees the ledger moves too.

---

## 5. Roles & permissions

Access is by module key, not role name. `inventory` covers the item,
movement, category, unit, report and barcode screens; `purchasing`
covers purchase orders and suppliers; `stock_requests` covers requests.

- **Admin / Manager / Storekeeper:** full access to the inventory pages.
- **Inventory Settings** additionally requires role `admin` or
  `manager`.
- **Cashier:** no inventory access at all.

See `docs/TECHNICAL_DOCUMENTATION.md` §8 for the complete page-to-guard
map.

---

## 6. Known gaps

- **`inv_batches` is unused.** Batch/lot tracking is not implemented.
- **`inv_locations` has no UI.** One default row is seeded.
- **Stock is a single global quantity.** There is no per-location
  breakdown, so a `transfer` movement would have to be recorded as a
  matched pair or it would quietly destroy stock value. Nothing in the
  UI creates transfers today, and transfers are ledger-neutral.
- **Reports export via the browser's print dialog**; there is no
  server-side PDF or native `.xlsx` rendering.
