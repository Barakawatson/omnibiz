<?php
// ============================================================
// Expiry Alerts
// ------------------------------------------------------------
// Staff-facing view of stock approaching its expiry date, with the
// ADJUSTED price the till would actually charge for it. Priced
// through retailEffectivePrice()/retailExpiryDiscount() - the exact
// same functions the checkout and the customer display use - so
// this page can never show a number the till would then contradict.
//
// Visible to cashier and storekeeper as well as admin/manager: a
// cashier benefits from knowing an item is marked down before ringing
// it up, and a storekeeper needs to see what is about to go to waste
// so stock can be requested or the item pulled from the shelf.
// ============================================================
require_once '../includes/auth.php';
requireModule('expiry_alerts');
date_default_timezone_set('Africa/Dar_es_Salaam');
$current_page = basename($_SERVER['PHP_SELF']);
include '../includes/db.php';
require_once '../includes/inventory_functions.php';
require_once '../includes/catalog_functions.php';
catalogBoot($conn);

// Only a role that can act on already-expired stock (raise a disposal
// request) gets the "remove from shelf" link - showing it to a cashier
// would point at a page they'd only be turned away from.
$canDispose = userCan('inventory');

// ---------- Filters ----------
$days = (int)($_GET['days'] ?? 30);
if (!in_array($days, [7, 14, 30, 60], true)) { $days = 30; }

$departments = catalogActiveDepartments($conn);
$deptFilter  = isset($departments[$_GET['dept'] ?? '']) ? (string)$_GET['dept'] : '';
$deptSql     = catalogDepartmentFilterSql($conn, 'i');

// ---------- Expiring-soon items (within the selected window) ----------
$conds  = ["i.deleted_at IS NULL", "i.status = 'active'", "i.current_stock > 0",
           "i.expiry_date IS NOT NULL", "i.expiry_date >= CURDATE()",
           "i.expiry_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)"];
$params = [$days];
$types  = 'i';
if ($deptFilter !== '') { $conds[] = 'i.department = ?'; $params[] = $deptFilter; $types .= 's'; }
$where = implode(' AND ', $conds) . $deptSql;

$stmt = $conn->prepare(
    "SELECT i.id, i.name, i.sku, i.expiry_date, i.current_stock, i.average_cost, i.department,
            u.abbreviation AS unit, c.name AS category_name,
            d.selling_price, d.promo_price, d.promo_active
     FROM inv_items i
     JOIN retail_product_details d ON d.item_id = i.id
     LEFT JOIN inv_units u ON u.id = i.unit_id
     LEFT JOIN inv_categories c ON c.id = i.category_id
     WHERE $where
     ORDER BY i.expiry_date ASC
     LIMIT 500");
$stmt->bind_param($types, ...$params);
$stmt->execute();
$expiring = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ---------- Metrics (fixed reference points, independent of $days) ----------
$mConds = ["i.deleted_at IS NULL", "i.status = 'active'", "i.current_stock > 0",
           "i.expiry_date IS NOT NULL", "i.expiry_date >= CURDATE()"];
$mParams = [];
$mTypes  = '';
if ($deptFilter !== '') { $mConds[] = 'i.department = ?'; $mParams[] = $deptFilter; $mTypes .= 's'; }
$mWhere = implode(' AND ', $mConds) . $deptSql;

$mStmt = $conn->prepare(
    "SELECT
        SUM(CASE WHEN i.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS due7,
        SUM(CASE WHEN i.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS due30,
        SUM(CASE WHEN i.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                 THEN i.current_stock * i.average_cost ELSE 0 END) AS value30
     FROM inv_items i WHERE $mWhere");
if ($mParams) { $mStmt->bind_param($mTypes, ...$mParams); }
$mStmt->execute();
$metrics = $mStmt->get_result()->fetch_assoc();
$mStmt->close();

// ---------- Already expired, still on the books ----------
// Same PER-BATCH fix as admin/inventory-disposal.php's eligible list:
// current_stock is the item's blended total across every batch, so an
// item with one expired batch and one fresh one must not have the
// fresh half counted as expired too. See that page's comment for the
// full reasoning; this mirrors it for consistency.
$ebConds = ["i.deleted_at IS NULL", "i.status = 'active'",
            "b.status = 'active'", "b.quantity > 0",
            "b.expiry_date IS NOT NULL", "b.expiry_date < CURDATE()"];
$ebParams = [];
$ebTypes  = '';
if ($deptFilter !== '') { $ebConds[] = 'i.department = ?'; $ebParams[] = $deptFilter; $ebTypes .= 's'; }
$ebWhere = implode(' AND ', $ebConds) . $deptSql;

$expired = [];
$ebStmt = $conn->prepare(
    "SELECT i.name, i.department, b.id AS batch_id, b.batch_no, b.quantity, b.expiry_date,
            u.abbreviation AS unit, c.name AS category_name
     FROM inv_batches b
     JOIN inv_items i ON i.id = b.item_id
     LEFT JOIN inv_units u ON u.id = i.unit_id
     LEFT JOIN inv_categories c ON c.id = i.category_id
     WHERE $ebWhere
     ORDER BY b.expiry_date ASC
     LIMIT 200");
if ($ebParams) { $ebStmt->bind_param($ebTypes, ...$ebParams); }
$ebStmt->execute();
foreach ($ebStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $b) {
    $expired[] = [
        'name'          => $b['name'],
        'department'    => $b['department'],
        'category_name' => $b['category_name'],
        'unit'          => $b['unit'],
        'expiry_date'   => $b['expiry_date'],
        'quantity'      => (float)$b['quantity'],
        'batch_label'   => 'Batch ' . (($b['batch_no'] ?? '') !== '' ? $b['batch_no'] : ('#' . $b['batch_id'])),
    ];
}
$ebStmt->close();

// Same defensive fallback as the disposal screen: an item whose own
// expiry_date field is expired but whose active batches don't fully
// cover its current_stock (a pre-batch-tracking data gap) still
// surfaces its uncovered remainder, so nothing silently vanishes.
$efConds = ["i.deleted_at IS NULL", "i.status = 'active'", "i.current_stock > 0",
            "i.expiry_date IS NOT NULL", "i.expiry_date < CURDATE()"];
$efParams = [];
$efTypes  = '';
if ($deptFilter !== '') { $efConds[] = 'i.department = ?'; $efParams[] = $deptFilter; $efTypes .= 's'; }
$efWhere = implode(' AND ', $efConds) . $deptSql;

$efStmt = $conn->prepare(
    "SELECT i.name, i.department, i.current_stock, i.expiry_date,
            u.abbreviation AS unit, c.name AS category_name,
            COALESCE((SELECT SUM(b2.quantity) FROM inv_batches b2 WHERE b2.item_id = i.id AND b2.status = 'active'), 0) AS batch_covered
     FROM inv_items i
     LEFT JOIN inv_units u ON u.id = i.unit_id
     LEFT JOIN inv_categories c ON c.id = i.category_id
     WHERE $efWhere
     LIMIT 200");
if ($efParams) { $efStmt->bind_param($efTypes, ...$efParams); }
$efStmt->execute();
foreach ($efStmt->get_result()->fetch_all(MYSQLI_ASSOC) as $f) {
    $uncovered = (float)$f['current_stock'] - (float)$f['batch_covered'];
    if ($uncovered <= 0.0005) { continue; }
    $expired[] = [
        'name'          => $f['name'],
        'department'    => $f['department'],
        'category_name' => $f['category_name'],
        'unit'          => $f['unit'],
        'expiry_date'   => $f['expiry_date'],
        'quantity'      => $uncovered,
        'batch_label'   => 'Untracked stock',
    ];
}
$efStmt->close();

usort($expired, function ($a, $b) { return strcmp($a['expiry_date'], $b['expiry_date']); });
$expired = array_slice($expired, 0, 200);

$pageTitle = 'Expiry Alerts';
$breadcrumbs = [['Dashboard', 'index.php'], ['Expiry Alerts']];
include 'inventory-header.php';
?>
<div class="inv-page-header">
    <div>
        <h1><i class="fas fa-triangle-exclamation me-2" style="color:var(--inv-primary);"></i>Expiry Alerts</h1>
        <div class="subtitle">Stock nearing its expiry date, priced at what the till would actually charge for it.</div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-md-4">
        <div class="inv-stat-card bg-grad-amber"><div class="d-flex justify-content-between">
            <div><div class="label">Expiring in 7 days</div><div class="value"><?php echo (int)($metrics['due7'] ?? 0); ?></div></div>
            <i class="fas fa-hourglass-half icon"></i>
        </div></div>
    </div>
    <div class="col-6 col-md-4">
        <div class="inv-stat-card bg-grad-teal"><div class="d-flex justify-content-between">
            <div><div class="label">Expiring in 30 days</div><div class="value"><?php echo (int)($metrics['due30'] ?? 0); ?></div></div>
            <i class="fas fa-calendar-days icon"></i>
        </div></div>
    </div>
    <div class="col-12 col-md-4">
        <div class="inv-stat-card bg-grad-red"><div class="d-flex justify-content-between">
            <div><div class="label">Stock value at risk (30d)</div><div class="value" style="font-size:1.1rem;"><?php echo invMoney($conn, $metrics['value30'] ?? 0); ?></div></div>
            <i class="fas fa-sack-dollar icon"></i>
        </div></div>
    </div>
</div>

<!-- Window + department filter -->
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <ul class="nav nav-pills" style="gap:6px;">
        <?php foreach ([7, 14, 30, 60] as $opt): ?>
        <li class="nav-item">
            <a class="nav-link <?php echo $days === $opt ? 'active' : ''; ?>"
               href="?days=<?php echo $opt; ?><?php echo $deptFilter !== '' ? '&dept=' . urlencode($deptFilter) : ''; ?>"
               style="border-radius:20px;">Within <?php echo $opt; ?> days</a>
        </li>
        <?php endforeach; ?>
    </ul>
    <?php if (count($departments) > 1): ?>
    <form method="get" class="d-flex align-items-center gap-2">
        <input type="hidden" name="days" value="<?php echo $days; ?>">
        <select name="dept" class="form-select form-select-sm" style="width:auto;" onchange="this.form.submit()">
            <option value="">All departments</option>
            <?php foreach ($departments as $key => $d): ?>
            <option value="<?php echo htmlspecialchars($key); ?>" <?php echo $deptFilter === $key ? 'selected' : ''; ?>>
                <?php echo htmlspecialchars($d['label']); ?>
            </option>
            <?php endforeach; ?>
        </select>
    </form>
    <?php endif; ?>
</div>

<div class="inv-card p-0">
    <div class="table-responsive">
        <table class="inv-table">
            <thead>
                <tr>
                    <th>Item</th><th>Category</th><th>Department</th><th class="text-end">Stock</th>
                    <th>Expiry date</th><th>Days left</th>
                    <th class="text-end">Price</th><th class="text-end">Adjusted price</th><th class="text-end">Discount</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$expiring): ?>
                <tr><td colspan="9"><div class="empty-state"><i class="fas fa-circle-check d-block" style="color:var(--color-success);"></i>Nothing expiring within <?php echo $days; ?> days.</div></td></tr>
                <?php endif; ?>
                <?php foreach ($expiring as $row):
                    $today = strtotime(date('Y-m-d'));
                    $expiry = strtotime((string)$row['expiry_date']);
                    $daysLeft = (int)round(($expiry - $today) / 86400);
                    $badge = $daysLeft <= 7 ? 'bg-danger' : ($daysLeft <= 14 ? 'bg-warning' : 'bg-secondary');
                    $was = (float)$row['selling_price'];
                    $now = retailEffectivePrice($row);
                    $disc = retailExpiryDiscount($row);
                ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($row['name']); ?></strong>
                        <?php if ($row['sku']): ?><div class="text-muted" style="font-size:.75rem;"><?php echo htmlspecialchars($row['sku']); ?></div><?php endif; ?>
                    </td>
                    <td><?php echo htmlspecialchars($row['category_name'] ?? '—'); ?></td>
                    <td><?php echo htmlspecialchars(catalogDepartmentLabel($row['department'])); ?></td>
                    <td class="text-end"><?php echo invQty($row['current_stock']); ?> <?php echo htmlspecialchars($row['unit'] ?? ''); ?></td>
                    <td><?php echo date('d M Y', $expiry); ?></td>
                    <td><span class="inv-badge <?php echo $badge; ?>"><?php echo $daysLeft; ?>d</span></td>
                    <td class="text-end"><?php echo $disc ? '<span class="text-muted text-decoration-line-through">' . invMoney($conn, $was) . '</span>' : invMoney($conn, $was); ?></td>
                    <td class="text-end"><strong><?php echo invMoney($conn, $now); ?></strong></td>
                    <td class="text-end"><?php echo $disc ? (round($disc['percent']) . '%') : '—'; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($expired): ?>
<div class="inv-card p-0 mt-3">
    <div class="p-3" style="border-bottom:1px solid var(--color-border);">
        <strong><i class="fas fa-trash-can me-2 text-danger"></i>Already expired — remove from shelf (<?php echo count($expired); ?>)</strong>
    </div>
    <div class="table-responsive">
        <table class="inv-table">
            <thead><tr><th>Item</th><th>Batch</th><th>Category</th><th>Department</th><th class="text-end">Qty</th><th>Expired on</th><?php if ($canDispose): ?><th></th><?php endif; ?></tr></thead>
            <tbody>
                <?php foreach ($expired as $row): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($row['name']); ?></strong></td>
                    <td class="text-muted" style="font-size:.85rem;"><?php echo htmlspecialchars($row['batch_label']); ?></td>
                    <td><?php echo htmlspecialchars($row['category_name'] ?? '—'); ?></td>
                    <td><?php echo htmlspecialchars(catalogDepartmentLabel($row['department'])); ?></td>
                    <td class="text-end"><?php echo invQty($row['quantity']); ?> <?php echo htmlspecialchars($row['unit'] ?? ''); ?></td>
                    <td><span class="inv-badge bg-danger"><?php echo date('d M Y', strtotime($row['expiry_date'])); ?></span></td>
                    <?php if ($canDispose): ?>
                    <td class="text-end"><a href="inventory-disposal.php" class="btn btn-sm btn-outline-danger">Request disposal</a></td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php include 'inventory-footer.php'; ?>
