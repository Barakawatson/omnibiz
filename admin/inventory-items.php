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
require_once '../includes/catalog_functions.php';   // department filtering
catalogBoot($conn);

$currentRole = $_SESSION['role'] ?? '';
$canManage   = userCan('inventory');
$userId      = (int)($_SESSION['id'] ?? 0);

function invFlash($type, $msg) { $_SESSION['inv_flash'] = ['type' => $type, 'msg' => $msg]; }

// ---------- POST handling ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canManage) {
        invFlash('danger', 'You do not have permission to manage items.');
        header('Location: inventory-items.php'); exit;
    }
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id           = (int)($_POST['id'] ?? 0);
        $name         = trim($_POST['name'] ?? '');
        $sku          = trim($_POST['sku'] ?? '');
        $barcode      = trim($_POST['barcode'] ?? '');
        $categoryId   = (int)($_POST['category_id'] ?? 0) ?: null;
        $unitId       = (int)($_POST['unit_id'] ?? 0) ?: null;
        $supplierId   = (int)($_POST['supplier_id'] ?? 0) ?: null;
        $purchase     = (float)($_POST['purchase_price'] ?? 0);
        $minStock     = (float)($_POST['min_stock'] ?? 0);
        $reorder      = (float)($_POST['reorder_level'] ?? 0);
        $openingStock = (float)($_POST['opening_stock'] ?? 0);
        $isPerishable = isset($_POST['is_perishable']) ? 1 : 0;
        $expiry       = trim($_POST['expiry_date'] ?? '');
        $expiry       = $expiry !== '' ? $expiry : null;
        $status       = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
        $sku          = $sku !== '' ? $sku : null;
        $barcode      = $barcode !== '' ? $barcode : null;

        if ($name === '') {
            invFlash('danger', 'Item name is required.');
        } else {
            if ($id > 0) {
                $stmt = $conn->prepare("UPDATE inv_items SET name=?, sku=?, barcode=?, category_id=?, unit_id=?, supplier_id=?, purchase_price=?, min_stock=?, reorder_level=?, is_perishable=?, expiry_date=?, status=? WHERE id=? AND deleted_at IS NULL");
                $stmt->bind_param('sssiiidddissi', $name, $sku, $barcode, $categoryId, $unitId, $supplierId, $purchase, $minStock, $reorder, $isPerishable, $expiry, $status, $id);
                $stmt->execute();
                $stmt->close();
                invAudit($conn, $userId, 'update', 'item', $id, $name);
                $flashMsg = 'Item updated.';

                // Optional stock correction: sets the live stock to the
                // entered value via an auditable 'adjust' movement (the
                // delta, who did it and why are all kept in the trail).
                $correctedRaw = trim($_POST['corrected_stock'] ?? '');
                if ($correctedRaw !== '' && is_numeric($correctedRaw)) {
                    $corrected = (float)$correctedRaw;
                    $curRes = $conn->prepare("SELECT current_stock FROM inv_items WHERE id = ?");
                    $curRes->bind_param('i', $id);
                    $curRes->execute();
                    $current = (float)($curRes->get_result()->fetch_assoc()['current_stock'] ?? 0);
                    $curRes->close();
                    $delta = $corrected - $current;
                    if (abs($delta) > 0.0005) {
                        [$okAdj, $msgAdj] = recordStockMovement(
                            $conn, $id, 'adjust', $delta, $userId,
                            'Stock correction via item edit (from ' . invQty($current) . ' to ' . invQty($corrected) . ')'
                        );
                        $flashMsg .= $okAdj
                            ? ' Stock corrected to ' . invQty($corrected) . '.'
                            : ' Stock correction failed: ' . $msgAdj;
                    }
                }
                invFlash('success', $flashMsg);
            } else {
                // The department is set explicitly rather than left to the
                // column default. The default is a fixed key that a shop
                // defining its own departments may not have, and an item
                // in a department that does not exist would vanish from
                // every operational screen. It follows the category when
                // there is one, otherwise the first department trading.
                $department = catalogFirstActiveDepartment($conn);
                if ($categoryId > 0) {
                    $cStmt = $conn->prepare("SELECT department FROM inv_categories WHERE id = ?");
                    if ($cStmt) {
                        $cStmt->bind_param('i', $categoryId);
                        $cStmt->execute();
                        $cDept = (string)($cStmt->get_result()->fetch_assoc()['department'] ?? '');
                        $cStmt->close();
                        if ($cDept !== '' && isset(catalogDepartments($conn)[$cDept])) { $department = $cDept; }
                    }
                }

                $stmt = $conn->prepare("INSERT INTO inv_items (name, sku, barcode, category_id, unit_id, supplier_id, purchase_price, min_stock, reorder_level, is_perishable, expiry_date, status, opening_stock, department) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
                $stmt->bind_param('sssiiidddissds', $name, $sku, $barcode, $categoryId, $unitId, $supplierId, $purchase, $minStock, $reorder, $isPerishable, $expiry, $status, $openingStock, $department);
                $stmt->execute();
                $newId = $conn->insert_id;
                $stmt->close();
                invAudit($conn, $userId, 'create', 'item', $newId, $name);
                // Record opening stock as an auditable movement.
                if ($openingStock > 0) {
                    recordStockMovement($conn, $newId, 'opening', $openingStock, $userId, 'Opening stock', $purchase);
                }
                invFlash('success', 'Item added.');
            }
        }
    } elseif ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $conn->prepare("UPDATE inv_items SET deleted_at = NOW(), status = 'inactive' WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        invAudit($conn, $userId, 'delete', 'item', $id, '');
        invFlash('success', 'Item removed (soft delete — history preserved).');
    }
    header('Location: inventory-items.php' . buildQuery()); exit;
}

function buildQuery() {
    $q = [];
    foreach (['search','category','status'] as $k) {
        if (!empty($_GET[$k])) { $q[$k] = $_GET[$k]; }
    }
    return $q ? '?' . http_build_query($q) : '';
}

// ---------- Dropdown data ----------
$categories = $conn->query("SELECT id, name FROM inv_categories WHERE deleted_at IS NULL ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$units      = $conn->query("SELECT id, name, abbreviation FROM inv_units WHERE deleted_at IS NULL ORDER BY name")->fetch_all(MYSQLI_ASSOC);
$suppliers  = $conn->query("SELECT id, name FROM inv_suppliers WHERE deleted_at IS NULL ORDER BY name")->fetch_all(MYSQLI_ASSOC);

// ---------- Filters / listing ----------
$search     = trim($_GET['search'] ?? '');
$catFilter  = (int)($_GET['category'] ?? 0);
$statFilter = $_GET['status'] ?? '';

// Filters are bound, never interpolated. Each condition contributes a
// placeholder and pushes its value onto $params in the same order, so
// the type string and the argument list cannot drift apart.
$conds  = ["i.deleted_at IS NULL"];
$params = [];
$types  = '';

if ($search !== '') {
    $conds[] = "(i.name LIKE ? OR i.sku LIKE ? OR i.barcode LIKE ?)";
    // Wildcards belong to the pattern, not to the user's text: escape
    // them so searching for "50%" looks for that, not for anything.
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
    array_push($params, $like, $like, $like);
    $types .= 'sss';
}
if ($catFilter > 0) {
    $conds[] = "i.category_id = ?";
    $params[] = $catFilter;
    $types .= 'i';
}
if ($statFilter === 'low') {
    $conds[] = "(i.current_stock <= 0 OR (i.reorder_level > 0 AND i.current_stock <= i.reorder_level) OR (i.min_stock > 0 AND i.current_stock <= i.min_stock))";
} elseif ($statFilter === 'out') {
    $conds[] = "i.current_stock <= 0";
} elseif (in_array($statFilter, ['active','inactive'], true)) {
    $conds[] = "i.status = ?";
    $params[] = $statFilter;
    $types .= 's';
}
$whereSql = implode(' AND ', $conds);
// Items in a department that is not trading are out of the current
// range and are not listed here (see Departments to bring one back).
// This fragment is built from the fixed department ENUM, not user input.
$whereSql .= catalogDepartmentFilterSql($conn, 'i');

$stmt = $conn->prepare("SELECT i.*, c.name AS category_name, u.abbreviation AS unit_abbr, s.name AS supplier_name
    FROM inv_items i
    LEFT JOIN inv_categories c ON i.category_id = c.id
    LEFT JOIN inv_units u ON i.unit_id = u.id
    LEFT JOIN inv_suppliers s ON i.supplier_id = s.id
    WHERE $whereSql ORDER BY i.name ASC");
if ($types !== '') { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$totalValue = 0;
foreach ($items as $it) {
    $cost = (float)$it['average_cost'] > 0 ? (float)$it['average_cost'] : (float)$it['purchase_price'];
    $totalValue += (float)$it['current_stock'] * $cost;
}

$pageTitle = 'Inventory Items';
include 'inventory-header.php';

// Nothing can be created without master data to hang it on.
$guardNeeds = ['departments','units'];
include 'partials/setup-required.php';
?>

<div class="inv-page-header">
    <div>
        <h1><i class="fas fa-boxes-stacked me-2" style="color:var(--inv-primary);"></i>Inventory Items</h1>
        <div class="subtitle"><?php echo count($items); ?> item(s) • Stock value: <strong><?php echo invMoney($conn, $totalValue); ?></strong></div>
    </div>
    <?php if ($canManage): ?>
    <button class="btn btn-inv" data-bs-toggle="modal" data-bs-target="#itemModal" onclick="resetItemForm()">
        <i class="fas fa-plus me-1"></i> Add Item
    </button>
    <?php endif; ?>
</div>

<div class="inv-card p-3 mb-3">
    <form class="row g-2 align-items-end" method="get">
        <div class="col-md-4">
            <label class="form-label">Search</label>
            <div class="input-group">
                <span class="input-group-text bg-white"><i class="fas fa-search"></i></span>
                <input type="text" name="search" class="form-control" placeholder="Name, SKU or barcode..." value="<?php echo htmlspecialchars($search); ?>">
            </div>
        </div>
        <div class="col-md-3">
            <label class="form-label">Category</label>
            <select name="category" class="form-select">
                <option value="">All categories</option>
                <?php foreach ($categories as $c): ?>
                    <option value="<?php echo $c['id']; ?>" <?php echo $catFilter == $c['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($c['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label">Status</label>
            <select name="status" class="form-select">
                <option value="">All</option>
                <option value="low" <?php echo $statFilter==='low'?'selected':''; ?>>Low / Reorder</option>
                <option value="out" <?php echo $statFilter==='out'?'selected':''; ?>>Out of stock</option>
                <option value="active" <?php echo $statFilter==='active'?'selected':''; ?>>Active</option>
                <option value="inactive" <?php echo $statFilter==='inactive'?'selected':''; ?>>Inactive</option>
            </select>
        </div>
        <div class="col-auto">
            <button class="btn btn-inv" type="submit">Filter</button>
            <?php if ($search!=='' || $catFilter || $statFilter!==''): ?><a href="inventory-items.php" class="btn btn-outline-secondary">Clear</a><?php endif; ?>
        </div>
    </form>
</div>

<div class="inv-card p-0">
    <div class="ui-table-wrap">
        <table class="inv-table">
            <thead>
                <tr>
                    <th>Item</th>
                    <th class="ui-col-optional">Category</th>
                    <th class="ui-col-secondary">Supplier</th>
                    <th class="text-end">Stock</th>
                    <th class="text-end ui-col-secondary">Min / Reorder</th>
                    <th class="text-end ui-col-optional">Avg Cost</th>
                    <th class="text-end">Value</th>
                    <th class="text-center">Status</th>
                    <?php if ($canManage): ?><th class="text-end">Actions</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($items)): ?>
                <tr><td colspan="<?php echo $canManage ? 9 : 8; ?>">
                    <div class="empty-state"><i class="fas fa-boxes-stacked d-block"></i>No items found.</div>
                </td></tr>
            <?php else: foreach ($items as $it):
                [$statusLabel, $statusColor] = invStockStatus($it);
                $cost = (float)$it['average_cost'] > 0 ? (float)$it['average_cost'] : (float)$it['purchase_price'];
                $value = (float)$it['current_stock'] * $cost;
            ?>
                <tr>
                    <td>
                        <strong><?php echo htmlspecialchars($it['name']); ?></strong>
                        <?php if ($it['status'] === 'inactive'): ?><span class="inv-badge bg-secondary text-white ms-1">inactive</span><?php endif; ?>
                        <div class="text-muted small"><?php echo htmlspecialchars($it['sku'] ?: 'No SKU'); ?></div>
                    </td>
                    <td class="ui-col-optional"><?php echo htmlspecialchars($it['category_name'] ?: '—'); ?></td>
                    <td class="ui-col-secondary"><?php echo htmlspecialchars($it['supplier_name'] ?: '—'); ?></td>
                    <td class="text-end"><strong><?php echo invQty($it['current_stock']); ?></strong> <span class="text-muted small"><?php echo htmlspecialchars($it['unit_abbr'] ?: ''); ?></span></td>
                    <td class="text-end text-muted small ui-col-secondary"><?php echo invQty($it['min_stock']); ?> / <?php echo invQty($it['reorder_level']); ?></td>
                    <td class="text-end ui-col-optional"><?php echo number_format($cost, 2, '.', ','); ?></td>
                    <td class="text-end"><?php echo number_format($value, 2, '.', ','); ?></td>
                    <td class="text-center"><span class="inv-badge bg-<?php echo $statusColor; ?><?php echo $statusColor==='warning'?' text-dark':' text-white'; ?>"><?php echo $statusLabel; ?></span></td>
                    <?php if ($canManage): ?>
                    <td class="text-end" style="white-space:nowrap;">
                        <a href="inventory-movements.php?item=<?php echo (int)$it['id']; ?>" class="btn btn-sm btn-outline-info" title="Stock movements"><i class="fas fa-right-left"></i></a>
                        <button class="btn btn-sm btn-outline-secondary" title="Edit" onclick='editItem(<?php echo json_encode($it, JSON_HEX_APOS | JSON_HEX_QUOT); ?>)'><i class="fas fa-pen"></i></button>
                        <button class="btn btn-sm btn-outline-danger" title="Delete" onclick="deleteItem(<?php echo (int)$it['id']; ?>, '<?php echo htmlspecialchars(addslashes($it['name'])); ?>')"><i class="fas fa-trash"></i></button>
                    </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($canManage): ?>
<!-- Add/Edit modal -->
<div class="modal fade" id="itemModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <form method="post">
<?php echo csrfField(); ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" id="item_id">
                <div class="modal-header">
                    <h5 class="modal-title" id="itemModalTitle">Add Item</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Item Name *</label>
                            <input type="text" name="name" id="i_name" class="form-control" required>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">SKU</label>
                            <input type="text" name="sku" id="i_sku" class="form-control">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Barcode</label>
                            <input type="text" name="barcode" id="i_barcode" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Category</label>
                            <select name="category_id" id="i_category" class="form-select">
                                <option value="">— none —</option>
                                <?php foreach ($categories as $c): ?><option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Unit</label>
                            <select name="unit_id" id="i_unit" class="form-select">
                                <option value="">— none —</option>
                                <?php foreach ($units as $u): ?><option value="<?php echo $u['id']; ?>"><?php echo htmlspecialchars($u['name'] . ' (' . $u['abbreviation'] . ')'); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Supplier</label>
                            <select name="supplier_id" id="i_supplier" class="form-select">
                                <option value="">— none —</option>
                                <?php foreach ($suppliers as $s): ?><option value="<?php echo $s['id']; ?>"><?php echo htmlspecialchars($s['name']); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Purchase Price (per unit)</label>
                            <input type="number" step="0.01" min="0" name="purchase_price" id="i_purchase" class="form-control" value="0">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Minimum Stock</label>
                            <input type="number" step="0.001" min="0" name="min_stock" id="i_min" class="form-control" value="0">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Reorder Level</label>
                            <input type="number" step="0.001" min="0" name="reorder_level" id="i_reorder" class="form-control" value="0">
                        </div>
                        <div class="col-md-4" id="openingStockWrap">
                            <label class="form-label">Opening Stock</label>
                            <input type="number" step="0.001" min="0" name="opening_stock" id="i_opening" class="form-control" value="0">
                            <div class="form-text">Only set when adding a new item.</div>
                        </div>
                        <div class="col-md-4" id="correctStockWrap" style="display:none;">
                            <label class="form-label">Correct Current Stock</label>
                            <input type="number" step="0.001" min="0" name="corrected_stock" id="i_corrected" class="form-control" placeholder="Leave blank to keep">
                            <div class="form-text">Fix entry mistakes: records an auditable "adjust" movement.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Status</label>
                            <select name="status" id="i_status" class="form-select">
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Expiry Date</label>
                            <input type="date" name="expiry_date" id="i_expiry" class="form-control">
                        </div>
                        <div class="col-md-4 d-flex align-items-center">
                            <div class="form-check mt-4">
                                <input type="checkbox" class="form-check-input" name="is_perishable" id="i_perishable">
                                <label class="form-check-label" for="i_perishable">Perishable / has expiry</label>
                            </div>
                        </div>
                    </div>
                    <div class="alert alert-light border mt-3 mb-0 small text-muted">
                        <i class="fas fa-circle-info me-1"></i> Routine stock changes go through <strong>Stock Movements</strong>. To correct a stock-entry mistake, use the "Correct Current Stock" field above - the fix is recorded as an auditable adjustment.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-inv">Save Item</button>
                </div>
            </form>
        </div>
    </div>
</div>

<form method="post" id="deleteForm" class="d-none">
<?php echo csrfField(); ?>
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" id="delete_id">
</form>
<?php endif; ?>

<?php
$pageScript = <<<'JS'
<script>
function resetItemForm() {
    document.getElementById('itemModalTitle').textContent = 'Add Item';
    document.getElementById('item_id').value = '';
    ['i_name','i_sku','i_barcode','i_expiry'].forEach(id => document.getElementById(id).value = '');
    ['i_category','i_unit','i_supplier'].forEach(id => document.getElementById(id).value = '');
    ['i_purchase','i_min','i_reorder','i_opening'].forEach(id => document.getElementById(id).value = '0');
    document.getElementById('i_status').value = 'active';
    document.getElementById('i_perishable').checked = false;
    document.getElementById('openingStockWrap').style.display = '';
    document.getElementById('correctStockWrap').style.display = 'none';
    document.getElementById('i_corrected').value = '';
}
function editItem(it) {
    document.getElementById('itemModalTitle').textContent = 'Edit Item';
    document.getElementById('item_id').value = it.id;
    document.getElementById('i_name').value = it.name || '';
    document.getElementById('i_sku').value = it.sku || '';
    document.getElementById('i_barcode').value = it.barcode || '';
    document.getElementById('i_category').value = it.category_id || '';
    document.getElementById('i_unit').value = it.unit_id || '';
    document.getElementById('i_supplier').value = it.supplier_id || '';
    document.getElementById('i_purchase').value = it.purchase_price || '0';
    document.getElementById('i_min').value = it.min_stock || '0';
    document.getElementById('i_reorder').value = it.reorder_level || '0';
    document.getElementById('i_status').value = it.status || 'active';
    document.getElementById('i_expiry').value = it.expiry_date || '';
    document.getElementById('i_perishable').checked = (it.is_perishable == 1);
    document.getElementById('openingStockWrap').style.display = 'none';
    document.getElementById('correctStockWrap').style.display = ''; // corrections allowed when editing
    document.getElementById('i_corrected').value = '';
    document.getElementById('i_corrected').placeholder = 'Now: ' + (it.current_stock || '0');
    new bootstrap.Modal(document.getElementById('itemModal')).show();
}
function deleteItem(id, name) {
    if (confirm('Remove item "' + name + '"? Its movement history is kept (soft delete).')) {
        document.getElementById('delete_id').value = id;
        document.getElementById('deleteForm').submit();
    }
}
</script>
JS;
include 'inventory-footer.php';
