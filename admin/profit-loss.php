<?php
// ============================================================
// Profit & Loss
// ------------------------------------------------------------
// Read straight from the ledger (accProfitAndLoss), not from the
// till, so it includes everything: sales, cost of goods, expenses,
// cash variances and any manual corrections.
// ============================================================
require_once '../includes/auth.php';
requireModule('accounting');
$current_page = basename($_SERVER['PHP_SELF']);
include '../includes/db.php';
require_once '../includes/pos_functions.php';
posBoot($conn);

// ---- Period ------------------------------------------------------------
$preset = $_GET['preset'] ?? 'month';
switch ($preset) {
    case 'last_month':
        $from = date('Y-m-01', strtotime('first day of last month'));
        $to   = date('Y-m-t',  strtotime('last day of last month'));
        $label = 'Last Month'; break;
    case 'year':
        $from = date('Y-01-01'); $to = date('Y-m-d'); $label = 'This Year'; break;
    case 'custom':
        $from = $_GET['from'] ?? date('Y-m-01');
        $to   = $_GET['to']   ?? date('Y-m-d');
        $label = 'Custom Range'; break;
    case 'month':
    default:
        $preset = 'month';
        $from = date('Y-m-01'); $to = date('Y-m-d'); $label = 'This Month'; break;
}
if ($from > $to) { [$from, $to] = [$to, $from]; }

$pl = accProfitAndLoss($conn, $from, $to);

// Cost of sales is an expense account, but on a retail P&L it belongs
// above the gross-profit line, not among operating costs.
$cogsCodes = [accSystemAccounts()['cogs']];
$costOfSales = 0.0;
$operating = [];
foreach ($pl['expenses'] as $e) {
    if (in_array($e['code'], $cogsCodes, true)) { $costOfSales += (float)$e['amount']; }
    else { $operating[] = $e; }
}
$operatingTotal = array_sum(array_column($operating, 'amount'));
$grossProfit    = round($pl['total_revenue'] - $costOfSales, 2);
$netProfit      = round($grossProfit - $operatingTotal, 2);
$grossMargin    = $pl['total_revenue'] > 0 ? round(($grossProfit / $pl['total_revenue']) * 100, 1) : 0;
$netMargin      = $pl['total_revenue'] > 0 ? round(($netProfit / $pl['total_revenue']) * 100, 1) : 0;

$pageTitle = 'Profit & Loss';
$breadcrumbs = [['Dashboard', 'index.php'], ['Accounting', 'accounting-dashboard.php'], ['Profit & Loss']];
include 'inventory-header.php';

function plMoney($v) { return number_format((float)$v, 2); }
?>
<div class="inv-page-header">
    <div>
        <h1><i class="fas fa-chart-line me-2" style="color:var(--inv-primary);"></i>Profit &amp; Loss</h1>
        <div class="subtitle"><?php echo htmlspecialchars($label); ?> &middot; <?php echo date('d M Y', strtotime($from)); ?> to <?php echo date('d M Y', strtotime($to)); ?></div>
    </div>
    <div class="d-flex gap-2 align-items-center">
        <form method="get" class="d-flex gap-2 align-items-center">
            <select name="preset" class="form-select form-select-sm" style="width:auto;border-radius:10px;" onchange="if(this.value!=='custom')this.form.submit();" aria-label="Report period">
                <option value="month"      <?php echo $preset === 'month' ? 'selected' : ''; ?>>This Month</option>
                <option value="last_month" <?php echo $preset === 'last_month' ? 'selected' : ''; ?>>Last Month</option>
                <option value="year"       <?php echo $preset === 'year' ? 'selected' : ''; ?>>This Year</option>
                <option value="custom"     <?php echo $preset === 'custom' ? 'selected' : ''; ?>>Custom…</option>
            </select>
            <?php if ($preset === 'custom'): ?>
            <input type="date" name="from" value="<?php echo htmlspecialchars($from); ?>" class="form-control form-control-sm" style="width:auto;border-radius:10px;" aria-label="From date">
            <input type="date" name="to"   value="<?php echo htmlspecialchars($to); ?>"   class="form-control form-control-sm" style="width:auto;border-radius:10px;" aria-label="To date">
            <button class="btn btn-sm btn-inv">Apply</button>
            <?php endif; ?>
        </form>
        <button onclick="window.print()" class="btn btn-sm btn-outline-secondary" style="border-radius:10px;"><i class="fas fa-print me-1"></i>Print</button>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="inv-stat-card bg-grad-teal">
            <div class="d-flex justify-content-between">
                <div><div class="label">Revenue</div><div class="value">Tsh <?php echo number_format($pl['total_revenue']); ?></div></div>
                <i class="fas fa-sack-dollar icon"></i>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="inv-stat-card bg-grad-green">
            <div class="d-flex justify-content-between">
                <div><div class="label">Gross Profit</div><div class="value">Tsh <?php echo number_format($grossProfit); ?></div>
                <div class="label"><?php echo $grossMargin; ?>% margin</div></div>
                <i class="fas fa-arrow-trend-up icon"></i>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="inv-stat-card bg-grad-amber">
            <div class="d-flex justify-content-between">
                <div><div class="label">Operating Costs</div><div class="value">Tsh <?php echo number_format($operatingTotal); ?></div></div>
                <i class="fas fa-money-bill-wave icon"></i>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="inv-stat-card <?php echo $netProfit >= 0 ? 'bg-grad-blue' : 'bg-grad-red'; ?>">
            <div class="d-flex justify-content-between">
                <div><div class="label">Net <?php echo $netProfit >= 0 ? 'Profit' : 'Loss'; ?></div>
                <div class="value">Tsh <?php echo number_format(abs($netProfit)); ?></div>
                <div class="label"><?php echo $netMargin; ?>% of revenue</div></div>
                <i class="fas fa-scale-balanced icon"></i>
            </div>
        </div>
    </div>
</div>

<div class="inv-card p-4">
    <h6 class="fw-bold mb-3">Statement of Profit &amp; Loss</h6>
    <table class="table mb-0" style="font-size:.92rem;">
        <tbody>
            <!-- Revenue -->
            <tr><td colspan="2" class="fw-bold text-muted pt-2" style="font-size:.8rem;text-transform:uppercase;letter-spacing:.5px;">Revenue</td></tr>
            <?php if (!$pl['revenue']): ?>
            <tr><td class="ps-4 text-muted">No revenue recorded in this period</td><td class="text-end">-</td></tr>
            <?php endif; ?>
            <?php foreach ($pl['revenue'] as $r): ?>
            <tr>
                <td class="ps-4">
                    <a href="journal-entry.php?account_id=<?php echo (int)$r['id']; ?>&from=<?php echo urlencode($from); ?>&to=<?php echo urlencode($to); ?>"
                       style="color:inherit;text-decoration:none;">
                        <?php echo htmlspecialchars($r['code'] . ' - ' . $r['name']); ?>
                    </a>
                </td>
                <td class="text-end"><?php echo plMoney($r['amount']); ?></td>
            </tr>
            <?php endforeach; ?>
            <tr class="fw-bold" style="border-top:1px solid var(--color-border-strong);">
                <td>Total Revenue</td><td class="text-end"><?php echo plMoney($pl['total_revenue']); ?></td>
            </tr>

            <!-- Cost of sales -->
            <tr><td colspan="2" class="fw-bold text-muted pt-4" style="font-size:.8rem;text-transform:uppercase;letter-spacing:.5px;">Cost of Sales</td></tr>
            <tr><td class="ps-4">Cost of goods sold</td><td class="text-end text-danger">(<?php echo plMoney($costOfSales); ?>)</td></tr>
            <tr class="fw-bold text-success" style="border-top:2px solid var(--color-border-strong);">
                <td>Gross Profit</td><td class="text-end"><?php echo plMoney($grossProfit); ?></td>
            </tr>

            <!-- Operating expenses -->
            <tr><td colspan="2" class="fw-bold text-muted pt-4" style="font-size:.8rem;text-transform:uppercase;letter-spacing:.5px;">Operating Expenses</td></tr>
            <?php if (!$operating): ?>
            <tr><td class="ps-4 text-muted">No operating expenses recorded in this period</td><td class="text-end">-</td></tr>
            <?php endif; ?>
            <?php foreach ($operating as $e): ?>
            <tr>
                <td class="ps-4">
                    <a href="journal-entry.php?account_id=<?php echo (int)$e['id']; ?>&from=<?php echo urlencode($from); ?>&to=<?php echo urlencode($to); ?>"
                       style="color:inherit;text-decoration:none;">
                        <?php echo htmlspecialchars($e['code'] . ' - ' . $e['name']); ?>
                    </a>
                </td>
                <td class="text-end text-danger">(<?php echo plMoney($e['amount']); ?>)</td>
            </tr>
            <?php endforeach; ?>
            <tr class="fw-bold" style="border-top:1px solid var(--color-border-strong);">
                <td>Total Operating Expenses</td><td class="text-end text-danger">(<?php echo plMoney($operatingTotal); ?>)</td>
            </tr>

            <!-- Bottom line -->
            <tr class="fw-bold <?php echo $netProfit >= 0 ? 'text-success' : 'text-danger'; ?>"
                style="border-top:3px double var(--color-text);font-size:1.1rem;">
                <td>Net <?php echo $netProfit >= 0 ? 'Profit' : 'Loss'; ?></td>
                <td class="text-end"><?php echo plMoney($netProfit); ?></td>
            </tr>
        </tbody>
    </table>
</div>

<?php include 'inventory-footer.php'; ?>
