# Technical Requirements Document (TRD)

Describes the system as implemented. Cross-reference: `CLAUDE.md` (repository conventions), `docs/TECHNICAL_DOCUMENTATION.md` (deep architecture reference, §1–25).

---

## 1. Stack

| Layer | Choice | Why (as recorded in the codebase) |
|---|---|---|
| Language | PHP 8.2, procedural + prepared statements | No framework, no ORM — smaller attack surface, no build step, runs on stock XAMPP |
| Database | MariaDB 10.4, database `retailer_shop`, port 3306 | 33 tables |
| Web server | Apache, port 8081, app served at `/Home/` | `.htaccess` rewrites (redirects, not internal rewrites — see §7) |
| Frontend | Bootstrap 5.3.2 + Font Awesome 6.4.0 + Poppins (CDN), vanilla JS | No second framework introduced |
| PDF generation | In-house (`includes/pdf_writer.php`) | No Composer/vendor directory exists; avoids a third-party library nobody can patch |
| Image generation | PHP GD | Used for generated product-tile placeholders |

No package manager, no Composer, no npm, no build step anywhere in the stack. This is a hard constraint on any future dependency choice.

## 2. File layout

```
includes/     db.php, auth.php, session.php, csrf.php, core_schema.php,
              remember_me.php, shop_settings.php, business_types.php,
              uploads.php, report_functions.php, pdf_writer.php,
              *_schema.php, *_functions.php, stock_ledger.php   (21 files)
admin/        41 staff-facing pages; sidebar-admin.php + sidebar-nav.php +
              inventory-header/footer.php are shared chrome
admin/api/    JSON endpoints (POS scanning/checkout, products-scan)
admin/partials/  page-header.php, empty-state.php, pagination.php, setup-required.php, first-run.php
assets/css/admin/  styles.css (legacy remnant, loads first) then ui.css (design system)
assets/js/admin/   ui.js — toasts, filters, sidebar, modals, live alert badges
assets/uploads/    shop_products/, profile_admin/, po_invoices/, shop_logo/ — the
                   only directories the app writes to
docs/          USER_GUIDE, TECHNICAL_DOCUMENTATION, SYSTEM_OPERATIONS_HOW_TO_GUIDE,
               TRA_FISCAL_INTEGRATION_AUDIT, specs/ (this set), screenshots/
```

## 3. Data integrity architecture

This is the section that matters most for correctness.

### 3.1 Self-installing schemas
Each module owns `includes/<module>_schema.php`: `CREATE TABLE IF NOT EXISTS` plus guarded `ALTER`s, version-flagged in `inv_settings`. Adding a column is a schema-version bump, never a manual migration a deployer has to remember. **Destructive** statements (DROP/DELETE) are never in a schema installer — they are standalone `.sql` files run deliberately (e.g. `sql/reset_to_fresh_install.sql`).

### 3.2 Single gate for stock changes
`recordStockMovement()` (`includes/inventory_functions.php`) is the **only** legitimate path to change `inv_items.current_stock`. It:
- locks the item row (`SELECT … FOR UPDATE`) so concurrent tills cannot oversell;
- writes an audit row with before/after quantities, who, why;
- maintains the running weighted-average cost;
- triggers `includes/stock_ledger.php` (required *inside* the function, not at top-level, to avoid an include cycle — accounting depends on inventory) to post the matching ledger entry for any movement type not already posted in aggregate by a higher layer (POS checkout, PO receipt).

### 3.3 Single gate for money
`accPostEntry()` (`includes/accounting_functions.php`) refuses to write anything unless debits equal credits. Balances are **never stored** — always summed from `acc_journal_lines` on demand, so the books cannot drift from their own history. Corrections are by **reversal** (a mirror entry), never by editing a posted line. Idempotency key: `(source_type, source_id)` on `acc_journal`, so a receipt or a stock movement can never be posted twice.

### 3.4 The checkout transaction
`posCheckout()` (`includes/pos_functions.php`) is **one MySQL transaction**: validate stock → recalculate prices server-side → validate tenders → insert the sale → insert lines → `recordStockMovement()` per line → insert payments → `accPostEntry()` → commit. If ledger posting fails, the whole sale rolls back. **Takings can never exist on the till but be missing from the books.** `poReceiveStock()` (goods receipt) follows the identical pattern.

### 3.5 The load-bearing invariant

```
SUM(inv_items.current_stock × inv_items.average_cost)  ==  balance of ledger account 1200 (Inventory Asset)
```

If this drifts, some code path moved stock without posting to the ledger. This is the standard post-change health check (see `CLAUDE.md`).

## 4. Security architecture

| Concern | Implementation |
|---|---|
| SQL injection | Prepared statements everywhere; the only surviving `real_escape_string()` calls take internal, non-user values (department keys, `SHOW`/`information_schema` identifiers) |
| CSRF | Per-session token (`includes/csrf.php`), required on every POST via `csrfRequire()`; JSON endpoints via `X-CSRF-Token` header; rejection returns HTTP 403 (not 419, which Apache maps to 500) |
| Session fixation / hijack | `includes/session.php`: `session.use_strict_mode` on, cookie `HttpOnly` + `SameSite=Lax` + `Secure`-on-HTTPS, app-specific cookie name (not the shared default `PHPSESSID`), `session_regenerate_id(true)` on password login |
| Persistent login | Selector/validator rotating tokens (`includes/remember_me.php`), SHA-256 hashed (validator is CSPRNG output, not a low-entropy secret — bcrypt would cost time for no benefit); any credential/role/deactivation change revokes all of a user's tokens |
| File uploads | `includes/uploads.php` — content-sniffed via `finfo` cross-checked with `getimagesize()`, size-capped (3 MB images / 8 MB documents), server-generated filenames (uploaded name never touches the filesystem); `assets/uploads/.htaccess` disables PHP execution in the upload tree entirely |
| RBAC | `requireModule()`/`requireRole()` at the top of every page — server-side, not menu-hiding; `admin/api/*` returns 401/403 JSON instead of an HTML redirect |
| Clean URLs | `.htaccess` **redirects** (302) to the real path rather than internally rewriting — an internal rewrite previously broke every relative link (sidebar, receipts, sign-out) for anyone landing on a clean URL; documented as a permanent constraint, not to be reverted |
| Passwords | bcrypt via `password_hash()`/`password_verify()` |
| Price integrity | The browser sends item ids and quantities only; price, discount, tax and totals are always recalculated server-side inside the checkout transaction |

Known, documented, **not yet fixed**: no login rate-limiting; `includes/db.php` sits inside the web root (mitigated by PHP execution rather than serving it as text, but not moved); default admin password (`admin`/`12345`) must be changed post-install — the dashboard now carries a first-run checklist that flags this explicitly until it's done.

## 5. Configurability layer (the part that makes this a product, not a single shop's script)

- `retail_departments` — administrator-owned rows (key, name, icon, colour, sort order, active). The `department` column was migrated from a 3-value `ENUM` to `VARCHAR(32)` across all seven tables that carry it, non-destructively (values preserved byte-for-byte), specifically so a new business type never requires a schema change again.
- The department **key** is generated once at creation and is immutable — renaming a department must never require rewriting history.
- `includes/business_types.php` — a data table of business types and optional starter templates (departments/categories/units); **no core code branches on a business-type key**. Applying a template is additive and idempotent.
- `businessSyncSetupState()` marks any database that already has trading history as "configured" during boot, so an upgrade can never push a live shop back through setup.

## 6. Reporting architecture

One shared renderer (`admin/report.php`, driven by `includes/report_functions.php`) for 23 reports. A report supplies four things — `$columns`, `$rows`, `$footer`, `$align` — plus optional `$metrics`/`$chart`/`$supports`; the renderer does pagination (`rqPaged()`, skipped for exports — an export is always the full filtered set), CSV, PDF (via the in-house `pdf_writer.php`) and print. Financial reports call the same authoritative functions the rest of the app uses (`accProfitAndLoss()`, `accTrialBalance()`, etc.) rather than reimplementing arithmetic — this is treated as an absolute rule, not a preference.

## 7. Known technical debt (explicit, not hidden)

- No automated test suite. Verification is manual: `php -l` syntax checks, live page loads, invariant SQL checks, and — for higher-risk changes — disposable database clones exercised end-to-end before touching production data.
- No CI/CD. No cron/queue infrastructure (relevant to any future external-integration work, e.g. TRA).
- Journal/Expenses report helpers fetch up to 10,000 rows before slicing (their underlying functions take a limit but no offset) — fine at current scale, would need offset support at a genuinely large ledger.
- PDF column widths are estimated from character counts, not real font metrics.

## 8. External integration status

**None are live.** The one investigated integration — Tanzania Revenue Authority (TRA) fiscal receipts — was the subject of a dedicated audit (`docs/TRA_FISCAL_INTEGRATION_AUDIT.md`) and is **not ready**: TRA requires a Commissioner-General-approved VFD/supplier relationship that does not yet exist, the official message specification was not publicly obtainable, and the application currently has no per-product tax classification, which a compliant fiscal receipt requires regardless of the API question. See that document for the full findings; do not re-derive them here.
