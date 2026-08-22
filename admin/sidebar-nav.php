<?php
// ============================================================
// Sidebar helpers - kept separate so sidebar-admin.php stays
// readable. Included by sidebar-admin.php.
// ============================================================

/**
 * A count badge with a stable id so ui.js can update it live
 * (see MX.watchAlerts). Hidden when the count is zero.
 */
function navBadge(string $key, int $count, string $variant = 'alert'): string {
    $hidden = $count > 0 ? '' : 'display:none;';
    return '<span id="nav-badge-' . htmlspecialchars($key) . '"'
         . ' class="mx-badge mx-badge-' . htmlspecialchars($variant) . '"'
         . ' style="' . $hidden . '">' . $count . '</span>';
}

/**
 * One navigation link inside a section.
 * $pages: every page filename that should light this link up.
 */
function navLink(string $href, string $icon, string $label, array $pages, string $badge = '', string $extraClass = ''): string {
    global $current_page;
    $active = in_array($current_page, $pages, true) ? ' active' : '';
    return '<a href="' . htmlspecialchars($href) . '" class="mx-link' . $active . ' ' . $extraClass . '" data-tip="' . htmlspecialchars($label) . '">'
         . '<i class="fas ' . htmlspecialchars($icon) . '"></i>'
         . '<span class="mx-link-text">' . htmlspecialchars($label) . '</span>'
         . $badge
         . '</a>';
}

/**
 * Open a collapsible section. $accent drives the icon colour
 * (sales | shop | inventory | account | home).
 *
 * $ownPages lets the section decide SERVER-SIDE whether it contains the
 * current page. That matters: without it a section only expands once
 * JavaScript runs, so on any page where the script is missing or slow
 * the whole menu would sit collapsed and look broken. Rendering the
 * `open` class up front means navigation works even with JS disabled -
 * ui.js then takes over for click-to-toggle and remembered state.
 */
function navSectionOpen(string $key, string $icon, string $label, string $accent, int $rollupCount = 0, array $ownPages = []): string {
    global $current_page;
    $isHere = $ownPages && in_array($current_page, $ownPages, true);
    $cls = 'mx-section' . ($isHere ? ' open has-active' : '');

    $badge = $rollupCount > 0
        ? '<span id="nav-rollup-' . htmlspecialchars($key) . '" class="mx-badge mx-badge-muted">' . $rollupCount . '</span>'
        : '';
    return '<div class="' . $cls . '" data-section="' . htmlspecialchars($key) . '">'
         . '<button type="button" class="mx-section-toggle" aria-expanded="' . ($isHere ? 'true' : 'false') . '" data-tip="' . htmlspecialchars($label) . '">'
         . '<span class="mx-sec-icon mx-sec-' . htmlspecialchars($accent) . '"><i class="fas ' . htmlspecialchars($icon) . '"></i></span>'
         . '<span class="mx-sec-label">' . htmlspecialchars($label) . '</span>'
         . $badge
         . '<i class="fas fa-chevron-right mx-caret"></i>'
         . '</button>'
         . '<div class="mx-section-body">';
}

function navSectionClose(): string {
    return '</div></div>';
}
