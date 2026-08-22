<?php
// ============================================================
// Retail POS Terminal - fast, touch-friendly till for walk-in sales.
// Left: product grid + department and category tabs + search.
// Right: cart, discount, totals, quick cash tender, actions.
// A global keydown listener captures hardware barcode scanners
// (USB/Bluetooth) anywhere on the page.
//
// The chosen terminal is remembered in the session, so a till that
// pinned to a department stays on that department's stock across
// sales and shift changes without being re-picked every time.
// ============================================================
require_once '../includes/auth.php';
requireModule('pos');
// Reject any POST that does not carry this session's CSRF token.
// Placed before every handler on this page, and a no-op on GET.
csrfRequire();
$current_page = basename($_SERVER['PHP_SELF']);
include '../includes/db.php';
require_once '../includes/pos_functions.php';
posBoot($conn);

$cashierId   = (int)($_SESSION['id'] ?? 0);
$cashierName = $_SESSION['username'] ?? '';
function posFlash($type, $msg) { $_SESSION['inv_flash'] = ['type' => $type, 'msg' => $msg]; }

$terminals = posGetTerminals($conn);

// ---------- Terminal selection (sticky per session) ------------------
if (isset($_GET['terminal'])) {
    $picked = posGetTerminal($conn, (int)$_GET['terminal']);
    if ($picked) { $_SESSION['pos_terminal_id'] = (int)$picked['id']; }
    header('Location: pos.php'); exit;
}
$terminalId = (int)($_SESSION['pos_terminal_id'] ?? 0);
$terminal   = posGetTerminal($conn, $terminalId);
if (!$terminal && $terminals) {
    // Default to the first active till rather than making the cashier
    // choose before they can serve anyone.
    $terminal = $terminals[0];
    $terminalId = (int)$terminal['id'];
    $_SESSION['pos_terminal_id'] = $terminalId;
}

// A till pinned to a department opens on that department's stock. Two
// values mean "open on everything" instead: no department at all, and
// the general key.
//
// The general key is a deliberate compatibility rule, not an assumption
// about the business. It is the department every install starts with and
// the one a mixed till is assigned to, so a shop that has always had its
// General Till show the whole range keeps that behaviour. Every other
// department behaves the same way whatever the shop calls it - Food,
// Plumbing or Prescription Medicines - and a till assigned to one opens
// on it. The cashier can switch with the department tabs either way, and
// "All Departments" is always the first tab.
$terminalDepartment = (string)($terminal['department'] ?? '');
if ($terminalDepartment === catalogDefaultDepartmentKey()) { $terminalDepartment = ''; }
$activeDepartment   = (string)($_GET['dept'] ?? $terminalDepartment);

// A till pinned to a department that has since been renamed away,
// removed or switched off falls back to showing everything, rather than
// an unexplained empty grid.
if ($activeDepartment !== '' && !isset(catalogDepartments($conn)[$activeDepartment])) {
    $activeDepartment = '';
}
if ($activeDepartment !== '' && !catalogDepartmentEnabled($conn, $activeDepartment)) {
    $activeDepartment = '';
}

// ---------- Held sales (server-side so any till can resume) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'hold') {
        $cart = json_decode($_POST['cart_json'] ?? '[]', true) ?: [];
        [$ok, $msg] = posHoldSale($conn, $cart, $cashierId, $cashierName,
            trim($_POST['label'] ?? ''), (float)($_POST['total'] ?? 0), $terminalId);
        posFlash($ok ? 'success' : 'danger', $msg);
        header('Location: pos.php'); exit;
    }
    if ($action === 'delete_held') {
        posDeleteHeldSale($conn, (int)($_POST['held_id'] ?? 0));
        posFlash('success', 'Held sale discarded.');
        header('Location: pos.php'); exit;
    }
}

// Stock nearing its expiry date, marked down automatically and shown on
// the customer display between sales (see retailExpiryOffers()). Loaded
// for every till page, because the customer window is this same file
// re-opened with #customer - there is no second route to feed.
$expiryOffers  = retailExpiryOffers($conn, 24);
$expiryPercent = retailExpiryDiscountPercent($conn);
$expiryDays    = retailExpiryDiscountDays($conn);

// The grid loads every product the till may sell; department and
// category filtering then happens client-side, so switching tabs is
// instant and does not cost a round trip mid-queue.
$products  = posGetProducts($conn);
$heldSales = posGetHeldSales($conn);
$taxRate   = posTaxRate($conn);
// Only departments that are actually trading get a tab - a disabled one
// would show an empty grid with no explanation.
$departments = catalogActiveDepartments($conn);

// Categories are derived from what is actually on the grid, so a
// disabled department never leaves an empty tab behind.
$activeDeptKeys = array_keys($departments);
$categories = [];
if ($activeDeptKeys) {
    $inList = "'" . implode("','", array_map([$conn, 'real_escape_string'], $activeDeptKeys)) . "'";
    $catRows = $conn->query(
        "SELECT DISTINCT c.id, c.name, c.department
         FROM inv_categories c
         JOIN inv_items i ON i.category_id = c.id AND i.status = 'active' AND i.deleted_at IS NULL
              AND i.department IN ($inList)
         JOIN retail_product_details d ON d.item_id = i.id AND d.is_enabled = 1 AND d.is_pos_visible = 1 AND d.usage_type IN ('sale','both')
         ORDER BY c.name");
    $categories = $catRows ? $catRows->fetch_all(MYSQLI_ASSOC) : [];
}

// Today's till summary for this cashier.
$tillStmt = $conn->prepare(
    "SELECT COUNT(*) AS sales, COALESCE(SUM(total),0) AS takings
     FROM sales_transactions
     WHERE status = 'completed' AND DATE(created_at) = CURDATE() AND cashier_id = ?");
$tillStmt->bind_param('i', $cashierId);
$tillStmt->execute();
$till = $tillStmt->get_result()->fetch_assoc();
$tillStmt->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>POS Terminal &middot; <?php echo htmlspecialchars(shopName($conn)); ?></title>
    <link rel="icon" type="image/png" href="../assets/images/favicon.png">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        /* ============================================================
           POS TERMINAL — presentation only
           ------------------------------------------------------------
           Every class name below is a contract with the terminal's
           JavaScript further down this file: renderCart() builds
           .cart-line / .cl-* / .qty-btn markup at runtime, and the
           scanner, filters and checkout bind to these ids. Names and
           structure are unchanged — only the visual treatment.

           Calm, high-contrast and dense: a cashier reads this at a
           glance under shop lighting, often on a touchscreen. Touch
           targets are >=40px and transitions are short, so nothing
           lags behind a barcode scan.
           ============================================================ */
        :root {
            --pos-primary: #0f9aa8;
            --pos-primary-dark: #0c828e;
            --pos-dark: #12242c;
            --pos-line: #e3eaee;
            --pos-bg: #f2f5f7;
            --pos-text: #16242b;
            --pos-muted: #5f7480;
            --pos-success: #157f47;
            --pos-danger: #c02626;
        }
        * { box-sizing: border-box; }
        body {
            font-family: 'Poppins', system-ui, -apple-system, 'Segoe UI', sans-serif;
            background: var(--pos-bg);
            margin: 0; height: 100vh; overflow: hidden;
            color: var(--pos-text);
            -webkit-font-smoothing: antialiased;
        }
        :focus-visible { outline: 2px solid var(--pos-primary); outline-offset: 2px; }

        .pos-shell { display: flex; flex-direction: column; height: 100vh; }

        /* ---- Top bar ---------------------------------------------- */
        .pos-top {
            background: var(--pos-dark); color: #fff;
            padding: 10px 16px;
            display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
            flex-shrink: 0;
        }
        .pos-top .brand { font-weight: 600; font-size: .9375rem; display: flex; align-items: center; gap: 8px; }
        .pos-top .brand i { color: var(--pos-primary); }
        .pos-top .brand .pos-logo { max-height: 22px; max-width: 90px; object-fit: contain; }
        .pos-top .till { font-size: .75rem; color: #a9bdc7; }
        .pos-top .till strong { color: #fff; font-variant-numeric: tabular-nums; }
        .pos-top .spacer { flex: 1; }
        /* The bar gained two controls; let it shrink its children
           rather than wrap onto a second row and eat grid height. */
        .pos-top { flex-wrap: nowrap; }
        .pos-top > * { flex-shrink: 0; }
        .pos-top .scan-wrap { flex-shrink: 1; min-width: 140px; }
        @media (max-width: 1200px) {
            /* Icon-only buttons below this width - the icons are
               unambiguous and the labels are the first thing to go. */
            .pos-top .pos-topbtn span { display: none !important; }
            /* ...except Sales & receipts. Customer screen and full
               screen are optional extras a cashier can manage without
               finding; this is their ONLY route to their own sales and
               to reprinting a receipt, and a bare icon does not say so.
               It costs about 110px, which the bar can spare down to the
               next breakpoint. */
            .pos-top .pos-topbtn-labelled span { display: inline !important; }
        }
        @media (max-width: 900px) {
            /* Below this the bar genuinely needs the room - the till
               name and the running total have already gone. */
            .pos-top .pos-topbtn-labelled span { display: none !important; }
        }
        @media (max-width: 860px) {
            .pos-top .till { display: none; }
        }
        .pos-topbtn {
            border-radius: 8px; border: 1px solid rgba(255,255,255,.18);
            background: rgba(255,255,255,.06); color: #dfeaef;
        }
        .pos-topbtn:hover { background: rgba(255,255,255,.14); color: #fff; }
        .pos-topbtn:focus-visible { outline: 2px solid var(--pos-primary); outline-offset: 2px; }
        /* In full screen the browser chrome is gone, so reclaim the
           padding the window furniture used to justify. */
        body.pos-fullscreen .pos-top { padding-top: 6px; padding-bottom: 6px; }
        .pos-top a.exit {
            color: #a9bdc7; text-decoration: none; font-size: .8125rem;
            padding: 7px 12px; border-radius: 8px;
            border: 1px solid rgba(255,255,255,.14);
        }
        .pos-top a.exit:hover { color: #fff; background: rgba(255,255,255,.08); }

        .terminal-select {
            width: auto; border-radius: 8px; font-size: .8125rem; height: 38px;
            border: 1px solid rgba(255,255,255,.18);
            background: rgba(255,255,255,.07); color: #fff;
            padding: 0 30px 0 10px;
        }
        .terminal-select option { color: var(--pos-text); }
        .terminal-select:focus { box-shadow: 0 0 0 3px rgba(15,154,168,.35); border-color: var(--pos-primary); outline: none; }

        /* ---- Scanner: the single most important control ------------- */
        .scan-wrap { position: relative; min-width: 260px; flex: 1; max-width: 460px; }
        .scan-wrap i {
            position: absolute; left: 13px; top: 50%; transform: translateY(-50%);
            color: var(--pos-primary); font-size: .9375rem;
        }
        #scanInput {
            width: 100%; height: 42px;
            border-radius: 10px;
            border: 2px solid var(--pos-primary);
            background: #fff; color: var(--pos-text);
            padding: 6px 12px 6px 38px;
            font-size: .9375rem;
        }
        #scanInput::placeholder { color: #8b9ba5; }
        #scanInput:focus { outline: none; box-shadow: 0 0 0 4px rgba(15,154,168,.3); }

        /* ---- Body ---------------------------------------------------- */
        .pos-body { flex: 1; display: flex; min-height: 0; }
        .pos-left { flex: 1; display: flex; flex-direction: column; min-width: 0; padding: 14px; }
        .pos-right {
            width: 400px; background: #fff;
            border-left: 1px solid var(--pos-line);
            display: flex; flex-direction: column;
        }

        /* ---- Department + category tabs ----------------------------- */
        .dept-tabs { display: flex; gap: 6px; overflow-x: auto; padding-bottom: 8px; flex-shrink: 0; }
        .dept-tab {
            white-space: nowrap; border: 1px solid var(--pos-line);
            background: #fff; color: var(--pos-muted);
            border-radius: 8px; padding: 9px 16px;
            font-size: .8125rem; font-weight: 500; cursor: pointer;
            transition: background .12s, color .12s, border-color .12s;
        }
        .dept-tab:hover { border-color: #cfdae1; color: var(--pos-text); }
        .dept-tab.active { background: var(--pos-dark); border-color: var(--pos-dark); color: #fff; }

        .cat-tabs { display: flex; gap: 6px; overflow-x: auto; padding-bottom: 8px; flex-shrink: 0; }
        .cat-tabs::-webkit-scrollbar, .dept-tabs::-webkit-scrollbar { height: 5px; }
        .cat-tabs::-webkit-scrollbar-thumb, .dept-tabs::-webkit-scrollbar-thumb { background: #cfdae1; border-radius: 3px; }
        .cat-tab {
            white-space: nowrap; border: 1px solid var(--pos-line);
            background: #fff; color: var(--pos-muted);
            border-radius: 999px; padding: 6px 14px;
            font-size: .78125rem; font-weight: 500; cursor: pointer;
            transition: background .12s, color .12s, border-color .12s;
        }
        .cat-tab:hover { color: var(--pos-text); }
        .cat-tab.active { background: #e6f6f8; border-color: #b8e4e9; color: #0a6d77; font-weight: 600; }
        .cat-tab.hidden-cat { display: none; }

        /* ---- Product grid -------------------------------------------- */
        .grid-scroll { flex: 1; overflow-y: auto; margin-top: 6px; }
        .grid-scroll::-webkit-scrollbar { width: 8px; }
        .grid-scroll::-webkit-scrollbar-thumb { background: #cfdae1; border-radius: 4px; }
        .prod-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(158px, 1fr)); gap: 10px; padding-bottom: 10px; }
        .prod-card {
            background: #fff; border-radius: 10px;
            border: 1px solid var(--pos-line);
            box-shadow: 0 1px 2px rgba(16,36,43,.05);
            cursor: pointer; overflow: hidden;
            transition: border-color .12s, box-shadow .12s, transform .08s;
            user-select: none;
        }
        .prod-card:hover { border-color: var(--pos-primary); box-shadow: 0 2px 8px rgba(16,36,43,.1); }
        .prod-card:active { transform: scale(.98); }
        .prod-card.oos { opacity: .5; cursor: not-allowed; }
        .prod-card.oos:hover { border-color: var(--pos-line); box-shadow: none; }
        .prod-thumb {
            height: 84px; background: #f7fafb center/cover no-repeat;
            display: flex; align-items: center; justify-content: center;
            color: #c3d0d6; font-size: 1.6rem; position: relative;
            border-bottom: 1px solid var(--pos-line);
        }
        .stock-chip {
            position: absolute; top: 6px; right: 6px;
            font-size: .625rem; font-weight: 600;
            padding: 2px 7px; border-radius: 999px;
            font-variant-numeric: tabular-nums;
        }
        .prod-info { padding: 8px 10px 10px; }
        .prod-name { font-size: .8125rem; font-weight: 500; line-height: 1.3; height: 2.6em; overflow: hidden; }
        .prod-card.expired { opacity: .45; cursor: not-allowed; }
        .prod-card.expired:hover { border-color: var(--pos-line); box-shadow: none; }
        .prod-card.expired .prod-thumb { filter: grayscale(1); }
        .expired-chip {
            position: absolute; top: 6px; left: 6px;
            background: #9b1c1c; color: #fff; font-weight: 700;
            font-size: .62rem; letter-spacing: .06em;
            padding: 3px 7px; border-radius: 7px;
        }
        .exp-chip {
            position: absolute; top: 6px; left: 6px;
            background: #e0483b; color: #fff; font-weight: 700;
            font-size: .68rem; padding: 2px 7px; border-radius: 7px;
        }
        .prod-was { color: #9fb0b8; font-weight: 400; font-size: .74rem; margin-left: 5px; }
        .prod-price {
            color: var(--pos-text); font-weight: 600; font-size: .875rem; margin-top: 5px;
            font-variant-numeric: tabular-nums;
        }

        /* ---- Cart ----------------------------------------------------- */
        .cart-head {
            padding: 12px 14px; border-bottom: 1px solid var(--pos-line);
            display: flex; align-items: center; justify-content: space-between; gap: 8px;
            flex-shrink: 0;
        }
        .cart-head h6 { margin: 0; font-weight: 600; font-size: .9375rem; }
        .cart-lines { flex: 1; overflow-y: auto; padding: 4px 12px; }
        .cart-lines::-webkit-scrollbar { width: 8px; }
        .cart-lines::-webkit-scrollbar-thumb { background: #cfdae1; border-radius: 4px; }
        .cart-empty { text-align: center; color: #8b9ba5; padding: 56px 20px; font-size: .8125rem; }
        .cart-empty i { font-size: 2rem; display: block; margin-bottom: 12px; opacity: .5; }

        .cart-line { border-bottom: 1px solid #f1f6f8; padding: 10px 2px; }
        .cart-line:last-child { border-bottom: none; }
        .cl-top { display: flex; justify-content: space-between; gap: 10px; }
        .cl-name { font-size: .8125rem; font-weight: 500; line-height: 1.35; }
        .cl-unit { font-size: .6875rem; color: var(--pos-muted); margin-top: 1px; }
        .cl-total { font-weight: 600; font-size: .875rem; white-space: nowrap; font-variant-numeric: tabular-nums; }
        .cl-actions { display: flex; align-items: center; gap: 8px; margin-top: 8px; }
        .qty-btn {
            width: 40px; height: 40px; border-radius: 8px;
            border: 1px solid #cfdae1; background: #fff;
            font-weight: 600; color: var(--pos-primary-dark);
            font-size: 1rem; line-height: 1;
            transition: background .1s, color .1s;
        }
        .qty-btn:hover { background: #f7fafb; }
        .qty-btn:active { background: var(--pos-primary); border-color: var(--pos-primary); color: #fff; }
        .qty-val { min-width: 38px; text-align: center; font-weight: 600; font-size: .9375rem; font-variant-numeric: tabular-nums; }
        .rm-btn {
            margin-left: auto; border: none; background: none;
            color: #b6c3ca; width: 40px; height: 40px; border-radius: 8px;
            transition: color .1s, background .1s;
        }
        .rm-btn:hover { color: var(--pos-danger); background: #fdecec; }

        /* ---- Totals ---------------------------------------------------- */
        .totals { border-top: 1px solid var(--pos-line); padding: 12px 14px; background: #f7fafb; flex-shrink: 0; }
        .t-row { display: flex; justify-content: space-between; align-items: center; font-size: .8125rem; padding: 3px 0; color: var(--pos-muted); }
        .t-row span:last-child { color: var(--pos-text); font-variant-numeric: tabular-nums; }
        .t-row.grand {
            font-size: 1.25rem; font-weight: 600; color: var(--pos-text);
            border-top: 1px solid #dde5e9; margin-top: 8px; padding-top: 10px;
        }
        .disc-input {
            width: 110px; height: 32px; border: 1px solid #cfdae1;
            border-radius: 8px; padding: 2px 9px; text-align: right;
            font-size: .8125rem; font-variant-numeric: tabular-nums;
        }
        .disc-input:focus { outline: none; border-color: var(--pos-primary); box-shadow: 0 0 0 3px rgba(15,154,168,.15); }

        /* ---- Tender ---------------------------------------------------- */
        .tender { padding: 12px 14px; border-top: 1px solid var(--pos-line); flex-shrink: 0; }

        .pay-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; }
        .pay-head label { font-size: .8125rem; font-weight: 600; color: var(--pos-text); }
        .pay-split-toggle {
            border: 1px solid #cfdae1; background: #fff;
            border-radius: 8px; padding: 4px 10px;
            font-size: .6875rem; font-weight: 600; color: var(--pos-muted);
        }
        .pay-split-toggle:hover { background: #f7fafb; color: var(--pos-text); }
        .pay-split-toggle.active { background: var(--pos-primary); border-color: var(--pos-primary); color: #fff; }

        .pay-rows { display: flex; flex-direction: column; gap: 6px; }
        .pay-row { display: flex; align-items: center; gap: 8px; }
        .pay-label {
            display: flex; align-items: center; gap: 7px;
            width: 132px; flex-shrink: 0;
            font-size: .75rem; font-weight: 500; color: var(--pos-muted);
            margin: 0;
        }
        .pay-label i { width: 14px; text-align: center; color: var(--pos-primary); }
        .pay-input {
            flex: 1; min-width: 0; height: 38px;
            border: 1px solid #cfdae1; border-radius: 8px;
            padding: 2px 10px; text-align: right;
            font-size: .9375rem; font-weight: 600;
            font-variant-numeric: tabular-nums;
        }
        .pay-input:focus { outline: none; border-color: var(--pos-primary); box-shadow: 0 0 0 3px rgba(15,154,168,.18); }
        /* The row carrying money is highlighted so a split is obvious. */
        .pay-row.has-amount .pay-input { border-color: var(--pos-primary); background: #f2fbfc; }
        .pay-row.has-amount .pay-label { color: var(--pos-text); }

        .pay-summary { margin-top: 10px; padding-top: 8px; border-top: 1px dashed var(--pos-line); }
        .pay-summary .t-row { padding: 2px 0; }
        #balanceRow.settled span:last-child { color: var(--pos-success); }
        #balanceRow.short span:last-child { color: var(--pos-danger); font-weight: 600; }
        .tender-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 6px; margin-top: 8px; }
        .tender-btn {
            border: 1px solid #cfdae1; background: #fff;
            border-radius: 8px; padding: 14px 2px;
            font-size: .75rem; font-weight: 600; color: #41545e;
            font-variant-numeric: tabular-nums;
            transition: background .1s, border-color .1s, color .1s;
        }
        .tender-btn:hover { background: #f7fafb; }
        .tender-btn:active, .tender-btn.exact {
            background: var(--pos-primary); border-color: var(--pos-primary); color: #fff;
        }
        #paidInput {
            width: 100%; height: 46px;
            border: 2px solid #cfdae1; border-radius: 10px;
            padding: 4px 12px; font-size: 1.125rem; font-weight: 600;
            text-align: right; font-variant-numeric: tabular-nums;
        }
        #paidInput:focus { outline: none; border-color: var(--pos-primary); box-shadow: 0 0 0 3px rgba(15,154,168,.18); }
        .change-box {
            display: flex; justify-content: space-between; align-items: center;
            margin-top: 10px; background: #e7f6ed; border: 1px solid #c2e6d1;
            border-radius: 8px; padding: 9px 12px;
            font-weight: 600; font-size: .875rem; color: var(--pos-success);
            font-variant-numeric: tabular-nums;
        }
        .change-box.short { background: #fdecec; border-color: #f0c4c4; color: var(--pos-danger); }

        /* ---- Actions: checkout is the dominant control ----------------- */
        .actions { padding: 12px 14px 14px; display: grid; grid-template-columns: 1fr 1fr; gap: 8px; flex-shrink: 0; }
        .btn-pos {
            border: 1px solid transparent; border-radius: 8px;
            padding: 14px 8px; font-weight: 500; font-size: .8125rem;
            transition: background .12s, border-color .12s;
        }
        .btn-complete {
            grid-column: 1 / -1;
            background: var(--pos-success); border-color: var(--pos-success);
            color: #fff; padding: 16px; font-size: 1rem; font-weight: 600;
            border-radius: 10px;
        }
        .btn-complete:hover:not(:disabled) { background: #10693a; border-color: #10693a; }
        .btn-complete:disabled { background: #d3dde2; border-color: #d3dde2; color: #8b9ba5; }
        .btn-hold { background: #fff; border-color: #cfdae1; color: #41545e; }
        .btn-hold:hover { background: #f7fafb; }
        .btn-clear { background: #fff; border-color: #cfdae1; color: var(--pos-muted); }
        .btn-clear:hover { background: #fdecec; border-color: #f0c4c4; color: var(--pos-danger); }

        /* ---- Toast ------------------------------------------------------ */
        .toast-pos {
            position: fixed; top: 72px; left: 50%; transform: translateX(-50%);
            z-index: 3000; padding: 12px 20px; border-radius: 10px;
            color: #fff; font-weight: 500; font-size: .875rem;
            display: none; box-shadow: 0 12px 28px rgba(16,36,43,.22);
            max-width: 90vw; text-align: center;
        }
        .toast-pos.ok  { background: var(--pos-success); }
        .toast-pos.err { background: var(--pos-danger); }

        /* ---- Responsive -------------------------------------------------
           The till is a desktop/tablet surface. Below 900px the cart moves
           under the grid rather than shrinking into an unusable column. */
        @media (max-width: 1200px) { .pos-right { width: 360px; } }
        @media (max-width: 900px) {
            body { overflow: auto; }
            .pos-body { flex-direction: column; }
            .pos-right { width: 100%; border-left: none; border-top: 1px solid var(--pos-line); }
            .scan-wrap { max-width: none; order: 3; flex-basis: 100%; }
            .prod-grid { grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); }
        }
        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after { transition-duration: .01ms !important; }
        }

        /* ============================================================
           CUSTOMER DISPLAY
           ------------------------------------------------------------
           Built from the same teal/dark-blue-green palette as the rest
           of the product, using the POS page's own --pos-* variables.
           Prices use a monospaced face so digits line up and read like
           a hardware till, which is what customers expect.

           Sized for a customer standing a metre away: large type, high
           contrast, no interaction.
           ============================================================ */
        body.cust-mode { overflow: hidden; }
        body.cust-mode .pos-top,
        body.cust-mode .pos-wrap { display: none !important; }

        .cust-view {
            position: fixed; inset: 0; z-index: 5000;
            background: var(--pos-dark);
            color: #eaf3f6;
            font-family: 'Poppins', system-ui, sans-serif;
            display: flex;
        }

        /* ---- Idle ------------------------------------------------- */
        .cust-idle { display: flex; width: 100%; }
        .cust-idle-brand {
            flex: 1.15; display: flex; flex-direction: column;
            align-items: center; justify-content: center; text-align: center;
            padding: 4vh 4vw; gap: 10px;
            background: linear-gradient(150deg, #103642 0%, #0d2730 55%, #0a1e25 100%);
        }
        .cust-idle-logo {
            width: 96px; height: 96px; border-radius: 24px;
            background: rgba(255,255,255,.06);
            border: 1px solid rgba(255,255,255,.12);
            display: flex; align-items: center; justify-content: center;
            font-size: 2.4rem; color: var(--pos-primary); margin-bottom: 8px;
        }
        .cust-idle-logo img { max-width: 72px; max-height: 72px; object-fit: contain; }
        .cust-idle-name { font-size: clamp(1.6rem, 3vw, 2.6rem); font-weight: 600; letter-spacing: -.01em; }
        .cust-idle-sub  { font-size: clamp(.9rem, 1.2vw, 1.1rem); color: #9fc2cd; }
        .cust-idle-welcome {
            margin-top: 18px; font-size: clamp(1rem, 1.5vw, 1.35rem);
            color: var(--pos-primary); font-weight: 500;
        }
        .cust-idle-status {
            flex: .85; display: flex; flex-direction: column;
            align-items: center; justify-content: center; gap: 14px;
            padding: 4vh 4vw; background: #0a1e25; text-align: center;
        }
        .cust-status-dot {
            display: inline-flex; align-items: center; gap: 10px;
            font-size: 1rem; color: #bcd7de;
            border: 1px solid rgba(255,255,255,.14); border-radius: 999px;
            padding: 10px 20px; background: rgba(255,255,255,.04);
        }
        .cust-status-dot span {
            width: 10px; height: 10px; border-radius: 50%;
            background: #37d39f; box-shadow: 0 0 0 4px rgba(55,211,159,.18);
        }
        .cust-idle-hint { color: #7fa3b0; font-size: .95rem; max-width: 34ch; margin: 0; }

        /* ---- Idle offers slideshow -------------------------------- */
        /* One slide is visible at a time; the rest are stacked beneath
           it at opacity 0. Cross-fading rather than sliding keeps the
           animation cheap on the low-powered stick PCs these displays
           usually run on. */
        .cust-offer { width: 100%; display: flex; flex-direction: column; align-items: center; gap: 18px; }
        .cust-offer-tag {
            display: inline-flex; align-items: center; gap: 9px;
            background: linear-gradient(135deg, #f0b429, #e8873b);
            color: #2a1a04; font-weight: 600; font-size: clamp(.9rem, 1.3vw, 1.15rem);
            padding: 9px 20px; border-radius: 999px;
            box-shadow: 0 6px 22px rgba(240,180,41,.22);
        }
        .cust-offer-stage {
            position: relative; width: 100%; max-width: 460px;
            aspect-ratio: 1 / 1.12; max-height: 62vh;
        }
        .cust-offer-slide {
            position: absolute; inset: 0; margin: 0;
            display: flex; flex-direction: column; align-items: center; justify-content: flex-start;
            gap: 14px; opacity: 0; transform: scale(.97);
            transition: opacity .6s ease, transform .6s ease;
            pointer-events: none;
        }
        .cust-offer-slide.is-on { opacity: 1; transform: scale(1); }
        .cust-offer-img {
            position: relative; width: 100%; flex: 1;
            border-radius: 20px; overflow: hidden;
            background: rgba(255,255,255,.05);
            border: 1px solid rgba(255,255,255,.10);
            display: flex; align-items: center; justify-content: center;
            font-size: 3rem; color: #4a6d78;
        }
        .cust-offer-img img { width: 100%; height: 100%; object-fit: cover; }
        .cust-offer-badge {
            position: absolute; top: 12px; right: 12px;
            background: #e0483b; color: #fff; font-weight: 700;
            font-size: clamp(1rem, 1.8vw, 1.5rem);
            padding: 8px 14px; border-radius: 14px;
            box-shadow: 0 6px 18px rgba(224,72,59,.35);
        }
        .cust-offer-slide figcaption { text-align: center; width: 100%; }
        .cust-offer-name {
            font-size: clamp(1.05rem, 1.9vw, 1.5rem); font-weight: 600;
            line-height: 1.25; margin-bottom: 6px;
        }
        .cust-offer-price { display: flex; align-items: baseline; justify-content: center; gap: 12px; }
        .cust-offer-price .was {
            color: #7fa3b0; text-decoration: line-through;
            font-size: clamp(.9rem, 1.3vw, 1.15rem);
        }
        .cust-offer-price .now {
            color: #37d39f; font-weight: 700;
            font-size: clamp(1.4rem, 2.6vw, 2.1rem);
        }
        .cust-offer-meta { color: #9fc2cd; font-size: clamp(.78rem, 1.1vw, .95rem); margin-top: 6px; }
        .cust-offer-dots { display: flex; gap: 7px; flex-wrap: wrap; justify-content: center; max-width: 90%; }
        .cust-offer-dots span {
            width: 7px; height: 7px; border-radius: 50%;
            background: rgba(255,255,255,.22); transition: background .3s ease, transform .3s ease;
        }
        .cust-offer-dots span.is-on { background: var(--pos-primary); transform: scale(1.35); }

        @media (prefers-reduced-motion: reduce) {
            .cust-offer-slide { transition: none; }
        }

        /* ---- Active ----------------------------------------------- */
        .cust-active { display: flex; width: 100%; }

        .cust-items {
            flex: 1.25; display: flex; flex-direction: column;
            background: #0c242c; border-right: 1px solid rgba(255,255,255,.08);
            min-width: 0;
        }
        .cust-items-head {
            display: flex; align-items: center; justify-content: space-between;
            padding: 20px 28px; border-bottom: 1px solid rgba(255,255,255,.08);
            font-size: 1.05rem; font-weight: 600; color: #cfe3e9; flex: 0 0 auto;
        }
        .cust-count {
            background: var(--pos-primary); color: #06222a;
            border-radius: 999px; min-width: 32px; text-align: center;
            padding: 2px 10px; font-size: .9rem; font-weight: 700;
        }
        .cust-item-list {
            list-style: none; margin: 0; padding: 8px 0;
            overflow-y: auto; flex: 1 1 auto;
        }
        .cust-item-list li {
            display: flex; align-items: baseline; gap: 16px;
            padding: 14px 28px; border-bottom: 1px solid rgba(255,255,255,.05);
        }
        .cust-item-qty {
            font-family: ui-monospace, 'Courier New', monospace;
            font-size: 1.05rem; font-weight: 700; color: var(--pos-primary);
            min-width: 3.5ch;
        }
        .cust-item-name { flex: 1; font-size: 1.05rem; color: #e7f1f4; min-width: 0; }
        .cust-item-line {
            font-family: ui-monospace, 'Courier New', monospace;
            font-size: 1.05rem; font-weight: 600; white-space: nowrap;
        }
        .cust-item-each { display: block; font-size: .78rem; color: #7fa3b0; font-weight: 400; }

        .cust-money {
            flex: .95; display: flex; flex-direction: column;
            padding: 22px 28px; background: #0a1e25; min-width: 320px;
        }
        .cust-money-head {
            display: flex; align-items: center; gap: 10px;
            font-size: 1rem; font-weight: 600; color: #9fc2cd;
            padding-bottom: 16px; border-bottom: 1px solid rgba(255,255,255,.08);
        }
        .cust-money-logo { max-height: 26px; max-width: 70px; object-fit: contain; }

        /* Auto margin here rather than on .cust-total, so the whole money
           group - the running totals, the amount due and how to pay -
           centres as one block in the pane instead of the amount being
           centred only in the space the totals happen to leave. */
        .cust-lines { margin: auto 0 0; }
        .cust-lines > div {
            display: flex; justify-content: space-between; align-items: baseline;
            padding: 7px 0;
        }
        .cust-lines dt { color: #9fc2cd; font-size: 1rem; margin: 0; font-weight: 400; }
        .cust-lines dd {
            margin: 0; font-family: ui-monospace, 'Courier New', monospace;
            font-size: 1.05rem; font-weight: 600; color: #e7f1f4;
        }
        .cust-lines-pay { border-top: 1px solid rgba(255,255,255,.08); margin-top: 16px; }

        /* The amount and how to pay it are the two things a customer
           actually leans in to read, so they sit together in the middle
           of the pane rather than pinned to the bottom edge: auto margin
           above the total and below the payment block centres the pair
           as a group in whatever space the item list leaves. */
        .cust-total {
            margin-top: 18px; padding: 22px 20px; border-radius: 16px;
            background: linear-gradient(140deg, #0f8f86 0%, #0d7f8e 100%);
            box-shadow: 0 10px 30px rgba(0,0,0,.35);
            text-align: center;
        }
        .cust-total-label {
            font-size: .95rem; letter-spacing: .06em; text-transform: uppercase;
            color: rgba(255,255,255,.82); margin-bottom: 4px;
        }
        .cust-total-value {
            font-family: ui-monospace, 'Courier New', monospace;
            font-size: clamp(2rem, 4.4vw, 3.4rem); font-weight: 700;
            line-height: 1.05; color: #fff; word-break: break-all;
        }

        .cust-pay {
            margin-top: 18px; margin-bottom: auto;
            border-top: 1px solid rgba(255,255,255,.08); padding-top: 16px;
            text-align: center;
        }
        .cust-pay-head {
            font-size: .9rem; letter-spacing: .08em; text-transform: uppercase;
            color: #9fc2cd; margin-bottom: 12px;
        }
        .cust-pay-row { margin-bottom: 18px; line-height: 1.3; }
        .cust-pay-row:last-child { margin-bottom: 0; }
        .cust-pay-provider {
            display: block; font-size: clamp(.95rem, 1.5vw, 1.2rem); color: #cfe3e9;
        }
        /* The number is what the customer types into their phone, from
           a metre away and often at an angle. It is deliberately the
           largest thing on the pane after the amount itself: tabular
           figures so the digits line up, and letter-spacing so 1 and 7
           cannot be mistaken for each other. */
        .cust-pay-number {
            display: block; margin: 4px 0 2px;
            font-family: ui-monospace, 'Courier New', monospace;
            font-variant-numeric: tabular-nums;
            font-size: clamp(1.8rem, 3.6vw, 3rem); font-weight: 700;
            letter-spacing: .05em; line-height: 1.1;
            color: var(--pos-primary);
            text-shadow: 0 2px 12px rgba(66,195,207,.25);
            word-break: break-all;
        }
        .cust-pay-account {
            display: block; font-size: clamp(.9rem, 1.3vw, 1.05rem); color: #9fc2cd;
        }

        /* ---- Paid -------------------------------------------------- */
        .cust-done {
            width: 100%; display: flex; flex-direction: column;
            align-items: center; justify-content: center; gap: 12px; text-align: center;
            background: radial-gradient(circle at 50% 35%, #10564f 0%, #0a2b2e 55%, #081f25 100%);
        }
        .cust-done-mark {
            width: 108px; height: 108px; border-radius: 50%;
            background: #17a67d; color: #fff;
            display: flex; align-items: center; justify-content: center;
            font-size: 3rem; box-shadow: 0 0 0 12px rgba(23,166,125,.16);
            margin-bottom: 8px;
        }
        .cust-done-title { font-size: clamp(1.6rem, 3vw, 2.4rem); font-weight: 600; }
        .cust-done-sub   { font-size: clamp(1rem, 1.4vw, 1.2rem); color: #a9cfd0; }
        .cust-done-token {
            margin-top: 18px; padding: 12px 24px; border-radius: 12px;
            background: rgba(255,255,255,.07); border: 1px solid rgba(255,255,255,.14);
        }
        .cust-done-token-label {
            display: block; font-size: .75rem; letter-spacing: .08em;
            text-transform: uppercase; color: #8fb6bd; margin-bottom: 2px;
        }
        .cust-done-token-value {
            font-family: ui-monospace, 'Courier New', monospace;
            font-size: 1.4rem; font-weight: 700; color: #fff;
        }

        /* Narrow / portrait second screens stack rather than squash. */
        @media (max-width: 900px) {
            .cust-idle, .cust-active { flex-direction: column; }
            .cust-money { min-width: 0; }
            .cust-items { border-right: none; border-bottom: 1px solid rgba(255,255,255,.08); }
        }

    </style>
</head>
<body>
<div class="pos-shell">
    <!-- ============ Top bar: scanner input lives here ============ -->

<!-- ============================================================
     CUSTOMER DISPLAY  (second screen)
     ------------------------------------------------------------
     Rendered by this same page when the URL carries #customer, so
     there is no extra route, no extra endpoint and no server work.
     Hidden until then. It listens on the BroadcastChannel and only
     ever displays - it can neither read nor change the cart.
     ============================================================ -->
<div id="custView" class="cust-view" hidden aria-live="polite">

    <!-- Idle: nothing scanned yet -->
    <div class="cust-idle" id="custIdle">
        <div class="cust-idle-brand">
            <div class="cust-idle-logo">
                <?php $cLogo = shopLogoUrl($conn, '../'); if ($cLogo !== ''): ?>
                    <img src="<?php echo htmlspecialchars($cLogo); ?>" alt="">
                <?php else: ?>
                    <i class="fas fa-cash-register"></i>
                <?php endif; ?>
            </div>
            <div class="cust-idle-name"><?php echo htmlspecialchars(shopName($conn)); ?></div>
            <div class="cust-idle-sub"><?php echo htmlspecialchars(shopSetting($conn, 'shop_tagline', '')); ?></div>
            <div class="cust-idle-welcome">Karibu &mdash; welcome</div>
        </div>
        <div class="cust-idle-status">
            <?php if ($expiryOffers): ?>
            <!-- Today's mark-downs, one at a time. The prices here are the
                 same ones retailEffectivePrice() will charge at the till,
                 so the screen can never promise a discount the checkout
                 does not honour. -->
            <div class="cust-offer" id="custOffer" aria-live="off">
                <div class="cust-offer-tag">
                    <i class="fas fa-tags"></i>
                    <span><?php echo rtrim(rtrim(number_format($expiryPercent, 1, '.', ''), '0'), '.'); ?>% off &mdash; today's offers</span>
                </div>

                <div class="cust-offer-stage">
                    <?php foreach ($expiryOffers as $n => $o): ?>
                    <figure class="cust-offer-slide<?php echo $n === 0 ? ' is-on' : ''; ?>"
                            data-slide="<?php echo (int)$n; ?>">
                        <div class="cust-offer-img">
                            <?php if ($o['image'] !== ''): ?>
                                <img src="../<?php echo htmlspecialchars($o['image']); ?>" alt="" loading="lazy">
                            <?php else: ?>
                                <i class="fas fa-basket-shopping"></i>
                            <?php endif; ?>
                            <span class="cust-offer-badge">&minus;<?php echo (int)round($o['percent']); ?>%</span>
                        </div>
                        <figcaption>
                            <div class="cust-offer-name"><?php echo htmlspecialchars($o['name']); ?></div>
                            <div class="cust-offer-price">
                                <span class="was">Tsh <?php echo number_format($o['was']); ?></span>
                                <span class="now">Tsh <?php echo number_format($o['now']); ?></span>
                            </div>
                            <div class="cust-offer-meta">
                                <?php if ($o['days_left'] <= 0): ?>
                                    Best before today
                                <?php elseif ($o['days_left'] === 1): ?>
                                    Best before tomorrow
                                <?php else: ?>
                                    Best before <?php echo date('j M', strtotime($o['expiry'])); ?>
                                <?php endif; ?>
                                &middot; save Tsh <?php echo number_format($o['saving']); ?>
                            </div>
                        </figcaption>
                    </figure>
                    <?php endforeach; ?>
                </div>

                <div class="cust-offer-dots" id="custOfferDots">
                    <?php foreach ($expiryOffers as $n => $o): ?>
                        <span class="<?php echo $n === 0 ? 'is-on' : ''; ?>"></span>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php else: ?>
            <div class="cust-status-dot"><span></span>Till ready</div>
            <p class="cust-idle-hint">Your items will appear here as they are scanned.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Active: items on the left, money on the right -->
    <div class="cust-active" id="custActive" hidden>
        <div class="cust-items">
            <div class="cust-items-head">
                <span>Your items</span>
                <span id="custCount" class="cust-count">0</span>
            </div>
            <ul class="cust-item-list" id="custList"></ul>
        </div>

        <div class="cust-money">
            <div class="cust-money-head">
                <?php if ($cLogo !== ''): ?>
                    <img src="<?php echo htmlspecialchars($cLogo); ?>" alt="" class="cust-money-logo">
                <?php endif; ?>
                <span><?php echo htmlspecialchars(shopName($conn)); ?></span>
            </div>

            <dl class="cust-lines">
                <div><dt>Subtotal</dt><dd id="custSub">Tsh 0</dd></div>
                <div id="custDiscRow" hidden><dt>Discount</dt><dd id="custDisc">Tsh 0</dd></div>
                <div id="custTaxRow" hidden><dt>Tax</dt><dd id="custTax">Tsh 0</dd></div>
            </dl>

            <div class="cust-total">
                <div class="cust-total-label">Amount due</div>
                <div class="cust-total-value" id="custGrand">Tsh 0</div>
            </div>

            <dl class="cust-lines cust-lines-pay" id="custPayRows" hidden>
                <div><dt>Paid</dt><dd id="custPaid">Tsh 0</dd></div>
                <div><dt>Change</dt><dd id="custChange">Tsh 0</dd></div>
            </dl>

            <?php
            // Payment instructions, from Shop Settings. Only enabled and
            // configured methods, so a customer never sees a placeholder.
            $custPay = shopPaymentMethods($conn, true);
            if ($custPay): ?>
            <div class="cust-pay">
                <div class="cust-pay-head">How to pay</div>
                <?php foreach ($custPay as $pm): ?>
                <div class="cust-pay-row">
                    <span class="cust-pay-provider"><?php echo htmlspecialchars($pm['provider']); ?></span>
                    <span class="cust-pay-number"><?php echo htmlspecialchars($pm['payment_number']); ?></span>
                    <?php if (!empty($pm['account_name'])): ?>
                    <span class="cust-pay-account"><?php echo htmlspecialchars($pm['account_name']); ?></span>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Paid -->
    <div class="cust-done" id="custDone" hidden>
        <div class="cust-done-mark"><i class="fas fa-check"></i></div>
        <div class="cust-done-title">Payment received</div>
        <div class="cust-done-sub">Thank you for shopping with us</div>
        <div class="cust-done-token">
            <span class="cust-done-token-label">Receipt</span>
            <span class="cust-done-token-value" id="custToken">&mdash;</span>
        </div>
    </div>
</div>

    <div class="pos-top">
        <div class="brand">
            <?php $posLogo = shopLogoUrl($conn, '../'); if ($posLogo !== ''): ?>
                <img src="<?php echo htmlspecialchars($posLogo); ?>" alt="" class="pos-logo">
            <?php else: ?>
                <i class="fas fa-cash-register"></i>
            <?php endif; ?>
            <span><?php echo htmlspecialchars(shopName($conn)); ?></span>
        </div>

        <!-- Which till this is. Sticky per session. -->
        <form method="get" class="d-flex align-items-center gap-1">
            <select name="terminal" class="form-select form-select-sm terminal-select" onchange="this.form.submit()">
                <?php foreach ($terminals as $t): ?>
                <option value="<?php echo (int)$t['id']; ?>" <?php echo $terminalId === (int)$t['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($t['name'] . ' (' . $t['code'] . ')'); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </form>

        <div class="scan-wrap">
            <i class="fas fa-barcode"></i>
            <input type="text" id="scanInput" autocomplete="off" placeholder="Scan barcode or type to search…" autofocus>
        </div>
        <div class="till">
            <i class="fas fa-user me-1"></i><?php echo htmlspecialchars($cashierName); ?>
            &middot; Today: <strong><?php echo (int)$till['sales']; ?></strong> sales,
            <strong>Tsh <?php echo number_format((float)$till['takings']); ?></strong>
        </div>
        <div class="spacer"></div>
        <?php if ($heldSales): ?>
        <button class="btn btn-sm btn-warning" style="border-radius:8px;" data-bs-toggle="modal" data-bs-target="#heldModal">
            <i class="fas fa-pause me-1"></i><?php echo count($heldSales); ?> held
        </button>
        <?php endif; ?>
        <?php if (userCan('pos_sales')): ?>
        <!-- Sales & receipts.
             A cashier is granted pos_sales and pos-sales.php scopes the
             list to their own till, but the POS has no sidebar - so
             without this button the screen they land on after signing in
             had no route to their own sales, and reprinting a receipt
             meant typing a URL. -->
        <a class="btn btn-sm pos-topbtn pos-topbtn-labelled" href="<?php echo htmlspecialchars(adminUrl('pos-sales.php')); ?>"
           title="Today's sales and receipt reprints" aria-label="Sales and receipts">
            <!-- No Bootstrap d-* utilities here: the till's own CSS
                 hides .pos-topbtn span with !important below 1200px, so
                 they would never have applied. .pos-topbtn-labelled in
                 that media query controls this label instead. -->
            <i class="fas fa-receipt me-1"></i><span>Sales &amp; receipts</span>
        </a>
        <?php endif; ?>

        <!-- Customer display: opens the same page with #customer, which
             renders the customer-facing view. Pure front-end. -->
        <button type="button" class="btn btn-sm pos-topbtn" id="custScreenBtn"
                title="Open the customer display on a second screen"
                aria-label="Open the customer display on a second screen">
            <i class="fas fa-desktop me-1"></i><span class="d-none d-lg-inline">Customer screen</span>
        </button>

        <!-- Full screen. Hidden entirely when the browser has no
             Fullscreen API, rather than offering a control that does
             nothing. -->
        <button type="button" class="btn btn-sm pos-topbtn" id="fsBtn" hidden
                aria-pressed="false" title="Enter full screen" aria-label="Enter full screen">
            <i class="fas fa-expand me-1"></i><span class="d-none d-lg-inline" id="fsLabel">Full screen</span>
        </button>

        <?php
        // The POS has no sidebar, so this is the ONLY way off this page.
        //
        // "Exit POS" is offered only to a role that has somewhere else to
        // go: for a cashier roleHome() IS the till, so the link used to
        // point at the page they were already on - and, reached through
        // the clean URL /Home/pos/terminal, it resolved to
        // /Home/pos/pos.php and 404ed. A cashier was trapped, with no
        // sign-out anywhere on the screen.
        //
        // Sign out is always shown, for every role.
        $posRole  = (string)($_SESSION['role'] ?? '');
        $posHome  = roleHome($posRole);
        $posCanExit = ($posHome !== '' && $posHome !== 'pos.php');
        ?>
        <?php if ($posCanExit): ?>
        <a class="exit" href="<?php echo htmlspecialchars(adminUrl($posHome)); ?>">
            <i class="fas fa-arrow-left me-1"></i>Exit POS
        </a>
        <?php endif; ?>

        <a class="exit" href="<?php echo htmlspecialchars(appRootUrl()); ?>/logout.php"
           onclick="return confirm('Sign out of the till? Any items in the cart will be lost.');">
            <i class="fas fa-right-from-bracket me-1"></i>Sign out
        </a>
    </div>

    <div class="pos-body">
        <!-- ============ Left: product grid ============ -->
        <div class="pos-left">
            <!-- Department first, then categories within it. -->
            <div class="dept-tabs" id="deptTabs">
                <button class="dept-tab <?php echo $activeDepartment === '' ? 'active' : ''; ?>" data-dept="">
                    <i class="fas fa-store me-1"></i>All Departments
                </button>
                <?php foreach ($departments as $key => $d): ?>
                <button class="dept-tab <?php echo $activeDepartment === $key ? 'active' : ''; ?>" data-dept="<?php echo htmlspecialchars($key); ?>">
                    <i class="fas <?php echo htmlspecialchars($d['icon']); ?> me-1"></i><?php echo htmlspecialchars($d['label']); ?>
                </button>
                <?php endforeach; ?>
            </div>

            <div class="cat-tabs" id="catTabs">
                <button class="cat-tab active" data-cat="0" data-dept="">All Products</button>
                <?php foreach ($categories as $cat): ?>
                <button class="cat-tab" data-cat="<?php echo (int)$cat['id']; ?>"
                        data-dept="<?php echo htmlspecialchars($cat['department'] ?? ''); ?>">
                    <?php echo htmlspecialchars($cat['name']); ?>
                </button>
                <?php endforeach; ?>
            </div>
            <div class="grid-scroll">
                <div class="prod-grid" id="prodGrid">
                    <?php foreach ($products as $p):
                        $price = posUnitPrice($p);
                        // Marked down for expiry? The cashier is shown why
                        // the till price differs from the shelf label.
                        $exp   = retailExpiryDiscount($p);
                        $was   = (float)($p['selling_price'] ?? 0);
                        // Expired stock stays ON the grid, greyed and
                        // labelled, rather than vanishing: the cashier
                        // needs to see it is there so it can be pulled
                        // off the shelf and written off.
                        $expiredWhy = retailExpiryBlockReason($p);
                        $stock = (float)$p['current_stock'];
                        $img = $p['image'] ? '../' . ltrim($p['image'], '/') : '';
                    ?>
                    <div class="prod-card <?php echo $stock <= 0 ? 'oos' : ''; ?><?php echo $expiredWhy !== null ? ' expired' : ''; ?>"
                         role="button" tabindex="0"
                         <?php if ($expiredWhy !== null): ?>data-expired="<?php echo htmlspecialchars($expiredWhy); ?>"<?php endif; ?>
                         data-id="<?php echo (int)$p['id']; ?>"
                         data-name="<?php echo htmlspecialchars($p['name']); ?>"
                         data-price="<?php echo $price; ?>"
                         data-stock="<?php echo $stock; ?>"
                         data-cat="<?php echo (int)$p['category_id']; ?>"
                         data-dept="<?php echo htmlspecialchars($p['department'] ?? ''); ?>"
                         data-search="<?php echo htmlspecialchars(strtolower($p['name'] . ' ' . $p['sku'] . ' ' . $p['barcode'] . ' ' . ($p['brand'] ?? ''))); ?>">
                        <div class="prod-thumb" <?php echo $img ? 'style="background-image:url(\'' . htmlspecialchars($img) . '\')"' : ''; ?>>
                            <?php if (!$img): ?><i class="fas fa-box"></i><?php endif; ?>
                            <span class="stock-chip <?php echo $stock <= 0 ? 'bg-danger text-white' : ($stock <= 5 ? 'bg-warning text-dark' : 'bg-light text-muted'); ?>">
                                <?php echo $stock <= 0 ? 'Out' : invQty($stock); ?>
                            </span>
                            <?php if ($expiredWhy !== null): ?>
                            <span class="expired-chip">EXPIRED</span>
                            <?php elseif ($exp !== null): ?>
                            <span class="exp-chip" title="Best before <?php echo htmlspecialchars(date('j M Y', strtotime($exp['expiry']))); ?>">&minus;<?php echo (int)round($exp['percent']); ?>%</span>
                            <?php endif; ?>
                        </div>
                        <div class="prod-info">
                            <div class="prod-name"><?php echo htmlspecialchars($p['name']); ?></div>
                            <div class="prod-price">
                                Tsh <?php echo number_format($price); ?>
                                <?php if ($exp !== null && $was > $price): ?><s class="prod-was">Tsh <?php echo number_format($was); ?></s><?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div id="noResults" style="display:none;text-align:center;color:#9fb0b8;padding:50px;">
                    <i class="fas fa-search" style="font-size:2.4rem;display:block;margin-bottom:10px;"></i>
                    No products match your search.
                </div>
            </div>
        </div>

        <!-- ============ Right: cart ============ -->
        <div class="pos-right">
            <div class="cart-head">
                <h6><i class="fas fa-shopping-basket me-1"></i> Current Sale <span id="cartCount" class="text-muted" style="font-weight:400;font-size:.8rem;"></span></h6>
                <select id="custType" class="form-select form-select-sm" style="width:auto;border-radius:8px;">
                    <option value="cash">Walk-in (cash)</option>
                    <option value="registered">Registered customer</option>
                </select>
            </div>

            <div id="custFields" style="display:none;padding:8px 14px;border-bottom:1px solid #eef1f4;">
                <input type="text" id="custPhone" class="form-control form-control-sm mb-1" placeholder="Customer phone (identity)" style="border-radius:8px;">
                <input type="text" id="custName" class="form-control form-control-sm" placeholder="Customer name" style="border-radius:8px;">
            </div>

            <div class="cart-lines" id="cartLines">
                <div class="cart-empty" id="cartEmpty">
                    <i class="fas fa-barcode"></i>
                    Scan a barcode or tap a product to begin
                </div>
            </div>

            <div class="totals">
                <div class="t-row"><span>Subtotal</span><span id="tSubtotal">Tsh 0</span></div>
                <div class="t-row align-items-center">
                    <span>Discount</span>
                    <input type="number" min="0" step="1" value="0" id="discInput" class="disc-input">
                </div>
                <?php if ($taxRate > 0): ?>
                <div class="t-row"><span>Tax (<?php echo rtrim(rtrim(number_format($taxRate, 2), '0'), '.'); ?>%)</span><span id="tTax">Tsh 0</span></div>
                <?php endif; ?>
                <div class="t-row grand"><span>TOTAL</span><span id="tGrand">Tsh 0</span></div>
            </div>

            <!-- ============ Payment =============================
                 One amount box per method. A sale can be settled with
                 any single one, or split across several (cash + mobile,
                 mobile + bank, ...). Electronic amounts must be exact -
                 change only ever comes out of the cash drawer, which is
                 why the quick-tender buttons apply to Cash alone.
                 ================================================= -->
            <div class="tender">
                <div class="pay-head">
                    <label>Payment</label>
                    <button type="button" class="pay-split-toggle" id="splitToggle"
                            aria-expanded="false" title="Pay with more than one method">
                        <i class="fas fa-code-branch"></i> Split
                    </button>
                </div>

                <div class="pay-rows" id="payRows">
                    <?php foreach (posPaymentMethods() as $mKey => $mMeta):
                        // Card shares the bank account and is rarely used at
                        // this counter, so it stays behind the Split toggle.
                        $isPrimary = in_array($mKey, ['cash', 'lipa_namba'], true);
                    ?>
                    <div class="pay-row <?php echo $isPrimary ? '' : 'pay-extra'; ?>" data-method="<?php echo htmlspecialchars($mKey); ?>"
                         <?php echo $isPrimary ? '' : 'style="display:none;"'; ?>>
                        <label class="pay-label" for="pay_<?php echo htmlspecialchars($mKey); ?>">
                            <i class="fas <?php echo htmlspecialchars($mMeta['icon']); ?>"></i>
                            <span><?php echo htmlspecialchars($mMeta['label']); ?></span>
                        </label>
                        <input type="number" min="0" step="1" class="pay-input"
                               id="pay_<?php echo htmlspecialchars($mKey); ?>"
                               data-method="<?php echo htmlspecialchars($mKey); ?>"
                               placeholder="0" aria-label="<?php echo htmlspecialchars($mMeta['label']); ?> amount">
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- Quick cash tender. Kept as #paidInput's companion so
                     the existing keypad muscle memory still works. -->
                <div class="tender-grid" id="tenderGrid">
                    <button class="tender-btn exact" data-amt="exact">Exact</button>
                    <button class="tender-btn" data-amt="1000">1,000</button>
                    <button class="tender-btn" data-amt="2000">2,000</button>
                    <button class="tender-btn" data-amt="5000">5,000</button>
                    <button class="tender-btn" data-amt="10000">10,000</button>
                    <button class="tender-btn" data-amt="20000">20,000</button>
                    <button class="tender-btn" data-amt="50000">50,000</button>
                    <button class="tender-btn" data-amt="clear">Clear</button>
                </div>

                <div class="pay-summary">
                    <div class="t-row"><span>Tendered</span><span id="tenderedVal">Tsh 0</span></div>
                    <div class="t-row" id="balanceRow"><span>Balance due</span><span id="balanceVal">Tsh 0</span></div>
                </div>

                <div class="change-box" id="changeBox"><span>Change due</span><span id="changeVal">Tsh 0</span></div>
            </div>

            <div class="actions">
                <button class="btn-pos btn-hold" id="holdBtn"><i class="fas fa-pause me-1"></i>Hold Sale</button>
                <button class="btn-pos btn-clear" id="clearBtn"><i class="fas fa-trash me-1"></i>Clear Cart</button>
                <button class="btn-pos btn-complete" id="completeBtn" disabled>
                    <i class="fas fa-check-circle me-1"></i>Complete &amp; Print Receipt
                </button>
            </div>
        </div>
    </div>
</div>

<div class="toast-pos" id="posToast"></div>

<!-- Held sales modal -->
<div class="modal fade" id="heldModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content" style="border-radius:14px;">
            <div class="modal-header"><h5 class="modal-title"><i class="fas fa-pause me-2"></i>Held Sales</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <?php foreach ($heldSales as $h): ?>
                <div class="d-flex justify-content-between align-items-center border-bottom py-2">
                    <div>
                        <strong><?php echo htmlspecialchars($h['label']); ?></strong>
                        <div class="text-muted" style="font-size:.78rem;">
                            <?php echo (int)$h['item_count']; ?> item(s) &middot; Tsh <?php echo number_format((float)$h['total_estimate']); ?>
                            &middot; <?php echo htmlspecialchars($h['cashier_name'] ?? ''); ?>
                            &middot; <?php echo date('H:i', strtotime($h['created_at'])); ?>
                        </div>
                    </div>
                    <div class="d-flex gap-1">
                        <button class="btn btn-sm btn-success" style="border-radius:8px;"
                                onclick='resumeHeld(<?php echo (int)$h["id"]; ?>, <?php echo json_encode($h["cart_json"], JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
                            Resume
                        </button>
                        <form method="post" onsubmit="return confirm('Discard this held sale?');">
<?php echo csrfField(); ?>
                            <input type="hidden" name="action" value="delete_held">
                            <input type="hidden" name="held_id" value="<?php echo (int)$h['id']; ?>">
                            <button class="btn btn-sm btn-outline-danger" style="border-radius:8px;"><i class="fas fa-trash"></i></button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
</div>

<!-- Hold form (submitted by JS) -->
<form method="post" id="holdForm" style="display:none;">
<?php echo csrfField(); ?>
    <input type="hidden" name="action" value="hold">
    <input type="hidden" name="cart_json" id="holdCartJson">
    <input type="hidden" name="label" id="holdLabel">
    <input type="hidden" name="total" id="holdTotal">
</form>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// =====================================================================
// POS terminal logic
// =====================================================================

// ---------------------------------------------------------------
// Customer display - receiving half of the bus
// ---------------------------------------------------------------
// Runs in the window opened with #customer. It listens on the same
// BroadcastChannel and re-renders; it never posts, so it cannot
// affect the sale. Everything below exits early on the till itself.
(function () {
    const isCustomer = window.location.hash === '#customer';
    const view = document.getElementById('custView');

    // Adding #customer to an already-open tab is a same-document
    // navigation, so this script would not re-run. Reload once so the
    // customer view initialises cleanly. (The "Customer screen" button
    // opens a new window, which loads normally and skips this.)
    window.addEventListener('hashchange', function () {
        const wantCustomer = window.location.hash === '#customer';
        if (wantCustomer !== document.body.classList.contains('cust-mode')) {
            window.location.reload();
        }
    });

    if (!isCustomer || !view) { return; }

    // Take over the window: hide the till chrome, show the display.
    document.body.classList.add('cust-mode');
    view.hidden = false;
    document.title = 'Customer display';

    const paneIdle   = document.getElementById('custIdle');
    const paneActive = document.getElementById('custActive');
    const paneDone   = document.getElementById('custDone');
    const listEl     = document.getElementById('custList');
    const countEl    = document.getElementById('custCount');

    const money = n => 'Tsh ' + Math.round(Number(n) || 0).toLocaleString();
    const set = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v; };
    const show = (el, on) => { if (el) el.hidden = !on; };

    let doneTimer = null;

    // ---- Idle offers slideshow -------------------------------------
    // Runs ONLY while the idle screen is up. It is stopped the moment a
    // customer is being served: a rotating advert next to a live total
    // is a distraction at exactly the wrong moment, and the animation
    // would compete with the cart for a slow display's CPU.
    const offerBox = document.getElementById('custOffer');
    const slides   = offerBox ? Array.from(offerBox.querySelectorAll('.cust-offer-slide')) : [];
    const dots     = offerBox ? Array.from(offerBox.querySelectorAll('#custOfferDots span')) : [];
    const SLIDE_MS = 7000;
    // The mark-downs are worked out from today's date, so a display left
    // running overnight would advertise yesterday's list. Reload it, but
    // only while idle, so a sale is never interrupted.
    const REFRESH_MS = 30 * 60 * 1000;

    let slideAt = 0, slideTimer = null, idleSince = Date.now();

    function paintSlide() {
        slides.forEach((s, i) => s.classList.toggle('is-on', i === slideAt));
        dots.forEach((d, i) => d.classList.toggle('is-on', i === slideAt));
    }
    function startSlides() {
        if (slides.length < 1 || slideTimer) { return; }
        idleSince = Date.now();
        paintSlide();
        if (slides.length < 2) { return; }          // nothing to rotate
        slideTimer = setInterval(() => {
            // Long idle: pick up today's offers and any price change.
            if (Date.now() - idleSince > REFRESH_MS) { location.reload(); return; }
            slideAt = (slideAt + 1) % slides.length;
            paintSlide();
        }, SLIDE_MS);
    }
    function stopSlides() {
        if (slideTimer) { clearInterval(slideTimer); slideTimer = null; }
    }
    // A hidden tab should not burn a timer on an animation nobody sees.
    document.addEventListener('visibilitychange', () => {
        if (document.hidden) { stopSlides(); }
        else if (paneIdle && !paneIdle.hidden) { startSlides(); }
    });

    function render(d) {
        if (!d) { return; }

        if (d.status === 'paid') {
            show(paneIdle, false); show(paneActive, false); show(paneDone, true);
            stopSlides();
            set('custToken', d.receipt || '—');
            // Return to the welcome screen so the next customer does not
            // walk up to the previous customer's total.
            clearTimeout(doneTimer);
            doneTimer = setTimeout(() => {
                show(paneDone, false); show(paneIdle, true);
                startSlides();
            }, 12000);
            return;
        }

        clearTimeout(doneTimer);
        const items = Array.isArray(d.items) ? d.items : [];

        if (!items.length) {
            show(paneDone, false); show(paneActive, false); show(paneIdle, true);
            startSlides();
            return;
        }

        show(paneDone, false); show(paneIdle, false); show(paneActive, true);
        stopSlides();

        // Rebuilt with textContent only - an item name can never inject
        // markup into the customer-facing screen.
        listEl.textContent = '';
        let units = 0;
        items.forEach(it => {
            units += Number(it.qty) || 0;
            const li = document.createElement('li');

            const q = document.createElement('span');
            q.className = 'cust-item-qty';
            q.textContent = (Number(it.qty) || 0) + '×';

            const n = document.createElement('span');
            n.className = 'cust-item-name';
            n.textContent = it.name || '';

            const l = document.createElement('span');
            l.className = 'cust-item-line';
            l.textContent = money(it.line);
            const each = document.createElement('span');
            each.className = 'cust-item-each';
            each.textContent = money(it.price) + ' each';
            l.appendChild(each);

            li.append(q, n, l);
            listEl.appendChild(li);
        });
        // Newest line stays in view on a long sale.
        listEl.scrollTop = listEl.scrollHeight;

        countEl.textContent = units;
        set('custSub',   money(d.subtotal));
        set('custDisc',  '- ' + money(d.discount));
        set('custTax',   money(d.tax));
        set('custGrand', money(d.grand));
        set('custPaid',  money(d.tendered));
        set('custChange',money(d.change));

        show(document.getElementById('custDiscRow'), (Number(d.discount) || 0) > 0);
        show(document.getElementById('custTaxRow'),  (Number(d.tax) || 0) > 0);
        show(document.getElementById('custPayRows'), (Number(d.tendered) || 0) > 0);
    }

    if ('BroadcastChannel' in window) {
        const bus = new BroadcastChannel('pos_sales_bus');
        bus.onmessage = e => render(e.data);
    } else {
        set('custToken', 'This browser cannot receive till updates.');
    }

    // The display opens on the idle screen, so the offers start rotating
    // straight away rather than waiting for the first message from the
    // till - which may not arrive until the next customer.
    startSlides();
})();

const CSRF_TOKEN = <?php echo json_encode(csrfToken()); ?>;
const TAX_RATE = <?php echo json_encode($taxRate); ?>;
const TAX_INCLUSIVE = <?php echo posTaxInclusive($conn) ? 'true' : 'false'; ?>;
let cart = [];          // [{id, name, price, qty, stock}]
let resumedHeldId = 0;  // set when a held sale was resumed

const scanInput = document.getElementById('scanInput');

// ---------------------------------------------------------------
// Audio feedback - short WebAudio tones, no asset files needed.
// ---------------------------------------------------------------
let audioCtx = null;
function beep(ok) {
    try {
        audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)();
        const osc = audioCtx.createOscillator();
        const gain = audioCtx.createGain();
        osc.connect(gain); gain.connect(audioCtx.destination);
        if (ok) {
            osc.frequency.value = 1180;             // crisp high blip
            gain.gain.setValueAtTime(0.09, audioCtx.currentTime);
            osc.start();
            osc.stop(audioCtx.currentTime + 0.08);
        } else {
            osc.type = 'square';
            osc.frequency.value = 220;              // low buzz
            gain.gain.setValueAtTime(0.08, audioCtx.currentTime);
            osc.start();
            osc.stop(audioCtx.currentTime + 0.30);
        }
    } catch (e) { /* audio blocked - visual toast still shows */ }
}

function toast(msg, ok = true) {
    const t = document.getElementById('posToast');
    t.textContent = msg;
    t.className = 'toast-pos ' + (ok ? 'ok' : 'err');
    t.style.display = 'block';
    clearTimeout(t._t);
    t._t = setTimeout(() => { t.style.display = 'none'; }, ok ? 1600 : 3200);
}

function money(n) { return 'Tsh ' + Math.round(n).toLocaleString(); }
function focusScanner() { scanInput.focus(); scanInput.select(); }

// ---------------------------------------------------------------
// Cart operations
// ---------------------------------------------------------------
function addToCart(id, name, price, stock, qty = 1) {
    id = parseInt(id); price = parseFloat(price); stock = parseFloat(stock);
    if (stock <= 0) { beep(false); toast(name + ' is out of stock.', false); return false; }
    const line = cart.find(l => l.id === id);
    const newQty = (line ? line.qty : 0) + qty;
    if (newQty > stock) {
        beep(false);
        toast('Only ' + stock + ' of ' + name + ' in stock.', false);
        return false;
    }
    if (line) { line.qty = newQty; }
    else { cart.push({ id, name, price, qty, stock }); }
    renderCart();
    return true;
}

function setQty(id, qty) {
    const line = cart.find(l => l.id === id);
    if (!line) return;
    if (qty <= 0) { cart = cart.filter(l => l.id !== id); }
    else if (qty > line.stock) { beep(false); toast('Only ' + line.stock + ' in stock.', false); return; }
    else { line.qty = qty; }
    renderCart();
}

function clearCart(silent) {
    cart = [];
    resumedHeldId = 0;
    document.getElementById('discInput').value = 0;
    clearPayments();
    renderCart();
    if (!silent) toast('Cart cleared.');
    focusScanner();
}

/* ---------------------------------------------------------------
   PAYMENT
   ---------------------------------------------------------------
   One input per method. A sale may be settled with any single one
   or split across several. Change comes only from cash, so the
   electronic methods must not exceed what is owed.
   --------------------------------------------------------------- */
function payInputs() { return Array.from(document.querySelectorAll('.pay-input')); }

function clearPayments() {
    payInputs().forEach(i => { i.value = ''; i.closest('.pay-row').classList.remove('has-amount'); });
    updateChange();
}

/** [{method, amount}] for every box with a positive amount. */
function collectPayments() {
    return payInputs()
        .map(i => ({ method: i.dataset.method, amount: parseFloat(i.value) || 0 }))
        .filter(p => p.amount > 0);
}

function paymentTotals() {
    const t = totals();
    let cash = 0, electronic = 0;
    collectPayments().forEach(p => {
        if (p.method === 'cash') { cash += p.amount; } else { electronic += p.amount; }
    });
    const tendered = cash + electronic;
    return {
        grand: t.grand,
        cash: cash,
        electronic: electronic,
        tendered: tendered,
        balance: Math.max(0, t.grand - tendered),
        change: Math.max(0, tendered - t.grand),
        // Electronic money cannot produce change, so an overpayment on
        // those methods is an error rather than change owed.
        overElectronic: electronic - t.grand > 0.005
    };
}

function totals() {
    const subtotal = cart.reduce((s, l) => s + l.price * l.qty, 0);
    let discount = parseFloat(document.getElementById('discInput').value) || 0;
    if (discount > subtotal) discount = subtotal;
    const net = subtotal - discount;
    let tax = 0, grand = net;
    if (TAX_RATE > 0) {
        if (TAX_INCLUSIVE) { tax = net - (net / (1 + TAX_RATE / 100)); grand = net; }
        else { tax = net * TAX_RATE / 100; grand = net + tax; }
    }
    return { subtotal, discount, tax, grand };
}

function renderCart() {
    const wrap = document.getElementById('cartLines');
    const t = totals();

    if (!cart.length) {
        wrap.innerHTML = '<div class="cart-empty"><i class="fas fa-barcode"></i>Scan a barcode or tap a product to begin</div>';
    } else {
        wrap.innerHTML = '';
        cart.forEach(l => {
            const div = document.createElement('div');
            div.className = 'cart-line';
            div.innerHTML =
                '<div class="cl-top"><div><div class="cl-name"></div>' +
                '<div class="cl-unit">' + money(l.price) + ' each</div></div>' +
                '<div class="cl-total">' + money(l.price * l.qty) + '</div></div>' +
                '<div class="cl-actions">' +
                '<button type="button" class="qty-btn" data-act="minus" aria-label="Decrease quantity">&minus;</button>' +
                '<span class="qty-val">' + l.qty + '</span>' +
                '<button type="button" class="qty-btn" data-act="plus" aria-label="Increase quantity">+</button>' +
                '<button type="button" class="rm-btn" data-act="rm" aria-label="Remove item"><i class="fas fa-trash" aria-hidden="true"></i></button>' +
                '</div>';
            div.querySelector('.cl-name').textContent = l.name;
            div.querySelector('[data-act=minus]').onclick = () => setQty(l.id, l.qty - 1);
            div.querySelector('[data-act=plus]').onclick  = () => setQty(l.id, l.qty + 1);
            div.querySelector('[data-act=rm]').onclick    = () => setQty(l.id, 0);
            wrap.appendChild(div);
        });
    }

    document.getElementById('cartCount').textContent = cart.length ? '(' + cart.reduce((s, l) => s + l.qty, 0) + ' items)' : '';
    document.getElementById('tSubtotal').textContent = money(t.subtotal);
    const taxEl = document.getElementById('tTax');
    if (taxEl) taxEl.textContent = money(t.tax);
    document.getElementById('tGrand').textContent = money(t.grand);
    updateChange();
    document.getElementById('completeBtn').disabled = cart.length === 0;
}

function updateChange() {
    const p = paymentTotals();

    // Highlight the rows actually carrying money.
    payInputs().forEach(i => {
        i.closest('.pay-row').classList.toggle('has-amount', (parseFloat(i.value) || 0) > 0);
    });

    document.getElementById('tenderedVal').textContent = money(p.tendered);

    const balRow = document.getElementById('balanceRow');
    const balVal = document.getElementById('balanceVal');
    if (p.overElectronic) {
        balRow.className = 't-row short';
        balVal.textContent = 'Over by ' + money(p.electronic - p.grand);
    } else if (p.balance > 0.005) {
        balRow.className = 't-row short';
        balVal.textContent = money(p.balance);
    } else {
        balRow.className = 't-row settled';
        balVal.textContent = 'Settled';
    }

    const box = document.getElementById('changeBox');
    box.className = 'change-box' + (p.balance > 0.005 || p.overElectronic ? ' short' : '');
    document.getElementById('changeVal').textContent =
        p.overElectronic ? 'Electronic overpayment'
        : (p.balance > 0.005 ? 'Short ' + money(p.balance) : money(p.change));

    // Nothing can be completed until the sale is fully covered.
    const btn = document.getElementById('completeBtn');
    if (btn) {
        btn.disabled = cart.length === 0 || p.balance > 0.005 || p.overElectronic;
    }
}

// ---------------------------------------------------------------
// Product grid: click to add, filter by category/search
// ---------------------------------------------------------------
function activateProductCard(card) {
    if (card.dataset.expired) { beep(false); toast(card.dataset.expired, false); return; }
    if (card.classList.contains('oos')) { beep(false); toast('Out of stock.', false); return; }
    if (addToCart(card.dataset.id, card.dataset.name, card.dataset.price, card.dataset.stock)) beep(true);
    focusScanner();
}
document.querySelectorAll('.prod-card').forEach(card => {
    card.addEventListener('click', () => activateProductCard(card));
    // Keyboard equivalent for the same activation, since the card is a
    // div with role="button" rather than a native <button>.
    card.addEventListener('keydown', e => {
        if (e.key === 'Enter' || e.key === ' ' || e.key === 'Spacebar') {
            e.preventDefault();
            activateProductCard(card);
        }
    });
});

let activeCat = 0;
let activeDept = <?php echo json_encode($activeDepartment); ?>;

document.querySelectorAll('.cat-tab').forEach(tab => {
    tab.addEventListener('click', () => {
        document.querySelectorAll('.cat-tab').forEach(t => t.classList.remove('active'));
        tab.classList.add('active');
        activeCat = parseInt(tab.dataset.cat);
        applyFilter();
    });
});

// Switching department resets the category filter and hides the
// categories that belong to the other department, so the cashier
// never sees an empty "Pens & Pencils" tab at the food counter.
document.querySelectorAll('.dept-tab').forEach(tab => {
    tab.addEventListener('click', () => {
        document.querySelectorAll('.dept-tab').forEach(t => t.classList.remove('active'));
        tab.classList.add('active');
        activeDept = tab.dataset.dept || '';
        activeCat = 0;
        document.querySelectorAll('.cat-tab').forEach(t => {
            t.classList.toggle('active', t.dataset.cat === '0');
            const belongs = activeDept === '' || t.dataset.dept === activeDept || t.dataset.cat === '0';
            t.classList.toggle('hidden-cat', !belongs);
        });
        applyFilter();
        focusScanner();
    });
});

function applyFilter() {
    const q = scanInput.value.trim().toLowerCase();
    let visible = 0;
    document.querySelectorAll('.prod-card').forEach(card => {
        const deptOk = activeDept === '' || card.dataset.dept === activeDept;
        const catOk = activeCat === 0 || parseInt(card.dataset.cat) === activeCat;
        const qOk = q === '' || card.dataset.search.includes(q);
        const show = deptOk && catOk && qOk;
        card.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    document.getElementById('noResults').style.display = visible === 0 ? 'block' : 'none';
}

// Apply the terminal's own department on first paint.
document.querySelectorAll('.cat-tab').forEach(t => {
    const belongs = activeDept === '' || t.dataset.dept === activeDept || t.dataset.cat === '0';
    t.classList.toggle('hidden-cat', !belongs);
});
applyFilter();

// ---------------------------------------------------------------
// Barcode scanning
//
// Hardware scanners type very fast and end with Enter. Two paths:
//  1. Focus is in the scan box  -> Enter submits whatever is there.
//  2. Focus is elsewhere        -> a global listener detects the burst
//     of fast keystrokes, buffers it, and handles Enter itself. Typing
//     slowly (a human) never triggers this.
// ---------------------------------------------------------------
const SCAN_MAX_GAP_MS = 45;   // scanners send chars far faster than this
let scanBuffer = '';
let lastKeyTime = 0;

document.addEventListener('keydown', (e) => {
    const el = document.activeElement;
    const inEditable = el && (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' || el.tagName === 'SELECT');

    // F2 = jump back to the scanner box from anywhere.
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

    // Buffer fast keystrokes arriving while focus is elsewhere.
    if (e.key.length === 1) {
        if (now - lastKeyTime > SCAN_MAX_GAP_MS) scanBuffer = '';
        scanBuffer += e.key;
        lastKeyTime = now;
    }
});

// Live search as the operator types in the scan box.
scanInput.addEventListener('input', applyFilter);

// Look a scanned code up on the server, then add it to the cart.
function handleScan(code) {
    // Fast path: the product is already on this page.
    const local = Array.from(document.querySelectorAll('.prod-card'))
        .find(c => c.dataset.search.split(' ').includes(code.toLowerCase()));
    if (local) {
        // The fast path never reaches the server, so the block is repeated
        // here. posCheckout() remains the gate that actually decides.
        if (local.dataset.expired) { beep(false); toast(local.dataset.expired, false); focusScanner(); return; }
        if (addToCart(local.dataset.id, local.dataset.name, local.dataset.price, local.dataset.stock)) {
            beep(true); toast('Added ' + local.dataset.name);
        }
        focusScanner();
        return;
    }

    fetch('api/products-scan.php?barcode=' + encodeURIComponent(code), { credentials: 'same-origin' })
        .then(r => r.json())
        .then(d => {
            if (!d.ok || !d.found) { beep(false); toast(d.message || 'Unknown barcode.', false); return; }
            const p = d.product;
            // d.message explains WHY (department off, not set up for
            // sale, out of stock) rather than a generic refusal.
            if (!p.sellable) { beep(false); toast(p.name + ': ' + (d.message || 'not available for sale.'), false); return; }
            if (addToCart(p.id, p.name, p.price, p.stock)) { beep(true); toast('Added ' + p.name); }
        })
        .catch(() => { beep(false); toast('Scan lookup failed - check your connection.', false); })
        .finally(focusScanner);
}

// ---------------------------------------------------------------
// Tender buttons & totals inputs
// ---------------------------------------------------------------
// Quick-tender buttons apply to CASH only - the other methods are
// exact-amount transfers and cannot produce change.
document.querySelectorAll('.tender-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        const amt = btn.dataset.amt;
        const cashEl = document.getElementById('pay_cash');
        if (!cashEl) { return; }

        if (amt === 'exact') {
            // "Exact" means the cash needed to settle whatever the other
            // methods have not already covered.
            const p = paymentTotals();
            const stillOwed = Math.max(0, p.grand - p.electronic);
            cashEl.value = Math.round(stillOwed);
        } else if (amt === 'clear') {
            cashEl.value = '';
        } else {
            cashEl.value = (parseFloat(cashEl.value) || 0) + parseFloat(amt);
        }
        updateChange();
    });
});

document.getElementById('discInput').addEventListener('input', renderCart);
payInputs().forEach(i => i.addEventListener('input', updateChange));

// Split toggle reveals the less-used methods (bank, card).
document.getElementById('splitToggle').addEventListener('click', function () {
    const on = this.classList.toggle('active');
    this.setAttribute('aria-expanded', on ? 'true' : 'false');
    document.querySelectorAll('.pay-row.pay-extra').forEach(row => {
        row.style.display = on ? '' : 'none';
        // Hiding a row must not leave money stranded in it.
        if (!on) {
            const input = row.querySelector('.pay-input');
            if (input) { input.value = ''; }
        }
    });
    updateChange();
    focusScanner();
});

document.getElementById('custType').addEventListener('change', function() {
    document.getElementById('custFields').style.display = this.value === 'registered' ? 'block' : 'none';
});

// ---------------------------------------------------------------
// Hold / clear / complete
// ---------------------------------------------------------------
document.getElementById('clearBtn').addEventListener('click', () => {
    if (!cart.length) return;
    if (confirm('Clear the whole cart?')) clearCart();
});

document.getElementById('holdBtn').addEventListener('click', () => {
    if (!cart.length) { toast('Nothing to hold.', false); return; }
    const label = prompt('Label for this held sale (e.g. customer name):', 'Held ' + new Date().toTimeString().slice(0, 5));
    if (label === null) return;
    document.getElementById('holdCartJson').value = JSON.stringify(cart);
    document.getElementById('holdLabel').value = label;
    document.getElementById('holdTotal').value = totals().grand;
    document.getElementById('holdForm').submit();
});

function resumeHeld(id, json) {
    try { cart = JSON.parse(json) || []; } catch (e) { cart = []; }
    resumedHeldId = id;
    renderCart();
    bootstrap.Modal.getInstance(document.getElementById('heldModal'))?.hide();
    toast('Held sale resumed.');
    focusScanner();
}

let checkingOut = false;
document.getElementById('completeBtn').addEventListener('click', () => {
    if (!cart.length || checkingOut) return;
    const t = totals();
    const p = paymentTotals();
    const payments = collectPayments();

    if (!payments.length) {
        beep(false);
        toast('Enter how the customer is paying.', false);
        return;
    }
    if (p.overElectronic) {
        beep(false);
        toast('Electronic payment is more than the total - change can only be given in cash.', false);
        return;
    }
    if (p.balance > 0.005) {
        beep(false);
        toast('Short by ' + money(p.balance) + '.', false);
        return;
    }

    checkingOut = true;
    const btn = document.getElementById('completeBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Processing…';

    fetch('api/pos-checkout.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
        body: JSON.stringify({
            items: cart.map(l => ({ item_id: l.id, quantity: l.qty })),
            discount: t.discount,
            // One entry per method used. The server re-validates the
            // amounts and recomputes the total from the database.
            payments: payments,
            terminal_id: <?php echo (int)$terminalId; ?>,
            held_id: resumedHeldId,
            customer: {
                type: document.getElementById('custType').value,
                name: document.getElementById('custName').value,
                phone: document.getElementById('custPhone').value
            }
        })
    })
    .then(r => r.json())
    .then(d => {
        if (!d.ok) { beep(false); toast(d.message || 'Checkout failed.', false); return; }
        beep(true);
        const change = d.receipt.change_due;
        toast('Sale ' + d.receipt_no + ' complete.' + (change > 0 ? ' Change: ' + money(change) : ''));
        // Tell the customer display the sale is paid, with its receipt
        // number as the token. Display only - nothing is recomputed.
        if (POS_BUS) {
            POS_BUS.postMessage({ type: 'cart', status: 'paid', receipt: d.receipt_no,
                                  items: [], subtotal: 0, discount: 0, tax: 0, grand: 0,
                                  tendered: 0, change: change, at: Date.now() });
        }
        // Open the printable receipt, then reset the till for the next customer.
        window.open(d.print_url, '_blank', 'width=380,height=650');
        clearCart(true);
        document.getElementById('custType').value = 'cash';
        document.getElementById('custFields').style.display = 'none';
        document.getElementById('custName').value = '';
        document.getElementById('custPhone').value = '';
        // Refresh stock badges after a short pause so the next sale sees
        // accurate numbers.
        setTimeout(() => location.reload(), 1500);
    })
    .catch(() => { beep(false); toast('Network error - the sale was NOT completed.', false); })
    .finally(() => {
        checkingOut = false;
        btn.disabled = cart.length === 0;
        btn.innerHTML = '<i class="fas fa-check-circle me-1"></i>Complete &amp; Print Receipt';
        focusScanner();
    });
});

// Keep the scanner focused: on load, on click-away, and after every sale.
window.addEventListener('load', focusScanner);
document.addEventListener('click', (e) => {
    const el = e.target;
    if (el.closest('input, select, textarea, button, a, .modal')) return;
    focusScanner();
});
setInterval(() => {
    if (document.activeElement === document.body) focusScanner();
}, 2000);

renderCart();

// ---------------------------------------------------------------
// Full screen (kiosk use)
// ---------------------------------------------------------------
// Uses the real Fullscreen API, not a CSS imitation, so the browser
// chrome genuinely goes away.
//
// Deliberately does NOT touch F11: that is the browser's own shortcut
// and cashiers may already use it. It also stays clear of F2 and of
// the global scan listener - this only binds a click on its own
// button plus the browser's own fullscreenchange event.
const fsBtn = document.getElementById('fsBtn');
const fsSupported = !!(document.fullscreenEnabled || document.webkitFullscreenEnabled);

if (fsBtn && fsSupported) {
    fsBtn.hidden = false;

    fsBtn.addEventListener('click', () => {
        const inFs = document.fullscreenElement || document.webkitFullscreenElement;
        if (!inFs) {
            const el = document.documentElement;
            const req = el.requestFullscreen || el.webkitRequestFullscreen;
            if (req) {
                // A rejected promise is normal (permissions policy, or
                // the gesture was not trusted) - report it rather than
                // leaving the button looking broken.
                Promise.resolve(req.call(el)).catch(() => {
                    toast('Full screen was blocked by the browser.', false);
                });
            }
        } else {
            const exit = document.exitFullscreen || document.webkitExitFullscreen;
            if (exit) { Promise.resolve(exit.call(document)).catch(() => {}); }
        }
        // Keep the scanner focused: leaving focus on the button would
        // send the next scan into it instead of the cart.
        focusScanner();
    });

    // Fires for Esc and for the browser's own controls too, so the
    // label can never disagree with the actual state.
    const syncFs = () => {
        const inFs = !!(document.fullscreenElement || document.webkitFullscreenElement);
        document.body.classList.toggle('pos-fullscreen', inFs);
        fsBtn.setAttribute('aria-pressed', inFs ? 'true' : 'false');
        fsBtn.title = inFs ? 'Exit full screen' : 'Enter full screen';
        fsBtn.setAttribute('aria-label', fsBtn.title);
        const icon = fsBtn.querySelector('i');
        if (icon) { icon.className = inFs ? 'fas fa-compress me-1' : 'fas fa-expand me-1'; }
        const label = document.getElementById('fsLabel');
        if (label) { label.textContent = inFs ? 'Exit full screen' : 'Full screen'; }
    };
    document.addEventListener('fullscreenchange', syncFs);
    document.addEventListener('webkitfullscreenchange', syncFs);
    syncFs();
} else if (fsBtn) {
    // Leave it hidden, and say why if anyone goes looking.
    fsBtn.hidden = true;
    console.info('POS: this browser does not support the Fullscreen API, so the full-screen control is hidden.');
}

// ---------------------------------------------------------------
// Customer display (second screen)
// ---------------------------------------------------------------
// Pure front-end, over BroadcastChannel. Nothing is sent to the
// server, no new endpoint, no polling: the till broadcasts its cart
// state and any window on the same origin listening to the channel
// re-renders. The customer view is this same page loaded with the
// #customer hash (see the block near the top of the script).
const POS_BUS = ('BroadcastChannel' in window) ? new BroadcastChannel('pos_sales_bus') : null;

const custBtn = document.getElementById('custScreenBtn');
if (custBtn) {
    if (!POS_BUS) {
        custBtn.hidden = true;
        console.info('POS: BroadcastChannel is unavailable, so the customer display is hidden.');
    } else {
        custBtn.addEventListener('click', () => {
            const url = window.location.href.split('#')[0] + '#customer';
            window.open(url, 'miraCustomerDisplay',
                        'width=1280,height=800,menubar=no,toolbar=no,location=no,status=no');
            // A window opening on a second monitor starts empty, so
            // send the current state straight away.
            setTimeout(broadcastCart, 400);
            focusScanner();
        });
    }
}

/**
 * Publish the current sale to the customer display.
 *
 * Read-only: this reports what the till already computed. It never
 * feeds back into the cart, the totals or the checkout, so the
 * customer screen cannot affect what is charged.
 */
function broadcastCart(status) {
    if (!POS_BUS) { return; }
    const t = totals();
    const p = paymentTotals();
    POS_BUS.postMessage({
        type: 'cart',
        status: status || 'active',
        items: cart.map(l => ({ name: l.name, qty: l.qty, price: l.price, line: l.price * l.qty })),
        subtotal: t.subtotal,
        discount: t.discount,
        tax: t.tax,
        grand: t.grand,
        tendered: p ? p.tendered : 0,
        change: p ? Math.max(0, p.tendered - t.grand) : 0,
        at: Date.now()
    });
}

// Broadcast whenever the sale changes. renderCart() already runs on
// every add, quantity change, removal and discount edit, so wrapping
// it catches them all without touching the cart logic itself.
const _renderCart = renderCart;
renderCart = function () {
    _renderCart.apply(this, arguments);
    broadcastCart();
};

// Payment entry changes the amount tendered and the change due.
payInputs().forEach(i => i.addEventListener('input', () => broadcastCart()));

// Send the opening state.
broadcastCart();

</script>
</body>
</html>
