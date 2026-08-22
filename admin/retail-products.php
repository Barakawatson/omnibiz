<?php
// ============================================================
// Admin - Retail Products
// ------------------------------------------------------------
// Layers the sellable attributes (department, selling/promo price,
// images, description, brand, pack size, till visibility) on top of
// existing inv_items rows. Inventory itself (stock, cost, suppliers)
// stays managed by the inventory module - this page never duplicates
// it, and never writes current_stock.
// ============================================================
require_once '../includes/auth.php';
requireModule('products');
// Reject any POST that does not carry this session's CSRF token.
// Placed before every handler on this page, and a no-op on GET.
csrfRequire();
$current_page = basename($_SERVER['PHP_SELF']);
include '../includes/db.php';
require_once '../includes/pos_functions.php';
posBoot($conn);

$userId = (int)($_SESSION['id'] ?? 0);
$departments = catalogDepartments($conn);

// Image files stay in their original folder so paths already stored
// in the database keep resolving after the retail conversion.
$uploadDirFs  = __DIR__ . '/../assets/uploads/shop_products';
$uploadDirRel = 'assets/uploads/shop_products'; // stored in DB, root-relative

// ---------- POST handling ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $itemId     = (int)($_POST['item_id'] ?? 0);
        // Fall back to the first department the shop actually has - never
        // to a hardcoded key, which may not exist in this business.
        $department = isset($departments[$_POST['department'] ?? ''])
            ? (string)$_POST['department']
            : catalogFirstActiveDepartment($conn);
        $barcode    = trim($_POST['barcode'] ?? '');

        if ($itemId <= 0) {
            retailFlash('danger', 'Invalid item.');
            header('Location: retail-products.php'); exit;
        }

        // Department lives on the inventory item (it drives the till
        // tabs, the Z-report split and the ledger), not on the catalog
        // row, so it is saved separately.
        $deptStmt = $conn->prepare("UPDATE inv_items SET department = ? WHERE id = ? AND deleted_at IS NULL");
        $deptStmt->bind_param('si', $department, $itemId);
        $deptStmt->execute();
        $deptStmt->close();

        [$ok, $msg] = retailSaveProductDetails($conn, $itemId, [
            'usage_type'     => $_POST['usage_type'] ?? 'sale',
            'description'    => $_POST['description'] ?? '',
            'package_size'   => mb_substr(trim($_POST['package_size'] ?? ''), 0, 40),
            'brand'          => mb_substr(trim($_POST['brand'] ?? ''), 0, 80),
            'selling_price'  => $_POST['selling_price'] ?? 0,
            'promo_price'    => $_POST['promo_price'] ?? '',
            'promo_active'   => isset($_POST['promo_active']),
            'is_enabled'     => isset($_POST['is_enabled']),
            'is_pos_visible' => isset($_POST['is_pos_visible']),
        ]);

        // A price of zero on a sellable item would ring up as free.
        $usageType = $_POST['usage_type'] ?? 'sale';
        if ($ok && in_array($usageType, ['sale', 'both'], true) && (float)($_POST['selling_price'] ?? 0) <= 0) {
            $ok = false;
            $msg = 'A selling price is required for items sold at the till.';
        }

        if ($ok) {
            invAudit($conn, $userId, 'product_save', 'item', $itemId,
                'dept=' . $department . ', price=' . (float)($_POST['selling_price'] ?? 0));
        }
        retailFlash($ok ? 'success' : 'danger', $msg);

        // Barcode is stored on the inventory item itself (shared with
        // the barcode station and the POS scanner).
        if ($barcode !== '') {
            [$bcOk, $bcMsg] = posAssignBarcode($conn, $itemId, $barcode, $userId);
            if (!$bcOk) { retailFlash('danger', $bcMsg); }
        }

        // ---- Multi-image upload (jpg/png/webp, max 3 MB each) ----
        // Validation goes through the shared helper so this path, profile
        // photos and PO invoices all enforce the same rules. Previously
        // this was the only one that got it right; now there is one
        // implementation rather than three that can drift.
        if (!empty($_FILES['images']['name'][0])) {
            $saved = 0;
            $rejected = [];
            foreach ($_FILES['images']['tmp_name'] as $i => $tmp) {
                $one = [
                    'error'    => $_FILES['images']['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                    'tmp_name' => $tmp,
                ];
                if ($one['error'] === UPLOAD_ERR_NO_FILE) { continue; }

                [$upOk, $upRes] = uploadStoreImage($one, $uploadDirFs, 'p' . $itemId);
                if (!$upOk) {
                    $rejected[] = ($_FILES['images']['name'][$i] ?? 'file') . ' - ' . $upRes;
                    continue;
                }

                $path = $uploadDirRel . '/' . $upRes;
                $img = $conn->prepare("INSERT INTO retail_product_images (item_id, file_path, sort_order) VALUES (?, ?, ?)");
                $img->bind_param('isi', $itemId, $path, $i);
                if ($img->execute()) {
                    $saved++;
                } else {
                    uploadDeleteFile($uploadDirFs, $upRes);   // don't orphan it
                    $rejected[] = ($_FILES['images']['name'][$i] ?? 'file') . ' - could not be recorded.';
                }
                $img->close();
            }

            // Rejected files used to be skipped in silence, so a product
            // simply had no picture and nobody knew why.
            if ($saved > 0)   { retailFlash('success', 'Product saved. ' . $saved . ' image(s) uploaded.'); }
            if ($rejected)    { retailFlash('danger', 'Some images were not saved: ' . implode(' | ', $rejected)); }
        }

        header('Location: retail-products.php'); exit;
    }

    if ($action === 'delete_image') {
        $imgId = (int)($_POST['image_id'] ?? 0);
        $stmt = $conn->prepare("SELECT file_path FROM retail_product_images WHERE id = ?");
        $stmt->bind_param('i', $imgId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            @unlink(__DIR__ . '/../' . $row['file_path']);
            $del = $conn->prepare("DELETE FROM retail_product_images WHERE id = ?");
            $del->bind_param('i', $imgId);
            $del->execute();
            $del->close();
            retailFlash('success', 'Image removed.');
        }
        header('Location: retail-products.php'); exit;
    }

    if ($action === 'withdraw') {
        // "Withdraw from sale" takes the product off the till; the
        // inventory item itself is untouched (the inventory module owns
        // item deletion), so stock and history survive.
        $itemId = (int)($_POST['item_id'] ?? 0);
        $stmt = $conn->prepare("UPDATE retail_product_details SET usage_type = 'internal', is_enabled = 0, is_pos_visible = 0 WHERE item_id = ?");
        $stmt->bind_param('i', $itemId);
        $stmt->execute();
        $stmt->close();
        invAudit($conn, $userId, 'product_withdraw', 'item', $itemId, '');
        retailFlash('success', 'Product withdrawn from sale (inventory item kept).');
        header('Location: retail-products.php'); exit;
    }
}

// ---------- Data for the page ----------
$filter     = $_GET['filter'] ?? 'all';
$deptFilter = isset($departments[$_GET['dept'] ?? '']) ? $_GET['dept'] : '';
$search     = trim($_GET['q'] ?? '');
$page       = max(1, (int)($_GET['page'] ?? 1));
$perPage    = 25;

$where  = "i.deleted_at IS NULL";
$params = [];
$types  = '';

// Server-side search: the catalogue can outgrow a single page, so
// filtering must happen in SQL rather than by hiding rendered rows.
if ($search !== '') {
    $where .= " AND (i.name LIKE ? OR i.sku LIKE ? OR i.barcode LIKE ? OR d.brand LIKE ?)";
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like);
    $types .= 'ssss';
}

if ($filter === 'selling')  { $where .= " AND d.usage_type IN ('sale','both')"; }
if ($filter === 'internal') { $where .= " AND (d.usage_type IS NULL OR d.usage_type = 'internal')"; }
if ($filter === 'low')      { $where .= " AND d.usage_type IN ('sale','both') AND i.current_stock <= i.reorder_level AND i.current_stock > 0"; }
if ($filter === 'out')      { $where .= " AND d.usage_type IN ('sale','both') AND i.current_stock <= 0"; }
if ($filter === 'unpriced') { $where .= " AND d.usage_type IN ('sale','both') AND (d.selling_price IS NULL OR d.selling_price <= 0)"; }
if ($deptFilter !== '')     { $where .= " AND i.department = ?"; $params[] = $deptFilter; $types .= 's'; }

// Products in a disabled department are not part of the current range,
// so they are hidden by default. Explicitly filtering TO a disabled
// department still shows them - that is the route the Departments
// screen links to when you need to move stranded stock somewhere it
// can actually sell.
$viewingDisabledDept = ($deptFilter !== '' && !catalogDepartmentEnabled($conn, $deptFilter));
if (!$viewingDisabledDept) {
    $where .= catalogDepartmentFilterSql($conn, 'i');
}

// Total first, so pagination knows how many pages exist.
$countSql = "SELECT COUNT(*) AS c
             FROM inv_items i
             LEFT JOIN retail_product_details d ON d.item_id = i.id
             WHERE $where";
$cStmt = $conn->prepare($countSql);
if ($types !== '') { $cStmt->bind_param($types, ...$params); }
$cStmt->execute();
$totalItems = (int)($cStmt->get_result()->fetch_assoc()['c'] ?? 0);
$cStmt->close();

$totalPages = max(1, (int)ceil($totalItems / $perPage));
if ($page > $totalPages) { $page = $totalPages; }
$offset = ($page - 1) * $perPage;

$sql = "SELECT i.id, i.name, i.sku, i.barcode, i.department, i.current_stock, i.reorder_level,
               i.min_stock, i.status, i.average_cost, i.expiry_date,
               c.name AS category_name, u.abbreviation AS unit,
               d.usage_type, d.description, d.package_size, d.brand, d.selling_price,
               d.promo_price, d.promo_active, d.is_enabled, d.is_pos_visible,
               (SELECT COUNT(*) FROM retail_product_images im WHERE im.item_id = i.id) AS image_count,
               (SELECT file_path FROM retail_product_images im WHERE im.item_id = i.id ORDER BY im.sort_order, im.id LIMIT 1) AS image,
               (SELECT COALESCE(SUM(li.quantity), 0) FROM sales_transaction_items li
                 JOIN sales_transactions t ON t.id = li.transaction_id AND t.status = 'completed'
                 WHERE li.item_id = i.id) AS sold_qty
        FROM inv_items i
        LEFT JOIN retail_product_details d ON d.item_id = i.id
        LEFT JOIN inv_categories c ON c.id = i.category_id
        LEFT JOIN inv_units u ON u.id = i.unit_id
        WHERE $where
        ORDER BY i.name
        LIMIT $perPage OFFSET $offset";
$stmt = $conn->prepare($sql);
if ($types !== '') { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// Images for the products on THIS page only. Previously every image in
// the catalogue was loaded on every request to feed one modal per row.
$imagesByItem = [];
$pageIds = array_column($items, 'id');
if ($pageIds) {
    $idList = implode(',', array_map('intval', $pageIds));
    $imgRes = $conn->query("SELECT id, item_id, file_path FROM retail_product_images
                            WHERE item_id IN ($idList) ORDER BY sort_order, id");
    if ($imgRes) {
        foreach ($imgRes->fetch_all(MYSQLI_ASSOC) as $im) { $imagesByItem[(int)$im['item_id']][] = $im; }
    }
}

/** Query string carrying the current filters, minus `page`. */
function productQuery(array $overrides = []): string {
    $base = [
        'filter' => $_GET['filter'] ?? 'all',
        'dept'   => $_GET['dept'] ?? '',
        'q'      => $_GET['q'] ?? '',
    ];
    $merged = array_filter(array_merge($base, $overrides), function ($v) { return $v !== '' && $v !== null; });
    return '?' . http_build_query($merged);
}

$counts = ['selling' => 0, 'enabled' => 0, 'low' => 0, 'out' => 0, 'unpriced' => 0];
$countRes = $conn->query(
    "SELECT SUM(d.usage_type IN ('sale','both')) AS selling,
            SUM(d.usage_type IN ('sale','both') AND d.is_enabled = 1) AS enabled,
            SUM(d.usage_type IN ('sale','both') AND i.current_stock <= i.reorder_level AND i.current_stock > 0) AS low,
            SUM(d.usage_type IN ('sale','both') AND i.current_stock <= 0) AS `out`,
            SUM(d.usage_type IN ('sale','both') AND (d.selling_price IS NULL OR d.selling_price <= 0)) AS unpriced
     FROM inv_items i JOIN retail_product_details d ON d.item_id = i.id
     WHERE i.deleted_at IS NULL" . catalogDepartmentFilterSql($conn, 'i'));
if ($countRes && ($r = $countRes->fetch_assoc())) { $counts = array_map('intval', $r); }

$pageTitle = 'Products';
$breadcrumbs = [['Dashboard', 'index.php'], ['Products']];
include 'inventory-header.php';

// Nothing can be created without master data to hang it on.
$guardNeeds = ['departments'];
include 'partials/setup-required.php';
?>
<?php
$ph = [
    'title'    => 'Products',
    'icon'     => 'fa-tags',
    'subtitle' => 'Set what is sold at the till, in which department, and at what price. '
                . 'Stock and costs live in <a href="inventory-items.php">Inventory &rsaquo; Items</a>.',
];
include 'partials/page-header.php';
?>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3"><div class="inv-stat-card bg-grad-teal"><div class="d-flex justify-content-between align-items-start"><div><div class="label">On sale</div><div class="value"><?php echo number_format($counts['selling']); ?></div></div><i class="fas fa-tags icon"></i></div></div></div>
    <div class="col-6 col-md-3"><div class="inv-stat-card bg-grad-green"><div class="d-flex justify-content-between align-items-start"><div><div class="label">Visible on till</div><div class="value"><?php echo number_format($counts['enabled']); ?></div></div><i class="fas fa-cash-register icon"></i></div></div></div>
    <div class="col-6 col-md-3"><div class="inv-stat-card bg-grad-amber"><div class="d-flex justify-content-between align-items-start"><div><div class="label">Low stock</div><div class="value"><?php echo number_format($counts['low']); ?></div></div><i class="fas fa-triangle-exclamation icon"></i></div></div></div>
    <div class="col-6 col-md-3"><div class="inv-stat-card bg-grad-red"><div class="d-flex justify-content-between align-items-start"><div><div class="label">Out of stock</div><div class="value"><?php echo number_format($counts['out']); ?></div></div><i class="fas fa-ban icon"></i></div></div></div>
</div>

<?php if ($counts['unpriced'] > 0 && $filter !== 'unpriced'): ?>
<div class="alert alert-warning">
    <i class="fas fa-triangle-exclamation"></i>
    <div>
        <strong><?php echo $counts['unpriced']; ?></strong> product(s) are marked for sale but have no price, so the
        till cannot ring them up. <a href="<?php echo htmlspecialchars(productQuery(['filter' => 'unpriced'])); ?>">Show them</a>.
    </div>
</div>
<?php endif; ?>

<!-- ============ Toolbar: search, status chips, department ============ -->
<form method="get" class="ui-toolbar" role="search">
    <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>">
    <?php if ($deptFilter !== ''): ?><input type="hidden" name="dept" value="<?php echo htmlspecialchars($deptFilter); ?>"><?php endif; ?>

    <div class="ui-search <?php echo $search !== '' ? 'has-value' : ''; ?>">
        <i class="fas fa-magnifying-glass"></i>
        <input type="search" name="q" value="<?php echo htmlspecialchars($search); ?>"
               placeholder="Search name, SKU, barcode or brand…" aria-label="Search products">
        <button type="button" class="ui-search-clear" aria-label="Clear search"><i class="fas fa-xmark"></i></button>
    </div>
    <button class="ui-btn ui-btn-secondary"><i class="fas fa-magnifying-glass"></i>Search</button>

    <?php if ($search !== '' || $filter !== 'all' || $deptFilter !== ''): ?>
        <a href="retail-products.php" class="ui-btn ui-btn-ghost"><i class="fas fa-xmark"></i>Clear filters</a>
    <?php endif; ?>

    <span class="ui-toolbar-spacer"></span>
    <span class="ui-result-count"><strong><?php echo number_format($totalItems); ?></strong> product(s)</span>
</form>

<div class="ui-toolbar">
    <?php
    $filters = ['all' => 'All', 'selling' => 'On sale', 'internal' => 'Store use only',
                'low' => 'Low stock', 'out' => 'Out of stock', 'unpriced' => 'Missing price'];
    foreach ($filters as $key => $label): ?>
    <a href="<?php echo htmlspecialchars(productQuery(['filter' => $key])); ?>"
       class="ui-chip <?php echo $filter === $key ? 'active' : ''; ?>"><?php echo $label; ?></a>
    <?php endforeach; ?>

    <span style="width:1px;height:20px;background:var(--color-border);margin:0 4px;"></span>

    <a href="<?php echo htmlspecialchars(productQuery(['dept' => ''])); ?>"
       class="ui-chip <?php echo $deptFilter === '' ? 'active' : ''; ?>">All departments</a>
    <?php foreach ($departments as $key => $d):
        // A disabled department only gets a chip while you are looking at
        // it, so the list stays clean but you can still get back out.
        $deptOn = catalogDepartmentEnabled($conn, $key);
        if (!$deptOn && $deptFilter !== $key) { continue; }
    ?>
    <a href="<?php echo htmlspecialchars(productQuery(['dept' => $key])); ?>"
       class="ui-chip <?php echo $deptFilter === $key ? 'active' : ''; ?>">
        <i class="fas <?php echo htmlspecialchars($d['icon']); ?>"></i><?php echo htmlspecialchars($d['label']); ?>
        <?php if (!$deptOn): ?><span class="ui-caption">(disabled)</span><?php endif; ?>
    </a>
    <?php endforeach; ?>
</div>

<?php if ($viewingDisabledDept): ?>
<div class="alert alert-warning" style="border-radius:12px;">
    <i class="fas fa-circle-pause me-1"></i>
    <strong><?php echo htmlspecialchars(catalogDepartmentLabel($deptFilter)); ?></strong> is disabled, so these
    products are not on sale anywhere. Move them to a trading department below, or re-enable it in
    <a href="departments.php">Departments</a>.
</div>
<?php endif; ?>

<div class="inv-card p-0">
    <div class="table-responsive">
        <table class="inv-table">
            <thead>
                <tr>
                    <th style="width:52px;"><span class="visually-hidden">Image</span></th>
                    <th>Product</th>
                    <th class="ui-col-optional">Department</th>
                    <th class="ui-col-optional">Category</th>
                    <th class="ui-col-secondary">Availability</th>
                    <th class="text-end">Stock</th>
                    <th class="text-end">Price</th>
                    <th class="text-end ui-col-secondary">Margin</th>
                    <th class="text-end ui-col-optional">Sold</th>
                    <th>Till</th>
                    <th style="width:96px;"><span class="visually-hidden">Actions</span></th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$items): ?>
                <tr><td colspan="11">
                    <?php
                    $es = $search !== ''
                        ? ['icon' => 'fa-magnifying-glass', 'title' => 'No products match “' . $search . '”',
                           'msg'  => 'Try a different name, SKU or barcode.',
                           'action' => '<a class="ui-btn ui-btn-secondary" href="retail-products.php">Clear search</a>']
                        : ['icon' => 'fa-box-open', 'title' => 'No products here',
                           'msg'  => 'Nothing matches this filter yet.',
                           'action' => '<a class="ui-btn ui-btn-secondary" href="inventory-items.php">Go to Inventory items</a>'];
                    include 'partials/empty-state.php';
                    ?>
                </td></tr>
            <?php endif; ?>
            <?php foreach ($items as $it):
                $usage    = $it['usage_type'] ?? 'internal';
                $onSale   = in_array($usage, ['sale','both'], true);
                $price    = retailEffectivePrice($it);
                $margin   = $onSale ? retailMarginPercent($price, (float)$it['average_cost']) : null;
                [$stockLabel, $stockClass] = invStockStatus($it);
                $dept = (string)($it['department'] ?? '');
                $deptMeta = $departments[$dept] ?? ['icon' => 'fa-boxes-stacked',
                                                    'label' => catalogDepartmentLabel($dept)];
            ?>
                <tr>
                    <td>
                        <?php if ($it['image']): ?>
                            <img src="../<?php echo htmlspecialchars($it['image']); ?>" class="ui-thumb" alt="" loading="lazy" width="38" height="38">
                        <?php else: ?>
                            <div class="ui-thumb-placeholder"><i class="fas fa-image"></i></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div style="font-weight:500;"><?php echo htmlspecialchars($it['name']); ?></div>
                        <?php if ($it['brand'] || $it['package_size']): ?>
                        <div class="ui-caption"><?php echo htmlspecialchars(trim(($it['brand'] ?? '') . ' ' . ($it['package_size'] ?? ''))); ?></div>
                        <?php endif; ?>
                        <?php if ($it['sku'] || $it['barcode']): ?>
                        <div class="ui-caption">
                            <?php if ($it['sku']): ?><?php echo htmlspecialchars($it['sku']); ?><?php endif; ?>
                            <?php if ($it['barcode']): ?> &middot; <i class="fas fa-barcode"></i> <?php echo htmlspecialchars($it['barcode']); ?><?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td class="ui-col-optional">
                        <span class="ui-badge ui-badge-neutral">
                            <i class="fas <?php echo htmlspecialchars($deptMeta['icon']); ?>"></i><?php echo htmlspecialchars($deptMeta['label']); ?>
                        </span>
                    </td>
                    <td class="ui-col-optional"><?php echo htmlspecialchars($it['category_name'] ?? '—'); ?></td>
                    <td class="ui-col-secondary">
                        <?php
                        $usageBadge = ['internal' => 'ui-badge-neutral', 'sale' => 'ui-badge-info', 'both' => 'ui-badge-info'][$usage] ?? 'ui-badge-neutral';
                        $usageLabel = ['internal' => 'Store use', 'sale' => 'For sale', 'both' => 'Sale + store'][$usage] ?? 'Store use';
                        ?>
                        <span class="ui-badge <?php echo $usageBadge; ?>"><?php echo $usageLabel; ?></span>
                    </td>
                    <td class="text-end">
                        <span class="ui-num"><?php echo invQty($it['current_stock']); ?></span>
                        <span class="ui-caption"><?php echo htmlspecialchars($it['unit'] ?? ''); ?></span>
                        <div><span class="ui-badge ui-badge-<?php echo $stockClass === 'warning' ? 'warning' : ($stockClass === 'danger' ? 'danger' : 'success'); ?>"><?php echo $stockLabel; ?></span></div>
                    </td>
                    <td class="text-end">
                        <?php if ($onSale): ?>
                            <?php if (!empty($it['promo_active']) && $it['promo_price'] !== null): ?>
                                <div class="ui-caption text-decoration-line-through">Tsh <?php echo number_format((float)$it['selling_price']); ?></div>
                                <span class="ui-badge ui-badge-danger">Tsh <?php echo number_format((float)$it['promo_price']); ?></span>
                            <?php else: ?>
                                <span class="ui-money">Tsh <?php echo number_format((float)$it['selling_price']); ?></span>
                            <?php endif; ?>
                        <?php else: ?><span class="ui-caption">—</span><?php endif; ?>
                    </td>
                    <td class="text-end ui-col-secondary">
                        <?php if ($margin === null): ?><span class="ui-caption">—</span>
                        <?php else: ?>
                            <span style="color:<?php echo $margin < 5 ? 'var(--color-danger)' : ($margin < 15 ? 'var(--color-warning)' : 'var(--color-success)'); ?>;font-weight:500;">
                                <?php echo $margin; ?>%
                            </span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end ui-col-optional ui-num"><?php echo invQty($it['sold_qty']); ?></td>
                    <td>
                        <?php if (!$onSale): ?>
                            <span class="ui-badge ui-badge-neutral">Not sold</span>
                        <?php elseif (!empty($it['is_pos_visible']) && !empty($it['is_enabled'])): ?>
                            <span class="ui-badge ui-badge-success"><span class="ui-dot ui-dot-success"></span>On till</span>
                        <?php else: ?>
                            <span class="ui-badge ui-badge-neutral">Hidden</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="d-flex gap-1 justify-content-end">
                            <?php
                            // ONE modal is reused for every row. The row's current
                            // values ride along as data-field-* attributes and are
                            // written into the form by MX.fillModal - so the page
                            // no longer renders a full <form> per product.
                            $imgs = $imagesByItem[(int)$it['id']] ?? [];
                            $imgJson = [];
                            foreach ($imgs as $im) { $imgJson[] = ['id' => (int)$im['id'], 'path' => '../' . $im['file_path']]; }
                            ?>
                            <button class="ui-btn ui-btn-secondary ui-btn-sm ui-btn-icon"
                                    title="Edit <?php echo htmlspecialchars($it['name']); ?>"
                                    aria-label="Edit <?php echo htmlspecialchars($it['name']); ?>"
                                    data-ui-modal="#productModal"
                                    data-title="<?php echo htmlspecialchars($it['name']); ?>"
                                    data-images='<?php echo htmlspecialchars(json_encode($imgJson), ENT_QUOTES); ?>'
                                    data-field-item-id="<?php echo (int)$it['id']; ?>"
                                    data-field-department="<?php echo htmlspecialchars($dept); ?>"
                                    data-field-usage-type="<?php echo htmlspecialchars($usage); ?>"
                                    data-field-selling-price="<?php echo (float)($it['selling_price'] ?? 0); ?>"
                                    data-field-promo-price="<?php echo $it['promo_price'] !== null ? (float)$it['promo_price'] : ''; ?>"
                                    data-field-barcode="<?php echo htmlspecialchars($it['barcode'] ?? ''); ?>"
                                    data-field-brand="<?php echo htmlspecialchars($it['brand'] ?? ''); ?>"
                                    data-field-package-size="<?php echo htmlspecialchars($it['package_size'] ?? ''); ?>"
                                    data-field-description="<?php echo htmlspecialchars($it['description'] ?? ''); ?>"
                                    data-field-promo-active="<?php echo !empty($it['promo_active']) ? '1' : '0'; ?>"
                                    data-field-is-enabled="<?php echo !empty($it['is_enabled']) ? '1' : '0'; ?>"
                                    data-field-is-pos-visible="<?php echo !empty($it['is_pos_visible']) ? '1' : '0'; ?>"
                                    data-cost="<?php echo number_format((float)$it['average_cost'], 2); ?>"
                                    data-stock="<?php echo invQty($it['current_stock']) . ' ' . htmlspecialchars($it['unit'] ?? ''); ?>">
                                <i class="fas fa-pen"></i>
                            </button>
                            <?php if ($onSale): ?>
                            <form method="post" onsubmit="return confirm('Withdraw <?php echo htmlspecialchars($it['name'], ENT_QUOTES); ?> from sale? Stock and history are kept.');">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="withdraw">
                                <input type="hidden" name="item_id" value="<?php echo (int)$it['id']; ?>">
                                <button class="ui-btn ui-btn-danger ui-btn-sm ui-btn-icon" title="Withdraw from sale" aria-label="Withdraw from sale">
                                    <i class="fas fa-ban"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
    $pg = ['page' => $page, 'pages' => $totalPages, 'total' => $totalItems,
           'per_page' => $perPage, 'base' => productQuery(), 'label' => 'products'];
    include 'partials/pagination.php';
    ?>
</div>

<!-- ============================================================
     ONE product modal, reused by every row.
     ------------------------------------------------------------
     Previously this page rendered a complete <form> per product,
     so a 500-product catalogue meant 500 modals in the DOM. The
     row's values are now carried on its Edit button as
     data-field-* attributes and written in by MX.fillModal().
     The POST target, field names and handler are unchanged.
     ============================================================ -->
<div class="modal fade" id="productModal" tabindex="-1" aria-labelledby="productModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <form method="post" enctype="multipart/form-data" data-ui-loading>
<?php echo csrfField(); ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="item_id" value="">

                <div class="modal-header">
                    <h5 class="modal-title" id="productModalTitle">
                        <i class="fas fa-tags me-2" style="color:var(--color-primary);"></i>
                        <span data-ui-modal-title>Edit product</span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="pm_department">Department</label>
                            <select class="form-select" name="department" id="pm_department">
                                <?php foreach ($departments as $key => $d):
                                    $deptOn = catalogDepartmentEnabled($conn, $key); ?>
                                <option value="<?php echo htmlspecialchars($key); ?>">
                                    <?php echo htmlspecialchars($d['label']); ?><?php echo $deptOn ? '' : ' (disabled — will not sell)'; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Drives the till tabs and the Z-report split.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="pm_usage">Availability</label>
                            <select class="form-select" name="usage_type" id="pm_usage">
                                <option value="sale">Sold to customers</option>
                                <option value="both">Sold to customers + used in store</option>
                                <option value="internal">Store use only (never on the till)</option>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label" for="pm_price">Selling price (Tsh)<span class="ui-required">*</span></label>
                            <input type="number" step="0.01" min="0" class="form-control" name="selling_price" id="pm_price">
                            <div class="form-text">Cost: Tsh <span data-ui-text="cost">0.00</span></div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="pm_promo">Promo price (Tsh)</label>
                            <input type="number" step="0.01" min="0" class="form-control" name="promo_price" id="pm_promo">
                            <div class="form-text">Must be lower than the selling price.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="pm_barcode">Barcode</label>
                            <input type="text" class="form-control" name="barcode" id="pm_barcode" placeholder="Scan or type">
                            <div class="form-text">Print from <a href="barcode-labels.php">Barcode Labels</a>.</div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="pm_brand">Brand</label>
                            <input type="text" class="form-control" name="brand" id="pm_brand" maxlength="80" placeholder="e.g. Azam, Bic">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="pm_pack">Pack / size</label>
                            <input type="text" class="form-control" name="package_size" id="pm_pack" maxlength="40" placeholder="e.g. 500ml, A4 ream">
                        </div>

                        <div class="col-12">
                            <label class="form-label" for="pm_desc">Description</label>
                            <textarea class="form-control" name="description" id="pm_desc" rows="2"></textarea>
                        </div>

                        <div class="col-md-6">
                            <fieldset class="ui-fieldset mb-0">
                                <legend class="ui-fieldset-legend">Visibility</legend>
                                <div class="form-check form-switch mb-1">
                                    <input class="form-check-input" type="checkbox" name="promo_active" id="pm_promo_active">
                                    <label class="form-check-label" for="pm_promo_active">Promotion active</label>
                                </div>
                                <div class="form-check form-switch mb-1">
                                    <input class="form-check-input" type="checkbox" name="is_enabled" id="pm_enabled">
                                    <label class="form-check-label" for="pm_enabled">Available for sale</label>
                                </div>
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="is_pos_visible" id="pm_visible">
                                    <label class="form-check-label" for="pm_visible">Show on the till grid</label>
                                </div>
                            </fieldset>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label" for="pm_images">Product images</label>
                            <input type="file" class="form-control" name="images[]" id="pm_images"
                                   accept="image/jpeg,image/png,image/webp" multiple>
                            <div class="form-text">JPG, PNG or WebP · max 3 MB each.</div>
                            <div class="d-flex gap-2 mt-2 flex-wrap" id="pm_gallery"></div>
                        </div>

                        <div class="col-12">
                            <div class="alert alert-light mb-0">
                                <i class="fas fa-circle-info"></i>
                                <div>
                                    In stock: <strong data-ui-text="stock">—</strong>.
                                    Adjust it in <a href="inventory-movements.php">Stock Movements</a>;
                                    cost and suppliers live in <a href="inventory-items.php">Inventory &rsaquo; Items</a>.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="ui-btn ui-btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="ui-btn ui-btn-primary"><i class="fas fa-check"></i>Save product</button>
                </div>
            </form>
        </div>
    </div>
</div>

<form method="post" id="deleteImageForm">
<?php echo csrfField(); ?>
    <input type="hidden" name="action" value="delete_image">
    <input type="hidden" name="image_id" id="deleteImageId">
</form>

<?php
$pageScript = <<<'HTML'
<script>
function deleteImage(id) {
    if (!confirm('Remove this image?')) return;
    document.getElementById('deleteImageId').value = id;
    document.getElementById('deleteImageForm').submit();
}

// The shared modal needs three things MX.fillModal does not cover:
// the image gallery, and the read-only cost/stock figures. They are
// read off the trigger button when the modal opens.
document.addEventListener('click', function (e) {
    const trigger = e.target.closest('[data-ui-modal="#productModal"]');
    if (!trigger) { return; }

    const modal = document.getElementById('productModal');

    // Read-only context.
    const cost = modal.querySelector('[data-ui-text="cost"]');
    if (cost) { cost.textContent = trigger.dataset.cost || '0.00'; }
    const stock = modal.querySelector('[data-ui-text="stock"]');
    if (stock) { stock.textContent = trigger.dataset.stock || '—'; }

    // Gallery. Built with DOM methods rather than innerHTML so a file
    // path can never inject markup.
    const gallery = document.getElementById('pm_gallery');
    if (!gallery) { return; }
    gallery.textContent = '';

    let images = [];
    try { images = JSON.parse(trigger.dataset.images || '[]'); } catch (err) { images = []; }

    images.forEach(function (img) {
        const wrap = document.createElement('div');
        wrap.style.position = 'relative';

        const el = document.createElement('img');
        el.src = img.path;
        el.alt = '';
        el.loading = 'lazy';
        el.style.cssText = 'width:56px;height:56px;border-radius:8px;object-fit:cover;border:1px solid var(--color-border);';

        const del = document.createElement('button');
        del.type = 'button';
        del.className = 'ui-btn ui-btn-danger-solid';
        del.setAttribute('aria-label', 'Remove image');
        del.style.cssText = 'position:absolute;top:-6px;right:-6px;width:20px;height:20px;padding:0;border-radius:50%;font-size:.6rem;';
        del.innerHTML = '<i class="fas fa-xmark"></i>';
        del.addEventListener('click', function () { deleteImage(img.id); });

        wrap.append(el, del);
        gallery.appendChild(wrap);
    });

    // Clear any file chosen for a previous product.
    const fileInput = document.getElementById('pm_images');
    if (fileInput) { fileInput.value = ''; }
});
</script>
HTML;
include 'inventory-footer.php';
?>
