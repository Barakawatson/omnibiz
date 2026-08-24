# Implementation Plan

This is not a plan to build OmniBiz — it is built and running (the reference deployment, 477 products live). This document records **what shipped, in what order, and what the sequenced work ahead of it actually is**, so the plan itself has evidentiary value rather than being aspirational.

---

## 1. What has already shipped (baseline, verified)

In roughly the order it was built, each verified against the source and/or a live/cloned database before being considered done:

1. **Retail conversion** — laundry-management system converted in place to POS/Inventory/Accounting; laundry-era code, tables and uploads removed.
2. **Supplier management** — brought to the standard pattern (deactivate-not-delete, prepared statements, audit log).
3. **Full documentation set** — User Guide, Technical Documentation, Operations How-To, UI Components reference, all screenshot-verified against the running system.
4. **Security hardening** — forgeable remember-me cookie replaced with rotating hashed tokens; CSRF protection added to every state-changing request; four SQL-injection-pattern files converted to prepared statements; upload validation rebuilt (content-sniffed, size-capped) after discovering the existing "weak validation" finding was actually an **unauthenticated RCE** via the avatar-crop path; session cookie hardened (`HttpOnly`, `SameSite`, app-specific name, strict mode).
5. **Shop identity → configuration** — name/address/logo/TIN/VRN/payment methods moved from hardcoded constants to database settings, with the old constants kept as a last-resort fallback only.
6. **Theme system** (light/dark/system) and **POS full-screen + customer display** (BroadcastChannel-based, additive, read-only).
7. **Navigation redesign** — six-then-seven destination sidebar, naming collisions resolved (Account vs Accounting → Administration vs Finance), Reports placed below Finance (reads from every module rather than being one of them).
8. **Reporting Centre rebuild** — 23 reports, one renderer, CSV/PDF/print, drill-down, server-side pagination; an N+1 query fixed (29 queries → 1, ~48×) along the way.
9. **Mobile verification** — all report screens and 10 other admin pages tested at 390px via an iframe harness (the only way to get genuine sub-920px viewport testing on this dev machine); found and fixed a real off-canvas sidebar bug in the process.
10. **Configurability rebuild** — departments moved from a 3-value `ENUM` to administrator-owned data (`retail_departments`), non-destructively; business-type templates; 10-step setup wizard; verified against 9 different business types on fresh databases plus a full upgrade-path test against a clone of the live shop (byte-identical history preserved).
11. **URL-routing fix** — clean URLs changed from internal rewrite to redirect after discovering the rewrite broke every relative link (sidebar, receipts, sign-out) for any role landing on a clean URL; also added a missing "Sales & receipts" and "Sign out" control to the POS top bar, which had no sidebar of its own.
12. **Approval-rights fix** — purchase-order approval, cancellation-of-approved, and supplier payment restricted to Manager/Administrator (`purchasing_approve` key), enforced server-side with an audit trail of denied attempts, not just hidden buttons.
13. **Near-expiry markdown + expiry block** — automatic 5% discount inside a configurable window, computed in the single pricing function so grid/scan/checkout/display can never disagree; expired stock separately and additionally **blocked from sale outright** at every entry point (checkout, scan, grid), with a settings toggle and cashier-facing reasons.
14. **Customer-display polish** — payment block centred as a group, Lipa Namba number enlarged for readability from arm's length.
15. **Catalogue build-out** — 477 products across 5 departments, 32 categories, generated placeholder imagery (chosen over stock photos after determining licensed photos of the actual product lines don't exist and a generic photo risks the wrong item on a self-checkout-style grid).
16. **TRA fiscal integration audit** (Phase 1 of a 12-phase plan) — completed; **status: not ready**, blocked on an external approved-supplier relationship and an unpublished official specification, independent of any code work. See `docs/TRA_FISCAL_INTEGRATION_AUDIT.md`.
17. **Batch/lot-level expiry tracking (FEFO)** — `inv_batches` (schema present but unused until this point) now receives a row on every stock-in (barcode station and PO receiving both support an optional per-batch expiry date), and FEFO allocation deducts oldest-expiry-first at checkout and in physical-count corrections. A one-time backfill gave every item that already had stock a single `LEGACY-<id>` batch so FEFO had something to allocate against from day one. `inv_items.expiry_date` — the single field pricing and checkout-blocking actually read — is kept in sync with "the earliest still-active batch's date" (`refreshItemExpiryFromBatches()`) every time a batch is created or its quantity/status changes, closing a gap where newly-received stock inherited a stale, already-past expiry date from older stock of the same item and was wrongly blocked from sale.
18. **Expiry alerts, cashier stock requests, institutional customers, EFD-on-payment** — a staff-facing Expiry Alerts page (cashier/storekeeper/manager/admin) showing items nearing expiry at the same adjusted price the till charges; cashiers widened onto the existing stock-request workflow (own requests only, review stays with inventory holders); `customer`/`sales_transactions` gained address/TIN/email so an organisation or government buyer's details print on the receipt, snapshotted at sale time; a supplier's EFD (TRA fiscal) receipt is now required to attach before recording the **payment that fully settles** a purchase order (not at receiving — an EFD is proof of payment, not of delivery), reusing the existing content-validated upload pipeline.
19. **Accordion navigation, Supplier Liabilities, cancelled-cart audit trail, POS cart persistence** — the sidebar's per-section expand/collapse (previously accumulated open sections across page loads despite being labelled "accordion" in code comments) now genuinely allows only one section open at a time. A new Supplier Liabilities dashboard aggregates every purchase order's outstanding balance across suppliers (admin/manager full access via the existing payment modal, storekeeper read-only), backed by a new optional `due_date` on purchase orders defaulted from a configurable payment-terms setting. Clearing a non-empty POS cart, removing its last line item, or discarding a parked held sale now all require a reason and write a permanent `cancelled_carts` row, visible only to Administrators via a new admin-only report group; a persistent badge on the sidebar's Reports link shows today's count (correct on first page load, not just after a JS poll) and `MX.watchAlerts()` additionally toasts any increase within its existing 30-second poll. The live cart is now also shadow-copied to this browser's `localStorage` on every change (a same-device safety net layered on top of, not replacing, the existing server-side Hold Sale mechanism) with a restore-or-discard prompt on the next load, and a `beforeunload` warning fires whenever the cart is non-empty — both explicitly scoped to what browsers actually allow (no attempt to block `F5`/`Ctrl+R`, which no browser lets a page do reliably).

## 2. Working method (how each of the above was actually delivered — carry this forward)

1. **Audit before changing.** Read the current behaviour and data before writing code; state findings plainly, including when they contradict an earlier assumption.
2. **Never test against production data blind.** Higher-risk changes were dry-run on a disposable clone of the live database first; a full `mysqldump` backup was taken before anything destructive, every time.
3. **Verify the invariant, not just "it runs."** After any change touching stock or money: `Σ stock×cost == Inventory Asset` and `Σ debits == Σ credits`, checked by SQL, not assumed.
4. **Prove the fix at the layer that matters.** A UI-only check is not enough for something like the near-expiry price — the checkout function itself was tested to confirm the *charged* price matched, not just the *displayed* one.
5. **Clean up test artefacts.** Scratch databases dropped, test sales voided-and-removed as matched sets, orphaned upload files deleted — after every session, not left behind.
6. **Say what's still open.** Every piece of work above ends with an explicit list of what wasn't done and why, rather than presenting partial work as complete.

## 3. Recommended next work, in priority order

This is a recommendation, not a commitment — sequenced by risk and dependency, matching the working method above.

### Priority 1 — Security debt already identified and not yet closed
- Login rate-limiting (currently unlimited attempts).
- Move `includes/db.php` outside the web root, or its credentials to environment variables.
- Change the default admin password on every real deployment (the dashboard now nags for this, but nothing enforces it).

### Priority 2 — Foundational for either TRA fiscal work or general tax correctness
- **Per-product tax classification.** Today there is one shop-wide tax rate; TRA (and correct VAT reporting generally) needs a category per item. This is real schema + UI + backfill work across 477 products and is a prerequisite for TRA Phase 2 regardless of how the API question resolves.

### Priority 3 — TRA fiscal integration, if the business decision is made to pursue it
Follow the 12-phase sequence already laid out in `docs/TRA_FISCAL_INTEGRATION_AUDIT.md` §21, **starting only after** the legal/supplier route is confirmed and the official specification is obtained — not before. Phase 0 (that confirmation) is explicitly outside engineering's control.

### Priority 4 — Product completeness gaps, lower urgency
- Refund/credit-note workflow distinct from whole-sale void.
- Per-location stock (if the business genuinely needs it — currently a deliberate non-feature, not an oversight).
- Offset support on the Journal/Expenses report helpers, once the ledger is large enough for the current 10,000-row cap to matter.
- Automated test suite — every verification to date has been manual; this is the highest-leverage investment for long-term safety as the codebase grows.

## 4. What must never be revisited without re-reading the reasoning first

A short list of decisions that look like they could be "simplified" but were deliberately made this way, to save a future implementer from re-breaking them:

- **Clean URLs must redirect, never internally rewrite** (broke all relative links previously).
- **The off-canvas sidebar must use `transform`, never a computed `left`** (drifted against a responsive token previously).
- **`department` values must stay administrator-editable data**, never re-hardcoded as an `ENUM` or PHP constant list.
- **`retailEffectivePrice()` is the only place price is decided** — a second pricing path is how the grid/checkout/display would end up disagreeing.
- **Stock changes only through `recordStockMovement()`; money only through `accPostEntry()`.** Any code that writes `current_stock` or a journal line directly outside these gates breaks the audit trail and the core invariant.
- **A closed day (`acc_daily_close`) is immutable.** Never add an "edit" path to it.
- **The EFD receipt gate lives on the final supplier PAYMENT, not on receiving.** Re-litigated once already this session — an EFD is TRA's proof a payment was fiscalised, not proof goods arrived; moving it to receiving would gate the wrong event.
- **A cart cancellation is a deliberate button, not an incidental state change.** The mandatory-reason modal fires on Clear Cart, the last line item being removed, and discarding a held sale — never on ordinary line-by-line editing of a cart that still has other items in it.
- **`poRecordPayment()` stays the one and only call site that moves money to a supplier.** The Supplier Liabilities dashboard links into the existing payment modal rather than adding a second entry point, so `purchasing_approve` keeps covering every path by which money reaches a supplier.
- **Cart persistence is `localStorage`, same-device only, layered on top of Hold Sale — not a replacement for it.** Hold Sale remains the deliberate, cross-terminal, audit-visible way to park a sale; the shadow copy exists solely to survive an accidental refresh in the same browser.
