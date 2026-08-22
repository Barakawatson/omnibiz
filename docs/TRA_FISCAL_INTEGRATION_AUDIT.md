# TRA FISCAL INTEGRATION — REQUIREMENTS AUDIT

| | |
|---|---|
| **Phase** | 1 — Requirements audit. **No application code was modified.** |
| **Date** | 14 August 2026 |
| **System** | OmniBiz, PHP 8.2 + MariaDB, XAMPP |
| **Question** | Can this POS submit fiscal receipts to the Tanzania Revenue Authority? |

**Evidence labels used throughout:**

| Label | Meaning |
|---|---|
| **[TRA-VERIFIED]** | Confirmed directly against a TRA-owned host or a page published on `tra.go.tz` during this audit |
| **[CODE-VERIFIED]** | Read from this repository or its live database during this audit |
| **[THIRD-PARTY]** | Community documentation or open-source clients. **Corroborating only. Not authoritative.** |
| **[NOT VERIFIED]** | Could not be confirmed from an official TRA source. Not to be implemented against |
| **[DESIGN]** | My recommendation, not a TRA requirement |

---

## THE HEADLINE FINDING

**This shop cannot lawfully fiscalise its own sales by writing code against the TRA API, and no amount of development changes that.**

From TRA's own site [TRA-VERIFIED]:

> "**VFD**: Is the digital system (software cloud based) which **approved by Commissioner General** for capture, transmit and keep records of sales data electronically."

> "…shall acquire and issue fiscal receipt or fiscal invoice by using electronic fiscal device which **shall purchase from approved Supplier**."

> "**Who is EFD/VFD Supplier?** Is a **certified entity by commissioner General** for import, distribute and sell EFD/VFD, install, configure, integrate, train users on how to use EFDs and provide after sales support including repairs and maintenance."

> "Requirement for Approved as EFD/VFD Supplier: **A minimum capital of Tanzanian shillings one billion and five hundred million** for electronic fiscal device suppliers…"

The fiscal identity that signs a receipt (the certificate, `REGID`, `EFDSERIAL`) is issued by TRA to an **approved VFD**, not to a shop that has written its own client. Therefore:

- The **software** must be an approved VFD, or
- The shop must obtain its VFD **from an approved supplier** and this POS integrates with *that supplier's* system, or
- The vendor of this POS applies to become an approved VFD supplier (capital requirement above).

Everything technical below remains valid and useful — but it is subordinate to this. **Do not deploy a self-built fiscalisation client to production without written confirmation of the route being taken.**

---

## 1. OFFICIAL TRA INTEGRATION METHOD

**[TRA-VERIFIED]** TRA operates an **EFDMS Receipt API** for **VFD (Virtual Fiscal Device)** — cloud software that issues fiscal receipts, as distinct from a physical EFD.

**[TRA-VERIFIED]** Obligation to use one:

> turnover "…**11 million and above per year**; Traders trading in the Region's prime areas…; Traders dealing with selected business sectors such as Spare Parts, Hardware, **Mini Supermarkets**, Petrol stations, Mobile phone shops, Sub wholesale sho[ps]…"

A supermarket in Iringa is squarely inside that scope.

**[NOT VERIFIED]** TRA does **not** publish the VFD developer specification openly. Searching `tra.go.tz` returns supplier lists and taxpayer guidance, not an integration pack. The specification appears to be issued to approved suppliers. **The exact message schema below therefore rests on third-party sources and must be replaced by the official document before any implementation.**

---

## 2–4. ENDPOINTS

All hosts below were probed during this audit. TLS certificate on both `virtual.tra.go.tz` and `verify.tra.go.tz`:

```
subject = C=TZ, L=Dar-es-salaam, O=Tanzania Revenue Authority, CN=*.tra.go.tz
issuer  = DigiCert Global G2 TLS RSA SHA256 2020 CA1
```

so these are genuinely TRA-operated. [TRA-VERIFIED]

### Test / simulation environment [TRA-VERIFIED — endpoints exist and answer]

| Purpose | URL | Probe result |
|---|---|---|
| Registration | `https://virtual.tra.go.tz/efdmsRctApi/api/vfdRegReq` | `200` — *"Welcome to the VFD Registration Request Endpoint"* |
| Token | `https://virtual.tra.go.tz/efdmsRctApi/vfdtoken` | `400 {"error":"unsupported_grant_type"}` |
| Receipt | `https://virtual.tra.go.tz/efdmsRctApi/api/efdmsRctInfo` | `200` — *"Welcome to efdmsRct API"* |
| Z report | `https://virtual.tra.go.tz/efdmsRctApi/api/efdmszreport` | `200` — *"Welcome to efdmsRct API"* |
| Verification portal | `https://virtual.tra.go.tz/efdmsRctVerify/Home/Index` | `200` — page titled *"EFD | Receipt Verification"* |

The token endpoint's `unsupported_grant_type` error is an **OAuth2 password-grant** signature — it confirms the token flow exists without sending credentials.

### Production environment [TRA-VERIFIED as TRA-owned; paths PARTIALLY verified]

| Purpose | URL | Probe result |
|---|---|---|
| Registration | `https://vfd.tra.go.tz/api/vfdRegReq` | `400` (exists, rejects bare GET) |
| Token | `https://vfd.tra.go.tz/vfdtoken` | `200` |
| Receipt | `https://vfd.tra.go.tz/api/efdmsRctInfo` | **[NOT VERIFIED]** — `403` on probe |
| Z report | `https://vfd.tra.go.tz/api/efdmszreport` | **[NOT VERIFIED]** |
| Verification | `https://verify.tra.go.tz/` | `302`, TRA certificate |

**Note the path difference:** production drops the `/efdmsRctApi` prefix. That asymmetry is exactly the kind of detail that must come from the official document, not inference. **[NOT VERIFIED]**

---

## 5. AUTHENTICATION, CERTIFICATES, SIGNING

**[THIRD-PARTY — corroborated by two independent sources, NOT confirmed by TRA]**

| Element | Value |
|---|---|
| Registration | POST XML `<EFDMS><REGDATA><TIN>…</TIN><CERTKEY>…</CERTKEY></REGDATA><EFDMSSIGNATURE>…</EFDMSSIGNATURE></EFDMS>` |
| Headers (registration) | `Content-Type: application/xml`, `Cert-Serial: <base64 serial>`, `Client: webapi` |
| Headers (receipt / Z) | as above **plus** `Authorization: bearer <token>` and `Routing-Key: vfdrct` or `vfdzreport` |
| Token | `x-www-form-urlencoded`, `Username`, `Password`, `grant_type=password`; returns `access_token`, `expires_in` |
| Signing | **SHA1 with RSA**, key from a **PKCS#12 (.pfx)** file; sign the inner element (`<REGDATA>`, `<RCT>`, `<ZREPORT>`); Base64 into `<EFDMSSIGNATURE>` |
| `Cert-Serial` | Base64 of the signing certificate's serial number |

The brief named `CertSerial`, `HashAlgorithm` and `MsgBody`. `Cert-Serial` is corroborated above. **`HashAlgorithm` and `MsgBody` do not appear in the sources I could reach — [NOT VERIFIED].** They may belong to a different or newer TRA interface, which is itself a reason to obtain the current official document.

**Registration credentials cannot be self-generated.** `REGID`, `EFDSERIAL`, `RECEIPTCODE`, the username/password and the signing certificate all come from TRA in the registration response. **Without TRA onboarding, even the test environment cannot be exercised. [TRA-VERIFIED by implication — the registration endpoint requires a TIN TRA has enrolled.]**

---

## 6. REQUIRED RECEIPT AND MESSAGE FIELDS

**[THIRD-PARTY]** Fields commonly required on the receipt message, with the rules attached to them:

| Field | Rule |
|---|---|
| `TIN` | 9 digits |
| `REGID`, `EFDSERIAL` | Issued by TRA at registration |
| `RCTNUM` | Sequential from 1, **never reused** |
| `GC` | Global counter, never resets; "GC must always be equal to RCTNUM" |
| `DC` | Daily counter, resets at midnight |
| `ZNUM` | `YYYYMMDD`, must equal the receipt date |
| `RCTVNUM` | Receipt code + GC — **this is the verification code printed for the customer** |
| `CUSTIDTYPE` | 1=TIN, 2=Licence, 3=Voter, 4=Passport, 5=NID, 6=None |
| Response | `ACKCODE` (0 = success), `ACKMSG` |

Stated ordering rules: future dates rejected; a resubmitted failure must keep its **original** `RCT_TIME`/`ZNUM`; transactions sent **one at a time**, next only after the previous succeeds.

**[TRA-VERIFIED]** The public verification portal at `virtual.tra.go.tz/efdmsRctVerify/Home/Index` has exactly **one** input:

```html
<input id="rctvcode" name="RctVcode" required type="text">   <!-- label: "Enter Receipt Verification Code" -->
```

So the one thing the printed receipt absolutely must carry for a customer to verify is the **Receipt Verification Code**. Anything else printed is presentation.

---

## 7. CURRENT POS ARCHITECTURE [CODE-VERIFIED]

### Where a sale becomes final

`posCheckout()` in `includes/pos_functions.php` — one MySQL transaction:

```
begin_transaction()
  ├─ per line: SELECT … FOR UPDATE (locks the item row)
  ├─ validate: exists, enabled, usage_type, department trading, NOT expired, stock
  ├─ prices recalculated server-side (retailEffectivePrice)
  ├─ tax computed (posTaxRate / posTaxInclusive)
  ├─ tenders validated (electronic ≤ total; change only from cash)
  ├─ INSERT sales_transactions            ← the sale exists from here
  ├─ INSERT sales_transaction_items
  ├─ recordStockMovement() per line       ← stock + stock ledger
  ├─ INSERT sales_payments
  └─ accPostEntry()                       ← DR Cash/Mobile/Bank + COGS, CR Sales + Inventory
commit()
```

**Transaction boundary:** everything or nothing. If the ledger posting fails the sale is rolled back — takings cannot exist on the till but be missing from the books.

### Files and surfaces

| Layer | File |
|---|---|
| Till page, cart, scanner, F2 focus, customer display | `admin/pos.php` |
| Checkout, void, pricing, terminals, held sales | `includes/pos_functions.php` |
| Checkout endpoint (JSON, CSRF header) | `admin/api/pos-checkout.php` |
| Barcode lookup | `admin/api/products-scan.php` |
| Receipt (80 mm) | `admin/pos-receipt.php` |
| Sales list, void | `admin/pos-sales.php` |
| Ledger | `includes/accounting_functions.php` (`accPostEntry`) |
| Stock | `includes/inventory_functions.php` (`recordStockMovement`) |
| Daily close | `admin/z-report.php`, `accCloseDay()` |

### Tables

`sales_transactions`, `sales_transaction_items`, `sales_payments`, `pos_terminals`, `acc_journal`, `acc_journal_lines`, `inv_stock_movements`, `acc_daily_close` (+ `acc_daily_close_departments`).

---

## 8. CURRENT RECEIPT [CODE-VERIFIED]

`admin/pos-receipt.php`, `@page { size: 80mm auto }`, monospaced, thermal-friendly.

| TRA-relevant field | Present today? |
|---|---|
| Business name, address, phone | **Yes** (`shopName`, `shopAddressLine`, configurable) |
| **TIN** | **Yes** — `shop_tin`, printed when set |
| **VRN** | **Yes** — `shop_vrn`, printed when set |
| Receipt number | **Yes** — but see §11 |
| Date / time | **Yes** |
| Item description, qty, amount | **Yes** |
| Total incl. tax | **Yes** |
| Tax line | **Only when `tax_amount > 0`** — currently never, see §10 |
| Payment method(s) | **Yes**, per tender |
| Customer name/phone | **Yes** when a registered customer is chosen |
| **Serial Number / UIN / REGID** | **No** |
| **Z number** | **No** |
| **Receipt Verification Code** | **No** |
| **Tax category per item** | **No** |
| **Customer ID type / ID number** | **No** |
| **Total excluding tax** | **No** (subtotal is pre-discount, not net-of-tax) |

---

## 9. TAX MAPPING — THE LARGEST TECHNICAL GAP [CODE-VERIFIED]

```php
pos_tax_rate      = 0      // one global rate for the whole shop
pos_tax_inclusive = 0
```

- Tax is a **single shop-wide percentage**, stored per sale as `tax_rate` + `tax_amount`.
- **There is no per-product tax class.** `retail_product_details` has no tax column; `inv_items` has none.
- With the rate at 0, **every sale currently records zero tax** and the receipt prints no tax line.

TRA fiscal receipts require a **tax category per item** (standard-rated, zero-rated, exempt, special-rated are the categories generally used) with per-category totals. **[THIRD-PARTY for the category letters — [NOT VERIFIED] against TRA.]**

**Consequence:** even with a perfect API client, this system cannot currently produce a compliant receipt, because it does not know whether a loaf of bread is standard-rated or exempt. This is a **data model gap, not a formatting gap**, and it touches every one of the 477 products.

**Not changed during this audit** — per the brief, tax calculations were not silently altered.

---

## 10. PAYMENT MAPPING [CODE-VERIFIED]

| App method | Ledger account | Plausible TRA type |
|---|---|---|
| `cash` | 1000 Cash on Hand | CASH |
| `lipa_namba` | 1010 Mobile Money | ELECTRONIC / MOBILE **[NOT VERIFIED]** |
| `bank` | 1020 Bank Account | ELECTRONIC/CHEQUE **[NOT VERIFIED]** |
| `card` | 1020 Bank Account | CARD **[NOT VERIFIED]** |

Split tenders are supported and stored per tender in `sales_payments` — good, because a fiscal message must usually carry the payment breakdown. The **exact TRA payment-type vocabulary is [NOT VERIFIED]** and must not be guessed.

---

## 11. RECEIPT NUMBERING — A DIRECT CONFLICT [CODE-VERIFIED]

Current format, from `posGenerateReceiptNo()`:

```
MRT-20260813-001A66
     ^date     ^daily seq  ^3 random chars (so a customer cannot guess another receipt)
```

- The sequence **resets daily**.
- It contains **random characters**.
- It is **shop-wide**, not per terminal.

TRA requires `RCTNUM`/`GC` to be **strictly sequential, gapless, never reused, never reset** [THIRD-PARTY]. These two schemes are incompatible. The fiscal counters must therefore be **new columns owned by the fiscal layer**, not derived from `receipt_no`. The existing human-facing receipt number can stay exactly as it is.

---

## 12. MULTIPLE TERMINALS [CODE-VERIFIED / NOT VERIFIED]

`pos_terminals` exists (id, name, code, department, location). Sales record `terminal_id`.

**[NOT VERIFIED]** Whether TRA issues one VFD identity per shop or per terminal, and how concurrent tills share or partition the `GC` sequence. The third-party rule "send one transaction at a time, only send the next when the current has succeeded" implies a **single serialised fiscal queue per VFD identity** — which, with 1 till today and more later, argues for a **single queue worker**, not per-terminal submission. **[DESIGN]**

---

## 13. RETURNS, VOIDS, CORRECTIONS [CODE-VERIFIED / NOT VERIFIED]

The app has `posVoidSale()`: returns stock, posts a mirror ledger entry (Sales Returns + Inventory / Cash + COGS), marks `status='voided'`. There is **no credit note, no partial refund, no debit note**.

**[NOT VERIFIED]** The official TRA correction process. The third-party source says a wrongly-issued receipt must be **re-sent as a new receipt with a new number** — never the same `RCTVNUM` — which implies fiscal receipts are **immutable and cannot be voided**, only offset. If so, the app's local void has **no fiscal equivalent** and a compliant correction flow is a **required new feature**. This must be confirmed before design.

---

## 14. Z-REPORT / DAILY CLOSE [CODE-VERIFIED]

`accCloseDay()` writes an immutable `acc_daily_close` snapshot and posts any cash variance. It is **entirely internal** — nothing is transmitted.

TRA exposes `…/api/efdmszreport` [TRA-VERIFIED endpoint exists], so a **daily Z submission is expected**. The local close is a good anchor for it, but the fiscal Z must be built from **fiscal** counters (`ZNUM`, GC range, per-tax-category totals), not from the accounting snapshot. **[DESIGN]**

---

## 15. CUSTOMER INFORMATION [CODE-VERIFIED]

`customer` holds **name + phone only**. `sales_transactions` additionally stores `customer_type` (`cash`/`registered`), name and phone.

Missing for fiscal use: **customer ID type** and **ID number** (`CUSTIDTYPE` 1–6 plus the value). For an ordinary walk-in, `CUSTIDTYPE = 6` (None) appears to be the intended value [THIRD-PARTY], so routine retail should not need customer data — but **when a customer demands a TIN-bearing receipt this becomes mandatory**, and the fields do not exist. **[NOT VERIFIED]** exactly when TRA requires them.

---

## 16. TRANSACTION SAFETY — THE ARCHITECTURAL PROBLEM [DESIGN]

`posCheckout()` is a single database transaction. **A TRA call must never be placed inside it**: an HTTP request that hangs would hold row locks on stock across the network, and a rollback cannot un-send a submitted receipt.

Recommended shape:

```
1. posCheckout()  — unchanged, still atomic
       └─ additionally INSERT fiscal_receipts (status = PENDING)   ← inside the same transaction
2. COMMIT          — the sale, stock and ledger are now safe and consistent
3. Fiscal submission — AFTER commit, outside any transaction
       ├─ success → status = FISCALIZED, store RCTVNUM/ACKCODE/…
       ├─ hard reject → status = FAILED   (needs human attention)
       └─ timeout/unknown → status stays PENDING, retried by a worker
4. Receipt prints from whatever state the sale is in
```

States actually needed: **PENDING → SUBMITTED → FISCALIZED**, plus **FAILED** and **UNKNOWN**. `DRAFT`, `COMPLETED`, `CANCELLED`, `RETRY_REQUIRED` from the brief are redundant here — the sale's own `status` already covers completion and cancellation, and retry is a *scheduling* property, not a state.

This deliberately means **the sale can be complete locally while fiscalisation is still pending**. That is unavoidable with an external authority, and is why the receipt must say which state it is in.

---

## 17. DUPLICATE PREVENTION [DESIGN + THIRD-PARTY]

- **Local:** `fiscal_receipts.sale_id` **UNIQUE** — one fiscal record per sale, enforced by the database. Mirrors the existing `acc_journal (source_type, source_id)` idempotency key.
- **TRA-side:** duplicates are detected by `RCTVNUM`; resending the same one is discarded [THIRD-PARTY]. So a retry must resend **byte-identical** content, and a correction must use a **new** number.
- A submission worker must take a **row lock** on the fiscal record before sending, so two workers cannot double-submit.

---

## 18. PROPOSED DATA MODEL [DESIGN — not implemented]

A **dedicated table**, one-to-one with a sale, because multiple states and full request/response payloads must be retained:

```sql
CREATE TABLE fiscal_receipts (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  sale_id       INT NOT NULL,               -- UNIQUE: one fiscal record per sale
  environment   VARCHAR(10) NOT NULL,       -- test | production
  status        VARCHAR(20) NOT NULL,       -- PENDING|SUBMITTED|FISCALIZED|FAILED|UNKNOWN
  gc            BIGINT NULL,                -- global counter, gapless
  dc            INT NULL,                   -- daily counter
  rct_num       BIGINT NULL,
  znum          VARCHAR(8) NULL,
  rctvnum       VARCHAR(60) NULL,           -- the verification code printed for the customer
  ack_code      VARCHAR(10) NULL,
  ack_msg       VARCHAR(255) NULL,
  fiscal_date   DATE NULL,
  fiscal_time   TIME NULL,
  attempts      INT NOT NULL DEFAULT 0,
  last_error    VARCHAR(255) NULL,
  request_xml   MEDIUMTEXT NULL,            -- retained for audit and byte-identical retry
  response_xml  MEDIUMTEXT NULL,
  submitted_at  DATETIME NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_fiscal_sale (sale_id),
  UNIQUE KEY uq_fiscal_rctvnum (rctvnum),
  CONSTRAINT fk_fiscal_sale FOREIGN KEY (sale_id) REFERENCES sales_transactions (id)
);

CREATE TABLE fiscal_counters (  -- gapless sequence, allocated under a row lock
  id INT PRIMARY KEY, gc BIGINT NOT NULL, dc INT NOT NULL, dc_date DATE NOT NULL
);
```

Plus a `fiscal_z_reports` table when the Z requirement is confirmed. **No second sales system, no duplicated accounting.** Sales, stock and ledger stay exactly as they are.

---

## 19. SETTINGS DESIGN [DESIGN]

Keep **Shop information** (name, address, phone, TIN, VRN — already exists in `admin/shop-settings.php`) separate from a new **TRA / Fiscal settings** screen, admin-only via the existing `requireModule('shop_settings')` pattern or a new admin-only key:

| Setting | Notes |
|---|---|
| Fiscalisation enabled | Off by default |
| Environment | **test / production** — admin only; cashiers cannot see or change it |
| TIN | Reuse `shop_tin` |
| REGID / EFDSERIAL / RECEIPTCODE | From TRA registration response — read-only after registration |
| Certificate path | **Filesystem path outside the web root**, never an upload into `assets/` |
| Certificate serial | Displayed, not editable |
| Certificate password | Write-only field; never rendered back |

**Secrets must not live in the database in plaintext, must never reach JavaScript, and the `.pfx` must sit outside `htdocs`.** `includes/db.php` is already noted as an existing weakness for being inside the web root — the certificate must not repeat it.

---

## 20. RISKS AND LIMITATIONS

1. **Legal/governance (highest).** Self-integration is not the sanctioned route; the VFD must be approved by the Commissioner General or bought from an approved supplier.
2. **Specification not public.** The message schema here is third-party. Implementing against it risks silent non-compliance.
3. **No tax model.** Per-item tax categories do not exist and cannot be invented; this blocks a compliant receipt regardless of API work.
4. **Correction process unknown.** The local void has no verified fiscal equivalent.
5. **Numbering conflict.** Fiscal counters must be new and independent of `receipt_no`.
6. **No queue infrastructure.** No cron, no worker, no job table today; retries need one (Windows Task Scheduler or a manual "retry pending" screen).
7. **Offline behaviour is a legal question, not a technical one.** [NOT VERIFIED] What TRA permits when its API is unreachable. Do not invent an offline fiscalisation mode.
8. **Clock accuracy.** Future-dated receipts are rejected; the shop PC's clock becomes compliance-critical.

---

## 21. IMPLEMENTATION PHASES [DESIGN]

| Phase | Content | Gate to proceed |
|---|---|---|
| **0** | Confirm the legal route; obtain the official TRA VFD specification and test credentials | **Blocking. Nothing below can start without it** |
| 1 | This audit | Done |
| 2 | Per-item tax categories: schema, admin UI, backfill 477 products | Tax authority confirmed |
| 3 | `fiscal_receipts` + `fiscal_counters` schema, no submission | Reviewed |
| 4 | TRA settings screen, certificate handling, secrets outside web root | Security review |
| 5 | Registration + token against **test** only | Token obtained |
| 6 | Signing and XML build; submit to test; store responses | Test receipts verify on the TRA portal |
| 7 | Wire into `posCheckout()` as a post-commit step | Sale/stock/ledger unchanged under failure injection |
| 8 | Receipt: fiscal block + verification code | Printed on 80 mm hardware |
| 9 | Retry worker, pending/failed dashboard, reconciliation report | Survives TRA outage simulation |
| 10 | Z report submission | Confirmed required |
| 11 | Production switch | Written approval |

---

## 22. FINAL STATUS

# TRA INTEGRATION STATUS: **NOT READY — REQUIREMENTS/INFORMATION STILL MISSING**

**Blocking items, in order:**

1. **The legal route is not established.** [TRA-VERIFIED] A VFD must be approved by the Commissioner General and acquired from an approved supplier.
2. **The official specification has not been obtained.** [NOT VERIFIED] Field names, XML schema, tax categories, payment types, correction process, offline rules and the production receipt endpoint all rest on third-party sources.
3. **No TRA credentials exist.** No TIN enrolment, no certificate, no `REGID`. Even the test environment cannot be exercised.
4. **The application has no tax model.** Per-item tax categories are absent, and the shop-wide rate is 0.

**What is genuinely ready:** the transaction architecture. `posCheckout()` is atomic, prices are server-side, tenders are itemised, stock and ledger are already idempotent and reversible, and the receipt is a clean 80 mm template with TIN/VRN support. A fiscal layer can be added **around** it without disturbing barcode scanning, F2 focus, the cart, inventory, purchasing, accounting, reports, authentication or RBAC.

**Recommended next step:** contact TRA or an approved VFD supplier, establish the route, and obtain the official integration pack and test credentials. Phase 2 (tax categories) can proceed in parallel, since per-item tax classification is needed for correct VAT reporting regardless of how fiscalisation is ultimately delivered.

---

## SOURCES

- [TRA — Know about E-Fiscal Devices (EFD)](https://www.tra.go.tz/page/know-about-e-fiscal-devices-efd) — definitions, obligation, supplier approval **[TRA-VERIFIED]**
- [TRA — EFD/VFD Suppliers](https://www.tra.go.tz/page/efd-vfd-suppliers) **[TRA-VERIFIED]**
- `https://virtual.tra.go.tz/efdmsRctApi/…` — endpoints probed directly **[TRA-VERIFIED]**
- `https://virtual.tra.go.tz/efdmsRctVerify/Home/Index` — verification portal, single `RctVcode` field **[TRA-VERIFIED]**
- `https://vfd.tra.go.tz/…`, `https://verify.tra.go.tz/` — production hosts probed **[TRA-VERIFIED as TRA-owned]**
- [TRA VFD API Documentation (tra-docs.netlify.app)](https://tra-docs.netlify.app/guide/api/) — **community mirror, not official [THIRD-PARTY]**
- [Golang-Tanzania/tra-vfd](https://github.com/Golang-Tanzania/tra-vfd) — endpoint constants **[THIRD-PARTY]**
