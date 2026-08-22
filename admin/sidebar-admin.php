<?php
// ============================================================
// Admin sidebar - navigation shell
// ------------------------------------------------------------
// Renders only the modules the signed-in role may use (userCan()
// in includes/auth.php). Section open/closed state is remembered
// in localStorage by MX.initSidebar(); a section containing the
// current page is expanded server-side so navigation still works
// with JavaScript disabled.
//
// Styling lives in assets/css/admin/ui.css. The class names here
// (.mx-section, .mx-link, .mx-badge …) are a contract with
// assets/js/admin/ui.js - do not rename without updating both.
// ============================================================

$current_page = $current_page ?? basename($_SERVER['PHP_SELF']);
$currentRole = $_SESSION['role'] ?? '';
// A blank role (legacy data) must not blow up the sidebar - the user
// simply sees Account only, and access-denied explains the fix.
if ($currentRole === '') { $currentRole = 'none'; }

$adminName = $_SESSION['username'] ?? 'Guest';

// Admin record for the avatar. Guarded so a missing connection or a
// deleted row can never take the whole page down.
$adminData = null;
if (isset($conn) && $conn instanceof mysqli) {
    $adminQuery = $conn->prepare("SELECT id, username, profile_photo FROM admin WHERE username = ?");
    if ($adminQuery) {
        $adminQuery->bind_param("s", $adminName);
        $adminQuery->execute();
        $adminData = $adminQuery->get_result()->fetch_assoc();
        $adminQuery->close();
    } else {
        error_log('sidebar-admin.php: could not prepare admin query - ' . $conn->error);
    }
}

$adminDisplayName = $adminData['username'] ?? $adminName;
$adminId    = $_SESSION['id'] ?? ($adminData['id'] ?? null);
$adminPhoto = $adminData['profile_photo'] ?? null;

// Emit the design-system CSS here too: several pages build their own
// <head> instead of using inventory-header.php, and the constant stops
// it loading twice on pages that use both.
if (!defined('MX_UI_ASSETS_EMITTED')) {
    define('MX_UI_ASSETS_EMITTED', true);
    echo '<link rel="stylesheet" href="../assets/css/admin/ui.css">' . "\n";
}
?>
<!-- Marks the page as script-less until ui.js removes it. Without this a
     JS failure would leave every nav section collapsed and unopenable. -->
<script>document.documentElement.classList.add('no-js');</script>

<div class="overlay" id="overlay" onclick="toggleSidebar()"></div>

<aside class="sidebar" id="sidebar">
    <?php
    require_once '../includes/auth.php';
    require_once __DIR__ . '/sidebar-nav.php';

    // Live badge counts. Each query is guarded so a module whose tables
    // are not installed yet can never break navigation.
    $navCounts = ['requests' => 0, 'low_stock' => 0, 'open_pos' => 0, 'unclosed_days' => 0, 'disposal' => 0];
    if (isset($conn) && $conn instanceof mysqli) {
        $navQueries = [
            'requests'  => "SELECT COUNT(*) AS c FROM inv_stock_requests WHERE status = 'pending'",
            'low_stock' => "SELECT COUNT(*) AS c FROM inv_items WHERE deleted_at IS NULL AND status = 'active' AND (current_stock <= 0 OR (reorder_level > 0 AND current_stock <= reorder_level))",
            'open_pos'  => "SELECT COUNT(*) AS c FROM inv_purchase_orders WHERE deleted_at IS NULL AND status IN ('draft','approved','partially_received')",
            'disposal'  => "SELECT COUNT(*) AS c FROM inv_disposal_requests WHERE status = 'pending'",
            // Past trading days whose cash was never counted.
            'unclosed_days' => "SELECT COUNT(DISTINCT DATE(t.created_at)) AS c FROM sales_transactions t WHERE t.status = 'completed' AND DATE(t.created_at) < CURDATE() AND NOT EXISTS (SELECT 1 FROM acc_daily_close c WHERE c.close_date = DATE(t.created_at))",
        ];
        foreach ($navQueries as $k => $sql) {
            $r = @$conn->query($sql);
            if ($r instanceof mysqli_result) { $navCounts[$k] = (int)($r->fetch_assoc()['c'] ?? 0); }
        }
    }

    // Every page belonging to each section, so the section can open
    // itself server-side (see navSectionOpen). Keep in sync when adding
    // a page - a page missing here simply won't auto-expand its section.
    $secPages = [
        'sales'      => ['pos.php','pos-sales.php','pos-receipt.php','retail-products.php','manage-customers.php'],
        'inventory'  => ['inventory-dashboard.php','inventory-items.php','barcode-station.php',
                         'barcode-labels.php','inventory-requests.php','inventory-movements.php',
                         'inventory-purchase-orders.php','inventory-po-view.php','inventory-suppliers.php',
                         'inventory-categories.php','inventory-units.php','inventory-disposal.php',
                         'inventory-disposal-print.php','inventory-reports.php','inventory-settings.php'],
        'accounting' => ['accounting-dashboard.php','chart-of-accounts.php','journal.php',
                         'journal-entry.php','expenses.php','z-report.php','profit-loss.php'],
        'account'    => ['manage-users.php','profile.php','departments.php','shop-settings.php','setup.php'],
    ];
    ?>

    <!-- Brand -->
    <div class="mx-brand">
        <div class="mx-brand-logo">
            <?php if (!empty($businessLogo)): ?>
                <img src="<?php echo htmlspecialchars($businessLogo); ?>" alt="">
            <?php else: ?>
                <i class="fas fa-cash-register"></i>
            <?php endif; ?>
        </div>
        <div class="mx-brand-text">
            <div class="mx-brand-name"><?php echo htmlspecialchars($businessName ?? 'Retail POS'); ?></div>
            <div class="mx-brand-sub"><?php echo htmlspecialchars(shopSetting($conn, 'shop_tagline', 'Retail')); ?></div>
        </div>
    </div>

    <nav class="mx-nav" aria-label="Main navigation">

        <?php
        // ------------------------------------------------------------
        // Navigation structure
        // ------------------------------------------------------------
        // Six top-level destinations, matching how the shop actually
        // works: Dashboard, Point of Sale, Inventory, Finance,
        // Administration (plus Manager Overview for managers).
        //
        // Three naming problems were fixed here:
        //
        //  * "Account" (the admin section) sat next to "Accounting"
        //    (the ledger). One is now "Administration" and the other
        //    "Finance", so neither can be mistaken for the other.
        //
        //  * Three different pages were all called "Dashboard" or
        //    "Overview". Each now says which area it summarises -
        //    "Inventory overview", "Finance overview".
        //
        //  * Every section sat under a heading that repeated its own
        //    name ("Sell" > "Sales", "Stock" > "Inventory"). The
        //    redundant headings are gone; the section label carries it.
        //
        // The mx-* class names are untouched - ui.js binds to them.
        // ------------------------------------------------------------
        ?>

        <?php if (userCan('dashboard') || userCan('manager_overview')): ?>
        <div class="mx-nav-heading">Overview</div>
        <?php endif; ?>

        <?php if (userCan('dashboard')): ?>
        <a href="index.php" class="mx-link mx-link-top <?php echo ($current_page === 'index.php') ? 'active' : ''; ?>" data-tip="Dashboard">
            <i class="fas fa-gauge-high mx-sec-home"></i>
            <span class="mx-link-text">Dashboard</span>
        </a>
        <?php endif; ?>

        <?php if (userCan('manager_overview')): ?>
        <a href="manager-overview.php" class="mx-link mx-link-top <?php echo ($current_page === 'manager-overview.php') ? 'active' : ''; ?>" data-tip="Manager overview">
            <i class="fas fa-binoculars mx-sec-home"></i>
            <span class="mx-link-text">Manager overview</span>
        </a>
        <?php endif; ?>

        <?php if (userCan('pos') || userCan('pos_sales') || userCan('products') || userCan('customers')): ?>
        <div class="mx-nav-heading">Work</div>
        <?php echo navSectionOpen('sales', 'fa-cart-shopping', 'Point of Sale', 'sales', 0, $secPages['sales']); ?>
            <?php if (userCan('pos')): ?>
                <?php echo navLink('pos.php', 'fa-cash-register', 'Open the till', ['pos.php']); ?>
            <?php endif; ?>
            <?php if (userCan('pos_sales')): ?>
                <?php echo navLink('pos-sales.php', 'fa-receipt', 'Sales & receipts', ['pos-sales.php','pos-receipt.php']); ?>
            <?php endif; ?>
            <?php if (userCan('products')): ?>
                <?php echo navLink('retail-products.php', 'fa-tags', 'Products & prices', ['retail-products.php']); ?>
            <?php endif; ?>
            <?php if (userCan('customers')): ?>
                <?php echo navLink('manage-customers.php', 'fa-users', 'Customers', ['manage-customers.php']); ?>
            <?php endif; ?>
        <?php echo navSectionClose(); ?>
        <?php endif; ?>

        <?php if (userCan('inventory')): ?>
        <?php echo navSectionOpen('inventory', 'fa-boxes-stacked', 'Inventory', 'inventory', $navCounts['requests'] + $navCounts['low_stock'] + $navCounts['disposal'], $secPages['inventory']); ?>
            <?php echo navLink('inventory-dashboard.php', 'fa-chart-pie', 'Inventory overview', ['inventory-dashboard.php']); ?>
            <?php echo navLink('inventory-items.php', 'fa-box-open', 'Items & stock levels', ['inventory-items.php'], navBadge('low_stock', $navCounts['low_stock'], 'warn')); ?>
            <?php echo navLink('inventory-movements.php', 'fa-right-left', 'Stock movements', ['inventory-movements.php']); ?>
            <?php if (userCan('barcode')): ?>
                <?php echo navLink('barcode-station.php', 'fa-barcode', 'Barcode station', ['barcode-station.php','barcode-labels.php']); ?>
            <?php endif; ?>
            <?php if (userCan('stock_requests')): ?>
                <?php echo navLink('inventory-requests.php', 'fa-clipboard-list', 'Stock requests', ['inventory-requests.php'], navBadge('requests', $navCounts['requests'], 'warn')); ?>
            <?php endif; ?>
            <?php if (userCan('inventory')): ?>
                <?php echo navLink('inventory-disposal.php', 'fa-trash-can', 'Expired goods', ['inventory-disposal.php','inventory-disposal-print.php'], navBadge('disposal', $navCounts['disposal'], 'danger')); ?>
            <?php endif; ?>
            <?php if (userCan('purchasing')): ?>
                <?php echo navLink('inventory-purchase-orders.php', 'fa-file-invoice-dollar', 'Purchase orders', ['inventory-purchase-orders.php','inventory-po-view.php'], navBadge('open_pos', $navCounts['open_pos'], 'info')); ?>
                <?php echo navLink('inventory-suppliers.php', 'fa-truck-field', 'Suppliers', ['inventory-suppliers.php']); ?>
            <?php endif; ?>
            <?php echo navLink('inventory-reports.php', 'fa-chart-column', 'Inventory reports', ['inventory-reports.php']); ?>
            <?php
            // Reference data and configuration - needed rarely, so kept
            // below the daily work rather than mixed into it.
            ?>
            <?php echo navLink('inventory-categories.php', 'fa-folder-tree', 'Categories', ['inventory-categories.php']); ?>
            <?php echo navLink('inventory-units.php', 'fa-ruler', 'Units of measure', ['inventory-units.php']); ?>
            <?php if (userCan('settings')): ?>
                <?php echo navLink('inventory-settings.php', 'fa-sliders', 'Inventory settings', ['inventory-settings.php']); ?>
            <?php endif; ?>
        <?php echo navSectionClose(); ?>
        <?php endif; ?>

        <?php if (userCan('accounting')): ?>
        <?php echo navSectionOpen('accounting', 'fa-book', 'Finance', 'accounting', $navCounts['unclosed_days'], $secPages['accounting']); ?>
            <?php echo navLink('accounting-dashboard.php', 'fa-chart-pie', 'Finance overview', ['accounting-dashboard.php']); ?>
            <?php echo navLink('z-report.php', 'fa-cash-register', 'Daily close (Z-report)', ['z-report.php'], navBadge('unclosed_days', $navCounts['unclosed_days'], 'warn')); ?>
            <?php echo navLink('expenses.php', 'fa-money-bill-wave', 'Expenses', ['expenses.php']); ?>
            <?php echo navLink('profit-loss.php', 'fa-chart-line', 'Profit & loss', ['profit-loss.php']); ?>
            <?php echo navLink('journal.php', 'fa-book-open', 'General ledger', ['journal.php','journal-entry.php']); ?>
            <?php if (userCan('accounting_manage')): ?>
                <?php echo navLink('chart-of-accounts.php', 'fa-sitemap', 'Chart of accounts', ['chart-of-accounts.php']); ?>
            <?php endif; ?>
        <?php echo navSectionClose(); ?>
        <?php endif; ?>

        <?php
        // Reports sits last in the working area, below Finance: it reads
        // from every module above it rather than being one of them.
        // The Reporting Centre gates each group internally, so it is
        // offered to anyone with at least one reportable module.
        if (userCan('pos_sales') || userCan('inventory') || userCan('purchasing') || userCan('accounting')): ?>
        <a href="reports.php" class="mx-link mx-link-top <?php echo in_array($current_page, ['reports.php','report.php'], true) ? 'active' : ''; ?>" data-tip="Reports">
            <i class="fas fa-chart-column mx-sec-home"></i>
            <span class="mx-link-text">Reports</span>
        </a>
        <?php endif; ?>

        <div class="mx-nav-heading">Manage</div>
        <?php echo navSectionOpen('account', 'fa-gear', 'Administration', 'account', 0, $secPages['account']); ?>
            <?php if (userCan('shop_settings')): ?>
                <?php echo navLink('setup.php', 'fa-wand-magic-sparkles', 'Setup', ['setup.php']); ?>
                <?php echo navLink('shop-settings.php', 'fa-store', 'Shop settings', ['shop-settings.php']); ?>
            <?php endif; ?>
            <?php if (userCan('departments')): ?>
                <?php echo navLink('departments.php', 'fa-shapes', 'Departments', ['departments.php']); ?>
            <?php endif; ?>
            <?php if (userCan('users')): ?>
                <?php echo navLink('manage-users.php', 'fa-user-shield', 'Staff accounts', ['manage-users.php']); ?>
            <?php endif; ?>
            <?php echo navLink('profile.php', 'fa-id-badge', 'My profile', ['profile.php']); ?>
            <a href="javascript:void(0);" onclick="confirmLogout()" class="mx-link mx-link-logout" data-tip="Sign out">
                <i class="fas fa-right-from-bracket"></i>
                <span class="mx-link-text">Sign out</span>
            </a>
        <?php echo navSectionClose(); ?>

    </nav>

    <!-- Signed-in user -->
    <div class="mx-user">
        <?php if (!empty($adminPhoto)): ?>
            <img src="../assets/uploads/profile_admin/<?php echo htmlspecialchars($adminPhoto); ?>" class="mx-user-avatar" alt="">
        <?php else: ?>
            <img src="../assets/images/default-avatar.png" class="mx-user-avatar" alt="">
        <?php endif; ?>
        <div class="mx-user-meta">
            <div class="mx-user-name"><?php echo htmlspecialchars($adminDisplayName); ?></div>
            <div class="mx-user-role"><?php echo htmlspecialchars(roleLabel($currentRole)); ?></div>
        </div>
        <a href="profile.php" class="mx-user-edit" title="Edit profile" aria-label="Edit profile"><i class="fas fa-gear"></i></a>
    </div>

    <?php
    // Ship the shared behaviour with the sidebar. Most admin pages build
    // their own <head>, so linking it only from inventory-footer.php
    // would leave several pages with a menu that cannot be expanded.
    if (!defined('MX_UI_JS_EMITTED')) {
        define('MX_UI_JS_EMITTED', true);
        echo '<script src="../assets/js/admin/ui.js"></script>' . "\n";
    }
    ?>
</aside>

<!-- Sign-out confirmation -->
<div class="modal fade" id="logoutModal" tabindex="-1" aria-labelledby="logoutModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="logoutModalLabel">Sign out</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-0 ui-body">You will need to sign in again to continue.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="ui-btn ui-btn-secondary ui-btn-sm" data-bs-dismiss="modal">Cancel</button>
                <a href="../logout.php" class="ui-btn ui-btn-danger-solid ui-btn-sm">Sign out</a>
            </div>
        </div>
    </div>
</div>

<script>
    // Sidebar collapse (desktop) / off-canvas (tablet and below).
    // 992px matches the breakpoint in ui.css - keep them in step.
    function toggleSidebar() {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('overlay');
        const content = document.getElementById('content');

        if (window.innerWidth > 992) {
            sidebar.classList.toggle('collapsed');
            if (content) { content.classList.toggle('collapsed'); }
            localStorage.setItem('sidebarState', sidebar.classList.contains('collapsed') ? 'collapsed' : 'expanded');
        } else {
            sidebar.classList.toggle('open');
            overlay.classList.toggle('show');
        }
    }

    function confirmLogout() {
        if (window.bootstrap) {
            bootstrap.Modal.getOrCreateInstance(document.getElementById('logoutModal')).show();
        } else {
            window.location.href = '../logout.php';
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        const sidebar = document.getElementById('sidebar');
        const content = document.getElementById('content');
        const overlay = document.getElementById('overlay');

        if (localStorage.getItem('sidebarState') === 'collapsed' && window.innerWidth > 992) {
            sidebar.classList.add('collapsed');
            if (content) { content.classList.add('collapsed'); }
        }
        if (window.innerWidth <= 992) {
            sidebar.classList.remove('open');
            overlay.classList.remove('show');
        }

        // Close the off-canvas menu with Escape.
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && sidebar.classList.contains('open')) { toggleSidebar(); }
        });

        const backToTop = document.getElementById('backToTopBtn');
        if (backToTop) {
            const sync = () => backToTop.classList.toggle('show', window.pageYOffset > 240);
            window.addEventListener('scroll', sync, { passive: true });
            sync();
        }
    });
</script>
