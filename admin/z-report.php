<?php
// ============================================================
// Daily Close (Z-Report)
// ------------------------------------------------------------
// The end-of-day till reconciliation: what the system says the
// day took, what is physically in the drawer, and the variance
// between them. Closing a day snapshots those figures forever
// (see accCloseDay) and posts any over/short to the ledger.
// ============================================================
require_once '../includes/auth.php';
requireModule('accounting');
// Reject any POST that does not carry this session's CSRF token.
// Placed before every handler on this page, and a no-op on GET.
csrfRequire();
$current_page = basename($_SERVER['PHP_SELF']);
include '../includes/db.php';
require_once '../includes/pos_functions.php';
posBoot($conn);

$userId   = (int)($_SESSION['id'] ?? 0);
$userName = (string)($_SESSION['username'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'close') {
    [$ok, $msg] = accCloseDay(
        $conn,
        $_POST['close_date'] ?? date('Y-m-d'),
        (float)($_POST['counted_cash'] ?? 0),
        trim($_POST['notes'] ?? ''),
        $userId, $userName
    );
    retailFlash($ok ? 'success' : 'danger', $msg);
    header('Location: z-report.php?date=' . urlencode($_POST['close_date'] ?? date('Y-m-d')));
    exit;
}

$date = $_GET['date'] ?? date('Y-m-d');
if (!strtotime($date)) { $date = date('Y-m-d'); }

$close   = accGetClose($conn, $date);
$figures = $close ? null : accDayFigures($conn, $date);
$recent  = accRecentCloses($conn, 20);

// A closed day shows its stored snapshot; an open day shows live
// figures. Normalising both into $f keeps the markup below single-path.
$f = $close ? [
    'sales_count'       => (int)$close['sales_count'],
    'voided_count'      => (int)$close['voided_count'],
    'gross_sales'       => (float)$close['gross_sales'],
    'discounts'         => (float)$close['discounts'],
    'tax_collected'     => (float)$close['tax_collected'],
    'net_sales'         => (float)$close['net_sales'],
    'cost_of_sales'     => (float)$close['cost_of_sales'],
    'gross_profit'      => (float)$close['gross_profit'],
    'cash_sales'        => (float)$close['cash_sales'],
    'mobile_sales'      => (float)$close['mobile_sales'],
    'bank_sales'        => (float)($close['bank_sales'] ?? 0),
    'card_sales'        => (float)$close['card_sales'],
    'cash_expenses'     => (float)$close['cash_expenses'],
    'opening_float'     => (float)$close['opening_float'],
    'expected_cash'     => (float)$close['expected_cash'],
] : $figures;

$departments = catalogDepartments($conn);

// The departmental split. A closed day reads its stored breakdown, so a
// Z-report printed months later shows exactly the figures it was closed
// with - even if departments have been renamed, added or retired since.
// An open day is split live from today's sales.
if ($close) {
    $deptSplit = accCloseDepartments($conn, $close);
} else {
    $deptSplit = [];
    foreach (($figures['department_sales'] ?? []) as $dKey => $amount) {
        $deptSplit[$dKey] = ['label' => catalogDepartmentLabel($dKey), 'amount' => (float)$amount];
    }
}
$pageTitle = 'Daily Close (Z-Report)';
$breadcrumbs = [['Dashboard', 'index.php'], ['Accounting', 'accounting-dashboard.php'], ['Daily Close']];
include 'inventory-header.php';

function zMoney($v) { return 'Tsh ' . number_format((float)$v, 2); }
?>
<div class="inv-page-header">
    <div>
        <h1><i class="fas fa-cash-register me-2" style="color:var(--inv-primary);"></i>Daily Close &mdash; Z-Report</h1>
        <div class="subtitle"><?php echo date('l, d F Y', strtotime($date)); ?></div>
    </div>
    <div class="d-flex gap-2 align-items-center">
        <form method="get" class="d-flex gap-2">
            <input type="date" name="date" value="<?php echo htmlspecialchars($date); ?>" max="<?php echo date('Y-m-d'); ?>"
                   class="form-control form-control-sm" style="width:auto;border-radius:10px;" onchange="this.form.submit()" aria-label="Report date">
        </form>
        <button onclick="window.print()" class="btn btn-sm btn-outline-secondary" style="border-radius:10px;"><i class="fas fa-print me-1"></i>Print</button>
    </div>
</div>

<?php if ($close): ?>
<div class="alert alert-success" style="border-radius:12px;">
    <i class="fas fa-lock me-1"></i>
    This day was closed by <strong><?php echo htmlspecialchars($close['closed_by_name'] ?: 'unknown'); ?></strong>
    on <?php echo date('d/m/Y H:i', strtotime($close['closed_at'])); ?>.
    The figures below are the snapshot taken at that moment.
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="inv-stat-card bg-grad-teal">
            <div class="d-flex justify-content-between">
                <div><div class="label">Net Sales</div><div class="value"><?php echo 'Tsh ' . number_format($f['net_sales']); ?></div>
                <div class="label"><?php echo $f['sales_count']; ?> sale(s)</div></div>
                <i class="fas fa-receipt icon"></i>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="inv-stat-card bg-grad-green">
            <div class="d-flex justify-content-between">
                <div><div class="label">Gross Profit</div><div class="value"><?php echo 'Tsh ' . number_format($f['gross_profit']); ?></div>
                <div class="label">Cost <?php echo 'Tsh ' . number_format($f['cost_of_sales']); ?></div></div>
                <i class="fas fa-arrow-trend-up icon"></i>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="inv-stat-card bg-grad-blue">
            <div class="d-flex justify-content-between">
                <div><div class="label">Expected Cash</div><div class="value"><?php echo 'Tsh ' . number_format($f['expected_cash']); ?></div>
                <div class="label">Float + cash &minus; payouts</div></div>
                <i class="fas fa-wallet icon"></i>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <?php $variance = $close ? (float)$close['variance'] : null; ?>
        <div class="inv-stat-card <?php echo $variance === null ? 'bg-grad-purple' : (abs($variance) < 0.01 ? 'bg-grad-green' : 'bg-grad-red'); ?>">
            <div class="d-flex justify-content-between">
                <div>
                    <div class="label">Variance</div>
                    <div class="value"><?php echo $variance === null ? 'Not closed' : 'Tsh ' . number_format(abs($variance)); ?></div>
                    <div class="label">
                        <?php
                        if ($variance === null) { echo 'Close the day to record'; }
                        elseif (abs($variance) < 0.01) { echo 'Drawer balanced'; }
                        else { echo $variance < 0 ? 'Short' : 'Over'; }
                        ?>
                    </div>
                </div>
                <i class="fas fa-scale-balanced icon"></i>
            </div>
        </div>
    </div>
</div>

<div class="row g-3 mb-4">
    <!-- ============ The report ============ -->
    <div class="col-lg-7">
        <div class="inv-card p-4">
            <h6 class="fw-bold mb-3"><i class="fas fa-file-invoice me-2" style="color:var(--inv-primary);"></i>Z-Report for <?php echo date('d/m/Y', strtotime($date)); ?></h6>
            <table class="table table-sm mb-0" style="font-size:.9rem;">
                <tbody>
                    <tr><td>Gross sales (before discount)</td><td class="text-end"><?php echo zMoney($f['gross_sales']); ?></td></tr>
                    <tr><td>Less discounts</td><td class="text-end text-danger">&minus; <?php echo zMoney($f['discounts']); ?></td></tr>
                    <?php if ($f['tax_collected'] > 0): ?>
                    <tr><td>Tax collected</td><td class="text-end"><?php echo zMoney($f['tax_collected']); ?></td></tr>
                    <?php endif; ?>
                    <tr class="fw-bold" style="border-top:2px solid var(--color-border-strong);">
                        <td>Net sales</td><td class="text-end"><?php echo zMoney($f['net_sales']); ?></td>
                    </tr>
                    <tr><td>Cost of goods sold</td><td class="text-end text-danger">&minus; <?php echo zMoney($f['cost_of_sales']); ?></td></tr>
                    <tr class="fw-bold text-success"><td>Gross profit</td><td class="text-end"><?php echo zMoney($f['gross_profit']); ?></td></tr>

                    <tr><td colspan="2" class="pt-3 fw-bold text-muted" style="font-size:.8rem;text-transform:uppercase;">Payment breakdown</td></tr>
                    <tr><td><i class="fas fa-money-bill me-1 text-muted"></i>Cash</td><td class="text-end"><?php echo zMoney($f['cash_sales']); ?></td></tr>
                    <tr><td><i class="fas fa-mobile-screen me-1 text-muted"></i>Lipa Namba / Mobile</td><td class="text-end"><?php echo zMoney($f['mobile_sales']); ?></td></tr>
                    <?php if ($f['bank_sales'] > 0): ?>
                    <tr><td><i class="fas fa-building-columns me-1 text-muted"></i>Bank transfer</td><td class="text-end"><?php echo zMoney($f['bank_sales']); ?></td></tr>
                    <?php endif; ?>
                    <?php if ($f['card_sales'] > 0): ?>
                    <tr><td><i class="fas fa-credit-card me-1 text-muted"></i>Card</td><td class="text-end"><?php echo zMoney($f['card_sales']); ?></td></tr>
                    <?php endif; ?>

                    <tr><td colspan="2" class="pt-3 fw-bold text-muted" style="font-size:.8rem;text-transform:uppercase;">By department</td></tr>
                    <?php if ($deptSplit): foreach ($deptSplit as $dKey => $dRow): ?>
                    <tr>
                        <td>
                            <i class="fas <?php echo htmlspecialchars(catalogDepartmentIcon($dKey)); ?> me-1"
                               style="color:<?php echo htmlspecialchars(catalogDepartmentColour($dKey)); ?>;"></i>
                            <?php echo htmlspecialchars($dRow['label']); ?>
                        </td>
                        <td class="text-end"><?php echo zMoney($dRow['amount']); ?></td>
                    </tr>
                    <?php endforeach; else: ?>
                    <tr><td colspan="2" class="text-muted" style="font-size:.85rem;">No departmental takings recorded.</td></tr>
                    <?php endif; ?>

                    <tr><td colspan="2" class="pt-3 fw-bold text-muted" style="font-size:.8rem;text-transform:uppercase;">Cash drawer</td></tr>
                    <tr><td>Opening float</td><td class="text-end"><?php echo zMoney($f['opening_float']); ?></td></tr>
                    <tr><td>Plus cash sales</td><td class="text-end">+ <?php echo zMoney($f['cash_sales']); ?></td></tr>
                    <tr><td>Less cash paid out (expenses)</td><td class="text-end text-danger">&minus; <?php echo zMoney($f['cash_expenses']); ?></td></tr>
                    <tr class="fw-bold" style="border-top:2px solid var(--color-border-strong);">
                        <td>Expected in drawer</td><td class="text-end"><?php echo zMoney($f['expected_cash']); ?></td>
                    </tr>
                    <?php if ($close): ?>
                    <tr><td>Counted in drawer</td><td class="text-end"><?php echo zMoney($close['counted_cash']); ?></td></tr>
                    <tr class="fw-bold <?php echo abs((float)$close['variance']) < 0.01 ? 'text-success' : 'text-danger'; ?>">
                        <td>Variance (<?php echo (float)$close['variance'] < 0 ? 'short' : 'over'; ?>)</td>
                        <td class="text-end"><?php echo zMoney($close['variance']); ?></td>
                    </tr>
                    <?php endif; ?>

                    <?php if ($f['voided_count'] > 0): ?>
                    <tr><td class="text-muted pt-3">Voided sales (excluded above)</td><td class="text-end text-muted pt-3"><?php echo $f['voided_count']; ?></td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
            <?php if ($close && $close['notes']): ?>
            <div class="alert alert-light mt-3 mb-0" style="border-radius:10px;font-size:.85rem;">
                <strong>Notes:</strong> <?php echo htmlspecialchars($close['notes']); ?>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ============ Close the day ============ -->
    <div class="col-lg-5">
        <?php if (!$close): ?>
        <div class="inv-card p-4">
            <h6 class="fw-bold mb-1"><i class="fas fa-lock me-2" style="color:var(--inv-primary);"></i>Close This Day</h6>
            <p class="text-muted" style="font-size:.84rem;">
                Count the drawer and enter the total. The difference against the expected
                <strong><?php echo zMoney($f['expected_cash']); ?></strong> is recorded as an over/short
                and posted to the ledger, so the books match the cash.
                <strong>A closed day cannot be reopened.</strong>
            </p>
            <form method="post" onsubmit="return confirm('Close <?php echo date('d/m/Y', strtotime($date)); ?>? This cannot be undone.');">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="close">
                <input type="hidden" name="close_date" value="<?php echo htmlspecialchars($date); ?>">
                <div class="mb-3">
                    <label class="form-label" for="zr_counted">Cash counted in drawer (Tsh)</label>
                    <input type="number" step="0.01" min="0" name="counted_cash" id="zr_counted" class="form-control form-control-lg"
                           style="border-radius:10px;font-weight:700;text-align:right;" required
                           value="<?php echo number_format($f['expected_cash'], 2, '.', ''); ?>">
                    <div class="form-text">Pre-filled with the expected amount - change it to what you actually counted.</div>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="zr_notes">Notes <span class="text-muted">(optional)</span></label>
                    <textarea name="notes" id="zr_notes" rows="2" class="form-control" style="border-radius:10px;"
                              placeholder="Explain any shortfall or surplus"></textarea>
                </div>
                <button class="btn btn-inv w-100" <?php echo $date > date('Y-m-d') ? 'disabled' : ''; ?>>
                    <i class="fas fa-lock me-1"></i>Close Day &amp; Post to Ledger
                </button>
            </form>
        </div>
        <?php endif; ?>

        <div class="inv-card p-0 <?php echo $close ? '' : 'mt-3'; ?>">
            <div class="p-3 border-bottom"><h6 class="mb-0 fw-bold"><i class="fas fa-clock-rotate-left me-2" style="color:var(--inv-primary);"></i>Recent Closes</h6></div>
            <div class="table-responsive" style="max-height:360px;overflow-y:auto;">
                <table class="inv-table">
                    <thead><tr><th>Date</th><th class="text-end">Net Sales</th><th class="text-end">Variance</th></tr></thead>
                    <tbody>
                    <?php if (!$recent): ?>
                        <tr><td colspan="3"><div class="empty-state"><i class="fas fa-calendar-xmark d-block"></i>No days closed yet.</div></td></tr>
                    <?php endif; ?>
                    <?php foreach ($recent as $c): $v = (float)$c['variance']; ?>
                        <tr>
                            <td><a href="?date=<?php echo urlencode($c['close_date']); ?>" style="color:var(--inv-primary);text-decoration:none;"><?php echo date('d/m/Y', strtotime($c['close_date'])); ?></a></td>
                            <td class="text-end"><?php echo 'Tsh ' . number_format((float)$c['net_sales']); ?></td>
                            <td class="text-end">
                                <?php if (abs($v) < 0.01): ?>
                                    <span class="inv-badge bg-success text-white">Balanced</span>
                                <?php else: ?>
                                    <span class="inv-badge bg-<?php echo $v < 0 ? 'danger' : 'warning'; ?> <?php echo $v < 0 ? 'text-white' : 'text-dark'; ?>">
                                        <?php echo $v < 0 ? '&minus;' : '+'; ?><?php echo number_format(abs($v)); ?>
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
</div>

<?php include 'inventory-footer.php'; ?>
