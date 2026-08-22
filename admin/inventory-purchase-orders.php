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

// ---------- Create PO ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    $supplierId = (int)($_POST['supplier_id'] ?? 0) ?: null;
    $orderDate  = trim($_POST['order_date'] ?? '') ?: date('Y-m-d');
    $expected   = trim($_POST['expected_date'] ?? '') ?: null;
    $notes      = trim($_POST['notes'] ?? '');
    $itemIds    = $_POST['item_id'] ?? [];
    $qtys       = $_POST['qty'] ?? [];
    $prices     = $_POST['unit_price'] ?? [];

    // Build valid lines + total.
    $lines = [];
    $total = 0.0;
    foreach ($itemIds as $idx => $iid) {
        $iid = (int)$iid;
        $q = (float)($qtys[$idx] ?? 0);
        $p = (float)($prices[$idx] ?? 0);
        if ($iid > 0 && $q > 0) {
            $lines[] = [$iid, $q, $p];
            $total += $q * $p;
        }
    }

    if (empty($lines)) {
        invFlash('danger', 'Add at least one item line to create a purchase order.');
        header('Location: inventory-purchase-orders.php'); exit;
    }

    $conn->begin_transaction();
    try {
        $placeholder = 'PO-TEMP-' . uniqid();
        $stmt = $conn->prepare("INSERT INTO inv_purchase_orders (po_number, supplier_id, status, order_date, expected_date, total_amount, notes, created_by) VALUES (?, ?, 'draft', ?, ?, ?, ?, ?)");
        $stmt->bind_param('sissdsi', $placeholder, $supplierId, $orderDate, $expected, $total, $notes, $userId);
        $stmt->execute();
        $poId = $conn->insert_id;
        $stmt->close();

        $poNumber = 'PO-' . str_pad($poId, 6, '0', STR_PAD_LEFT);
        $upd = $conn->prepare("UPDATE inv_purchase_orders SET po_number = ? WHERE id = ?");
        $upd->bind_param('si', $poNumber, $poId);
        $upd->execute(); $upd->close();

        $ins = $conn->prepare("INSERT INTO inv_purchase_order_lines (po_id, item_id, quantity, unit_price) VALUES (?,?,?,?)");
        foreach ($lines as $l) {
            $ins->bind_param('iidd', $poId, $l[0], $l[1], $l[2]);
            $ins->execute();
        }
        $ins->close();

        $conn->commit();
        invAudit($conn, $userId, 'create', 'purchase_order', $poId, $poNumber);
        invFlash('success', "Purchase order $poNumber created (draft).");
        header('Location: inventory-po-view.php?id=' . $poId); exit;
    } catch (Throwable $e) {
        $conn->rollback();
        invFlash('danger', 'Could not create purchase order: ' . $e->getMessage());
        header('Location: inventory-purchase-orders.php'); exit;
    }
}

// ---------- Listing ----------
$statusFilter = $_GET['status'] ?? '';
$conds = ["po.deleted_at IS NULL"];
if (in_array($statusFilter, ['draft','approved','received','partially_received','cancelled'], true)) {
    $conds[] = "po.status = '" . $statusFilter . "'";
}
$whereSql = implode(' AND ', $conds);

$pos = $conn->query("SELECT po.*, s.name AS supplier_name
    FROM inv_purchase_orders po
    LEFT JOIN inv_suppliers s ON po.supplier_id = s.id
    WHERE $whereSql ORDER BY po.created_at DESC LIMIT 300")->fetch_all(MYSQLI_ASSOC);

$suppliers = $conn->query("SELECT id, name FROM inv_suppliers WHERE deleted_at IS NULL ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$items = $conn->query("SELECT i.id, i.name, i.purchase_price, u.abbreviation AS unit_abbr
    FROM inv_items i LEFT JOIN inv_units u ON i.unit_id=u.id
    WHERE i.deleted_at IS NULL AND i.status='active' ORDER BY i.name")->fetch_all(MYSQLI_ASSOC);

$statusMeta = [
    'draft'=>['Draft','secondary'], 'approved'=>['Approved','info'],
    'partially_received'=>['Partially Received','warning'], 'received'=>['Received','success'],
    'cancelled'=>['Cancelled','danger'],
];
$payMeta = ['unpaid'=>['Unpaid','danger'], 'partial'=>['Partial','warning'], 'paid'=>['Paid','success']];

$pageTitle = 'Purchase Orders';
include 'inventory-header.php';
?>

<div class="inv-page-header">
    <div>
        <h1><i class="fas fa-file-invoice-dollar me-2" style="color:var(--inv-primary);"></i>Purchase Orders</h1>
        <div class="subtitle">Raise orders to suppliers, approve, and receive stock.</div>
    </div>
    <button class="btn btn-inv" data-bs-toggle="modal" data-bs-target="#poModal" onclick="resetPO()">
        <i class="fas fa-plus me-1"></i> New Purchase Order
    </button>
</div>

<div class="inv-card p-3 mb-3">
    <form class="row g-2 align-items-end" method="get">
        <div class="col-md-3">
            <label class="form-label">Status</label>
            <select name="status" class="form-select" onchange="this.form.submit()">
                <option value="">All</option>
                <?php foreach ($statusMeta as $k=>$m): ?><option value="<?php echo $k; ?>" <?php echo $statusFilter===$k?'selected':''; ?>><?php echo $m[0]; ?></option><?php endforeach; ?>
            </select>
        </div>
    </form>
</div>

<div class="inv-card p-0">
    <div class="table-responsive">
        <table class="inv-table">
            <thead>
                <tr><th>PO #</th><th>Supplier</th><th>Order Date</th><th>Status</th>
                    <th class="text-end">Total</th><th class="text-center">Payment</th><th class="text-end">Actions</th></tr>
            </thead>
            <tbody>
            <?php if (empty($pos)): ?>
                <tr><td colspan="7"><div class="empty-state"><i class="fas fa-file-invoice-dollar d-block"></i>No purchase orders yet.</div></td></tr>
            <?php else: foreach ($pos as $po):
                $sm = $statusMeta[$po['status']] ?? [ucfirst($po['status']),'secondary'];
                $pm = $payMeta[$po['payment_status']] ?? ['—','secondary']; ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($po['po_number']); ?></strong></td>
                    <td><?php echo htmlspecialchars($po['supplier_name'] ?: '—'); ?></td>
                    <td class="small"><?php echo $po['order_date'] ? date('d M Y', strtotime($po['order_date'])) : '—'; ?></td>
                    <td><span class="inv-badge bg-<?php echo $sm[1]; ?><?php echo $sm[1]==='warning'?' text-dark':' text-white'; ?>"><?php echo $sm[0]; ?></span></td>
                    <td class="text-end"><?php echo invMoney($conn, $po['total_amount']); ?></td>
                    <td class="text-center"><span class="inv-badge bg-<?php echo $pm[1]; ?><?php echo $pm[1]==='warning'?' text-dark':' text-white'; ?>"><?php echo $pm[0]; ?></span></td>
                    <td class="text-end"><a href="inventory-po-view.php?id=<?php echo (int)$po['id']; ?>" class="btn btn-sm btn-inv"><i class="fas fa-eye me-1"></i>View</a></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Create PO modal -->
<div class="modal fade" id="poModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <form method="post">
<?php echo csrfField(); ?>
                <input type="hidden" name="action" value="create">
                <div class="modal-header"><h5 class="modal-title">New Purchase Order</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="row g-3 mb-2">
                        <div class="col-md-5">
                            <label class="form-label">Supplier</label>
                            <select name="supplier_id" class="form-select">
                                <option value="">— none —</option>
                                <?php foreach ($suppliers as $s): ?><option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3"><label class="form-label">Order Date</label><input type="date" name="order_date" class="form-control" value="<?php echo date('Y-m-d'); ?>"></div>
                        <div class="col-md-4"><label class="form-label">Expected Date</label><input type="date" name="expected_date" class="form-control"></div>
                    </div>
                    <label class="form-label">Items</label>
                    <div id="poLines"></div>
                    <button type="button" class="btn btn-sm btn-outline-secondary mt-2" onclick="addPOLine()"><i class="fas fa-plus me-1"></i>Add item</button>
                    <div class="mt-3"><label class="form-label">Notes</label><textarea name="notes" class="form-control" rows="2"></textarea></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-inv">Create Draft PO</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$itemsJson = json_encode($items, JSON_HEX_APOS | JSON_HEX_QUOT);
$pageScript = <<<JS
<script>
const PO_ITEMS = {$itemsJson};
function poItemOptions(sel) {
    let h = '<option value="">— item —</option>';
    PO_ITEMS.forEach(function(it){ h += '<option value="'+it.id+'" data-price="'+(it.purchase_price||0)+'"'+(it.id==sel?' selected':'')+'>'+it.name+(it.unit_abbr?(' ('+it.unit_abbr+')'):'')+'</option>'; });
    return h;
}
function addPOLine() {
    const wrap = document.getElementById('poLines');
    const row = document.createElement('div');
    row.className = 'row g-2 mb-2 align-items-center';
    row.innerHTML =
        '<div class="col-6"><select name="item_id[]" class="form-select form-select-sm po-item" onchange="fillPrice(this)">'+poItemOptions('')+'</select></div>'+
        '<div class="col-3"><input type="number" step="0.001" min="0" name="qty[]" class="form-control form-control-sm" placeholder="Qty"></div>'+
        '<div class="col-2"><input type="number" step="0.01" min="0" name="unit_price[]" class="form-control form-control-sm" placeholder="Price"></div>'+
        '<div class="col-1"><button type="button" class="btn btn-sm btn-outline-danger" onclick="this.closest(\\'.row\\').remove()" aria-label="Remove line" title="Remove line"><i class="fas fa-times"></i></button></div>';
    wrap.appendChild(row);
}
function fillPrice(sel) {
    const opt = sel.options[sel.selectedIndex];
    const price = opt ? opt.getAttribute('data-price') : 0;
    const priceInput = sel.closest('.row').querySelector('input[name="unit_price[]"]');
    if (priceInput && (!priceInput.value || priceInput.value == '0')) { priceInput.value = price; }
}
function resetPO() { document.getElementById('poLines').innerHTML=''; addPOLine(); }
</script>
JS;
include 'inventory-footer.php';
