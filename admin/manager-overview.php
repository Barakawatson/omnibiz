<?php
// ============================================================
// Manager Overview - the manager's landing screen (/manager/overview).
// ------------------------------------------------------------
// Sales performance, daily summaries, cashier and terminal
// performance, inventory overrides and the profit position.
// Deliberately read-heavy: this is the screen a manager checks
// before deciding what to override.
// ============================================================
require_once '../includes/auth.php';
requireModule('manager_overview');
$current_page = basename($_SERVER['PHP_SELF']);
include '../includes/db.php';
require_once '../includes/pos_functions.php';
posBoot($conn);

$departments = catalogDepartments();

// ---- Period selector ---------------------------------------------------
$period = $_GET['period'] ?? 'month';
switch ($period) {
    case 'today':   $from = date('Y-m-d');                          $to = date('Y-m-d'); $label = 'Today'; break;
    case 'week':    $from = date('Y-m-d', strtotime('monday this week')); $to = date('Y-m-d'); $label = 'This Week'; break;
    case 'quarter': $from = date('Y-m-d', strtotime('-90 days'));    $to = date('Y-m-d'); $label = 'Last 90 Days'; break;
    case 'custom':
        $from  = $_GET['from'] ?? date('Y-m-01');
        $to    = $_GET['to']   ?? date('Y-m-d');
        $label = 'Custom Range';
        break;
    case 'month':
    default:
        $period = 'month';
        $from = date('Y-m-01'); $to = date('Y-m-d'); $label = 'This Month';
        break;
}
// A reversed range would silently return nothing; swap instead.
if ($from > $to) { [$from, $to] = [$to, $from]; }

/** One row from a prepared date-ranged query. */
function mgrRow(mysqli $conn, string $sql, string $from, string $to): array {
    $stmt = $conn->prepare($sql);
    if (!$stmt) { return []; }
    $stmt->bind_param('ss', $from, $to);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    return $row;
}

/** All rows from a prepared date-ranged query. */
function mgrRows(mysqli $conn, string $sql, string $from, string $to): array {
    $stmt = $conn->prepare($sql);
    if (!$stmt) { return []; }
    $stmt->bind_param('ss', $from, $to);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

// ---- Headline sales ----------------------------------------------------
$sales = mgrRow($conn,
    "SELECT COUNT(*) AS sale_count,
            COALESCE(SUM(total), 0)        AS revenue,
            COALESCE(SUM(tax_amount), 0)   AS tax,
            COALESCE(SUM(total_cost), 0)   AS cost,
            COALESCE(SUM(gross_profit), 0) AS profit,
            COALESCE(SUM(discount), 0)     AS discounts,
            COALESCE(AVG(total), 0)        AS avg_basket
     FROM sales_transactions
     WHERE status = 'completed' AND DATE(created_at) BETWEEN ? AND ?", $from, $to);

$voided = mgrRow($conn,
    "SELECT COUNT(*) AS c, COALESCE(SUM(total), 0) AS v
     FROM sales_transactions
     WHERE status = 'voided' AND DATE(created_at) BETWEEN ? AND ?", $from, $to);

$marginPct = ((float)($sales['revenue'] ?? 0) > 0)
    ? round(((float)$sales['profit'] / (float)$sales['revenue']) * 100, 1)
    : 0;

// ---- Expenses and the bottom line -------------------------------------
$expenses = mgrRow($conn,
    "SELECT COALESCE(SUM(amount), 0) AS total FROM acc_expenses
     WHERE deleted_at IS NULL AND expense_date BETWEEN ? AND ?", $from, $to);
$netProfit = round((float)($sales['profit'] ?? 0) - (float)($expenses['total'] ?? 0), 2);

// ---- Daily summaries ---------------------------------------------------
$daily = mgrRows($conn,
    "SELECT DATE(created_at) AS d,
            COUNT(*) AS sale_count,
            COALESCE(SUM(total), 0)        AS revenue,
            COALESCE(SUM(gross_profit), 0) AS profit
     FROM sales_transactions
     WHERE status = 'completed' AND DATE(created_at) BETWEEN ? AND ?
     GROUP BY DATE(created_at) ORDER BY d DESC LIMIT 31", $from, $to);

// Which of those days have been formally closed.
$closedDays = [];
$closeRes = @$conn->query("SELECT close_date, variance FROM acc_daily_close");
if ($closeRes instanceof mysqli_result) {
    foreach ($closeRes->fetch_all(MYSQLI_ASSOC) as $c) { $closedDays[$c['close_date']] = (float)$c['variance']; }
}

// ---- By department -----------------------------------------------------
$byDept = mgrRows($conn,
    "SELECT COALESCE(i.department, 'general') AS department,
            SUM(li.line_total) AS revenue,
            SUM(li.quantity)   AS qty,
            SUM(li.quantity * li.unit_cost) AS cost
     FROM sales_transaction_items li
     JOIN sales_transactions t ON t.id = li.transaction_id AND t.status = 'completed'
     LEFT JOIN inv_items i ON i.id = li.item_id
     WHERE DATE(t.created_at) BETWEEN ? AND ?
     GROUP BY COALESCE(i.department, 'general')", $from, $to);

// ---- By cashier --------------------------------------------------------
$byCashier = mgrRows($conn,
    "SELECT t.cashier_name,
            COUNT(*) AS sale_count,
            COALESCE(SUM(t.total), 0)        AS revenue,
            COALESCE(SUM(t.gross_profit), 0) AS profit,
            COALESCE(SUM(t.discount), 0)     AS discounts,
            SUM(CASE WHEN t.status = 'voided' THEN 1 ELSE 0 END) AS voids
     FROM sales_transactions t
     WHERE DATE(t.created_at) BETWEEN ? AND ?
     GROUP BY t.cashier_name ORDER BY revenue DESC", $from, $to);

// Cancelled carts per cashier - same fraud-oversight audience as the
// Cancelled Carts report (this whole page already requires
// manager_overview, which only admin/manager hold, so no extra gate is
// needed here). Merged in by name rather than joined in SQL: the two
// tables share no key besides cashier_id/name, and a cashier who
// cancelled carts without completing any sale in the period must still
// show up, not be silently dropped by an inner join.
if (userCan('fraud_audit')) {
    $cancelledByCashier = [];
    foreach (mgrRows($conn,
        "SELECT COALESCE(cashier_name, 'Unknown') AS cashier_name,
                COUNT(*) AS cancelled_count,
                COALESCE(SUM(total_value), 0) AS cancelled_value
         FROM cancelled_carts
         WHERE DATE(created_at) BETWEEN ? AND ?
         GROUP BY COALESCE(cashier_name, 'Unknown')", $from, $to) as $cc) {
        $cancelledByCashier[$cc['cashier_name']] = $cc;
    }
    foreach ($byCashier as &$c) {
        $name = $c['cashier_name'] ?: 'Unknown';
        $c['cancelled_count'] = (int)($cancelledByCashier[$name]['cancelled_count'] ?? 0);
        $c['cancelled_value'] = (float)($cancelledByCashier[$name]['cancelled_value'] ?? 0);
        unset($cancelledByCashier[$name]);
    }
    unset($c);
    // Anyone left in $cancelledByCashier cancelled carts but made no
    // completed/voided sale in this period, so never appeared above.
    foreach ($cancelledByCashier as $name => $cc) {
        $byCashier[] = [
            'cashier_name' => $name, 'sale_count' => 0, 'revenue' => 0, 'profit' => 0,
            'discounts' => 0, 'voids' => 0,
            'cancelled_count' => (int)$cc['cancelled_count'], 'cancelled_value' => (float)$cc['cancelled_value'],
        ];
    }
}

// ---- By terminal -------------------------------------------------------
$byTerminal = mgrRows($conn,
    "SELECT COALESCE(term.name, 'Unassigned') AS terminal_name,
            COUNT(*) AS sale_count,
            COALESCE(SUM(t.total), 0) AS revenue
     FROM sales_transactions t
     LEFT JOIN pos_terminals term ON term.id = t.terminal_id
     WHERE t.status = 'completed' AND DATE(t.created_at) BETWEEN ? AND ?
     GROUP BY COALESCE(term.name, 'Unassigned') ORDER BY revenue DESC", $from, $to);

// ---- Inventory position (not date-ranged) ------------------------------
// Stock position covers the current range only - a department that is
// not trading is not part of what the shop stocks today. The sales and
// profit figures above are NOT filtered: they are financial history.
$invRow = @$conn->query(
    "SELECT COUNT(*) AS items,
            COALESCE(SUM(i.current_stock * i.average_cost), 0) AS value,
            SUM(i.current_stock <= 0) AS out_of_stock,
            SUM(i.current_stock > 0 AND i.reorder_level > 0 AND i.current_stock <= i.reorder_level) AS low
     FROM inv_items i WHERE i.deleted_at IS NULL AND i.status = 'active'"
     . catalogDepartmentFilterSql($conn, 'i'));
$inv = ($invRow instanceof mysqli_result) ? ($invRow->fetch_assoc() ?: []) : [];

// ---- Slow movers: on sale, in stock, nothing sold in the period --------
$slowMovers = mgrRows($conn,
    "SELECT i.id, i.name, i.department, i.current_stock, i.average_cost, u.abbreviation AS unit
     FROM inv_items i
     JOIN retail_product_details d ON d.item_id = i.id
     LEFT JOIN inv_units u ON u.id = i.unit_id
     WHERE i.deleted_at IS NULL AND i.status = 'active'
       AND d.usage_type IN ('sale','both') AND i.current_stock > 0
       " . catalogDepartmentFilterSql($conn, 'i') . "
       AND NOT EXISTS (
           SELECT 1 FROM sales_transaction_items li
           JOIN sales_transactions t ON t.id = li.transaction_id AND t.status = 'completed'
           WHERE li.item_id = i.id AND DATE(t.created_at) BETWEEN ? AND ?
       )
     ORDER BY (i.current_stock * i.average_cost) DESC LIMIT 10", $from, $to);

$pageTitle = 'Manager Overview';
include 'inventory-header.php';

function mgrMoney($v) { return 'Tsh ' . number_format((float)$v); }
?>
<div class="inv-page-header">
    <div>
        <h1><i class="fas fa-binoculars" style="color:var(--color-primary);font-size:1rem;margin-right:6px;"></i>Manager Overview</h1>
        <div class="subtitle"><?php echo htmlspecialchars($label); ?> &middot; <?php echo date('d M Y', strtotime($from)); ?> to <?php echo date('d M Y', strtotime($to)); ?></div>
    </div>
    <form method="get" class="d-flex gap-2 align-items-center flex-wrap">
        <select name="period" class="form-select form-select-sm" style="width:auto;border-radius:10px;" onchange="if(this.value!=='custom')this.form.submit();">
            <option value="today"   <?php echo $period === 'today' ? 'selected' : ''; ?>>Today</option>
            <option value="week"    <?php echo $period === 'week' ? 'selected' : ''; ?>>This Week</option>
            <option value="month"   <?php echo $period === 'month' ? 'selected' : ''; ?>>This Month</option>
            <option value="quarter" <?php echo $period === 'quarter' ? 'selected' : ''; ?>>Last 90 Days</option>
            <option value="custom"  <?php echo $period === 'custom' ? 'selected' : ''; ?>>Custom…</option>
        </select>
        <?php if ($period === 'custom'): ?>
        <input type="date" name="from" value="<?php echo htmlspecialchars($from); ?>" class="form-control form-control-sm" style="width:auto;border-radius:10px;">
        <input type="date" name="to"   value="<?php echo htmlspecialchars($to); ?>"   class="form-control form-control-sm" style="width:auto;border-radius:10px;">
        <button class="btn btn-sm btn-inv">Apply</button>
        <?php endif; ?>
    </form>
</div>

<!-- ============ Headline ============ -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="inv-stat-card bg-grad-teal">
            <div class="d-flex justify-content-between">
                <div>
                    <div class="label">Revenue</div>
                    <div class="value"><?php echo mgrMoney($sales['revenue'] ?? 0); ?></div>
                    <div class="label"><?php echo (int)($sales['sale_count'] ?? 0); ?> sale(s)</div>
                </div>
                <i class="fas fa-sack-dollar icon"></i>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="inv-stat-card bg-grad-green">
            <div class="d-flex justify-content-between">
                <div>
                    <div class="label">Gross Profit</div>
                    <div class="value"><?php echo mgrMoney($sales['profit'] ?? 0); ?></div>
                    <div class="label"><?php echo $marginPct; ?>% margin</div>
                </div>
                <i class="fas fa-arrow-trend-up icon"></i>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="inv-stat-card bg-grad-amber">
            <div class="d-flex justify-content-between">
                <div>
                    <div class="label">Expenses</div>
                    <div class="value"><?php echo mgrMoney($expenses['total'] ?? 0); ?></div>
                    <div class="label">In this period</div>
                </div>
                <i class="fas fa-money-bill-wave icon"></i>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="inv-stat-card <?php echo $netProfit >= 0 ? 'bg-grad-blue' : 'bg-grad-red'; ?>">
            <div class="d-flex justify-content-between">
                <div>
                    <div class="label">Net Profit</div>
                    <div class="value"><?php echo mgrMoney($netProfit); ?></div>
                    <div class="label">Profit less expenses</div>
                </div>
                <i class="fas fa-scale-balanced icon"></i>
            </div>
        </div>
    </div>
</div>

<!-- ============ Secondary figures ============ -->
<div class="row g-3 mb-4">
    <div class="col-md-8">
        <div class="inv-card p-4 h-100">
            <h6 class="fw-bold mb-3"><i class="fas fa-store me-2" style="color:var(--inv-primary);"></i>Performance by Department</h6>
            <?php
            $deptMap = [];
            foreach ($byDept as $d) { $deptMap[$d['department']] = $d; }
            $deptGrand = max(0.01, array_sum(array_column($byDept, 'revenue')));
            ?>
            <div class="table-responsive">
                <table class="inv-table">
                    <thead><tr><th>Department</th><th class="text-end">Units</th><th class="text-end">Revenue</th><th class="text-end">Cost</th><th class="text-end">Profit</th><th class="text-end">Margin</th><th style="width:120px;">Share</th></tr></thead>
                    <tbody>
                    <?php foreach ($departments as $key => $meta):
                        $row = $deptMap[$key] ?? ['revenue' => 0, 'qty' => 0, 'cost' => 0];
                        $rev = (float)$row['revenue']; $cost = (float)$row['cost'];
                        $profit = $rev - $cost;
                        $margin = $rev > 0 ? round(($profit / $rev) * 100, 1) : 0;
                        $share = round(($rev / $deptGrand) * 100);
                    ?>
                        <tr>
                            <td><i class="fas <?php echo htmlspecialchars($meta['icon']); ?> me-1" style="color:<?php echo htmlspecialchars($meta['colour']); ?>;"></i><?php echo htmlspecialchars($meta['label']); ?></td>
                            <td class="text-end"><?php echo invQty($row['qty']); ?></td>
                            <td class="text-end fw-bold"><?php echo mgrMoney($rev); ?></td>
                            <td class="text-end text-muted"><?php echo mgrMoney($cost); ?></td>
                            <td class="text-end"><?php echo mgrMoney($profit); ?></td>
                            <td class="text-end"><?php echo $margin; ?>%</td>
                            <td>
                                <div style="height:7px;background:var(--color-border);border-radius:20px;overflow:hidden;">
                                    <div style="width:<?php echo $share; ?>%;height:100%;background:<?php echo htmlspecialchars($meta['colour']); ?>;"></div>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="inv-card p-4 h-100">
            <h6 class="fw-bold mb-3"><i class="fas fa-boxes-stacked me-2" style="color:var(--inv-primary);"></i>Inventory Position</h6>
            <div class="d-flex justify-content-between mb-2" style="font-size:.88rem;">
                <span class="text-muted">Stock value (at cost)</span><strong><?php echo mgrMoney($inv['value'] ?? 0); ?></strong>
            </div>
            <div class="d-flex justify-content-between mb-2" style="font-size:.88rem;">
                <span class="text-muted">Active items</span><strong><?php echo number_format((int)($inv['items'] ?? 0)); ?></strong>
            </div>
            <div class="d-flex justify-content-between mb-2" style="font-size:.88rem;">
                <span class="text-muted">Out of stock</span>
                <strong class="<?php echo (int)($inv['out_of_stock'] ?? 0) > 0 ? 'text-danger' : ''; ?>"><?php echo (int)($inv['out_of_stock'] ?? 0); ?></strong>
            </div>
            <div class="d-flex justify-content-between mb-3" style="font-size:.88rem;">
                <span class="text-muted">Below reorder level</span>
                <strong class="<?php echo (int)($inv['low'] ?? 0) > 0 ? 'text-warning' : ''; ?>"><?php echo (int)($inv['low'] ?? 0); ?></strong>
            </div>
            <hr>
            <div class="d-flex justify-content-between mb-2" style="font-size:.88rem;">
                <span class="text-muted">Discounts given</span><strong><?php echo mgrMoney($sales['discounts'] ?? 0); ?></strong>
            </div>
            <div class="d-flex justify-content-between mb-2" style="font-size:.88rem;">
                <span class="text-muted">Voided sales</span>
                <strong class="<?php echo (int)($voided['c'] ?? 0) > 0 ? 'text-danger' : ''; ?>">
                    <?php echo (int)($voided['c'] ?? 0); ?> (<?php echo mgrMoney($voided['v'] ?? 0); ?>)
                </strong>
            </div>
            <div class="d-flex justify-content-between" style="font-size:.88rem;">
                <span class="text-muted">Average basket</span><strong><?php echo mgrMoney($sales['avg_basket'] ?? 0); ?></strong>
            </div>
            <div class="mt-3 d-grid gap-2">
                <a href="inventory-items.php" class="btn btn-sm btn-outline-secondary" style="border-radius:10px;"><i class="fas fa-sliders me-1"></i>Inventory Overrides</a>
                <a href="z-report.php" class="btn btn-sm btn-inv"><i class="fas fa-file-invoice me-1"></i>Daily Close</a>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <!-- ============ Cashier performance ============ -->
    <div class="col-lg-7">
        <div class="inv-card p-0 h-100">
            <div class="p-3 border-bottom"><h6 class="mb-0 fw-bold"><i class="fas fa-user-tie me-2" style="color:var(--inv-primary);"></i>Cashier Performance</h6></div>
            <div class="table-responsive">
                <table class="inv-table">
                    <thead><tr><th>Cashier</th><th class="text-end">Sales</th><th class="text-end">Revenue</th><th class="text-end">Profit</th><th class="text-end">Discounts</th><th class="text-end">Voids</th><?php if (userCan('fraud_audit')): ?><th class="text-end">Cancelled</th><?php endif; ?></tr></thead>
                    <tbody>
                    <?php if (!$byCashier): ?>
                        <tr><td colspan="<?php echo userCan('fraud_audit') ? 7 : 6; ?>"><div class="empty-state"><i class="fas fa-user-slash d-block"></i>No sales in this period.</div></td></tr>
                    <?php endif; ?>
                    <?php foreach ($byCashier as $c): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($c['cashier_name'] ?: 'Unknown'); ?></td>
                            <td class="text-end"><?php echo (int)$c['sale_count']; ?></td>
                            <td class="text-end fw-bold"><?php echo mgrMoney($c['revenue']); ?></td>
                            <td class="text-end"><?php echo mgrMoney($c['profit']); ?></td>
                            <td class="text-end <?php echo (float)$c['discounts'] > 0 ? 'text-warning' : 'text-muted'; ?>"><?php echo mgrMoney($c['discounts']); ?></td>
                            <td class="text-end <?php echo (int)$c['voids'] > 0 ? 'text-danger fw-bold' : 'text-muted'; ?>"><?php echo (int)$c['voids']; ?></td>
                            <?php if (userCan('fraud_audit')): ?>
                            <td class="text-end <?php echo (int)($c['cancelled_count'] ?? 0) > 0 ? 'text-danger fw-bold' : 'text-muted'; ?>"
                                title="<?php echo (int)($c['cancelled_count'] ?? 0) > 0 ? htmlspecialchars(mgrMoney($c['cancelled_value'] ?? 0)) . ' discarded' : ''; ?>">
                                <?php echo (int)($c['cancelled_count'] ?? 0); ?>
                            </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ============ Terminal performance ============ -->
    <div class="col-lg-5">
        <div class="inv-card p-0 h-100">
            <div class="p-3 border-bottom"><h6 class="mb-0 fw-bold"><i class="fas fa-desktop me-2" style="color:var(--inv-primary);"></i>Terminal Performance</h6></div>
            <div class="table-responsive">
                <table class="inv-table">
                    <thead><tr><th>Terminal</th><th class="text-end">Sales</th><th class="text-end">Revenue</th></tr></thead>
                    <tbody>
                    <?php if (!$byTerminal): ?>
                        <tr><td colspan="3"><div class="empty-state"><i class="fas fa-desktop d-block"></i>No sales in this period.</div></td></tr>
                    <?php endif; ?>
                    <?php foreach ($byTerminal as $t): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($t['terminal_name']); ?></td>
                            <td class="text-end"><?php echo (int)$t['sale_count']; ?></td>
                            <td class="text-end fw-bold"><?php echo mgrMoney($t['revenue']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <!-- ============ Daily summaries ============ -->
    <div class="col-lg-7">
        <div class="inv-card p-0 h-100">
            <div class="p-3 border-bottom"><h6 class="mb-0 fw-bold"><i class="fas fa-calendar-day me-2" style="color:var(--inv-primary);"></i>Daily Summaries</h6></div>
            <div class="table-responsive" style="max-height:420px;overflow-y:auto;">
                <table class="inv-table">
                    <thead><tr><th>Date</th><th class="text-end">Sales</th><th class="text-end">Revenue</th><th class="text-end">Profit</th><th>Close</th></tr></thead>
                    <tbody>
                    <?php if (!$daily): ?>
                        <tr><td colspan="5"><div class="empty-state"><i class="fas fa-calendar-xmark d-block"></i>No trading days in this period.</div></td></tr>
                    <?php endif; ?>
                    <?php foreach ($daily as $d): $closed = array_key_exists($d['d'], $closedDays); ?>
                        <tr>
                            <td><?php echo date('D, d M Y', strtotime($d['d'])); ?></td>
                            <td class="text-end"><?php echo (int)$d['sale_count']; ?></td>
                            <td class="text-end fw-bold"><?php echo mgrMoney($d['revenue']); ?></td>
                            <td class="text-end"><?php echo mgrMoney($d['profit']); ?></td>
                            <td>
                                <?php if (!$closed): ?>
                                    <span class="inv-badge bg-warning text-dark">Open</span>
                                <?php elseif (abs($closedDays[$d['d']]) < 0.01): ?>
                                    <span class="inv-badge bg-success text-white">Balanced</span>
                                <?php else: ?>
                                    <span class="inv-badge bg-danger text-white">
                                        <?php echo $closedDays[$d['d']] < 0 ? 'Short' : 'Over'; ?>
                                        <?php echo number_format(abs($closedDays[$d['d']])); ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ============ Slow movers ============ -->
    <div class="col-lg-5">
        <div class="inv-card p-0 h-100">
            <div class="p-3 border-bottom">
                <h6 class="mb-0 fw-bold"><i class="fas fa-hourglass-half me-2" style="color:var(--inv-primary);"></i>Slow Movers</h6>
                <div class="text-muted" style="font-size:.76rem;">In stock, on sale, nothing sold in this period - capital sitting still.</div>
            </div>
            <div class="table-responsive" style="max-height:420px;overflow-y:auto;">
                <table class="inv-table">
                    <thead><tr><th>Item</th><th class="text-end">Stock</th><th class="text-end">Tied-up Cost</th></tr></thead>
                    <tbody>
                    <?php if (!$slowMovers): ?>
                        <tr><td colspan="3"><div class="empty-state"><i class="fas fa-circle-check d-block"></i>Everything on sale moved in this period.</div></td></tr>
                    <?php endif; ?>
                    <?php foreach ($slowMovers as $s): ?>
                        <tr>
                            <td>
                                <?php echo htmlspecialchars($s['name']); ?>
                                <div class="text-muted" style="font-size:.72rem;"><?php echo htmlspecialchars(catalogDepartmentLabel($s['department'])); ?></div>
                            </td>
                            <td class="text-end"><?php echo invQty($s['current_stock']); ?> <?php echo htmlspecialchars($s['unit'] ?? ''); ?></td>
                            <td class="text-end fw-bold"><?php echo mgrMoney((float)$s['current_stock'] * (float)$s['average_cost']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include 'inventory-footer.php'; ?>
