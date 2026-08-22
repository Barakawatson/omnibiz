# Product Requirements Document (PRD)

| | |
|---|---|
| **Product** | OmniBiz |
| **Type** | Point of Sale, Inventory and Accounting system for retail businesses |
| **Document status** | Describes the system **as built and running**, verified against the source and the live database on 14–18 August 2026. Not a proposal. |
| **Reference deployment** | A supermarket in Iringa, Tanzania (477 products, 5 departments, 32 categories) |
| **Related documents** | 02 Technical Requirements · 03 UI/UX Spec · 04 App Flow · 05 Backend Schema · 06 Implementation Plan |

---

## 1. Problem statement

A small-to-mid-sized Tanzanian retail business — a supermarket, pharmacy, hardware shop, stationer, or similar — needs one system that:

- rings up a sale at a till, correctly and fast, with a barcode scanner;
- knows what is in stock, at what cost, and never lets the shelf and the books disagree;
- keeps a real set of accounts (not a spreadsheet bolted onto a till) so profit, tax exposure and cash position are always answerable;
- can be configured for *that* business's own departments and products, rather than being written for one shop and awkwardly reused.

Before this system existed in its current form, it was a laundry-management product converted in place. The product decision behind everything that follows was: **make the retail core general-purpose, not laundry-specific and not supermarket-specific** — see PRD §3.

## 2. Users

Four roles, each with a distinct home screen and a fixed set of permissions (no custom roles):

| Role | Job | Lands on |
|---|---|---|
| **Cashier** | Serve customers at the till | POS Terminal |
| **Storekeeper** | Stock intake, purchase orders, barcoding | Inventory Dashboard |
| **Manager** | Everything operational, plus reports, voids, staff | Manager Overview |
| **Administrator** | Everything, plus chart of accounts, journal entries, departments, TRA/fiscal config (planned) | Dashboard |

Permissions are enforced **server-side** on every page (`requireModule()` / `requireRole()`), not just hidden in the menu.

## 3. Product principle: configurable, not business-specific

Stated because it shapes every other requirement: **the same source code must run a supermarket, a duka la dawa, a hardware shop, a stationer or a cosmetics counter with zero code changes.**

- Departments, categories, units, locations, suppliers, payment methods and receipt content are **administrator-configured data**, not compiled-in constants.
- A "business type" (Supermarket, Pharmacy, Stationery, Hardware, …) is a **classification plus optional starter templates** — it must never imply industry-specific behaviour (a Pharmacy business type does *not* add prescriptions, patient records or controlled-drug tracking; that would be a separate, unbuilt module).
- A fresh install starts with one neutral department ("General") and no products — nothing is assumed on its behalf.
- An install that already has trading history is never pushed through setup again.

## 4. Core capabilities (what exists today)

### 4.1 Point of Sale
- Barcode scan or tap-to-add product grid, department and category tabs, live search.
- Server-recalculated pricing on every line — the browser sends ids and quantities only.
- Split payment across cash, mobile money (Lipa Namba), bank transfer and card in one sale.
- Held sales (park and resume, from any terminal).
- Discounts at the order level.
- Automatic 5% mark-down on stock within a configurable window (default 30 days) of its expiry date, shown as a slideshow on an optional second-screen customer display.
- **Expired stock is refused at checkout, at the barcode scan, and on the grid** — not merely un-discounted.
- Receipt printing on an 80 mm thermal layout, with the shop's own logo, address, TIN, VRN and payment instructions.
- Full-screen kiosk mode and a customer-facing second display (BroadcastChannel-based), both additive and unable to affect the sale.

### 4.2 Inventory
- One physical/logical item = one `inv_items` row; the same row is sold at the till, tracked in stock, and priced.
- Every stock change (sale, receipt, damage, expiry, count correction, transfer, internal use) goes through a single function that locks the row, keeps a full audit trail, and maintains a running weighted-average cost.
- Barcode generation and label printing.
- Reorder alerts, low/out-of-stock views.
- Stock requests (internal transfer/replenishment workflow).

### 4.3 Purchasing
- Purchase orders: draft → approved → (partially) received → paid.
- **Approving an order, cancelling an approved order, and recording a supplier payment are restricted to Manager/Administrator** — a storekeeper can raise an order and receive goods, but cannot commit the shop's money.
- Supplier invoice upload, content-validated.
- Receiving stock posts inventory and the ledger together, atomically.

### 4.4 Accounting
- Full double-entry ledger. Every sale, purchase, expense, stock write-off and daily-close variance posts a balanced journal entry automatically — there is no manual bookkeeping step for routine trade.
- Chart of accounts, journal, trial balance, profit & loss.
- Daily close (Z-report) reconciles counted cash against what the system expected, department by department, and posts any variance.
- **The one invariant that must always hold:** stock value on the shelf (`Σ current_stock × average_cost`) equals the Inventory Asset account balance in the ledger. This is checked after every change of consequence.

### 4.5 Reporting
- A single Reporting Centre covering Sales, Inventory, Purchasing and Accounting — 23 reports from one shared renderer.
- Every report supports CSV, PDF and print output, drill-down into the underlying detail, and server-side pagination.
- Reports read from the same authoritative functions the rest of the app uses (e.g. the P&L report calls the same function `admin/profit-loss.php` does) — nothing is recalculated a second, possibly-different way for reporting.

### 4.6 Configuration & setup
- A ten-step setup wizard: business type, business info, departments, categories, units, locations, suppliers, payment methods, receipt, review.
- Every step is additive — nothing is ever deleted by setup, and a shop with existing trade is never re-prompted.
- Business-type "templates" only *suggest* a starting structure; nothing is locked.

### 4.7 Identity, theme, access
- Shop identity (name, logo, address, contact, TIN/VRN, payment methods, receipt content) is database-configured, not hardcoded.
- Light/dark/system theme.
- Persistent "keep me signed in" via rotating, hashed tokens (not a forgeable cookie).
- CSRF protection on every state-changing request.
- Content-validated file uploads (MIME-sniffed, size-capped, server-generated filenames).

## 5. Explicitly out of scope today

Recorded so nobody assumes silently: batch/lot tracking, per-location stock (stock is one global quantity per product), refunds/credit notes as a distinct workflow (only whole-sale void exists), customer loyalty/CRM beyond name+phone, multi-tenancy (one shop per installation, verified — no `tenant_id` anywhere), any TRA/fiscal receipt submission (see the TRA audit — blocked on external approval, not a code gap), login rate-limiting.

## 6. Non-functional requirements

| Requirement | Current state |
|---|---|
| Runs on modest, offline-capable shop hardware | Yes — XAMPP stack, no cloud dependency for core operation |
| A sale never exists on the till without existing in the books | Guaranteed by transaction design (§ Technical Requirements) |
| No customer-facing PII exposed unnecessarily | Customer record is name + phone only |
| Works on a phone-sized admin screen | Verified at 390px for all 23 reports and the main admin pages |
| Auditable | Every stock movement and privileged action is logged with who/when/why |

## 7. Success criteria

- A cashier can complete a sale, print a receipt, and have stock and the ledger update correctly, without training beyond "scan, pay, print."
- An administrator can stand up a **different kind of shop** (not a supermarket) using only the setup wizard and Departments screen, with no source change — demonstrated for 9 business types on fresh databases in testing.
- The stock-value/ledger invariant holds after every category of stock-affecting operation.
- Every report's export matches what was on screen, exactly.
