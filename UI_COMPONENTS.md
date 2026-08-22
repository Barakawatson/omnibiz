# UI Components — OmniBiz admin

The shared visual layer for every staff-facing page. **Build from these components rather than inventing markup** — restyling then upgrades every screen at once.

Verified against `assets/css/admin/ui.css` (1,202 lines) and `assets/js/admin/ui.js` (522 lines) on 13 August 2026.

**Best working examples:** `admin/inventory-categories.php` and `admin/inventory-suppliers.php` are the cleanest current screens — page header, stat cards, toolbar, table, shared modal, empty state. Copy one of those rather than starting from scratch.

> **The POS terminal is not part of this system.** `admin/pos.php` is deliberately standalone: its own inline CSS, its own class names, no sidebar, and it does **not** load `ui.css` or `ui.js`. Do not apply anything below to it.

---

## Load order

```html
<link rel="stylesheet" href="…bootstrap@5.3.2…">        <!-- CDN -->
<link rel="stylesheet" href="../assets/css/admin/styles.css">  <!-- legacy, 36 lines -->
<link rel="stylesheet" href="../assets/css/admin/ui.css">      <!-- the design system -->
```

**Order matters.** `styles.css` is a 36-line remnant kept so nothing referencing its rules breaks; `ui.css` must load after it to win. `admin/inventory-header.php` emits both correctly — page authors never write these tags.

---

## Naming — three prefixes, three meanings

| Prefix | Meaning | Rename? |
|---|---|---|
| `ui-*` | The canonical component set. **Use these for anything new.** | Free to change |
| `mx-*` | The application shell — sidebar, toasts, dropdowns, nav. **A contract with `ui.js`**, which binds to these exact names. | **Never** |
| `inv-*` | First-class aliases defined alongside their `ui-*` equivalents, because 20+ pages already use them. | Keep |

`inv-card` = `ui-card`, `inv-table` = `ui-table`, `inv-stat-card` = `ui-kpi`. They share one rule block, so both stay in step.

---

## Tokens

Never hard-code a colour, radius, shadow or spacing value. Everything is a custom property on `:root`.

```css
/* Brand */    --color-primary #0f9aa8   --color-primary-hover   --color-primary-active
               --color-primary-soft      --color-primary-softer  --color-primary-border
/* Status */   --color-success #157f47   --color-warning #a5620a
               --color-danger  #c02626   --color-info    #0b7285   (+ each …-soft)
/* Text */     --color-text #16242b  --color-text-muted  --color-text-faint  --color-text-invert
/* Surfaces */ --color-surface  --color-surface-alt  --color-background
               --color-border   --color-border-strong
/* Sidebar */  --color-sidebar #12242c  --color-sidebar-raised  --color-sidebar-text  --color-sidebar-active
/* Radius */   --radius-sm 6  --radius-md 8  --radius-lg 12  --radius-xl 16  --radius-pill 999
/* Shadow */   --shadow-sm  --shadow-md  --shadow-lg          (restrained on purpose)
/* Spacing */  --space-1 4 … --space-8 32                     (4px rhythm)
/* Motion */   --ease  --fast .12s  --normal .2s
/* Layout */   --sidebar-w 254  --sidebar-collapsed-w 68  --topbar-h 56
/* Accents */  --accent-home  --accent-sales  --accent-inventory  --accent-accounting  --accent-account
```

`--mx-*` and `--inv-*` aliases map onto these rather than duplicating them, so older inline styles keep working from one source of truth.

**Principles:** calm surfaces, one accent, restrained shadows, consistent 4px rhythm, readable density. This is an operational tool — clarity beats decoration.

---

## Page skeleton

Pages never write `<head>`, the sidebar or the top bar. Set variables, include the header, render the body, include the footer.

```php
$pageTitle    = 'Suppliers';                                  // browser + top bar title
$pageSubtitle = 'Who you buy from';                           // optional
$breadcrumbs  = [['Dashboard','index.php'], ['Inventory','inventory-dashboard.php'], ['Suppliers']];
$topbarSearch = ['placeholder'=>'Search…', 'name'=>'q', 'action'=>'', 'value'=>$q];  // optional
$pageHead     = '<link rel="stylesheet" href="…">';           // optional per-page <head> assets
include 'inventory-header.php';

$ph = ['title'=>'Suppliers', 'icon'=>'fa-truck-field',
       'subtitle'=>'Who you buy from, and what you still owe them.',
       'actions'=>'<button class="ui-btn ui-btn-primary" …><i class="fas fa-plus"></i>Add supplier</button>'];
include 'partials/page-header.php';

/* … page body … */

$pageScript = <<<'HTML'
<script>/* page-specific JS */</script>
HTML;
include 'inventory-footer.php';
```

The last breadcrumb is the current page and carries no URL. `$ph['actions']` is emitted **as-is**, so escape anything dynamic you interpolate into it.

---

## Shared partials

| Partial | Variable | Notes |
|---|---|---|
| `partials/page-header.php` | `$ph` = `title`, `subtitle`, `icon`, `actions` | Title block with right-aligned actions |
| `partials/empty-state.php` | `$es` = `icon`, `title`, `msg`, `action` | Explains and offers the next step |
| `partials/pagination.php` | `$pg` = `page`, `pages`, `total`, `per_page`, `base`, `label` | Renders **nothing** when there is one page — a lone "1" tells the user nothing |

---

## Components

### Buttons

```html
<button class="ui-btn ui-btn-primary"><i class="fas fa-plus"></i>Add supplier</button>
```

| Variant | Use |
|---|---|
| `ui-btn-primary` | The one affirmative action on the screen |
| `ui-btn-secondary` | Everything else |
| `ui-btn-ghost` | Tertiary / dismissive ("Clear") |
| `ui-btn-danger` | Destructive — outlined, fills red on hover |
| `ui-btn-danger-solid` | Confirmed destructive, inside a modal |

Sizes and shapes: `ui-btn-sm` (30px), `ui-btn-lg` (44px), `ui-btn-icon` (32px square), `ui-btn-block` (full width). Loading state: `ui-btn.is-loading` hides the label and spins — applied automatically by `data-ui-loading`, or manually via `MX.setLoading(btn, true)`.

Bootstrap's `.btn`, `.btn-primary`, `.btn-danger` etc. are restyled to match, so existing markup does not look foreign.

### Cards

```html
<div class="ui-card">                          <!-- or .inv-card -->
    <div class="ui-card-head"><h2 class="ui-card-title">Recent activity</h2></div>
    <div class="ui-card-body">…</div>
    <div class="ui-card-foot">…</div>
</div>
```

`<div class="inv-card p-0">` is the common wrapper for a full-bleed table.

### KPI / stat cards

```html
<div class="inv-stat-card bg-grad-teal">        <!-- or .ui-kpi -->
    <div class="d-flex justify-content-between align-items-start">
        <div><div class="label">Suppliers</div><div class="value">14</div></div>
        <i class="fas fa-truck-field icon"></i>
    </div>
</div>
```

Inner elements are `.label`, `.value` and `.icon`. Accent variants: `bg-grad-teal | green | blue | amber | purple | red` (these set the left accent bar, not a literal gradient background). `ui-kpi.k-accent` gives the same bar without a colour choice. Trend indicator: `ui-trend` + `ui-trend-up | -down | -flat`.

### Tables

```html
<div class="inv-card p-0">
  <div class="ui-table-wrap">                  <!-- horizontal scroll lives here -->
    <table class="inv-table">                  <!-- or .ui-table -->
      <thead><tr>
        <th>Supplier</th>
        <th class="ui-col-optional">Contact</th>
        <th class="ui-col-secondary">Phone</th>
        <th class="text-end">Outstanding</th>
      </tr></thead>
      <tbody><tr data-search="iringa wholesalers 0754…" data-status="active"> … </tr></tbody>
    </table>
  </div>
</div>
```

- Headers are sticky. `.text-end` right-aligns **and** applies tabular numerals.
- `ui-col-optional` hides below **1200px**; `ui-col-secondary` hides below **992px**. Use them so dense tables degrade instead of overflowing.
- Always wrap in `ui-table-wrap` — that is what keeps the page body from scrolling sideways.

### Badges and status

```html
<span class="ui-badge ui-badge-success"><span class="ui-dot ui-dot-success"></span>Active</span>
```

`ui-badge-success | warning | danger | info | neutral`. Standalone dots: `ui-dot-success | warning | danger | muted`.

Legacy, still styled: `mx-status` + `s-success | s-warning | s-danger | s-info`, and `inv-badge`.

### Toolbar, search and chips

```html
<form method="get" class="ui-toolbar" role="search">
    <div class="ui-search has-value">
        <i class="fas fa-magnifying-glass"></i>
        <input type="search" name="q" value="…" placeholder="Search…">
        <button type="button" class="ui-search-clear"><i class="fas fa-xmark"></i></button>
    </div>
    <button class="ui-btn ui-btn-secondary">Search</button>

    <a href="?status=active" class="ui-chip active">Active <span class="ui-chip-count">12</span></a>
    <a href="?status=inactive" class="ui-chip">Inactive</a>

    <span class="ui-toolbar-spacer"></span>
    <span class="ui-result-count"><strong>12</strong> shown</span>
</form>
```

`MX.initSearch()` wires the clear button and toggles `has-value` automatically — you do not need to set that class server-side, though rendering it saves a flash.

### Forms

Bootstrap form classes are restyled: `.form-label`, `.form-control` (`-sm`/`-lg`), `.form-text`, `.form-check-input`, `.form-switch`, `.is-invalid`. Additions: `ui-required` (the red asterisk), `ui-fieldset` + `ui-fieldset-legend`, `ui-help`, `ui-error`.

### Modals — one per page, not one per row

The pattern that keeps page weight constant instead of growing with the row count. A trigger declares the modal and carries the row's values:

```html
<button data-ui-modal="#supplierModal"
        data-title="Edit supplier"
        data-field-id="7"
        data-field-name="Iringa Wholesalers"
        data-field-contact-person="J. Mwakalinga"
        data-field-is-active="1">
```

Inside the modal, elements opt in:

| Attribute | Effect |
|---|---|
| `name="contact_person"` | `.value` is set (checkboxes get `.checked`; selects fall back to the first option if the value is absent) |
| `data-ui-text="name"` | `.textContent` is set |
| `data-ui-src="image"` | `.src` is set |
| `data-ui-href="url"` | `.href` is set |
| `data-ui-show="barcode"` | Hidden when the value is empty |
| `data-ui-modal-title` | Receives `data-title` |

`data-field-contact-person` maps to the field named `contact_person` — the browser camel-cases the attribute and `MX.initModals()` converts it back.

**Nothing is ever injected as HTML.** Values are assigned via `.value` / `.textContent`, so row data cannot inject markup. Keep it that way.

Add `data-ui-loading` to the form so the submit button disables and spins. Use `ui-modal-danger` for a destructive confirmation.

### Empty and loading states

`ui-empty` > `ui-empty-title` + `ui-empty-msg` (use `partials/empty-state.php`). Loading: `ui-spinner`, `ui-skeleton`.

### Pagination

`ui-pagination` > `ui-pagination-info` + `ui-pagination-pages` > `ui-page-link` / `ui-page-gap`. Use `partials/pagination.php`.

### Text helpers

`ui-title` · `ui-subtitle` · `ui-section-title` · `ui-body` · `ui-muted` · `ui-caption` · `ui-label` · `ui-num` (tabular numerals) · `ui-money` (tabular, no wrap) · `ui-truncate` · `ui-code` · `ui-divider` · `ui-thumb` / `ui-thumb-placeholder` · `ui-meter` / `ui-meter-fill`.

Defined but currently unused: `ui-bars` / `ui-bar` / `ui-bar-col` / `ui-bar-label` / `ui-bar-value` — a CSS bar chart, ready for the next one rather than hand-rolling flex utilities again.

### Row action menus

`mx-actions` > `mx-actions-btn` (the `…`) + `mx-actions-menu` with links or buttons; `.danger` for destructive items. Click-outside and Escape close it. Wired by `MX.initDropdowns()`.

---

## Sidebar

Grouped accordion. Sections collapse and expand, remember their state in `localStorage`, and the section containing the current page auto-expands with a `has-active` highlight. Collapsed (icon-only) mode shows tooltips on hover.

```php
<?php echo navSectionOpen('sales', 'fa-cart-shopping', 'Sales', 'sales', 0, $secPages['sales']); ?>
    <?php echo navLink('pos.php', 'fa-cash-register', 'POS Terminal', ['pos.php']); ?>
    <?php echo navLink('pos-sales.php', 'fa-receipt', 'Sales & Receipts',
        ['pos-sales.php','pos-receipt.php']); ?>
<?php echo navSectionClose(); ?>
```

`navLink($href, $icon, $label, $activePages, $badge, $extraClass)` — `$activePages` lists every filename that should light the link up, so a detail page keeps its parent highlighted.

`navBadge($key, $count, $variant)` renders `#nav-badge-{key}`, which `MX.watchAlerts()` updates live. Variants: `primary`, `alert`, `warn`, `info`, `muted`.

`navSectionOpen($key, $icon, $label, $accent, $rollupCount, $ownPages)` — accent is one of `home | sales | inventory | accounting | account`.

The five headings are **Overview**, **Sell**, **Stock**, **Finance**, **Account**. Every link is wrapped in `userCan()`, so users never see a link they cannot follow — **when you add a page, add its guard and its sidebar entry together.**

---

## JavaScript API (`window.MX`)

One global, defined in `assets/js/admin/ui.js`, auto-initialised on `DOMContentLoaded`. Safe to include everywhere.

```js
MX.toast('Saved', 'success');
MX.toast({ title: 'Low stock alert', message: '3 items at reorder level.', type: 'danger',
           href: 'inventory-items.php', timeout: 9000 });   // success | warning | danger | info

MX.filterTable(tableEl, 'query');   // manual client-side filter
MX.setLoading(btn, true);           // button spinner
MX.money(1500);                     // "Tsh 1,500"
MX.timeAgo('2026-08-12 09:15:00');  // "2h ago"
MX.confirmSubmit('formId', 'Delete this?');
```

Also exposed, all called automatically at boot: `MX.initSidebar()`, `MX.initFilters()`, `MX.initDropdowns()`, `MX.initSearch()`, `MX.initModals()`, `MX.initFormLoading()`, `MX.watchAlerts()`, `MX.fillModal()`.

### Declarative filtering — no page reloads

Add `data-search` to each row (a lowercase haystack) and point an input at the table:

```html
<input data-mx-filter="#itemsTable" placeholder="Search…">
<span data-mx-count="#itemsTable">42</span>
<div class="ui-empty" data-mx-empty="#itemsTable" style="display:none">No matches</div>

<table id="itemsTable" class="inv-table">
  <tbody><tr data-search="azam cola 500ml sm-bev-001" data-status="active"> … </tr></tbody>
</table>
```

Chip filters use `data-mx-chip="#itemsTable"` + `data-mx-value="active"`, matched against each row's `data-status`. Escape clears the search box.

### Live alerts

`MX.watchAlerts(30000)` polls `admin/notifications-api.php` every 30s, repaints sidebar badges in place, and raises a toast for any count that **increased** since the last poll. It primes silently on first load, so opening a page is never noisy. Counts and their targets:

| Key | Toast | Links to |
|---|---|---|
| `low_stock` | Low stock alert (danger) | `inventory-items.php` |
| `requests` | Stock request (warning) | `inventory-requests.php` |
| `open_pos` | Purchase orders (info) | `inventory-purchase-orders.php` |
| `unclosed_days` | Unclosed day (warning) | `z-report.php` |

The endpoint gates each count by `userCan()`, so a cashier is never told how many purchase orders are open. **Badge ids, the `ALERT_META` map in `ui.js` and the endpoint's response keys must agree** — a mismatch means the count silently paints nowhere.

Opt a page out with `<body data-mx-noalerts>`. Previous counts are cached in `localStorage`.

### Server flash → toast

Set `$_SESSION['inv_flash'] = ['type' => 'success', 'msg' => '…']` then redirect. `inventory-header.php` reads it, **clears it**, and emits:

```html
<span data-mx-flash="Supplier added." data-mx-flash-type="success"></span>
```

`ui.js` converts that to a toast and removes the element. Clearing on read is what stops the message reappearing on refresh.

### localStorage keys

| Key | Contents |
|---|---|
| `mxOpenSections` | Which sidebar sections are expanded |
| `mxLastAlertCounts` | Previous badge counts, so only increases toast |
| `sidebarState` | `collapsed` / `expanded` |

`sessionStorage` is not used anywhere.

### No-JS fallback

`ui.css` carries `.no-js` rules that force every nav section open. `ui.js` removes the class at boot, so navigation still works if the script fails to load.

---

## Responsive

| Breakpoint | Behaviour |
|---|---|
| ≤ 1400px | Tighter page padding |
| ≤ 1200px | `.ui-col-optional` columns hide |
| ≤ 992px | Sidebar becomes an off-canvas drawer with an overlay; `.ui-col-secondary` columns hide |
| ≤ 768px | Denser tables, stacked toolbars, full-width toasts |
| ≤ 576px | Smallest phone layout |

**992px is the sidebar breakpoint and it is duplicated in `ui.js` (`toggleSidebar`) — keep the two in step.**

Removing the old `@media (max-width:1440px)` sidebar rule from `styles.css` is what stopped ordinary laptops getting the mobile drawer; do not reintroduce a rule like it.

**The drawer hides itself with `transform: translateX(-100%)`, never a computed `left`.** It used to sit at `left: calc(-1 * var(--sidebar-w) - 8px)` while its mobile `width` was a hardcoded `258px` — but `--sidebar-w` shrinks to 234px and then 214px at narrower breakpoints, so the two drifted and the drawer stayed 36px on-screen, covering the left edge of every page below 992px. A percentage of its own width cannot drift. Verified across all report screens and 10 other admin pages at 390px and 768px.

---

## Print

`@media print` hides the sidebar, top bar, overlay, back-to-top, toasts, page actions, toolbars and pagination; clears the content offset; flattens cards to a plain border; and drops the body to 11pt on white. Receipts, the Z-report and barcode labels rely on this.

---

## Dashboards

`admin/index.php` combines every module: stat cards for today's takings, gross profit, expected cash in the drawer and items needing reorder; a 7-day sales trend beside the department split; then top sellers, the reorder list and recent activity. `admin/manager-overview.php` is the manager's equivalent, adding cashier and terminal performance, daily summaries with their close status, and slow movers.

Both use `.inv-stat-card` with the `bg-grad-*` accents. The 7-day trend on `index.php` is **not** a charting library — it is hand-rolled Bootstrap flex utilities (`d-flex align-items-end` over `flex-fill` columns) inside an `inv-card`. `admin/inventory-dashboard.php` is the only page in the whole application that loads Chart.js.

The `ui-bars` / `ui-bar` / `ui-bar-col` helpers in `ui.css` are the intended replacement for that hand-rolled markup, but **nothing uses them yet** — reach for them before hand-rolling another bar chart.

Both dashboards render entirely server-side on load. The only live polling in the admin area is `MX.watchAlerts()`.

---

## Adding a component

1. Check it is not already here — `inv-*` aliases mean the thing you want may exist under an older name.
2. Add it to the matching numbered section of `ui.css` (1 Tokens … 19 Print).
3. Use tokens only. No literal colours, radii, shadows or pixel spacing.
4. Give it a `ui-` prefix. Only touch `mx-*` if `ui.js` binds to it, and then never rename.
5. Check it at 1200px, 992px and 768px, and in print if it can appear on a report.
6. If it needs JavaScript, hang it off `MX` and call it from `boot()`.
