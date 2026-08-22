<?php
// ============================================================
// Accounting Overview - the entry point to the finance module.
// ------------------------------------------------------------
// Cash position, this month's trading, what still needs closing,
// and the most recent ledger activity.
// ============================================================
require_once '../includes/auth.php';
requireModule('accounting');
$current_page = basename($_SERVER['PHP_SELF']);
include '../includes/db.php';
require_once '../includes/pos_functions.php';
posBoot($conn);

$monthStart = date('Y-m-01');
$today      = date('Y-m-d');
$codes      = accSystemAccounts();

// ---- Cash position (all time, so it reads as a real balance) ----------
$cashBalance   = accAccountBalance($conn, accAccountId($conn, $codes['cash']));
$mobileBalance = accAccountBalance($conn, accAccountId($conn, $codes['mobile_money']));
$stockValue    = accAccountBalance($conn, accAccountId($conn, $codes['inventory']));

// ---- This month, from the ledger --------------------------------------
$pl = accProfitAndLoss($conn, $monthStart, $today);
$cogs = 0.0;
foreach ($pl['expenses'] as $e) {
    if ($e['code'] === $codes['cogs']) { $cogs = (float)$e['amount']; }
}
$grossProfit = round($pl['total_revenue'] - $cogs, 2);

// ---- Days still open --------------------------------------------------
// Any day that traded but was never closed. An unclosed day means the
// drawer was never counted against the books.
$openDays = [];
$openRes = @$conn->query(
    "SELECT DATE(t.created_at) AS d,
            COUNT(*) AS sales,
            COALESCE(SUM(t.total), 0) AS revenue
     FROM sales_transactions t
     WHERE t.status = 'completed'
       AND DATE(t.created_at) < CURDATE()
       AND NOT EXISTS (SELECT 1 FROM acc_daily_close c WHERE c.close_date = DATE(t.created_at))
     GROUP BY DATE(t.created_at)
     ORDER BY d DESC LIMIT 10");
if ($openRes instanceof mysqli_result) { $openDays = $openRes->fetch_all(MYSQLI_ASSOC); }

// ---- Recent ledger activity -------------------------------------------
$recentEntries = accGetJournal($conn, '', '', '', 10);

// ---- Today, live ------------------------------------------------------
$todayFigures = accDayFigures($conn, $today);
$todayClosed  = accGetClose($conn, $today) !== null;

$sourceLabels = [
    'pos_sale' => 'Till sale', 'pos_void' => 'Sale voided', 'expense' => 'Expense',
    'daily_close' => 'Daily close', 'reversal' => 'Reversal', 'manual' => 'Manual entry',
];

$pageTitle = 'Accounting';
$breadcrumbs = [['Dashboard', 'index.php'], ['Accounting']];
include 'inventory-header.php';

function acMoney($v) { return 'Tsh ' . number_format((float)$v); }
?>
<div class="inv-page-header">
    <div>
        <h1><i class="fas fa-book" style="color:var(--color-primary);font-size:1rem;margin-right:6px;"></i>Accounting</h1>
        <div class="subtitle">Month to date &middot; <?php echo date('d M Y', strtotime($monthStart)); ?> to <?php echo date('d M Y'); ?></div>
    </div>
    <div class="d-flex gap-2">
        <a href="expenses.php" class="btn btn-outline-secondary" style="border-radius:10px;"><i class="fas fa-money-bill-wave me-1"></i>Record Expense</a>
        <a href="z-report.php" class="btn btn-inv"><i class="fas fa-cash-register me-1"></i>Daily Close</a>
    </div>
</div>

<?php if ($openDays): ?>
<div class="alert alert-warning" style="border-radius:12px;">
    <i class="fas fa-triangle-exclamation me-1"></i>
    <strong><?php echo count($openDays); ?></strong> past trading day(s) were never closed, so their cash was never
    reconciled against the books. Close them from the <a href="z-report.php">Z-Report</a> page.
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="inv-stat-card bg-grad-teal">
            <div class="d-flex justify-content-between">
                <div><div class="label">Cash on Hand</div><div class="value"><?php echo acMoney($cashBalance); ?></div>
                <div class="label">Per the ledger</div></div>
                <i class="fas fa-wallet icon"></i>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="inv-stat-card bg-grad-purple">
            <div class="d-flex justify-content-between">
                <div><div class="label">Mobile Money</div><div class="value"><?php echo acMoney($mobileBalance); ?></div>
                <div class="label">Lipa Namba</div></div>
                <i class="fas fa-mobile-screen icon"></i>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="inv-stat-card bg-grad-green">
            <div class="d-flex justify-content-between">
                <div><div class="label">Gross Profit (MTD)</div><div class="value"><?php echo acMoney($grossProfit); ?></div>
                <div class="label">Revenue <?php echo acMoney($pl['total_revenue']); ?></div></div>
                <i class="fas fa-arrow-trend-up icon"></i>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="inv-stat-card <?php echo $pl['net_profit'] >= 0 ? 'bg-grad-blue' : 'bg-grad-red'; ?>">
            <div class="d-flex justify-content-between">
                <div><div class="label">Net <?php echo $pl['net_profit'] >= 0 ? 'Profit' : 'Loss'; ?> (MTD)</div>
                <div class="value"><?php echo acMoney(abs($pl['net_profit'])); ?></div>
                <div class="label">After all expenses</div></div>
                <i class="fas fa-scale-balanced icon"></i>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <!-- ============ Today ============ -->
    <div class="col-lg-5">
        <div class="inv-card p-4 h-100">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="mb-0 fw-bold"><i class="fas fa-sun me-2" style="color:var(--inv-primary);"></i>Today</h6>
                <?php if ($todayClosed): ?>
                    <span class="inv-badge bg-secondary text-white"><i class="fas fa-lock me-1"></i>Closed</span>
                <?php else: ?>
                    <span class="inv-badge bg-success text-white">Trading</span>
                <?php endif; ?>
            </div>
            <table class="table table-sm mb-0" style="font-size:.88rem;">
                <tr><td class="text-muted">Sales</td><td class="text-end"><?php echo (int)$todayFigures['sales_count']; ?></td></tr>
                <tr><td class="text-muted">Net sales</td><td class="text-end fw-bold"><?php echo acMoney($todayFigures['net_sales']); ?></td></tr>
                <tr><td class="text-muted">Cost of sales</td><td class="text-end"><?php echo acMoney($todayFigures['cost_of_sales']); ?></td></tr>
                <tr><td class="text-muted">Gross profit</td><td class="text-end text-success fw-bold"><?php echo acMoney($todayFigures['gross_profit']); ?></td></tr>
                <tr><td class="text-muted">Cash taken</td><td class="text-end"><?php echo acMoney($todayFigures['cash_sales']); ?></td></tr>
                <tr><td class="text-muted">Cash paid out</td><td class="text-end text-danger"><?php echo acMoney($todayFigures['cash_expenses']); ?></td></tr>
                <tr style="border-top:2px solid var(--color-border-strong);"><td class="fw-bold">Expected in drawer</td><td class="text-end fw-bold"><?php echo acMoney($todayFigures['expected_cash']); ?></td></tr>
            </table>
            <div class="mt-3 d-grid">
                <a href="z-report.php" class="btn btn-sm btn-inv"><i class="fas fa-file-invoice me-1"></i>Open Z-Report</a>
            </div>
        </div>
    </div>

    <!-- ============ Unclosed days ============ -->
    <div class="col-lg-7">
        <div class="inv-card p-0 h-100">
            <div class="p-3 border-bottom"><h6 class="mb-0 fw-bold"><i class="fas fa-calendar-xmark me-2" style="color:var(--inv-primary);"></i>Days Awaiting Close</h6></div>
            <div class="table-responsive">
                <table class="inv-table">
                    <thead><tr><th>Date</th><th class="text-end">Sales</th><th class="text-end">Revenue</th><th style="width:100px;"></th></tr></thead>
                    <tbody>
                    <?php if (!$openDays): ?>
                        <tr><td colspan="4"><div class="empty-state"><i class="fas fa-circle-check d-block"></i>Every past trading day has been closed.</div></td></tr>
                    <?php endif; ?>
                    <?php foreach ($openDays as $d): ?>
                        <tr>
                            <td><?php echo date('D, d M Y', strtotime($d['d'])); ?></td>
                            <td class="text-end"><?php echo (int)$d['sales']; ?></td>
                            <td class="text-end fw-bold"><?php echo acMoney($d['revenue']); ?></td>
                            <td><a href="z-report.php?date=<?php echo urlencode($d['d']); ?>" class="btn btn-sm btn-inv">Close</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <!-- ============ Recent ledger activity ============ -->
    <div class="col-lg-8">
        <div class="inv-card p-0">
            <div class="p-3 border-bottom d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold"><i class="fas fa-book-open me-2" style="color:var(--inv-primary);"></i>Recent Ledger Activity</h6>
                <a href="journal.php" style="font-size:.8rem;text-decoration:none;color:var(--inv-primary);">Full ledger</a>
            </div>
            <div class="table-responsive">
                <table class="inv-table">
                    <thead><tr><th>Entry</th><th>Date</th><th>Memo</th><th>Source</th><th class="text-end">Amount</th></tr></thead>
                    <tbody>
                    <?php if (!$recentEntries): ?>
                        <tr><td colspan="5"><div class="empty-state"><i class="fas fa-book d-block"></i>Nothing posted to the ledger yet.</div></td></tr>
                    <?php endif; ?>
                    <?php foreach ($recentEntries as $e): ?>
                        <tr>
                            <td><a href="journal-entry.php?id=<?php echo (int)$e['id']; ?>" style="color:var(--inv-primary);text-decoration:none;"><code style="font-size:.78rem;"><?php echo htmlspecialchars($e['entry_no']); ?></code></a></td>
                            <td><?php echo date('d/m/Y', strtotime($e['entry_date'])); ?></td>
                            <td style="font-size:.85rem;"><?php echo htmlspecialchars($e['memo'] ?: '-'); ?></td>
                            <td><span class="inv-badge bg-light text-dark"><?php echo htmlspecialchars($sourceLabels[$e['source_type']] ?? $e['source_type']); ?></span></td>
                            <td class="text-end fw-bold"><?php echo number_format((float)$e['total_debit'], 2); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ============ Quick links ============ -->
    <div class="col-lg-4">
        <div class="inv-card p-4 h-100">
            <h6 class="fw-bold mb-3"><i class="fas fa-compass me-2" style="color:var(--inv-primary);"></i>Reports &amp; Setup</h6>
            <div class="d-grid gap-2">
                <a href="profit-loss.php" class="btn btn-outline-secondary text-start" style="border-radius:10px;">
                    <i class="fas fa-chart-line me-2" style="color:var(--inv-primary);"></i>Profit &amp; Loss statement
                </a>
                <a href="journal.php?view=trial" class="btn btn-outline-secondary text-start" style="border-radius:10px;">
                    <i class="fas fa-scale-balanced me-2" style="color:var(--inv-primary);"></i>Trial balance
                </a>
                <a href="expenses.php" class="btn btn-outline-secondary text-start" style="border-radius:10px;">
                    <i class="fas fa-money-bill-wave me-2" style="color:var(--inv-primary);"></i>Expenses
                </a>
                <?php if (userCan('accounting_manage')): ?>
                <a href="chart-of-accounts.php" class="btn btn-outline-secondary text-start" style="border-radius:10px;">
                    <i class="fas fa-sitemap me-2" style="color:var(--inv-primary);"></i>Chart of accounts
                </a>
                <?php endif; ?>
            </div>
            <hr>
            <div class="d-flex justify-content-between" style="font-size:.86rem;">
                <span class="text-muted">Inventory asset (ledger)</span>
                <strong><?php echo acMoney($stockValue); ?></strong>
            </div>
            <div class="text-muted mt-2" style="font-size:.76rem;">
                This is the stock value the books carry. It should track the
                <a href="inventory-reports.php">inventory valuation</a>; a persistent gap
                means stock moved without a matching ledger posting.
            </div>
        </div>
    </div>
</div>

<?php include 'inventory-footer.php'; ?>
