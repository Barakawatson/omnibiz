<?php
// ============================================================
// Journal entry detail / account ledger.
// ------------------------------------------------------------
// Two modes:
//   ?id=N          - one journal entry with all its lines
//   ?account_id=N  - the ledger for one account over a period,
//                    with a running balance
// ============================================================
require_once '../includes/auth.php';
requireModule('accounting');
$current_page = 'journal.php';   // keep the sidebar's Ledger link active
include '../includes/db.php';
require_once '../includes/pos_functions.php';
posBoot($conn);

$journalId = (int)($_GET['id'] ?? 0);
$accountId = (int)($_GET['account_id'] ?? 0);
$from      = $_GET['from'] ?? date('Y-m-01');
$to        = $_GET['to']   ?? date('Y-m-d');
if ($from > $to) { [$from, $to] = [$to, $from]; }

$departments = catalogDepartments();

if ($journalId <= 0 && $accountId <= 0) {
    header('Location: journal.php'); exit;
}

// ---- Entry mode ---------------------------------------------------------
$entry = null; $lines = [];
if ($journalId > 0) {
    $stmt = $conn->prepare("SELECT * FROM acc_journal WHERE id = ?");
    $stmt->bind_param('i', $journalId);
    $stmt->execute();
    $entry = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$entry) {
        retailFlash('danger', 'That journal entry does not exist.');
        header('Location: journal.php'); exit;
    }
    $lines = accGetEntryLines($conn, $journalId);
}

// ---- Account ledger mode ------------------------------------------------
$account = null; $ledger = [];
if ($accountId > 0) {
    $stmt = $conn->prepare("SELECT * FROM acc_accounts WHERE id = ?");
    $stmt->bind_param('i', $accountId);
    $stmt->execute();
    $account = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$account) {
        retailFlash('danger', 'That account does not exist.');
        header('Location: chart-of-accounts.php'); exit;
    }
    $ledger = accLedgerLines($conn, $accountId, $from, $to);
}

$sourceLabels = [
    'pos_sale' => 'Till sale', 'pos_void' => 'Sale voided', 'expense' => 'Expense',
    'daily_close' => 'Daily close', 'reversal' => 'Reversal', 'manual' => 'Manual entry',
];

$pageTitle = $entry ? ('Entry ' . $entry['entry_no']) : ('Ledger - ' . $account['name']);
$breadcrumbs = [['Dashboard', 'index.php'], ['Accounting', 'accounting-dashboard.php'],
                ['General Ledger', 'journal.php'], [$entry ? $entry['entry_no'] : $account['name']]];
include 'inventory-header.php';

function jeMoney($v) { return number_format((float)$v, 2); }
?>

<?php if ($entry): ?>
<!-- ============================ ENTRY ============================ -->
<div class="inv-page-header">
    <div>
        <h1><i class="fas fa-book me-2" style="color:var(--inv-primary);"></i><?php echo htmlspecialchars($entry['entry_no']); ?></h1>
        <div class="subtitle"><?php echo htmlspecialchars($entry['memo'] ?: 'No memo'); ?></div>
    </div>
    <div class="d-flex gap-2">
        <a href="journal.php" class="btn btn-outline-secondary" style="border-radius:10px;"><i class="fas fa-arrow-left me-1"></i>Back to Ledger</a>
        <button onclick="window.print()" class="btn btn-inv"><i class="fas fa-print me-1"></i>Print</button>
    </div>
</div>

<?php if ($entry['status'] === 'reversed'): ?>
<div class="alert alert-secondary" style="border-radius:12px;">
    <i class="fas fa-rotate-left me-1"></i>
    This entry was reversed. The original figures remain here as a permanent record;
    the mirror-image entry is
    <a href="?id=<?php echo (int)$entry['reversed_by']; ?>">#<?php echo (int)$entry['reversed_by']; ?></a>.
</div>
<?php endif; ?>
<?php if ($entry['reversal_of']): ?>
<div class="alert alert-info" style="border-radius:12px;">
    <i class="fas fa-info-circle me-1"></i>
    This is a reversal of entry
    <a href="?id=<?php echo (int)$entry['reversal_of']; ?>">#<?php echo (int)$entry['reversal_of']; ?></a>.
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-md-8">
        <div class="inv-card p-0">
            <div class="table-responsive">
                <table class="inv-table">
                    <thead><tr><th>Code</th><th>Account</th><th>Line memo</th><th class="text-end">Debit</th><th class="text-end">Credit</th></tr></thead>
                    <tbody>
                    <?php foreach ($lines as $l): ?>
                        <tr>
                            <td><code style="font-size:.8rem;"><?php echo htmlspecialchars($l['code']); ?></code></td>
                            <td>
                                <a href="?account_id=<?php echo (int)$l['account_id']; ?>" style="color:var(--inv-primary);text-decoration:none;">
                                    <?php echo htmlspecialchars($l['name']); ?>
                                </a>
                            </td>
                            <td class="text-muted" style="font-size:.85rem;"><?php echo htmlspecialchars($l['memo'] ?: '-'); ?></td>
                            <td class="text-end"><?php echo (float)$l['debit'] > 0 ? jeMoney($l['debit']) : ''; ?></td>
                            <td class="text-end"><?php echo (float)$l['credit'] > 0 ? jeMoney($l['credit']) : ''; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr style="background:var(--color-primary-softer);font-weight:700;">
                            <td colspan="3">TOTAL</td>
                            <td class="text-end"><?php echo jeMoney($entry['total_debit']); ?></td>
                            <td class="text-end"><?php echo jeMoney($entry['total_credit']); ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="inv-card p-4">
            <h6 class="fw-bold mb-3">Entry Details</h6>
            <table class="table table-sm mb-0" style="font-size:.86rem;">
                <tr><td class="text-muted">Date</td><td class="text-end"><?php echo date('d/m/Y', strtotime($entry['entry_date'])); ?></td></tr>
                <tr><td class="text-muted">Source</td><td class="text-end"><?php echo htmlspecialchars($sourceLabels[$entry['source_type']] ?? $entry['source_type']); ?></td></tr>
                <tr><td class="text-muted">Department</td><td class="text-end"><?php echo htmlspecialchars(catalogDepartmentLabel($entry['department'])); ?></td></tr>
                <tr><td class="text-muted">Posted by</td><td class="text-end"><?php echo htmlspecialchars($entry['created_by_name'] ?: 'System'); ?></td></tr>
                <tr><td class="text-muted">Posted at</td><td class="text-end"><?php echo date('d/m/Y H:i', strtotime($entry['created_at'])); ?></td></tr>
                <tr><td class="text-muted">Status</td><td class="text-end">
                    <span class="inv-badge bg-<?php echo $entry['status'] === 'posted' ? 'success' : 'secondary'; ?> text-white">
                        <?php echo ucfirst($entry['status']); ?>
                    </span>
                </td></tr>
            </table>
        </div>
    </div>
</div>

<?php else: ?>
<!-- ======================= ACCOUNT LEDGER ======================= -->
<?php
$closing = $ledger ? (float)end($ledger)['balance'] : 0.0;
$normal  = accAccountTypes()[$account['type']]['normal'];
?>
<div class="inv-page-header">
    <div>
        <h1><i class="fas fa-file-lines me-2" style="color:var(--inv-primary);"></i><?php echo htmlspecialchars($account['code'] . ' - ' . $account['name']); ?></h1>
        <div class="subtitle">
            <?php echo htmlspecialchars(accAccountTypes()[$account['type']]['label']); ?> account
            &middot; increases on the <?php echo $normal; ?> side
            &middot; <?php echo date('d M Y', strtotime($from)); ?> to <?php echo date('d M Y', strtotime($to)); ?>
        </div>
    </div>
    <form method="get" class="d-flex gap-2 align-items-center">
        <input type="hidden" name="account_id" value="<?php echo (int)$accountId; ?>">
        <input type="date" name="from" value="<?php echo htmlspecialchars($from); ?>" class="form-control form-control-sm" style="width:auto;border-radius:10px;" aria-label="From date">
        <input type="date" name="to" value="<?php echo htmlspecialchars($to); ?>" class="form-control form-control-sm" style="width:auto;border-radius:10px;" aria-label="To date">
        <button class="btn btn-sm btn-inv">Apply</button>
    </form>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="inv-stat-card bg-grad-teal">
            <div class="d-flex justify-content-between">
                <div>
                    <div class="label">Closing Balance</div>
                    <div class="value">Tsh <?php echo number_format($closing, 2); ?></div>
                    <div class="label"><?php echo count($ledger); ?> movement(s)</div>
                </div>
                <i class="fas fa-scale-balanced icon"></i>
            </div>
        </div>
    </div>
</div>

<div class="inv-card p-0">
    <div class="table-responsive">
        <table class="inv-table">
            <thead><tr><th>Date</th><th>Entry</th><th>Memo</th><th>Source</th><th class="text-end">Debit</th><th class="text-end">Credit</th><th class="text-end">Balance</th></tr></thead>
            <tbody>
            <?php if (!$ledger): ?>
                <tr><td colspan="7"><div class="empty-state"><i class="fas fa-file-lines d-block"></i>No movements on this account in the selected period.</div></td></tr>
            <?php endif; ?>
            <?php foreach ($ledger as $l): ?>
                <tr>
                    <td><?php echo date('d/m/Y', strtotime($l['entry_date'])); ?></td>
                    <td><a href="?id=<?php echo (int)$l['journal_id']; ?>" style="color:var(--inv-primary);text-decoration:none;"><code style="font-size:.78rem;"><?php echo htmlspecialchars($l['entry_no']); ?></code></a></td>
                    <td style="font-size:.85rem;"><?php echo htmlspecialchars($l['line_memo'] ?: $l['entry_memo'] ?: '-'); ?></td>
                    <td><span class="inv-badge bg-light text-dark"><?php echo htmlspecialchars($sourceLabels[$l['source_type']] ?? $l['source_type']); ?></span></td>
                    <td class="text-end"><?php echo (float)$l['debit'] > 0 ? jeMoney($l['debit']) : ''; ?></td>
                    <td class="text-end"><?php echo (float)$l['credit'] > 0 ? jeMoney($l['credit']) : ''; ?></td>
                    <td class="text-end fw-bold"><?php echo jeMoney($l['balance']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php include 'inventory-footer.php'; ?>
