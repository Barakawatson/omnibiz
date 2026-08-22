<?php
// ============================================================
// Chart of Accounts
// ------------------------------------------------------------
// The structure the whole ledger posts into. Accounts flagged
// is_system are the ones the automatic postings depend on (till
// sales, COGS, cash, inventory): they can be renamed, but never
// deleted or deactivated, or a checkout would have nowhere to go.
// ============================================================
require_once '../includes/auth.php';
requireModule('accounting_manage');
// Reject any POST that does not carry this session's CSRF token.
// Placed before every handler on this page, and a no-op on GET.
csrfRequire();
$current_page = basename($_SERVER['PHP_SELF']);
include '../includes/db.php';
require_once '../includes/pos_functions.php';
posBoot($conn);

$userId = (int)($_SESSION['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id          = (int)($_POST['id'] ?? 0);
        $code        = trim($_POST['code'] ?? '');
        $name        = trim($_POST['name'] ?? '');
        $type        = $_POST['type'] ?? '';
        $description = trim($_POST['description'] ?? '') ?: null;
        $isActive    = isset($_POST['is_active']) ? 1 : 0;

        // A system account's type and active-status <select>/<checkbox> are
        // disabled in the edit modal, and a disabled form control is never
        // submitted at all - so $type and $isActive above would arrive as
        // '' and 0 for a system account, not the value it actually has.
        // Resolve the lock BEFORE validating, or "Choose a valid account
        // type" would reject every edit of a system account, including a
        // plain rename.
        $existing = null;
        if ($id > 0) {
            $chk = $conn->prepare("SELECT is_system, type FROM acc_accounts WHERE id = ?");
            $chk->bind_param('i', $id);
            $chk->execute();
            $existing = $chk->get_result()->fetch_assoc();
            $chk->close();

            if ($existing && (int)$existing['is_system'] === 1) {
                // A system account must stay usable: its type is what the
                // automatic postings rely on, and deactivating it would
                // break the next checkout.
                $type = $existing['type'];   // locked
                $isActive = 1;               // locked
            }
        }

        if ($code === '' || $name === '') {
            retailFlash('danger', 'An account needs both a code and a name.');
        } elseif (!isset(accAccountTypes()[$type])) {
            retailFlash('danger', 'Choose a valid account type.');
        } elseif (!preg_match('/^[0-9A-Za-z\-.]{1,20}$/', $code)) {
            retailFlash('danger', 'Account codes may only contain letters, numbers, dots and hyphens.');
        } else {
            if ($id > 0) {
                $stmt = $conn->prepare("UPDATE acc_accounts SET code = ?, name = ?, type = ?, description = ?, is_active = ? WHERE id = ?");
                $stmt->bind_param('ssssii', $code, $name, $type, $description, $isActive, $id);
                $ok = $stmt->execute();
                $dup = ($conn->errno === 1062);
                $stmt->close();
                retailFlash($ok ? 'success' : 'danger',
                    $ok ? 'Account updated.' : ($dup ? 'That account code is already in use.' : 'Could not update the account.'));
                if ($ok) { invAudit($conn, $userId, 'account_update', 'account', $id, $code . ' ' . $name); }
            } else {
                $stmt = $conn->prepare("INSERT INTO acc_accounts (code, name, type, description, is_active) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param('ssssi', $code, $name, $type, $description, $isActive);
                $ok = $stmt->execute();
                $newId = (int)$conn->insert_id;
                $dup = ($conn->errno === 1062);
                $stmt->close();
                retailFlash($ok ? 'success' : 'danger',
                    $ok ? 'Account created.' : ($dup ? 'That account code is already in use.' : 'Could not create the account.'));
                if ($ok) { invAudit($conn, $userId, 'account_create', 'account', $newId, $code . ' ' . $name); }
            }
        }
        header('Location: chart-of-accounts.php'); exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);

        $chk = $conn->prepare(
            "SELECT a.is_system, a.code, a.name, (SELECT COUNT(*) FROM acc_journal_lines l WHERE l.account_id = a.id) AS uses
             FROM acc_accounts a WHERE a.id = ?");
        $chk->bind_param('i', $id);
        $chk->execute();
        $acc = $chk->get_result()->fetch_assoc();
        $chk->close();

        if (!$acc) {
            retailFlash('danger', 'That account does not exist.');
        } elseif ((int)$acc['is_system'] === 1) {
            retailFlash('danger', 'System accounts cannot be deleted - the till and the ledger post to them automatically.');
        } elseif ((int)$acc['uses'] > 0) {
            // Deleting would orphan posted history, so retire it instead.
            $stmt = $conn->prepare("UPDATE acc_accounts SET is_active = 0 WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
            retailFlash('success', 'That account has ' . (int)$acc['uses'] . ' posted entries, so it was deactivated instead of deleted - its history stays intact.');
        } else {
            $stmt = $conn->prepare("UPDATE acc_accounts SET deleted_at = NOW(), is_active = 0 WHERE id = ?");
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $stmt->close();
            invAudit($conn, $userId, 'account_delete', 'account', $id, $acc['code'] . ' ' . $acc['name']);
            retailFlash('success', 'Account deleted.');
        }
        header('Location: chart-of-accounts.php'); exit;
    }
}

// ---- Accounts with their live balances ----------------------------------
$accounts = accGetAccounts($conn, '', false);
// One grouped query rather than one per account. Verified to return
// identical balances to accAccountBalance() for every account.
$balances = accAllAccountBalances($conn);

// Group by type for the tabbed display.
$grouped = [];
foreach ($accounts as $a) { $grouped[$a['type']][] = $a; }

$pageTitle = 'Chart of Accounts';
$breadcrumbs = [['Dashboard', 'index.php'], ['Accounting', 'accounting-dashboard.php'], ['Chart of Accounts']];
include 'inventory-header.php';

function coaMoney($v) { return number_format((float)$v, 2); }
?>
<div class="inv-page-header">
    <div>
        <h1><i class="fas fa-sitemap me-2" style="color:var(--inv-primary);"></i>Chart of Accounts</h1>
        <div class="subtitle">The structure every sale, expense and daily close posts into.</div>
    </div>
    <button class="btn btn-inv" data-bs-toggle="modal" data-bs-target="#accountModal" onclick="newAccount()">
        <i class="fas fa-plus me-1"></i>New Account
    </button>
</div>

<div class="alert alert-light" style="border-radius:12px;font-size:.85rem;">
    <i class="fas fa-shield-halved me-1" style="color:var(--inv-primary);"></i>
    Accounts marked <span class="inv-badge bg-info text-white">System</span> are used by the automatic postings
    (till sales, cost of goods, cash and inventory). You can rename them to suit your bookkeeping,
    but their type cannot change and they cannot be deleted or switched off.
</div>

<?php foreach (accAccountTypes() as $typeKey => $typeMeta):
    $list = $grouped[$typeKey] ?? [];
    if (!$list) { continue; }
    $typeTotal = 0.0;
    foreach ($list as $a) { $typeTotal += $balances[(int)$a['id']]; }
?>
<div class="inv-card p-0 mb-3">
    <div class="p-3 border-bottom d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-bold">
            <?php
            $icons = ['asset' => 'fa-building-columns', 'liability' => 'fa-file-invoice-dollar',
                      'equity' => 'fa-handshake', 'revenue' => 'fa-arrow-trend-up', 'expense' => 'fa-arrow-trend-down'];
            ?>
            <i class="fas <?php echo $icons[$typeKey]; ?> me-2" style="color:var(--inv-primary);"></i>
            <?php echo htmlspecialchars($typeMeta['label']); ?> Accounts
            <span class="text-muted fw-normal" style="font-size:.8rem;">(increase on the <?php echo $typeMeta['normal']; ?> side)</span>
        </h6>
        <span class="fw-bold">Tsh <?php echo coaMoney($typeTotal); ?></span>
    </div>
    <div class="table-responsive">
        <table class="inv-table">
            <thead><tr><th style="width:90px;">Code</th><th>Account</th><th>Description</th><th>Status</th><th class="text-end">Balance</th><th style="width:110px;"></th></tr></thead>
            <tbody>
            <?php foreach ($list as $a): $bal = $balances[(int)$a['id']]; ?>
                <tr>
                    <td><code style="font-size:.8rem;"><?php echo htmlspecialchars($a['code']); ?></code></td>
                    <td>
                        <a href="journal-entry.php?account_id=<?php echo (int)$a['id']; ?>" style="color:var(--inv-primary);text-decoration:none;">
                            <?php echo htmlspecialchars($a['name']); ?>
                        </a>
                        <?php if ((int)$a['is_system'] === 1): ?>
                        <span class="inv-badge bg-info text-white ms-1">System</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-muted" style="font-size:.83rem;"><?php echo htmlspecialchars($a['description'] ?: '-'); ?></td>
                    <td>
                        <?php if ((int)$a['is_active'] === 1): ?>
                            <span class="inv-badge bg-success text-white">Active</span>
                        <?php else: ?>
                            <span class="inv-badge bg-secondary text-white">Inactive</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end fw-bold"><?php echo coaMoney($bal); ?></td>
                    <td>
                        <button class="btn btn-sm btn-inv" title="Edit"
                                onclick='editAccount(<?php echo json_encode([
                                    "id" => (int)$a["id"], "code" => $a["code"], "name" => $a["name"],
                                    "type" => $a["type"], "description" => $a["description"],
                                    "is_active" => (int)$a["is_active"], "is_system" => (int)$a["is_system"],
                                ], JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'>
                            <i class="fas fa-edit"></i>
                        </button>
                        <?php if ((int)$a['is_system'] !== 1): ?>
                        <form method="post" style="display:inline;" onsubmit="return confirm('Delete <?php echo htmlspecialchars($a['name'], ENT_QUOTES); ?>? If it has posted entries it will be deactivated instead.');">
                            <?php echo csrfField(); ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?php echo (int)$a['id']; ?>">
                            <button class="btn btn-sm btn-outline-danger" style="border-radius:8px;" title="Delete"><i class="fas fa-trash"></i></button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endforeach; ?>

<!-- ============ Add / edit account ============ -->
<div class="modal fade" id="accountModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post">
<?php echo csrfField(); ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" id="accId" value="0">
                <div class="modal-header">
                    <h5 class="modal-title" id="accModalTitle"><i class="fas fa-sitemap me-2"></i>New Account</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="systemWarning" class="alert alert-info d-none" style="border-radius:10px;font-size:.84rem;">
                        <i class="fas fa-lock me-1"></i>This is a system account. You may rename it and edit its
                        description, but its type and active status are locked.
                    </div>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label" for="accCode">Code</label>
                            <input type="text" name="code" id="accCode" class="form-control" maxlength="20" required placeholder="e.g. 6250">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label" for="accName">Account name</label>
                            <input type="text" name="name" id="accName" class="form-control" maxlength="120" required placeholder="e.g. Security Services">
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="accType">Type</label>
                            <select name="type" id="accType" class="form-select" required>
                                <?php foreach (accAccountTypes() as $key => $meta): ?>
                                <option value="<?php echo htmlspecialchars($key); ?>">
                                    <?php echo htmlspecialchars($meta['label']); ?> (increases on the <?php echo $meta['normal']; ?> side)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="accDesc">Description <span class="text-muted">(optional)</span></label>
                            <input type="text" name="description" id="accDesc" class="form-control" maxlength="255">
                        </div>
                        <div class="col-12">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="is_active" id="accActive" checked>
                                <label class="form-check-label" for="accActive">Active (available when posting)</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-inv">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$pageScript = <<<'HTML'
<script>
function newAccount() {
    document.getElementById('accModalTitle').innerHTML = '<i class="fas fa-sitemap me-2"></i>New Account';
    document.getElementById('accId').value = 0;
    document.getElementById('accCode').value = '';
    document.getElementById('accName').value = '';
    document.getElementById('accDesc').value = '';
    document.getElementById('accType').selectedIndex = 0;
    document.getElementById('accType').disabled = false;
    document.getElementById('accActive').checked = true;
    document.getElementById('accActive').disabled = false;
    document.getElementById('systemWarning').classList.add('d-none');
}

function editAccount(a) {
    document.getElementById('accModalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit ' + a.code;
    document.getElementById('accId').value = a.id;
    document.getElementById('accCode').value = a.code;
    document.getElementById('accName').value = a.name;
    document.getElementById('accDesc').value = a.description || '';
    document.getElementById('accType').value = a.type;
    document.getElementById('accActive').checked = a.is_active === 1;

    // A system account's type and active flag are locked. They are only
    // disabled visually - the server enforces it again on save.
    const locked = a.is_system === 1;
    document.getElementById('accType').disabled = locked;
    document.getElementById('accActive').disabled = locked;
    document.getElementById('systemWarning').classList.toggle('d-none', !locked);

    new bootstrap.Modal(document.getElementById('accountModal')).show();
}
</script>
HTML;
include 'inventory-footer.php';
?>
