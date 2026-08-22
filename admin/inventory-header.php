<?php
// ============================================================
// Shared admin layout - OPEN
// ------------------------------------------------------------
// Expects the including page to have already run auth, set
// $current_page, and included db + its module functions.
//
// Optional page variables:
//   $pageTitle    string  page + browser title
//   $pageSubtitle string  one line under the title in the top bar
//   $breadcrumbs  array   [['Label','url.php'], …, ['Current']]
//   $topbarSearch array   ['placeholder'=>, 'name'=>, 'action'=>, 'value'=>]
//
// All styling now lives in assets/css/admin/ui.css - this file no
// longer carries an inline <style> block, so restyling a component
// happens in one place and every page picks it up.
// ============================================================
if (!isset($pageTitle)) { $pageTitle = 'Dashboard'; }
// Business identity comes from Shop Settings, falling back to the
// original constants so an unconfigured install looks unchanged.
require_once __DIR__ . '/../includes/shop_settings.php';
$businessName = shopName($conn);
$businessLogo = shopLogoUrl($conn, '../');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <script>
    /* Applied before any stylesheet paints, so the page never flashes
       the wrong theme. "system" deliberately sets NO attribute - the
       prefers-color-scheme rules in ui.css handle it, which keeps the
       OS the single source of truth for that mode. */
    (function () {
        try {
            var t = localStorage.getItem('mxTheme');
            if (t === 'light' || t === 'dark') {
                document.documentElement.setAttribute('data-theme', t);
            }
        } catch (e) { /* private mode - fall back to system */ }
    })();
    </script>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?> &middot; <?php echo htmlspecialchars($businessName); ?></title>
    <link rel="icon" type="image/png" href="../assets/images/favicon.png">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/admin/styles.css">
    <!-- Design system: must load after styles.css so it can refine it -->
    <link rel="stylesheet" href="../assets/css/admin/ui.css">
    <?php if (!defined('MX_UI_ASSETS_EMITTED')) { define('MX_UI_ASSETS_EMITTED', true); } ?>
    <?php
    // Page-specific <head> assets. Set $pageHead before including this
    // file when one screen needs a library the rest of the app does not
    // (e.g. the avatar cropper on profile.php). Emitted verbatim, so the
    // calling page owns its contents.
    if (!empty($pageHead)) { echo $pageHead; }
    ?>
</head>
<body>
    <?php include 'sidebar-admin.php'; ?>

    <div class="content" id="content">

        <!-- ============ Top bar ============ -->
        <header class="ui-topbar">
            <button class="toggle-btn-mobile" onclick="toggleSidebar()" aria-label="Open navigation">
                <i class="fas fa-bars"></i>
            </button>
            <button class="toggle-btn" onclick="toggleSidebar()" aria-label="Collapse navigation">
                <i class="fas fa-bars"></i>
            </button>

            <div class="ui-topbar-context">
                <?php
                // The page's own <h1> carries the title, so the top bar
                // shows the trail that leads to it rather than repeating
                // the same word twice on screen. With no trail to show,
                // it falls back to the page name for orientation.
                $sep = ' <i class="fas fa-angle-right" style="font-size:.6rem;opacity:.45;"></i> ';
                if (!empty($breadcrumbs) && is_array($breadcrumbs) && count($breadcrumbs) > 1) {
                    $parts = [];
                    foreach ($breadcrumbs as $bc) {
                        $parts[] = !empty($bc[1])
                            ? '<a href="' . htmlspecialchars($bc[1]) . '">' . htmlspecialchars($bc[0]) . '</a>'
                            : '<span style="color:var(--color-text);font-weight:500;">' . htmlspecialchars($bc[0]) . '</span>';
                    }
                    echo '<span class="ui-topbar-crumb">' . implode($sep, $parts) . '</span>';
                } else {
                    echo '<span class="ui-topbar-title">' . htmlspecialchars($pageTitle) . '</span>';
                }
                ?>
            </div>

            <div class="ui-topbar-spacer"></div>

            <?php if (!empty($topbarSearch) && is_array($topbarSearch)): ?>
            <!-- Page-scoped search: submits to the page's own GET handler. -->
            <form class="ui-topbar-search" method="get" action="<?php echo htmlspecialchars($topbarSearch['action'] ?? ''); ?>" role="search">
                <i class="fas fa-magnifying-glass"></i>
                <input type="search"
                       name="<?php echo htmlspecialchars($topbarSearch['name'] ?? 'q'); ?>"
                       value="<?php echo htmlspecialchars($topbarSearch['value'] ?? ''); ?>"
                       placeholder="<?php echo htmlspecialchars($topbarSearch['placeholder'] ?? 'Search…'); ?>"
                       aria-label="Search this page">
            </form>
            <?php endif; ?>

            <?php if (function_exists('userCan') && userCan('pos')): ?>
            <a href="pos.php" class="ui-btn ui-btn-secondary ui-btn-sm" title="Open the POS terminal">
                <i class="fas fa-cash-register"></i><span class="d-none d-lg-inline">Open Till</span>
            </a>
            <?php endif; ?>

            <!-- Theme: light / system / dark. A three-state control rather
                 than a toggle, because "follow my computer" is a distinct
                 answer from either fixed choice. -->
            <div class="ui-theme-switch" id="themeSwitch" role="group" aria-label="Colour theme">
                <button type="button" data-theme-choice="light"  aria-pressed="false" title="Light theme"  aria-label="Light theme"><i class="fas fa-sun"></i></button>
                <button type="button" data-theme-choice="system" aria-pressed="true"  title="Match my device" aria-label="Match my device"><i class="fas fa-circle-half-stroke"></i></button>
                <button type="button" data-theme-choice="dark"   aria-pressed="false" title="Dark theme"   aria-label="Dark theme"><i class="fas fa-moon"></i></button>
            </div>

            <?php
            // Notification bell - the dot appears only when the live
            // alert poll reports something outstanding (see ui.js).
            ?>
            <a href="<?php echo (function_exists('userCan') && userCan('inventory')) ? 'inventory-items.php' : '#'; ?>"
               class="ui-topbar-btn" id="topbarAlerts" title="Alerts" aria-label="Alerts">
                <i class="fas fa-bell"></i>
                <span class="ui-topbar-dot" id="topbarAlertDot" style="display:none;"></span>
            </a>

            <a href="profile.php" class="ui-topbar-user">
                <?php if (!empty($adminPhoto)): ?>
                    <img src="../assets/uploads/profile_admin/<?php echo htmlspecialchars($adminPhoto); ?>" alt="">
                <?php else: ?>
                    <img src="../assets/images/default-avatar.png" alt="">
                <?php endif; ?>
                <span class="d-none d-md-block">
                    <span class="ui-topbar-user-name d-block"><?php echo htmlspecialchars($adminDisplayName ?? ''); ?></span>
                    <span class="ui-topbar-user-role d-block"><?php echo htmlspecialchars(function_exists('roleLabel') ? roleLabel($_SESSION['role'] ?? '') : ''); ?></span>
                </span>
            </a>
        </header>

        <main class="ui-page">
            <?php
            // Flash messages become toasts via ui.js. The <noscript>
            // block keeps them visible if JavaScript is unavailable.
            if (!empty($_SESSION['inv_flash'])):
                $flash = $_SESSION['inv_flash'];
                $flashType = in_array($flash['type'], ['success','warning','danger','info'], true) ? $flash['type'] : 'info';
            ?>
                <span data-mx-flash="<?php echo htmlspecialchars($flash['msg']); ?>"
                      data-mx-flash-type="<?php echo htmlspecialchars($flashType); ?>"></span>
                <noscript>
                    <div class="alert alert-<?php echo htmlspecialchars($flashType); ?>">
                        <?php echo htmlspecialchars($flash['msg']); ?>
                    </div>
                </noscript>
                <?php unset($_SESSION['inv_flash']); ?>
            <?php endif; ?>
