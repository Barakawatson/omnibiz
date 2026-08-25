<?php
// ============================================================
// Admin - Expired Goods Disposal
// ------------------------------------------------------------
// Three states, not two: a storekeeper requests disposal of currently
// expired, in-stock items; only admin/manager can APPROVE or REJECT
// (authorization only - no stock movement yet); a later, separate
// CONFIRM DISPOSAL step - open to anyone with inventory access, same
// as receiving a purchase order needs only 'purchasing' rather than
// 'purchasing_approve' - is what actually deducts stock and posts the
// loss to the ledger. See createDisposalRequest() / approveDisposalRequest()
// / executeDisposalRequest() in includes/inventory_functions.php.
// ============================================================
require_once '../includes/auth.php';
requireModule('inventory');
// Reject any POST that does not carry this session's CSRF token.
// Placed before every handler on this page, and a no-op on GET.
csrfRequire();
date_default_timezone_set('Africa/Dar_es_Salaam');
$current_page = basename($_SERVER['PHP_SELF']);
include '../includes/db.php';
require_once '../includes/inventory_functions.php';
require_once '../includes/catalog_functions.php';   // department filtering
catalogBoot($conn);

// Approving or rejecting a disposal request is a loss-authorization
// decision, not operational work - only admin/manager hold
// 'disposal_approve' (see roleModules() in includes/auth.php).
// A storekeeper keeps the rest of the job: flag expired stock, request
// its disposal, and confirm disposal once it's approved.
$canApprove = userCan('disposal_approve');
$userId     = (int)($_SESSION['id'] ?? 0);
$userName   = $_SESSION['username'] ?? '';

function disposalFlash($type, $msg) { $_SESSION['inv_flash'] = ['type' => $type, 'msg' => $msg]; }

// ---------- POST ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Enforced here, before any handler. Hiding the buttons is a courtesy
    // to the user, not a security control - a forged POST from a
    // storekeeper's session is refused just the same, and logged.
    if ($action === 'review' && !$canApprove) {
        $requestId = (int)($_POST['request_id'] ?? 0);
        invAudit($conn, $userId, 'denied_review', 'disposal_request', $requestId, '');
        disposalFlash('danger', 'Only a manager or administrator can approve or reject a disposal request.');
        header('Location: inventory-disposal.php'); exit;
    }

    // Any staff member with inventory access can request disposal of
    // expired stock they find.
    if ($action === 'create') {
        $reason = trim($_POST['reason'] ?? '');
        $lines = [];
        // Each option's value is 'b<batch_id>' (the normal, batch-targeted
        // path) or 'i<item_id>' (the untracked-stock fallback line) - see
        // the disposalLineTemplate below. createDisposalRequest() re-locks
        // and re-validates whichever one this resolves to; nothing here
        // is trusted past that.
        $keys = $_POST['line_key'] ?? [];
        $qtys = $_POST['qty'] ?? [];
        foreach ((array)$keys as $i => $key) {
            $qty = (float)($qtys[$i] ?? 0);
            if ($qty <= 0) { continue; }
            if (preg_match('/^b(\d+)$/', (string)$key, $m)) {
                $lines[] = ['batch_id' => (int)$m[1], 'qty' => $qty];
            } elseif (preg_match('/^i(\d+)$/', (string)$key, $m)) {
                $lines[] = ['item_id' => (int)$m[1], 'qty' => $qty];
            }
        }
        [$ok, $msg] = createDisposalRequest($conn, $userId, $userName, $reason, $lines);
        disposalFlash($ok ? 'success' : 'danger', $ok ? "Disposal request $msg submitted - waiting for manager/admin approval." : $msg);
        header('Location: inventory-disposal.php'); exit;
    }

    if ($action === 'review') {
        $requestId = (int)($_POST['request_id'] ?? 0);
        $note = trim($_POST['review_note'] ?? '');
        $decision = ($_POST['decision'] ?? '') === 'reject' ? 'reject' : 'approve';
        $approved = [];
        foreach ((array)($_POST['approve_qty'] ?? []) as $lineId => $qty) {
            $approved[(int)$lineId] = (float)$qty;
        }
        [$ok, $msg] = approveDisposalRequest($conn, $requestId, $decision, $approved, $userId, $userName, $note);
        disposalFlash($ok ? 'success' : 'danger', $msg);
        header('Location: inventory-disposal.php'); exit;
    }

    // Confirm disposal - the physical act. No disposal_approve needed,
    // same reasoning as PO receiving needing only 'purchasing'.
    if ($action === 'execute') {
        $requestId = (int)($_POST['request_id'] ?? 0);
        $note = trim($_POST['reason'] ?? '');
        [$ok, $msg] = executeDisposalRequest($conn, $requestId, $userId, $userName, $note);
        disposalFlash($ok ? 'success' : 'danger', $msg);
        header('Location: inventory-disposal.php'); exit;
    }
}

// ---------- Data ----------
// Currently expired stock, computed PER BATCH rather than from the
// item's blended current_stock. current_stock is the item's TOTAL
// on-hand across every batch, fresh and expired mixed together - an
// item with 100 expired units and 100 fresh ones has current_stock=200,
// and reading that field here would show the fresh half as expired too.
// Reading inv_batches instead means an item with two separate expired
// deliveries correctly becomes two rows, each with its own quantity.
$expiredItems = [];
$batchRes = $conn->query(
    "SELECT b.id AS batch_id, b.item_id, b.batch_no, b.quantity, b.expiry_date,
            i.name, i.sku, i.barcode, i.average_cost, u.abbreviation AS unit, c.name AS category_name,
            DATEDIFF(CURDATE(), b.expiry_date) AS days_expired
     FROM inv_batches b
     JOIN inv_items i ON i.id = b.item_id
     LEFT JOIN inv_units u ON u.id = i.unit_id
     LEFT JOIN inv_categories c ON c.id = i.category_id
     WHERE b.status = 'active' AND b.quantity > 0
       AND b.expiry_date IS NOT NULL AND b.expiry_date < CURDATE()
       AND i.deleted_at IS NULL AND i.status = 'active'"
     . catalogDepartmentFilterSql($conn, 'i') . " ORDER BY b.expiry_date ASC");
if ($batchRes) {
    foreach ($batchRes->fetch_all(MYSQLI_ASSOC) as $b) {
        $expiredItems[] = [
            'batch_id'      => (int)$b['batch_id'],
            'item_id'       => (int)$b['item_id'],
            'name'          => $b['name'],
            'sku'           => $b['sku'],
            'barcode'       => $b['barcode'],
            'expiry_date'   => $b['expiry_date'],
            'quantity'      => (float)$b['quantity'],
            'average_cost'  => (float)$b['average_cost'],
            'unit'          => $b['unit'],
            'category_name' => $b['category_name'],
            'days_expired'  => (int)$b['days_expired'],
            'batch_label'   => 'Batch ' . (($b['batch_no'] ?? '') !== '' ? $b['batch_no'] : ('#' . $b['batch_id'])),
        ];
    }
}

// Defensive fallback: an item whose OWN expiry_date field says expired
// (refreshItemExpiryFromBatches() keeps that field synced to the
// earliest active batch) but whose active batches don't fully cover its
// current_stock - a pre-batch-tracking data gap that shouldn't occur
// post-backfill, but shouldn't silently hide stock either. Surfaces
// only the uncovered remainder, so nothing vanishes from the queue and
// a legacy item with no batches at all still shows its full stock,
// exactly as before this fix.
$fallbackRes = $conn->query(
    "SELECT i.id AS item_id, i.name, i.sku, i.barcode, i.expiry_date, i.current_stock, i.average_cost,
            u.abbreviation AS unit, c.name AS category_name,
            DATEDIFF(CURDATE(), i.expiry_date) AS days_expired,
            COALESCE((SELECT SUM(b2.quantity) FROM inv_batches b2 WHERE b2.item_id = i.id AND b2.status = 'active'), 0) AS batch_covered
     FROM inv_items i
     LEFT JOIN inv_units u ON u.id = i.unit_id
     LEFT JOIN inv_categories c ON c.id = i.category_id
     WHERE i.deleted_at IS NULL AND i.status = 'active'
       AND i.expiry_date IS NOT NULL AND i.expiry_date < CURDATE()
       AND i.current_stock > 0"
     . catalogDepartmentFilterSql($conn, 'i'));
if ($fallbackRes) {
    foreach ($fallbackRes->fetch_all(MYSQLI_ASSOC) as $f) {
        $uncovered = (float)$f['current_stock'] - (float)$f['batch_covered'];
        if ($uncovered <= 0.0005) { continue; }
        $expiredItems[] = [
            'batch_id'      => null,
            'item_id'       => (int)$f['item_id'],
            'name'          => $f['name'],
            'sku'           => $f['sku'],
            'barcode'       => $f['barcode'],
            'expiry_date'   => $f['expiry_date'],
            'quantity'      => $uncovered,
            'average_cost'  => (float)$f['average_cost'],
            'unit'          => $f['unit'],
            'category_name' => $f['category_name'],
            'days_expired'  => (int)$f['days_expired'],
            'batch_label'   => 'Untracked stock',
        ];
    }
}
usort($expiredItems, function ($a, $b) { return strcmp($a['expiry_date'], $b['expiry_date']); });

// Days-expired bucket, used by the Status filter below - every row on
// this table is already "active & expired" (that's the query above),
// so there is no other kind of status left to filter by here.
foreach ($expiredItems as &$it) {
    $d = $it['days_expired'];
    $it['days_bucket'] = $d <= 7 ? '0-7' : ($d <= 14 ? '8-14' : ($d <= 30 ? '15-30' : '31+'));
}
unset($it);

// Only offer categories that actually appear among expired items -
// same "don't offer a filter with nothing behind it" principle the
// reporting module follows.
$expiredCategories = [];
foreach ($expiredItems as $it) {
    if ($it['category_name']) { $expiredCategories[$it['category_name']] = true; }
}
$expiredCategories = array_keys($expiredCategories);
sort($expiredCategories);

// The status is allow-listed AND bound. The allow-list is what keeps an
// unknown value from silently returning nothing; binding is what keeps
// the value out of the SQL text regardless.
$statusFilter = $_GET['status'] ?? '';
$statusValid  = in_array($statusFilter, ['pending','approved','rejected','disposed'], true);
$where = $statusValid ? "r.status = ?" : "1=1";

$stmt = $conn->prepare(
    "SELECT r.*,
            (SELECT COUNT(*) FROM inv_disposal_request_lines l WHERE l.request_id = r.id) AS line_count
     FROM inv_disposal_requests r
     WHERE $where
     ORDER BY r.created_at DESC
     LIMIT 300");
if ($statusValid) { $stmt->bind_param('s', $statusFilter); }
$stmt->execute();
$requests = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Lines per request (for the expandable review/dispose blocks).
$linesByRequest = [];
$lineRes = $conn->query(
    "SELECT l.*, i.name AS item_name, i.sku, u.abbreviation AS unit
     FROM inv_disposal_request_lines l
     JOIN inv_items i ON i.id = l.item_id
     LEFT JOIN inv_units u ON u.id = i.unit_id
     ORDER BY l.id");
if ($lineRes) {
    foreach ($lineRes->fetch_all(MYSQLI_ASSOC) as $l) { $linesByRequest[(int)$l['request_id']][] = $l; }
}

$statusCounts = [];
$scRes = $conn->query("SELECT status, COUNT(*) AS c FROM inv_disposal_requests GROUP BY status");
if ($scRes) { foreach ($scRes->fetch_all(MYSQLI_ASSOC) as $r) { $statusCounts[$r['status']] = (int)$r['c']; } }

$statusMeta = [
    'pending'  => ['Pending', 'warning', 'fa-hourglass-half'],
    'approved' => ['Approved', 'info', 'fa-check-circle'],
    'rejected' => ['Rejected', 'danger', 'fa-times-circle'],
    'disposed' => ['Disposed', 'success', 'fa-trash-can'],
];

$pageTitle = 'Expired Goods Disposal';
$breadcrumbs = [['Dashboard', 'index.php'], ['Inventory', 'inventory-dashboard.php'], ['Expired Goods Disposal']];
include 'inventory-header.php';
?>
<div class="inv-page-header">
    <div>
        <h1><i class="fas fa-trash-can me-2" style="color:var(--inv-primary);"></i>Expired Goods Disposal</h1>
        <div class="subtitle">Request disposal of expired stock; a manager or admin must approve before it can actually be disposed.</div>
    </div>
    <button class="btn btn-inv" data-bs-toggle="modal" data-bs-target="#newDisposalModal"><i class="fas fa-plus me-1"></i> New Disposal Request</button>
</div>

<div class="inv-card p-0 mb-4">
    <div class="p-3 border-bottom"><h6 class="mb-0 fw-bold"><i class="fas fa-calendar-xmark me-2" style="color:var(--inv-primary);"></i>Currently Expired, In Stock</h6></div>

    <div class="p-3 border-bottom">
        <div class="row g-2 align-items-end">
            <div class="col-md-6">
                <label class="form-label">Search</label>
                <div class="input-group">
                    <span class="input-group-text bg-white"><i class="fas fa-search"></i></span>
                    <input type="text" id="expiredSearch" class="form-control" placeholder="Name, SKU or barcode...">
                </div>
            </div>
            <div class="col-md-3">
                <label class="form-label">Category</label>
                <select id="expiredCategoryFilter" class="form-select">
                    <option value="">All categories</option>
                    <?php foreach ($expiredCategories as $cat): ?>
                    <option value="<?php echo htmlspecialchars($cat); ?>"><?php echo htmlspecialchars($cat); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Status</label>
                <select id="expiredDaysFilter" class="form-select">
                    <option value="">All</option>
                    <option value="0-7">Expired 0-7 days ago</option>
                    <option value="8-14">Expired 8-14 days ago</option>
                    <option value="15-30">Expired 15-30 days ago</option>
                    <option value="31+">Expired 31+ days ago</option>
                </select>
            </div>
        </div>
    </div>

    <div class="table-responsive">
        <table class="inv-table" id="expiredStockTable">
            <thead><tr><th>Item</th><th>Batch</th><th>Expired</th><th class="text-end">Qty</th><th class="text-end">Est. Loss Value</th></tr></thead>
            <tbody>
            <?php if (!$expiredItems): ?>
                <tr data-mx-nofilter><td colspan="5"><div class="empty-state"><i class="fas fa-circle-check d-block"></i>Nothing currently expired is still in stock.</div></td></tr>
            <?php endif; ?>
            <?php foreach ($expiredItems as $it):
                $searchHay = strtolower(trim($it['name'] . ' ' . $it['sku'] . ' ' . $it['barcode']));
            ?>
                <tr data-search="<?php echo htmlspecialchars($searchHay, ENT_QUOTES); ?>"
                    data-category="<?php echo htmlspecialchars($it['category_name'] ?? '', ENT_QUOTES); ?>"
                    data-days-bucket="<?php echo $it['days_bucket']; ?>">
                    <td><?php echo htmlspecialchars($it['name']); ?><?php if ($it['sku']): ?> <span class="text-muted" style="font-size:.8rem;">(<?php echo htmlspecialchars($it['sku']); ?>)</span><?php endif; ?></td>
                    <td class="text-muted" style="font-size:.85rem;"><?php echo htmlspecialchars($it['batch_label']); ?></td>
                    <td><span class="inv-badge bg-danger text-white"><?php echo (int)$it['days_expired']; ?> day(s) ago</span></td>
                    <td class="text-end"><?php echo invQty($it['quantity']); ?> <?php echo htmlspecialchars($it['unit'] ?? ''); ?></td>
                    <td class="text-end">Tsh <?php echo number_format($it['quantity'] * $it['average_cost'], 2); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div class="empty-state" id="expiredStockNoMatch" style="display:none;"><i class="fas fa-magnifying-glass d-block"></i>No expired stock matches these filters.</div>
    </div>
</div>

<div class="mb-3" style="display:flex;flex-wrap:wrap;gap:6px;">
    <a href="inventory-disposal.php" class="btn btn-sm <?php echo $statusFilter === '' ? 'btn-inv' : 'btn-outline-secondary'; ?>" style="border-radius:20px;">All (<?php echo array_sum($statusCounts); ?>)</a>
    <?php foreach ($statusMeta as $key => $meta): ?>
    <a href="?status=<?php echo $key; ?>" class="btn btn-sm <?php echo $statusFilter === $key ? 'btn-inv' : 'btn-outline-secondary'; ?>" style="border-radius:20px;"><?php echo $meta[0]; ?> (<?php echo $statusCounts[$key] ?? 0; ?>)</a>
    <?php endforeach; ?>
</div>

<?php if (!$requests): ?>
<div class="inv-card"><div class="empty-state"><i class="fas fa-trash-can d-block"></i>No disposal requests<?php echo $statusFilter ? ' with this status' : ' yet'; ?>.</div></div>
<?php endif; ?>

<?php foreach ($requests as $r):
    $meta = $statusMeta[$r['status']];
    $lines = $linesByRequest[(int)$r['id']] ?? [];
    $isPending  = $r['status'] === 'pending';
    $isApproved = $r['status'] === 'approved';
?>
<div class="inv-card p-3 mb-3">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div>
            <strong style="font-size:1.05rem;"><?php echo htmlspecialchars($r['request_no']); ?></strong>
            <span class="inv-badge bg-<?php echo $meta[1]; ?> <?php echo $meta[1] === 'warning' ? 'text-dark' : 'text-white'; ?> ms-1"><i class="fas <?php echo $meta[2]; ?> me-1"></i><?php echo $meta[0]; ?></span>
            <div class="text-muted" style="font-size:.83rem;">
                Requested by <strong><?php echo htmlspecialchars($r['requested_by_name'] ?? '-'); ?></strong>
                on <?php echo date('d M Y, H:i', strtotime($r['created_at'])); ?>
                <?php if ($r['reason']): ?> &middot; <?php echo htmlspecialchars($r['reason']); ?><?php endif; ?>
            </div>
            <?php if ($r['reviewed_at']): ?>
            <div class="text-muted" style="font-size:.8rem;">Reviewed by <?php echo htmlspecialchars($r['reviewed_by_name'] ?? '-'); ?>, <?php echo date('d M Y, H:i', strtotime($r['reviewed_at'])); ?><?php echo $r['review_note'] ? ' - "' . htmlspecialchars($r['review_note']) . '"' : ''; ?></div>
            <?php endif; ?>
            <?php if ($r['disposed_at']): ?>
            <div class="text-muted" style="font-size:.8rem;">Disposed by <?php echo htmlspecialchars($r['disposed_by_name'] ?? '-'); ?>, <?php echo date('d M Y, H:i', strtotime($r['disposed_at'])); ?></div>
            <?php endif; ?>
        </div>
        <div class="d-flex gap-2 align-items-center">
            <?php if ($isApproved || $r['status'] === 'disposed'): ?>
            <a class="btn btn-sm btn-outline-secondary" style="border-radius:8px;" target="_blank"
               href="inventory-disposal-print.php?id=<?php echo (int)$r['id']; ?>" title="Print disposal certificate">
                <i class="fas fa-print"></i>
            </a>
            <?php endif; ?>
            <button class="btn btn-sm btn-outline-secondary" style="border-radius:8px;" data-bs-toggle="collapse" data-bs-target="#disLines<?php echo (int)$r['id']; ?>">
                <i class="fas fa-list me-1"></i><?php echo (int)$r['line_count']; ?> item(s)
            </button>
        </div>
    </div>

    <div class="collapse <?php echo ($isPending && $canApprove) || $isApproved ? 'show' : ''; ?>" id="disLines<?php echo (int)$r['id']; ?>">
        <div class="table-responsive mt-3">
            <table class="inv-table">
                <thead><tr><th>Item</th><th>Expired</th><th class="text-end">Requested</th><th><?php echo $isPending ? 'Approve Qty' : 'Approved'; ?></th></tr></thead>
                <tbody>
                    <?php foreach ($lines as $l): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($l['item_name']); ?></td>
                        <td><?php echo $l['expiry_date'] ? date('d M Y', strtotime($l['expiry_date'])) : '-'; ?></td>
                        <td class="text-end"><?php echo invQty($l['qty_requested']); ?> <?php echo htmlspecialchars($l['unit'] ?? ''); ?></td>
                        <td>
                            <?php if ($isPending && $canApprove): ?>
                                <input type="number" step="0.001" min="0" max="<?php echo (float)$l['qty_requested']; ?>"
                                       class="form-control form-control-sm" style="max-width:130px;border-radius:8px;"
                                       form="reviewForm<?php echo (int)$r['id']; ?>"
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

        <?php if ($isPending && $canApprove): ?>
        <form method="post" id="reviewForm<?php echo (int)$r['id']; ?>">
<?php echo csrfField(); ?>
            <input type="hidden" name="action" value="review">
            <input type="hidden" name="request_id" value="<?php echo (int)$r['id']; ?>">
            <div class="d-flex gap-2 flex-wrap align-items-center mt-2">
                <input type="text" class="form-control" name="review_note" placeholder="Review note (optional)" style="max-width:320px;border-radius:10px;">
                <button type="submit" name="decision" value="approve" class="btn btn-success" style="border-radius:10px;"
                        onclick="return confirm('Approve with the quantities entered above? This authorizes disposal - stock is not deducted until it is confirmed disposed.');">
                    <i class="fas fa-check me-1"></i>Approve
                </button>
                <button type="submit" name="decision" value="reject" class="btn btn-outline-danger" style="border-radius:10px;"
                        onclick="return confirm('Reject this entire disposal request?');">
                    <i class="fas fa-times me-1"></i>Reject
                </button>
            </div>
            <div class="form-text mt-1">Lower a quantity for a partial approval. Approving does not remove stock - that happens only when disposal is confirmed.</div>
        </form>
        <?php elseif ($isPending): ?>
        <span class="inv-badge bg-secondary text-white mt-2 d-inline-block">Awaiting approval by a manager or administrator</span>
        <?php elseif ($isApproved): ?>
        <button type="button" class="btn btn-danger mt-2" style="border-radius:10px;"
                data-ui-modal="#executeModal"
                data-title="Confirm disposal of <?php echo htmlspecialchars($r['request_no'], ENT_QUOTES); ?>"
                data-field-request-id="<?php echo (int)$r['id']; ?>">
            <i class="fas fa-trash-can me-1"></i> Confirm Disposal
        </button>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>

<!-- New disposal request modal -->
<div class="modal fade" id="newDisposalModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="post">
<?php echo csrfField(); ?>
                <input type="hidden" name="action" value="create">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-trash-can me-2"></i>New Disposal Request</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label" for="dr_reason">Reason / Notes</label>
                        <input type="text" class="form-control" name="reason" id="dr_reason" placeholder="e.g. Weekly expiry check">
                    </div>
                    <label class="form-label">Expired Items</label>
                    <div id="disposalLines"></div>
                    <button type="button" class="btn btn-sm btn-outline-secondary mt-2" style="border-radius:8px;" onclick="addDisposalLine()"><i class="fas fa-plus me-1"></i>Add Item</button>
                    <div class="form-text mt-2">Requested by: <strong><?php echo htmlspecialchars($userName); ?></strong> - nothing is deducted until a manager/admin approves AND disposal is confirmed.</div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-inv">Submit Request</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Confirm disposal modal - ONE modal reused per row via MX.fillModal
     (see retail-products.php / pos-sales.php for the same pattern),
     instead of one modal per request. Reason is required, matching
     every other destructive-but-safe action in this app. -->
<div class="modal fade" id="executeModal" tabindex="-1" aria-labelledby="executeModalTitle" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" id="executeForm">
<?php echo csrfField(); ?>
                <input type="hidden" name="action" value="execute">
                <input type="hidden" name="request_id" value="">
                <div class="modal-header">
                    <h5 class="modal-title" id="executeModalTitle"><span data-ui-modal-title>Confirm disposal</span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p style="font-size:.92rem;">This deducts the approved quantities from stock and posts the loss to the ledger (Stock Loss &amp; Shrinkage). This cannot be undone.</p>
                    <label class="form-label" for="disposeReason">Reason / confirmation note</label>
                    <input type="text" name="reason" id="disposeReason" class="form-control" placeholder="e.g. Disposed via municipal waste collection" required style="border-radius:10px;">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Confirm Disposal</button>
                </div>
            </form>
        </div>
    </div>
</div>

<template id="disposalLineTemplate">
    <div class="d-flex gap-2 mb-2 disposal-line">
        <select class="form-select" name="line_key[]" required onchange="onDisposalLineChange(this)">
            <option value="">Choose an expired batch...</option>
            <?php foreach ($expiredItems as $it): ?>
            <option value="<?php echo $it['batch_id'] ? 'b' . (int)$it['batch_id'] : 'i' . (int)$it['item_id']; ?>"
                    data-qty="<?php echo (float)$it['quantity']; ?>">
                <?php echo htmlspecialchars($it['name']); ?> - <?php echo htmlspecialchars($it['batch_label']); ?>
                (<?php echo invQty($it['quantity']); ?> <?php echo htmlspecialchars($it['unit'] ?? ''); ?>, expired <?php echo (int)$it['days_expired']; ?>d ago)
            </option>
            <?php endforeach; ?>
        </select>
        <input type="number" class="form-control" name="qty[]" step="0.001" min="0.001" placeholder="Qty" style="max-width:130px;" required>
        <button type="button" class="btn btn-outline-danger" style="border-radius:8px;" onclick="this.closest('.disposal-line').remove()" aria-label="Remove line" title="Remove line"><i class="fas fa-times"></i></button>
    </div>
</template>

<?php
$pageScript = <<<'HTML'
<script>
function addDisposalLine() {
    const tpl = document.getElementById('disposalLineTemplate');
    document.getElementById('disposalLines').appendChild(tpl.content.cloneNode(true));
}
addDisposalLine();

// Search + Category + Status(days-expired range) on the "Currently
// Expired, In Stock" table - all three combine (AND), unlike the
// single-criterion MX.filterTable()/chip helpers in ui.js, which only
// ever apply one filter at a time and would clobber each other here.
// No page reload: every row is already on the page, this only toggles
// the same .mx-row-hidden class the shared filter component uses.
(function () {
    const searchInput = document.getElementById('expiredSearch');
    const categorySelect = document.getElementById('expiredCategoryFilter');
    const daysSelect = document.getElementById('expiredDaysFilter');
    const rows = document.querySelectorAll('#expiredStockTable tbody tr[data-search]');
    const noMatch = document.getElementById('expiredStockNoMatch');
    if (!searchInput || !rows.length) { return; }

    function applyExpiredFilters() {
        const q = searchInput.value.trim().toLowerCase();
        const cat = categorySelect.value;
        const bucket = daysSelect.value;
        let visible = 0;
        rows.forEach(function (row) {
            const show = (q === '' || row.dataset.search.indexOf(q) !== -1)
                && (cat === '' || row.dataset.category === cat)
                && (bucket === '' || row.dataset.daysBucket === bucket);
            row.classList.toggle('mx-row-hidden', !show);
            if (show) { visible++; }
        });
        if (noMatch) { noMatch.style.display = visible === 0 ? '' : 'none'; }
    }

    let searchTimer = null;
    searchInput.addEventListener('input', function () {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(applyExpiredFilters, 90);
    });
    searchInput.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { searchInput.value = ''; applyExpiredFilters(); }
    });
    categorySelect.addEventListener('change', applyExpiredFilters);
    daysSelect.addEventListener('change', applyExpiredFilters);
})();

// Client-side hint only, from the chosen batch's own remaining quantity -
// createDisposalRequest() re-locks and re-checks the real quantity
// server-side regardless, same as every other cap in this app.
function onDisposalLineChange(sel) {
    const line = sel.closest('.disposal-line');
    const qtyInput = line ? line.querySelector('input[name="qty[]"]') : null;
    if (!qtyInput) { return; }
    const opt = sel.selectedOptions[0];
    const max = opt ? opt.dataset.qty : '';
    if (max) { qtyInput.max = max; qtyInput.placeholder = 'Qty (max ' + max + ')'; }
    else { qtyInput.removeAttribute('max'); qtyInput.placeholder = 'Qty'; }
}

// MX.fillModal only touches fields named by a data-field-* attribute, so
// a reason typed for one request would otherwise still be sitting in the
// box the next time the shared modal is opened for a different request.
document.addEventListener('click', function (e) {
    if (e.target.closest('[data-ui-modal="#executeModal"]')) {
        const reason = document.getElementById('disposeReason');
        if (reason) { reason.value = ''; }
    }
});
</script>
HTML;
include 'inventory-footer.php';
?>
