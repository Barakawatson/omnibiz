<?php
// ============================================================
// Inventory - Product Categories
// ------------------------------------------------------------
// Categories group products for the till's category tabs, the
// reports and the department split. They used to exist only as
// seed data in includes/inventory_schema.php, so adding one meant
// editing PHP or running SQL. This screen manages them properly.
//
// A category in use is never destroyed: deleting one that products
// point at would orphan them and blank the category on every past
// report, so it is deactivated instead.
// ============================================================
require_once '../includes/auth.php';
requireModule('inventory');
// Reject any POST that does not carry this session's CSRF token.
// Placed before every handler on this page, and a no-op on GET.
csrfRequire();
$current_page = basename($_SERVER['PHP_SELF']);
include '../includes/db.php';
require_once '../includes/inventory_functions.php';
require_once '../includes/catalog_functions.php';
catalogBoot($conn);

$userId = (int)($_SESSION['id'] ?? 0);
$departments = catalogDepartments();

/** How many live products point at this category. */
function categoryUsage(mysqli $conn, int $id): int {
    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM inv_items WHERE category_id = ? AND deleted_at IS NULL");
    if (!$stmt) { return 0; }
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $n = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
    $stmt->close();
    return $n;
}

// ---------- POST ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id          = (int)($_POST['id'] ?? 0);
        $name        = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '') ?: null;
        $department  = isset($departments[$_POST['department'] ?? '']) ? $_POST['department'] : 'general';
        $isActive    = isset($_POST['is_active']) ? 1 : 0;

        if ($name === '') {
            retailFlash('danger', 'Enter a category name.');
        } elseif (mb_strlen($name) > 100) {
            retailFlash('danger', 'Category name is too long (100 characters maximum).');
        } else {
            if ($id > 0) {
                $stmt = $conn->prepare("UPDATE inv_categories SET name = ?, description = ?, department = ?, is_active = ?
                                        WHERE id = ? AND deleted_at IS NULL");
                $stmt->bind_param('sssii', $name, $description, $department, $isActive, $id);
                $ok = $stmt->execute();
                $dup = ($conn->errno === 1062);
                $stmt->close();

                retailFlash($ok ? 'success' : 'danger',
                    $ok ? 'Category updated.'
                        : ($dup ? 'Another category is already called "' . $name . '".' : 'Could not update the category.'));
                if ($ok) { invAudit($conn, $userId, 'category_update', 'category', $id, $name); }
            } else {
                $stmt = $conn->prepare("INSERT INTO inv_categories (name, description, department, is_active) VALUES (?, ?, ?, ?)");
                $stmt->bind_param('sssi', $name, $description, $department, $isActive);
                $ok = $stmt->execute();
                $newId = (int)$conn->insert_id;
                $dup = ($conn->errno === 1062);
                $stmt->close();

                retailFlash($ok ? 'success' : 'danger',
                    $ok ? 'Category "' . $name . '" added.'
                        : ($dup ? 'A category called "' . $name . '" already exists.' : 'Could not add the category.'));
                if ($ok) { invAudit($conn, $userId, 'category_create', 'category', $newId, $name); }
            }
        }
        header('Location: inventory-categories.php'); exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);

        $stmt = $conn->prepare("SELECT name FROM inv_categories WHERE id = ? AND deleted_at IS NULL");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $cat = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$cat) {
            retailFlash('danger', 'That category does not exist.');
        } else {
            $inUse = categoryUsage($conn, $id);
            if ($inUse > 0) {
                // Deactivating keeps every product's category intact and
                // keeps past reports readable; it just stops the category
                // being chosen for anything new.
                $stmt = $conn->prepare("UPDATE inv_categories SET is_active = 0 WHERE id = ?");
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $stmt->close();
                retailFlash('success', '"' . $cat['name'] . '" is used by ' . $inUse
                    . ' product(s), so it was deactivated rather than deleted - those products keep their category.');
            } else {
                $stmt = $conn->prepare("UPDATE inv_categories SET deleted_at = NOW(), is_active = 0 WHERE id = ?");
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $stmt->close();
                invAudit($conn, $userId, 'category_delete', 'category', $id, $cat['name']);
                retailFlash('success', 'Category "' . $cat['name'] . '" deleted.');
            }
        }
        header('Location: inventory-categories.php'); exit;
    }
}

// ---------- Data ----------
$deptFilter = isset($departments[$_GET['dept'] ?? '']) ? $_GET['dept'] : '';
$search     = trim($_GET['q'] ?? '');

$where  = "c.deleted_at IS NULL";
$params = [];
$types  = '';
if ($deptFilter !== '') { $where .= " AND c.department = ?"; $params[] = $deptFilter; $types .= 's'; }
if ($search !== '')     { $where .= " AND (c.name LIKE ? OR c.description LIKE ?)";
                          $like = '%' . $search . '%'; array_push($params, $like, $like); $types .= 'ss'; }

$sql = "SELECT c.*,
               (SELECT COUNT(*) FROM inv_items i WHERE i.category_id = c.id AND i.deleted_at IS NULL) AS item_count,
               (SELECT COALESCE(SUM(i.current_stock * i.average_cost), 0) FROM inv_items i
                 WHERE i.category_id = c.id AND i.deleted_at IS NULL) AS stock_value
        FROM inv_categories c
        WHERE $where
        ORDER BY c.department, c.name";
$stmt = $conn->prepare($sql);
if ($types !== '') { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$categories = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$totals = ['all' => 0, 'active' => 0, 'unused' => 0];
$cRes = $conn->query("SELECT COUNT(*) AS all_c,
                             SUM(is_active = 1) AS active_c,
                             SUM((SELECT COUNT(*) FROM inv_items i WHERE i.category_id = c.id AND i.deleted_at IS NULL) = 0) AS unused_c
                      FROM inv_categories c WHERE c.deleted_at IS NULL");
if ($cRes && ($r = $cRes->fetch_assoc())) {
    $totals = ['all' => (int)$r['all_c'], 'active' => (int)$r['active_c'], 'unused' => (int)$r['unused_c']];
}

$pageTitle = 'Categories';
$breadcrumbs = [['Dashboard', 'index.php'], ['Inventory', 'inventory-dashboard.php'], ['Categories']];
include 'inventory-header.php';

$ph = [
    'title'    => 'Product Categories',
    'icon'     => 'fa-folder-tree',
    'subtitle' => 'Categories group products for the till tabs and reports. '
                . 'Each belongs to a department.',
    'actions'  => '<button type="button" class="ui-btn ui-btn-primary" data-bs-toggle="modal" data-bs-target="#categoryModal" onclick="newCategory()">'
                . '<i class="fas fa-plus"></i>Add category</button>',
];
include 'partials/page-header.php';
?>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-4">
        <div class="inv-stat-card bg-grad-teal">
            <div class="d-flex justify-content-between align-items-start">
                <div><div class="label">Categories</div><div class="value"><?php echo number_format($totals['all']); ?></div></div>
                <i class="fas fa-folder-tree icon"></i>
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
                <div><div class="label">Holding no products</div><div class="value"><?php echo number_format($totals['unused']); ?></div></div>
                <i class="fas fa-folder-open icon"></i>
            </div>
        </div>
    </div>
</div>

<form method="get" class="ui-toolbar" role="search">
    <?php if ($deptFilter !== ''): ?><input type="hidden" name="dept" value="<?php echo htmlspecialchars($deptFilter); ?>"><?php endif; ?>
    <div class="ui-search <?php echo $search !== '' ? 'has-value' : ''; ?>">
        <i class="fas fa-magnifying-glass"></i>
        <input type="search" name="q" value="<?php echo htmlspecialchars($search); ?>"
               placeholder="Search categories…" aria-label="Search categories">
        <button type="button" class="ui-search-clear" aria-label="Clear search"><i class="fas fa-xmark"></i></button>
    </div>
    <button class="ui-btn ui-btn-secondary"><i class="fas fa-magnifying-glass"></i>Search</button>

    <span style="width:1px;height:20px;background:var(--color-border);margin:0 4px;"></span>

    <a href="inventory-categories.php<?php echo $search !== '' ? '?q=' . urlencode($search) : ''; ?>"
       class="ui-chip <?php echo $deptFilter === '' ? 'active' : ''; ?>">All departments</a>
    <?php foreach ($departments as $key => $d): ?>
    <a href="?dept=<?php echo urlencode($key); ?><?php echo $search !== '' ? '&q=' . urlencode($search) : ''; ?>"
       class="ui-chip <?php echo $deptFilter === $key ? 'active' : ''; ?>">
        <i class="fas <?php echo htmlspecialchars($d['icon']); ?>"></i><?php echo htmlspecialchars($d['label']); ?>
    </a>
    <?php endforeach; ?>

    <span class="ui-toolbar-spacer"></span>
    <span class="ui-result-count"><strong><?php echo count($categories); ?></strong> shown</span>
</form>

<div class="inv-card p-0">
    <div class="ui-table-wrap">
        <table class="inv-table">
            <thead>
                <tr>
                    <th>Category</th>
                    <th>Department</th>
                    <th class="ui-col-optional">Description</th>
                    <th class="text-end">Products</th>
                    <th class="text-end ui-col-secondary">Stock value</th>
                    <th>Status</th>
                    <th style="width:96px;"><span class="visually-hidden">Actions</span></th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$categories): ?>
                <tr><td colspan="7">
                    <?php
                    $es = ($search !== '' || $deptFilter !== '')
                        ? ['icon' => 'fa-magnifying-glass', 'title' => 'No categories match',
                           'msg'  => 'Try a different search or department.',
                           'action' => '<a class="ui-btn ui-btn-secondary" href="inventory-categories.php">Clear filters</a>']
                        : ['icon' => 'fa-folder-open', 'title' => 'No categories yet',
                           'msg'  => 'Add one to start grouping products.',
                           'action' => '<button type="button" class="ui-btn ui-btn-primary" data-bs-toggle="modal" data-bs-target="#categoryModal" onclick="newCategory()"><i class="fas fa-plus"></i>Add category</button>'];
                    include 'partials/empty-state.php';
                    ?>
                </td></tr>
            <?php endif; ?>

            <?php foreach ($categories as $c):
                $dept = $c['department'] ?: 'general';
                $count = (int)$c['item_count'];
                $dMeta = $departments[$dept] ?? ['icon' => 'fa-boxes-stacked', 'label' => catalogDepartmentLabel($dept)];
            ?>
                <tr>
                    <td style="font-weight:500;"><?php echo htmlspecialchars($c['name']); ?></td>
                    <td>
                        <span class="ui-badge ui-badge-neutral">
                            <i class="fas <?php echo htmlspecialchars($dMeta['icon']); ?>"></i><?php echo htmlspecialchars($dMeta['label']); ?>
                        </span>
                    </td>
                    <td class="ui-col-optional ui-muted"><?php echo htmlspecialchars($c['description'] ?? '—'); ?></td>
                    <td class="text-end ui-num"><?php echo number_format($count); ?></td>
                    <td class="text-end ui-col-secondary ui-money">Tsh <?php echo number_format((float)$c['stock_value']); ?></td>
                    <td>
                        <?php if ((int)$c['is_active'] === 1): ?>
                            <span class="ui-badge ui-badge-success"><span class="ui-dot ui-dot-success"></span>Active</span>
                        <?php else: ?>
                            <span class="ui-badge ui-badge-neutral">Inactive</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="d-flex gap-1 justify-content-end">
                            <button type="button" class="ui-btn ui-btn-secondary ui-btn-sm ui-btn-icon"
                                    title="Edit <?php echo htmlspecialchars($c['name'], ENT_QUOTES); ?>"
                                    aria-label="Edit <?php echo htmlspecialchars($c['name'], ENT_QUOTES); ?>"
                                    data-ui-modal="#categoryModal"
                                    data-title="Edit category"
                                    data-field-id="<?php echo (int)$c['id']; ?>"
                                    data-field-name="<?php echo htmlspecialchars($c['name'], ENT_QUOTES); ?>"
                                    data-field-description="<?php echo htmlspecialchars($c['description'] ?? '', ENT_QUOTES); ?>"
                                    data-field-department="<?php echo htmlspecialchars($dept); ?>"
                                    data-field-is-active="<?php echo (int)$c['is_active'] === 1 ? '1' : '0'; ?>">
                                <i class="fas fa-pen"></i>
                            </button>
                            <form method="post" onsubmit="return confirm(<?php
                                echo $count > 0
                                    ? "'\"" . htmlspecialchars($c['name'], ENT_QUOTES) . "\" is used by " . $count . " product(s). It will be deactivated rather than deleted, so those products keep their category. Continue?'"
                                    : "'Delete \"" . htmlspecialchars($c['name'], ENT_QUOTES) . "\"? Nothing is using it.'";
                            ?>);">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo (int)$c['id']; ?>">
                                <button class="ui-btn ui-btn-danger ui-btn-sm ui-btn-icon"
                                        title="<?php echo $count > 0 ? 'Deactivate' : 'Delete'; ?>"
                                        aria-label="<?php echo $count > 0 ? 'Deactivate' : 'Delete'; ?> <?php echo htmlspecialchars($c['name'], ENT_QUOTES); ?>">
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

<!-- ============ Add / edit category ============ -->
<div class="modal fade" id="categoryModal" tabindex="-1" aria-labelledby="categoryModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" data-ui-loading>
<?php echo csrfField(); ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="0">
                <div class="modal-header">
                    <h5 class="modal-title" id="categoryModalTitle">
                        <i class="fas fa-folder-tree me-2" style="color:var(--color-primary);"></i>
                        <span data-ui-modal-title>Add category</span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label" for="cat_name">Name<span class="ui-required">*</span></label>
                        <input type="text" class="form-control" id="cat_name" name="name" required maxlength="100"
                               placeholder="e.g. Frozen Foods" autocomplete="off">
                        <div class="form-text">Must be unique.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="cat_department">Department<span class="ui-required">*</span></label>
                        <select class="form-select" id="cat_department" name="department" required>
                            <?php foreach ($departments as $key => $d):
                                $deptOn = catalogDepartmentEnabled($conn, $key); ?>
                            <option value="<?php echo htmlspecialchars($key); ?>">
                                <?php echo htmlspecialchars($d['label']); ?><?php echo $deptOn ? '' : ' (disabled)'; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Decides which till tab the category appears under.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="cat_description">Description</label>
                        <input type="text" class="form-control" id="cat_description" name="description" maxlength="255"
                               placeholder="Optional note for staff">
                    </div>
                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox" name="is_active" id="cat_active" checked>
                        <label class="form-check-label" for="cat_active">Active — can be assigned to products</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="ui-btn ui-btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="ui-btn ui-btn-primary"><i class="fas fa-check"></i>Save category</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$pageScript = <<<'HTML'
<script>
// "Add" reuses the same modal, so it has to be reset - otherwise it
// would still hold whichever category was edited last.
function newCategory() {
    const modal = document.getElementById('categoryModal');
    modal.querySelector('[data-ui-modal-title]').textContent = 'Add category';
    modal.querySelector('[name="id"]').value = '0';
    modal.querySelector('[name="name"]').value = '';
    modal.querySelector('[name="description"]').value = '';
    modal.querySelector('[name="department"]').selectedIndex = 0;
    modal.querySelector('[name="is_active"]').checked = true;
}
</script>
HTML;
include 'inventory-footer.php';
?>
