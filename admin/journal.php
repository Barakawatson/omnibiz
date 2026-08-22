<?php
// ============================================================
// General Ledger - the journal and the trial balance.
// ------------------------------------------------------------
// Two views of the same data: the entry list (what happened, in
// order) and the trial balance (where it all landed). Manual
// entries can be added here; automatic ones (till sales, expenses,
// daily closes) arrive from their own modules and are read-only.
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

// ---- Manual journal entry ---------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'post_entry') {
    if (!userCan('accounting_manage')) {
        retailFlash('danger', 'You do not have permission to post manual journal entries.');
        header('Location: journal.php'); exit;
    }

    $lines = [];
    foreach ((array)($_POST['account'] ?? []) as $i => $accountId) {
        $lines[] = [
            'account' => (int)$accountId,
            'debit'   => (float)($_POST['debit'][$i] ?? 0),
            'credit'  => (float)($_POST['credit'][$i] ?? 0),
            'memo'    => $_POST['line_memo'][$i] ?? '',
        ];
    }

    [$ok, $msg] = accPostEntry(
        $conn, $lines, trim($_POST['memo'] ?? ''), 'manual', null,
        $userId, $userName, $_POST['department'] ?? 'general',
        $_POST['entry_date'] ?? date('Y-m-d')
    );
    retailFlash($ok ? 'success' : 'danger', $ok ? 'Journal entry ' . $msg . ' posted.' : $msg);
    header('Location: journal.php'); exit;
}

// ---- Reverse an entry ---------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reverse') {
    if (!userCan('accounting_manage')) {
        retailFlash('danger', 'You do not have permission to reverse journal entries.');
    } else {
        [$ok, $msg] = accReverseEntry($conn, (int)($_POST['journal_id'] ?? 0), $userId, $userName, trim($_POST['reason'] ?? ''));
        retailFlash($ok ? 'success' : 'danger', $msg);
    }
    header('Location: journal.php'); exit;
}

// ---- Filters ------------------------------------------------------------
$view       = $_GET['view'] ?? 'entries';
$from       = $_GET['from'] ?? date('Y-m-01');
$to         = $_GET['to']   ?? date('Y-m-d');
$sourceType = $_GET['source'] ?? '';
if ($from > $to) { [$from, $to] = [$to, $from]; }

$entries      = accGetJournal($conn, $from, $to, $sourceType);
$trialBalance = accTrialBalance($conn, $from, $to);
$accounts     = accGetAccounts($conn);

$tbDebits = 0.0; $tbCredits = 0.0;
foreach ($trialBalance as $t) { $tbDebits += (float)$t['debits']; $tbCredits += (float)$t['credits']; }

$sourceLabels = [
    'pos_sale'    => 'Till sale',
    'pos_void'    => 'Sale voided',
    'expense'     => 'Expense',
    'daily_close' => 'Daily close',
    'reversal'    => 'Reversal',
    'manual'      => 'Manual entry',
];

$departments = catalogDepartments();
$pageTitle = 'General Ledger';
$breadcrumbs = [['Dashboard', 'index.php'], ['Accounting', 'accounting-dashboard.php'], ['General Ledger']];
include 'inventory-header.php';

function jMoney($v) { return number_format((float)$v, 2); }
?>
<div class="inv-page-header">
    <div>
        <h1><i class="fas fa-book-open me-2" style="color:var(--inv-primary);"></i>General Ledger</h1>
        <div class="subtitle"><?php echo date('d M Y', strtotime($from)); ?> to <?php echo date('d M Y', strtotime($to)); ?></div>
    </div>
    <?php if (userCan('accounting_manage')): ?>
    <button class="btn btn-inv" data-bs-toggle="modal" data-bs-target="#entryModal">
        <i class="fas fa-plus me-1"></i>Manual Entry
    </button>
    <?php endif; ?>
</div>

<form method="get" class="inv-card p-3 mb-4">
    <input type="hidden" name="view" value="<?php echo htmlspecialchars($view); ?>">
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
            <label class="form-label">Source</label>
            <select name="source" class="form-select" style="border-radius:10px;">
                <option value="">All sources</option>
                <?php foreach ($sourceLabels as $key => $lab): ?>
                <option value="<?php echo htmlspecialchars($key); ?>" <?php echo $sourceType === $key ? 'selected' : ''; ?>><?php echo htmlspecialchars($lab); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2"><button class="btn btn-inv w-100"><i class="fas fa-filter me-1"></i>Filter</button></div>
    </div>
</form>

<ul class="nav nav-pills mb-3">
    <li class="nav-item">
        <a class="nav-link <?php echo $view === 'entries' ? 'active' : ''; ?>" style="border-radius:20px;<?php echo $view === 'entries' ? 'background:var(--inv-primary);' : 'color:#5a6b73;'; ?>"
           href="?view=entries&from=<?php echo urlencode($from); ?>&to=<?php echo urlencode($to); ?>&source=<?php echo urlencode($sourceType); ?>">
            <i class="fas fa-list me-1"></i>Journal Entries
        </a>
    </li>
    <li class="nav-item ms-2">
        <a class="nav-link <?php echo $view === 'trial' ? 'active' : ''; ?>" style="border-radius:20px;<?php echo $view === 'trial' ? 'background:var(--inv-primary);' : 'color:#5a6b73;'; ?>"
           href="?view=trial&from=<?php echo urlencode($from); ?>&to=<?php echo urlencode($to); ?>">
            <i class="fas fa-scale-balanced me-1"></i>Trial Balance
        </a>
    </li>
</ul>

<?php if ($view === 'trial'): ?>
<!-- ============ Trial balance ============ -->
<?php $balanced = abs($tbDebits - $tbCredits) < 0.01; ?>
<div class="alert alert-<?php echo $balanced ? 'success' : 'danger'; ?>" style="border-radius:12px;">
    <i class="fas fa-<?php echo $balanced ? 'circle-check' : 'triangle-exclamation'; ?> me-1"></i>
    <?php if ($balanced): ?>
        The books balance: total debits equal total credits at <strong>Tsh <?php echo jMoney($tbDebits); ?></strong>.
    <?php else: ?>
        <strong>The books do not balance.</strong> Debits Tsh <?php echo jMoney($tbDebits); ?>
        vs credits Tsh <?php echo jMoney($tbCredits); ?> - a difference of
        Tsh <?php echo jMoney(abs($tbDebits - $tbCredits)); ?>. This should never happen; check for
        entries edited directly in the database.
    <?php endif; ?>
</div>

<div class="inv-card p-0">
    <div class="table-responsive">
        <table class="inv-table">
            <thead><tr><th>Code</th><th>Account</th><th>Type</th><th class="text-end">Debits</th><th class="text-end">Credits</th><th class="text-end">Balance</th></tr></thead>
            <tbody>
            <?php foreach ($trialBalance as $t):
                $debits = (float)$t['debits']; $credits = (float)$t['credits'];
                if ($debits == 0.0 && $credits == 0.0) { continue; }
                $normal = accAccountTypes()[$t['type']]['normal'];
                $balance = $normal === 'debit' ? $debits - $credits : $credits - $debits;
            ?>
                <tr>
                    <td><code style="font-size:.8rem;"><?php echo htmlspecialchars($t['code']); ?></code></td>
                    <td>
                        <a href="journal-entry.php?account_id=<?php echo (int)$t['id']; ?>&from=<?php echo urlencode($from); ?>&to=<?php echo urlencode($to); ?>"
                           style="color:var(--inv-primary);text-decoration:none;"><?php echo htmlspecialchars($t['name']); ?></a>
                    </td>
                    <td><span class="inv-badge bg-light text-dark"><?php echo htmlspecialchars(accAccountTypes()[$t['type']]['label']); ?></span></td>
                    <td class="text-end"><?php echo $debits > 0 ? jMoney($debits) : '-'; ?></td>
                    <td class="text-end"><?php echo $credits > 0 ? jMoney($credits) : '-'; ?></td>
                    <td class="text-end fw-bold"><?php echo jMoney($balance); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="background:var(--color-primary-softer);font-weight:700;">
                    <td colspan="3">TOTAL</td>
                    <td class="text-end"><?php echo jMoney($tbDebits); ?></td>
                    <td class="text-end"><?php echo jMoney($tbCredits); ?></td>
                    <td></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>

<?php else: ?>
<!-- ============ Journal entries ============ -->
<div class="inv-card p-0">
    <div class="table-responsive">
        <table class="inv-table">
            <thead><tr><th>Entry No</th><th>Date</th><th>Memo</th><th>Source</th><th>Dept</th><th>By</th><th class="text-end">Amount</th><th>Status</th><th style="width:110px;"></th></tr></thead>
            <tbody>
            <?php if (!$entries): ?>
                <tr><td colspan="9"><div class="empty-state"><i class="fas fa-book d-block"></i>No journal entries in this period.</div></td></tr>
            <?php endif; ?>
            <?php foreach ($entries as $e): ?>
                <tr>
                    <td><code style="font-size:.78rem;"><?php echo htmlspecialchars($e['entry_no']); ?></code></td>
                    <td><?php echo date('d/m/Y', strtotime($e['entry_date'])); ?></td>
                    <td style="font-size:.86rem;"><?php echo htmlspecialchars($e['memo'] ?: '-'); ?></td>
                    <td><span class="inv-badge bg-light text-dark"><?php echo htmlspecialchars($sourceLabels[$e['source_type']] ?? $e['source_type']); ?></span></td>
                    <td><span class="inv-badge" style="background:<?php echo htmlspecialchars($departments[$e['department']]['colour'] ?? '#8b9aa2'); ?>;color:#fff;"><?php echo htmlspecialchars(catalogDepartmentLabel($e['department'])); ?></span></td>
                    <td class="text-muted" style="font-size:.82rem;"><?php echo htmlspecialchars($e['created_by_name'] ?: '-'); ?></td>
                    <td class="text-end fw-bold"><?php echo jMoney($e['total_debit']); ?></td>
                    <td>
                        <?php if ($e['status'] === 'reversed'): ?>
                            <span class="inv-badge bg-secondary text-white">Reversed</span>
                        <?php elseif ($e['reversal_of']): ?>
                            <span class="inv-badge bg-info text-white">Reversal</span>
                        <?php else: ?>
                            <span class="inv-badge bg-success text-white">Posted</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="journal-entry.php?id=<?php echo (int)$e['id']; ?>" class="btn btn-sm btn-outline-secondary" style="border-radius:8px;" title="View entry"><i class="fas fa-eye"></i></a>
                        <?php if (userCan('accounting_manage') && $e['status'] === 'posted'): ?>
                        <button type="button" class="btn btn-sm btn-outline-danger" style="border-radius:8px;" title="Reverse entry"
                                aria-label="Reverse entry <?php echo htmlspecialchars($e['entry_no'], ENT_QUOTES); ?>"
                                data-ui-modal="#reverseModal"
                                data-title="Reverse <?php echo htmlspecialchars($e['entry_no'], ENT_QUOTES); ?>"
                                data-field-journal-id="<?php echo (int)$e['id']; ?>">
                            <i class="fas fa-rotate-left"></i>
                        </button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php if (userCan('accounting_manage')): ?>
<!-- ============ Reverse entry ============ -->
<!-- ONE modal reused for every row via MX.fillModal, instead of a native
     window.prompt() - the original entry stays in the ledger and this
     posts a mirror-image entry against it, so the reason should be typed
     into the same custom-modal UI as every other destructive-but-safe
     action in this app, not the browser's own prompt() dialog. -->
<div class="modal fade" id="reverseModal" tabindex="-1" aria-labelledby="reverseModalTitle" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" id="reverseForm">
<?php echo csrfField(); ?>
                <input type="hidden" name="action" value="reverse">
                <input type="hidden" name="journal_id" value="">
                <div class="modal-header">
                    <h5 class="modal-title" id="reverseModalTitle"><span data-ui-modal-title>Reverse entry</span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p style="font-size:.92rem;">The original entry stays in the ledger and a mirror-image entry is posted against it.</p>
                    <label class="form-label" for="reverseReason">Reason</label>
                    <input type="text" name="reason" id="reverseReason" class="form-control" required style="border-radius:10px;">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger" style="border-radius:10px;">Reverse Entry</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if (userCan('accounting_manage')): ?>
<!-- ============ Manual entry ============ -->
<div class="modal fade" id="entryModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post" id="entryForm">
<?php echo csrfField(); ?>
                <input type="hidden" name="action" value="post_entry">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-book me-2"></i>Manual Journal Entry</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label" for="je_date">Date</label>
                            <input type="date" name="entry_date" id="je_date" value="<?php echo date('Y-m-d'); ?>" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="je_dept">Department</label>
                            <select name="department" id="je_dept" class="form-select">
                                <?php foreach ($departments as $key => $d): ?>
                                <option value="<?php echo htmlspecialchars($key); ?>" <?php echo $key === 'general' ? 'selected' : ''; ?>><?php echo htmlspecialchars($d['label']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="je_memo">Memo</label>
                            <input type="text" name="memo" id="je_memo" class="form-control" maxlength="255" placeholder="What is this entry for?" required>
                        </div>
                    </div>

                    <table class="table table-sm" id="lineTable">
                        <thead>
                            <tr><th style="width:45%;">Account</th><th>Debit</th><th>Credit</th><th style="width:26%;">Line memo</th><th></th></tr>
                        </thead>
                        <tbody id="lineBody">
                            <!-- Two lines to start: an entry needs at least two to balance. -->
                            <?php for ($i = 0; $i < 2; $i++): ?>
                            <tr>
                                <td>
                                    <select name="account[]" class="form-select form-select-sm" required>
                                        <option value="">Choose account…</option>
                                        <?php foreach ($accounts as $a): ?>
                                        <option value="<?php echo (int)$a['id']; ?>"><?php echo htmlspecialchars($a['code'] . ' - ' . $a['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td><input type="number" step="0.01" min="0" name="debit[]" class="form-control form-control-sm debit-in" placeholder="0.00"></td>
                                <td><input type="number" step="0.01" min="0" name="credit[]" class="form-control form-control-sm credit-in" placeholder="0.00"></td>
                                <td><input type="text" name="line_memo[]" class="form-control form-control-sm" maxlength="255"></td>
                                <td><button type="button" class="btn btn-sm btn-outline-danger rm-line" style="border-radius:8px;"><i class="fas fa-times"></i></button></td>
                            </tr>
                            <?php endfor; ?>
                        </tbody>
                        <tfoot>
                            <tr class="fw-bold">
                                <td class="text-end">Totals</td>
                                <td id="totDebit">0.00</td>
                                <td id="totCredit">0.00</td>
                                <td colspan="2" id="balanceMsg" class="text-danger" style="font-size:.82rem;">Not balanced</td>
                            </tr>
                        </tfoot>
                    </table>
                    <button type="button" class="btn btn-sm btn-outline-secondary" id="addLine" style="border-radius:8px;">
                        <i class="fas fa-plus me-1"></i>Add line
                    </button>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-inv" id="postBtn" disabled>Post Entry</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php
$pageScript = <<<'HTML'
<script>
// MX.fillModal only touches fields named by a data-field-* attribute, so
// a reason typed for one entry would otherwise still be sitting in the
// box the next time the shared modal is opened for a different entry.
document.addEventListener('click', function (e) {
    if (e.target.closest('[data-ui-modal="#reverseModal"]')) {
        const reason = document.getElementById('reverseReason');
        if (reason) { reason.value = ''; }
    }
});

// ---- Manual entry: live balance check --------------------------------
// The Post button stays disabled until debits equal credits, so an
// unbalanced entry never even reaches the server.
(function () {
    const body = document.getElementById('lineBody');
    if (!body) return;

    function recalc() {
        let d = 0, c = 0;
        body.querySelectorAll('.debit-in').forEach(i => d += parseFloat(i.value) || 0);
        body.querySelectorAll('.credit-in').forEach(i => c += parseFloat(i.value) || 0);
        document.getElementById('totDebit').textContent = d.toFixed(2);
        document.getElementById('totCredit').textContent = c.toFixed(2);
        const balanced = Math.abs(d - c) < 0.005 && d > 0;
        const msg = document.getElementById('balanceMsg');
        msg.textContent = balanced ? 'Balanced' : 'Difference: ' + Math.abs(d - c).toFixed(2);
        msg.className = balanced ? 'text-success' : 'text-danger';
        document.getElementById('postBtn').disabled = !balanced;
    }

    // A line is one side or the other, never both.
    body.addEventListener('input', function (e) {
        if (e.target.classList.contains('debit-in') && e.target.value) {
            e.target.closest('tr').querySelector('.credit-in').value = '';
        }
        if (e.target.classList.contains('credit-in') && e.target.value) {
            e.target.closest('tr').querySelector('.debit-in').value = '';
        }
        recalc();
    });

    body.addEventListener('click', function (e) {
        const btn = e.target.closest('.rm-line');
        if (!btn) return;
        if (body.rows.length <= 2) { alert('An entry needs at least two lines.'); return; }
        btn.closest('tr').remove();
        recalc();
    });

    document.getElementById('addLine').addEventListener('click', function () {
        const row = body.rows[0].cloneNode(true);
        row.querySelectorAll('input').forEach(i => i.value = '');
        row.querySelector('select').selectedIndex = 0;
        body.appendChild(row);
    });

    recalc();
})();
</script>
HTML;
include 'inventory-footer.php';
?>
