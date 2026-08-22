<?php
// ============================================================
// Reporting Center - the single way in to every report
// ------------------------------------------------------------
// Reports were previously scattered: inventory ones behind a
// dropdown on inventory-reports.php, financial ones each on their
// own page, and sales reporting barely existed at all. This is the
// index over the lot.
//
// It shows only groups the signed-in role may open, using the
// EXISTING module permissions - a cashier has pos_sales so they see
// sales reports; they have neither inventory nor accounting, so
// financial reports never appear for them.
// ============================================================
require_once '../includes/auth.php';

// Any signed-in user may reach the centre; each group inside is gated
// individually, and a role with no reportable modules is told so
// rather than being bounced to access-denied.
requireRole(allSystemRoles());
csrfRequire();

$current_page = basename($_SERVER['PHP_SELF']);
include '../includes/db.php';
require_once '../includes/report_functions.php';
inventoryBoot($conn);

$groups = reportVisibleGroups();

$pageTitle = 'Reports';
$breadcrumbs = [['Dashboard', 'index.php'], ['Reports']];
include 'inventory-header.php';

$ph = [
    'title'    => 'Reporting Centre',
    'icon'     => 'fa-chart-column',
    'subtitle' => 'Every report the system can produce, grouped by what it answers. All figures come from the live books and stock records.',
];
include 'partials/page-header.php';
?>

<?php if (!$groups): ?>
    <?php
    $es = ['icon' => 'fa-chart-column',
           'title' => 'No reports available for your role',
           'msg'   => 'Reports follow the areas you can already use. Ask an administrator if you need access to sales, inventory or accounting.'];
    include 'partials/empty-state.php';
    ?>
<?php else: ?>

<div class="row g-3">
    <?php foreach ($groups as $key => $group): ?>
    <div class="col-lg-6">
        <section class="inv-card h-100" aria-labelledby="rg-<?php echo $key; ?>">
            <div class="ui-card-head">
                <h2 class="ui-card-title" id="rg-<?php echo $key; ?>">
                    <i class="fas <?php echo htmlspecialchars($group['icon']); ?> me-2"
                       style="color:var(--accent-<?php echo htmlspecialchars($group['accent']); ?>);" aria-hidden="true"></i>
                    <?php echo htmlspecialchars($group['label']); ?>
                </h2>
                <span class="ui-badge ui-badge-neutral"><?php echo count($group['reports']); ?></span>
            </div>
            <div class="ui-card-body">
                <p class="ui-muted mb-3"><?php echo htmlspecialchars($group['blurb']); ?></p>
                <ul class="rc-list">
                    <?php foreach ($group['reports'] as [$rk, $rlabel, $rblurb]): ?>
                    <li>
                        <a class="rc-item" href="report.php?g=<?php echo urlencode($key); ?>&amp;r=<?php echo urlencode($rk); ?>">
                            <span class="rc-item-text">
                                <span class="rc-item-title"><?php echo htmlspecialchars($rlabel); ?></span>
                                <span class="rc-item-blurb"><?php echo htmlspecialchars($rblurb); ?></span>
                            </span>
                            <i class="fas fa-chevron-right rc-item-go" aria-hidden="true"></i>
                        </a>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </section>
    </div>
    <?php endforeach; ?>
</div>

<div class="inv-card p-4 mt-3">
    <h2 class="ui-section-title mb-2">Where these figures come from</h2>
    <p class="ui-muted mb-2">
        Nothing here is recalculated for display. Sales figures come from the recorded
        transactions, stock values from the item records at weighted-average cost, and every
        financial figure from the double-entry ledger itself &mdash; the same source the
        Profit &amp; Loss and daily close already use.
    </p>
    <p class="ui-caption mb-0">
        Every report can be printed on your shop's letterhead or exported as CSV.
        Both come from the same server-side query as the screen, so an export can never
        disagree with what you were looking at.
    </p>
</div>

<?php endif; ?>

<?php
$pageHead = '';
include 'inventory-footer.php';
?>
