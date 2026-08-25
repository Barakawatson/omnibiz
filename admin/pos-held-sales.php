<?php
// ============================================================
// POS - Held Sales Review (manager/admin)
// ------------------------------------------------------------
// A cashier only ever sees their OWN active held sales, on their own
// currently-locked terminal (posGetHeldSalesForCashier() in pos.php) -
// enforced server-side, not just by what a screen chooses to render.
// This page is the other half: a supervisor surface over the SAME
// pos_held_sales rows, for the cases a cashier's own view can never
// show - a sale orphaned by a logout, or expired from inactivity - and
// the one authorized way to bring one back into use.
//
// Recovery reassigns the ORIGINAL row to a currently-active cashier
// (read from that terminal's own live lock, never a separately typed
// account - see includes/pos_functions.php's posRecoverHeldSale()) and
// reopens it as 'held'. Nothing is duplicated and nothing is deleted;
// the full history survives in inv_audit_log regardless of how many
// times a row's status changes.
// ============================================================
require_once '../includes/auth.php';
requireModule('held_sales_review');
// Reject any POST that does not carry this session's CSRF token.
// Placed before every handler on this page, and a no-op on GET.
csrfRequire();
$current_page = basename($_SERVER['PHP_SELF']);
include '../includes/db.php';
require_once '../includes/pos_functions.php';
posBoot($conn);

$userId = (int)($_SESSION['id'] ?? 0);

function heldFlash($type, $msg) { $_SESSION['inv_flash'] = ['type' => $type, 'msg' => $msg]; }

/** Badge class + label for a held sale's current status. */
function heldStatusBadge(string $status): array {
    switch ($status) {
        case 'held':      return ['ui-badge-neutral', 'Held'];
        case 'stale':     return ['ui-badge-warning', 'Ageing'];
        case 'expired':   return ['ui-badge-danger', 'Expired'];
        case 'orphaned':  return ['ui-badge-danger', 'Orphaned'];
        case 'resumed':   return ['ui-badge-info', 'Resumed'];
        case 'completed': return ['ui-badge-success', 'Completed'];
        case 'cancelled': return ['ui-badge-neutral', 'Cancelled'];
        default:          return ['ui-badge-neutral', ucfirst($status)];
    }
}

/** "2h 15m" / "38m" style age from a timestamp to now. */
function heldAge(string $timestamp): string {
    $minutes = max(0, (int)((time() - strtotime($timestamp)) / 60));
    if ($minutes < 60) { return $minutes . 'm'; }
    return (int)($minutes / 60) . 'h ' . ($minutes % 60) . 'm';
}

// ---------- POST ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'recover') {
    $heldSaleId = (int)($_POST['id'] ?? 0);
    $targetTerminalId = (int)($_POST['terminal_id'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');

    // The target cashier is read from the chosen terminal's own current
    // lock, not a separately-typed account - and that lock must be
    // genuinely active (not merely present but stale), so recovery can
    // only ever hand a sale to someone actually logged in right now.
    $timeoutMinutes = posTerminalLockTimeoutMinutes($conn);
    $targetTerminal = posGetTerminal($conn, $targetTerminalId);
    $lockState = $targetTerminal ? posTerminalLockState($targetTerminal, $timeoutMinutes) : 'free';
    $newCashierId = (int)($targetTerminal['locked_by_user_id'] ?? 0);
    $newCashierName = (string)($targetTerminal['locked_by_username'] ?? '');

    if (!$targetTerminal || $lockState !== 'active' || $newCashierId <= 0) {
        heldFlash('danger', 'That till does not have an actively logged-in cashier right now - pick a till that is actively in use.');
    } else {
        [$ok, $msg] = posRecoverHeldSale($conn, $heldSaleId, $newCashierId, $newCashierName, $targetTerminalId, $userId, $reason);
        heldFlash($ok ? 'success' : 'danger', $msg);
    }
    header('Location: pos-held-sales.php'); exit;
}

// ---------- Data ----------
$heldSales = posGetHeldSalesForReview($conn);
$needsAttention = array_values(array_filter($heldSales, function ($h) {
    return in_array($h['status'], ['orphaned', 'expired'], true);
}));

// Only a terminal someone is genuinely, actively logged into right now
// is offered as a recovery target - reusing Phase 2's live lock data
// rather than a separate "who's on shift" list this app doesn't have.
$timeoutMinutes = posTerminalLockTimeoutMinutes($conn);
$activeTerminals = array_values(array_filter(posGetTerminals($conn), function ($t) use ($timeoutMinutes) {
    return posTerminalLockState($t, $timeoutMinutes) === 'active';
}));

$totals = ['needs_attention' => count($needsAttention), 'total' => count($heldSales)];

$pageTitle = 'Held Sales Review';
$breadcrumbs = [['Dashboard', 'index.php'], ['Held Sales Review']];
include 'inventory-header.php';

$ph = [
    'title'    => 'Held Sales Review',
    'icon'     => 'fa-pause',
    'subtitle' => 'Every parked sale across every till - a cashier only ever sees their own. '
                . 'Orphaned and expired ones can be recovered to a cashier who is actively logged in right now.',
];
include 'partials/page-header.php';
?>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-4">
        <div class="inv-stat-card bg-grad-amber">
            <div class="d-flex justify-content-between align-items-start">
                <div><div class="label">Needs attention</div><div class="value"><?php echo number_format($totals['needs_attention']); ?></div></div>
                <i class="fas fa-triangle-exclamation icon"></i>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="inv-stat-card bg-grad-teal">
            <div class="d-flex justify-content-between align-items-start">
                <div><div class="label">Total on record</div><div class="value"><?php echo number_format($totals['total']); ?></div></div>
                <i class="fas fa-pause icon"></i>
            </div>
        </div>
    </div>
</div>

<div class="inv-card p-0 mb-4">
    <div class="ui-card-head"><h2 class="ui-card-title"><i class="fas fa-triangle-exclamation me-2"></i>Needs attention</h2></div>
    <div class="ui-table-wrap">
        <table class="inv-table">
            <thead>
                <tr>
                    <th>Cashier</th>
                    <th>Till</th>
                    <th>Department</th>
                    <th class="text-end">Amount</th>
                    <th>Status</th>
                    <th>Held for</th>
                    <th style="width:110px;"><span class="visually-hidden">Actions</span></th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$needsAttention): ?>
                <tr><td colspan="7">
                    <?php
                    $es = ['icon' => 'fa-circle-check', 'title' => 'Nothing needs attention',
                           'msg'  => 'No orphaned or expired held sales right now.'];
                    include 'partials/empty-state.php';
                    ?>
                </td></tr>
            <?php endif; ?>
            <?php foreach ($needsAttention as $h):
                [$badgeClass, $badgeLabel] = heldStatusBadge($h['status']);
                $deptLabel = $h['terminal_department'] ? catalogDepartmentLabel($h['terminal_department']) : '-';
                $context = htmlspecialchars($h['label'] . ' - ' . ($h['cashier_name'] ?? 'unknown cashier')
                    . ' on ' . ($h['terminal_name'] ?? 'an unknown till'), ENT_QUOTES);
            ?>
                <tr>
                    <td><?php echo htmlspecialchars($h['cashier_name'] ?? '-'); ?></td>
                    <td><?php echo htmlspecialchars($h['terminal_name'] ?? '-'); ?></td>
                    <td><?php echo htmlspecialchars($deptLabel); ?></td>
                    <td class="text-end ui-num">Tsh <?php echo number_format((float)$h['total_estimate']); ?></td>
                    <td><span class="ui-badge <?php echo $badgeClass; ?>"><?php echo $badgeLabel; ?></span></td>
                    <td><?php echo heldAge($h['created_at']); ?></td>
                    <td>
                        <div class="d-flex gap-1 justify-content-end">
                            <button type="button" class="ui-btn ui-btn-secondary ui-btn-sm ui-btn-icon"
                                    title="View workflow history" aria-label="View workflow history"
                                    data-history-id="<?php echo (int)$h['id']; ?>">
                                <i class="fas fa-clock-rotate-left"></i>
                            </button>
                            <button type="button" class="ui-btn ui-btn-primary ui-btn-sm"
                                    data-ui-modal="#recoverModal"
                                    data-title="Recover held sale"
                                    data-field-id="<?php echo (int)$h['id']; ?>"
                                    data-field-context="<?php echo $context; ?>">
                                Recover
                            </button>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="inv-card p-0">
    <div class="ui-card-head"><h2 class="ui-card-title">All held sales (reference)</h2></div>
    <div class="ui-table-wrap">
        <table class="inv-table">
            <thead>
                <tr>
                    <th>Cashier</th>
                    <th>Till</th>
                    <th>Department</th>
                    <th class="text-end">Amount</th>
                    <th>Status</th>
                    <th>Age</th>
                    <th>Notes</th>
                    <th style="width:56px;"><span class="visually-hidden">History</span></th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$heldSales): ?>
                <tr><td colspan="8">
                    <?php
                    $es = ['icon' => 'fa-pause', 'title' => 'No held sales on record', 'msg' => 'Nothing has ever been parked.'];
                    include 'partials/empty-state.php';
                    ?>
                </td></tr>
            <?php endif; ?>
            <?php foreach ($heldSales as $h):
                [$badgeClass, $badgeLabel] = heldStatusBadge($h['status']);
                $deptLabel = $h['terminal_department'] ? catalogDepartmentLabel($h['terminal_department']) : '-';
                $notes = [];
                if ($h['status'] === 'completed' && $h['resulting_txn_id']) {
                    $notes[] = '<a href="pos-receipt.php?id=' . (int)$h['resulting_txn_id'] . '">Sale #' . (int)$h['resulting_txn_id'] . '</a>';
                }
                if ($h['recovered_at']) {
                    $notes[] = 'Recovered ' . date('M j, H:i', strtotime($h['recovered_at']))
                        . ($h['recovery_reason'] ? ' - ' . htmlspecialchars($h['recovery_reason']) : '');
                }
            ?>
                <tr>
                    <td><?php echo htmlspecialchars($h['cashier_name'] ?? '-'); ?></td>
                    <td><?php echo htmlspecialchars($h['terminal_name'] ?? '-'); ?></td>
                    <td><?php echo htmlspecialchars($deptLabel); ?></td>
                    <td class="text-end ui-num">Tsh <?php echo number_format((float)$h['total_estimate']); ?></td>
                    <td><span class="ui-badge <?php echo $badgeClass; ?>"><?php echo $badgeLabel; ?></span></td>
                    <td><?php echo heldAge($h['created_at']); ?></td>
                    <td class="small text-muted"><?php echo $notes ? implode('<br>', $notes) : '-'; ?></td>
                    <td class="text-end">
                        <button type="button" class="ui-btn ui-btn-secondary ui-btn-sm ui-btn-icon"
                                title="View workflow history" aria-label="View workflow history"
                                data-history-id="<?php echo (int)$h['id']; ?>">
                            <i class="fas fa-clock-rotate-left"></i>
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- ============ Recover an orphaned/expired held sale ============ -->
<div class="modal fade" id="recoverModal" tabindex="-1" aria-labelledby="recoverModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" data-ui-loading>
<?php echo csrfField(); ?>
                <input type="hidden" name="action" value="recover">
                <input type="hidden" name="id" value="0">
                <div class="modal-header">
                    <h5 class="modal-title" id="recoverModalTitle">
                        <i class="fas fa-rotate-left me-2" style="color:var(--color-primary);"></i>
                        <span data-ui-modal-title>Recover held sale</span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted mb-2" data-ui-text="context"></p>
                    <div class="mb-3">
                        <div class="small text-muted fw-semibold mb-1">Workflow so far</div>
                        <ol class="list-unstyled small mb-0" id="recoverTimeline"
                            style="max-height:180px; overflow-y:auto; border:1px solid var(--color-border); border-radius:8px; padding:8px 10px;">
                            <li class="text-muted">Loading&hellip;</li>
                        </ol>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="recover_terminal">Give it to<span class="ui-required">*</span></label>
                        <select class="form-select" id="recover_terminal" name="terminal_id" required>
                            <option value="" selected disabled>Choose an actively logged-in till&hellip;</option>
                            <?php foreach ($activeTerminals as $t): ?>
                            <option value="<?php echo (int)$t['id']; ?>">
                                <?php echo htmlspecialchars($t['name'] . ' (' . $t['code'] . ') - ' . $t['locked_by_username']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if (!$activeTerminals): ?>
                        <div class="form-text text-danger">No till has a cashier actively logged in right now - ask one to sign in first.</div>
                        <?php endif; ?>
                    </div>
                    <div class="mb-0">
                        <label class="form-label" for="recover_reason">Reason<span class="ui-required">*</span></label>
                        <textarea class="form-control" id="recover_reason" name="reason" rows="2" required maxlength="255"
                                  placeholder="Why this is being recovered, and to whom"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="ui-btn ui-btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="ui-btn ui-btn-primary" <?php echo $activeTerminals ? '' : 'disabled'; ?>>
                        <i class="fas fa-check"></i>Recover
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============ Full workflow history - read only ============ -->
<div class="modal fade" id="historyModal" tabindex="-1" aria-labelledby="historyModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="historyModalTitle">
                    <i class="fas fa-clock-rotate-left me-2" style="color:var(--color-primary);"></i>Workflow history
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <ol class="list-unstyled small mb-0" id="fullHistoryList"
                    style="max-height:320px; overflow-y:auto;">
                    <li class="text-muted">Loading&hellip;</li>
                </ol>
            </div>
            <div class="modal-footer">
                <button type="button" class="ui-btn ui-btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<?php
$pageScript = <<<'HTML'
<script>
// Renders one held sale's inv_audit_log trail into a <ol>. Built with
// createElement/textContent throughout - never innerHTML - because
// reason/detail text in the trail can contain whatever a cashier or
// manager typed (recovery_reason, cancellation reasons, etc.).
function renderHeldTimeline(listEl, trail) {
    listEl.textContent = '';
    if (!trail.length) {
        const li = document.createElement('li');
        li.className = 'text-muted';
        li.textContent = 'No history recorded yet.';
        listEl.appendChild(li);
        return;
    }
    trail.forEach(function (entry) {
        const li = document.createElement('li');
        li.className = 'd-flex justify-content-between align-items-start gap-2 py-1 border-bottom';

        const left = document.createElement('div');
        const label = document.createElement('div');
        label.className = 'fw-semibold';
        label.textContent = entry.label;
        left.appendChild(label);
        if (entry.details) {
            const details = document.createElement('div');
            details.className = 'text-muted';
            details.textContent = entry.details;
            left.appendChild(details);
        }
        const who = document.createElement('div');
        who.className = 'text-muted';
        who.textContent = entry.username;
        left.appendChild(who);

        const when = document.createElement('div');
        when.className = 'text-muted text-nowrap';
        when.textContent = entry.created_at;

        li.appendChild(left);
        li.appendChild(when);
        listEl.appendChild(li);
    });
}

function loadHeldTimeline(heldSaleId, listEl) {
    listEl.textContent = '';
    const loading = document.createElement('li');
    loading.className = 'text-muted';
    loading.textContent = 'Loading…';
    listEl.appendChild(loading);

    fetch('api/pos-held-sale-history.php?id=' + encodeURIComponent(heldSaleId), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (d) {
            renderHeldTimeline(listEl, (d.ok && Array.isArray(d.trail)) ? d.trail : []);
        })
        .catch(function () {
            listEl.textContent = '';
            const err = document.createElement('li');
            err.className = 'text-danger';
            err.textContent = 'Could not load history.';
            listEl.appendChild(err);
        });
}

// Recover modal: load this held sale's timeline every time it opens,
// using the id MX.fillModal() just set on the form's hidden input.
document.getElementById('recoverModal').addEventListener('shown.bs.modal', function (e) {
    const id = e.target.querySelector('input[name="id"]').value;
    loadHeldTimeline(id, document.getElementById('recoverTimeline'));
});

// Standalone "History" icon buttons, on both tables.
document.querySelectorAll('[data-history-id]').forEach(function (btn) {
    btn.addEventListener('click', function () {
        const modal = document.getElementById('historyModal');
        bootstrap.Modal.getOrCreateInstance(modal).show();
        loadHeldTimeline(btn.dataset.historyId, document.getElementById('fullHistoryList'));
    });
});
</script>
HTML;
include 'inventory-footer.php';
?>
