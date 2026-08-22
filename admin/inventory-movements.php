<?php
require_once '../includes/auth.php';
requireModule('inventory');
// Reject any POST that does not carry this session's CSRF token.
// Placed before every handler on this page, and a no-op on GET.
csrfRequire();
date_default_timezone_set('Africa/Dar_es_Salaam');
$current_page = basename($_SERVER['PHP_SELF']);
include '../includes/db.php';
require_once '../includes/inventory_functions.php';
inventoryBoot($conn);

$userId = (int)($_SESSION['id'] ?? 0);
function invFlash($type, $msg) { $_SESSION['inv_flash'] = ['type' => $type, 'msg' => $msg]; }

// Movement types that ADD stock vs REDUCE stock.
$typeDirection = [
    'receive' => 1, 'return' => -1, 'issue' => -1,
    'damage' => -1, 'expire' => -1, 'loss' => -1,
];

// ---------- POST: record a movement ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'record') {
    $itemId   = (int)($_POST['item_id'] ?? 0);
    $type     = $_POST['movement_type'] ?? '';
    $qty      = abs((float)($_POST['quantity'] ?? 0));
    $reason   = trim($_POST['reason'] ?? '');
    $unitCost = (float)($_POST['unit_cost'] ?? 0);

    if ($type === 'adjust') {
        // Adjustment direction decides the sign.
        $dir = ($_POST['adjust_direction'] ?? 'increase') === 'decrease' ? -1 : 1;
        $signed = $dir * $qty;
    } elseif (isset($typeDirection[$type])) {
        $signed = $typeDirection[$type] * $qty;
    } else {
        $signed = 0;
    }

    if ($itemId <= 0 || $qty <= 0 || $signed == 0) {
        invFlash('danger', 'Please choose an item, a movement type and a quantity greater than zero.');
    } else {
        [$ok, $msg] = recordStockMovement($conn, $itemId, $type, $signed, $userId, $reason, $unitCost);
        invFlash($ok ? 'success' : 'danger', $ok ? 'Stock movement recorded.' : $msg);
        if ($ok) { invAudit($conn, $userId, 'movement_' . $type, 'item', $itemId, $reason); }
    }
    header('Location: inventory-movements.php' . (!empty($_GET['item']) ? '?item=' . (int)$_GET['item'] : ''));
    exit;
}

// ---------- Dropdown items ----------
$itemsList = $conn->query("SELECT id, name, current_stock, unit_id,
        (SELECT abbreviation FROM inv_units u WHERE u.id = i.unit_id) AS unit_abbr
    FROM inv_items i WHERE deleted_at IS NULL AND status = 'active' ORDER BY name")->fetch_all(MYSQLI_ASSOC);

// ---------- History filters ----------
$fItem = (int)($_GET['item'] ?? 0);
$fType = $_GET['type'] ?? '';
$fFrom = trim($_GET['from'] ?? '');
$fTo   = trim($_GET['to'] ?? '');

// Every filter is bound. The date bounds are also validated as real
// calendar dates first, so a malformed value is ignored outright rather
// than reaching the database and matching nothing in a confusing way.
$conds  = ["1=1"];
$params = [];
$types  = '';

if ($fItem > 0) {
    $conds[] = "m.item_id = ?";
    $params[] = $fItem;
    $types .= 'i';
}
// The same nine values the ENUM allows; an unknown type is ignored.
if ($fType !== '' && in_array($fType, ['receive','issue','return','damage',
        'expire','loss','adjust','transfer','opening'], true)) {
    $conds[] = "m.movement_type = ?";
    $params[] = $fType;
    $types .= 's';
}
if ($fFrom !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fFrom)) {
    $conds[] = "m.created_at >= ?";
    $params[] = $fFrom . ' 00:00:00';
    $types .= 's';
}
if ($fTo !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fTo)) {
    $conds[] = "m.created_at <= ?";
    $params[] = $fTo . ' 23:59:59';
    $types .= 's';
}
$whereSql = implode(' AND ', $conds);

$stmt = $conn->prepare("SELECT m.*, i.name AS item_name,
        (SELECT abbreviation FROM inv_units u WHERE u.id = i.unit_id) AS unit_abbr,
        a.username AS user_name
    FROM inv_stock_movements m
    JOIN inv_items i ON m.item_id = i.id
    LEFT JOIN admin a ON m.user_id = a.id
    WHERE $whereSql ORDER BY m.created_at DESC, m.id DESC LIMIT 400");
if ($types !== '') { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$movements = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$typeMeta = [
    'receive'=>['Received','success','fa-arrow-down'], 'issue'=>['Issued','info','fa-arrow-up'],
    'return'=>['Returned','secondary','fa-rotate-left'], 'damage'=>['Damaged','danger','fa-house-crack'],
    'expire'=>['Expired','danger','fa-hourglass-end'], 'loss'=>['Lost','danger','fa-triangle-exclamation'],
    'adjust'=>['Adjusted','warning','fa-sliders'], 'transfer'=>['Transferred','primary','fa-right-left'],
    'opening'=>['Opening','success','fa-flag'],
];

$pageTitle = 'Stock Movements';
include 'inventory-header.php';
?>

<div class="inv-page-header">
    <div>
        <h1><i class="fas fa-right-left me-2" style="color:var(--inv-primary);"></i>Stock Movements</h1>
        <div class="subtitle">Record stock in/out and review the full audit trail.</div>
    </div>
    <button class="btn btn-inv" data-bs-toggle="modal" data-bs-target="#moveModal">
        <i class="fas fa-plus me-1"></i> Record Movement
    </button>
</div>

<div class="inv-card p-3 mb-3">
    <form class="row g-2 align-items-end" method="get">
        <div class="col-md-3">
            <label class="form-label">Item</label>
            <select name="item" class="form-select">
                <option value="0">All items</option>
                <?php foreach ($itemsList as $it): ?>
                    <option value="<?php echo $it['id']; ?>" <?php echo $fItem==$it['id']?'selected':''; ?>><?php echo htmlspecialchars($it['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">Type</label>
            <select name="type" class="form-select">
                <option value="">All</option>
                <?php foreach ($typeMeta as $k => $m): ?>
                    <option value="<?php echo $k; ?>" <?php echo $fType===$k?'selected':''; ?>><?php echo $m[0]; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2"><label class="form-label">From</label><input type="date" name="from" class="form-control" value="<?php echo htmlspecialchars($fFrom); ?>"></div>
        <div class="col-md-2"><label class="form-label">To</label><input type="date" name="to" class="form-control" value="<?php echo htmlspecialchars($fTo); ?>"></div>
        <div class="col-auto">
            <button class="btn btn-inv" type="submit">Filter</button>
            <a href="inventory-movements.php" class="btn btn-outline-secondary">Clear</a>
        </div>
    </form>
</div>

<div class="inv-card p-0">
    <div class="table-responsive">
        <table class="inv-table">
            <thead>
                <tr>
                    <th>Date &amp; Time</th><th>Item</th><th>Type</th>
                    <th class="text-end">Qty</th><th class="text-end">Before</th><th class="text-end">After</th>
                    <th>Reason</th><th>By</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($movements)): ?>
                <tr><td colspan="8"><div class="empty-state"><i class="fas fa-right-left d-block"></i>No movements recorded yet.</div></td></tr>
            <?php else: foreach ($movements as $m):
                $meta = $typeMeta[$m['movement_type']] ?? [ucfirst($m['movement_type']),'secondary','fa-circle'];
                $qtyVal = (float)$m['quantity'];
                $sign = $qtyVal > 0 ? '+' : '';
            ?>
                <tr>
                    <td class="small"><?php echo date('d M Y H:i', strtotime($m['created_at'])); ?></td>
                    <td><?php echo htmlspecialchars($m['item_name']); ?></td>
                    <td><span class="inv-badge bg-<?php echo $meta[1]; ?><?php echo $meta[1]==='warning'?' text-dark':' text-white'; ?>"><i class="fas <?php echo $meta[2]; ?> me-1"></i><?php echo $meta[0]; ?></span></td>
                    <td class="text-end fw-semibold" style="color:<?php echo $qtyVal>0?'#1c8c5f':'#d0362b'; ?>;"><?php echo $sign . invQty($qtyVal); ?> <span class="text-muted small"><?php echo htmlspecialchars($m['unit_abbr'] ?: ''); ?></span></td>
                    <td class="text-end text-muted"><?php echo invQty($m['qty_before']); ?></td>
                    <td class="text-end fw-semibold"><?php echo invQty($m['qty_after']); ?></td>
                    <td class="small text-muted"><?php echo htmlspecialchars($m['reason'] ?: '—'); ?></td>
                    <td class="small"><?php echo htmlspecialchars($m['user_name'] ?: 'System'); ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Record movement modal -->
<div class="modal fade" id="moveModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
<?php echo csrfField(); ?>
                <input type="hidden" name="action" value="record">
                <div class="modal-header">
                    <h5 class="modal-title">Record Stock Movement</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Item *</label>
                        <select name="item_id" id="m_item" class="form-select" required>
                            <option value="">— select item —</option>
                            <?php foreach ($itemsList as $it): ?>
                                <option value="<?php echo $it['id']; ?>" <?php echo $fItem==$it['id']?'selected':''; ?>>
                                    <?php echo htmlspecialchars($it['name']); ?> (stock: <?php echo invQty($it['current_stock']) . ' ' . htmlspecialchars($it['unit_abbr'] ?: ''); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Movement Type *</label>
                        <select name="movement_type" id="m_type" class="form-select" required onchange="onTypeChange()">
                            <option value="receive">Receive (stock in)</option>
                            <option value="issue">Issue for operations (stock out)</option>
                            <option value="adjust">Manual adjustment</option>
                            <option value="damage">Damaged (stock out)</option>
                            <option value="expire">Expired (stock out)</option>
                            <option value="loss">Lost (stock out)</option>
                            <option value="return">Return to supplier (stock out)</option>
                        </select>
                    </div>
                    <div class="mb-3" id="adjustDirWrap" style="display:none;">
                        <label class="form-label">Adjustment Direction</label>
                        <select name="adjust_direction" class="form-select">
                            <option value="increase">Increase (+)</option>
                            <option value="decrease">Decrease (−)</option>
                        </select>
                    </div>
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label">Quantity *</label>
                            <input type="number" step="0.001" min="0" name="quantity" class="form-control" required>
                        </div>
                        <div class="col-6" id="unitCostWrap">
                            <label class="form-label">Unit Cost</label>
                            <input type="number" step="0.01" min="0" name="unit_cost" class="form-control" value="0">
                            <div class="form-text">Used to update average cost on receipts.</div>
                        </div>
                    </div>
                    <div class="mb-1 mt-2">
                        <label class="form-label">Reason / Note</label>
                        <input type="text" name="reason" class="form-control" placeholder="e.g. weekly restock, spillage, stock count correction">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-inv">Record</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$autoOpen = (!empty($_GET['item'])) ? "new bootstrap.Modal(document.getElementById('moveModal'));" : "";
$pageScript = <<<JS
<script>
function onTypeChange() {
    var t = document.getElementById('m_type').value;
    document.getElementById('adjustDirWrap').style.display = (t === 'adjust') ? '' : 'none';
    document.getElementById('unitCostWrap').style.display = (t === 'receive') ? '' : 'none';
}
document.addEventListener('DOMContentLoaded', onTypeChange);
</script>
JS;
include 'inventory-footer.php';
