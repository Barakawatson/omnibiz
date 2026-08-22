<?php
// ============================================================
// Expenses
// ------------------------------------------------------------
// A small form over a journal entry: recording an expense debits
// the chosen expense account and credits whatever paid for it
// (cash drawer, mobile money, or supplier credit). Both halves are
// written in one transaction by accRecordExpense().
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    [$ok, $msg] = accRecordExpense($conn, $_POST, $userId, $userName);
    retailFlash($ok ? 'success' : 'danger', $msg);
    header('Location: expenses.php'); exit;
}

// ---- Filters -----------------------------------------------------------
$from      = $_GET['from'] ?? date('Y-m-01');
$to        = $_GET['to']   ?? date('Y-m-d');
$accountId = (int)($_GET['account_id'] ?? 0);
if ($from > $to) { [$from, $to] = [$to, $from]; }

$expenses = accGetExpenses($conn, $from, $to, $accountId);

// Accounts to spend ON (expense accounts) and to pay FROM (assets and
// payables - a purchase on credit is still an expense today).
$expenseAccounts = accGetAccounts($conn, 'expense');
$assetAccounts   = accGetAccounts($conn, 'asset');
$payableAccounts = accGetAccounts($conn, 'liability');
$paidFromOptions = array_merge($assetAccounts, $payableAccounts);

// ---- Period summary ----------------------------------------------------
$totalSpend = 0.0;
$byAccount  = [];
foreach ($expenses as $e) {
    $totalSpend += (float)$e['amount'];
    $key = $e['account_code'] . ' ' . $e['account_name'];
    $byAccount[$key] = ($byAccount[$key] ?? 0) + (float)$e['amount'];
}
arsort($byAccount);

$departments = catalogDepartments();
$pageTitle = 'Expenses';
$breadcrumbs = [['Dashboard', 'index.php'], ['Accounting', 'accounting-dashboard.php'], ['Expenses']];
include 'inventory-header.php';

function expMoney($v) { return 'Tsh ' . number_format((float)$v); }
?>
<div class="inv-page-header">
    <div>
        <h1><i class="fas fa-money-bill-wave me-2" style="color:var(--inv-primary);"></i>Expenses</h1>
        <div class="subtitle">Every expense is posted to the general ledger the moment it is saved.</div>
    </div>
    <button class="btn btn-inv" data-bs-toggle="modal" data-bs-target="#addExpenseModal">
        <i class="fas fa-plus me-1"></i>Record Expense
    </button>
</div>

<form method="get" class="inv-card p-3 mb-4">
    <div class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label">From</label>
            <input type="date" name="from" value="<?php echo htmlspecialchars($from); ?>" class="form-control" style="border-radius:10px;">
        </div>
        <div class="col-md-3">
            <label class="form-label">To</label>
            <input type="date" name="to" value="<?php echo htmlspecialchars($to); ?>" class="form-control" style="border-radius:10px;">
        </div>
        <div class="col-md-4">
            <label class="form-label">Expense account</label>
            <select name="account_id" class="form-select" style="border-radius:10px;">
                <option value="0">All expense accounts</option>
                <?php foreach ($expenseAccounts as $a): ?>
                <option value="<?php echo (int)$a['id']; ?>" <?php echo $accountId === (int)$a['id'] ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($a['code'] . ' - ' . $a['name']); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <button class="btn btn-inv w-100"><i class="fas fa-filter me-1"></i>Filter</button>
        </div>
    </div>
</form>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="inv-stat-card bg-grad-red">
            <div class="d-flex justify-content-between">
                <div>
                    <div class="label">Total in Period</div>
                    <div class="value"><?php echo expMoney($totalSpend); ?></div>
                    <div class="label"><?php echo count($expenses); ?> expense(s)</div>
                </div>
                <i class="fas fa-money-bill-wave icon"></i>
            </div>
        </div>
    </div>
    <div class="col-md-8">
        <div class="inv-card p-3 h-100">
            <h6 class="fw-bold mb-2" style="font-size:.9rem;">Where the money went</h6>
            <?php if (!$byAccount): ?>
                <div class="text-muted" style="font-size:.85rem;">No expenses recorded in this period.</div>
            <?php endif; ?>
            <?php
            $topAccounts = array_slice($byAccount, 0, 5, true);
            $maxSpend = $byAccount ? max($byAccount) : 1;
            foreach ($topAccounts as $name => $amount):
                $pct = round(($amount / max(0.01, $maxSpend)) * 100);
            ?>
            <div class="mb-2">
                <div class="d-flex justify-content-between" style="font-size:.82rem;">
                    <span><?php echo htmlspecialchars($name); ?></span>
                    <strong><?php echo expMoney($amount); ?></strong>
                </div>
                <div style="height:6px;background:var(--color-border);border-radius:20px;margin-top:3px;overflow:hidden;">
                    <div style="width:<?php echo $pct; ?>%;height:100%;background:var(--color-danger);"></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="inv-card p-0">
    <div class="table-responsive">
        <table class="inv-table">
            <thead>
                <tr><th>Reference</th><th>Date</th><th>Expense Account</th><th>Payee</th><th>Description</th>
                    <th>Paid From</th><th>Dept</th><th class="text-end">Amount</th><th>Entry</th></tr>
            </thead>
            <tbody>
            <?php if (!$expenses): ?>
                <tr><td colspan="9"><div class="empty-state"><i class="fas fa-receipt d-block"></i>No expenses in this period.</div></td></tr>
            <?php endif; ?>
            <?php foreach ($expenses as $e): ?>
                <tr>
                    <td><code style="font-size:.78rem;"><?php echo htmlspecialchars($e['reference_no']); ?></code></td>
                    <td><?php echo date('d/m/Y', strtotime($e['expense_date'])); ?></td>
                    <td><?php echo htmlspecialchars($e['account_code'] . ' - ' . $e['account_name']); ?></td>
                    <td><?php echo htmlspecialchars($e['payee'] ?: '-'); ?></td>
                    <td class="text-muted" style="font-size:.85rem;"><?php echo htmlspecialchars($e['description'] ?: '-'); ?></td>
                    <td><?php echo htmlspecialchars($e['paid_from_name']); ?></td>
                    <td><span class="inv-badge" style="background:<?php echo htmlspecialchars($departments[$e['department']]['colour'] ?? '#8b9aa2'); ?>;color:#fff;"><?php echo htmlspecialchars(catalogDepartmentLabel($e['department'])); ?></span></td>
                    <td class="text-end fw-bold"><?php echo expMoney($e['amount']); ?></td>
                    <td>
                        <?php if ($e['journal_id']): ?>
                        <a href="journal-entry.php?id=<?php echo (int)$e['journal_id']; ?>" style="color:var(--inv-primary);text-decoration:none;font-size:.8rem;">
                            <i class="fas fa-book-open"></i> View
                        </a>
                        <?php else: ?>-<?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ============ Add expense ============ -->
<div class="modal fade" id="addExpenseModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
<?php echo csrfField(); ?>
                <input type="hidden" name="action" value="add">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-money-bill-wave me-2"></i>Record Expense</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="exp_date">Date</label>
                            <input type="date" name="expense_date" id="exp_date" value="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d'); ?>" class="form-control" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="exp_amount">Amount (Tsh)</label>
                            <input type="number" step="0.01" min="0.01" name="amount" id="exp_amount" class="form-control" required placeholder="0.00">
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="exp_account">What was it for? (expense account)</label>
                            <select name="account_id" id="exp_account" class="form-select" required>
                                <option value="">Choose an expense account…</option>
                                <?php foreach ($expenseAccounts as $a): ?>
                                <option value="<?php echo (int)$a['id']; ?>"><?php echo htmlspecialchars($a['code'] . ' - ' . $a['name']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="exp_paid_from">Paid from</label>
                            <select name="paid_from_account_id" id="exp_paid_from" class="form-select" required>
                                <option value="">Choose where the money came from…</option>
                                <?php foreach ($paidFromOptions as $a): ?>
                                <option value="<?php echo (int)$a['id']; ?>" <?php echo $a['code'] === '1000' ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($a['code'] . ' - ' . $a['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Paying from Cash on Hand reduces the expected drawer total on today's Z-report.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="exp_payee">Payee <span class="text-muted">(optional)</span></label>
                            <input type="text" name="payee" id="exp_payee" class="form-control" maxlength="150" placeholder="Who was paid">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="exp_dept">Department</label>
                            <select name="department" id="exp_dept" class="form-select">
                                <?php foreach ($departments as $key => $d): ?>
                                <option value="<?php echo htmlspecialchars($key); ?>" <?php echo $key === 'general' ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($d['label']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="exp_desc">Description <span class="text-muted">(optional)</span></label>
                            <input type="text" name="description" id="exp_desc" class="form-control" maxlength="255" placeholder="e.g. October shop rent">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-inv">Save &amp; Post</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include 'inventory-footer.php'; ?>
