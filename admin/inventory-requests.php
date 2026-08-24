<?php
// ============================================================
// Admin - Stock Requests
// Shop floor staff request stock for store use (cleaning supplies,
// till rolls, carrier bags, display samples); the storekeeper
// approves, rejects or partially approves. Inventory is deducted
// ONLY for approved quantities. Complete history is kept.
// ============================================================
require_once '../includes/auth.php';
requireModule('stock_requests');
// Reject any POST that does not carry this session's CSRF token.
// Placed before every handler on this page, and a no-op on GET.
csrfRequire();
date_default_timezone_set('Africa/Dar_es_Salaam');
$current_page = basename($_SERVER['PHP_SELF']);
include '../includes/db.php';
require_once '../includes/inventory_functions.php';
require_once '../includes/catalog_functions.php';   // department filtering
catalogBoot($conn);

$currentRole = $_SESSION['role'] ?? '';
$canReview   = userCan('inventory');
$userId      = (int)($_SESSION['id'] ?? 0);
$userName    = $_SESSION['username'] ?? '';

function reqFlash($type, $msg) { $_SESSION['inv_flash'] = ['type' => $type, 'msg' => $msg]; }

// ---------- POST ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Any staff member can submit a request.
    if ($action === 'create') {
        $purpose = trim($_POST['purpose'] ?? '');
        $lines = [];
        $itemIds = $_POST['item_id'] ?? [];
        $qtys    = $_POST['qty'] ?? [];
        foreach ((array)$itemIds as $i => $itemId) {
            $lines[] = ['item_id' => (int)$itemId, 'qty' => (float)($qtys[$i] ?? 0)];
        }
        [$ok, $msg] = createStockRequest($conn, $userId, $userName, $purpose, $lines);
        reqFlash($ok ? 'success' : 'danger', $ok ? "Request $msg submitted - waiting for stockkeeper approval." : $msg);
        header('Location: inventory-requests.php'); exit;
    }

    // Only admin/manager review.
    if ($action === 'review') {
        if (!$canReview) {
            reqFlash('danger', 'Only admin/manager can review stock requests.');
        } else {
            $requestId = (int)($_POST['request_id'] ?? 0);
            $note = trim($_POST['review_note'] ?? '');
            $approved = [];
            if (($_POST['decision'] ?? '') === 'reject') {
                // Reject everything: zero approved quantities.
            } else {
                foreach ((array)($_POST['approve_qty'] ?? []) as $lineId => $qty) {
                    $approved[(int)$lineId] = (float)$qty;
                }
            }
            [$ok, $msg] = reviewStockRequest($conn, $requestId, $approved, $userId, $note);
            reqFlash($ok ? 'success' : 'danger', $msg);
        }
        header('Location: inventory-requests.php'); exit;
    }
}

// ---------- Data ----------
// The status is allow-listed AND bound. The allow-list is what keeps an
// unknown value from silently returning nothing; binding is what keeps
// the value out of the SQL text regardless.
$statusFilter = $_GET['status'] ?? '';
$statusValid  = in_array($statusFilter, ['pending','approved','partially_approved','rejected'], true);

// A cashier (or anyone else without review rights) can raise requests but
// must only ever see their own - reviewers keep the full list. Built as
// conds/params/types together so the type string can't drift from the
// argument list as filters are added.
$conds = [];
$params = [];
$types = '';
if ($statusValid) { $conds[] = 'r.status = ?'; $params[] = $statusFilter; $types .= 's'; }
if (!$canReview) { $conds[] = 'r.requested_by = ?'; $params[] = $userId; $types .= 'i'; }
$where = $conds ? implode(' AND ', $conds) : '1=1';

$stmt = $conn->prepare(
    "SELECT r.*,
            (SELECT COUNT(*) FROM inv_stock_request_lines l WHERE l.request_id = r.id) AS line_count
     FROM inv_stock_requests r
     WHERE $where
     ORDER BY r.created_at DESC
     LIMIT 300");
if ($params) { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Lines per request (for the expandable review blocks).
$linesByRequest = [];
$lineRes = $conn->query(
    "SELECT l.*, i.name AS item_name, i.current_stock, u.abbreviation AS unit
     FROM inv_stock_request_lines l
     JOIN inv_items i ON i.id = l.item_id
     LEFT JOIN inv_units u ON u.id = i.unit_id
     ORDER BY l.id");
if ($lineRes) {
    foreach ($lineRes->fetch_all(MYSQLI_ASSOC) as $l) { $linesByRequest[(int)$l['request_id']][] = $l; }
}

// Items for the new-request form.
$activeItems = $conn->query(
    "SELECT i.id, i.name, i.current_stock, u.abbreviation AS unit
     FROM inv_items i LEFT JOIN inv_units u ON u.id = i.unit_id
     WHERE i.status = 'active' AND i.deleted_at IS NULL"
     . catalogDepartmentFilterSql($conn, 'i') . " ORDER BY i.name")->fetch_all(MYSQLI_ASSOC);

// Same own-requests-only scope as the list above, so a cashier's status
// badges never imply visibility into totals they can't actually see.
$statusCounts = [];
if ($canReview) {
    $scRes = $conn->query("SELECT status, COUNT(*) AS c FROM inv_stock_requests GROUP BY status");
} else {
    $scStmt = $conn->prepare("SELECT status, COUNT(*) AS c FROM inv_stock_requests WHERE requested_by = ? GROUP BY status");
    $scStmt->bind_param('i', $userId);
    $scStmt->execute();
    $scRes = $scStmt->get_result();
    $scStmt->close();
}
if ($scRes) { foreach ($scRes->fetch_all(MYSQLI_ASSOC) as $r) { $statusCounts[$r['status']] = (int)$r['c']; } }

$statusMeta = [
    'pending'            => ['Pending', 'warning', 'fa-hourglass-half'],
    'approved'           => ['Approved', 'success', 'fa-check-circle'],
    'partially_approved' => ['Partially Approved', 'info', 'fa-adjust'],
    'rejected'           => ['Rejected', 'danger', 'fa-times-circle'],
];

$pageTitle = 'Stock Requests';
$breadcrumbs = [['Dashboard', 'index.php'], ['Inventory', 'inventory-dashboard.php'], ['Stock Requests']];
include 'inventory-header.php';
?>
<div class="inv-page-header">
    <div>
        <h1><i class="fas fa-clipboard-list me-2" style="color:var(--inv-primary);"></i>Stock Requests</h1>
        <div class="subtitle">Staff request stock for store use; the storekeeper approves, and only approved quantities leave inventory.</div>
    </div>
    <button class="btn btn-inv" data-bs-toggle="modal" data-bs-target="#newRequestModal"><i class="fas fa-plus me-1"></i> New Request</button>
</div>

<div class="mb-3" style="display:flex;flex-wrap:wrap;gap:6px;">
    <a href="inventory-requests.php" class="btn btn-sm <?php echo $statusFilter === '' ? 'btn-inv' : 'btn-outline-secondary'; ?>" style="border-radius:20px;">All (<?php echo array_sum($statusCounts); ?>)</a>
    <?php foreach ($statusMeta as $key => $meta): ?>
    <a href="?status=<?php echo $key; ?>" class="btn btn-sm <?php echo $statusFilter === $key ? 'btn-inv' : 'btn-outline-secondary'; ?>" style="border-radius:20px;"><?php echo $meta[0]; ?> (<?php echo $statusCounts[$key] ?? 0; ?>)</a>
    <?php endforeach; ?>
</div>

<?php if (!$requests): ?>
<div class="inv-card"><div class="empty-state"><i class="fas fa-clipboard-list d-block"></i>No stock requests<?php echo $statusFilter ? ' with this status' : ' yet'; ?>.</div></div>
<?php endif; ?>

<?php foreach ($requests as $r):
    $meta = $statusMeta[$r['status']];
    $lines = $linesByRequest[(int)$r['id']] ?? [];
    $isPending = $r['status'] === 'pending';
?>
<div class="inv-card p-3 mb-3">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div>
            <strong style="font-size:1.05rem;"><?php echo htmlspecialchars($r['request_no']); ?></strong>
            <span class="inv-badge bg-<?php echo $meta[1]; ?> <?php echo $meta[1] === 'warning' ? 'text-dark' : 'text-white'; ?> ms-1"><i class="fas <?php echo $meta[2]; ?> me-1"></i><?php echo $meta[0]; ?></span>
            <div class="text-muted" style="font-size:.83rem;">
                Requested by <strong><?php echo htmlspecialchars($r['requested_by_name'] ?? '-'); ?></strong>
                on <?php echo date('d M Y, H:i', strtotime($r['created_at'])); ?>
                <?php if ($r['purpose']): ?> &middot; <?php echo htmlspecialchars($r['purpose']); ?><?php endif; ?>
            </div>
            <?php if ($r['reviewed_at']): ?>
            <div class="text-muted" style="font-size:.8rem;">Reviewed <?php echo date('d M Y, H:i', strtotime($r['reviewed_at'])); ?><?php echo $r['review_note'] ? ' - "' . htmlspecialchars($r['review_note']) . '"' : ''; ?></div>
            <?php endif; ?>
        </div>
        <button class="btn btn-sm btn-outline-secondary" style="border-radius:8px;" data-bs-toggle="collapse" data-bs-target="#reqLines<?php echo (int)$r['id']; ?>">
            <i class="fas fa-list me-1"></i><?php echo (int)$r['line_count']; ?> item(s)
        </button>
    </div>

    <div class="collapse <?php echo $isPending && $canReview ? 'show' : ''; ?>" id="reqLines<?php echo (int)$r['id']; ?>">
        <form method="post" class="mt-3">
<?php echo csrfField(); ?>
            <input type="hidden" name="action" value="review">
            <input type="hidden" name="request_id" value="<?php echo (int)$r['id']; ?>">
            <div class="table-responsive">
                <table class="inv-table">
                    <thead><tr><th>Item</th><th>Requested</th><th>In Stock</th><th><?php echo $isPending ? 'Approve Qty' : 'Approved'; ?></th></tr></thead>
                    <tbody>
                        <?php foreach ($lines as $l): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($l['item_name']); ?></td>
                            <td><?php echo invQty($l['qty_requested']); ?> <?php echo htmlspecialchars($l['unit'] ?? ''); ?></td>
                            <td><?php echo invQty($l['current_stock']); ?></td>
                            <td>
                                <?php if ($isPending && $canReview): ?>
                                    <input type="number" step="0.001" min="0" max="<?php echo (float)$l['qty_requested']; ?>"
                                           class="form-control form-control-sm" style="max-width:130px;border-radius:8px;"
                                           name="approve_qty[<?php echo (int)$l['id']; ?>]" value="<?php echo (float)$l['qty_requested']; ?>">
                                <?php elseif ($isPending): ?>
                                    <span class="text-muted">awaiting review</span>
                                <?php else: ?>
                                    <?php echo $l['qty_approved'] !== null ? invQty($l['qty_approved']) : '-'; ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($isPending && $canReview): ?>
            <div class="d-flex gap-2 flex-wrap align-items-center mt-2">
                <input type="text" class="form-control" name="review_note" placeholder="Review note (optional)" style="max-width:320px;border-radius:10px;">
                <button type="submit" name="decision" value="approve" class="btn btn-success" style="border-radius:10px;"
                        onclick="return confirm('Approve with the quantities entered above? Approved stock will be deducted from inventory.');">
                    <i class="fas fa-check me-1"></i>Approve / Partially Approve
                </button>
                <button type="submit" name="decision" value="reject" class="btn btn-outline-danger" style="border-radius:10px;"
                        onclick="return confirm('Reject this entire request? No stock will be deducted.');">
                    <i class="fas fa-times me-1"></i>Reject All
                </button>
            </div>
            <div class="form-text mt-1">Lower a quantity (even to 0) for a partial approval. Only the approved amounts are deducted.</div>
            <?php endif; ?>
        </form>
    </div>
</div>
<?php endforeach; ?>

<!-- New Request modal -->
<div class="modal fade" id="newRequestModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post">
<?php echo csrfField(); ?>
                <input type="hidden" name="action" value="create">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-clipboard-list me-2"></i>New Stock Request</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Purpose / Notes</label>
                        <input type="text" class="form-control" name="purpose" placeholder="e.g. Carrier bags for the front till">
                    </div>
                    <label class="form-label">Items</label>
                    <div id="requestLines"></div>
                    <button type="button" class="btn btn-sm btn-outline-secondary mt-2" style="border-radius:8px;" onclick="addRequestLine()"><i class="fas fa-plus me-1"></i>Add Item</button>
                    <div class="form-text mt-2">Requested by: <strong><?php echo htmlspecialchars($userName); ?></strong> - nothing is deducted until the stockkeeper approves.</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-inv">Submit Request</button>
                </div>
            </form>
        </div>
    </div>
</div>

<template id="lineTemplate">
    <div class="d-flex gap-2 mb-2 request-line">
        <select class="form-select" name="item_id[]" required>
            <option value="">Choose an item...</option>
            <?php foreach ($activeItems as $it): ?>
            <option value="<?php echo (int)$it['id']; ?>"><?php echo htmlspecialchars($it['name']); ?> (stock: <?php echo invQty($it['current_stock']); ?> <?php echo htmlspecialchars($it['unit'] ?? ''); ?>)</option>
            <?php endforeach; ?>
        </select>
        <input type="number" class="form-control" name="qty[]" step="0.001" min="0.001" placeholder="Qty" style="max-width:130px;" required>
        <button type="button" class="btn btn-outline-danger" style="border-radius:8px;" onclick="this.closest('.request-line').remove()" aria-label="Remove line" title="Remove line"><i class="fas fa-times"></i></button>
    </div>
</template>

<?php
$pageScript = <<<HTML
<script>
function addRequestLine() {
    const tpl = document.getElementById('lineTemplate');
    document.getElementById('requestLines').appendChild(tpl.content.cloneNode(true));
}
addRequestLine();
</script>
HTML;
include 'inventory-footer.php';
?>
