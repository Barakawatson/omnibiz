<?php
// ============================================================
// Admin Dashboard - the whole shop at a glance.
// ------------------------------------------------------------
// Today's trade, stock health, department split and the ledger
// position. Roles without the 'dashboard' module are bounced to
// their own home screen instead (a cashier lands on the till).
// ============================================================
require_once '../includes/auth.php';
requireRole(allSystemRoles());

if (!userCan('dashboard')) {
    header('Location: ' . roleHome($_SESSION['role'] ?? ''));
    exit();
}

$current_page = basename($_SERVER['PHP_SELF']);
include '../includes/db.php';
require_once '../includes/pos_functions.php';
posBoot($conn);

$today       = date('Y-m-d');
$departments = catalogDepartments();
$figures     = accDayFigures($conn, $today);
$todayClose  = accGetClose($conn, $today);

/** Single scalar from a guarded query, so a missing table can't break the page. */
function dashScalar(mysqli $conn, string $sql, $default = 0) {
    $r = @$conn->query($sql);
    if ($r instanceof mysqli_result) {
        $row = $r->fetch_assoc();
        return $row ? array_values($row)[0] : $default;
    }
    return $default;
}

// ---- Stock health -----------------------------------------------------
// Everything below describes what the shop currently stocks, so it is
// restricted to departments that are trading. Financial figures further
// down are NOT filtered - see the note above the sales queries.
$deptFilter = catalogDepartmentFilterSql($conn, 'i');

$outOfStock  = (int)dashScalar($conn, "SELECT COUNT(*) FROM inv_items i WHERE i.deleted_at IS NULL AND i.status = 'active' AND i.current_stock <= 0" . $deptFilter);
$lowStock    = (int)dashScalar($conn, "SELECT COUNT(*) FROM inv_items i WHERE i.deleted_at IS NULL AND i.status = 'active' AND i.current_stock > 0 AND i.reorder_level > 0 AND i.current_stock <= i.reorder_level" . $deptFilter);
$totalItems  = (int)dashScalar($conn, "SELECT COUNT(*) FROM inv_items i WHERE i.deleted_at IS NULL AND i.status = 'active'" . $deptFilter);
$stockValue  = (float)dashScalar($conn, "SELECT COALESCE(SUM(i.current_stock * i.average_cost), 0) FROM inv_items i WHERE i.deleted_at IS NULL AND i.status = 'active'" . $deptFilter);
// Past trading days with sales but no daily close - surfaced on the
// dashboard so an unreconciled till is noticed the next morning.
$unclosedDays = (int)dashScalar($conn,
    "SELECT COUNT(DISTINCT DATE(t.created_at)) FROM sales_transactions t
      WHERE t.status = 'completed' AND DATE(t.created_at) < CURDATE()
        AND NOT EXISTS (SELECT 1 FROM acc_daily_close c WHERE c.close_date = DATE(t.created_at))");
$openPOs     = (int)dashScalar($conn, "SELECT COUNT(*) FROM inv_purchase_orders WHERE deleted_at IS NULL AND status IN ('draft','approved','partially_received')");
$pendingReqs = (int)dashScalar($conn, "SELECT COUNT(*) FROM inv_stock_requests WHERE status = 'pending'");
$pendingDisposals = (int)dashScalar($conn, "SELECT COUNT(*) FROM inv_disposal_requests WHERE status = 'pending'");
$unpriced    = (int)dashScalar($conn, "SELECT COUNT(*) FROM inv_items i JOIN retail_product_details d ON d.item_id = i.id
                                       WHERE i.deleted_at IS NULL AND d.usage_type IN ('sale','both') AND (d.selling_price IS NULL OR d.selling_price <= 0)" . $deptFilter);

// ---- Month to date ----------------------------------------------------
$monthStart = date('Y-m-01');
$monthSales = (float)dashScalar($conn, "SELECT COALESCE(SUM(total), 0) FROM sales_transactions
                                        WHERE status = 'completed' AND DATE(created_at) >= '$monthStart'");
$monthProfit = (float)dashScalar($conn, "SELECT COALESCE(SUM(gross_profit), 0) FROM sales_transactions
                                         WHERE status = 'completed' AND DATE(created_at) >= '$monthStart'");
$monthCount  = (int)dashScalar($conn, "SELECT COUNT(*) FROM sales_transactions
                                       WHERE status = 'completed' AND DATE(created_at) >= '$monthStart'");
$avgBasket   = $monthCount > 0 ? $monthSales / $monthCount : 0;

// ---- Last 7 days, for the mini trend ----------------------------------
$trend = [];
$trendRes = @$conn->query(
    "SELECT DATE(created_at) AS d, COALESCE(SUM(total), 0) AS t
     FROM sales_transactions
     WHERE status = 'completed' AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
     GROUP BY DATE(created_at)");
if ($trendRes instanceof mysqli_result) {
    foreach ($trendRes->fetch_all(MYSQLI_ASSOC) as $r) { $trend[$r['d']] = (float)$r['t']; }
}
$trendDays = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i day"));
    $trendDays[$d] = $trend[$d] ?? 0.0;
}
$trendMax = max(1, max($trendDays));

// ---- Top sellers this month -------------------------------------------
$topSellers = [];
$topRes = @$conn->query(
    "SELECT li.item_name, i.department,
            SUM(li.quantity) AS qty,
            SUM(li.line_total) AS revenue
     FROM sales_transaction_items li
     JOIN sales_transactions t ON t.id = li.transaction_id AND t.status = 'completed'
     LEFT JOIN inv_items i ON i.id = li.item_id
     WHERE DATE(t.created_at) >= '$monthStart'"
     . $deptFilter . "
     GROUP BY li.item_id, li.item_name, i.department
     ORDER BY revenue DESC LIMIT 8");
if ($topRes instanceof mysqli_result) { $topSellers = $topRes->fetch_all(MYSQLI_ASSOC); }

// ---- Items needing attention -------------------------------------------
$reorderList = [];
$reorderRes = @$conn->query(
    "SELECT i.id, i.name, i.department, i.current_stock, i.reorder_level, u.abbreviation AS unit
     FROM inv_items i
     LEFT JOIN inv_units u ON u.id = i.unit_id
     WHERE i.deleted_at IS NULL AND i.status = 'active'
       AND (i.current_stock <= 0 OR (i.reorder_level > 0 AND i.current_stock <= i.reorder_level))"
     . $deptFilter . "
     ORDER BY i.current_stock ASC LIMIT 10");
if ($reorderRes instanceof mysqli_result) { $reorderList = $reorderRes->fetch_all(MYSQLI_ASSOC); }

// ---- Recent sales ------------------------------------------------------
$recentSales = [];
$recentRes = @$conn->query(
    "SELECT t.id, t.receipt_no, t.total, t.payment_method, t.department, t.status,
            t.cashier_name, t.created_at, term.name AS terminal_name
     FROM sales_transactions t
     LEFT JOIN pos_terminals term ON term.id = t.terminal_id
     ORDER BY t.id DESC LIMIT 8");
if ($recentRes instanceof mysqli_result) { $recentSales = $recentRes->fetch_all(MYSQLI_ASSOC); }

$pageTitle = 'Dashboard';
include 'inventory-header.php';

function dashMoney($v) { return 'Tsh ' . number_format((float)$v); }
?>
<?php
$actions = '';
if (userCan('accounting')) {
    $actions .= '<a href="z-report.php" class="ui-btn ui-btn-secondary"><i class="fas fa-file-invoice"></i>Daily close</a>';
}
$ph = [
    'title'    => 'Dashboard',
    'subtitle' => htmlspecialchars(shopName($conn)) . ' &middot; ' . date('l, j F Y')
                  . ($todayClose ? ' <span class="ui-badge ui-badge-neutral ms-1"><i class="fas fa-lock"></i>Day closed</span>' : ''),
    'actions'  => $actions,
];
include 'partials/page-header.php';
?>

<!-- ============ First run ============ -->
<!-- Only rendered while setup has never been completed, and only for
     someone allowed to run it. Silent on a configured shop. -->
<?php include 'partials/first-run.php'; ?>

<!-- ============ Today ============ -->
<!-- ============ Needs attention ============ -->
<?php
// ------------------------------------------------------------------
// Exceptions come FIRST, above the figures.
//
// The takings and profit cards say how the shop is doing; this block
// says what somebody has to actually go and do. Reading the second
// from the first was previously left to the viewer - a low-stock
// count sat in a KPI tile, unclosed days appeared only inside the
// accounting screen, and open purchase orders nowhere at all.
//
// Every item is gated by the same permission as the page it links to,
// so nobody is told about work they cannot open.
// Uses existing figures only - no new queries, no changed maths.
// ------------------------------------------------------------------
$attention = [];

if (userCan('inventory') && $outOfStock > 0) {
    $attention[] = ['danger', 'fa-circle-exclamation',
        $outOfStock . ' ' . ($outOfStock === 1 ? 'product is' : 'products are') . ' out of stock',
        'They cannot be sold until stock is received.',
        'inventory-items.php?status=out', 'View items'];
}
if (userCan('inventory') && $lowStock > 0) {
    $attention[] = ['warning', 'fa-arrow-trend-down',
        $lowStock . ' ' . ($lowStock === 1 ? 'product is' : 'products are') . ' at or below reorder level',
        'Reorder before they run out.',
        'inventory-items.php?status=low', 'View items'];
}
if (userCan('accounting') && $unclosedDays > 0) {
    $attention[] = ['warning', 'fa-calendar-xmark',
        $unclosedDays . ' trading ' . ($unclosedDays === 1 ? 'day was' : 'days were') . ' never closed',
        'Their cash has not been reconciled against the books.',
        'z-report.php', 'Close the day'];
}
if (userCan('purchasing') && $openPOs > 0) {
    $attention[] = ['info', 'fa-file-invoice-dollar',
        $openPOs . ' purchase ' . ($openPOs === 1 ? 'order is' : 'orders are') . ' still open',
        'Awaiting approval, delivery or payment.',
        'inventory-purchase-orders.php', 'View orders'];
}
if (userCan('products') && $unpriced > 0) {
    $attention[] = ['danger', 'fa-tag',
        $unpriced . ' ' . ($unpriced === 1 ? 'product has' : 'products have') . ' no price',
        'They are marked for sale but cannot be rung up at the till.',
        'retail-products.php?filter=unpriced', 'Set prices'];
}
if (userCan('stock_requests') && $pendingReqs > 0) {
    $attention[] = ['info', 'fa-clipboard-list',
        $pendingReqs . ' stock ' . ($pendingReqs === 1 ? 'request is' : 'requests are') . ' waiting',
        'Nothing is deducted until you approve them.',
        'inventory-requests.php', 'Review'];
}
if (userCan('disposal_approve') && $pendingDisposals > 0) {
    $attention[] = ['warning', 'fa-trash-can',
        $pendingDisposals . ' disposal ' . ($pendingDisposals === 1 ? 'request needs' : 'requests need') . ' review',
        'Expired stock cannot be written off until you approve or reject it.',
        'inventory-disposal.php', 'Review'];
}
?>

<section class="ui-card mb-4" aria-labelledby="attnHead">
    <div class="ui-card-head">
        <h2 class="ui-card-title" id="attnHead">
            <i class="fas fa-triangle-exclamation me-2" style="color:<?php echo $attention ? 'var(--color-warning)' : 'var(--color-success)'; ?>;"></i>
            Needs attention
        </h2>
        <?php if ($attention): ?>
        <span class="ui-badge ui-badge-warning"><?php echo count($attention); ?></span>
        <?php else: ?>
        <span class="ui-badge ui-badge-success"><span class="ui-dot ui-dot-success"></span>All clear</span>
        <?php endif; ?>
    </div>
    <div class="ui-card-body">
        <?php if (!$attention): ?>
            <!-- A useful empty state: says what was checked, not just "nothing". -->
            <p class="ui-body mb-1">Nothing needs action right now.</p>
            <p class="ui-caption mb-0">
                Stock levels, purchase orders, product prices, stock requests and daily closes
                have all been checked.
            </p>
        <?php else: ?>
        <ul class="ui-attn-list">
            <?php foreach ($attention as [$tone, $icon, $title, $detail, $href, $cta]): ?>
            <li class="ui-attn-item ui-attn-<?php echo $tone; ?>">
                <i class="fas <?php echo $icon; ?> ui-attn-icon" aria-hidden="true"></i>
                <div class="ui-attn-text">
                    <div class="ui-attn-title"><?php echo htmlspecialchars($title); ?></div>
                    <div class="ui-attn-detail"><?php echo htmlspecialchars($detail); ?></div>
                </div>
                <a href="<?php echo htmlspecialchars($href); ?>" class="ui-btn ui-btn-secondary ui-btn-sm">
                    <?php echo htmlspecialchars($cta); ?>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>
</section>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="inv-stat-card bg-grad-teal">
            <div class="d-flex justify-content-between">
                <div>
                    <div class="label">Today's Takings</div>
                    <div class="value"><?php echo dashMoney($figures['net_sales']); ?></div>
                    <div class="label"><?php echo (int)$figures['sales_count']; ?> sale(s)</div>
                </div>
                <i class="fas fa-cash-register icon"></i>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="inv-stat-card bg-grad-green">
            <div class="d-flex justify-content-between">
                <div>
                    <div class="label">Today's Gross Profit</div>
                    <div class="value"><?php echo dashMoney($figures['gross_profit']); ?></div>
                    <div class="label">Cost <?php echo dashMoney($figures['cost_of_sales']); ?></div>
                </div>
                <i class="fas fa-arrow-trend-up icon"></i>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="inv-stat-card bg-grad-blue">
            <div class="d-flex justify-content-between">
                <div>
                    <div class="label">Expected in Drawer</div>
                    <div class="value"><?php echo dashMoney($figures['expected_cash']); ?></div>
                    <div class="label">Cash <?php echo dashMoney($figures['cash_sales']); ?> &middot; Mobile <?php echo dashMoney($figures['mobile_sales']); ?></div>
                </div>
                <i class="fas fa-wallet icon"></i>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="inv-stat-card <?php echo ($outOfStock + $lowStock) > 0 ? 'bg-grad-amber' : 'bg-grad-purple'; ?>">
            <div class="d-flex justify-content-between">
                <div>
                    <div class="label">Needs Reordering</div>
                    <div class="value"><?php echo $outOfStock + $lowStock; ?></div>
                    <div class="label"><?php echo $outOfStock; ?> out &middot; <?php echo $lowStock; ?> low</div>
                </div>
                <i class="fas fa-triangle-exclamation icon"></i>
            </div>
        </div>
    </div>
</div>

<?php /* The old alert strip moved into "Needs attention" above. */ ?>

<div class="row g-3 mb-4">
    <!-- ============ 7-day trend ============ -->
    <div class="col-lg-8">
        <div class="inv-card p-4 h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="mb-0 fw-bold"><i class="fas fa-chart-column me-2" style="color:var(--inv-primary);"></i>Last 7 Days</h6>
                <span class="text-muted" style="font-size:.8rem;">
                    Month to date: <strong><?php echo dashMoney($monthSales); ?></strong>
                    &middot; avg basket <strong><?php echo dashMoney($avgBasket); ?></strong>
                </span>
            </div>
            <div class="d-flex align-items-end gap-2" style="height:170px;">
                <?php foreach ($trendDays as $d => $amount):
                    $pct = max(3, ($amount / $trendMax) * 100);
                    $isToday = ($d === $today);
                ?>
                <div class="flex-fill text-center d-flex flex-column justify-content-end" style="height:100%;">
                    <div class="text-muted" style="font-size:.7rem;white-space:nowrap;"><?php echo $amount > 0 ? number_format($amount / 1000, 1) . 'k' : '-'; ?></div>
                    <div style="height:<?php echo $pct; ?>%;background:<?php echo $isToday ? 'linear-gradient(180deg,var(--inv-primary),var(--inv-primary-dark))' : 'var(--color-border-strong)'; ?>;border-radius:8px 8px 0 0;min-height:4px;"></div>
                    <div class="text-muted mt-1" style="font-size:.7rem;"><?php echo date('D', strtotime($d)); ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- ============ Department split ============ -->
    <div class="col-lg-4">
        <div class="inv-card p-4 h-100">
            <h6 class="mb-3 fw-bold"><i class="fas fa-store me-2" style="color:var(--inv-primary);"></i>Today by Department</h6>
            <?php
            // Every department the shop has, in its own order - not a
            // fixed three. Departments with no takings today still show,
            // at zero, so the split reads as a complete picture.
            $deptFigures = [];
            foreach (catalogActiveDepartments($conn) as $dKey => $dMeta) {
                $deptFigures[$dKey] = (float)($figures['department_sales'][$dKey] ?? 0);
            }
            // Anything sold today under a department that is no longer
            // active still has to appear, or the parts would not add up.
            foreach (($figures['department_sales'] ?? []) as $dKey => $amount) {
                if (!isset($deptFigures[$dKey])) { $deptFigures[$dKey] = (float)$amount; }
            }
            $deptTotal = max(0.01, array_sum($deptFigures));
            foreach ($deptFigures as $key => $amount):
                $meta = $departments[$key] ?? ['icon' => 'fa-boxes-stacked', 'colour' => '#8b9aa2',
                                               'label' => catalogDepartmentLabel($key)];
                $pct  = round(($amount / $deptTotal) * 100);
            ?>
            <div class="mb-3">
                <div class="d-flex justify-content-between" style="font-size:.85rem;">
                    <span><i class="fas <?php echo htmlspecialchars($meta['icon']); ?> me-1" style="color:<?php echo htmlspecialchars($meta['colour']); ?>;"></i><?php echo htmlspecialchars($meta['label']); ?></span>
                    <strong><?php echo dashMoney($amount); ?></strong>
                </div>
                <div style="height:7px;background:var(--color-border);border-radius:20px;margin-top:5px;overflow:hidden;">
                    <div style="width:<?php echo $pct; ?>%;height:100%;background:<?php echo htmlspecialchars($meta['colour']); ?>;"></div>
                </div>
            </div>
            <?php endforeach; ?>
            <hr>
            <div class="d-flex justify-content-between" style="font-size:.85rem;">
                <span class="text-muted">Stock on hand (at cost)</span>
                <strong><?php echo dashMoney($stockValue); ?></strong>
            </div>
            <div class="d-flex justify-content-between mt-1" style="font-size:.85rem;">
                <span class="text-muted">Active items</span>
                <strong><?php echo number_format($totalItems); ?></strong>
            </div>
            <div class="d-flex justify-content-between mt-1" style="font-size:.85rem;">
                <span class="text-muted">Open purchase orders</span>
                <strong><?php echo $openPOs; ?></strong>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <!-- ============ Top sellers ============ -->
    <div class="col-lg-6">
        <div class="inv-card p-0 h-100">
            <div class="p-3 border-bottom"><h6 class="mb-0 fw-bold"><i class="fas fa-trophy me-2" style="color:var(--inv-primary);"></i>Top Sellers This Month</h6></div>
            <div class="table-responsive">
                <table class="inv-table">
                    <thead><tr><th>Product</th><th>Dept</th><th class="text-end">Qty</th><th class="text-end">Revenue</th></tr></thead>
                    <tbody>
                    <?php if (!$topSellers): ?>
                        <tr><td colspan="4"><div class="empty-state"><i class="fas fa-chart-simple d-block"></i>No sales yet this month.</div></td></tr>
                    <?php endif; ?>
                    <?php foreach ($topSellers as $s):
                        $dept = $s['department'] ?: 'general';
                        // A department can be disabled (or, rarely, its row gone) without the sales
                        // history that named it changing - see catalogDepartmentLabel() in CLAUDE.md.
                        $dMeta = $departments[$dept] ?? ['colour' => '#8b9aa2', 'label' => catalogDepartmentLabel($dept)];
                    ?>
                        <tr>
                            <td><?php echo htmlspecialchars($s['item_name']); ?></td>
                            <td><span class="inv-badge" style="background:<?php echo htmlspecialchars($dMeta['colour']); ?>;color:#fff;"><?php echo htmlspecialchars($dMeta['label']); ?></span></td>
                            <td class="text-end"><?php echo invQty($s['qty']); ?></td>
                            <td class="text-end fw-bold"><?php echo dashMoney($s['revenue']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ============ Reorder list ============ -->
    <div class="col-lg-6">
        <div class="inv-card p-0 h-100">
            <div class="p-3 border-bottom d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold"><i class="fas fa-truck-ramp-box me-2" style="color:var(--inv-primary);"></i>Reorder Now</h6>
                <a href="inventory-items.php" style="font-size:.8rem;text-decoration:none;color:var(--inv-primary);">View all</a>
            </div>
            <div class="table-responsive">
                <table class="inv-table">
                    <thead><tr><th>Item</th><th>Dept</th><th class="text-end">In Stock</th><th class="text-end">Reorder At</th></tr></thead>
                    <tbody>
                    <?php if (!$reorderList): ?>
                        <tr><td colspan="4"><div class="empty-state"><i class="fas fa-circle-check d-block"></i>Every item is above its reorder level.</div></td></tr>
                    <?php endif; ?>
                    <?php foreach ($reorderList as $r):
                        $dept = $r['department'] ?: 'general';
                        $dMeta = $departments[$dept] ?? ['colour' => '#8b9aa2', 'label' => catalogDepartmentLabel($dept)];
                    ?>
                        <tr>
                            <td><?php echo htmlspecialchars($r['name']); ?></td>
                            <td><span class="inv-badge" style="background:<?php echo htmlspecialchars($dMeta['colour']); ?>;color:#fff;"><?php echo htmlspecialchars($dMeta['label']); ?></span></td>
                            <td class="text-end">
                                <span class="inv-badge <?php echo (float)$r['current_stock'] <= 0 ? 'bg-danger text-white' : 'bg-warning text-dark'; ?>">
                                    <?php echo invQty($r['current_stock']); ?> <?php echo htmlspecialchars($r['unit'] ?? ''); ?>
                                </span>
                            </td>
                            <td class="text-end text-muted"><?php echo invQty($r['reorder_level']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ============ Recent sales ============ -->
    <div class="col-12">
        <div class="inv-card p-0">
            <div class="p-3 border-bottom d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold"><i class="fas fa-receipt me-2" style="color:var(--inv-primary);"></i>Recent Sales</h6>
                <a href="pos-sales.php" style="font-size:.8rem;text-decoration:none;color:var(--inv-primary);">All sales</a>
            </div>
            <div class="table-responsive">
                <table class="inv-table">
                    <thead><tr><th>Receipt</th><th>Time</th><th>Till</th><th>Cashier</th><th>Payment</th><th class="text-end">Total</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php if (!$recentSales): ?>
                        <tr><td colspan="7"><div class="empty-state"><i class="fas fa-receipt d-block"></i>No sales recorded yet.</div></td></tr>
                    <?php endif; ?>
                    <?php foreach ($recentSales as $s): ?>
                        <tr>
                            <td>
                                <a href="pos-receipt.php?id=<?php echo (int)$s['id']; ?>&amp;noprint=1" target="_blank"
                                   style="color:var(--inv-primary);text-decoration:none;"><?php echo htmlspecialchars($s['receipt_no']); ?></a>
                            </td>
                            <td class="text-muted"><?php echo date('d/m H:i', strtotime($s['created_at'])); ?></td>
                            <td><?php echo htmlspecialchars($s['terminal_name'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($s['cashier_name'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars(posPaymentLabel($s['payment_method'])); ?></td>
                            <td class="text-end fw-bold"><?php echo dashMoney($s['total']); ?></td>
                            <td>
                                <?php if ($s['status'] === 'voided'): ?>
                                    <span class="inv-badge bg-danger text-white">Voided</span>
                                <?php else: ?>
                                    <span class="inv-badge bg-success text-white">Completed</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include 'inventory-footer.php'; ?>
