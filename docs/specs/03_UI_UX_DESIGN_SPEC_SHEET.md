# UI/UX Design Spec Sheet

Describes the design system actually implemented in `assets/css/admin/ui.css` (~1,440 lines) and `admin/pos.php`'s own inline styling. Component-level detail lives in `UI_COMPONENTS.md` at the repo root — this document summarises it plus the flows and states that document doesn't cover.

---

## 1. Two distinct visual systems, by design

| System | Used by | Loads |
|---|---|---|
| **Admin design system** | All 41 admin pages (dashboard, inventory, reports, settings…) | `styles.css` (legacy remnant, first) then `ui.css` |
| **POS terminal** | `admin/pos.php` only | Its own inline CSS — **deliberately does not load `ui.css`** |

The POS is a kiosk screen with different needs (huge tap targets, dark background for a shop floor, always-visible cart) — it is not a lesser member of the admin system, it is intentionally separate.

## 2. Design tokens (admin system)

```
Brand         --color-primary #0f9aa8 (teal)  · hover #0c828e · active #0a6d77
Status        success #157f47 · warning #a5620a · danger #c02626 · info #0b7285
              (each has a "-soft" background tint for chips/alerts)
Text          --color-text #16242b · muted #5f7480 · faint #8b9ba5
Surface       white #ffffff · alt #f7fafb · page background #f2f5f7
Border        #e3eaee (default) · #cfdae1 (strong)
Sidebar       dark blue-green #12242c, raised panel #1a3039, active text white
Radius        sm/md/lg tokens — never a bare pixel value in component CSS
```

**Rule enforced throughout:** components read colour from a token, never a hard-coded hex. This is what makes the theme system (§5) possible without duplicating a single component rule.

## 3. Layout shell (admin)

- **Sidebar** (dark), collapsible on desktop, off-canvas on mobile.
- **Top bar** — page title/breadcrumb, Open Till shortcut, notification bell (polls every 30s, each count gated per-role), user menu.
- **Main content** — page header partial, then the page body.
- Card-based content throughout: rounded corners, soft shadow, no bare tables floating on the page background.

## 4. Component inventory (admin system)

Design tokens → typography → app shell → sidebar → page header → buttons → cards & KPIs → tables → forms → badges → alerts → modals → toasts → search/filters/chips → pagination → empty/loading states → utilities → responsive → print. All documented with class names and JS contracts in `UI_COMPONENTS.md`.

**The modal contract**, because it recurs everywhere a list page needs add/edit: one Bootstrap modal per entity, filled via `data-field-*` attributes on the trigger element and read by `MX.initModals()` in `ui.js` — values are assigned via `.value`/`.textContent`, **never as injected HTML**, so row data can never inject markup.

## 5. Theme: light / dark / system

Implemented **only** by redefining the CSS custom properties in one section of `ui.css` — never a duplicated component rule. "System" deliberately sets **no** `data-theme` attribute so `prefers-color-scheme` stays authoritative; an explicit choice stamps `data-theme="dark"`/`"light"`. Preference persists in `localStorage`; an inline script applies it before first paint (no flash of the wrong theme). **Print is force-overridden to light** regardless of the active theme — a receipt must never print white-on-black.

## 6. Responsive behaviour

| Breakpoint | Effect |
|---|---|
| ≤1400px | Tighter page padding |
| ≤1200px | `.ui-col-optional` columns hide |
| ≤992px | Sidebar becomes an off-canvas drawer with overlay; `.ui-col-secondary` columns hide |
| ≤768px | Denser tables, stacked toolbars, full-width toasts |
| ≤576px | Smallest phone layout |

**The off-canvas sidebar hides itself with `transform: translateX(-100%)`, never a computed `left` offset** — a hand-computed offset previously drifted against the shrinking `--sidebar-w` token at narrower breakpoints and left the drawer 36px on-screen, clipping content on every page below 992px. Fixed and re-verified: all 23 reports plus 10 other admin pages at 390px, zero failures, using a same-origin iframe test harness (the only way to get genuine sub-920px viewport testing on this dev machine's Chrome).

## 7. The POS terminal — its own spec

- **Palette:** dark background (`--pos-dark`), teal accent (`--pos-primary`) matching the brand, high-contrast large type.
- **Layout:** left = product grid with department/category tabs and a persistent barcode-scan input (`F2` refocuses it from anywhere); right = live cart with quantity controls, discount, split-payment entry, held-sale access.
- **States a product tile can be in:** normal · out-of-stock (dimmed, click refused) · near-expiry (red `−N%` badge, struck-through original price) · **expired** (fully desaturated, red "EXPIRED" label, click refused with a spoken-aloud-style toast naming the reason) — expired stock is deliberately **kept visible rather than hidden**, so staff know to physically pull it.
- **Customer-facing second display** (`admin/pos.php#customer`, opened via BroadcastChannel `pos_sales_bus`):
  - **Idle state:** shop logo/name, and — when any stock is in its markdown window — a slideshow of today's offers (image, `−5%` badge, was/now price, "best before" date), cross-fading every 7s, paused instantly the moment a sale starts and resumed when the cart clears; also pauses when the tab is hidden and self-refreshes after 30 idle minutes so an overnight-left display picks up the new day's offers.
  - **Active-sale state:** item list (left) and running totals + **Amount due** + **How to pay** (right), the latter block **vertically centred as one group** in the pane (not pinned to the bottom) and the Lipa Namba number rendered large (`clamp(1.8rem,3.6vw,3rem)`), tabular-figure, letter-spaced — sized to be read and typed from a phone at a customer's arm's length.
  - **Paid state:** confirmation + receipt token, auto-reverting to idle after 12s.
  - The display is strictly read-only: it can never write back to the sale.

## 8. Interaction principles observed throughout

- **A blocked action always states why**, in words a non-technical cashier or customer can act on ("Azam White Bread 600g expired on 10 Aug 2026 and cannot be sold. Please remove it from the shelf.") — never a bare refusal, never a stack trace.
- **Destructive actions require confirmation** with the consequence spelled out (e.g. cancelling an approved PO, disabling a department that would drop N products off the till). **A subset go further and require a stated reason, not just a yes/no**: voiding a completed sale, and — newer — cancelling a POS cart (Clear Cart, the last line item being removed, or discarding a held sale), each via one shared modal (dropdown of reason codes + free text for "Other") that writes a permanent record before anything actually disappears; backing out of the modal leaves the cart exactly as it was.
- **In-use records are deactivated, not deleted**, with the flash message explaining why ("…is used by 4 product(s), so it was deactivated rather than deleted").
- **First-run guidance is a checklist, not a nag**: the dashboard's setup banner has no dismiss button (an unconfigured shop shouldn't be able to hide the fact), disappears automatically the moment setup is genuinely complete, and every unfinished item links straight to the screen that fixes it.

## 9. Accessibility / robustness notes

- Sidebar renders its "open" section server-side (not only via JS) so navigation still works with JavaScript disabled — and only one section is ever server-flagged active, which is what makes it a genuine single-open accordion rather than the array-of-remembered-sections behaviour it drifted into previously (`MX.initSidebar()` now closes every other section before applying a manual toggle).
- `aria-live`, `aria-expanded`, and keyboard focus are used on interactive chrome (filter panels, sidebar toggle, Escape-to-close).
- Reduced-motion media query respected on the offer slideshow and other transitions.
