# TECHNICAL DOCUMENTATION

| | |
|---|---|
| **System Name** | OmniBiz — Supermarket & Stationery Point of Sale |
| **Document Title** | Technical Documentation |
| **Version** | 1.0 |
| **Date** | 13 August 2026 |
| **Prepared From** | Direct inspection of the application source code, the live database schema (`mysqldump --no-data`), and the running application at `http://localhost:8081/Home/` |
| **Application Version** | Not defined in the application. No version constant exists. Schema versions are tracked per module in `inv_settings`: core `1`, inventory `4`, catalog `2`, accounting `3`, POS `3`. |

**Audience:** system administrators, developers, ICT technicians, database administrators, technical support.

**Accuracy note.** Every factual statement here was read from source or from the running system. Recommendations are confined to sections explicitly labelled *Recommended*. Anything not implemented is labelled **Not implemented**; anything that could not be established is labelled **Could not be verified**.

**Security note.** In line with normal practice, this document names configuration *variables* but does not reproduce credential *values*.

---

## Table of contents

1. [System overview](#1-system-overview)
2. [Technology stack](#2-technology-stack)
3. [System architecture](#3-system-architecture)
4. [Directory structure](#4-directory-structure)
5. [Database architecture](#5-database-architecture)
6. [Database schema](#6-database-schema)
7. [Authentication](#7-authentication)
8. [Authorization / RBAC](#8-authorization--rbac)
9. [POS architecture](#9-pos-architecture)
10. [Barcode system](#10-barcode-system)
11. [Inventory architecture](#11-inventory-architecture)
12. [Accounting architecture](#12-accounting-architecture)
13. [API documentation](#13-api-documentation)
14. [Security](#14-security)
15. [Error handling](#15-error-handling)
16. [File uploads](#16-file-uploads)
17. [Frontend architecture](#17-frontend-architecture)
18. [Deployment](#18-deployment)
19. [Backup and recovery](#19-backup-and-recovery)
20. [Maintenance](#20-maintenance)
21. [Known technical limitations](#21-known-technical-limitations)
22. [Shop settings, theming and the customer display](#22-shop-settings-theming-and-the-customer-display)
23. [Reporting system](#23-reporting-system)
24. [Business configuration](#24-business-configuration)
25. [URL handling](#25-url-handling)

---

## 1. System overview

### Architecture

A **server-rendered multi-page PHP application** with no framework, no ORM, no build step and no package manager. Each URL maps to one PHP file that acts as its own controller, model and view. A small number of JSON endpoints support the POS terminal and barcode station.

### Backend

Native procedural PHP 8.2. Database access is `mysqli` with prepared statements. Shared logic lives in `includes/` as plain functions; there are no classes anywhere in the application code.

### Frontend

Server-rendered HTML with Bootstrap 5 and vanilla ES6 JavaScript. One shared JavaScript library (`assets/js/admin/ui.js`) exposes a single global, `window.MX`. Page-specific JavaScript is written inline in the page that needs it. No transpilation, no bundling, no modules.

### Database

MariaDB 10.4.32, database `retailer_shop`, all tables InnoDB with `utf8mb4`. **31 tables.**

### Web server

Apache 2.4.58 from XAMPP, listening on **port 8081**, document root `C:\xampp\htdocs`, application served from `/Home/`.

### Authentication

PHP native sessions. Passwords hashed with bcrypt (`password_hash` / `password_verify`). An optional "remember me" cookie.

### Authorization

Role-based access control with five roles and twenty-five module permissions, enforced server-side at the top of every page by `requireRole()` or `requireModule()`.

### APIs

Four JSON endpoints — three under `admin/api/` plus one notifications endpoint. All are session-authenticated; none are public.

---

## 2. Technology stack

Versions below were read from the running system (`php -v`, `httpd -v`, `SELECT VERSION()`) or from the exact CDN URLs in the source.

| Component | Version | How it is loaded | Notes |
|---|---|---|---|
| **PHP** | 8.2.12 (ZTS, VC2019 x64, built Oct 2023) | XAMPP | |
| **Apache** | 2.4.58 (Win64, Apache Lounge VS17) | XAMPP | Port 8081 |
| **XAMPP** | Version string not recorded in the project | — | Installed at `C:\xampp` |
| **MariaDB** | 10.4.32 | XAMPP | Port 3306, database `retailer_shop` |
| **mysqli** | Bundled with PHP 8.2.12 | PHP extension | The only database driver used |
| **Bootstrap** | 5.3.2 | `cdn.jsdelivr.net` (CSS + `bundle.min.js`) | The bundle includes Popper |
| **Font Awesome** | 6.4.0 | `cdnjs.cloudflare.com` (CSS only) | |
| **Poppins** | — | `fonts.googleapis.com` | Weights 300–700 (400/500/600 on some pages) |
| **Chart.js** | 4.4.1 (`chart.umd.min.js`) | `cdn.jsdelivr.net` | Used on `admin/inventory-dashboard.php` **only** |
| **Cropper.js** | 1.5.12 (CSS + JS) | `cdnjs.cloudflare.com` | Referenced by `admin/inventory-header.php` and used by `admin/profile.php` |
| **Vanilla JavaScript** | ES6 | Inline + `assets/js/admin/ui.js` | No framework |

**SweetAlert2: not present.** No reference exists anywhere in the codebase. Confirmations use the browser's native `confirm()`; notifications use the in-house `MX.toast()`.

**PHP extensions confirmed loaded:** `mysqli`, `gd`, `mbstring`, `json`, `session`, `fileinfo`, `openssl`.
- `mysqli` — all database access
- `session` — authentication
- `json` — API payloads, held-sale carts
- `fileinfo` — `mime_content_type()` in product image validation
- `mbstring`, `gd`, `openssl` — present; no direct application dependency was identified. **Could not be verified** whether `gd` is required.

**No `package.json`, `composer.json`, lock file, or `vendor/` directory exists.** There is nothing to install and nothing to build.

---

## 3. System architecture

### Page-controller architecture

Every user-facing URL is a single PHP file that performs the whole request: guard, boot, handle POST, query, render. The canonical shape:

```php
<?php
require_once '../includes/auth.php';   // 1. sessions + RBAC
requireModule('inventory');            // 2. guard (must be first)
include '../includes/db.php';          // 3. connection + constants
require_once '../includes/xxx_functions.php';
xxxBoot($conn);                        // 4. self-installing schema

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    /* ... mutate ... */
    $_SESSION['inv_flash'] = ['type' => 'success', 'msg' => '...'];
    header('Location: same-page.php');  // 5. POST/Redirect/GET
    exit;
}
/* ... queries ... */
$pageTitle = '...';
include 'inventory-header.php';        // 6. shared chrome
/* ... markup ... */
include 'inventory-footer.php';
```

### Request lifecycle

```
Browser
  │
  ▼
Apache :8081 ── .htaccess rewrite ──► admin/<page>.php
  │                                        │
  │                                        ▼
  │                        require_once includes/auth.php
  │                          └─ session_start()
  │                                        │
  │                        requireRole() / requireModule()
  │                          ├─ not signed in ──► 302 login.php  (or 401 JSON for /api/)
  │                          └─ wrong role   ──► 302 access-denied.php (or 403 JSON)
  │                                        │
  │                        include includes/db.php
  │                          └─ mysqli_connect(), utf8mb4, timezone, constants
  │                                        │
  │                        <module>Boot($conn)
  │                          └─ ensure*Schema() — version check, then CREATE/ALTER if stale
  │                                        │
  │                        POST handling ──► mutate ──► flash ──► 302 self
  │                                        │
  │                        SELECT queries ──► render HTML
  ▼
Browser ◄── HTML (+ optional fetch() to admin/api/*.php for JSON)
```

### Authentication flow

1. `admin/login.php` calls `session_start()`.
2. If `$_SESSION['username']` and `$_SESSION['role']` are already set, it redirects to `roleHomeUrl($role)`.
3. Otherwise, if a `remember_admin` cookie exists, its username is looked up; if the account is active with a valid role, the session is populated and the user is redirected. **No password is verified on this path.**
4. On POST: the username is validated against `/^[a-zA-Z0-9_.-]{1,50}$/`, then looked up with a prepared statement, then `password_verify()`.
5. On success: status must be `active` and the role must be in `allSystemRoles()`; `session_regenerate_id(true)` is called; `$_SESSION['role']`, `['username']` and `['id']` are set; redirect to `roleHomeUrl()`.

### Authorization flow

See [section 8](#8-authorization--rbac).

### POST / Redirect / GET

Every mutating page POSTs to itself, writes a flash message into `$_SESSION['inv_flash']`, then issues a 302 to itself. `admin/inventory-header.php` reads the flash, clears it, and emits it as a `data-mx-flash` attribute which `ui.js` turns into a toast. This prevents duplicate submission on refresh.

### Database access

- One global `$conn` (a `mysqli` object) created by `includes/db.php`.
- Prepared statements are the convention throughout. Exceptions are catalogued in [section 14](#14-security).
- Multi-step writes use `begin_transaction()` / `commit()` / `rollback()` with `try`/`catch (Throwable)`.
- Row-level locks (`SELECT … FOR UPDATE`) protect stock quantities.

`includes/db.php` calls `mysqli_report(MYSQLI_REPORT_OFF)` at the top. This is deliberate and load-bearing: PHP 8.1 changed mysqli's default to throw exceptions, but roughly forty guarded probe queries in this codebase use the `@$conn->query(...)` idiom and test the return value. Under the 8.1+ default those probes become fatal errors and a fresh install cannot complete its first page load.

### Schema boot process

Each module owns an `includes/*_schema.php` with an `ensure*Schema(mysqli $conn)` function and a version constant. The function:

1. Reads its version key from `inv_settings`. If it matches the constant, returns immediately (the fast path on every request).
2. Otherwise runs `CREATE TABLE IF NOT EXISTS` for its tables, then guarded `ALTER TABLE` statements for new columns (guarded by an information-schema check), then seeds any required rows.
3. Writes the current version back into `inv_settings`.

| Module | File | Constant | Current |
|---|---|---|---|
| Core | `core_schema.php` | `CORE_SCHEMA_VERSION` | `2` |
| Inventory | `inventory_schema.php` | `INV_SCHEMA_VERSION` | `4` |
| Catalog | `catalog_schema.php` | `CATALOG_SCHEMA_VERSION` | `2` |
| Accounting | `accounting_schema.php` | `ACCOUNTING_SCHEMA_VERSION` | `3` |
| POS | `pos_schema.php` | `POS_SCHEMA_VERSION` | `3` |

Boot functions compose: `posBoot()` calls all five. **Destructive changes are never automatic** — dropping tables, deleting rows and reassigning roles live only in `sql/retail_migration.sql`, which a human runs deliberately.

### API requests

`fetch()` from the POS terminal and Barcode Station, `credentials: 'same-origin'`, JSON request and response bodies. `ui.js` additionally polls `admin/notifications-api.php` every 30 seconds.

### Frontend interaction

Progressive: the pages work as plain forms; JavaScript adds toasts, client-side filtering, sidebar state and modal population. The POS terminal is the exception — it is genuinely JavaScript-driven, with cart state held in a browser variable and committed via a single API call.

### Component diagram

```
┌──────────────────────────── Browser ────────────────────────────┐
│  Server-rendered pages          POS terminal (pos.php)          │
│  Bootstrap 5 + ui.js (MX)       inline ES6, cart in memory      │
└────────────┬────────────────────────────┬───────────────────────┘
             │ form POST / GET            │ fetch() JSON
             ▼                            ▼
┌────────────────────────── Apache 2.4.58 :8081 ──────────────────┐
│  .htaccess — clean routes, 404 catch-all, -Indexes              │
└────────────┬────────────────────────────┬───────────────────────┘
             ▼                            ▼
      admin/*.php  (pages)          admin/api/*.php  (JSON)
             │                            │
             └────────────┬───────────────┘
                          ▼
        ┌──────────────── includes/ ────────────────┐
        │  auth.php     — sessions, RBAC            │
        │  db.php       — $conn, constants          │
        │                                            │
        │  ┌── Layer 3: purchasing_functions.php ──┐│
        │  │   (sits above inventory + accounting)  ││
        │  └───────────────┬────────────────────────┘│
        │  ┌── Layer 2 ────┴────────────────────────┐│
        │  │ pos_functions   catalog_functions      ││
        │  └───────────────┬────────────────────────┘│
        │  ┌── Layer 1 ────┴────────────────────────┐│
        │  │ inventory_functions ──runtime──►       ││
        │  │        stock_ledger ──► accounting_fns ││
        │  └────────────────────────────────────────┘│
        │  *_schema.php — self-installing DDL        │
        └────────────────────┬───────────────────────┘
                             ▼
                MariaDB 10.4.32 · retailer_shop · 29 InnoDB tables
```

**Include-cycle note.** `accounting_functions.php` depends on inventory helpers, and `recordStockMovement()` must post to the ledger. A top-level include in both directions would be a cycle. The resolution: `inventory_functions.php` does **not** include `stock_ledger.php` at the top of the file — it calls `require_once __DIR__ . '/stock_ledger.php'` *at runtime, inside* `recordStockMovement()`, by which point both layers are loaded. Similarly, `purchasing_functions.php` is a third layer that sits above both and is never included by either.

---

## 4. Directory structure

```
C:\xampp\htdocs\home\
├── .htaccess                    Rewrite rules, 404 catch-all, -Indexes
├── CLAUDE.md                    Developer notes
├── INVENTORY-MODULE.md          Inventory module notes
├── UI_COMPONENTS.md             Design-system notes
├── logout.php                   Destroys session + remember cookie
├── notfound.php                 404 page (returns real HTTP 404)
│
├── includes/                    All shared server-side logic
│   ├── db.php                   Connection, charset, timezone, business constants
│   ├── auth.php                 Sessions, roles, permissions, guards
│   ├── csrf.php                 CSRF tokens (required by auth.php)
│   ├── core_schema.php          admin + customer + remember-token tables  (v2)
│   ├── remember_me.php          "Keep me signed in" tokens (selector/validator)
│   ├── inventory_schema.php     inv_* tables                      (v4)
│   ├── catalog_schema.php       retail_* tables, departments      (v2)
│   ├── accounting_schema.php    acc_* tables, chart of accounts   (v3)
│   ├── pos_schema.php           sales_*, pos_* tables             (v3)
│   ├── inventory_functions.php  recordStockMovement(), settings, audit
│   ├── catalog_functions.php    Products, pricing, customers, phone
│   ├── pos_functions.php        Checkout, void, barcode lookup, held sales
│   ├── purchasing_functions.php PO receiving and payments
│   ├── accounting_functions.php Journal, ledger, expenses, P&L, close
│   └── stock_ledger.php         Stock movement → journal mapping
│
├── admin/                       All staff-facing pages (37 files)
│   ├── login.php  index.php  access-denied.php  profile.php
│   ├── sidebar-admin.php  sidebar-nav.php       Shared navigation
│   ├── inventory-header.php  inventory-footer.php   Shared page shell
│   ├── partials/
│   │   ├── page-header.php   Standard page title block
│   │   ├── empty-state.php   Standard "nothing here" block
│   │   └── pagination.php    Standard pager
│   ├── notifications-api.php    Sidebar badge counts (JSON)
│   └── api/
│       ├── pos-checkout.php     POST — complete a sale
│       ├── products-scan.php    GET  — barcode/SKU lookup
│       └── inventory-stock-in.php POST — scan stock in
│
├── assets/
│   ├── css/admin/styles.css     Legacy remnant (36 lines) — load FIRST
│   ├── css/admin/ui.css         The design system (~50 KB) — load SECOND
│   ├── js/admin/ui.js           window.MX library (~520 lines)
│   ├── images/                  Logos, avatars, favicon
│   └── uploads/                 The only directory the app writes to
│       ├── shop_products/       Product images
│       ├── profile_admin/       Profile photos
│       └── po_invoices/         Purchase order invoices
│
└── docs/                        ← this documentation
    ├── USER_GUIDE.md
    ├── TECHNICAL_DOCUMENTATION.md
    ├── SYSTEM_OPERATIONS_HOW_TO_GUIDE.md
    └── screenshots/
```

### Responsibilities

**`includes/`** — everything shared. Two guaranteed-safe entry points per module: a `*_schema.php` that installs, and a `*_functions.php` that operates. Nothing in `includes/` emits HTML.

**`admin/`** — one file per screen. Each guards itself; there is no front controller, so a missing guard means a genuinely unprotected page.

**`admin/api/`** — JSON endpoints. Their only structural difference is `authIsApiRequest()`, which makes the guards return HTTP status codes rather than redirect to an HTML login form one directory up.

**`assets/`** — static files plus user uploads. `assets/uploads/` is the only directory the application writes to.

**`sql/`** — **removed.** It held `retail_migration.sql` (the destructive half of the laundry→retail conversion, already run) and `seed_sample_products.php` (a sample-product seeder). Neither was executed by the application, and both were deleted in the 13 August 2026 cleanup. The convention they embodied still stands: destructive DDL never goes in a schema installer — write it as a standalone `.sql` file for a human to run deliberately.

> **Status of the Markdown files in the project root.** All three were brought current on 13 August 2026.
>
> - **`CLAUDE.md`** — corrected the database name (`retailer_shop`, not `zeea-laundry`), the MySQL port (3306, not 3307), the primary colour token (`#0f9aa8`, not `#42c3cf`) and the department ENUM table list; added the split-payment, in-use-protection and known-rough-edges sections.
> - **`INVENTORY-MODULE.md`** — rewritten. It had substantial laundry residue: an entire section on "how automatic deduction works" using a *Standard Wash → Detergent / Softener* example, a row for the deleted `inventory-templates.php`, the dropped `inv_usage_templates` / `inv_order_consumption` tables, a "Staff" role that no longer exists, a rollback section referencing the deleted `admin/order-details.php`, and claims that the PO workflow and barcode scanning were still "future phases". All removed and replaced with the current file inventory and design reasoning.
> - **`UI_COMPONENTS.md`** — rewritten. It still documented the pre-redesign `mx-*` component set: `.mx-kpi`, `.mx-quick`, `.mx-card`, `.mx-table`, `.mx-feed`, `.mx-chip`, `.mx-empty` and `.mx-live` had all been removed from the stylesheet, so following the file produced unstyled markup. It also carried a sidebar example built on the deleted `shop-orders.php` and described two dashboard AJAX endpoints (`index.php?ajax_dashboard=1`, `?ajax_activity=1`) that do not exist. Rewritten against the current `ui.css` and `ui.js`, with every class and JS method machine-verified.
> - **`POS_MODULE.md`** — **deleted.** It predated both the catalog rename and the split-payment work: it referred to `shop_product_details` (renamed to `retail_product_details` by catalog schema v2), a receipt format of `MCS-POS-YYYYMMDD-NNNXXX` (now `MRT-YYYYMMDD-NNNXXX`) and a `sql/pos_migration.sql` that never existed, and it had no knowledge of departments, terminals or `sales_payments`. Sections 9 and 10 of this document replace it.

---

## 5. Database architecture

### Engine and database

- **Engine:** MariaDB 10.4.32, all tables **InnoDB** (so foreign keys and transactions are real)
- **Database:** `retailer_shop`
- **Charset / collation:** `utf8mb4` / `utf8mb4_general_ci` throughout
- **Connection:** `127.0.0.1:3306`
- **Configuration variables** (in `includes/db.php`, values not reproduced): `$host`, `$port`, `$username`, `$password`, `$database`

### Numeric conventions

| Kind of value | Column type | Reason |
|---|---|---|
| Money | `DECIMAL(14,2)` | Exact; never floating point |
| Quantities | `DECIMAL(14,3)` | Supports fractional units (kg, litres) |
| Unit costs | `DECIMAL(14,4)` | Extra precision so weighted averages do not drift |
| Tax rate | `DECIMAL(6,3)` | Percentage |
| Flags | `TINYINT(1)` | 0/1 |

### Deletion convention

**Soft deletes.** Most tables carry `deleted_at DATETIME NULL`; queries filter `deleted_at IS NULL`. History survives. Tables with soft delete: `inv_items`, `inv_categories`, `inv_units`, `inv_suppliers`, `inv_locations`, `inv_purchase_orders`, `acc_accounts`, `acc_expenses`.

### Foreign keys actually declared

Eighteen foreign key constraints exist in the live schema:

| Child table | Column | → Parent | On delete |
|---|---|---|---|
| `admin_remember_tokens` | `user_id` | `admin` | CASCADE |
| `acc_journal_lines` | `journal_id` | `acc_journal` | CASCADE |
| `acc_journal_lines` | `account_id` | `acc_accounts` | RESTRICT (default) |
| `inv_batches` | `item_id` | `inv_items` | CASCADE |
| `inv_items` | `category_id` | `inv_categories` | SET NULL |
| `inv_items` | `unit_id` | `inv_units` | SET NULL |
| `inv_items` | `supplier_id` | `inv_suppliers` | SET NULL |
| `inv_items` | `location_id` | `inv_locations` | SET NULL |
| `inv_po_payments` | `po_id` | `inv_purchase_orders` | CASCADE |
| `inv_po_receipts` | `po_id` | `inv_purchase_orders` | CASCADE |
| `inv_purchase_order_lines` | `po_id` | `inv_purchase_orders` | CASCADE |
| `inv_stock_movements` | `item_id` | `inv_items` | CASCADE |
| `inv_stock_request_lines` | `request_id` | `inv_stock_requests` | CASCADE |
| `inv_stock_request_lines` | `item_id` | `inv_items` | CASCADE |
| `retail_product_details` | `item_id` | `inv_items` | CASCADE |
| `retail_product_images` | `item_id` | `inv_items` | CASCADE |
| `sales_payments` | `transaction_id` | `sales_transactions` | CASCADE |
| `sales_transaction_items` | `transaction_id` | `sales_transactions` | CASCADE |

**Relationships enforced only in application code** (no FK declared): `sales_transactions.customer_id` → `customer`, `sales_transactions.terminal_id` → `pos_terminals`, `sales_transactions.cashier_id` → `admin`, `sales_transaction_items.item_id` → `inv_items`, `inv_purchase_order_lines.item_id` → `inv_items`, `inv_purchase_orders.supplier_id` → `inv_suppliers`, `acc_expenses.account_id` / `paid_from_account_id` → `acc_accounts`, and all `*_by` / `user_id` columns → `admin`.

This is deliberate in places: `sales_transaction_items` stores `item_name`, `barcode`, `unit_price` and `unit_cost` as **historical snapshots**, so a receipt reprints exactly as it was even if the product is later renamed, repriced or deleted.

### Logical relationship description

**Product identity — one item, layered.**
A product is **not** duplicated between modules. `inv_items` is the single row for a physical thing — its SKU, barcode, department, stock, cost. `retail_product_details` extends that row 1:1 with everything about *selling* it: price, promotion, whether it is enabled, whether the till shows it. `retail_product_images` adds 0..n pictures. The POS and every report read the same `inv_items` row.

```
inv_categories ─┐
inv_units ──────┼──► inv_items ──1:1──► retail_product_details
inv_suppliers ──┤       │      └──1:n──► retail_product_images
inv_locations ──┘       │
                        ├──1:n──► inv_stock_movements   (every change, ever)
                        ├──1:n──► inv_batches           (table unused)
                        ├──1:n──► inv_purchase_order_lines
                        ├──1:n──► inv_stock_request_lines
                        └──1:n──► sales_transaction_items
```

**Inventory relationships.**
`inv_items.current_stock` is the authoritative quantity. `inv_stock_movements` is the append-only journal that explains it — every row records `qty_before` and `qty_after`, so the quantity can be reconstructed and audited. Nothing writes `current_stock` except `recordStockMovement()`.

**Sales relationships.**

```
pos_terminals ─┐
admin (cashier)┼──► sales_transactions ──1:n──► sales_transaction_items
customer ──────┘            │                          (snapshot of item)
                            └──1:n──► sales_payments   (one row per tender)
```

A sale has **one** header, **n** lines, and **n** tenders. `sales_transactions.payment_method` holds a single method, or the literal `'split'` when more than one was used; the truth is in `sales_payments`. `pos_held_sales` holds a parked cart as JSON, server-side, scoped to the cashier and terminal that held it (see "Held sales" below — as of 25 August 2026 it is no longer true that any terminal can resume any held sale).

**Purchasing relationships.**

```
inv_suppliers ──► inv_purchase_orders ──┬──1:n──► inv_purchase_order_lines
                                        ├──1:n──► inv_po_receipts   (GRN)
                                        └──1:n──► inv_po_payments
```

Each receipt and each payment records the `journal_id` it produced, so the paper trail runs both ways.

**Accounting relationships.**

```
acc_accounts ◄──n:1── acc_journal_lines ──n:1──► acc_journal
                                                    │
acc_expenses ──────────────────────────────────────┘ (journal_id)
acc_daily_close ─── posts a variance entry ─────────┘
```

`acc_journal` is the entry header; `acc_journal_lines` are the debits and credits. `acc_journal` carries a **unique key on `(source_type, source_id)`** — this is the idempotency guard that makes it impossible to post the same sale, expense, movement or receipt twice.

**Account balances are never stored.** Every balance, trial balance and P&L figure is summed from `acc_journal_lines` on demand.

**The load-bearing invariant:**

> `acc_accounts` code 1200 (Inventory Asset) balance **===** `SUM(inv_items.current_stock × inv_items.average_cost)`

If those two numbers diverge, a stock movement failed to post. It is the single most useful health check in the system.

---

## 6. Database schema

All 31 tables present in `retailer_shop`.

### Core

| Table | Purpose | Important fields | Relationships |
|---|---|---|---|
| `admin` | Staff user accounts. Despite the name, holds **all** roles. | `id`, `username` (unique), `password` (bcrypt), `role` (varchar 30), `status` (active/inactive), `profile_photo`, `created_at` | Referenced by name/id from sales, journals, movements (no FK) |
| `customer` | Retail customers. No login. | `id`, `name`, `phone_number` (**unique** — the identity), `registration_date` | Referenced by `sales_transactions.customer_id` (no FK) |
| `admin_remember_tokens` | "Keep me signed in" tokens — one row per remembered device. | `user_id`, `selector` (**unique**, the lookup key), `validator_hash` (SHA-256 of the secret — the secret itself is never stored), `user_agent`, `expires_at`, `last_used_at` | FK → `admin` CASCADE, so deleting a user removes their tokens |

### Inventory (`inv_*`)

| Table | Purpose | Important fields | Relationships |
|---|---|---|---|
| `inv_items` | The single product record. | `sku` (unique), `barcode` (unique), `name`, `department` (enum), `category_id`, `unit_id`, `supplier_id`, `location_id`, `purchase_price`, `average_cost` (14,4), `current_stock` (14,3), `opening_stock`, `min_stock`, `reorder_level`, `max_stock`, `is_perishable`, `expiry_date`, `last_purchased_at`, `status`, `deleted_at` | FK → categories, units, suppliers, locations (all SET NULL). Parent of movements, details, images, batches |
| `inv_categories` | Product categories. | `name` (unique), `description`, `department`, `is_active`, `deleted_at` | Parent of `inv_items` |
| `inv_units` | Units of measure. | `name` (unique), `abbreviation`, `is_active`, `deleted_at` | Parent of `inv_items` |
| `inv_suppliers` | Suppliers. | `name`, `contact_person`, `phone`, `email`, `address`, `opening_balance`, `notes`, `is_active`, `deleted_at` | Parent of `inv_items`, `inv_purchase_orders` |
| `inv_locations` | Branches / warehouses / storage. | `name` (unique), `type` (enum), `address`, `is_active`, `deleted_at` | Parent of `inv_items`, `pos_terminals`. **No management UI** |
| `inv_stock_movements` | Append-only ledger of every stock change. | `item_id`, `movement_type` (enum: receive, issue, transfer, damage, expire, loss, adjust, return, opening), `quantity` (signed), `qty_before`, `qty_after`, `unit_cost`, `reference_type`, `reference_id`, `order_id`, `reason`, `user_id` | FK → `inv_items` CASCADE |
| `inv_batches` | Batch/lot tracking with expiry. | `item_id`, `batch_no`, `quantity`, `unit_cost`, `expiry_date`, `received_at` | FK → `inv_items` CASCADE. **Table is never written to** |
| `inv_purchase_orders` | Purchase order headers. | `po_number` (unique), `supplier_id`, `status` (draft/approved/received/partially_received/cancelled), `order_date`, `expected_date`, `received_date`, `total_amount`, `payment_status` (unpaid/partial/paid), `invoice_file`, `deleted_at` | Parent of lines, receipts, payments |
| `inv_purchase_order_lines` | Ordered items. | `po_id`, `item_id`, `quantity`, `unit_price`, `received_qty` | FK → PO CASCADE |
| `inv_po_receipts` | Goods-received notes. | `receipt_no` (unique), `po_id`, `total_value`, `line_count`, `journal_id`, `received_by_name` | FK → PO CASCADE |
| `inv_po_payments` | Payments to suppliers against a PO. | `payment_no` (unique), `po_id`, `amount`, `paid_from_account_id`, `payment_date`, `reference`, `journal_id` | FK → PO CASCADE |
| `inv_stock_requests` | Internal stock requests. | `request_no` (unique), `requested_by`, `purpose`, `status` (pending/approved/partially_approved/rejected), `review_note`, `reviewed_by`, `reviewed_at` | Parent of request lines |
| `inv_stock_request_lines` | Requested items. | `request_id`, `item_id`, `qty_requested`, `qty_approved` | FK → requests & items, CASCADE |
| `inv_settings` | Key/value configuration. | `setting_key` (unique), `setting_value`, `updated_at` | Also stores every module's schema version |
| `inv_audit_log` | User action audit trail. | `user_id`, `action`, `entity_type`, `entity_id`, `details`, `created_at` | — |

### Retail catalog (`retail_*`)

| Table | Purpose | Important fields | Relationships |
|---|---|---|---|
| `retail_product_details` | Selling attributes layered on an item. | `item_id` (**unique** — enforces 1:1), `usage_type` (internal/sale/both), `description`, `package_size`, `brand`, `selling_price`, `promo_price`, `promo_active`, `is_enabled`, `is_pos_visible` | FK → `inv_items` CASCADE |
| `retail_product_images` | Product photographs. | `item_id`, `file_path`, `sort_order` | FK → `inv_items` CASCADE |

*(Both were renamed in place from `shop_product_details` / `shop_product_images` by catalog schema v2.)*

### Point of sale (`pos_*`, `sales_*`)

| Table | Purpose | Important fields | Relationships |
|---|---|---|---|
| `sales_transactions` | Sale headers. | `receipt_no` (unique), `terminal_id`, `department`, `cashier_id`, `cashier_name`, `customer_type` (cash/registered), `customer_id`, `customer_name`, `customer_phone`, `subtotal`, `discount`, `tax_rate`, `tax_amount`, `total`, `total_cost`, `gross_profit`, `amount_paid`, `change_due`, `payment_method` (or `'split'`), `status` (completed/voided), `void_reason`, `voided_by`, `voided_at`, `note` | Parent of items and payments |
| `sales_transaction_items` | Sale lines — **historical snapshot**. | `transaction_id`, `item_id`, `item_name`, `barcode`, `department`, `quantity`, `unit_price`, `unit_cost`, `line_discount`, `line_total` | FK → transaction CASCADE |
| `sales_payments` | One row per tender. | `transaction_id`, `method` (cash/lipa_namba/bank/card), `amount`, `reference` | FK → transaction CASCADE |
| `pos_terminals` | Till definitions + live session lock (v8, 25 Aug 2026). | `name`, `code` (unique), `department`, `location_id`, `is_active`, `locked_by_user_id`, `locked_by_username`, `locked_at`, `last_activity_at` | Referenced by sales and held sales. A lock is a live session, orthogonal to `is_active` (enabled/deactivated) |
| `pos_held_sales` | Parked carts, server-side, with a real lifecycle (v9, 25 Aug 2026). | `label`, `terminal_id`, `cashier_id`, `cashier_name`, `cart_json` (mediumtext), `item_count`, `total_estimate`, `status`, `resumed_at`, `orphaned_at`, `resulting_txn_id`, `recovered_by`, `recovery_reason`, `recovered_at` | Never hard-deleted — see "Held sales" below |

### Accounting (`acc_*`)

| Table | Purpose | Important fields | Relationships |
|---|---|---|---|
| `acc_accounts` | Chart of accounts. | `code` (unique), `name`, `type` (asset/liability/equity/revenue/expense), `parent_id`, `is_system` (protected from deletion), `is_active`, `deleted_at` | Parent of journal lines |
| `acc_journal` | Journal entry headers. | `entry_no` (unique), `entry_date`, `memo`, `source_type`, `source_id`, **UNIQUE (`source_type`,`source_id`) — the idempotency guard**, `department`, `total_debit`, `total_credit`, `status` (posted/reversed), `reversed_by`, `reversal_of`, `created_by_name` | Parent of journal lines |
| `acc_journal_lines` | Debits and credits. | `journal_id`, `account_id`, `debit`, `credit`, `memo` | FK → journal CASCADE, FK → accounts RESTRICT |
| `acc_expenses` | Expense records. | `reference_no` (unique), `expense_date`, `account_id`, `paid_from_account_id`, `department`, `payee`, `description`, `amount`, `receipt_file` (**never populated — no upload UI**), `journal_id`, `deleted_at` | Links to its journal entry |
| `acc_daily_close` | Immutable end-of-day snapshot. | `close_date` (**unique** — a day can only close once), `opening_float`, `sales_count`, `voided_count`, `gross_sales`, `discounts`, `tax_collected`, `net_sales`, `cost_of_sales`, `gross_profit`, `cash_sales`, `mobile_sales`, `bank_sales`, `card_sales`, `supermarket_sales`, `stationery_sales`, `general_sales`, `cash_expenses`, `expected_cash`, `counted_cash`, `variance`, `notes`, `closed_by_name` | Variance posts a journal entry |

### Source types used in `acc_journal.source_type`

`pos_sale`, `pos_void`, `expense`, `purchase_order`, `po_payment`, `stock_movement`, `daily_close`, `manual`.

---

## 7. Authentication

### PHP sessions

`includes/auth.php` calls `session_start()` if no session is active, so every page that includes it is session-aware. Session keys used:

| Key | Contents |
|---|---|
| `$_SESSION['id']` | `admin.id` |
| `$_SESSION['username']` | `admin.username` |
| `$_SESSION['role']` | `admin.role` |
| `$_SESSION['pos_terminal_id']` | The till this session is working on |
| `$_SESSION['inv_flash']` | `['type' => …, 'msg' => …]` — consumed once, then cleared |

Session configuration is whatever `php.ini` provides; the application overrides nothing (no custom lifetime, no explicit `session_set_cookie_params`).

### Password hashing

`password_hash($password, PASSWORD_BCRYPT)` on create and on change; `password_verify()` on login. Plaintext passwords are never stored, logged or echoed. The `admin.password` column is `VARCHAR(255)`, wide enough for future algorithms.

### Login process

See the flow in [section 3](#authentication-flow). Key points:

- Username validated against `/^[a-zA-Z0-9_.-]{1,50}$/` before the query — a cheap rejection, not the injection defence (that is the prepared statement).
- Failure messages are deliberately identical for "no such user" and "wrong password": *"Incorrect username or password."*
- Status and role are checked **after** the password verifies, so those messages are only shown to someone who already proved they own the account.
- An invalid role (a legacy laundry role) produces a distinct, actionable message rather than a redirect loop.

### Session regeneration

`session_regenerate_id(true)` is called immediately after a successful password login, which defeats session fixation. **It is not called on the remember-me path.**

### Remember-me mechanism

*Rewritten 13 August 2026. See "Previous implementation" below for what this replaced and why.*

Implemented in `includes/remember_me.php` using the **selector/validator** pattern. The cookie (`mira_remember`) holds two random values joined by a colon:

```
<selector>:<validator>          e.g.  a3f1…(32 hex) : 9c2b…(64 hex)
```

| Half | Bytes | Role |
|---|---|---|
| **selector** | 16 (32 hex) | Finds the row. Unique-indexed, stored in the clear. Never secret. |
| **validator** | 32 (64 hex) | The secret. Only its **SHA-256 digest** is stored, compared with `hash_equals()`. |

**Table:** `admin_remember_tokens` — `user_id`, `selector` (unique), `validator_hash`, `user_agent`, `expires_at`, `last_used_at`, `created_at`, with `ON DELETE CASCADE` to `admin`.

**Why SHA-256 and not bcrypt:** the validator is 32 bytes of CSPRNG output, not a guessable password. There is no dictionary for a slow hash to defend against, and this runs on every page load — bcrypt would cost real time and buy nothing. (bcrypt remains correct for *passwords*, where entropy is low.)

**Properties this gives:**

| Property | How |
|---|---|
| Cannot be forged | The cookie names no user and carries no id; a valid token must already exist in the table |
| Cannot be replayed from a database dump | Only digests are stored, and a digest is not a usable cookie |
| Cannot be probed by timing | Lookup is by selector; the secret is compared with `hash_equals()` |
| Short-lived if stolen | The validator **rotates on every use** |
| Theft is detected | A known selector with the wrong validator revokes **every** token for that user and logs it |
| Cannot outlive a revocation | Status and role are re-checked on each use; tokens are destroyed on password change, role change and deactivation |
| Not readable by scripts | `HttpOnly`, `SameSite=Lax`, `Secure` automatically when served over HTTPS |
| Bounded | Max 5 devices per user (oldest dropped); expired rows purged on login |

**Lifecycle:**

| Event | Function | Effect |
|---|---|---|
| Password login with the box ticked | `rememberIssue()` | Issue token, set cookie. **The only place a token is created** |
| Return visit | `rememberValidate()` | Validate → rotate → `session_regenerate_id(true)` → populate session |
| Sign out | `rememberForgetSelector()` | Delete this device's row, expire the cookie |
| Password / role change, deactivation | `rememberForgetUser()` | Delete every row for that user |
| Validator mismatch | `rememberForgetUser()` + `error_log()` | Revoke everything, force a password login |

`session_regenerate_id(true)` is now called on **both** paths — a persistent login is no weaker a session than a typed one.

#### Previous implementation (the vulnerability)

```php
setcookie('remember_admin', json_encode(['username' => $user['username']]),
          time() + (86400 * 30), '/');
```

`login.php` JSON-decoded that cookie, looked the username up, and populated the session **with no password check and no signature of any kind**. Anyone who could set a cookie in their own browser could type `{"username":"admin"}`, load the login page, and be signed in as an administrator. It carried no HMAC, no random token, and nothing binding it to the browser, and it did not regenerate the session id.

The `remember_admin` cookie is **never read** by the current code. `rememberClearCookie()` deletes it on sight, since anyone still holding one is carrying a forgeable credential.

### Logout

`logout.php` deletes the remember token server-side, expires both the current and the legacy remember cookie, empties `$_SESSION`, expires the session cookie itself with the same attributes it was set with (read back from `session_get_cookie_params()`, so it cannot drift from [14.2](#142-session-cookie-hardening)), calls `session_destroy()`, and redirects to `admin/login.php`.

### Authentication guards

Every page's first executable statement after including `auth.php` is `requireRole()` or `requireModule()`. Both check `$_SESSION['username']` and `$_SESSION['role']` are set before checking permissions.

### Self-healing stale roles

`admin/access-denied.php` re-reads the user's real role from the database. If the session role is stale (an administrator changed it mid-session), the session is corrected and the user is redirected to their correct home screen rather than being stuck.

---

## 8. Authorization / RBAC

*(Rewritten 25 August 2026 against the actual current `includes/auth.php` — the previous revision of this section described a 4-role, 18-key model that a since-completed RBAC hardening pass superseded. Verify against `roleModules()` directly if this ever looks stale again; don't assume a key is enforced just because it's listed.)*

### Roles

Five, defined by `allSystemRoles()`:

```php
['admin', 'manager', 'accountant', 'storekeeper', 'cashier']
```

`roleLabel()` maps them to *Administrator*, *Manager*, *Accountant*, *Storekeeper*, *Cashier*.

### Module permissions

`roleModules(string $role): array` returns the module keys a role may use. Twenty-five keys exist:

`dashboard`, `manager_overview`, `pos`, `pos_sales`, `pos_void`, `products`, `inventory`, `purchasing`, `purchasing_approve`, `barcode`, `stock_requests`, `customers`, `accounting`, `accounting_manage`, `sales_reports`, `users`, `settings`, `departments`, `shop_settings`, `disposal_approve`, `expiry_alerts`, `supplier_liabilities`, `fraud_audit`, `terminals`, `held_sales_review`

| Role | Modules |
|---|---|
| `admin` | All twenty-five |
| `manager` | All except `accounting_manage` and `departments` |
| `accountant` | `dashboard`, `accounting`, `accounting_manage`, `sales_reports` — the books, full stop, plus read-only sales figures to reconcile. Deliberately no POS/inventory/terminal access and no `purchasing_approve` (spend authorization is operational, not bookkeeping) |
| `storekeeper` | `products`, `inventory`, `purchasing`, `barcode`, `stock_requests`, `expiry_alerts`, `supplier_liabilities` (read-only, enforced by the page rather than a separate key) — deliberately **not** `purchasing_approve`: raising a PO and receiving goods is the job, approving spend and paying a supplier is a supervisor's decision |
| `cashier` | `pos`, `pos_sales`, `stock_requests`, `expiry_alerts` — no `customers` (the till's own phone-lookup goes through `api/customer-lookup.php`, gated on `pos`, and `posCheckout()`'s own merge logic; the full customer CRUD page is not needed for checkout) |

Two keys are deliberately shared between admin and manager only, never storekeeper or cashier, because they're supervisor surfaces rather than day-to-day work: `terminals` (`admin/pos-terminals.php` — till management, force-release) and `held_sales_review` (`admin/pos-held-sales.php` — reviewing/recovering orphaned or expired held sales; a cashier only ever sees their *own* active ones, and that's enforced in the query itself, not by this key). `fraud_audit` (Cancelled Carts report) and `disposal_approve` follow the same admin+manager sharing rule.

`sales_reports` (the shop-wide Sales report GROUP: summary/by-cashier/by-terminal/transactions) is kept deliberately separate from `pos_sales` (a cashier's own-till-only sales list) so granting one can never silently grant the other — this split, and the new `accountant` role, `purchasing`/`purchasing_approve` split, `barcode` key, and `pos_void` enforcement below, are all part of the same 25 August 2026 hardening pass; see `CLAUDE.md`'s "Roles & access" and "POS additions" sections for the reasoning behind each.

### `userCan()`

```php
function userCan(string $module): bool {
    $role = $_SESSION['role'] ?? '';
    return in_array($module, roleModules($role), true);
}
```

Used both for guards and for conditional rendering — the sidebar wraps every link in a `userCan()` test, so users are not shown links they cannot follow.

### `requireModule()`

```php
function requireModule(string $module) {
    if (!isset($_SESSION['username'], $_SESSION['role'])) {
        authDeny('login.php', 'You are not signed in.');
    }
    if (!userCan($module)) {
        authDeny('access-denied.php', 'Your role does not have access to this.');
    }
}
```

### `requireRole()`

Takes an explicit list of role names. Used where the rule is genuinely about identity rather than capability — `inventory-settings.php` and `manage-users.php` use `['admin','manager']`; `index.php` and `profile.php` use `allSystemRoles()` (any signed-in user).

### `authDeny()` and `authIsApiRequest()`

```php
function authIsApiRequest(): bool {
    return strpos($_SERVER['SCRIPT_NAME'] ?? '', '/api/') !== false;
}
```

`authDeny()` branches on it: an API request gets **401** (not signed in) or **403** (wrong role) with a JSON body `{"ok":false,"message":"…"}`; a page request gets a 302. It also prefixes `../` when the caller is inside `admin/api/`, so the redirect resolves correctly.

### `roleHome()` and `roleHomeUrl()`

`roleHome()` returns a filename inside `admin/`; `roleHomeUrl()` returns the clean URL used after login.

**Clean URLs redirect, they do not rewrite** *(changed 13 August 2026)*. See [§25](#25-url-handling) for what went wrong when they did.

| Role | `roleHome()` | `roleHomeUrl()` |
|---|---|---|
| `admin` | `index.php` | `admin/dashboard` |
| `manager` | `manager-overview.php` | `manager/overview` |
| `accountant` | `accounting-dashboard.php` | `admin/accounting-dashboard.php` (no clean-URL alias of its own yet — the `.htaccess` rewrites are hand-listed per role) |
| `storekeeper` | `inventory-dashboard.php` | `inventory` |
| `cashier` | `pos.php` | `pos/terminal` |

### Complete page-to-guard map

*(Re-verified 25 August 2026 against the actual guard statement in every file — several entries below moved off the general `inventory` key onto their own key during the hardening pass.)*

| Page | Guard |
|---|---|
| `index.php` | `requireRole(allSystemRoles())` — then redirects roles without `dashboard` |
| `profile.php` | `requireRole(allSystemRoles())` |
| `manager-overview.php` | `requireModule('manager_overview')` |
| `pos.php` | `requireModule('pos')` |
| `pos-sales.php`, `pos-receipt.php` | `requireModule('pos_sales')` |
| `pos-terminals.php` | `requireModule('terminals')` |
| `pos-held-sales.php` | `requireModule('held_sales_review')` |
| `retail-products.php` | `requireModule('products')` |
| `manage-customers.php` | `requireModule('customers')` |
| `expiry-alerts.php` | `requireModule('expiry_alerts')` |
| `shop-settings.php` | `requireModule('shop_settings')` |
| `inventory-requests.php` | `requireModule('stock_requests')` — **not** `inventory`; this is the one Inventory-section page a cashier can open |
| `inventory-purchase-orders.php`, `inventory-po-view.php` | `requireModule('purchasing')` |
| `barcode-station.php`, `barcode-labels.php` | `requireModule('barcode')` |
| `inventory-dashboard.php`, `-items`, `-movements`, `-categories`, `-units`, `-suppliers`, `-disposal`, `-reports` | `requireModule('inventory')` |
| `inventory-settings.php` | `requireRole(['admin','manager'])` |
| `manage-users.php` | `requireRole(['admin','manager'])` |
| `accounting-dashboard.php`, `expenses.php`, `journal.php`, `journal-entry.php`, `profit-loss.php`, `z-report.php` | `requireModule('accounting')` |
| `chart-of-accounts.php` | `requireModule('accounting_manage')` |
| `departments.php` | `requireModule('departments')` |
| `api/pos-checkout.php`, `api/pos-cancel-cart.php`, `api/pos-resume-held.php`, `api/pos-terminal-heartbeat.php`, `api/customer-lookup.php` | `requireModule('pos')` |
| `api/pos-held-sale-history.php` | `requireModule('held_sales_review')` |
| `api/inventory-stock-in.php` | `requireModule('inventory')` |
| `api/products-scan.php` | `userCan('pos') \|\| userCan('barcode')` |
| `notifications-api.php` | Session check only; each count is individually gated by `userCan()` |
| `login.php`, `access-denied.php` | None by design |
| `inventory-header/footer.php`, `sidebar-*.php`, `partials/*` | None — includes, never requested directly |

**Secondary, in-page checks** (defence in depth beyond the page guard):

- `pos-sales.php` — voiding requires `userCan('pos_void')` *(25 August 2026 — previously an inline `in_array($role, ['admin','manager'])` check; now a real module key, same enforcement point)*
- `pos-receipt.php` — a non-admin/manager viewer must additionally be the sale's own `cashier_id`, or gets a 403 *(25 August 2026 — closes a receipt IDOR where any cashier could view any receipt by changing the id in the URL)*
- `admin/pos.php`'s `select_terminal` POST handler — a session already holding a *different* active terminal lock is refused outright, server-side, regardless of what the header UI offers *(25 August 2026, see CLAUDE.md "Terminal session-locking")*
- `admin/pos-held-sales.php`'s recovery action — the target cashier is read from the chosen terminal's own live lock (`posTerminalLockState() === 'active'`), never a separately typed account
- `journal.php` — posting and reversing require `userCan('accounting_manage')`
- `inventory-requests.php` — reviewing/approving requires `userCan('inventory')` (raising a request only needs `stock_requests`, which a cashier also has)

### Approval rights in purchasing

*Added 13 August 2026.* Raising a purchase order and committing money to it are different decisions, and they are now held by different people.

| Action | Key checked | admin | manager | storekeeper |
|---|---|---|---|---|
| Raise a purchase order (draft) | `purchasing` | yes | yes | yes |
| Upload the supplier invoice | `purchasing` | yes | yes | yes |
| Receive goods into stock | `purchasing` | yes | yes | yes |
| **Approve a purchase order** | `purchasing_approve` | yes | yes | **no** |
| **Cancel an approved order** | `purchasing_approve` | yes | yes | **no** |
| **Record a payment to a supplier** | `purchasing_approve` | yes | yes | **no** |

Cancelling a **draft** stays open to a storekeeper — nothing has been committed yet. Cancelling an **approved** order reverses a commitment, so it needs the key.

**Enforced server-side, before any handler runs.** `admin/inventory-po-view.php` computes `$canApprove = userCan('purchasing_approve')` and refuses the three actions outright; a refusal writes `denied_<action>` to `inv_audit_log` against the PO. The buttons are also hidden, and a storekeeper sees *"Awaiting approval by a manager"* and *"Only a manager or administrator can record a payment to a supplier"* in their place — but that is a courtesy, not the control. A forged POST from a storekeeper's session is refused identically.

`poRecordPayment()` has **exactly one call site**, so this gate covers every path by which money reaches a supplier. Expenses are a separate route and already sit behind the `accounting` module, which a storekeeper does not have.
### Departments as a second access dimension

Beyond RBAC, `catalog_schema.php` provides a trading switch per department:

- `catalogDepartments($conn)` — **every department this business has defined**, read from `retail_departments` (see [§24](#24-business-configuration)). Not a fixed set since 13 August 2026
- `catalogDepartmentEnabled($conn, $key)` — whether it is currently trading
- `catalogActiveDepartments($conn)` — currently trading
- `catalogDepartmentFilterSql($conn, $alias)` — an `AND … IN (…)` fragment for **operational** queries only
- `catalogSetDepartmentEnabled()` — the toggle, audit-logged

**Design rule, stated in the source and enforced in practice:** the department filter is applied to forward-looking, operational queries (the POS grid, product lists, scan lookups) and **never** to financial history. Filtering past sales or journal entries by a settings toggle would retroactively change last month's reports and break the trial balance.

---

## 9. POS architecture

### POS page (`admin/pos.php`, 1201 lines)

A single self-contained file: PHP controller, inline CSS (the terminal has its own visual language and does not use the admin shell), inline HTML, inline ES6. No sidebar.

Server-side responsibilities on load:

1. `requireModule('pos')`, `posBoot($conn)` (installs all five schemas).
2. **Terminal resolution.** `?terminal=N` sets `$_SESSION['pos_terminal_id']` then redirects (PRG). Otherwise the session value is used; if absent or invalid, the first active terminal is chosen automatically rather than blocking the cashier.
3. **Department resolution.** A till opens on the department it is assigned to, whatever the shop calls it. **Two values mean "open on everything" instead: no department at all, and the general key** — see [§24.7](#247-integration-points) for why that one is special. If the till's department has since been renamed away, removed or disabled, it also falls back to showing everything, rather than an unexplained empty grid. "All Departments" is always the first tab, so the cashier can reach the whole range from any till.
4. Handles `POST action=hold` and `POST action=delete_held`.
5. Loads the full product grid, held sales, tax rate, active departments, categories derived from what is actually on the grid, and today's till summary for this cashier.

### Product loading

`posGetProducts($conn)` loads **every** sellable product in one query — no pagination, no lazy loading. Department and category filtering then happen client-side by toggling CSS on the already-rendered cards, so switching tabs is instant and costs no round-trip mid-queue.

Filter conditions (all must hold):

```sql
i.deleted_at IS NULL AND i.status = 'active'
AND d.is_enabled = 1 AND d.is_pos_visible = 1
AND d.usage_type IN ('sale','both')
AND i.department IN (<currently active departments>)
```

### Cart state

Cart state is a **plain JavaScript array in page memory**:

```js
{ id, name, price, qty, stock }
```

`renderCart()` rebuilds the cart DOM; `totals()` recomputes subtotal, discount, tax and grand total for display. Deliberately holding a sale means POSTing it to `pos_held_sales`, server-side (see "Held sales" below) — that is the durable copy. Separately, the in-progress cart is also debounce-saved to `localStorage` (`mxPosCartShadow_<terminalId>`) purely as a crash/refresh safety net; on load, `checkCartShadow()` offers it back via a "Restore your previous cart?" modal if it's less than 12 hours old, then discards it either way. This shadow copy never reaches the server and is not the same mechanism as a held sale.

### Checkout API

One `fetch()` to `api/pos-checkout.php`. What the browser sends is deliberately minimal:

```js
{
  items: cart.map(l => ({ item_id: l.id, quantity: l.qty })),
  discount: t.discount,
  payments: [{ method, amount }, …],
  terminal_id: <int>,
  held_id: <int|null>,
  customer: { type, name, phone }
}
```

**Item ids and quantities only.** No prices, no totals, no cost. Anything the browser claims about money is ignored.

### Server-side price validation

Inside `posCheckout()`, for each line:

```sql
SELECT i.id, i.name, i.barcode, i.department, i.current_stock, i.average_cost,
       d.selling_price, d.promo_price, d.promo_active, d.is_enabled, d.usage_type
FROM inv_items i
LEFT JOIN retail_product_details d ON d.item_id = i.id
WHERE i.id = ? AND i.deleted_at IS NULL AND i.status = 'active'
FOR UPDATE
```

The price comes from `posUnitPrice()` → `retailEffectivePrice()`, which returns the promo price when `promo_active` is set, otherwise the selling price. Tax is applied from `pos_tax_rate` / `pos_tax_inclusive`, read from settings on the server.

### Stock validation

Each line is checked against the **locked** row:

```php
if ($qty > (float)$product['current_stock'] + 0.0005) {
    throw new Exception('Not enough stock for "' . $product['name'] . '". Available: …');
}
```

The `0.0005` tolerance accommodates `DECIMAL(14,3)` rounding. Deduction then goes through `recordStockMovement()`, which re-locks and re-checks — so overselling is impossible even under concurrency.

Three further per-line checks: the product must still exist and be active; it must be enabled with `usage_type` in (`sale`,`both`); and its department must currently be trading (re-checked here because a cart could have been built or held before an administrator switched the department off).

### Transaction processing — the integrity guarantee

`posCheckout()` opens **one** transaction and does everything inside it:

```
begin_transaction()
  ├─ FOR EACH line: SELECT … FOR UPDATE  (row lock held to commit)
  │    validate existence, sellability, department, stock
  │    resolve price and cost from the database
  ├─ compute subtotal, discount, tax, total, cost, gross profit
  ├─ validate tenders (electronic ≤ total; tendered ≥ total)
  ├─ resolve or create the customer
  ├─ INSERT sales_transactions        (retry once on receipt-no collision)
  ├─ FOR EACH line:
  │    INSERT sales_transaction_items
  │    recordStockMovement(…, 'issue', -qty, …, ownTransaction: false)
  ├─ FOR EACH tender: INSERT sales_payments
  ├─ accPostSale(…, ownTransaction: false)   ← ledger
  └─ invAudit(…)
commit()            — or —      rollback() on any Throwable
```

Every nested call receives `ownTransaction: false`, so there is exactly one transaction boundary. **If the ledger cannot take the sale, the sale does not happen.** There is no path that leaves takings on the till but missing from the books, or stock deducted without a sale.

Concurrency: because the `FOR UPDATE` locks are held until commit, a second till selling the same last unit blocks, then re-reads the true stock and fails cleanly with a message — rather than writing a negative quantity.

### Payment handling

`posPaymentMethods()` maps each method to its ledger account:

| Method | Label | Icon | Account |
|---|---|---|---|
| `cash` | Cash | `fa-money-bill-wave` | 1000 |
| `lipa_namba` | Lipa Namba/Mobile | `fa-mobile-screen` | 1010 |
| `bank` | Bank transfer | `fa-building-columns` | 1020 |
| `card` | Card | `fa-credit-card` | 1020 |

Validation rules:

- Only recognised methods with a positive amount become tenders.
- `nonCashTendered > total` → refused. Change can only come from the drawer, so an electronic overpayment would create change the till cannot give.
- `amountPaid < total` → refused, with the shortfall named.
- `payment_method` on the header is the single method, or `'split'` for a mixed tender.

The API is backward-compatible: a legacy caller may still send `payment_method` + `amount_paid` instead of a `payments` array. On that path a non-cash single tender is forced to settle exactly the total.

**Ledger posting is net of change.** Change leaves the drawer, so debiting the full cash tendered would overstate Cash on Hand by exactly the change:

```php
$changeLeft = $changeDue;
foreach ($tenders as $t) {
    $amount = $t['amount'];
    if ($t['method'] === 'cash' && $changeLeft > 0) {
        $deduct = min($amount, $changeLeft);
        $amount = round($amount - $deduct, 2);
        $changeLeft = round($changeLeft - $deduct, 2);
    }
    if ($amount > 0) { $ledgerTenders[] = ['method' => $t['method'], 'amount' => $amount]; }
}
```

`posVoidSale()` mirrors this exactly when refunding.

### Receipt generation

`admin/pos-receipt.php` (`requireModule('pos_sales')`) renders an 80 mm thermal layout with its own print CSS, and prints itself on load. Also used to reprint from the sales list. `posGetSale()` returns the header, lines and tenders.

Receipt numbers: `posGenerateReceiptNo()` produces `MRT-YYYYMMDD-NNNXXX` where `NNN` is the daily sequence and `XXX` is three random alphanumerics — so a customer cannot guess another receipt's number from their own. `receipt_no` is unique; on a 1062 collision the insert retries once with a fresh number.

### Held sales

*(Rewritten 25 August 2026 — the held-sale feature was fully re-architected for ownership, terminal isolation and a real lifecycle; the previous "any terminal can resume any held sale, checkout deletes the row" description no longer applies to any part of the system.)*

`pos_held_sales` stores the cart as JSON server-side, keyed to `cashier_id` + `terminal_id`. A held sale is **never hard-deleted** — `posDeleteHeldSale()` no longer exists — every terminal state is a `status` update instead:

```
held → {stale → expired}
held/stale → resumed → completed
held/stale → cancelled
held/stale/resumed → orphaned (cashier logout)
orphaned/expired → held (manager recovery, or the same cashier's own next login)
```

"Voided" is not a stored status: when a sale that came from a held sale is later voided, `posVoidSale()` cross-references it and logs a `held_sale_voided` event against the original row (its `status` correctly stays `completed`).

| Function | Does |
|---|---|
| `posGetHeldSalesForCashier($conn, $cashierId, $terminalId)` | The **only** reader `admin/pos.php` uses — scoped to that cashier on that terminal, `status IN ('held','stale')`. Another cashier's cart never reaches the browser |
| `posResumeHeldSale()` | `SELECT ... FOR UPDATE` + ownership check against the session's own cashier/terminal (never client-supplied) + status check, then flips to `resumed`. Serializes concurrent resume attempts on the same row |
| `posCompleteHeldSale()` | Called from `api/pos-checkout.php` after a successful sale; flips `resumed → completed` and sets `resulting_txn_id`. A mismatch here (forged `held_id`) never fails the already-completed sale — it only skips the link and logs `held_sale_link_mismatch` |
| `posOrphanHeldSalesForCashier()` | Called from `logout.php`; flips a cashier's own `held`/`stale`/`resumed` rows to `orphaned`. Never deletes, never hands them to whoever logs in next |
| `posReclaimOwnOrphanedHeldSales()` | Called from `admin/pos.php`'s terminal-claim handler; if the **same** cashier who orphaned a sale claims a terminal again, it silently returns to `held`. A different cashier claiming the till in between never triggers this |
| `posRecoverHeldSale()` | Manager/admin action (`admin/pos-held-sales.php`, key `held_sales_review`) reassigning an `orphaned`/`expired` row to a **currently-active** cashier — read from that terminal's own live lock, never typed in — and reopening it as `held`. Stamps `recovered_by`/`recovery_reason`/`recovered_at` |
| `posSweepHeldSales()` | Lazy, on-read staleness/expiry sweep (`held_sale_stale_minutes`/`held_sale_expiry_minutes` in `inv_settings`, defaults 30/120) — the same idiom as the batch-expiry lazy recompute elsewhere in the app |
| `posGetHeldSaleAuditTrail()` | Every `inv_audit_log` row for one held sale, oldest first — the workflow timeline shown in `admin/pos-held-sales.php`'s Recover modal and History button (`api/pos-held-sale-history.php`) |

**Audit events** (all `entity_type = 'held_sale'` in `inv_audit_log`): `held_sale_created`, `held_sale_resumed`, `held_sale_completed`, `held_sale_cashier_logout`, `held_sale_orphaned`, `held_sale_reclaimed`, `held_sale_stale`, `held_sale_expired`, `held_sale_cancelled`, `held_sale_voided`, `held_sale_manager_recovery`, `held_sale_link_mismatch`.

### Voiding

`posVoidSale()` — one transaction; locks the sale row `FOR UPDATE`; returns every line's stock as a `return` movement; reads the original tenders from `sales_payments`, applies the same change-netting, and posts the reversal via `accPostSaleVoid()`; flags the header `voided`. **Idempotent** — voiding an already-voided sale commits and reports *"This sale was already voided."* Since 25 August 2026 it also checks `pos_held_sales.resulting_txn_id` for a match and logs `held_sale_voided` against the originating held sale, if any (see "Held sales" above).

---

## 10. Barcode system

### Scanner input model

Hardware USB and Bluetooth barcode scanners present themselves as keyboards: they "type" the code very fast and finish with Enter. The terminal exploits the *speed*, which is what distinguishes a scanner from a human.

### Global keyboard listener

In `admin/pos.php`:

```js
const SCAN_MAX_GAP_MS = 45;   // scanners send chars far faster than this
let scanBuffer = '';
let lastKeyTime = 0;

document.addEventListener('keydown', (e) => {
    const el = document.activeElement;
    const inEditable = el && (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' || el.tagName === 'SELECT');

    if (e.key === 'F2') { e.preventDefault(); focusScanner(); return; }

    if (inEditable && el.id !== 'scanInput') return;   // typing a discount etc.

    const now = Date.now();
    if (e.key === 'Enter') {
        if (el === scanInput) {
            e.preventDefault();
            const code = scanInput.value.trim();
            scanInput.value = '';
            applyFilter();
            if (code) handleScan(code);
        } else if (scanBuffer.length >= 4) {
            e.preventDefault();
            const code = scanBuffer;
            scanBuffer = '';
            handleScan(code);
        }
        return;
    }

    if (el === scanInput) return;  // the input collects its own text

    if (e.key.length === 1) {
        if (now - lastKeyTime > SCAN_MAX_GAP_MS) scanBuffer = '';
        scanBuffer += e.key;
        lastKeyTime = now;
    }
});
```

**`SCAN_MAX_GAP_MS = 45`.** Any gap longer than 45 ms between characters resets the buffer. A human typing cannot sustain sub-45 ms intervals; a scanner is far faster. This is what makes "scan anywhere on the page" safe — a cashier typing a discount amount never accidentally triggers a lookup.

**Minimum buffer length of 4** before a buffered Enter counts as a scan, filtering out stray keystrokes.

**Focus discipline.** The scan box is re-focused on page load, on every click on empty space (anything not an input, select, textarea, button, link or modal), after every sale, and by a 2-second interval whenever `document.activeElement` has fallen back to `<body>`.

**F2** works from anywhere, including from inside other inputs, because it is handled before the `inEditable` early return.

### Two-tier lookup in `handleScan()`

1. **Local fast path** — searches the already-rendered `.prod-card` elements by their `data-search` attribute. No network call, instant.
2. **Server path** — `fetch('api/products-scan.php?barcode=…')`.

The response's `message` field explains *why* something cannot be sold ("department not trading", "not set up for sale", "out of stock") rather than a generic refusal — the cashier is told what to do instead of hunting.

### Barcode station (`admin/barcode-station.php`)

The same listener pattern, `requireModule('inventory')`, three modes:

| Mode | Action | Movement type |
|---|---|---|
| **Stock In** | Scan; the configured quantity is added. Optional unit cost updates weighted-average cost | `receive` |
| **Physical Audit** | Scan; enter the counted quantity. The difference is written | `adjust` |
| **Assign Barcodes** | Attach a barcode to an item that has none | — |

Stock In and Audit both call `POST admin/api/inventory-stock-in.php` (`mode: 'add'` / `'set'`).

`posAssignBarcode()` validates against `/^[A-Za-z0-9\-\.\ \$\/\+\%]{4,80}$/` — the Code128-encodable character set — and enforces uniqueness with a message naming the conflicting product rather than surfacing a database error.

`posGenerateBarcode()` produces internal codes as `MRT` + 9 digits, looping until an unused one is found. A bulk-generate action assigns them to every item lacking a barcode.

### Barcode labels (`admin/barcode-labels.php`)

Generates printable **Code128-B** sticker sheets. The barcodes are rendered **client-side as inline SVG** — no external library, no internet connection required. Choose items, label size and copy count, then print to A4 sticker sheets or a dedicated label printer.

### Product lookup

`posFindByBarcode($conn, $code, $allowSku = true)` matches `i.barcode = ?` or, by default, `i.sku = ?`. Both columns are uniquely indexed, so this remains a single indexed row lookup regardless of catalogue size.

---

## 11. Inventory architecture

### Stock quantities

`inv_items.current_stock` (`DECIMAL(14,3)`) is authoritative. `average_cost` (`DECIMAL(14,4)`) is the weighted-average cost used for COGS, valuation and write-offs.

### `recordStockMovement()` — the single gate

**Every** stock change in the entire system goes through this one function in `includes/inventory_functions.php`. Nothing else writes `current_stock`.

```php
function recordStockMovement(
    mysqli $conn,
    int $itemId,
    string $movementType,      // receive|issue|transfer|damage|expire|loss|adjust|return|opening
    float $signedQty,          // positive increases, negative decreases
    ?int $userId,
    string $reason = '',
    float $unitCost = 0.0,
    ?int $orderId = null,
    ?string $referenceType = null,
    ?int $referenceId = null,
    bool $ownTransaction = true
): array                        // [ok, message, qtyAfter]
```

What it does, in order:

1. Validates the movement type and rejects a zero quantity.
2. `SELECT current_stock, average_cost … FOR UPDATE` — **locks the item row** for the rest of the transaction.
3. Computes `after = before + signedQty`; throws if negative.
4. **Weighted-average cost** — recalculated only on positive `receive` / `opening` movements that carry a unit cost:
   ```php
   $totalValue = ($before * $avgCost) + ($signedQty * $unitCost);
   $avgCost = $after > 0 ? ($totalValue / $after) : $unitCost;
   ```
5. Updates `inv_items` (and `last_purchased_at` + `purchase_price` when a cost was supplied).
6. Inserts the `inv_stock_movements` audit row with `qty_before`, `qty_after`, cost, references, reason and user.
7. `require_once` **at runtime** of `stock_ledger.php`, then posts to the ledger. A movement with no explicit unit cost is valued at the item's weighted-average cost — writing stock off at zero would hide the loss entirely.
8. If the ledger posting fails, the whole thing throws and rolls back.

**`ownTransaction`** must be `false` when called inside a larger transaction (POS checkout, PO receiving, stock-request approval), so there is one transaction boundary rather than nested ones.

### Movement types

| Type | Sign | Raised by |
|---|---|---|
| `receive` | + | PO receiving, Barcode Station stock-in, manual movement |
| `issue` | − | POS sale, stock-request approval, manual movement |
| `adjust` | ± | Physical audit, manual adjustment |
| `damage` / `expire` / `loss` | − | Manual movement |
| `return` | + or − | POS void (returns stock), manual return-to-supplier (removes stock) |
| `opening` | + | Opening stock |
| `transfer` | — | Enum value exists; **nothing in the UI creates transfers** |

> **Documented risk in the source:** `inv_items.current_stock` is a single global quantity with no per-location breakdown. A transfer would therefore have to be recorded as a matched out/in pair or it would quietly destroy stock value. Nothing creates transfers today, and `stock_ledger.php` explicitly gives transfers no ledger effect.

### Receiving

`poReceiveStock()` in `purchasing_functions.php`:

1. Loads the PO; refuses unless status is `approved` or `partially_received`.
2. Opens one transaction.
3. Per line: caps the received quantity at what is outstanding, calls `recordStockMovement(… 'receive', +qty, …, 'purchase_order', $poId, false)` passing the unit price so weighted-average cost updates, then increments `received_qty`.
4. Writes an `inv_po_receipts` row (the goods-received note).
5. Posts `accPostGoodsReceipt()` — DR Inventory Asset / CR Accounts Payable.
6. Recomputes the PO status.

### Stock requests

`createStockRequest()` and `reviewStockRequest()`. Requests are for internal consumption. Stock is deducted **only for approved quantities**, as `issue` movements — which the ledger books to *Store Consumables Used*, recognising the cost when the stock is consumed rather than when something sells.

### Settings and audit

- `getInvSetting()` / `setInvSetting()` — the key/value store, also holding every schema version
- `invAudit()` — writes `inv_audit_log`
- `invQty()` / `invMoney()` / `invCurrency()` — display formatting

---

## 12. Accounting architecture

### Chart of accounts

`acc_accounts`, five types with a normal side:

| Type | Normal balance |
|---|---|
| asset | debit |
| liability | credit |
| equity | credit |
| revenue | credit |
| expense | debit |

`accSystemAccounts()` returns the fourteen codes the automatic postings depend on (listed in the User Guide, section 9). Accounts flagged `is_system` may be renamed but never deleted or deactivated. Non-system accounts follow the in-use rule: delete if never used, deactivate otherwise.

### Journals and journal lines

`accPostEntry()` is the only way anything reaches the ledger.

```php
accPostEntry(
    mysqli $conn,
    array $lines,               // [['account' => code|id, 'debit'|'credit' => float, 'memo' => string], …]
    string $memo,
    string $sourceType = 'manual',
    ?int $sourceId = null,
    ?int $userId = null,
    string $userName = '',
    string $department = 'general',
    ?string $entryDate = null,
    bool $ownTransaction = true
): array                        // [ok, entryNoOrMessage, journalId]
```

Integrity rules it enforces before writing anything:

1. **Account resolution.** `'account'` may be a string CODE or an int ID. Both are validated against the chart; an unknown one is rejected with a readable message rather than an opaque foreign-key failure.
2. **No line may be both a debit and a credit.**
3. **At least two lines** must survive (zero-amount lines are dropped).
4. **Debits must equal credits** to within half a cent, or nothing is written: *"Entry does not balance: debits X vs credits Y."*
5. **Idempotency.** `UNIQUE (source_type, source_id)` on `acc_journal`. A 1062 on insert is recognised as the guard working and reported as *"This transaction has already been posted to the ledger."*

> **PHP gotcha worth knowing.** PHP coerces numeric-string array keys to integers. Code that builds a per-account map keyed by account code (`$byAccount['1000']`) gets back an **int** `1000`, which `accPostEntry()` would then treat as an account *ID* rather than a *code*. The multi-tender posting paths cast explicitly — `['account' => (string)$code, …]` — and `accAccountExists()` validates the int path. Any new code that aggregates by account code must do the same.

### Reversal, not editing

`accReverseEntry()` writes the mirror-image entry, sets the original's `status = 'reversed'` and `reversed_by`, and stamps the new entry's `reversal_of`. The original is never modified or deleted. Reversing an already-reversed entry is refused.

### Automatic postings

| Trigger | Function | Entry |
|---|---|---|
| POS sale | `accPostSale()` | DR each tender account (net of change) · CR Sales Revenue · CR Taxes Payable (if any) · DR COGS · CR Inventory Asset |
| POS void | `accPostSaleVoid()` | The mirror of the above, refunding each tender to the account it landed in |
| PO goods receipt | `accPostGoodsReceipt()` | DR Inventory Asset · CR Accounts Payable |
| Supplier payment | `accPostSupplierPayment()` | DR Accounts Payable · CR the paying account |
| Expense | `accRecordExpense()` | DR the expense account · CR the paying account |
| Daily close variance | inside `accCloseDay()` | Short: DR Stock Loss & Shrinkage · CR Cash. Over: DR Cash · CR Other Income (4900) |
| Stock movement | `stockPostMovementToLedger()` | See table below |

### Stock-to-ledger integration (`includes/stock_ledger.php`)

| Movement | Debit | Credit |
|---|---|---|
| `damage` / `expire` / `loss` | Stock Loss & Shrinkage (5100) | Inventory Asset (1200) |
| `adjust` (count down) | Stock Loss & Shrinkage | Inventory Asset |
| `adjust` (count up) | Inventory Asset | Stock Loss & Shrinkage |
| `issue` (internal use) | Store Consumables Used (5200) | Inventory Asset |
| `receive` (no PO) | Inventory Asset | Accounts Payable (2000) |
| `return` (to supplier) | Accounts Payable | Inventory Asset |
| `opening` | Inventory Asset | Owner's Capital (3000) |
| `transfer` | — no ledger effect — | |

Two design decisions worth noting:

- **A count surplus is credited against shrinkage, not booked as income.** The account then shows *net* stock loss over a period rather than two unrelated-looking numbers.
- **Movements already posted in aggregate by a higher layer are skipped**, or the same money would be counted twice. `stockLedgerSkippedReferences()` returns `['pos_sale', 'purchase_order']` — the till posts one entry per sale, and PO receiving posts one entry per goods receipt.

`source_id` for a stock-movement entry is the movement's own id, so each movement posts exactly once and a retry after a failure cannot double-count.

### Balances, trial balance, ledger

- `accAccountBalance($conn, $accountId, $from, $to)` — summed from `acc_journal_lines`, signed by the account's normal side
- `accTrialBalance()` — every account with a balance; debits and credits must equal
- `accLedgerLines()` — one account's lines over a period with a running balance
- **No balance is ever stored.** Every figure is derived at read time.

### Daily close

`accDayFigures($conn, $date)` computes the day from source data:

- Sale headers → count, gross sales, discounts, tax, net sales
- **`sales_payments` grouped by method** → cash / mobile / bank / card. Read per-tender, so a split sale contributes to both columns rather than landing wholly under whichever method the header records.
- **Less total `change_due`** from the cash figure — change leaves the drawer, so without this every note-paid sale would overstate expected cash.
- Voided count
- Cost of sales and the departmental split, computed **per line** from `inv_items.department`, so a sale spanning two departments splits correctly
- Cash expenses paid from account 1000
- `gross_profit = net_sales − tax − cost_of_sales`
- `expected_cash = opening_float + cash_sales − cash_expenses`

`accCloseDay()` refuses a future date and refuses a second close (`close_date` is unique), snapshots all figures into `acc_daily_close`, and posts the variance. **A closed day cannot be reopened** — there is no reopen function.

### Profit and loss

`accProfitAndLoss($conn, $from, $to)` reads from the ledger, not from the till. That means it includes sales, COGS, expenses, stock write-offs, cash variances and any manual corrections — everything that touched the books.

### Accounting integrity rules — summary

1. Unbalanced entries are never written.
2. `UNIQUE (source_type, source_id)` makes double-posting impossible.
3. History is corrected by reversal, never by editing.
4. Balances are always derived, never stored.
5. System accounts cannot be deleted or deactivated.
6. Financial history is never filtered by the department toggle.
7. **The invariant:** Inventory Asset (1200) === `SUM(current_stock × average_cost)`.
8. A closed day is immutable.
9. Business writes and their ledger entries share one transaction — both or neither.

---

## 13. API documentation

*(Updated 25 August 2026 — four new endpoints added for terminal session-locking and held-sale ownership; see §13.4–§13.7.)*

Eight JSON endpoints under `admin/api/`, plus `admin/notifications-api.php`. All are session-authenticated; none accept an API key or token; all are same-origin only. Not documented in detail below: `admin/api/customer-lookup.php` (`requireModule('pos')`, phone-number lookup/autofill for checkout).

---

### 13.1 `GET admin/api/products-scan.php`

**Purpose:** indexed barcode or SKU lookup for the POS till and the Barcode Station. Returns item details, live stock, and the price the till should charge.

**Authentication:** signed-in session with `pos` **or** `barcode`. Either grants access — a storekeeper has no POS rights but must still be able to scan an item in.

**Parameters (query string):**

| Name | Type | Required | Notes |
|---|---|---|---|
| `barcode` | string | yes | Matched against `inv_items.barcode`, falling back to `sku` |

**Success — product found (HTTP 200):**

```json
{
  "ok": true,
  "found": true,
  "product": {
    "id": 12, "name": "Azam Cola 500ml", "sku": "SM-BEV-001",
    "barcode": "6001234500011", "category": "Beverages", "unit": "btl",
    "stock": 130.0, "price": 1000.0, "base_price": 1000.0, "promo": false,
    "image": "../assets/uploads/shop_products/p2_1786185521_0.jpg",
    "sellable": true, "pos_visible": true,
    "department": "supermarket", "dept_trading": true,
    "average_cost": 700.0
  },
  "message": "ok"
}
```

`message` explains *why* when the item cannot be sold — `"The Stationery department is not currently trading."`, `"This item is not set up for sale (check Products & Prices)."`, or `"Out of stock."`

**Not found (HTTP 200):**

```json
{ "ok": true, "found": false,
  "message": "No product matches barcode \"123456\".", "barcode": "123456" }
```

**No barcode supplied (HTTP 200):**

```json
{ "ok": false, "found": false, "message": "No barcode supplied." }
```

**Unauthenticated:** HTTP 401 `{"ok":false,"message":"You are not signed in."}`
**Wrong role:** HTTP 403 `{"ok":false,"message":"Your role does not have access to this."}`

> Note: "not found" returns `ok: true` with `found: false` — the *request* succeeded. Only a malformed request sets `ok: false`.

---

### 13.2 `POST admin/api/pos-checkout.php`

**Purpose:** atomic POS checkout. Validates stock, records the sale, deducts stock under row locks, posts the ledger entry, and returns the receipt payload.

**Authentication:** signed-in session with `pos`.

**Content type:** JSON body, or classic form-encoded (`$_POST`) as a fallback.

**Request:**

| Field | Type | Required | Notes |
|---|---|---|---|
| `items` | array | yes | `[{item_id:int, quantity:float, line_discount?:float}, …]`. May also be a JSON string. Lines with a non-positive id or quantity are dropped |
| `discount` | float | no | Order-level discount amount, capped at the subtotal |
| `payments` | array | preferred | `[{method:'cash'\|'lipa_namba'\|'bank'\|'card', amount:float, reference?:string}, …]` |
| `payment_method` | string | legacy | Single-tender alternative to `payments` |
| `amount_paid` | float | legacy | Used with `payment_method` |
| `customer` | object | no | `{type:'cash'\|'registered', name?:string, phone?:string}` |
| `terminal_id` | int | no | Falls back to `$_SESSION['pos_terminal_id']` |
| `held_id` | int | no | The held sale this cart was resumed from, if any. **Never deleted** (25 Aug 2026) — `posCompleteHeldSale()` links it (`status → 'completed'`, `resulting_txn_id` set), re-checking ownership against the *session's own* cashier/terminal, not this field. A mismatch only skips the link; it does not fail the sale, which has already committed by this point |
| `note` | string | no | |

**Prices, totals, tax and cost are never taken from the request.** They are recomputed server-side from the database.

**Success (HTTP 200):**

```json
{
  "ok": true,
  "receipt_no": "MRT-20260813-001A7K",
  "transaction_id": 5,
  "receipt": {
    "receipt_no": "MRT-20260813-001A7K",
    "created_at": "2026-08-13 14:22:07",
    "cashier": "admin",
    "customer_type": "cash", "customer_name": null, "customer_phone": null,
    "subtotal": 12000.0, "discount": 0.0,
    "tax_rate": 0.0, "tax_amount": 0.0,
    "total": 12000.0, "amount_paid": 20000.0, "change_due": 8000.0,
    "payment_method": "cash",
    "payments": [{ "method": "cash", "amount": 20000.0 }],
    "items": [
      { "name": "Azam Cola 500ml", "quantity": 2.0,
        "unit_price": 1000.0, "discount": 0.0, "line_total": 2000.0 }
    ]
  },
  "print_url": "pos-receipt.php?id=5",
  "message": "Sale completed."
}
```

**Business failure (HTTP 200):**

```json
{ "ok": false, "message": "Not enough stock for \"Azam Cola 500ml\". Available: 1.000." }
```

The whole transaction has been rolled back — no stock moved, no sale recorded, no ledger entry. Other messages: `"The cart is empty."`, `"A product in the cart is no longer available."`, `"\"X\" is not available for sale."`, `"\"X\" is in the Stationery department, which is not currently trading."`, `"Enter how the customer is paying."`, `"Electronic payment (N) is more than the total (M). Reduce it, or take the difference in cash."`, `"Short by N. Total is M, tendered K."`, `"Ledger posting failed: …"`

**Wrong method:** HTTP 405 `{"ok":false,"message":"POST required."}`
**Unauthenticated:** HTTP 401 · **Wrong role:** HTTP 403

---

### 13.3 `POST admin/api/inventory-stock-in.php`

**Purpose:** receive stock or record a physical count by scanning a barcode. Row-locked, written through the shared stock-movement audit trail.

**Authentication:** signed-in session with `inventory`.

**Request (JSON or form-encoded):**

| Field | Type | Required | Notes |
|---|---|---|---|
| `barcode` | string | one of | Preferred — comes from a scan |
| `item_id` | int | one of | Used when no barcode |
| `quantity` | float | yes | Amount received (`add`), or the counted quantity (`set`) |
| `mode` | string | no | `add` (default) or `set` (physical count) |
| `unit_cost` | float | no | Updates the item's weighted-average cost |
| `reason` | string | no | Free text stored on the movement |

**Success (HTTP 200):**

```json
{ "ok": true,
  "item": { "id": 12, "name": "Azam Cola 500ml", "barcode": "6001234500011", "stock": 142.0 },
  "message": "Azam Cola 500ml: stock now 142.000." }
```

In `set` mode the message reads `"…: adjusted to 140.000."`, or when the count already matches: `"…: count matches system stock (140.000) - no change."` (nothing is written).

**Barcode not recognised (HTTP 200):**

```json
{ "ok": false, "not_found": true, "barcode": "999",
  "message": "No product matches barcode \"999\". Assign it to an item first." }
```

**Other failures (HTTP 200):** `{"ok":false,"message":"…"}` — `"No item specified."`, `"Quantity must be greater than zero."`, `"Counted quantity cannot be negative."`, `"Item not found."`, `"Not enough stock. Available: …"`, `"Ledger posting failed: …"`

**Wrong method:** HTTP 405 · **Unauthenticated:** HTTP 401 · **Wrong role:** HTTP 403

---

### 13.4 `POST admin/api/pos-cancel-cart.php`

**Purpose:** records a cart that never became a sale — the live in-progress cart cleared, or a held sale discarded — with a mandatory reason. The one path that closes out a held sale without completing it.

**Authentication:** signed-in session with `pos`.

**Request (JSON):**

| Field | Type | Required | Notes |
|---|---|---|---|
| `source` | string | yes | `'live_cart'` or `'held_sale'` |
| `held_sale_id` | int | if `source='held_sale'` | Ownership-checked against the session's own cashier/terminal — **not** the `terminal_id` field below |
| `items` | array | yes | `[{item_id, name, qty}, …]` — for `held_sale` source, the row's own stored `cart_json` is used instead (25 Aug 2026), so a cashier cannot report fabricated contents for what they're cancelling |
| `total` | float | yes | Same override rule as `items` for `held_sale` source |
| `reason_code` | string | yes | One of the UI's dropdown values |
| `reason_detail` | string | if `reason_code='other'` | |
| `terminal_id` | int | no | **Session-derived only as of 25 Aug 2026** — this field is accepted but ignored for the ownership check |

**Success (HTTP 200):** `{ "ok": true, "message": "Cancellation recorded." }`

**Failure (HTTP 200):** `{"ok":false,"message":"…"}` — `"A cancellation reason is required."`, `"That held sale no longer exists."`, `"That held sale does not belong to you on this till."` (25 Aug 2026 — closes an IDOR where a client-supplied `held_sale_id` for another cashier's row was accepted with no check), `"This held sale is completed and cannot be cancelled from here."` (or whatever status it is)

**Wrong method:** HTTP 405 · **Unauthenticated:** HTTP 401 · **Wrong role:** HTTP 403

---

### 13.5 `POST admin/api/pos-resume-held.php`

*Added 25 August 2026.*

**Purpose:** the ownership + concurrency gate for resuming a held sale. The only way a held sale's `cart_json` ever reaches a browser — `admin/pos.php`'s list is already scoped to the caller's own rows, and this endpoint re-checks ownership again server-side before returning anything.

**Authentication:** signed-in session with `pos`.

**Request (JSON):**

| Field | Type | Required | Notes |
|---|---|---|---|
| `held_sale_id` | int | yes | Checked against `$_SESSION['id']` / `$_SESSION['pos_terminal_id']` — never a client-supplied cashier/terminal id |

**Success (HTTP 200):**

```json
{ "ok": true, "message": "Held sale resumed.",
  "cart": [{ "id": 12, "name": "Azam Cola 500ml", "price": 1000, "qty": 2 }] }
```

**Failure (HTTP 200):** `{"ok":false,"message":"…"}` — `"That held sale no longer exists."`, `"That held sale does not belong to you on this till."`, `"This held sale is resumed and cannot be resumed."` (or whatever status it already is — also what a genuine concurrent double-resume attempt sees, since `SELECT ... FOR UPDATE` serializes the two attempts rather than letting them race)

**Wrong method:** HTTP 405 · **Unauthenticated:** HTTP 401 · **Wrong role:** HTTP 403

---

### 13.6 `POST admin/api/pos-terminal-heartbeat.php`

*Added 25 August 2026.*

**Purpose:** keeps a claimed till's lock alive. Called every 45 seconds by `admin/pos.php` while a cashier has a terminal open.

**Authentication:** signed-in session with `pos`.

**Parameters:** none — the terminal id comes from `$_SESSION['pos_terminal_id']`, never the request body.

**Success (HTTP 200):** `{ "ok": true }` — `last_activity_at` advanced.

**Lock lost (HTTP 200):** `{ "ok": false }` — this session no longer holds the terminal it thinks it does (force-released by a manager, or reclaimed as stale by another cashier after the configured timeout). The client stops the heartbeat and re-shows the "Select a Till" modal; the in-progress cart is untouched.

**Wrong method:** HTTP 405 · **Unauthenticated:** HTTP 401 · **Wrong role:** HTTP 403

---

### 13.7 `GET admin/api/pos-held-sale-history.php`

*Added 25 August 2026.*

**Purpose:** the full `inv_audit_log` workflow timeline for one held sale, oldest first — what `admin/pos-held-sales.php` shows a manager before deciding whether/how to recover it.

**Authentication:** signed-in session with `held_sales_review` (admin/manager only).

**Parameters (query string):**

| Name | Type | Required | Notes |
|---|---|---|---|
| `id` | int | yes | The held sale's id |

**Success (HTTP 200):**

```json
{ "ok": true, "trail": [
  { "action": "held_sale_created", "label": "Created", "details": "jumja",
    "created_at": "2026-08-25 10:08:27", "username": "test" },
  { "action": "held_sale_stale", "label": "Marked ageing", "details": "No activity for over 30 minute(s).",
    "created_at": "2026-08-25 11:37:49", "username": "System" }
] }
```

`username` reads `"System"` for a lazy-sweep event (stale/expired), which has no attributable user. Rendered client-side with `createElement`/`textContent`, never `innerHTML`, since `details` can contain free text a cashier or manager typed (cancellation/recovery reasons).

**Unauthenticated:** HTTP 401 · **Wrong role:** HTTP 403

---

### 13.8 `GET admin/notifications-api.php`

**Purpose:** live counts for the sidebar badges. Polled every 30 seconds by `MX.watchAlerts()`.

**Authentication:** signed-in session. **Each key is individually gated** by the caller's permissions, so a role is never told a count it may not see.

**Parameters:** none.

**Success (HTTP 200):** keys present only when permitted.

```json
{ "low_stock": 3, "requests": 1, "open_pos": 2, "unclosed_days": 1 }
```

| Key | Requires | Counts |
|---|---|---|
| `low_stock` | `inventory` | Active items with `current_stock <= 0` or at/below `reorder_level` |
| `requests` | `stock_requests` | `inv_stock_requests` with status `pending` |
| `open_pos` | `purchasing` | POs in draft / approved / partially received |
| `unclosed_days` | `accounting` | Distinct past dates with completed sales but no `acc_daily_close` row |

**Unauthenticated:** HTTP 401 `{"error":"Not signed in."}`

*(This endpoint lives in `admin/`, not `admin/api/`, so it does its own session check rather than using `authDeny()`.)*

---

## 14. Security

### 14.1 CSRF protection

*Added 13 August 2026 (`includes/csrf.php`).*

**The attack it prevents.** Every page is authenticated by a session cookie, and the browser attaches that cookie to any request to this origin — including one triggered by a different site the user has open. Without a token, a page anywhere on the internet could contain a hidden auto-submitting form aimed at `manage-users.php`, and a manager who merely visited it while signed in would silently create an administrator. The same trick could void sales, close the day, delete records or post journal entries.

**The mechanism.** A 32-byte random token is generated once per session and held in `$_SESSION['csrf_token']`. Every state-changing request must present it. An attacking site can make the browser *send* a request, but the same-origin policy stops it *reading* our pages — so it cannot learn the token.

| Surface | How the token travels |
|---|---|
| HTML forms | Hidden field `csrf_token`, emitted by `csrfField()` — **41 forms across 21 pages** |
| `fetch()` to the JSON APIs | `X-CSRF-Token` header. A JSON body has no form fields, and requiring a custom header also forces a CORS preflight |

**API surface:**

| Endpoint | Protected |
|---|---|
| `POST admin/api/pos-checkout.php` | Yes — header, sent by `pos.php` |
| `POST admin/api/pos-cancel-cart.php` | Yes — header, sent by `pos.php` |
| `POST admin/api/pos-resume-held.php` | Yes — header, sent by `pos.php` *(25 Aug 2026)* |
| `POST admin/api/pos-terminal-heartbeat.php` | Yes — header, sent by `pos.php` *(25 Aug 2026)* |
| `POST admin/api/inventory-stock-in.php` | Yes — header, sent by `barcode-station.php` |
| `GET admin/api/products-scan.php` | No — read-only |
| `GET admin/api/pos-held-sale-history.php` | No — read-only, admin/manager only *(25 Aug 2026)* |
| `GET admin/notifications-api.php` | No — read-only |

**Helper API:**

| Function | Purpose |
|---|---|
| `csrfToken()` | The session token, created on first use |
| `csrfField()` | Hidden input for a form |
| `csrfMeta()` | Meta tag, for scripts that need to read it |
| `csrfValid()` | Boolean check, `hash_equals()` |
| `csrfRequire($redirect = null)` | Gate a handler. No-op on GET; on failure nothing executes |
| `csrfRotate()` | New token at a privilege boundary |

**Enforcement pattern.** `csrfRequire()` is called once per page, immediately after the role guard and before any handler:

```php
require_once '../includes/auth.php';
requireModule('inventory');
csrfRequire();          // no-op on GET; rejects an untokened POST
```

One call covers every handler on the page, which is far harder to get wrong than editing each `if ($_POST[...])` branch. `includes/auth.php` requires `csrf.php`, so the helpers are available everywhere without a separate include.

**On failure:** JSON callers get **403** with `{"ok":false,"error":"csrf","message":"…"}`; page callers get a flash message and a redirect back. Nothing is read or written either way, and the rejection is written to the error log with the referer.

*(403 rather than the 419 some frameworks use: 419 is not IANA-registered and Apache rewrites unknown codes to 500, which would report "server broke" instead of "rejected". Clients should branch on the `error` field.)*

**Design decisions worth knowing:**

- **One token per session, not per form.** Shop staff keep several tabs open — till, stock, reports — and per-form tokens would invalidate each other and produce failures that look random to the user.
- **GET is never checked.** GET requests must not change state anyway, and requiring a token would break plain links.
- **The token rotates on sign-in** (both password and remembered paths), so a token captured before a login cannot be used after it.
- **Login is protected too**, but inline rather than via `csrfRequire()`: that page has no flash area, and the usual cause of failure is innocent — a sign-in page left open until the session expired. The form re-renders with a fresh token, so one retry succeeds. Without this, a hostile page could sign a cashier into an attacker-controlled account, so everything they then did would be recorded against it.
- **`SameSite=Lax` is not relied upon.** Modern browsers default to it and it blocks most cross-site POSTs, but it is a browser default rather than a guarantee — older browsers, embedded webviews and configuration changes all remove it. The token is enforced server-side, so it holds regardless of the client.

### 14.2 Session cookie hardening

*Added 13 August 2026. `includes/session.php`.*

**What was wrong.** The application never configured its own session cookie, so it took whatever this XAMPP shipped. Measured on the running installation before the change:

| Setting | Was | Consequence |
|---|---|---|
| `session.cookie_httponly` | *(empty — off)* | `document.cookie` exposed the session id to any injected script. The CSRF token lives *in* the session, so stealing the cookie defeats that protection too. |
| `session.cookie_samesite` | *(empty)* | Cross-site behaviour depended entirely on the browser's own default. |
| `session.use_strict_mode` | `0` | PHP adopted **any** session id the browser offered, so an attacker could plant a known id and wait for the victim to sign in with it — session fixation. |
| `session.cookie_secure` | `0` | Correct today (the shop is on plain HTTP); would need to change with TLS. |

Setting these in `php.ini` would have worked on this one machine and been lost the day XAMPP is reinstalled or the app is copied to another PC. Putting them in code means the protection travels with the application.

**What it does now.** `appSessionStart()` sets `use_strict_mode`, `use_only_cookies` and `use_trans_sid=0`, names the cookie `mira_session`, and applies `lifetime=0`, `path=/`, `HttpOnly`, `SameSite=Lax` and `Secure` derived from the connection — the same shape as `rememberCookieParams()`, so both of the app's cookies are scoped alike. Lifetime stays 0 deliberately: staying signed in is the remember-me token's job, not a long-lived session cookie.

**Why a separate file rather than one more line in `auth.php`.** Five entry points start the session — `includes/auth.php`, `includes/csrf.php`, `admin/login.php`, `logout.php` and `admin/access-denied.php`. Configuration that must happen *before* `session_start()` is worthless if any one of them starts the session first with the old defaults. All five now call `appSessionStart()`; **no file calls `session_start()` directly any more.** Including `session.php` has no side effects, so `remember_me.php` and other includes can use `appIsHttps()` without starting anything.

**Why the cookie was renamed.** `PHPSESSID` at `path=/` is shared with every other application on the same XAMPP host; a neighbouring app can overwrite it. `mira_session` cannot collide. Nothing in the codebase hardcoded the old name (`logout.php` already used `session_name()`), so the rename is contained — its one visible effect is that everyone signed in at the moment of deployment is signed out once.

**Verified on the running system:**

| Check | Result |
|---|---|
| `Set-Cookie` on the login page | `mira_session=…; path=/; HttpOnly; SameSite=Lax` |
| Attributes survive `session_regenerate_id(true)` | Both the old and new `Set-Cookie` carry `HttpOnly; SameSite=Lax` |
| Attacker-supplied id `mira_session=attackerchosen…` | Discarded; the server issued its own id — strict mode working |
| Session continuity across requests | A CSRF token issued on a GET was accepted on the following POST, which then failed with *"Incorrect username or password"* — the intended rejection, not a session failure |
| Unauthenticated API request | Still `401 {"ok":false,"message":"You are not signed in."}` |
| Page guards | `pos.php`, `reports.php`, `access-denied.php`, `logout.php` and the clean URLs all still redirect to the login page |
| `Secure` flag | Absent over HTTP, as intended — setting it there would stop the cookie being sent at all and nobody could sign in |
| Real sign-in, in Chrome | Password login, `session_regenerate_id(true)`, redirect to the dashboard — all working |
| `document.cookie` read from the signed-in page | Shows **neither** `mira_session` nor `mira_remember` — `HttpOnly` confirmed from the browser's own side, not just the response header |
| Remember-me under the new cookie | A remembered device re-established its session and landed on the dashboard; sign-out revoked that token and a fresh sign-in issued a new one |

*Housekeeping note:* a browser that was signed in before the change still holds a stale `PHPSESSID` from the old configuration. Nothing reads it, and it expires when the browser closes. It is deliberately not deleted — `PHPSESSID` at `path=/` may belong to another application on the same XAMPP host, which is the very collision the rename avoids.

---

### CURRENT SECURITY

**Password hashing.** bcrypt via `password_hash()` / `password_verify()`. No plaintext anywhere. Minimum length 6 enforced on creation and change.

**Sessions.** Native PHP sessions, started through `appSessionStart()` so the cookie is `HttpOnly`, `SameSite=Lax`, `Secure` on HTTPS, and `session.use_strict_mode` is on. `session_regenerate_id(true)` on password login defeats fixation. Logout destroys the session and expires both cookies. See [14.2](#142-session-cookie-hardening).

**RBAC.** Enforced server-side at the top of every page, before any work. Five roles, twenty-five module keys. API endpoints return proper 401/403 JSON instead of redirecting. Secondary in-page checks add defence in depth for voiding, journal posting, request review, receipt ownership, and terminal/held-sale ownership.

**CSRF tokens.** Every state-changing form and JSON endpoint requires a per-session token; see [14.1](#141-csrf-protection).

**Prepared statements.** The dominant convention — all authentication, all POS, all accounting, all inventory writes, and (as of the current version) supplier, category and unit search.

**Server-side validation.** Every mutation re-validates on the server. Movement types, payment methods, roles, statuses, account types and departments are all checked against allow-lists.

**Server-side price calculation.** The POS browser sends **item ids and quantities only**. Prices, discounts, tax, totals and cost are read from the database. A tampered request cannot change what is charged.

**Transaction handling.** Multi-step operations run in one transaction with `try`/`catch (Throwable)` and rollback. Stock rows are locked with `SELECT … FOR UPDATE`, so concurrent tills cannot oversell.

**Accounting integrity.** Unbalanced entries refused; `UNIQUE (source_type, source_id)` prevents double-posting; corrections by reversal only; system accounts undeletable.

**Upload validation (product images).** Real MIME sniffing via `mime_content_type()`, an allow-list of `image/jpeg`, `image/png`, `image/webp`, a 3 MB cap, and a server-generated filename with an extension derived from the detected MIME — not from user input.

**Output escaping.** `htmlspecialchars(…, ENT_QUOTES)` is the convention for user-controlled output.

**Receipt numbers.** Three random alphanumerics appended, so one customer cannot enumerate another's receipt.

**Business safety rails.** Cannot deactivate the last active administrator; cannot change your own role; cannot deactivate yourself; a closed day cannot be reopened; entities in use are deactivated rather than deleted.

**`.htaccess`.** `Options -Indexes` disables directory listing. Unmatched URLs return a genuine HTTP 404.

**Error disclosure.** A failed database connection prints *"Sorry, the system is under maintenance."* and logs the technical detail via `error_log()`.

---

### Security weaknesses identified

Ordered by severity. These are findings from the current code, not recommendations.

**1. ~~The remember-me cookie is forgeable — CRITICAL.~~ FIXED 13 August 2026.**

Replaced with random, hashed, rotating selector/validator tokens — see [section 7](#remember-me-mechanism). Verified against the original exploit: a forged `{"username":"admin"}` cookie no longer authenticates, and the legacy cookie is deleted on sight.

**2. ~~`admin/manage-customers.php` interpolates search input into SQL — HIGH.~~ FIXED 13 August 2026.**

Converted to prepared statements, along with four other pages that used the same escape-then-interpolate pattern (`inventory-items.php`, `inventory-movements.php`, `inventory-reports.php`, `inventory-requests.php`). **No user input now reaches SQL by concatenation anywhere in the codebase.** The remaining `real_escape_string()` calls take internal values only — department keys from the fixed ENUM, and table/column names in `SHOW` statements, which cannot be parameterised.

*Superseded detail:*

```php
$search_clean = $conn->real_escape_string($search);
$search_query = "%$search_clean%";
$query .= " AND (name LIKE '$search_query' OR phone_number LIKE '$search_query')";
```

`real_escape_string()` makes this *currently* safe against classic injection, but it is the one remaining place in the codebase where user input reaches a query string by concatenation, and it breaks the convention every other file follows. *(The same pattern was present in `inventory-suppliers.php` and has been converted to a prepared statement; this file has not.)*

**3. ~~No CSRF protection anywhere — HIGH.~~ FIXED 13 August 2026.**

Every state-changing request now requires a per-session token — see [section 14.1](#141-csrf-protection). Verified against the attack: a forged cross-site POST that created an administrator is now rejected and nothing is written.

**4. ~~Profile photo upload validates only the extension — MEDIUM.~~ FIXED 13 August 2026** — and it was worse than this finding recorded: the cropped-image path derived the extension from the posted data URI, which was remote code execution. See [section 16](#16-file-uploads).

*Superseded detail:*

```php
$allowed_extensions = ['png', 'jpg', 'jpeg'];
$file_extension = strtolower(pathinfo($_FILES['profile_photo']['name'], PATHINFO_EXTENSION));
```

No MIME check, no size limit, no image verification — unlike the product-image path, which does all three. Files land in `assets/uploads/profile_admin/`, a web-served directory. The filename is server-generated (`uniqid('admin_')`), which prevents path traversal and constrains the extension to the allow-list, so this is not directly executable as PHP; but the content is entirely unvalidated.

**5. ~~Purchase-order invoice upload validates only the extension — MEDIUM.~~ FIXED 13 August 2026.**

Now `uploadStoreDocument()`: content-sniffed, 8 MB cap, server-generated name.

**6. ~~Uploads are stored under the web root with no execution guard — MEDIUM.~~ FIXED 13 August 2026.**

`assets/uploads/.htaccess` turns the PHP engine off and denies `.php` outright. Verified: a script placed there returns 403; images still load.

**7. ~~Partial session cookie hardening — MEDIUM.~~ FIXED 13 August 2026.**

The session cookie is now configured by the application itself rather than inherited from `php.ini` — see [section 14.2](#142-session-cookie-hardening).

**8. No brute-force protection — MEDIUM.**

No rate limiting, no lockout, no delay after repeated failed logins, and no audit record of failed attempts.

**9. Database credentials are in a web-root file — MEDIUM.**

`includes/db.php` sits under the document root. Directory listing is off and PHP files are executed rather than served, so this is not currently exposed; but a misconfiguration that stops PHP parsing would serve the credentials as plain text.

**10. Default credentials — MEDIUM (deployment).**

`core_schema.php` seeds a first-run administrator with a well-known username and a five-character password when the `admin` table is empty. It is intended to be changed immediately.

**11. No HTTPS — MEDIUM (deployment).**

Plain HTTP on port 8081. Passwords and session cookies cross the shop LAN in clear text.

**12. Password policy is minimal — LOW.** Six characters, no complexity or reuse rules.

**13. No password expiry, history or self-service reset — LOW.**

**14. `error_log()` only.** No structured application log, no security event log.

*(Findings 15–19 below were discovered and fixed during a 23–25 August 2026 RBAC/POS hardening pass, and are appended here rather than reflowed into strict severity order, since finding numbers 1–14 above are cross-referenced elsewhere in this document by number.)*

**15. ~~Unauthenticated password reset — CRITICAL.~~ FIXED 23 August 2026.**

`admin/reset-password.php` reset any account's password with no authentication at all. Deleted outright — the shop has no legitimate self-service password-reset flow to preserve in its place.

**16. ~~Receipt IDOR — MEDIUM.~~ FIXED 25 August 2026.**

`admin/pos-receipt.php` rendered any receipt by id with no ownership check — a cashier could view another cashier's receipt (customer name, phone, amounts) by changing the id in the URL. Now checks the sale's `cashier_id` against the session for any non-admin/manager viewer.

**17. ~~Held-sale cross-cashier IDOR — HIGH.~~ FIXED 25 August 2026.**

Three related gaps in the held-sales feature, all closed together: (a) the held-sales list was unfiltered — every cashier's parked cart contents (items, prices) were embedded in *every other* cashier's page HTML; (b) resuming a held sale was 100% client-side, with no server-side ownership check at all; (c) both checkout and the cancel-cart endpoint deleted a client-supplied `held_sale_id` for *any* held sale, with no check that it belonged to the caller. See §13.4–§13.5 and "Held sales" above.

**18. Till double-booking — MEDIUM.** FIXED 25 August 2026 (not previously a tracked finding — terminal selection was a session convenience, not an enforced boundary, until this pass). Two cashiers could select the same terminal and both operate on it at once, with no record of who actually held it. `pos_terminals` gained a real server-enforced lock (see "Terminal session-locking" in `CLAUDE.md`).

**19. Sidebar link over-exposure — LOW.** FIXED 25 August 2026. Six Inventory-section sidebar links rendered for any role that could open the section at all, including a cashier who should see only Stock Requests — they predated the `stock_requests` carve-out and were never wrapped in their own module check. The underlying pages were already correctly gated server-side, so this was a navigation/information-disclosure issue (link *labels* visible for pages that would deny the click), never an actual access bypass.

---

### RECOMMENDED IMPROVEMENTS

*These are recommendations. None of them is implemented.*

**Priority 1 — do these first**

1. ~~**Fix or remove remember-me.**~~ **Done 13 August 2026** — see [section 7](#remember-me-mechanism).
2. ~~**Add CSRF tokens.**~~ **Done 13 August 2026** — see [section 14.1](#141-csrf-protection).
3. ~~**Convert the remaining interpolated query** in `manage-customers.php` to a prepared statement.~~ **Done 13 August 2026** — along with four other files that shared the pattern. See finding 2.
4. **Change the default administrator password** immediately after any install.

**Priority 2**

5. ~~Apply the product-image validation pattern (MIME sniff + size cap + generated name) to profile photos and PO invoices.~~ **Done 13 August 2026** — `includes/uploads.php` now handles all three paths.
6. ~~Drop an `.htaccess` into `assets/uploads/` disabling script execution (`php_flag engine off`, and `RemoveHandler`/`RemoveType` for `.php`).~~ **Done 13 August 2026** — a `.php` file placed there returns 403; images still load.
7. ~~Harden session cookies: `session.cookie_httponly=1`, `cookie_samesite=Lax`, and `cookie_secure=1` once HTTPS exists.~~ **Done 13 August 2026** — set by the application rather than `php.ini`, plus `use_strict_mode`. See [14.2](#142-session-cookie-hardening). `Secure` turns itself on when the connection is HTTPS.
8. Add login rate limiting — a per-username and per-IP counter with a short lockout, plus an audit row on failure.
9. Move `includes/db.php` outside the document root, or move the credentials to environment variables.

**Priority 3**

10. Enable HTTPS, even with a self-signed certificate, if the system is reached across the shop network.
11. Raise the minimum password length to 10–12 and check against a common-password list.
12. Add a structured application log for security events (logins, failures, role changes, voids, closes).
13. Add an idle session timeout appropriate to a shop floor.
14. Self-host Bootstrap, Font Awesome, Poppins, Chart.js and Cropper.js. This is a **security and availability** improvement: it removes five third-party origins from the trust boundary *and* lets the till keep working when the internet is down — which matters for a shop in Iringa.

---

## 15. Error handling

### Exceptions

Business logic uses `throw new Exception('human-readable message')` inside `try` blocks, caught as `catch (Throwable $e)`. The message is written for the user, not the developer — *"Not enough stock for "Azam Cola 500ml". Available: 1.000."*, not *"SQLSTATE[23000]"*.

The prevailing return convention is a tuple rather than a thrown exception at the boundary:

```php
return [true,  $receiptNo, $txnId];   // success
return [false, $e->getMessage(), 0];  // failure
```

Callers destructure: `[$ok, $msg] = someOperation(...)`.

### Transactions and rollback

```php
$conn->begin_transaction();
try {
    /* … several writes, any of which may throw … */
    $conn->commit();
    return [true, $result];
} catch (Throwable $e) {
    $conn->rollback();
    return [false, $e->getMessage()];
}
```

Nested operations receive `ownTransaction: false` so there is exactly one boundary. Failure is always all-or-nothing.

### Logging

- **Database connection failure** — `error_log()` with the technical detail, generic message to the user
- **`inv_audit_log`** — a business audit trail (who did what to which entity), not an error log
- **PHP errors** — go wherever `php.ini` points, typically `C:\xampp\php\logs\php_error_log`
- **Apache** — `C:\xampp\apache\logs\error.log` and `access.log`

**There is no application-level error log.** Handled business failures are shown to the user and are not recorded anywhere. *(Recommended improvement, not implemented.)*

### Session flash messages

```php
$_SESSION['inv_flash'] = ['type' => 'success'|'danger'|'warning'|'info', 'msg' => '…'];
header('Location: same-page.php');
exit;
```

`inventory-header.php` reads it, **clears it**, and emits it as a `data-mx-flash` attribute; `ui.js` converts it to a toast. Clearing on read is what stops a message reappearing on refresh.

### API errors

| Situation | HTTP | Body |
|---|---|---|
| Success | 200 | `{"ok":true, …}` |
| Business failure | **200** | `{"ok":false,"message":"…"}` |
| Not signed in | 401 | `{"ok":false,"message":"You are not signed in."}` |
| Wrong role | 403 | `{"ok":false,"message":"Your role does not have access to this."}` |
| Wrong HTTP method | 405 | `{"ok":false,"message":"POST required."}` |

Business failures deliberately return 200 with `ok: false` — the HTTP request succeeded; the business operation did not. Clients must check `ok`, not just the status code.

### HTTP status responses

- `notfound.php` sends a real **404**. This was a deliberate fix: returning 200 for every unmatched URL told search engines the page existed and was fine to index.
- The `.htaccess` catch-all routes any non-file, non-directory request there.

### Client-side error handling

```js
fetch('api/pos-checkout.php', { … })
  .then(r => r.json())
  .then(d => { if (!d.ok) { beep(false); toast(d.message || 'Checkout failed.', false); return; } … })
  .catch(() => { beep(false); toast('Network error - the sale was NOT completed.', false); })
  .finally(() => { /* always re-enable the button and refocus the scanner */ });
```

The `.catch()` message is explicit that nothing was saved, and `.finally()` guarantees the till is never left with a permanently disabled button.

---

## 16. File uploads

### Locations

| Directory | Contents | Written by | Status |
|---|---|---|---|
| `assets/uploads/shop_products/` | Product images | `retail-products.php` | Active |
| `assets/uploads/profile_admin/` | Profile photos | `profile.php` | Active |
| `assets/uploads/po_invoices/` | Purchase order invoices | `inventory-po-view.php` | Active |
| `assets/uploads/shop_logo/` | Shop logo | `shop-settings.php` | Created on first logo upload |

Three orphaned laundry-era directories (`profil_admin`, `package_icons`, `paket_icons`, 27 files / ~14 MB) were deleted in the 13 August 2026 cleanup, along with the orphaned contents of the three surviving directories. The directories themselves are kept even when empty so the application never has to `mkdir` at runtime.

Directories are otherwise created on demand with `mkdir($dir, 0777, true)`. *(Mode 0777 is broader than necessary; on Windows/NTFS it has limited effect.)*

### One validation pipeline for everything

*Rewritten 13 August 2026.* All four upload paths go through `includes/uploads.php`; none of them looks at the uploaded filename.

| Entry point | Function | Accepts | Cap |
|---|---|---|---|
| Product images (`retail-products.php`) | `uploadStoreImage()` | JPEG, PNG, WebP | 3 MB |
| Shop logo (`shop-settings.php`) | `uploadStoreImage()` | same | 3 MB |
| Profile photo, file picker (`profile.php`) | `uploadStoreImage()` | same | 3 MB |
| Profile photo, Cropper.js result (`profile.php`) | `uploadStoreDataUriImage()` | same | 3 MB |
| PO invoice (`inventory-po-view.php`) | `uploadStoreDocument()` | PDF + the image types | 8 MB |

`uploadCheckImage()` sniffs the real type with `finfo` **and** cross-checks it with `getimagesize()`, requiring the two to agree — a file that lies to one of them is refused. The stored name is generated server-side (`prefix_random.ext`) with the extension taken from the **detected** MIME, so the uploaded name never reaches the filesystem.

`uploadStoreDataUriImage()` ignores the `data:` URI's declared type entirely and sniffs the decoded bytes. That path is why this rewrite happened: it previously took the extension straight from the declared type, so `data:image/php;base64,…` wrote a `.php` file into a web-served directory. That was remote code execution, not merely weak validation.

`uploadDeleteFile()` rejects any filename containing a path component and confirms with `realpath()` that the resolved file is inside the intended directory. The three deletion call sites now re-read the stored filename from the database rather than trusting a posted `current_photo` field, which was an arbitrary-file-delete.

`assets/uploads/.htaccess` disables the PHP engine and removes the handler for `.php`, so even a file that somehow lands there cannot execute. Verified: a `.php` file placed in `shop_products/` returns 403 while images still return 200.

### Expense receipts — Not implemented

`acc_expenses.receipt_file` exists in the schema, but `admin/expenses.php` has no file input and `accRecordExpense()` never writes the column. The field is always NULL.

### Effective size limits

Every path is capped by the application: 3 MB for images, 8 MB for documents. `php.ini`'s `upload_max_filesize` and `post_max_size` still apply on top and remain unverified for this installation; check `phpinfo()`.

---

## 17. Frontend architecture

### Bootstrap

Bootstrap 5.3.2 from CDN, CSS and the JS bundle. Used for the grid, modals, dropdowns, form controls and utilities. Bootstrap's own component classes are used where they fit; `ui.css` supplies everything specific to this application.

### CSS architecture

Two stylesheets, and **the order matters**:

```html
<link rel="stylesheet" href="../assets/css/admin/styles.css">  <!-- 1st: legacy, 36 lines -->
<link rel="stylesheet" href="../assets/css/admin/ui.css">      <!-- 2nd: the design system -->
```

`styles.css` is a 36-line remnant of the pre-redesign stylesheet, kept only so nothing that still references its rules breaks. It must load first so `ui.css` wins.

### `ui.css` — the design system (~50 KB)

Organised into sections: design tokens → typography → app shell → sidebar → page header → buttons → cards and KPIs → tables → forms → badges → alerts → modals → toasts → search, filters and chips → pagination → empty and loading states → utilities → responsive → print.

**Tokens.** Semantic CSS custom properties on `:root`:

```css
--color-primary, --color-success, --color-warning, --color-danger, --color-border, …
--radius-sm … --radius-lg
--shadow-sm … --shadow-lg
--space-1 … --space-8
```

Back-compatibility aliases `--mx-*` and `--inv-*` are defined from the same values so older markup keeps working.

**Class prefixes:**

| Prefix | Meaning |
|---|---|
| `ui-*` | Current design-system components (`ui-btn`, `ui-table-wrap`, `ui-badge`, `ui-search`, `ui-chip`, `ui-toolbar`, `ui-money`, `ui-num`, `ui-caption`, `ui-muted`, `ui-dot`) |
| `mx-*` | Retained deliberately — these are a **contract with `ui.js`** (`mx-section`, `mx-link`, `mx-actions`, `mx-toasts`). Renaming them breaks the JavaScript |
| `inv-*` | Older component classes still in use (`inv-card`, `inv-table`, `inv-stat-card`, `inv-page-header`) |

**Responsive breakpoints:** 1400, 1200, 992, 768 and 576 px. Two column utilities let dense tables degrade gracefully: `.ui-col-optional` hides at 1200 px, `.ui-col-secondary` at 992 px.

**Print styles** are included for receipts, the Z-report and barcode labels.

### `ui.js` — the `MX` namespace (~520 lines)

An IIFE exposing exactly one global: `window.MX`. It auto-initialises on `DOMContentLoaded` and is safe to include on every page.

| Method | Purpose |
|---|---|
| `MX.toast(opts, type)` | Non-blocking notification. Builds nodes with `textContent`, never `innerHTML` |
| `MX.initSidebar()` | Accordion nav; open sections persisted in `localStorage` |
| `MX.filterTable(table, query)` | Client-side row filter using each row's `data-search` |
| `MX.initFilters()` | Wires `data-mx-filter` inputs and `data-mx-chip` chips |
| `MX.initDropdowns()` | Row action menus |
| `MX.watchAlerts(intervalMs)` | Polls `notifications-api.php` (default 30 s), paints badges, toasts increases |
| `MX.initSearch()` | Search boxes with a clear button |
| `MX.fillModal(modal, data, title)` | Populates a shared modal from `data-field-*` attributes |
| `MX.initModals()` | Wires `data-ui-modal` triggers |
| `MX.initFormLoading()` | Disables and spins submit buttons on `data-ui-loading` forms |
| `MX.setLoading(btn, on)` | Manual loading state |
| `MX.money(n)` | `Tsh 12,000` |
| `MX.timeAgo(dateStr)` | Relative time |
| `MX.confirmSubmit(formId, message)` | Confirm-before-submit helper |

### Shared components

**The modal pattern.** One modal serves an entire list page, rather than one modal per row — which keeps page weight constant instead of linear in row count:

```html
<button data-ui-modal="#supplierModal"
        data-title="Edit supplier"
        data-field-id="7"
        data-field-name="Iringa Wholesalers"
        data-field-contact-person="J. Mwakalinga">
```

`MX.fillModal()` maps `data-field-contact-person` → the element named `contact_person` and assigns via `.value` / `.textContent` — **never `innerHTML`**, which is what makes the pattern XSS-safe.

**Shared partials.** `admin/partials/page-header.php`, `empty-state.php` and `pagination.php` are included with a small `$ph` / `$es` array rather than duplicating markup.

**Shared shell.** `inventory-header.php` accepts `$pageTitle`, `$pageSubtitle`, `$breadcrumbs`, `$topbarSearch` and `$pageHead`; `inventory-footer.php` accepts `$pageScript`.

### Page-specific JavaScript

Written inline in the page that needs it, passed to the footer as `$pageScript`. The POS terminal (~500 lines of inline ES6) and the Barcode Station are the substantial cases.

### `fetch()` requests

Always `credentials: 'same-origin'`, `Content-Type: application/json`, `.then(r => r.json())`, with a `.catch()` for network failure and a `.finally()` to restore UI state. There is no shared HTTP wrapper — each call site handles its own.

### `localStorage`

| Key | Contents |
|---|---|
| `mxOpenSections` | Which sidebar sections are expanded |
| `mxLastAlertCounts` | Previous badge counts, so only *increases* raise a toast |

### `sessionStorage`

**Not used anywhere.** The POS terminal's cart lives in a page variable and is deliberately not persisted client-side — parking a sale is a server-side operation so any till can resume it.

---

## 18. Deployment

### Current deployment

A single-machine XAMPP installation on Windows 11, serving the shop over the local network.

| Item | Value |
|---|---|
| **Platform** | Windows 11 Home (10.0.26200) |
| **Stack** | XAMPP — Apache 2.4.58, PHP 8.2.12, MariaDB 10.4.32 |
| **XAMPP root** | `C:\xampp` |
| **Document root** | `C:\xampp\htdocs` |
| **Application path** | `C:\xampp\htdocs\home` |
| **HTTP port** | **8081** |
| **Database port** | **3306** |
| **Database name** | `retailer_shop` |
| **Base URL (local)** | `http://localhost:8081/Home/` |
| **Base URL (LAN)** | `http://<server-ip>:8081/Home/` |
| **Timezone** | `Africa/Dar_es_Salaam`, set in `includes/db.php` |
| **Currency** | `Tsh` |

### Database connection

Configured in `includes/db.php` via `$host`, `$port`, `$username`, `$password`, `$database` (values not reproduced here). The connection sets `utf8mb4` and calls `mysqli_report(MYSQLI_REPORT_OFF)` — see [section 3](#database-access) for why that line is load-bearing.

### URL routing (`.htaccess`)

Requires Apache `mod_rewrite` (enabled by default in XAMPP).

| Clean URL | Target |
|---|---|
| `/admin/dashboard` | `admin/index.php` |
| `/manager/overview` | `admin/manager-overview.php` |
| `/inventory` | `admin/inventory-dashboard.php` |
| `/pos/terminal` | `admin/pos.php` |
| `/login` | `admin/login.php` |
| `/pos/sales` | `admin/pos-sales.php` |
| `/accounting` | `admin/accounting-dashboard.php` |
| `/` (site root) | `admin/login.php` |
| anything else that is not a real file or directory | `notfound.php` (HTTP 404) |

`Options -Indexes` disables directory listing. The 404 target is written relatively so it resolves when served from a subdirectory.

### Required PHP extensions

**Confirmed loaded and required:** `mysqli`, `session`, `json`, `fileinfo`.
**Loaded, no direct dependency identified:** `gd`, `mbstring`, `openssl`.

### Required Apache modules

`mod_rewrite` (mandatory — the clean routes and 404 catch-all depend on it).

### First-run behaviour

On the first page load after installation:

1. `ensureCoreSchema()` creates `admin` and `customer`. If `admin` is empty, it seeds a single administrator account so somebody can sign in.
2. The other four schema installers create their tables and seed the chart of accounts, retail categories, units, a default location and default settings.

**Change the seeded administrator password immediately.**

### Deploying to another machine

1. Install XAMPP with PHP 8.2 and MariaDB 10.4.
2. Copy the application to `C:\xampp\htdocs\home`.
3. Set Apache's listen port to 8081 (`httpd.conf`) — or adjust to taste and update the documented URLs.
4. Ensure `mod_rewrite` is enabled and `AllowOverride All` applies to the document root, or `.htaccess` is ignored.
5. Create the database and set `$host`, `$port`, `$username`, `$password`, `$database` in `includes/db.php`.
6. Restore a database dump, or let the schema installers build a fresh database on first load.
7. Sign in and change the administrator password.
8. Confirm `assets/uploads/` is writable by the Apache service account.

### Not deployed / not present

- **No HTTPS.** Plain HTTP only.
- **No staging or test environment.**
- **No CI/CD, no deployment script, no containerisation.**
- **No environment-based configuration** — a single hard-coded `db.php`.
- **No process manager or health check.** Apache and MariaDB are started manually from the XAMPP Control Panel unless installed as Windows services.

---

## 19. Backup and recovery

> **The system has no automated backup mechanism of any kind.** There is no scheduled task, no backup script, no export feature, and no replication. Backups are entirely manual and are the operator's responsibility. This is the single largest operational risk in the deployment.

### What must be backed up

| # | What | Where | Why | Frequency |
|---|---|---|---|---|
| 1 | **Database** `retailer_shop` | MariaDB | Every sale, every ledger entry, every stock level, all users and customers. **Irreplaceable.** | Daily, minimum |
| 2 | **Uploads** `assets/uploads/` | Filesystem | Product images, profile photos, PO invoices. Not in the database — a database restore alone loses them | Weekly, or after bulk uploads |
| 3 | **Configuration** `includes/db.php` | Filesystem | The connection settings and business constants | On change |
| 4 | **Application files** | Filesystem | The whole `home` directory | On change / after updates |
| 5 | `.htaccess` | Filesystem | Routing. Easily missed — it is a hidden file | On change |

Priority order for recovery: **database first**, then uploads, then application files.

### Database backup

```bash
"C:\xampp\mysql\bin\mysqldump.exe" -u root -h 127.0.0.1 -P 3306 \
    --single-transaction --routines --events \
    retailer_shop > backup_retailer_shop_YYYY-MM-DD.sql
```

`--single-transaction` gives a consistent snapshot of InnoDB tables without locking the shop out.

### Database restore

```bash
"C:\xampp\mysql\bin\mysql.exe" -u root -h 127.0.0.1 -P 3306 \
    retailer_shop < backup_retailer_shop_YYYY-MM-DD.sql
```

Restore into an empty database. The schema installers will bring anything missing up to the current version on the next page load.

### Files backup

Copy `C:\xampp\htdocs\home` (including hidden files) to external media or another machine.

### Recovery scenarios

| Scenario | Recovery |
|---|---|
| Database corrupted | Restore the most recent dump. Everything after that dump is lost |
| Application files damaged | Restore the files; the database is unaffected |
| Whole machine lost | Reinstall XAMPP → restore files → restore database → change passwords |
| Accidental record deletion | Most deletes are **soft** (`deleted_at`) and can be reversed with a SQL update. Sales and journal entries are never deleted — sales are voided, entries are reversed |
| A day closed by mistake | **No recovery path in the application.** `acc_daily_close` is immutable by design; correction requires direct database work and a compensating journal entry |

### Retention — recommended, not implemented

Daily dumps kept 30 days; weekly kept 3 months; monthly kept 12 months. At least one copy off-site or in cloud storage — a fire or theft takes the server *and* any backup sitting beside it.

**Restores must be tested.** An untested backup is a hypothesis.

---

## 20. Maintenance

### Database maintenance

**Health check — the invariant.** The most valuable single query in the system:

```sql
-- These two numbers must match.
SELECT ROUND(SUM(i.current_stock * i.average_cost), 2) AS shelf_at_cost
  FROM inv_items i WHERE i.deleted_at IS NULL;

SELECT ROUND(SUM(l.debit - l.credit), 2) AS inventory_asset_in_ledger
  FROM acc_journal_lines l
  JOIN acc_accounts a ON a.id = l.account_id
 WHERE a.code = '1200';
```

A divergence means a stock movement did not post to the ledger. Investigate before it compounds.

**Trial balance.** Total debits must equal total credits across `acc_journal_lines`. If they do not, something wrote lines outside `accPostEntry()`.

**Growth.** The fast-growing tables are `inv_stock_movements`, `sales_transactions`, `sales_transaction_items`, `acc_journal_lines` and `inv_audit_log`. All are indexed on `created_at`. **Do not delete rows from any of them** — they are the audit trail and the ledger. Archive only with a deliberate, documented process.

**Optimisation.** Occasionally, when the shop is closed:

```sql
OPTIMIZE TABLE inv_stock_movements, sales_transactions,
               sales_transaction_items, acc_journal_lines, inv_audit_log;
```

### Log monitoring

| Log | Location | Watch for |
|---|---|---|
| Apache error | `C:\xampp\apache\logs\error.log` | PHP fatals, permission failures |
| Apache access | `C:\xampp\apache\logs\access.log` | Unusual request patterns |
| PHP error | Per `php.ini`, typically `C:\xampp\php\logs\php_error_log` | Warnings, notices, connection failures |
| MariaDB error | `C:\xampp\mysql\data\*.err` | Crashes, corruption, deadlocks |
| `inv_audit_log` | Database | Business actions — voids, department toggles, deletions |

There is no log rotation configured. Archive and truncate manually before the files grow unwieldy.

### Upload cleanup

The three orphaned laundry-era directories were removed on 13 August 2026, together with the orphaned files inside the three live ones — 51 files, roughly 15 MB.

There is **no orphan-file cleanup**: deleting a product removes its image rows but files may remain on disk. A periodic reconciliation between `retail_product_images.file_path` and the directory contents is worth doing annually.

### Asset maintenance

`assets/images/favicon.png` is approximately 454 KB — very large for a favicon, and it is requested on every page load. Re-encoding it as a small PNG or ICO is a quick, safe win.

### Dependency maintenance

There is no package manager, so "updating dependencies" means editing CDN URLs. Bootstrap, Font Awesome, Poppins, Chart.js and Cropper.js are all pinned to explicit versions — which is good practice; nothing changes underneath you. Review them annually.

**The strongest recommendation in this section: self-host them.** It removes five external origins from the trust boundary and lets the till keep working without internet. That matters more here than in most deployments.

### User administration

- Review accounts and roles periodically; deactivate leavers rather than deleting them (their name is stamped on historical sales and journal entries)
- Rotate passwords after any staff change
- Keep at least two administrator accounts — the system will not let you deactivate the last one, but it also cannot help if you forget its password
- Check `inv_audit_log` for unexpected voids or department toggles

### Routine schedule — recommended

| Frequency | Task |
|---|---|
| **Daily** | Close the day (Z-report). Back up the database. Check the low-stock badge |
| **Weekly** | Back up `assets/uploads/`. Skim Apache and PHP error logs. Review open purchase orders |
| **Monthly** | Verify the inventory invariant. Check the trial balance. Review the P&L. Test-restore a backup. Review user accounts |
| **Annually** | Review CDN library versions. Reconcile orphaned upload files. Archive logs |

---

## 21. Known technical limitations

Findings from this inspection. Each is stated plainly, with its consequence.

### Architectural

1. **No automated tests.** No unit, integration or end-to-end tests, and no test framework. Every change is verified by loading pages in a browser. For a system that moves money and stock, this is the most significant engineering limitation.
2. **No dependency management or build step.** Five libraries load from three external CDNs at runtime. If the internet is down, the shop's till loses Bootstrap and Font Awesome. Chart.js and Cropper.js fail silently.
3. **No environment separation.** One hard-coded `db.php`; no dev/staging/production distinction; no configuration by environment.
4. **No front controller.** Every page must remember to guard itself. It works today — all 37 pages were verified — but nothing structurally prevents a new page from shipping unguarded.
5. **Business logic and presentation are interleaved** in page files. `admin/retail-products.php` is 692 lines, `admin/profile.php` 593, `admin/manage-customers.php` 564, `admin/pos.php` 1201.
6. **Schema installers run on every request.** The version check makes this cheap (one indexed `SELECT`), but it is still work on every page load, and a partially-applied upgrade under concurrency has no locking around it.

### Data model

7. **`inv_items.current_stock` is a single global quantity.** There is no per-location stock. `inv_locations` exists and items reference it, but stock is not tracked per location, so a `transfer` movement would have to be a matched pair or it would destroy stock value. Nothing creates transfers today — the risk is latent, not active.
8. **`inv_batches` is dead.** The table, its foreign key and the `batch_id` column on movements all exist; nothing writes to it. Batch and lot tracking is **not implemented**.
9. **`inv_locations` has no management UI.** One default row is seeded; there is no way to add or edit locations.
10. *(Resolved 13 August 2026.)* Departments used to be a fixed `ENUM('supermarket','stationery','general')` on all seven tables, so adding one was a schema change. They are now administrator-defined rows in `retail_departments`, and the seven columns are `VARCHAR(32)`. See [§24](#24-business-configuration).
11. **`acc_expenses.receipt_file` is never populated** — no upload exists.
12. **The `admin` table is misnamed.** It holds all five roles, not just administrators.

### Functional gaps

13. **No self-service password reset.** A locked-out user needs an administrator.
14. **A closed day cannot be reopened.** Deliberate, but it means a mis-keyed cash count needs direct database intervention.
15. **Per-line discounts are accepted by the API and stored, but the till has no way to enter one.**
16. **POS tax rate, tax-inclusive flag, receipt footer and opening float have no user interface.** They are read from `inv_settings` and can only be changed with SQL. The opening float in particular affects the expected-cash figure on every Z-report.
17. *(Resolved 13 August 2026.)* `admin/inventory-settings.php` used to write two settings nothing read — `auto_deduction_enabled` and `deduction_trigger_stage`, laundry-era leftovers that `inventory_schema.php` v4 deletes from `inv_settings`. The controls and the "usage templates" explanatory panel have been removed; the screen now offers only the currency label and the expiry-alert window.
18. **The `reports` permission is never checked.** Inventory Reports is gated by `inventory` instead, which is why storekeepers can open it. The `pos_void` permission is likewise unused — voiding checks role names directly.
19. **No customer purchase history view.** Sales record the customer, but there is no screen showing one customer's purchases.
20. **No stock transfer, no multi-branch, no supplier returns workflow** beyond a manual `return` movement.

### Performance

21. **The POS loads every sellable product on every page load** with no pagination. With 41 products this is instant. At several thousand it would become a large HTML payload and a slow first paint — although it is also what makes department and category switching instant.
22. *(Resolved 13 August 2026.)* `admin/chart-of-accounts.php` had an N+1 — one balance query per account (29 queries, 44.2 ms). `accAllAccountBalances()` now does it in one grouped query at 0.9 ms, returning identical balances. See [§23.9](#239-performance).
23. **Pagination exists on the products page and the reporting screens only.** Other list pages still render every row within a fixed `LIMIT`.
24. **No caching layer.** Every figure is computed per request. Correct, and appropriate at this scale.
25. **`assets/images/favicon.png` is ~454 KB**, fetched on every page load.

### Security

26. See [section 14](#security-weaknesses-identified). The remember-me cookie forgery is the critical one; the absence of CSRF protection is the broadest.

### Operational

27. **No automated backups.** See [section 19](#19-backup-and-recovery).
28. **No application error log.** Handled failures are shown to the user and recorded nowhere.
29. **No monitoring, alerting or health endpoint.**
30. **Single point of failure** — one machine runs Apache, MariaDB and the till. If it fails, the shop cannot sell.
31. *(Resolved 13 August 2026.)* The project-root Markdown files are now current: `POS_MODULE.md` was deleted, `INVENTORY-MODULE.md` was rewritten to strip laundry residue, and `CLAUDE.md` was corrected. See the status note in [section 4](#responsibilities).

### Could not be verified

- Whether `gd` and `mbstring` are actually required by any code path, or merely present
- The `php.ini` values of `upload_max_filesize`, `post_max_size` and `session.gc_maxlifetime` for this installation *(the session cookie flags no longer depend on `php.ini` — see [14.2](#142-session-cookie-hardening))*
- The exact XAMPP distribution version
- Whether Apache and MariaDB are installed as Windows services or started manually
- Whether any backup currently exists outside this machine

---

*End of Technical Documentation.*

---

## 22. Shop settings, theming and the customer display

*Added 13 August 2026.*

### 22.1 Deployment model

**Single-shop configurable installation.** Verified rather than assumed: no `shop_id`, `tenant_id`, `company_id`, `branch_id` or `org_id` column exists on any table. `inv_settings` is a flat global store, and users, products, stock, sales, customers and accounting are all global.

**This is not multi-tenant and must not be described as such.** Running a second business means a second database and a second copy of the application.

### 22.2 What replaced the hardcoded constants

Eight constants in `includes/db.php` used to carry the business's identity. They are now database-backed:

| Constant | Setting key |
|---|---|
| `BUSINESS_NAME` | `shop_name` |
| `BUSINESS_TAGLINE` | `shop_tagline` |
| `BUSINESS_ADDRESS` | `shop_address` |
| `BUSINESS_PHONE_E164` | `shop_phone` |
| `BUSINESS_WHATSAPP_NUMBER` | `shop_whatsapp` |
| `LIPA_NAMBA_NUMBER` / `_PROVIDER` / `_NAME` | migrated to a `shop_payment_methods` row |

**The constants remain in `db.php` deliberately** â€” they are the third tier of the fallback chain, so an installation that upgrades and never opens the settings screen looks exactly as it did before. They can be removed once every installation has booted at least once.

Two laundry-era literals were also replaced: the printed report header in `inventory-reports.php` (was "Mira Cleaning Services") and the POS `<title>`.

### 22.3 Storage

**Scalars** extend `inv_settings` â€” no second configuration system. `setting_value` was widened `VARCHAR(255)` â†’ `TEXT`, because a receipt header, footer or business description does not fit in 255 characters.

**Payment methods needed a table** (`shop_payment_methods`). The reasoning, since the brief asked for it: a shop needs several, each with a provider, number, payee name, instructions, an enabled flag and a display order, and they must be ordered and filtered as records. Serialising that into a single key/value row would mean parsing a structure out of a string on every read â€” a second configuration system wearing a disguise.

| Column | Purpose |
|---|---|
| `provider` | Free text. Suggestions are offered in the UI but not enforced, so an unlisted provider still works |
| `payment_type` | `mobile_money` / `bank` / `card` / `cash` / `other` |
| `payment_number`, `account_name` | What the customer pays into, and the name they will see |
| `instructions`, `reference_note` | Customer-facing guidance |
| `is_enabled`, `sort_order` | Visibility and ordering |

Installed by `ensureShopSettingsSchema()` via the existing self-installing pattern, hooked into `inventoryBoot()`, `catalogBoot()` and `posBoot()`. Additive only. `login.php` calls it directly, because the sign-in screen carries the shop's identity before any other boot has run.

**Migration of the old Lipa Namba constants runs once**, and only when the table is empty â€” so it can never resurrect a row an administrator deleted.

### 22.4 Reading settings

`includes/shop_settings.php`:

| Function | Purpose |
|---|---|
| `shopSettingsAll($conn, $refresh)` | All settings, **one query per request**, cached in a static |
| `shopSetting($conn, $key, $fallback)` | One value through the fallback chain |
| `shopSettingOn($conn, $key)` | A receipt toggle |
| `shopName($conn)` | Never empty |
| `shopLogoUrl($conn, $prefix)` | `''` when unset **or the file is missing**, so callers can just test truthiness |
| `shopAddressLine($conn)` | Joins the filled-in address parts, falling back to the single-line field |
| `shopPaymentMethods($conn, $enabledOnly)` | Enabled **and** non-blank only, in display order |

**Fallback order:** database value â†’ the old constant â†’ empty string. Toggles are three-state-safe: a stored `0` is a real answer, whereas an empty *text* value falls back rather than wiping the brand.

**Caching matters here.** A receipt reads a dozen settings; without the static cache that would be a dozen queries per print.

### 22.5 Consumers

`pos-receipt.php`, `pos.php`, `login.php`, `sidebar-admin.php`, `inventory-header.php`, `index.php`, `inventory-reports.php`.

**The receipt's layout, 80 mm width, fonts, numbering, tender handling and every calculation are untouched.** Only the identity block and an optional payment block changed, each line conditional on being both enabled and non-empty.

### 22.6 Security and audit

- `admin/shop-settings.php` is gated by a new **admin-only** module key `shop_settings`, added to `roleModules()`.
- Verified by test: cashier, storekeeper **and manager** are all redirected to `access-denied.php` and see no sidebar link.
- CSRF-protected like every other page (`csrfRequire()` plus `csrfField()` in each form).
- Logo upload uses the shared `uploads.php` pipeline â€” content-sniffed, size-capped, server-generated filename.
- Changes are recorded through the **existing** `invAudit()`; no second audit system.

### 22.7 Theme

Implemented purely by redefining the section-1 tokens. **No component rule is duplicated and no second styling framework was added.**

| State | Mechanism |
|---|---|
| Light | `:root[data-theme="light"]` â€” the base palette |
| Dark | `:root[data-theme="dark"]` |
| System | **No attribute at all** â€” `@media (prefers-color-scheme: dark)` on `:root:not([data-theme])` |

Leaving the attribute *off* for System is what keeps the OS authoritative; an explicit Light choice still wins because it sets the attribute.

**Anti-flash:** `inventory-header.php` emits a small inline script in `<head>`, before any stylesheet, that applies the stored value. It runs before first paint, so no flash of the wrong theme.

**Persistence is `localStorage` (`mxTheme`), not the database.** There is no per-user preferences table, and a theme belongs to the screen someone is standing at rather than to the account â€” a cashier moving tills should get that till's setting.

A handful of Bootstrap surfaces (form controls, modals, dropdowns, `.btn-close`) paint their own literals and are pointed back at the tokens.

**Print is force-overridden to a light palette**, so a receipt printed from a dark screen is still dark ink on white paper. The receipt preview is pinned light in both themes for the same reason.

API: `MX.getTheme()`, `MX.setTheme(choice)`, `MX.paintThemeSwitch()`, `MX.initTheme()`. Changing theme fires a `mx:themechange` event.

### 22.8 POS full screen

Real Fullscreen API (`requestFullscreen` / `exitFullscreen`, with `webkit` fallbacks), not CSS.

- State is synced from the browser's own `fullscreenchange` event, so **Esc and native controls keep the label correct**.
- `aria-pressed`, a live `aria-label` and a title attribute; it is a real `<button>`, so keyboard activation works without extra code.
- Hidden entirely when `document.fullscreenEnabled` is false, rather than offering a dead control.
- A rejected promise (permissions policy, untrusted gesture) raises a toast instead of failing silently.
- **F11 is not intercepted.** Neither is F2, the global scan listener, nor any checkout shortcut â€” only a click on its own button is bound.
- Focus returns to the scan box after toggling, so the next scan is not swallowed by the button.

### 22.9 Customer display

Second-screen view for the customer, entirely front-end. **No new route, no new endpoint, no polling, no server change.**

- The same `admin/pos.php`, loaded with the `#customer` hash, renders the customer view and hides the till chrome.
- `BroadcastChannel('pos_sales_bus')` carries the state. The till posts; the display listens and never posts back, so it cannot affect the sale.
- The till broadcasts by wrapping `renderCart()` â€” which already runs on every add, quantity change, removal and discount edit â€” plus the payment inputs. **The cart logic itself was not modified.**
- Three states: idle (welcome), active (items and totals), paid (receipt token, auto-returning to idle after 12s).
- Item names are rendered with `textContent` only, so a product name cannot inject markup into a customer-facing screen.
- Shop identity and payment instructions come from Shop Settings.
- Hidden when `BroadcastChannel` is unavailable.

A `hashchange` listener reloads once if `#customer` is added to an already-open tab, because a hash change alone is a same-document navigation and would not re-run the initialiser.

> **Styling note.** The original request for this feature specified Tailwind utility classes (`bg-slate-900`, `text-indigo-400`, `from-emerald-600`) and the brand name "NEXUS COFFEE CO.". Those were **not** used: they conflict with this system's no-second-framework rule, its teal identity, and the entire purpose of Â§22.2. The architecture requested â€” BroadcastChannel, `#customer` hash, `window.open` pop-up, the three states â€” was implemented exactly as specified.

### 22.10 Navigation

Reduced to seven destinations, in this order: Dashboard, Manager overview, Point of Sale, Inventory, Finance, Reports, Administration.

**Reports sits last in the working area, below Finance** (moved there 13 August 2026). It is a top-level link rather than a section because the Reporting Centre already groups the reports inside itself, and it belongs after the modules rather than among them — it reads from every one of them. It shows for anyone holding at least one reportable module key; each group is gated again inside.

Three naming faults were fixed:

- **"Account" vs "Accounting"** â€” now **Administration** and **Finance**.
- **Three pages called "Dashboard"/"Overview"** â€” now "Inventory overview" and "Finance overview".
- **Sections sat under headings repeating their own names** ("Sell" > "Sales"). The redundant headings are gone.

The Finance section gained an unclosed-days badge, matching the key `ui.js` already knew about.

**All `mx-*` class names are unchanged** â€” they are the `ui.js` contract.

The dashboard now leads with a **Needs attention** panel above the KPIs, collecting out-of-stock, low-stock, unclosed days, open purchase orders, unpriced products and pending stock requests. Each row is gated by the same permission as the page it links to. Its empty state names what was checked rather than just saying "nothing". **No existing query or calculation was changed**; one new count (unclosed days) was added.


---

## 23. Reporting system

*Added 13 August 2026.*

### 23.1 Structure

| File | Lines | Purpose |
|---|---|---|
| `admin/reports.php` | 104 | The Reporting Centre â€” an index of every report, grouped |
| `admin/report.php` | 1,029 | One renderer for all 23 reports: query, filters, table, CSV, PDF, print |
| `includes/report_functions.php` | 452 | Date ranges, the report catalogue, permissions, and the three output formats |
| `includes/pdf_writer.php` | 199 | Minimal PDF generator (see Â§23.7) |

Reports were previously scattered: nine inventory reports behind a dropdown on `inventory-reports.php`, each financial report on its own page, and **almost no sales reporting at all** â€” the sales data supported eight dimensions and only two were exposed.

`admin/inventory-reports.php`, `profit-loss.php`, `journal.php`, `z-report.php` and `expenses.php` all remain and still work; the Centre sits alongside them.

### 23.2 The 23 reports

| Group | Module key | Reports |
|---|---|---|
| **Sales** | `pos_sales` | summary, by product, by category, by department, by cashier, by terminal, by payment, transaction history |
| **Inventory** | `inventory` | valuation, low & out of stock, movements, most consumed, slow-moving, waste, adjustments |
| **Purchasing** | `purchasing` | supplier purchases, purchase orders, goods received |
| **Accounting** | `accounting` | profit & loss, trial balance, journal, expenses, daily-close history |

**Deliberately absent, because the data does not exist:** batch reporting (`inv_batches` is never written to), stock-by-location (there is no per-location quantity â€” `inv_items.current_stock` is a single global figure), and refunds (only voids exist). Offering these would mean inventing data.

### 23.3 The accuracy rule

**Financial reports call the existing functions. They do not reimplement them.**

| Report | Source |
|---|---|
| Profit & Loss | `accProfitAndLoss()`, with the same COGS split `profit-loss.php` uses |
| Trial balance | `accTrialBalance()` |
| Journal | `accGetJournal()` |
| Expenses | `accGetExpenses()` |
| Daily close | `accRecentCloses()` |

Sales and stock reports aggregate the recorded rows directly and never recompute a total the system already stored â€” a sale's `gross_profit` is read, not recalculated. Stock is valued at `average_cost` exactly as `recordStockMovement()` maintains it; **no alternative valuation method was introduced**.

Nothing is calculated in the browser. Charts plot server-computed values.

Verified against figures captured before any change:

| Report | Value | Source |
|---|---|---|
| Sales summary â€” net / profit | 33,800.00 / 7,050.00 | `sales_transactions` |
| Sales by payment â€” tendered | 35,000.00 | `sales_payments` |
| Stock valuation | 6,013,450.00 | `SUM(stock Ã— avg_cost)` |
| P&L revenue / net profit | 33,800.00 / 7,050.00 | `accProfitAndLoss()` |
| Trial balance | 6,107,750.00 both sides | `acc_journal_lines` |

### 23.4 Permissions

Each report is gated by `requireModule()` on the module its data already lives in â€” **no new permission was created**. The landing page shows only groups the role can open.

| Role | Groups visible | Financial reports |
|---|---|---|
| Cashier | Sales | **Blocked** |
| Storekeeper | Inventory, Purchasing | **Blocked** |
| Manager | All four | Allowed |
| Administrator | All four | Allowed |

Verified with real accounts of each role.

### 23.5 Filters

`reportResolveRange()` returns `[from, to, label, preset]` from seven presets (today, yesterday, this week, this month, last month, this year, custom). It uses the application's own timezone, so "today" means the same thing here as at the till. **Accounting period logic is untouched** â€” this only picks two dates.

Malformed input degrades rather than errors: an inverted custom range is swapped, an unparseable date falls back to this month, an unknown report key redirects to the Centre.

Optional filters appear only where the report supports them: department, cashier, terminal, payment method, category, supplier, item. All are bound parameters.

### 23.6 Pagination

Server-side `LIMIT`/`OFFSET` with a `COUNT` over the same `FROM`/`WHERE`, so the pager total can never disagree with the rows shown. Seven reports use `rqPaged()`; Journal and Expenses page in PHP because they come from the accounting helpers whose logic must not change â€” their footers say **"Page total"** while the summary cards report the whole period.

Aggregates bounded by their own dimension (departments, payment methods, ledger accounts) are not paginated; a pager there would be noise.

**Exports are never paged.** A CSV or PDF must be the whole filtered set â€” handing someone page 3 of 9 as "the report" would be worse than useless. `$isExport` lifts the window and applies a 10,000-row safety cap instead.

Rows per page is selectable (25/50/100/200) from a whitelist, so the parameter cannot be abused to pull an entire table in one request.

### 23.7 Output formats

All three are produced from **the same server-side `$rows` the screen rendered** â€” never a second query, never a client-side copy of the DOM.

| Format | Notes |
|---|---|
| **CSV** | UTF-8 with BOM so Excel opens accented names correctly |
| **PDF** | Shop letterhead, auto-landscape past six columns, content-derived column widths, header repeated on every page |
| **Print** | Separate minimal document, no sidebar or filters, browser print dialog |

**Why `pdf_writer.php` exists rather than a library:** this installation has no Composer and no vendor directory, so any library would have to be committed by hand â€” third-party code inside a system handling the shop's money, to be patched forever. The reports are plain text in columns, a small enough target to write against the PDF specification directly. It also keeps month-end reporting working offline, which matters given the CDN dependence documented in Â§21.

It supports A4 portrait/landscape, the standard Helvetica fonts (so nothing is embedded), text, rules, filled rectangles and automatic pagination. **It does not support images, embedded fonts or unicode beyond WinAnsi.** If a report ever needs those, that is the point to reconsider a library â€” `reportEmitPdf()` is a single call site.

Validated by: independent parsing of the xref table with every byte offset checked (0 bad across 7 files), and **pdf.js â€” the reference implementation â€” parsing a generated file** and returning the correct figures. Multi-page output confirmed to repeat the letterhead and column headers.

*Not visually confirmed:* the automation environment returned HTTP 204 for PDF fetches, so a rendered page could not be screenshotted. A real parser accepts the files and the text is correct, but the visual layout has not been eyeballed.

### 23.8 Drill-down

Reuses the existing detail screens rather than building new ones. Eleven cells carry links:

| From | Click | Opens |
|---|---|---|
| Sales summary | a day | Transactions for that day |
| Transactions | receipt no. | `pos-receipt.php` |
| Sales by product | a product | That item's stock movements |
| Stock valuation / Low stock | an item | That item's stock movements |
| Profit & Loss, Trial balance | an account | `journal-entry.php` â€” its ledger for the same period |
| Journal | entry no. | `journal-entry.php` â€” its lines |
| Purchase orders, Goods received | PO number | `inventory-po-view.php` |

The period travels with the link, so drilling from a January P&L shows January entries.

### 23.9 Performance

**The N+1 in `chart-of-accounts.php` is fixed.** It called `accAccountBalance()` once per account â€” 29 queries, 44.2 ms. `accAllAccountBalances()` does it in one grouped query at 0.9 ms, roughly **48Ã— faster**.

That helper mirrors `accAccountBalance()` exactly, including the `(j.id IS NOT NULL OR l.id IS NULL)` guard that excludes lines whose journal is not posted, and it reads its sign convention from `accAccountTypes()` rather than duplicating it. An earlier draft omitted both and would have silently reported different balances; it was corrected and **proved identical for all 29 accounts before being swapped in**.

Settings are read once per request via the static cache in `shop_settings.php`, so a report's letterhead costs one query rather than a dozen.

### 23.10 Errors and empty states

Query failures are logged with `error_log()` and shown as *"This report could not be produced. The problem has been logged."* â€” **no SQL, no file paths, no stack traces**. Verified with four malformed-input cases.

An empty result names the period and offers to clear the filters, rather than showing a blank table.

### 23.11 Limitations

- Journal and Expenses fetch up to 10,000 rows before slicing, because their helpers take a limit but no offset. Fine at this scale; a genuinely large ledger would want offset support added to those helpers.
- Column widths in the PDF are estimated from character counts, not real font metrics, so a very wide value can still be truncated with an ellipsis.
- The reporting centre is not offline-capable beyond the PDF path; the screen itself still needs Bootstrap and Font Awesome from a CDN (see §21).

### 23.12 Mobile verification

*Verified 13 August 2026.* Every report was loaded and inspected at a phone width, not merely styled for one.

| Checked | Result |
|---|---|
| All 23 reports at 390px | **0 failures** |
| Sales summary, transactions, valuation, movements, P&L, trial balance, journal at 768px | **0 failures** |
| 10 other admin pages at 390px (dashboard, reports index, inventory items, customers, shop settings, chart of accounts, Z-report, suppliers, manager overview, profile) | **0 failures** |

Each check asserted: the sidebar does not overlap the content, the page body does not scroll horizontally, the filter panel is collapsed behind its button, the table scrolls inside `.ui-table-wrap` rather than stretching the page, and summary figures stay at 18px rather than shrinking to unreadable. The filter toggle (`display: none` to `flex`, `aria-expanded` to `true`), the sidebar drawer (closed, opened, reclosed, with its overlay) and dark theme were each exercised at 390px.

**A real bug was found and fixed in the process.** Below 992px the sidebar went off-canvas via `left: calc(-1 * var(--sidebar-w) - 8px)`, but `--sidebar-w` is redefined down to 234px and then 214px at narrower breakpoints while the mobile `width` is hardcoded at 258px. The two disagreed, so the drawer sat **36px on-screen at `z-index: 1000`**, covering the left edge of every admin page on a phone or tablet - "Net sales" rendered as "et sales". It now uses `transform: translateX(-100%)`, which cannot drift from the width whatever either value becomes. `.rp-drill` also gained `white-space: nowrap` so a drill-down date does not wrap to four lines.

*Testing note:* Chrome would not size its window below roughly 920 CSS pixels on this machine, so the media queries never fired in a resized window and the bug was invisible. The pages were loaded inside a 390px-wide iframe instead - an iframe has its own browsing context, so media queries genuinely evaluate against it rather than being simulated. The harness page was deleted afterwards. Desktop rendering was re-checked after the fix (6 pages, HTTP 200, no PHP errors).

---

## 24. Business configuration

*Added 13 August 2026.*

### 24.1 What changed, and why

The system was written for one shop. Three departments — supermarket, stationery, general — were compiled into the source as an `ENUM` on seven tables and as a PHP array in `catalog_schema.php`. A different retail business could not use the software without a schema change.

It is now **configurable rather than business-specific**. The application supplies the retail infrastructure; the administrator describes the business:

```
Business type  →  Departments  →  Categories  →  Products
                        ↓
        Inventory · Purchasing · POS · Accounting · Reports
```

Every business runs the **same** authentication, users, products, inventory, purchasing, POS, sales, customers, accounting and reports. Configuration decides what is displayed and how stock is organised — never which code runs.

### 24.2 Departments are data

| | Before | After |
|---|---|---|
| Definition | PHP array of three | `retail_departments` rows |
| Storage | `ENUM('supermarket','stationery','general')` × 7 tables | `VARCHAR(32)` × 7 tables |
| Add one | Schema change | A form |
| Rename | Impossible | Safe — the key never moves |
| On/off | `dept_<key>_enabled` in `inv_settings` | `is_active` on the row |

```sql
CREATE TABLE retail_departments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  dept_key   VARCHAR(32) NOT NULL UNIQUE,   -- generated once, never changes
  name       VARCHAR(60) NOT NULL,          -- what the shop calls it
  icon       VARCHAR(40) NOT NULL DEFAULT 'fa-boxes-stacked',
  colour     VARCHAR(9)  NOT NULL DEFAULT '#8b9aa2',
  sort_order INT NOT NULL DEFAULT 0,
  is_active  TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
```

**Why the key is immutable.** `dept_key` is stamped on products, categories, sales, sale lines, tills, journal entries and expenses. If renaming a department rewrote it, a year of history would have to be rewritten with it — and any row missed would be orphaned. `catalogDepartmentKeyFromName()` therefore runs only at creation, and `catalogUpdateDepartment()` changes the name, icon and colour only. A shop can rename "Medicines" to "Pharmacy Stock" at any time and every past sale follows.

**Widening the columns is not destructive.** ENUM values are stored as strings; `MODIFY … VARCHAR(32) NOT NULL DEFAULT 'general'` preserves every one of them, along with the NOT NULL and the default. That is why `catalogWidenDepartmentColumns()` sits in the installer rather than in a `.sql` file the user must remember to run. Each column is checked first, so it runs once and then costs nothing.

**Deleting follows the rule the rest of the system follows.** `catalogDeleteDepartment()` counts references across products, categories, sales, tills, journal entries and expenses. Zero references, and it is deleted; anything at all, and it is **disabled instead**, with the flash message naming the counts. At least one department must remain active — a shop with none has a till that can sell nothing and no way back through the UI.

### 24.3 Business type

`includes/business_types.php` holds nine types: General Retail, Supermarket, Pharmacy / Duka la Dawa, Stationery, Cosmetics, Electronics, Hardware, General Merchandise, and **Other** (free text, e.g. "Building Materials Shop").

A business type is a **classification plus suggestions**. It stores two settings (`shop_business_type`, `shop_business_type_other`) and can seed departments, categories and units. **No core code branches on the type key** — there is no `if ($type === 'pharmacy')` anywhere — so adding a tenth type is a data change in `businessTypeCatalogue()` and nothing else.

> **Selecting "Pharmacy" does not make this a pharmacy management system.** There are no prescriptions, patient records, clinical records, diagnosis or controlled-drug tracking, and the wizard says so on the card itself. The system remains a retail POS. Industry-specific capability, if it is ever built, belongs in a separate module.

`businessApplyTemplate()` is **additive and idempotent**: departments and categories already present by name are skipped, nothing is renamed, nothing is deleted. Applying the same template twice creates nothing the second time (verified). Template unit names deliberately match the core seed's spelling — `Liters`, `Milliliters`, `Meters` — because a mismatch silently creates a second unit meaning the same thing. That was caught in testing, when `Litres` and `Liters` both appeared.

### 24.4 The setup wizard

`admin/setup.php`, guarded by `requireModule('shop_settings')` — administrator only, **no new permission was created**.

| Step | Configures | Writes to |
|---|---|---|
| 1 | Business type (+ optional template) | `inv_settings`, and the template's departments/categories/units |
| 2 | Name, trading name, description, address, phone, WhatsApp, email, website, TIN, VRN | `inv_settings` via `shop_settings.php` |
| 3 | Departments — add, remove, review | `retail_departments` |
| 4 | Categories, each in a department | `inv_categories` |
| 5 | Units of measure | `inv_units` |
| 6 | Locations | `inv_locations` |
| 7 | Suppliers | `inv_suppliers` |
| 8 | Payment methods shown on receipts | `shop_payment_methods` |
| 9 | Receipt header, footer and toggles | `inv_settings` |
| 10 | Review, then complete | `setup_completed` |

Every step is **additive** — the wizard has no code path that deletes or resets anything. Finishing it sets a flag and nothing else; the same screens stay available under Administration, and the structure can be changed whenever the business changes. The review step blocks completion only when no department is active, because products could not then be created.

### 24.5 Existing installations

**An installation that is already trading is never shown the wizard.** `businessSyncSetupState()` runs during `posBoot()` / `catalogBoot()` and marks setup complete when the database contains any products, sales, stock movements or purchase orders, recording `setup_skipped_existing_data = 1` so the reason is visible later.

The upgrade path was tested against a clone of the live database rolled back to the pre-change schema:

| Check | Result |
|---|---|
| Products, sales, sale lines, journal entries, categories, tills | **Byte-identical**, row for row |
| Ledger | 6,107,750.00 = 6,107,750.00, unchanged |
| Stock at cost | 6,013,450.00, unchanged |
| Registry seeded from existing data | supermarket, stationery, general — with their original labels, icons and colours |
| Administrator's on/off choices | Carried over (a disabled department stayed disabled) |
| All seven columns widened | 7 of 7, 0 still ENUM |
| Wizard | Not triggered; flagged as pre-existing |
| Renaming a legacy department | 22 products stayed attached to the key |

### 24.6 What a fresh install gets

Deliberately almost nothing, because assuming would mean hardcoding a business:

- **One neutral department**, "General", so a product has somewhere to live.
- **One neutral till**, "Main Till" — not "Supermarket Counter".
- **No categories at all.** The old installer seeded twenty supermarket and stationery categories into every new database; that seed has been removed.
- The generic unit list (pieces, packs, boxes, kilograms…) stays, since units are trade-neutral and the wizard can add more.

### 24.7 Integration points

| Area | How it uses departments now |
|---|---|
| **POS** | Tabs are built from `catalogActiveDepartments()`. A till opens on its assigned department, whatever it is called; a till with **no department**, with the **general key**, or with a department that was removed or disabled, opens on everything (see below). Barcode scanning, split payments, held sales and checkout are untouched. |
| **Products** | The department dropdown is the registry; a new item is stamped explicitly — following its category's department when it has one — never left to the column default. |
| **Inventory** | Operational queries filter through `catalogDepartmentFilterSql()`, unchanged. |
| **Purchasing** | Department-agnostic; nothing needed changing. |
| **Dashboard** | "Today by Department" iterates the shop's own departments, including any that sold today but have since been retired. |
| **Z-report** | An open day is split live; a closed day reads `acc_daily_close_departments`. |
| **Reports** | Sales by department groups on the stored key and labels through the registry. The catalogue no longer describes the report as "supermarket against stationery". |
| **Accounting** | `accDayFigures()` groups by department instead of three fixed `CASE WHEN`s. Cost of sales is still one aggregate over the day's lines — that query was left alone. |

#### Why the general key is still special at the till

The first version of this work removed that special case, on the grounds that a hardcoded department name driving behaviour is exactly what the configuration work set out to eliminate. A till assigned to a department then opened on it, full stop.

That was reverted at the shop's request, and the reasoning is sound: `general` is the department every installation starts with and the one a mixed till is assigned to, so a shop whose General Till has always shown the whole range would have found it showing one department's stock after an upgrade. Restoring the rule keeps that behaviour exactly as it was.

It is expressed as `catalogDefaultDepartmentKey()` rather than a bare `'general'` literal, and the reasoning is written at the call site, so it reads as the deliberate compatibility rule it is rather than a leftover assumption about supermarkets.

**The consequence to be aware of:** a newly configured shop that keeps a department called "General" will find a till assigned to it shows the whole range rather than that department's stock. That is the old semantics applying to any shop that keeps the default department, not a bug. A shop that wants a genuinely restricted "general goods" counter should name the department something else — the key is what matters, and it is generated from the name at creation.

Verified on the running system after the revert:

| Till | Opens on | Products shown |
|---|---|---|
| Supermarket Counter (`supermarket`) | Supermarket | 22, supermarket only |
| Stationery Counter (`stationery`) | Stationery | 18, stationery only |
| General Till (`general`) | All Departments | 40 — the whole range |
### 24.8 The daily close

`acc_daily_close` had three fixed columns, which cannot describe a shop with five departments. They are **kept** — a closed day is an immutable snapshot and its stored figures must never move — and a child table was added:

```sql
CREATE TABLE acc_daily_close_departments (
  id INT AUTO_INCREMENT PRIMARY KEY,
  close_id  INT NOT NULL,
  dept_key  VARCHAR(32) NOT NULL,
  dept_name VARCHAR(60) NOT NULL,        -- the name AS AT closing
  sales     DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  UNIQUE KEY (close_id, dept_key),
  FOREIGN KEY (close_id) REFERENCES acc_daily_close (id) ON DELETE CASCADE
);
```

Rows are written **inside the close transaction**, so a close can never exist without its breakdown. The department name is stored alongside the key, so a Z-report reprinted months later shows the name the department had on the day. `accCloseDepartments()` falls back to the three original columns for days closed before this existed.

### 24.9 Configuration dependencies

`admin/partials/setup-required.php` is included by the product screens. When there is no active department (or no unit, on the inventory item screen) it renders a named notice with a direct link, rather than letting a user create a record that cannot work.

It is deliberately a notice, not a redirect: an administrator who lands on the screen they asked for and is told exactly what is missing learns more than one who is bounced elsewhere.

### 24.10 Multi-shop

**One shop profile per installation.** This was verified rather than assumed: no `shop_id`, `tenant_id`, `company_id`, `branch_id` or `org_id` column exists on any table, and `inv_settings` is a flat global store (see §22.1). Being able to change the shop name is not multi-tenancy, and nothing here claims otherwise. What the configuration system does provide is the absence of hardcoded business identity, so the same source can be deployed for another shop with its own database.

### 24.11 Testing

Nine business types were each built on a **fresh database from the same unmodified source**, and taken through the full retail path: schema install → template → department rename → category → unit → supplier → product → stock intake → POS grid → barcode scan → department filter → checkout → ledger → daily figures → daily close → departmental breakdown → P&L → disable → delete protection.

| Configuration | Department exercised | Result |
|---|---|---|
| General Retail | `groceries` | All passed |
| Supermarket | `food` | All passed |
| Stationery | `writing_materials` | All passed |
| Pharmacy / Duka la Dawa | `medicines` | All passed |
| Cosmetics | `skin_care` | All passed |
| Electronics | `phones_accessories` | All passed |
| Hardware | `plumbing` | All passed |
| General Merchandise | `household` | All passed |
| Other (custom) | `general` | All passed |

Each run also asserted the load-bearing invariants: **debits = credits**, and **Inventory Asset (1200) = SUM(stock × average cost)**. Both held in every configuration.

Mobile: the wizard and the departments screen were loaded at 390px — no horizontal page scroll, no sidebar overlap, and the ten-step rail scrolls sideways inside its own card rather than wrapping.

### 24.12 Limitations

- **Business type does not imply industry features.** Restated because it matters: this is general retail capability only.
- **Locations remain labels.** `inv_items.current_stock` is a single global quantity, so a location does not carry its own stock balance. The wizard says so on the step.
- The `department` column default is still `'general'` for compatibility. Any INSERT that omits the column relies on a key the shop may not have, so every insert path sets it explicitly.
- Departments are reordered by moving one place at a time. There is no drag-and-drop — which is also why it works on a phone.
- A till's department is set by seed or SQL; there is still no terminals screen (unchanged by this work).

---

## 25. URL handling

*Added 13 August 2026, after two user-reported faults turned out to share one cause.*

### 25.1 What was reported

> *"I can't log out, also I can't access receipt prints."*

Both were real, and both came from the same place.

### 25.2 The cause

The clean role URLs in `.htaccess` were **internal rewrites**. A cashier signing in was sent to `/Home/pos/terminal`; Apache served `admin/pos.php` while the browser's address bar still read `/Home/pos/terminal`.

The browser resolves every relative URL on a page against the address it thinks it is at. So on that page:

| Link | Intended | Actually requested | Result |
|---|---|---|---|
| Exit POS → `pos.php` | `/Home/admin/pos.php` | `/Home/pos/pos.php` | **404** |
| Receipt → `pos-receipt.php?id=1` | `/Home/admin/pos-receipt.php?id=1` | `/Home/pos/pos-receipt.php?id=1` | **404** |
| 404 page → `index.php` | `/Home/index.php` | `/Home/pos/index.php` | **404** |
| `authDeny()` → `login.php` | `/Home/admin/login.php` | `/Home/pos/login.php` | **404** |

All four appear in the Apache access log, including the 404 page's own "Back to Home" 404ing — a cashier who hit the first fault had no working link anywhere on screen.

The blast radius was wider than the two symptoms: **every one of the 29 sidebar links** 404ed for any role landing on a clean URL, which is storekeepers (`/Home/inventory`) and managers (`/Home/manager/overview`) as well as cashiers.

### 25.3 The fix

**The clean routes now redirect (`R=302`) instead of rewriting.** The browser ends up at the real path, so relative links, form actions and asset URLs on all 38 admin pages resolve correctly — without editing hundreds of `href`s, and without a rule future pages have to remember.

Rejected alternatives, and why:

- **Rewriting every relative link to be absolute.** Hundreds of links across pages, partials, tables, breadcrumbs and JavaScript; one missed link leaves the same dead end, and every future page has to obey the rule.
- **A `<base href>` tag.** One line, but it also changes how `action=""` forms and relative asset paths resolve, which risks breaking POST handlers to fix navigation.

The redirect targets carry an `%{ENV:BASE}` prefix, computed by the standard Apache idiom, so the application still works whether it is served from `/Home/` or from the document root:

```apache
RewriteCond %{REQUEST_URI}::$1 ^(.*?/)(.*)::\2$
RewriteRule ^(.*)$ - [E=BASE:%1]
RewriteRule ^inventory/?$ %{ENV:BASE}admin/inventory-dashboard.php [R=302,L,QSA]
```

The clean URLs still work as bookmarks and are still what login forwards to; they now hand over to the real page instead of masquerading as it.

### 25.4 URL helpers

For URLs built in PHP, `includes/auth.php` provides:

| Function | Returns | Used by |
|---|---|---|
| `adminBaseUrl()` | `/Home/admin` | the two below |
| `adminUrl('page.php')` | `/Home/admin/page.php` | `authDeny()`, the till's `print_url`, the receipt window's own links |
| `appRootUrl()` | `/Home` | the till's Sign out (`logout.php` lives at the app root) |

They derive the path from `SCRIPT_NAME`, which is the *rewrite target* whatever the browser asked for, so they are correct under either routing style. `adminBaseUrl()` strips a trailing `/api` so endpoints under `admin/api/` resolve to pages one level up.

These are defence in depth: with the redirect in place the relative forms would work again, but a URL built explicitly cannot be broken by a future routing change.

### 25.5 No way out of the till

A second, independent fault in the same report: **`admin/pos.php` had no sign-out control at all** — the page has no sidebar, and its only navigation was "Exit POS", which pointed at `roleHome($role)`. For a cashier that is `pos.php`, the page they were already on.

So a cashier could not leave the till even with routing working: the link either reloaded the POS or 404ed.

Now:

- **Sign out is always shown**, for every role, and posts to `appRootUrl() . '/logout.php'` with a confirmation, since an accidental click would lose the cart.
- **Exit POS is shown only when the role has somewhere else to go** — `roleHome()` different from `pos.php`. A manager or administrator sees both controls; a cashier sees only Sign out.

Verified in the browser as the cashier account: from `/Home/pos/terminal` the till renders one control, `Sign out → /Home/logout.php`.

### 25.5a No route to a cashier's own sales

Related, and reported straight after: a cashier could not reach **Sales & receipts** from where they work.

The permissions were never the problem. `roleModules('cashier')` has always granted `pos` and `pos_sales`, `pos-sales.php` guards on exactly `pos_sales`, and that page already scopes a non-supervisor to their own till (`AND t.cashier_id = ?`) and refuses them the void action. A cashier who typed the URL got a correct, correctly-restricted page.

What was missing was a **link**. The till has no sidebar, so the screen a cashier lands on after signing in offered no route to their own sales, and reprinting a receipt meant knowing a URL.

`admin/pos.php` now carries a **Sales & receipts** button in the top bar, gated on `userCan('pos_sales')` — so cashiers, managers and administrators see it and a storekeeper never would. The return trip already existed: `pos-sales.php` has an **Open POS** button and, for roles that get one, the sidebar.

Its label is shown from the `md` breakpoint rather than `lg` like the optional Customer-screen and Full-screen controls beside it: on a small till screen this is a cashier's only route to their sales, so an unlabelled icon would hide exactly the thing they need.
### 25.6 Not a bug

The same report mentioned Access Denied pages. The access log shows the cashier account opening purchase orders, the trial balance and a purchase-order view — pages a cashier's role genuinely excludes. RBAC behaved correctly; those were not counted as faults.

### 25.7 Verified after the change

| Check | Result |
|---|---|
| All 7 clean URLs | 302 to the correct real path |
| Sidebar links from `/Home/inventory`, `/Home/admin/dashboard`, `/Home/pos/sales` | 29 of 29 resolve to real `/Home/admin/*.php` |
| Signed-out clean URL | Redirects to `/Home/admin/login.php`, which exists (previously `/Home/pos/login.php`, 404) |
| Receipt | Renders with the shop's identity, department and payment instructions |
| Till controls (cashier) | Sign out only, resolving correctly |
| Site root, unknown URL, assets, uploads guard | 200 / 404 page / 200 / 403 — all unchanged |
