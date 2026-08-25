<?php
// ============================================================
// POS - Till Terminals
// ------------------------------------------------------------
// The physical registers a sale can be rung up on. pos_terminals held
// exactly one seeded row ("Main Till"/"TILL-01") until this screen
// existed - there was no way to add a second till without direct SQL.
// A till belongs to a department, so it can default to that
// department's stock on admin/pos.php without the cashier picking one
// every time (see posCheckout()'s single-department override).
//
// A terminal already used by a sale, a held sale or a cancelled cart is
// never destroyed - deleting it would orphan those rows and blank the
// terminal's name on every past report (admin/report.php's "by
// terminal" breakdown, admin/manager-overview.php's "By terminal"
// card), so it is deactivated instead. pos_terminals carries no
// deleted_at (unlike inv_categories/inv_units), so a terminal with
// truly nothing pointing at it is hard-deleted rather than soft-deleted
// - the same reasoning admin/shop-settings.php already uses for
// shop_payment_methods, a lookup row with no history of its own.
// ============================================================
require_once '../includes/auth.php';
requireModule('terminals');
// Reject any POST that does not carry this session's CSRF token.
// Placed before every handler on this page, and a no-op on GET.
csrfRequire();
$current_page = basename($_SERVER['PHP_SELF']);
include '../includes/db.php';
require_once '../includes/pos_functions.php';
posBoot($conn);

$userId = (int)($_SESSION['id'] ?? 0);
$departments = catalogDepartments($conn);

function terminalFlash($type, $msg) { $_SESSION['inv_flash'] = ['type' => $type, 'msg' => $msg]; }

/**
 * How many rows across sales history point at this terminal. Three
 * tables, not one - unlike a product category (only inv_items) - since
 * none of them carry a foreign key to pos_terminals.id, a hard delete
 * would silently orphan whichever of these still referenced it.
 */
function terminalUsage(mysqli $conn, int $id): array {
    $counts = ['sales' => 0, 'held' => 0, 'cancelled' => 0];
    $queries = [
        'sales'     => "SELECT COUNT(*) AS c FROM sales_transactions WHERE terminal_id = ?",
        'held'      => "SELECT COUNT(*) AS c FROM pos_held_sales WHERE terminal_id = ?",
        'cancelled' => "SELECT COUNT(*) AS c FROM cancelled_carts WHERE terminal_id = ?",
    ];
    foreach ($queries as $key => $sql) {
        $stmt = $conn->prepare($sql);
        if (!$stmt) { continue; }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $counts[$key] = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
        $stmt->close();
    }
    return $counts;
}

// ---------- POST ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id         = (int)($_POST['id'] ?? 0);
        $name       = trim($_POST['name'] ?? '');
        $code       = strtoupper(trim($_POST['code'] ?? ''));
        $department = isset($departments[$_POST['department'] ?? '']) ? $_POST['department'] : catalogFirstActiveDepartment($conn);
        $isActive   = isset($_POST['is_active']) ? 1 : 0;

        if ($name === '' || $code === '') {
            terminalFlash('danger', 'A till needs both a name and a code.');
        } elseif (mb_strlen($name) > 80) {
            terminalFlash('danger', 'Till name is too long (80 characters maximum).');
        } elseif (mb_strlen($code) > 20) {
            terminalFlash('danger', 'Till code is too long (20 characters maximum).');
        } else {
            if ($id > 0) {
                $stmt = $conn->prepare("UPDATE pos_terminals SET name = ?, code = ?, department = ?, is_active = ?
                                        WHERE id = ?");
                $stmt->bind_param('sssii', $name, $code, $department, $isActive, $id);
                $ok = $stmt->execute();
                $dup = ($conn->errno === 1062);
                $stmt->close();

                terminalFlash($ok ? 'success' : 'danger',
                    $ok ? 'Till updated.'
                        : ($dup ? 'Another till is already using the code "' . $code . '".' : 'Could not update the till.'));
                if ($ok) { invAudit($conn, $userId, 'terminal_update', 'terminal', $id, $name . ' (' . $code . ')'); }
            } else {
                // location_id is not exposed here - it is DEFAULT NULL, has
                // no FK, and nothing in the codebase reads it back (a
                // future multi-branch feature would need to actually build
                // that out, not just fill an unused column).
                $stmt = $conn->prepare("INSERT INTO pos_terminals (name, code, department, is_active) VALUES (?, ?, ?, ?)");
                $stmt->bind_param('sssi', $name, $code, $department, $isActive);
                $ok = $stmt->execute();
                $newId = (int)$conn->insert_id;
                $dup = ($conn->errno === 1062);
                $stmt->close();

                terminalFlash($ok ? 'success' : 'danger',
                    $ok ? 'Till "' . $name . '" added.'
                        : ($dup ? 'A till with the code "' . $code . '" already exists.' : 'Could not add the till.'));
                if ($ok) { invAudit($conn, $userId, 'terminal_create', 'terminal', $newId, $name . ' (' . $code . ')'); }
            }
        }
        header('Location: pos-terminals.php'); exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);

        $stmt = $conn->prepare("SELECT name, code FROM pos_terminals WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $term = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$term) {
            terminalFlash('danger', 'That till does not exist.');
        } else {
            $usage = terminalUsage($conn, $id);
            $inUse = $usage['sales'] + $usage['held'] + $usage['cancelled'];

            if ($inUse > 0) {
                // Deactivating keeps every past sale, held sale and
                // cancellation pointing at a real terminal row, so reports
                // (admin/report.php, admin/manager-overview.php) keep
                // showing its name instead of blanking it out. This is
                // also why posGetTerminals()/posGetTerminal() filter
                // is_active = 1 - it's the only signal they need.
                $stmt = $conn->prepare("UPDATE pos_terminals SET is_active = 0 WHERE id = ?");
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $stmt->close();

                $parts = [];
                if ($usage['sales'] > 0)     { $parts[] = $usage['sales'] . ' sale(s)'; }
                if ($usage['held'] > 0)      { $parts[] = $usage['held'] . ' held sale(s)'; }
                if ($usage['cancelled'] > 0) { $parts[] = $usage['cancelled'] . ' cancellation(s)'; }
                terminalFlash('success', '"' . $term['name'] . '" is used by ' . implode(', ', $parts)
                    . ', so it was deactivated rather than deleted.');
            } else {
                // No history anywhere references this row - a genuine
                // delete, same reasoning as shop-settings.php's payment
                // method delete: nothing is orphaned by removing it.
                // pos_terminals has no deleted_at column to set instead.
                $stmt = $conn->prepare("DELETE FROM pos_terminals WHERE id = ?");
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $stmt->close();
                invAudit($conn, $userId, 'terminal_delete', 'terminal', $id, $term['name'] . ' (' . $term['code'] . ')');
                terminalFlash('success', 'Till "' . $term['name'] . '" deleted.');
            }
        }
        header('Location: pos-terminals.php'); exit;
    }

    if ($action === 'force_release') {
        // No extra role check here on purpose - authorization is this
        // page's own gate (requireModule('terminals'), admin/manager
        // only), the same "the page gate is the control" pattern
        // purchasing_approve and disposal_approve use elsewhere. Anyone
        // who can reach this handler is already allowed to do this.
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $conn->prepare("SELECT name, code, locked_by_username FROM pos_terminals WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $term = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$term) {
            terminalFlash('danger', 'That till does not exist.');
        } else {
            posForceReleaseTerminal($conn, $id);
            $wasHeldBy = $term['locked_by_username'] ?: 'a cashier';
            invAudit($conn, $userId, 'terminal_force_release', 'terminal', $id,
                $term['name'] . ' (' . $term['code'] . ') - was held by ' . $wasHeldBy);
            terminalFlash('success', 'Till "' . $term['name'] . '" released. It was held by ' . $wasHeldBy . '.');
        }
        header('Location: pos-terminals.php'); exit;
    }
}

// ---------- Data ----------
$search = trim($_GET['q'] ?? '');
$where  = '1=1';
$params = [];
$types  = '';
if ($search !== '') {
    $where .= " AND (p.name LIKE ? OR p.code LIKE ?)";
    $like = '%' . $search . '%';
    array_push($params, $like, $like);
    $types .= 'ss';
}

$sql = "SELECT p.*,
               (SELECT COUNT(*) FROM sales_transactions s WHERE s.terminal_id = p.id) AS sale_count,
               (SELECT COUNT(*) FROM pos_held_sales h WHERE h.terminal_id = p.id) AS held_count,
               (SELECT COUNT(*) FROM cancelled_carts c WHERE c.terminal_id = p.id) AS cancelled_count
        FROM pos_terminals p
        WHERE $where
        ORDER BY p.department, p.name";
$stmt = $conn->prepare($sql);
if ($types !== '') { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$terminals = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Same classifier admin/pos.php uses, so this page can never show a
// lock as "active" while the till picker treats it as stale, or vice
// versa - one function, read everywhere a staleness check happens.
$lockTimeoutMinutes = posTerminalLockTimeoutMinutes($conn);

$totals = ['all' => 0, 'active' => 0, 'unused' => 0];
$tRes = $conn->query("SELECT COUNT(*) AS all_c,
                             SUM(is_active = 1) AS active_c,
                             SUM((SELECT COUNT(*) FROM sales_transactions s WHERE s.terminal_id = p.id) = 0
                                 AND (SELECT COUNT(*) FROM pos_held_sales h WHERE h.terminal_id = p.id) = 0
                                 AND (SELECT COUNT(*) FROM cancelled_carts c WHERE c.terminal_id = p.id) = 0) AS unused_c
                      FROM pos_terminals p");
if ($tRes && ($r = $tRes->fetch_assoc())) {
    $totals = ['all' => (int)$r['all_c'], 'active' => (int)$r['active_c'], 'unused' => (int)$r['unused_c']];
}

$pageTitle = 'Tills / Terminals';
$breadcrumbs = [['Dashboard', 'index.php'], ['Tills / Terminals']];
include 'inventory-header.php';

$ph = [
    'title'    => 'Tills / Terminals',
    'icon'     => 'fa-cash-register',
    'subtitle' => 'Every physical register a sale can be rung up on. '
                . 'Each belongs to a department and shows on receipts by its code.',
    'actions'  => '<button type="button" class="ui-btn ui-btn-primary" data-bs-toggle="modal" data-bs-target="#terminalModal" onclick="newTerminal()">'
                . '<i class="fas fa-plus"></i>Add till</button>',
];
include 'partials/page-header.php';
?>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-4">
        <div class="inv-stat-card bg-grad-teal">
            <div class="d-flex justify-content-between align-items-start">
                <div><div class="label">Tills</div><div class="value"><?php echo number_format($totals['all']); ?></div></div>
                <i class="fas fa-cash-register icon"></i>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="inv-stat-card bg-grad-green">
            <div class="d-flex justify-content-between align-items-start">
                <div><div class="label">Active</div><div class="value"><?php echo number_format($totals['active']); ?></div></div>
                <i class="fas fa-circle-check icon"></i>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="inv-stat-card bg-grad-amber">
            <div class="d-flex justify-content-between align-items-start">
                <div><div class="label">Never used</div><div class="value"><?php echo number_format($totals['unused']); ?></div></div>
                <i class="fas fa-cash-register icon"></i>
            </div>
        </div>
    </div>
</div>

<form method="get" class="ui-toolbar" role="search">
    <div class="ui-search <?php echo $search !== '' ? 'has-value' : ''; ?>">
        <i class="fas fa-magnifying-glass"></i>
        <input type="search" name="q" value="<?php echo htmlspecialchars($search); ?>"
               placeholder="Search tills…" aria-label="Search tills">
        <button type="button" class="ui-search-clear" aria-label="Clear search"><i class="fas fa-xmark"></i></button>
    </div>
    <button class="ui-btn ui-btn-secondary"><i class="fas fa-magnifying-glass"></i>Search</button>

    <span class="ui-toolbar-spacer"></span>
    <span class="ui-result-count"><strong><?php echo count($terminals); ?></strong> shown</span>
</form>

<div class="inv-card p-0">
    <div class="ui-table-wrap">
        <table class="inv-table">
            <thead>
                <tr>
                    <th>Till</th>
                    <th>Code</th>
                    <th>Department</th>
                    <th class="text-end">Sales</th>
                    <th>Status</th>
                    <th style="width:96px;"><span class="visually-hidden">Actions</span></th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$terminals): ?>
                <tr><td colspan="6">
                    <?php
                    $es = ($search !== '')
                        ? ['icon' => 'fa-magnifying-glass', 'title' => 'No tills match',
                           'msg'  => 'Try a different search.',
                           'action' => '<a class="ui-btn ui-btn-secondary" href="pos-terminals.php">Clear search</a>']
                        : ['icon' => 'fa-cash-register', 'title' => 'No tills yet',
                           'msg'  => 'Add one so a cashier can check out on it.',
                           'action' => '<button type="button" class="ui-btn ui-btn-primary" data-bs-toggle="modal" data-bs-target="#terminalModal" onclick="newTerminal()"><i class="fas fa-plus"></i>Add till</button>'];
                    include 'partials/empty-state.php';
                    ?>
                </td></tr>
            <?php endif; ?>

            <?php foreach ($terminals as $t):
                $dept  = $t['department'] ?: 'general';
                $count = (int)$t['sale_count'] + (int)$t['held_count'] + (int)$t['cancelled_count'];
                $dMeta = $departments[$dept] ?? ['icon' => 'fa-boxes-stacked', 'label' => catalogDepartmentLabel($dept)];
            ?>
                <tr>
                    <td style="font-weight:500;"><?php echo htmlspecialchars($t['name']); ?></td>
                    <td><span class="ui-code"><?php echo htmlspecialchars($t['code']); ?></span></td>
                    <td>
                        <span class="ui-badge ui-badge-neutral">
                            <i class="fas <?php echo htmlspecialchars($dMeta['icon']); ?>"></i><?php echo htmlspecialchars($dMeta['label']); ?>
                        </span>
                    </td>
                    <td class="text-end ui-num"><?php echo number_format($count); ?></td>
                    <td>
                        <?php if ((int)$t['is_active'] === 1): ?>
                            <span class="ui-badge ui-badge-success"><span class="ui-dot ui-dot-success"></span>Active</span>
                        <?php else: ?>
                            <span class="ui-badge ui-badge-neutral">Inactive</span>
                        <?php endif; ?>
                        <?php
                        // Session status: is_active means "enabled here", this is
                        // an orthogonal "is a cashier on it right now" - the same
                        // free/active/stale classifier the till picker itself uses.
                        $lockState = posTerminalLockState($t, $lockTimeoutMinutes);
                        if ($lockState !== 'free'):
                        ?>
                        <div class="small mt-1">
                            <span class="ui-badge <?php echo $lockState === 'stale' ? 'ui-badge-warning' : 'ui-badge-info'; ?>">
                                <i class="fas fa-user-clock"></i><?php echo $lockState === 'stale' ? 'Stale lock' : 'In session'; ?>
                            </span>
                            <div class="text-muted mt-1">
                                <?php echo htmlspecialchars($t['locked_by_username'] ?? ''); ?><br>
                                started <?php echo htmlspecialchars($t['locked_at'] ? date('M j, H:i', strtotime($t['locked_at'])) : '-'); ?>,
                                last active <?php echo htmlspecialchars($t['last_activity_at'] ? date('H:i', strtotime($t['last_activity_at'])) : '-'); ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="d-flex gap-1 justify-content-end">
                            <?php if ($lockState !== 'free'): ?>
                            <form method="post" onsubmit="return confirm('Force-release &quot;<?php echo htmlspecialchars($t['name'], ENT_QUOTES); ?>&quot;? <?php echo htmlspecialchars($t['locked_by_username'] ?? 'The cashier', ENT_QUOTES); ?> will be signed out of this till and it becomes available immediately. Continue?');">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="force_release">
                                <input type="hidden" name="id" value="<?php echo (int)$t['id']; ?>">
                                <button class="ui-btn ui-btn-secondary ui-btn-sm ui-btn-icon"
                                        title="Force-release this till"
                                        aria-label="Force-release <?php echo htmlspecialchars($t['name'], ENT_QUOTES); ?>">
                                    <i class="fas fa-lock-open"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                            <button type="button" class="ui-btn ui-btn-secondary ui-btn-sm ui-btn-icon"
                                    title="Edit <?php echo htmlspecialchars($t['name'], ENT_QUOTES); ?>"
                                    aria-label="Edit <?php echo htmlspecialchars($t['name'], ENT_QUOTES); ?>"
                                    data-ui-modal="#terminalModal"
                                    data-title="Edit till"
                                    data-field-id="<?php echo (int)$t['id']; ?>"
                                    data-field-name="<?php echo htmlspecialchars($t['name'], ENT_QUOTES); ?>"
                                    data-field-code="<?php echo htmlspecialchars($t['code'], ENT_QUOTES); ?>"
                                    data-field-department="<?php echo htmlspecialchars($dept); ?>"
                                    data-field-is-active="<?php echo (int)$t['is_active'] === 1 ? '1' : '0'; ?>">
                                <i class="fas fa-pen"></i>
                            </button>
                            <form method="post" onsubmit="return confirm(<?php
                                echo $count > 0
                                    ? "'\"" . htmlspecialchars($t['name'], ENT_QUOTES) . "\" is used by " . $count . " sale/held sale/cancellation record(s). It will be deactivated rather than deleted, so those records keep their till. Continue?'"
                                    : "'Delete \"" . htmlspecialchars($t['name'], ENT_QUOTES) . "\"? Nothing is using it.'";
                            ?>);">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo (int)$t['id']; ?>">
                                <button class="ui-btn ui-btn-danger ui-btn-sm ui-btn-icon"
                                        title="<?php echo $count > 0 ? 'Deactivate' : 'Delete'; ?>"
                                        aria-label="<?php echo $count > 0 ? 'Deactivate' : 'Delete'; ?> <?php echo htmlspecialchars($t['name'], ENT_QUOTES); ?>">
                                    <i class="fas <?php echo $count > 0 ? 'fa-ban' : 'fa-trash'; ?>"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ============ Add / edit till ============ -->
<div class="modal fade" id="terminalModal" tabindex="-1" aria-labelledby="terminalModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" data-ui-loading>
<?php echo csrfField(); ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="0">
                <div class="modal-header">
                    <h5 class="modal-title" id="terminalModalTitle">
                        <i class="fas fa-cash-register me-2" style="color:var(--color-primary);"></i>
                        <span data-ui-modal-title>Add till</span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label" for="term_name">Name<span class="ui-required">*</span></label>
                        <input type="text" class="form-control" id="term_name" name="name" required maxlength="80"
                               placeholder="e.g. Front Counter" autocomplete="off">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="term_code">Code<span class="ui-required">*</span></label>
                        <input type="text" class="form-control" id="term_code" name="code" required maxlength="20"
                               placeholder="e.g. TILL-02" style="text-transform:uppercase;" autocomplete="off">
                        <div class="form-text">Must be unique. Shown on receipts.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="term_department">Department<span class="ui-required">*</span></label>
                        <select class="form-select" id="term_department" name="department" required>
                            <?php foreach ($departments as $key => $d):
                                $deptOn = catalogDepartmentEnabled($conn, $key); ?>
                            <option value="<?php echo htmlspecialchars($key); ?>">
                                <?php echo htmlspecialchars($d['label']); ?><?php echo $deptOn ? '' : ' (disabled)'; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Which department's stock this till opens on by default.</div>
                    </div>
                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox" name="is_active" id="term_active" checked>
                        <label class="form-check-label" for="term_active">Active — can be selected on the till</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="ui-btn ui-btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="ui-btn ui-btn-primary"><i class="fas fa-check"></i>Save till</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$pageScript = <<<'HTML'
<script>
// "Add" reuses the same modal, so it has to be reset - otherwise it
// would still hold whichever till was edited last.
function newTerminal() {
    const modal = document.getElementById('terminalModal');
    modal.querySelector('[data-ui-modal-title]').textContent = 'Add till';
    modal.querySelector('[name="id"]').value = '0';
    modal.querySelector('[name="name"]').value = '';
    modal.querySelector('[name="code"]').value = '';
    modal.querySelector('[name="department"]').selectedIndex = 0;
    modal.querySelector('[name="is_active"]').checked = true;
}
</script>
HTML;
include 'inventory-footer.php';
?>
