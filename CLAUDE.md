# CLAUDE.md

Guidance for Claude Code when working in this repository.

## What this is

**OmniBiz** — a configurable Point of Sale, Inventory and Accounting system for retail businesses, running on XAMPP. The reference installation is a supermarket and stationery store in Iringa, Tanzania, but the software is deliberately not written for that shop: the business type, its departments, categories, units, locations, suppliers, payment methods and receipt are all configuration (`admin/setup.php`, `admin/departments.php`, `admin/shop-settings.php`). A duka la dawa, a hardware shop or a cosmetics counter runs the same source with no code changes.

It was converted in place from a laundry management system (originally "zeea-laundry"). All laundry, garment-processing and pickup/delivery logistics code has been removed, along with the customer-facing online storefront. A cleanup on 13 August 2026 deleted the last laundry-era files (the `paket_icons` / `package_icons` / `profil_admin` upload folders, unreferenced legacy images, the stale `POS_MODULE.md`, and the one-off `sql/` conversion scripts) and the dead auto-deduction UI. One cosmetic trace remains deliberately: the product upload folder is still `assets/uploads/shop_products/`, because renaming it would break stored image paths for no benefit.

| Module | Purpose | Key files |
|---|---|---|
| **POS** | Walk-in till, barcode scanning, split payments, receipts, held sales, voids | `admin/pos.php`, `includes/pos_*.php` |
| **Inventory** | Stock, suppliers, purchase orders, stock requests, barcodes | `admin/inventory-*.php`, `includes/inventory_*.php` |
| **Catalog** | Which items are sold, at what price, in which department | `admin/retail-products.php`, `includes/catalog_*.php` |
| **Accounting** | Chart of accounts, double-entry ledger, expenses, Z-report, P&L | `admin/accounting-*.php`, `admin/journal*.php`, `admin/z-report.php`, `admin/expenses.php`, `includes/accounting_*.php` |

### Where the documentation lives

`docs/` is the authoritative, verified documentation set, written from the source and the running system:

- `docs/USER_GUIDE.md` — every screen from the user's side, with real screenshots in `docs/screenshots/`
- `docs/TECHNICAL_DOCUMENTATION.md` — architecture, all 31 tables, RBAC map, API contracts, security findings, known limitations
- `docs/SYSTEM_OPERATIONS_HOW_TO_GUIDE.md` — step-by-step procedures, daily workflow, troubleshooting

The admin panel's shared visual layer (design tokens, sidebar, cards, tables, modals, toasts, client-side filtering) is documented in `UI_COMPONENTS.md` — use those components rather than inventing new markup. It was rewritten on 13 August 2026 against the current `ui.css` / `ui.js`, with every class and method verified, so it can be trusted. **The POS terminal is explicitly outside that system** — `admin/pos.php` has its own inline CSS and loads neither `ui.css` nor `ui.js`. `INVENTORY-MODULE.md` documents the inventory module's own design reasoning and file inventory; it was rewritten on 13 August 2026 to strip laundry residue, and `docs/` remains the deeper reference.

`POS_MODULE.md` was **deleted** in that cleanup — it predated the catalog rename and the split-payment work and had become actively misleading. `docs/TECHNICAL_DOCUMENTATION.md` §9 and §10 replace it.

## Stack & conventions

Plain **PHP 8.2 + mysqli** (procedural/prepared statements, no framework, no ORM, **no build step, no package manager**), **Bootstrap 5.3.2** + Font Awesome 6.4.0 + Poppins from CDN, vanilla JavaScript. MariaDB 10.4 runs on **port 3306**, database **`retailer_shop`** (see `includes/db.php`). Apache serves the app on **port 8081** at `http://localhost:8081/Home/`.

`includes/db.php` calls `mysqli_report(MYSQLI_REPORT_OFF)` before connecting. **Do not remove that line.** PHP 8.1 made mysqli throw by default, but roughly forty guarded probe queries here use the `@$conn->query(...)`-and-check-the-return idiom; under the throwing default a fresh install dies on its first page load.

Follow the existing conventions rather than introducing new ones:

- **Prepared statements everywhere.** Never interpolate user input into SQL — as of 13 August 2026 there are no exceptions left, so there is no precedent to copy. Double-check `bind_param` type strings against the column list; they're a common source of silent bugs. When a filter is optional, build `$conds`/`$params`/`$types` together so the type string and the argument list cannot drift apart. Escape LIKE wildcards (`%`, `_`, backslash) in the **bound value**, or a search for "50%" matches everything. The only surviving `real_escape_string()` calls take internal values (department keys, table names in `SHOW` statements) — never request data.
- **Self-installing schemas.** Each module has an `includes/*_schema.php` that runs `CREATE TABLE IF NOT EXISTS` plus guarded `ALTER`s, version-flagged in `inv_settings`. Adding a table or column means bumping that module's version constant and adding a guarded statement — never a manual migration the user has to remember. **Destructive** changes (DROP, DELETE) are the exception and must never go in a schema installer: write them as a standalone `.sql` file for the user to run deliberately, and say so. (The original conversion's destructive half lived in `sql/retail_migration.sql`; it had been run and was removed in the 13 August 2026 cleanup, so `sql/` no longer exists.)
- **Never duplicate data across modules.** A product IS an `inv_items` row, extended 1:1 by `retail_product_details` (price, brand, till visibility) and illustrated by `retail_product_images`. The POS sells those same rows. Customers are the `customer` table, identified by phone number.
- **All stock changes go through `recordStockMovement()`** (`includes/inventory_functions.php`), which locks the item row, writes an audit row with before/after quantities, and maintains weighted-average cost. Pass `ownTransaction: false` when calling inside a larger transaction.
- **All money movements go through `accPostEntry()`** (`includes/accounting_functions.php`), which refuses to write anything unless debits equal credits. Account balances are never stored — they are summed from `acc_journal_lines` on demand, so the books cannot drift from their own history. Entries are corrected by *reversal* (a mirror entry), never by editing.
- **A checkout is one transaction.** `posCheckout()` validates stock, writes the sale, deducts inventory and posts the ledger entry inside a single database transaction. If the ledger posting fails, the sale rolls back — takings can never exist on the till but be missing from the books. Receiving goods (`poReceiveStock()`) works the same way.
- **Soft deletes** (`deleted_at`) rather than hard deletes, so history survives.
- **Every page that handles POST calls `csrfRequire()`** — once, immediately after the role guard, before any handler. It is a no-op on GET. **Every POST form needs `<?php echo csrfField(); ?>`**, and any `fetch()` that mutates must send the `X-CSRF-Token` header. A new page without these will silently reject its own submissions, which looks like a bug in the form. See `includes/csrf.php`.
- **A record that is in use is deactivated, never deleted.** Categories, units, suppliers and non-system ledger accounts all follow this: count the references first; delete only when the count is zero, otherwise set `is_active = 0` and say so in the flash message ("…is used by 4 product(s), so it was deactivated rather than deleted"). Copy that pattern for any new lookup table.
- **Money is `DECIMAL(14,2)`**, quantities `DECIMAL(14,3)`, unit costs `DECIMAL(14,4)`, currency displayed as `Tsh` with thousands separators.
- Prices, totals and stock are **always recalculated server-side**. The browser sends ids and quantities only.
- Timezone `Africa/Dar_es_Salaam` (set once in `includes/db.php`); phone numbers normalise to `+255…`.
- **Everything is in English** — filenames, UI strings, comments, column names.
- **Never call `session_start()` directly.** Call `appSessionStart()` (`includes/session.php`), which sets `HttpOnly`, `SameSite=Lax`, `Secure`-on-HTTPS and `use_strict_mode` *before* the session begins. Those settings are worthless if any entry point starts the session first with PHP's defaults, so all five that need one — `auth.php`, `csrf.php`, `login.php`, `logout.php`, `access-denied.php` — go through it. The cookie is `mira_session`, not `PHPSESSID`, so a neighbouring app on the same XAMPP host cannot clobber it.
- **"Keep me signed in" tokens are issued in exactly one place** — `login.php`, after a verified password, via `rememberIssue()`. Never issue one anywhere else. Whenever an account's trust changes (password, role, deactivation), call `rememberForgetUser()` so remembered devices are signed out; `includes/remember_me.php` explains why.

### PHP gotchas that have already bitten this codebase

- **Numeric-string array keys become ints.** `$byAccount['1000']` comes back as int `1000`, and `accPostEntry()` reads an int as an account **ID** rather than a **CODE** — which silently broke every split checkout. Any code that aggregates by account code must cast back: `['account' => (string)$code, …]`.
- **`?>` inside a `//` comment ends PHP mode.** Don't write a closing tag in a single-line comment.

## Payments

A sale can be settled with several methods at once. `posPaymentMethods()` (`includes/pos_schema.php`) is the single source of truth, mapping each method to its ledger account:

| Method | Label | Account |
|---|---|---|
| `cash` | Cash | 1000 Cash on Hand |
| `lipa_namba` | Lipa Namba/Mobile | 1010 Mobile Money |
| `bank` | Bank transfer | 1020 Bank Account |
| `card` | Card | 1020 Bank Account |

Each tender gets a row in `sales_payments`; `sales_transactions.payment_method` holds the single method, or the literal `'split'`. Two rules `posCheckout()` enforces and any new payment code must preserve:

- **An electronic tender may never exceed the total.** Change comes only out of the drawer, so an overpayment by transfer would owe change the till cannot give.
- **The ledger is posted net of change.** Change leaves the drawer, so debiting the full cash tendered overstates Cash on Hand by exactly the change. `posVoidSale()` mirrors the same netting when refunding.

## The accounting behind stock

Stock is an **asset** from the moment it arrives and only becomes an **expense** when it sells. Every movement of value follows from that:

| Event | Debit | Credit | Posted by |
|---|---|---|---|
| Receive goods on a PO | Inventory Asset | Accounts Payable | `poReceiveStock()` |
| Pay the supplier | Accounts Payable | Cash / Mobile / Bank | `poRecordPayment()` |
| Sell at the till | Cash / Mobile / Bank + COGS | Sales Revenue + Inventory Asset | `posCheckout()` |
| Void a sale | Sales Returns + Inventory | Cash / Mobile / Bank + COGS | `posVoidSale()` |
| Record an expense | the expense account | Cash / Mobile / Payable | `accRecordExpense()` |
| Close the day short/over | Shrinkage / Cash | Cash / Other Income | `accCloseDay()` |
| Damage / expiry / loss | Stock Loss & Shrinkage | Inventory Asset | `stock_ledger.php` |
| Count shortfall | Stock Loss & Shrinkage | Inventory Asset | `stock_ledger.php` |
| Count surplus | Inventory Asset | Stock Loss & Shrinkage | `stock_ledger.php` |
| Internal issue (store use) | Store Consumables Used | Inventory Asset | `stock_ledger.php` |
| Receive without a PO | Inventory Asset | Accounts Payable | `stock_ledger.php` |
| Opening stock | Inventory Asset | Owner's Capital | `stock_ledger.php` |
| Return to supplier | Accounts Payable | Inventory Asset | `stock_ledger.php` |
| Location transfer | — | — | no ledger effect |

`includes/purchasing_functions.php` sits **above** both inventory and accounting — it is the only file that knows a goods receipt is simultaneously a stock movement and a ledger entry, which keeps the two modules below it free of each other.

`includes/stock_ledger.php` posts every *other* stock movement. It is `require_once`d **inside** `recordStockMovement()` rather than at the top of `inventory_functions.php`, because accounting depends on inventory and a top-level include the other way would be a cycle. Doing it there means the codebase invariant "all stock changes go through `recordStockMovement()`" now guarantees the books move too — no page can bypass it. Movements a higher layer already posts in aggregate (`reference_type` of `pos_sale` or `purchase_order`) are skipped so nothing is counted twice.

A movement with no unit cost is valued at the item's weighted-average cost; writing stock off at zero would hide the loss. Count surpluses are credited against Shrinkage rather than booked as income, so that account shows *net* stock loss for a period.

**The reconciliation that matters:** `Inventory Asset` (code 1200) in the ledger should always equal `SUM(current_stock * average_cost)` across `inv_items`. If it drifts, some path moved stock without posting. This is the single most useful health check in the system — run it after touching anything that moves stock.

Because the ledger's idempotency key is `(source_type, source_id)`, anything that can legitimately happen more than once per parent record gets its own event row: `inv_po_receipts` and `inv_po_payments` exist for exactly that reason (a PO can be received and paid in stages). Individual stock movements use their own `inv_stock_movements.id`.

**Caveat:** `transfer` is ledger-neutral, but `inv_items.current_stock` is a single global quantity with no per-location breakdown — so a transfer must be recorded as a matched pair or it will quietly destroy stock value. Nothing in the UI creates transfers today.

## Shop identity is configuration, not code

Business identity lives in the database, edited at `admin/shop-settings.php` (admin-only, module key `shop_settings`). **Never hardcode a shop name, address, phone or payment number in a page** - read it through `includes/shop_settings.php`:

| Need | Call |
|---|---|
| Shop name (never empty) | `shopName($conn)` |
| Any scalar setting | `shopSetting($conn, 'shop_email')` |
| A receipt on/off switch | `shopSettingOn($conn, 'receipt_show_tin')` |
| Logo URL (`''` when unset or file missing) | `shopLogoUrl($conn, '../')` |
| Address as one line | `shopAddressLine($conn)` |
| Payment instructions | `shopPaymentMethods($conn, true)` - enabled AND non-blank only |

Fallback order is **database value -> the old `BUSINESS_*` constant -> empty string**. The constants stay in `db.php` as that third tier; an install that never opens the settings screen looks unchanged. All settings load in **one** query per request via a static cache - don't reintroduce per-key queries.

Payment methods are the one thing that earned their own table (`shop_payment_methods`): several rows, each ordered and individually enabled. Everything else extends `inv_settings`.

## Theme

Light / dark / system, implemented **only** by redefining the tokens in section 21 of `ui.css`. Never duplicate a component rule for dark - if a component reads its colour from a token it already works. "System" deliberately sets **no** `data-theme` attribute so `prefers-color-scheme` stays authoritative. Preference is `localStorage.mxTheme`; an inline script in `inventory-header.php` applies it before first paint. **Print is force-overridden to light** - a receipt must never print white-on-black.

## Mobile

Below 992px the sidebar becomes a drawer. **It hides itself with `transform: translateX(-100%)`, never a computed `left`.** It used to sit at `left: calc(-1 * var(--sidebar-w) - 8px)` while its mobile `width` was a hardcoded 258px — but `--sidebar-w` shrinks to 234px then 214px at narrower breakpoints, so the two drifted and the drawer stayed 36px on-screen at `z-index: 1000`, clipping the left edge of every page on a phone. A percentage of its own width cannot drift; don't reintroduce a hand-computed offset.

Verified 13 August 2026: all 23 reports plus 10 other admin pages at 390px, and the main report screens at 768px, with zero failures. Chrome would not size its window below ~920 CSS px here, so the pages were loaded in a 390px-wide iframe — media queries evaluate against an iframe's own viewport, so that is a real test rather than a simulated one.

## POS additions

`admin/pos.php` now also carries a **full-screen** control (real Fullscreen API, synced from `fullscreenchange` so Esc keeps the label right) and a **customer display** (second screen, `BroadcastChannel('pos_sales_bus')`, same page loaded with `#customer`). Both are additive: they do not touch the cart, checkout, receipt, barcode listener, `SCAN_MAX_GAP_MS` or F2. The display listens only and never posts back, so it cannot affect a sale.

### Terminal session-locking (25 August 2026)

`pos_terminals` (schema v8) gained `locked_by_user_id` / `locked_by_username` / `locked_at` / `last_activity_at` — one cashier per till at a time, enforced **server-side**, not just by hiding the picker. Deliberately separate from `is_active` (enabled/deactivated is a configuration toggle; a lock is a live session).

- `posClaimTerminal()` (`includes/pos_functions.php`) does `SELECT ... FOR UPDATE` then check-then-write inside one transaction — the same lock-check-write shape as `disposeFromBatch()` / `recordStockMovement()`. Two simultaneous claims on the same till cannot both win (verified with a real two-process race, not just a unit test).
- `posTerminalLockState($terminal, $timeoutMinutes)` is the **one** place free/active/stale is decided from a row; every reader (the till picker, the header, `admin/pos-terminals.php`) calls it so they can never disagree. Timeout is `terminal_lock_timeout_minutes` in `inv_settings` (default 20) — read via `getInvSetting()`, never a literal.
- **No live switching.** `admin/pos.php`'s `select_terminal` POST handler rejects a claim attempt outright if the session already holds a *different* active lock — the only way to move to another till is to log out (which releases the lock) and log back in. This is checked server-side in the handler itself, not just by removing the header's picker UI.
- `admin/pos.php` blocks behind a static-backdrop "Select a Till" modal until a lock is actually held fresh on every load — there is no more auto-fallback to "the first active till" (that could hand a cashier a till someone else is mid-sale on). A 45s heartbeat (`admin/api/pos-terminal-heartbeat.php`) keeps `last_activity_at` current while the till is open; a failed heartbeat re-shows the modal.
- `logout.php` releases the lock (`posReleaseTerminal()`); `admin/pos-terminals.php` shows a Session status row (free/active/stale) and a **Force Release** action for admin/manager (`posForceReleaseTerminal()` — no extra role check inside the function itself; the page's own `requireModule('terminals')` is the gate, same pattern as `purchasing_approve`).
- While wiring the sidebar links for this, found and fixed a real bug: six Inventory links (`inventory-dashboard.php`, `inventory-items.php`, `inventory-movements.php`, `inventory-reports.php`, `inventory-categories.php`, `inventory-units.php`) were rendering for **any** role that opened the Inventory section — because that section opens for `stock_requests` alone (so a cashier can reach Stock Requests), and those six links predated that carve-out and were never wrapped in their own `userCan('inventory')` check the way their siblings (barcode, disposal, purchasing) already were. The underlying pages were already `requireModule('inventory')`-gated server-side, so this was a visibility bug, not an access hole — fixed in `admin/sidebar-admin.php` regardless, plus a stray breadcrumb link to `inventory-dashboard.php` on `admin/inventory-requests.php` that a cashier couldn't actually open.

### Held-sale ownership, isolation and lifecycle (25 August 2026)

Held sales used to be a bare `pos_held_sales` row with no ownership check anywhere: the list was unfiltered (every cashier's parked cart contents were embedded in every other cashier's page HTML), resume was 100% client-side (`prompt()`-adjacent `JSON.parse()` of data already in the DOM), and both checkout and cancel-cart deleted a client-supplied `held_sale_id` with no check that it belonged to the caller. All of that is closed now:

- `pos_held_sales` (schema v9) gained a `status` column — `held → {stale → expired}`, `held/stale → resumed → completed`, `held/stale → cancelled`, `held/stale/resumed → orphaned`. **A held sale is never hard-deleted again**; every terminal state is an `UPDATE`, so `posDeleteHeldSale()` no longer exists. "Voided" is **not** a stored status — when a sale that came from a held sale is later voided, `posVoidSale()` cross-references it and logs `held_sale_voided` against the original row, whose `status` correctly stays `completed` (that fact doesn't become false just because the sale was later reversed).
- `posGetHeldSalesForCashier($conn, $cashierId, $terminalId)` is the only reader `admin/pos.php` uses now — scoped to `cashier_id` AND `terminal_id` AND `status IN ('held','stale')`, so another cashier's cart never reaches the browser at all.
- `posResumeHeldSale()` / `posCompleteHeldSale()` are the ownership + concurrency gate, same `FOR UPDATE` shape as the terminal claim, keyed to the **session's own** cashier/terminal — never a client-supplied one. New endpoint `admin/api/pos-resume-held.php`; `admin/api/pos-checkout.php` and `pos-cancel-cart.php` were hardened the same way (session-derived `terminal_id`, not `$body['terminal_id']`).
- Stale/expiry thresholds are lazy-swept on read (`posSweepHeldSales()`, mirroring the batch-expiry lazy-recompute idiom already used elsewhere) — `held_sale_stale_minutes` / `held_sale_expiry_minutes` in `inv_settings` (defaults 30 / 120).
- **Logout orphans, never deletes, never auto-transfers.** `posOrphanHeldSalesForCashier()` flips a cashier's own `held`/`stale`/`resumed` rows to `orphaned` at logout. A *different* cashier claiming that same till next does **not** inherit them. The **same** cashier's own next login automatically reclaims their own orphaned sales back to `held` (`posReclaimOwnOrphanedHeldSales()`, called from `admin/pos.php`'s claim handler) — a different cashier claiming the till in between never triggers this.
- `admin/pos-held-sales.php` (module key `held_sales_review`, admin/manager) reviews every held sale across every till and recovers an orphaned/expired one to a **currently-active** cashier — read from that terminal's own live lock (`posGetTerminal()` + `posTerminalLockState()`), never a separately typed account. Every held-sale event (created/resumed/completed/cashier-logout/orphaned/reclaimed/stale/expired/cancelled/voided/manager-recovery) is written to the shared `inv_audit_log` and surfaced as a workflow timeline (`admin/api/pos-held-sale-history.php`) shown both inline in the Recover modal and via a standalone History button — built with `createElement`/`textContent`, never `innerHTML`, since the trail contains free text people typed.
- The "Hold Sale" label prompt is a proper in-page modal now (`#holdLabelModal` in `admin/pos.php`), not a native `prompt()` — the native dialog froze the tab for any kind of scripted interaction and was a bare OS popup for users.

## Reporting

`admin/reports.php` is the Reporting Centre; `admin/report.php` renders all 23 reports from one switch. Adding a report means adding an entry to `reportCatalogue()` in `includes/report_functions.php` and a `case` in that switch - not a new page.

**The accuracy rule is absolute.** Financial reports call the existing readers - `accProfitAndLoss()`, `accTrialBalance()`, `accGetJournal()`, `accGetExpenses()`, `accRecentCloses()` - and never reimplement their arithmetic. Sales and stock reports read stored values (`gross_profit`, `average_cost`); they do not recompute a total the system already holds. Nothing is calculated in the browser; charts plot server-computed numbers.

A report builds four things, then the shared renderer does the rest:

| Variable | Purpose |
|---|---|
| `$columns` | Header labels |
| `$rows` | Cells. A cell may be a string, or an array: `['text'=>…, 'href'=>…]` for drill-down, `'badge'=>…` for a status pill, `'row'=>'section\|sub\|total'` for financial hierarchy |
| `$footer` | Totals row |
| `$align` | `[colIndex => 1]` for right-aligned numerics |

Plus optionally `$metrics` (summary cards), `$chart`, and `$supports` (which filter controls to show).

- **Only offer a filter the report actually honours** - `$supports` drives which controls render.
- **Paginate with `rqPaged()`** for anything that can grow; skip it for aggregates bounded by their own dimension. **Never page an export** - `$isExport` lifts the window, because a CSV or PDF must be the whole filtered set.
- **CSV, PDF and print all come from the same `$rows`.** Never build a second query for an export, and never export what the browser drew.
- **Do not offer a report the data cannot support.** Batch, per-location stock and refunds are absent on purpose - `inv_batches` is never written, stock has no per-location breakdown, and only voids exist.
- Query failures are logged and shown as a generic message. **Never surface SQL, paths or stack traces.**

`includes/pdf_writer.php` is a small in-house PDF generator (core fonts, text and rules only). It exists because there is no Composer here and the shop needs month-end reports offline. It has one call site, `reportEmitPdf()`, so swapping in a library later is contained. It cannot do images, embedded fonts or unicode beyond WinAnsi - if a report needs those, that is the moment to reconsider.

**`accAllAccountBalances()` replaced an N+1** on the chart of accounts (29 queries → 1, ~48× faster). It mirrors `accAccountBalance()` exactly, including the posted-lines guard and the sign convention read from `accAccountTypes()`. If you touch either, keep them identical - they must never report different balances.

## Departments — configuration, not code

**This is a general retail system. It must not be written as a supermarket system.** The same source runs a duka la dawa, a hardware shop, a stationery shop or a cosmetics counter; what differs is configuration. Never hardcode a department name, a category or a business type into the core.

Departments live in **`retail_departments`** (`dept_key`, `name`, `icon`, `colour`, `sort_order`, `is_active`) and are created by the administrator at `admin/departments.php` or during `admin/setup.php`. Catalog schema v3 widened `department` from `ENUM('supermarket','stationery','general')` to `VARCHAR(32)` on all seven tables that carry it — `inv_items`, `inv_categories`, `sales_transactions`, `sales_transaction_items`, `pos_terminals`, `acc_journal`, `acc_expenses`. That widening is non-destructive (every stored value survives byte-for-byte), which is why it lives in the installer rather than a manual migration.

**The key is generated once from the name and never changes.** Renaming "Medicines" to "Pharmacy Stock" must not orphan a year of sales, so `catalogDepartmentKeyFromName()` runs at creation only; `catalogUpdateDepartment()` never touches `dept_key`.

Which function you call still matters:

| Use | For | Why |
|---|---|---|
| `catalogActiveDepartments($conn)` | Forward-looking lists: till grid, department tabs, new-product defaults | Only what is trading now |
| `catalogDepartmentFilterSql($conn, 'i')` | **Operational** SQL: product lists, stock levels, reorder alerts, valuations, pickers, barcode screens | Appends `AND i.department IN (…)`; returns `''` when everything is on |
| `catalogDepartments($conn)` | **Financial** history: ledger, past sales, Z-reports, P&L | A past sale must still render its department name after it is switched off |

`catalogDepartments()` takes an optional `$conn` and caches in `$GLOBALS['__catalog_dept_cache']`, filled during boot — that is why the ~30 existing call sites with no connection to hand still work. After any write to the registry call `catalogDepartmentsReload($conn)`, or the page will not see its own change. `catalogDepartmentLabel()` humanises an unknown key rather than substituting a wrong label, so a department deleted years later still renders its old sales.

**Never assume a department called `general` exists.** Use `catalogFirstActiveDepartment($conn)` for defaults. The column default is still `'general'` for compatibility, so any INSERT that omits `department` is a bug — `inventory-items.php` sets it explicitly, following the category's department when there is one.

A department in use is **disabled, never deleted** (`catalogDeleteDepartment()` counts references across all six tables first), and at least one must always stay active. Disabling still leaves financial history untouched, for the same reason as before: last month's figures must not change because of a settings toggle.

**One department key is special at the till, on purpose.** A `pos_terminals` row whose `department` is `catalogDefaultDepartmentKey()` (`general`) opens the POS on **everything**, exactly as a till with no department does. That is a deliberate compatibility rule — `general` is the department every install starts with and the one a mixed till is assigned to, so a General Till keeps showing the whole range after the configurability work. It is written as `catalogDefaultDepartmentKey()`, never a bare literal. Every other department behaves identically whatever the shop calls it: a till assigned to Plumbing or Medicines opens on that department.

### Business type

`admin/setup.php` is a ten-step wizard; `includes/business_types.php` holds the catalogue. A business type is a **classification plus suggested departments, categories and units** — nothing more. No core code branches on the type key, so adding one is a data change in `businessTypeCatalogue()` and nothing else.

**Selecting "Pharmacy" does not make this a pharmacy system.** There are no prescriptions, patient records, dispensing or controlled-drug tracking, and a business type must never be allowed to imply them. Industry-specific behaviour, if it ever exists, belongs in a separate module.

`businessApplyTemplate()` is additive and idempotent: anything already present by name is skipped, nothing is renamed, nothing is deleted. Template unit names must match the core seed's spelling (`Liters`, `Milliliters`, `Meters`) or the shop ends up with two units meaning the same thing.

**An existing installation is never pushed through the wizard.** `businessSyncSetupState()` runs during boot and marks any database that already has products, sales, stock movements or purchase orders as configured.

The daily close keeps its original `supermarket_sales` / `stationery_sales` / `general_sales` columns — a closed day is immutable — and gains `acc_daily_close_departments`, one row per department. `accCloseDepartments()` reads the child rows and falls back to those three columns for days closed before the change.

## Layout

```
includes/          db.php, auth.php, session.php, csrf.php, core_schema.php, remember_me.php,
                   shop_settings.php, business_types.php, uploads.php, report_functions.php,
                   pdf_writer.php, *_schema.php, *_functions.php, stock_ledger.php
admin/             all staff-facing pages (43 files); sidebar-admin.php + sidebar-nav.php +
                   inventory-header/footer.php are shared chrome
admin/partials/    page-header.php, empty-state.php, pagination.php, setup-required.php
admin/api/         JSON endpoints (POS scanning/checkout/stock-in/cancel/resume-held/
                   terminal-heartbeat/held-sale-history, customer lookup)
assets/css/admin/  styles.css (36-line legacy remnant) then ui.css (design system) - order matters
assets/js/admin/   ui.js exposes window.MX (toasts, filters, sidebar, modals, live alerts)
assets/uploads/    the only directory the app writes to - shop_products/,
                   profile_admin/, po_invoices/ (kept even when empty, so the app
                   never has to mkdir at runtime)
docs/              USER_GUIDE / TECHNICAL_DOCUMENTATION / SYSTEM_OPERATIONS_HOW_TO_GUIDE
                   + screenshots/
```

`admin/notifications-api.php` (not under `api/`) feeds the sidebar badge counts, polled every 30s by `MX.watchAlerts()`. Each count is individually gated by `userCan()`, so a role is never told a number it may not see.

## Roles & access

Five roles, defined by `roleModules()` in `includes/auth.php`:

| Role | Lands on | Can do |
|---|---|---|
| `admin` | `/admin/dashboard` | Everything, including the chart of accounts, manual journal entries, departments and setup |
| `manager` | `/manager/overview` | Everything operational plus reports, voids, users, terminals and held-sale recovery — but not the chart of accounts, journal entries or departments |
| `accountant` | `admin/accounting-dashboard.php` | The books, full stop — chart of accounts, journal, expenses, P&L, daily close history — plus read-only `sales_reports` to reconcile revenue. No POS, inventory or terminal access, and no `pos_sales` (they read sales through the report group, not the till's own transaction list) |
| `storekeeper` | `/inventory` | Stock intake, purchase orders, reorder alerts, barcodes, products, stock requests — but **cannot approve a purchase order or pay a supplier** |
| `cashier` | `/pos/terminal` | POS checkout, own-till receipts, customer lookup, stock requests, expiry alerts — nothing else |

Guard every admin page with `requireModule('key')` (preferred) or `requireRole([...])` as its first statement after including `auth.php`. The sidebar renders only modules the role may use via `userCan()`, and `roleHome()` / `roleHomeUrl()` decide where each role lands at login. When adding a page, add its role guard *and* its sidebar entry together — and check every link/button on it individually: a section-level `userCan()` check that gates the whole sidebar group is not enough, since a link inside that group with no check of its own renders for anyone who can open the group at all (the exact bug fixed on 25 August 2026 in `admin/sidebar-admin.php` - see "POS additions" below).

`authIsApiRequest()` makes the guards return **401/403 JSON** for anything under `admin/api/` instead of redirecting to an HTML login form one directory up.

The dead `reports` key (granted but never checked — Inventory Reports has always actually gated on `inventory`) was removed entirely on 25 August 2026; there is no bare `reports` key left to find in `roleModules()`. `pos_void` **is** enforced (`admin/pos-sales.php` reads `userCan('pos_void')`, not the role name, as of the same date) and `held_sales_review` (admin + manager, `admin/pos-held-sales.php`) is new. Don't assume a key is enforced just because it's in the list — grep for it.

**`purchasing_approve` (admin + manager only) IS enforced**, in `admin/inventory-po-view.php`. Spending decisions are separated from stock work: a storekeeper raises the order, uploads the invoice and books the goods in, but **approving an order, cancelling one that is already approved, and recording a payment to a supplier** all require the key. It is checked server-side before any handler runs, and a refusal is written to the audit log as `denied_<action>` — hiding the buttons is a courtesy, not the control. `poRecordPayment()` has exactly one call site, so that gate covers every path by which money reaches a supplier.

The clean role URLs above **redirect** (`R=302`) to the real `admin/*.php` files. `mod_rewrite` is required; without `.htaccess` every clean URL 404s.

**They must never go back to being internal rewrites.** A rewrite leaves the browser's address at `/Home/inventory` while `admin/inventory-dashboard.php` answers, so every relative link on the page resolves against `/Home/` - the sidebar's `inventory-items.php` becomes `/Home/inventory-items.php`, which 404s. That broke the whole of navigation for any role landing on a clean URL, and trapped cashiers in the till: Exit POS, the receipt window and even the 404 page's own "Back to Home" all 404ed (13 August 2026; the three failures are in the Apache access log). Redirecting keeps the real path in the address bar, so all 43 admin pages' relative links, form actions and asset URLs resolve without touching a single href.

The redirect targets are prefixed with `%{ENV:BASE}`, computed by the standard Apache idiom at the top of the rules, so the app still works from a subdirectory or from the document root.

For URLs built in PHP, use **`adminUrl('page.php')`** and **`appRootUrl()`** (`includes/auth.php`) rather than a bare relative string. They derive the path from `SCRIPT_NAME`, which is the rewrite target whatever the browser asked for. `authDeny()` and the till's `print_url` both use them.

There is **one login portal**: `admin/login.php`. It forwards each role straight to its home screen — there is no splash page.

## Known rough edges

Don't re-derive these; they're documented in full in `docs/TECHNICAL_DOCUMENTATION.md` §14 and §21.

  *(Two issues that used to head this list were fixed on 13 August 2026: the forgeable remember-me cookie — see `includes/remember_me.php` — and the total absence of CSRF protection — see `includes/csrf.php`.)*
- **No UI for** `pos_tax_rate`, `pos_tax_inclusive`, `pos_receipt_footer`, `acc_opening_float` (SQL-only), locations, or batches (`inv_batches` is never written to).
- **Profile photo and PO invoice uploads validate the extension only** — no MIME check, no size cap. Product images do it properly (`mime_content_type()` + 3 MB cap + generated filename); copy that path, not the other two.
- **No test suite, no automated backups, no application error log.**

## Working here

There's no test suite and no build step — changes are verified by loading pages in the browser at `http://localhost:8081/Home/`. Start **MySQL (port 3306)** and Apache in the XAMPP control panel first; without MySQL every page shows the maintenance message from `includes/db.php`.

Useful verification commands:

```bash
# Syntax check before claiming a page works
/c/xampp/php/php.exe -l admin/some-page.php

# Query the database
"C:\xampp\mysql\bin\mysql.exe" -u root -h 127.0.0.1 -P 3306 retailer_shop -e "SELECT …"
```

The invariant check, worth running after any stock or ledger change:

```sql
SELECT ROUND(SUM(current_stock * average_cost), 2) FROM inv_items WHERE deleted_at IS NULL;
SELECT ROUND(SUM(l.debit - l.credit), 2) FROM acc_journal_lines l
  JOIN acc_accounts a ON a.id = l.account_id WHERE a.code = '1200';
-- these two must match
```

Prefer editing existing files over creating new ones, match the surrounding visual style (teal `#0f9aa8` primary via `--color-primary`, rounded cards, soft shadows — always use the tokens in `ui.css`, never a hard-coded hex), and keep comments focused on *why* a piece of code exists, especially around transaction safety, idempotency guards, and business rules that aren't obvious from the code.

Clean up test data after verifying anything against the live database — test sales, test suppliers and scratch rows should not survive the session that created them.
