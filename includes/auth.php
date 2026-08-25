<?php
// ============================================================
// Central role-based access control for admin/ pages.
// ------------------------------------------------------------
// Usage (first statement after including this file):
//   require_once '../includes/auth.php';
//   requireRole(['admin', 'manager']);   // only these roles may view
//   requireModule('inventory');          // or gate by business module
//
// Five retail roles:
//   admin       - full unrestricted system control
//   manager     - operational management, reports, overrides
//   accountant  - the books: chart of accounts, journal, expenses,
//                 plus read-only sales figures to reconcile against
//   storekeeper - stock intake, purchase orders, reorder alerts
//   cashier     - POS terminal checkout and cash drawer
// ============================================================

// Never session_start() directly - the cookie has to be configured
// before the session begins. See includes/session.php.
require_once __DIR__ . '/session.php';
appSessionStart();

// Every page in the system includes auth.php, so CSRF helpers are
// always available - csrfField(), csrfMeta() and csrfRequire() never
// need a separate include.
require_once __DIR__ . '/csrf.php';

/** Every role the system knows (used by manage-users and validation). */
function allSystemRoles(): array {
    return ['admin', 'manager', 'accountant', 'storekeeper', 'cashier'];
}

/** Human-readable role names for the UI. */
function roleLabel(string $role): string {
    $labels = [
        'admin'       => 'Administrator',
        'manager'     => 'Manager',
        'accountant'  => 'Accountant',
        'storekeeper' => 'Storekeeper',
        'cashier'     => 'Cashier',
    ];
    return $labels[$role] ?? ucfirst(str_replace('_', ' ', $role));
}

/**
 * Which business modules a role may use. Module keys:
 *   dashboard, manager_overview, pos, pos_sales, pos_void,
 *   products, inventory, purchasing, barcode, stock_requests,
 *   customers, accounting, accounting_manage, sales_reports, users,
 *   settings, departments, shop_settings, disposal_approve,
 *   expiry_alerts, supplier_liabilities, fraud_audit, terminals
 */
function roleModules(string $role): array {
    switch ($role) {
        case 'admin':
            // 'departments' is admin-only: switching one off takes its
            // whole product range off the till, which is a decision about
            // what the business sells, not day-to-day management.
            // 'disposal_approve' mirrors 'purchasing_approve': approving a
            // write-off authorizes a loss, it isn't operational work.
            // 'fraud_audit' (Cancelled Carts) is a supervisor key, held by
            // admin and manager - a cashier or storekeeper never sees it.
            // 'terminals' manages the physical tills, not what the shop
            // sells or its books, so - unlike 'departments' - it is shared
            // with manager rather than kept admin-only.
            // 'sales_reports' gates the shop-wide Sales report GROUP
            // (summary/by_cashier/by_terminal/transactions, etc.) - kept
            // separate from 'pos_sales' (the operational, own-till-only
            // sales list a cashier also needs) so granting one never
            // silently grants the other.
            // 'held_sales_review' is the manager/admin recovery screen for
            // orphaned or expired held sales - a cashier only ever sees
            // their own active ones (enforced in the query itself, not by
            // this key), so this is purely a supervisor surface, same
            // sharing rule as 'terminals'.
            return ['dashboard','manager_overview','pos','pos_sales','pos_void','products',
                    'inventory','purchasing','purchasing_approve','barcode','stock_requests','customers',
                    'accounting','accounting_manage','sales_reports','users','settings','departments',
                    'shop_settings','disposal_approve','expiry_alerts','supplier_liabilities','fraud_audit',
                    'terminals','held_sales_review'];
        case 'manager':
            // Everything operational plus overrides and reports, but the
            // chart of accounts itself stays an admin-only structure.
            return ['dashboard','manager_overview','pos','pos_sales','pos_void','products',
                    'inventory','purchasing','purchasing_approve','barcode','stock_requests','customers',
                    'accounting','sales_reports','users','settings','disposal_approve','expiry_alerts',
                    'supplier_liabilities','fraud_audit','terminals','held_sales_review'];
        case 'accountant':
            // The books, full stop - chart of accounts, journal, expenses,
            // P&L, daily close history - plus read-only sales figures to
            // reconcile revenue against what was posted. Deliberately no
            // 'purchasing_approve' (authorizing spend is an operational
            // decision, not a bookkeeping one), no POS/inventory/terminal
            // access, and no 'pos_sales' - they read sales through the
            // report group, not the till's own transaction list.
            return ['dashboard','accounting','accounting_manage','sales_reports'];
        case 'storekeeper':
            // 'purchasing' lets a storekeeper raise a purchase order and
            // book goods in when they arrive - their job. It deliberately
            // does NOT include 'purchasing_approve': approving an order
            // commits the shop to spending, and recording a payment moves
            // real money out of an account. Both are a supervisor's
            // decision, so only admin and manager hold that key.
            // 'supplier_liabilities' is granted read-only here (enforced
            // by the page itself, not by a separate module key) so a
            // storekeeper can see what's owed without being able to pay it.
            return ['products','inventory','purchasing','barcode','stock_requests','expiry_alerts',
                    'supplier_liabilities'];
        case 'cashier':
            // No 'customers': the till's own phone-lookup/autofill goes
            // through api/customer-lookup.php (gated on 'pos') and
            // posCheckout()'s own merge logic, never through the full
            // customer CRUD admin page - a cashier never needed that key
            // for checkout, it just hadn't been separated out before.
            return ['pos','pos_sales','stock_requests','expiry_alerts'];
        default:
            return [];
    }
}

/** True when the logged-in user's role includes the given module. */
function userCan(string $module): bool {
    $role = $_SESSION['role'] ?? '';
    return in_array($module, roleModules($role), true);
}

/**
 * The landing page for a role - "their" home screen.
 * These are the targets behind the clean URLs in .htaccess:
 *   /admin/dashboard   -> admin/index.php
 *   /manager/overview  -> admin/manager-overview.php
 *   /inventory         -> admin/inventory-dashboard.php
 *   /pos/terminal      -> admin/pos.php
 */
function roleHome(string $role): string {
    switch ($role) {
        case 'manager':     return 'manager-overview.php';
        case 'accountant':  return 'accounting-dashboard.php';
        case 'storekeeper': return 'inventory-dashboard.php';
        case 'cashier':     return 'pos.php';
        case 'admin':       return 'index.php';
        default:            return 'index.php';
    }
}

/**
 * Absolute clean URL a role should be sent to straight after login.
 * Accountant has no clean-URL alias of its own yet (the .htaccess
 * rewrites are hand-listed per role) - it goes straight to the real
 * admin/ path, the same way every role reached its page before clean
 * URLs existed for it.
 */
function roleHomeUrl(string $role): string {
    switch ($role) {
        case 'manager':     return 'manager/overview';
        case 'accountant':  return 'admin/accounting-dashboard.php';
        case 'storekeeper': return 'inventory';
        case 'cashier':     return 'pos/terminal';
        case 'admin':       return 'admin/dashboard';
        default:            return 'admin/dashboard';
    }
}

/**
 * URL of the admin directory, as the browser must see it.
 *
 * THE PROBLEM THIS SOLVES
 * The clean URLs in .htaccess are rewrites, not redirects: a cashier
 * signing in lands on /Home/pos/terminal while the file answering is
 * admin/pos.php. The browser then resolves every relative link on that
 * page against /Home/pos/ - a directory that does not exist - so
 * "pos-receipt.php" became /Home/pos/pos-receipt.php and 404ed. The
 * access log showed exactly that: a cashier's Exit POS and their
 * receipt both dead-ending on Page Not Found, with no way back.
 *
 * SCRIPT_NAME is the rewrite TARGET (/Home/admin/pos.php) whatever URL
 * the browser asked for, so it gives the real directory in every case.
 * Endpoints under admin/api/ have the /api segment stripped, since
 * their redirects go to pages one level up.
 */
function adminBaseUrl(): string {
    $dir = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '')));
    if (substr($dir, -4) === '/api') { $dir = substr($dir, 0, -4); }
    return ($dir === '/' || $dir === '.') ? '' : rtrim($dir, '/');
}

/** An absolute URL to a file in admin/, safe under the clean URLs. */
function adminUrl(string $file): string {
    return adminBaseUrl() . '/' . ltrim($file, '/');
}

/**
 * URL of the application root, one level above admin/.
 * logout.php and the assets folder live there.
 */
function appRootUrl(): string {
    $dir = str_replace('\\', '/', dirname(adminBaseUrl()));
    return ($dir === '/' || $dir === '.') ? '' : rtrim($dir, '/');
}

/**
 * True when the current request is a JSON endpoint rather than a page.
 * Endpoints live in admin/api/, so a redirect to an HTML login form
 * would be both wrong (the caller wants JSON) and broken (login.php is
 * one directory up). They get a 401 instead.
 */
function authIsApiRequest(): bool {
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    return strpos($script, '/api/') !== false;
}

/**
 * Send the caller somewhere sensible when access is denied, then stop.
 * $page is a filename inside admin/ - the path is adjusted so it still
 * resolves from admin/api/.
 */
function authDeny(string $page, string $message): void {
    if (authIsApiRequest()) {
        http_response_code($page === 'login.php' ? 401 : 403);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'message' => $message]);
        exit();
    }

    // Absolute, never relative: a page reached through a clean URL such
    // as /Home/pos/terminal would otherwise redirect to
    // /Home/pos/login.php, which does not exist.
    header('Location: ' . adminUrl($page));
    exit();
}

/**
 * Require the current session to belong to one of the given roles.
 * Not logged in at all -> login. Logged in but wrong role -> access-denied.
 */
function requireRole(array $allowedRoles) {
    if (!isset($_SESSION['username']) || !isset($_SESSION['role'])) {
        authDeny('login.php', 'You are not signed in.');
    }

    if (!in_array($_SESSION['role'], $allowedRoles, true)) {
        authDeny('access-denied.php', 'Your role does not have access to this.');
    }
}

/** Gate a page by business module instead of a hardcoded role list. */
function requireModule(string $module) {
    if (!isset($_SESSION['username']) || !isset($_SESSION['role'])) {
        authDeny('login.php', 'You are not signed in.');
    }
    if (!userCan($module)) {
        authDeny('access-denied.php', 'Your role does not have access to this.');
    }
}
