# Application Flow

Traces the real, code-verified paths through the system — not an idealised flow. Each diagram corresponds to an actual function/file named alongside it.

---

## 1. Authentication and routing

```
Visitor → /Home/  (or a clean URL: /pos/terminal, /inventory, /manager/overview, /admin/dashboard)
            │
            ▼
   .htaccess REDIRECTS (302) to the real admin/*.php file
   (never an internal rewrite — that broke every relative link on the page, fixed permanently)
            │
            ▼
   Already signed in? ──yes──► roleHomeUrl($role) for that role
            │no
            ▼
   admin/login.php
     · CSRF-protected form
     · "Keep me signed in" issues a rotating selector/validator token (NOT a copy of the login form)
     · session_regenerate_id(true) on success (defeats fixation)
            │
            ▼
   Role-specific home screen (Dashboard / Manager Overview / Inventory Dashboard / POS Terminal)
```

Every subsequent admin page starts with `requireModule()`/`requireRole()` — enforced server-side, independent of what the sidebar shows.

## 2. The sale — cashier flow

```
Cashier opens POS Terminal
   │
   ├─ scan barcode (F2 refocuses the scan input from anywhere on the page)
   │     └─ admin/api/products-scan.php → posFindByBarcode()
   │           refuses with a stated reason if: department not trading,
   │           product not enabled/visible, EXPIRED
   │
   ├─ or tap a product tile (same server-truth price/stock/expiry state)
   │
   ├─ adjust quantity, apply an order-level discount
   │
   ├─ choose payment: cash / Lipa Namba / bank / card — any combination
   │     · an electronic tender may never exceed the total (change comes only from cash)
   │
   └─ Complete & Print Receipt
         │
         ▼
   admin/api/pos-checkout.php → posCheckout()   [SINGLE DB TRANSACTION]
         │
         ├─ re-validate every line: exists, enabled, department trading, NOT expired, enough stock
         ├─ recompute price/discount/tax server-side (browser input is never trusted)
         ├─ INSERT sales_transactions + sales_transaction_items
         ├─ recordStockMovement() per line  → stock deducted, weighted-avg cost maintained
         ├─ INSERT sales_payments (one row per tender)
         ├─ accPostEntry()  → DR Cash/Mobile/Bank + COGS, CR Sales Revenue + Inventory Asset
         │     (net of change given — never the full cash tendered)
         └─ COMMIT   — or ROLLBACK if any step fails; nothing partial is ever left behind
         │
         ▼
   Receipt window opens automatically (admin/pos-receipt.php) — 80mm layout, shop identity,
   itemised lines, tax (if configured), payment breakdown, footer
         │
         ▼
   Customer display (if a second screen is attached) shows PAID, then reverts to idle after 12s
```

**Held sale variant:** at any point before Complete, "Hold Sale" parks the cart server-side (resumable from *any* terminal); no stock or ledger effect until it is resumed and completed.

**Void variant** (Manager/Administrator only): `posVoidSale()` — same transactional shape in reverse: returns stock via `recordStockMovement()`, posts a mirror ledger entry (Sales Returns + Inventory / Cash + COGS), marks the sale voided. Idempotent — voiding twice does nothing the second time.

## 3. Purchasing flow

```
Storekeeper: raise Purchase Order (draft)
      │
      ▼
Manager/Administrator: APPROVE          ← storekeeper cannot do this (purchasing_approve key)
      │
      ▼
Storekeeper: goods arrive → Receive Stock
      │        poReceiveStock() [transaction]
      │        ├─ recordStockMovement() (receive) → stock up, weighted-avg cost recalculated
      │        └─ accPostEntry() → DR Inventory Asset, CR Accounts Payable
      ▼
Manager/Administrator: Record Payment to supplier   ← storekeeper cannot do this
      │        poRecordPayment() → DR Accounts Payable, CR Cash/Mobile/Bank
      ▼
Order status becomes fully paid / partially paid, tracked against what was actually RECEIVED
(not what was originally ordered)
```

Cancelling a **draft** stays available to a storekeeper (nothing committed yet); cancelling an **approved** order requires the same approval key, since it reverses a commitment.

## 4. Stock movement flow (every route, one gate)

```
   Sale ──┐
   PO receipt ──┤
   Damage/expiry/loss ──┤
   Count correction ──┼──► recordStockMovement()  [row-locked, single gate]
   Internal issue ──┤        ├─ before/after quantity written to inv_stock_movements (audit trail)
   Transfer ──┤        ├─ weighted-average cost recalculated
   Opening stock ──┘        └─ stock_ledger.php posts the matching journal entry
                                (skipped for movements a higher layer already posted in aggregate —
                                 POS checkout and PO receipt — so nothing is ever double-posted)
```

## 5. Daily close (Z-report) flow

```
End of trading day → admin/z-report.php
   │
   ├─ accDayFigures() computes: gross/net sales, discounts, tax, cost of sales, gross profit,
   │     cash/mobile/bank/card split, per-department split (dynamic — reads whatever
   │     departments this shop actually has, not a fixed three)
   │
   ├─ Cashier/manager counts the physical drawer
   │
   └─ accCloseDay()  [transaction]
         ├─ INSERT acc_daily_close (immutable snapshot)
         ├─ INSERT acc_daily_close_departments (one row per department, for reports/reprints
         │     that must still show the department name/split as it was ON THAT DAY)
         └─ if counted ≠ expected: post the variance (Shrinkage / Cash, or Cash / Other Income)
```

A closed day can never be reopened or edited — only ever reported on.

## 6. Configuration flow (setup wizard)

```
Fresh install boots → businessSyncSetupState()
   │
   ├─ Database already has products/sales/movements/POs?
   │     └─ yes → marked "configured" silently. Setup wizard never shown. (upgrade-safe)
   │     └─ no  → Setup available; dashboard shows a first-run checklist (no dismiss button,
   │              disappears automatically once every item is done)
   ▼
admin/setup.php — 10 steps, each additive, resumable, none of them destructive:
   1 Business type (+ optional starter template)   6 Locations
   2 Business information                          7 Suppliers
   3 Departments                                    8 Payment methods
   4 Categories                                      9 Receipt content
   5 Units                                          10 Review → Complete
```

## 7. Near-expiry / expired-stock flow

```
Nightly (conceptually — actually evaluated live on every price lookup, no batch job exists):
   product.expiry_date within N days (default 30, admin-configurable)?
        │
        ├─ yes, and not yet expired → retailEffectivePrice() returns 95% of shelf price
        │        (or the existing hand-set promo price if that's already cheaper — never both)
        │        Shown on: product grid (badge), customer display (slideshow), and IS
        │        the price actually charged at checkout — one function, so nothing can disagree
        │
        └─ yes, and PAST expiry_date → retailExpiryBlockReason() returns a reason string
                 Blocked at: checkout (the authoritative gate, inside the transaction),
                 barcode scan (server), product grid (client, fast path), scanner fast-path (client)
                 A mixed cart with even one expired line is refused WHOLE — nothing partial sells.
```

## 8. Reporting flow

```
Reporting Centre (admin/reports.php) → pick group → pick report
   │
   ▼
admin/report.php  (one renderer for all 23 reports)
   ├─ report builds $columns / $rows / $footer / $align (+ optional $metrics/$chart/$supports)
   ├─ financial reports call the SAME functions the rest of the app uses
   │     (accProfitAndLoss(), accTrialBalance(), accGetJournal() …) — never reimplemented
   ├─ on-screen: paginated (rqPaged())
   └─ CSV / PDF / Print: same $rows, full filtered set (pagination lifted) — an export can
         never disagree with what was on screen
```
