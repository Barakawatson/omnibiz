# OmniBiz

A configurable **Point of Sale, Inventory and Accounting** system for retail businesses. Runs on plain PHP + MySQL/MariaDB with no build step, no framework and no package manager — clone it, point it at a database, and it installs its own schema on first load.

The business type, departments, categories, units, suppliers, payment methods and receipt are all configuration, not code. A supermarket, a pharmacy (duka la dawa), a hardware shop or a cosmetics counter runs the same source with zero code changes — set it up once at `admin/setup.php`.

## What's included

| Module | What it does |
|---|---|
| **POS** | Walk-in till, barcode scanning, split payments (cash / mobile money / bank / card), receipts, held sales, voids |
| **Inventory** | Stock levels, suppliers, purchase orders, stock requests, barcodes, batch/lot expiry tracking with FEFO (first-expiry-first-out) allocation |
| **Catalog** | Which items are sold, at what price, in which department |
| **Accounting** | Double-entry ledger, chart of accounts, expenses, Z-report (day close), profit & loss |
| **Expired goods disposal** | Storekeeper requests disposal of expired stock → admin/manager approves → separate confirm step deducts stock and posts the ledger loss, with a printable disposal certificate |

Every stock movement and every money movement is auditable: stock changes are locked, logged and cost-averaged; ledger entries always balance (debits = credits) and are corrected by reversal, never by editing history.

## Stack

- **PHP 8.2**, procedural + prepared statements, no ORM
- **MariaDB / MySQL** (tested on MariaDB 10.4)
- **Bootstrap 5.3.2**, vanilla JavaScript, no frontend build step
- Designed for **XAMPP**, but runs on any Apache/PHP/MySQL stack

## Getting started

1. Clone this repo into your web root (e.g. `htdocs/omnibiz` on XAMPP).
2. Create a database and point `includes/db.php` at it (host, port, user, password, database name).
3. Start Apache and MySQL, then open the app in a browser. Each module installs its own tables automatically on first request — there is no SQL dump to import.
4. You'll land on `admin/setup.php`, a step-by-step wizard: pick a business type (used only to suggest starting departments/categories/units — it does not change any code path), name your shop, and create the first admin account.
5. Sign in at `admin/login.php` and start configuring departments, products and payment methods for your business.

## Roles

Four fixed roles, each with its own home screen: **Admin** (full access, including the chart of accounts and departments), **Manager** (everything operational plus reports and voids), **Storekeeper** (stock intake, purchase orders, barcodes — cannot approve a PO or pay a supplier), **Cashier** (POS checkout, receipts, customers).

## Documentation

- [`docs/USER_GUIDE.md`](docs/USER_GUIDE.md) — every screen, from the user's side
- [`docs/TECHNICAL_DOCUMENTATION.md`](docs/TECHNICAL_DOCUMENTATION.md) — architecture, schema, security notes, known limitations
- [`docs/SYSTEM_OPERATIONS_HOW_TO_GUIDE.md`](docs/SYSTEM_OPERATIONS_HOW_TO_GUIDE.md) — step-by-step procedures and daily workflow
- [`UI_COMPONENTS.md`](UI_COMPONENTS.md) — the shared admin design system (tokens, sidebar, cards, tables, modals)
- [`INVENTORY-MODULE.md`](INVENTORY-MODULE.md) — the inventory module's own design notes

## Status

Built and actively used by a real retail shop. No automated test suite yet — changes are verified by loading pages in the browser and checking the stock/ledger invariant documented in `docs/TECHNICAL_DOCUMENTATION.md`.
