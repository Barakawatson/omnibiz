<?php
// ============================================================
// Departments - how THIS business organises what it sells.
// ------------------------------------------------------------
// Departments are the shop's own structure, not the application's.
// A supermarket may run Food / Beverages / Household; a hardware
// shop Plumbing / Electrical / Tools; a duka la dawa Medicines /
// Personal Care. The administrator creates, renames, reorders,
// disables and (when nothing uses it) removes them here.
//
// Two rules this screen exists to protect:
//
//  * A department in use is DISABLED, never deleted - the same rule
//    categories, units and suppliers follow. History has to survive.
//
//  * Disabling takes a product range off the till immediately, but
//    touches nothing else: stock, costs, prices and every past sale
//    read exactly as before, and historical reports still show the
//    department by name. Trade that already happened must never
//    change because of a settings toggle.
//
// The storage key behind each department is generated once at
// creation and never changes, so renaming "Medicines" to "Pharmacy
// Stock" cannot orphan a year of sales.
// ============================================================
require_once '../includes/auth.php';
requireModule('departments');
// Reject any POST that does not carry this session's CSRF token.
// Placed before every handler on this page, and a no-op on GET.
csrfRequire();
$current_page = basename($_SERVER['PHP_SELF']);
include '../includes/db.php';
require_once '../includes/pos_functions.php';
require_once '../includes/business_types.php';
posBoot($conn);

$userId = (int)($_SESSION['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $key    = trim((string)($_POST['department'] ?? ''));

    if ($action === 'toggle') {
        $enabled = ($_POST['enabled'] ?? '') === '1';
        [$ok, $msg] = catalogSetDepartmentEnabled($conn, $key, $enabled);
        if ($ok) {
            invAudit($conn, $userId, $enabled ? 'department_enable' : 'department_disable',
                     'department', null, $key);
        }
        retailFlash($ok ? 'success' : 'danger', $msg);

    } elseif ($action === 'save') {
        // One modal serves both: an empty key means "add".
        $name   = (string)($_POST['name'] ?? '');
        $icon   = (string)($_POST['icon'] ?? '');
        $colour = (string)($_POST['colour'] ?? '');

        if ($key === '') {
            [$ok, $msg, $newKey] = catalogCreateDepartment($conn, $name, $icon, $colour);
            if ($ok) { invAudit($conn, $userId, 'department_create', 'department', null, $newKey); }
        } else {
            [$ok, $msg] = catalogUpdateDepartment($conn, $key, $name, $icon, $colour);
            if ($ok) { invAudit($conn, $userId, 'department_update', 'department', null, $key); }
        }
        retailFlash($ok ? 'success' : 'danger', $msg);

    } elseif ($action === 'delete') {
        [$ok, $msg] = catalogDeleteDepartment($conn, $key);
        if ($ok) { invAudit($conn, $userId, 'department_delete', 'department', null, $key); }
        retailFlash($ok ? 'success' : 'danger', $msg);

    } elseif ($action === 'move') {
        // Reorder by swapping with the neighbour, so it works without
        // drag-and-drop - and therefore works on a phone.
        $dir   = ($_POST['dir'] ?? '') === 'up' ? 'up' : 'down';
        $keys  = array_keys(catalogDepartmentsFresh($conn));
        $pos   = array_search($key, $keys, true);
        if ($pos !== false) {
            $swap = $dir === 'up' ? $pos - 1 : $pos + 1;
            if ($swap >= 0 && $swap < count($keys)) {
                [$keys[$pos], $keys[$swap]] = [$keys[$swap], $keys[$pos]];
                catalogReorderDepartments($conn, $keys);
                retailFlash('success', 'Order updated.');
            }
        }

    } elseif ($action === 'apply_template') {
        // Add the departments a business type suggests, on top of
        // whatever already exists. Nothing is renamed or removed.
        $typeKey = (string)($_POST['template'] ?? '');
        $made    = businessApplyTemplate($conn, $typeKey, false, false);
        retailFlash($made['departments'] > 0 ? 'success' : 'info',
            $made['departments'] > 0
                ? $made['departments'] . ' department(s) added. Rename or remove any you do not need.'
                : 'Nothing to add - those departments already exist.');
    }

    header('Location: departments.php'); exit;
}

$departments = catalogDepartments($conn);
$activeCount = count(catalogActiveDepartments($conn));
$templates   = businessTypeCatalogue();
$typeKey     = shopSetting($conn, 'shop_business_type');

$pageTitle = 'Departments';
$breadcrumbs = [['Dashboard', 'index.php'], ['Departments']];
include 'inventory-header.php';

function deptMoney($v) { return 'Tsh ' . number_format((float)$v); }

// A small palette and icon set, so a department can be told apart at a
// glance on the till without anyone typing a hex code.
$palette = ['#42c3cf', '#22c55e', '#f6c23e', '#ec4899', '#8b5cf6', '#ef4444', '#38bdf8', '#f59e0b', '#8b9aa2'];
$icons   = ['fa-boxes-stacked', 'fa-basket-shopping', 'fa-bowl-food', 'fa-bottle-water', 'fa-house',
            'fa-pump-soap', 'fa-pills', 'fa-briefcase-medical', 'fa-pen', 'fa-book', 'fa-paperclip',
            'fa-graduation-cap', 'fa-mobile-screen', 'fa-laptop', 'fa-bolt', 'fa-faucet',
            'fa-screwdriver-wrench', 'fa-trowel-bricks', 'fa-shirt', 'fa-spray-can-sparkles', 'fa-scissors'];
?>
<div class="inv-page-header">
    <div>
        <h1><i class="fas fa-store me-2" style="color:var(--inv-primary);"></i>Departments</h1>
        <div class="subtitle">
            How <?php echo htmlspecialchars(shopName($conn)); ?> organises what it sells.
            <?php if ($typeKey !== ''): ?>
                Business type: <strong><?php echo htmlspecialchars(businessTypeLabel($conn)); ?></strong>.
            <?php endif; ?>
        </div>
    </div>
    <div class="d-flex gap-2">
        <button class="btn btn-inv" data-ui-modal="#departmentModal" data-title="Add department"
                onclick="newDepartment()">
            <i class="fas fa-plus me-1"></i>Add department
        </button>
    </div>
</div>

<div class="alert alert-light" style="border-radius:12px;font-size:.86rem;">
    <i class="fas fa-circle-info" style="color:var(--inv-primary);"></i>
    <!-- .alert is display:flex, so the copy needs a single wrapper or each
         inline element becomes its own column. -->
    <div>
        Disabling a department hides its products from the POS till and from the department tabs, and a
        cashier scanning one of its barcodes is told it is not currently on sale.
        <strong>Nothing else changes</strong> &mdash; stock levels, costs, prices and every past sale stay
        exactly as they are, and historical reports still show the department by name. Re-enabling it puts
        everything straight back on the till.
    </div>
</div>

<div class="row g-3">
<?php $i = 0; foreach ($departments as $key => $meta):
    $enabled = !empty($meta['active']);
    $stats   = catalogDepartmentStats($conn, $key);
    $isLastActive = $enabled && $activeCount <= 1;
    $refs    = catalogDepartmentReferences($conn, $key);
    $inUse   = array_sum($refs) > 0;
    $i++;
?>
    <div class="col-lg-4 col-md-6">
        <div class="inv-card p-4 h-100" style="<?php echo $enabled ? '' : 'opacity:.72;'; ?>border-left:5px solid <?php echo htmlspecialchars($meta['colour']); ?>;">
            <div class="d-flex justify-content-between align-items-start mb-3">
                <div class="d-flex align-items-center gap-2">
                    <div style="width:44px;height:44px;border-radius:12px;background:<?php echo htmlspecialchars($meta['colour']); ?>;color:#fff;display:flex;align-items:center;justify-content:center;font-size:1.15rem;">
                        <i class="fas <?php echo htmlspecialchars($meta['icon']); ?>"></i>
                    </div>
                    <div>
                        <div class="fw-bold" style="font-size:1.05rem;"><?php echo htmlspecialchars($meta['label']); ?></div>
                        <?php if ($enabled): ?>
                            <span class="inv-badge bg-success text-white"><i class="fas fa-circle-check me-1"></i>Trading</span>
                        <?php else: ?>
                            <span class="inv-badge bg-secondary text-white"><i class="fas fa-circle-pause me-1"></i>Disabled</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="d-flex flex-column gap-1">
                    <form method="post" class="d-inline">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="move">
                        <input type="hidden" name="dir" value="up">
                        <input type="hidden" name="department" value="<?php echo htmlspecialchars($key); ?>">
                        <button class="btn btn-sm btn-outline-secondary" style="border-radius:8px;padding:.1rem .45rem;"
                                title="Move up" <?php echo $i === 1 ? 'disabled' : ''; ?>><i class="fas fa-chevron-up"></i></button>
                    </form>
                    <form method="post" class="d-inline">
                        <?php echo csrfField(); ?>
                        <input type="hidden" name="action" value="move">
                        <input type="hidden" name="dir" value="down">
                        <input type="hidden" name="department" value="<?php echo htmlspecialchars($key); ?>">
                        <button class="btn btn-sm btn-outline-secondary" style="border-radius:8px;padding:.1rem .45rem;"
                                title="Move down" <?php echo $i === count($departments) ? 'disabled' : ''; ?>><i class="fas fa-chevron-down"></i></button>
                    </form>
                </div>
            </div>

            <table class="table table-sm mb-3" style="font-size:.85rem;">
                <tr>
                    <td class="text-muted">Active items</td>
                    <td class="text-end fw-bold"><?php echo number_format($stats['items']); ?></td>
                </tr>
                <tr>
                    <td class="text-muted">On the till</td>
                    <td class="text-end fw-bold"><?php echo number_format($stats['on_till']); ?></td>
                </tr>
                <tr>
                    <td class="text-muted">Stock value</td>
                    <td class="text-end"><?php echo deptMoney($stats['stock_value']); ?></td>
                </tr>
                <tr>
                    <td class="text-muted">Sales recorded</td>
                    <td class="text-end"><?php echo number_format($stats['sales_count']); ?></td>
                </tr>
            </table>

            <?php if ($enabled && $stats['on_till'] > 0): ?>
            <div class="text-muted mb-2" style="font-size:.78rem;">
                <i class="fas fa-triangle-exclamation me-1 text-warning"></i>
                Disabling removes <strong><?php echo number_format($stats['on_till']); ?></strong>
                product(s) from the till.
            </div>
            <?php endif; ?>

            <div class="d-flex gap-2">
                <button class="btn btn-sm btn-outline-secondary flex-fill" style="border-radius:8px;"
                        data-ui-modal="#departmentModal"
                        data-title="Edit department"
                        data-field-department="<?php echo htmlspecialchars($key); ?>"
                        data-field-name="<?php echo htmlspecialchars($meta['label']); ?>"
                        data-field-icon="<?php echo htmlspecialchars($meta['icon']); ?>"
                        data-field-colour="<?php echo htmlspecialchars($meta['colour']); ?>">
                    <i class="fas fa-pen me-1"></i>Edit
                </button>

                <form method="post" class="flex-fill" onsubmit="return confirm(<?php
                    echo $enabled
                        ? "'Disable " . htmlspecialchars($meta['label'], ENT_QUOTES) . "? Its "
                          . (int)$stats['on_till'] . " product(s) come off the till immediately. Stock and history are not affected.'"
                        : "'Enable " . htmlspecialchars($meta['label'], ENT_QUOTES) . "? Its products go back on the till.'";
                ?>
);">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="department" value="<?php echo htmlspecialchars($key); ?>">
                    <input type="hidden" name="enabled" value="<?php echo $enabled ? '0' : '1'; ?>">
                    <button class="btn btn-sm w-100 <?php echo $enabled ? 'btn-outline-danger' : 'btn-inv'; ?>"
                            <?php echo $isLastActive ? 'disabled' : ''; ?>>
                        <i class="fas <?php echo $enabled ? 'fa-circle-pause' : 'fa-circle-play'; ?> me-1"></i>
                        <?php echo $enabled ? 'Disable' : 'Enable'; ?>
                    </button>
                </form>
            </div>

            <?php if (!$inUse && count($departments) > 1): ?>
            <form method="post" class="mt-2" onsubmit="return confirm('Remove <?php echo htmlspecialchars($meta['label'], ENT_QUOTES); ?>? Nothing uses it, so it can be deleted outright.');">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="department" value="<?php echo htmlspecialchars($key); ?>">
                <button class="btn btn-sm btn-link text-danger w-100 p-0" style="font-size:.78rem;">
                    <i class="fas fa-trash me-1"></i>Remove (not used by anything)
                </button>
            </form>
            <?php endif; ?>

            <?php if ($isLastActive): ?>
            <div class="text-muted text-center mt-2" style="font-size:.75rem;">
                This is the only department still trading, so it cannot be disabled.
            </div>
            <?php endif; ?>
        </div>
    </div>
<?php endforeach; ?>
</div>

<?php
// Items stranded in a department that is switched off - they cannot be
// sold until either the department comes back or they are moved.
$stranded = [];
$disabledKeys = array_diff(array_keys($departments), array_keys(catalogActiveDepartments($conn)));
if ($disabledKeys) {
    $in = "'" . implode("','", array_map([$conn, 'real_escape_string'], $disabledKeys)) . "'";
    $res = @$conn->query(
        "SELECT i.id, i.name, i.department, i.current_stock, i.average_cost, u.abbreviation AS unit
         FROM inv_items i
         JOIN retail_product_details d ON d.item_id = i.id
         LEFT JOIN inv_units u ON u.id = i.unit_id
         WHERE i.deleted_at IS NULL AND i.status = 'active'
           AND i.department IN ($in)
           AND d.usage_type IN ('sale','both') AND d.is_enabled = 1
         ORDER BY (i.current_stock * i.average_cost) DESC LIMIT 50");
    if ($res instanceof mysqli_result) { $stranded = $res->fetch_all(MYSQLI_ASSOC); }
}
?>

<?php if ($stranded): ?>
<div class="inv-card p-0 mt-4">
    <div class="p-3 border-bottom">
        <h6 class="mb-0 fw-bold"><i class="fas fa-box-open me-2" style="color:var(--inv-primary);"></i>Products Currently Off Sale</h6>
        <div class="text-muted" style="font-size:.78rem;">
            These are marked for sale but sit in a disabled department, so the till will not ring them up.
            Re-enable the department, or move them to a trading one from
            <a href="retail-products.php">Products &amp; Prices</a>.
        </div>
    </div>
    <div class="table-responsive">
        <table class="inv-table">
            <thead><tr><th>Product</th><th>Department</th><th class="text-end">Stock</th><th class="text-end">Stock Value</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($stranded as $s): ?>
                <tr>
                    <td><?php echo htmlspecialchars($s['name']); ?></td>
                    <td>
                        <span class="inv-badge bg-secondary text-white">
                            <?php echo htmlspecialchars(catalogDepartmentLabel($s['department'])); ?>
                        </span>
                    </td>
                    <td class="text-end"><?php echo invQty($s['current_stock']); ?> <?php echo htmlspecialchars($s['unit'] ?? ''); ?></td>
                    <td class="text-end"><?php echo deptMoney((float)$s['current_stock'] * (float)$s['average_cost']); ?></td>
                    <td class="text-end">
                        <a href="retail-products.php?filter=all&dept=<?php echo urlencode($s['department']); ?>"
                           class="btn btn-sm btn-outline-secondary" style="border-radius:8px;">Move</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Suggested structures. Additive only: nothing existing is touched. -->
<div class="inv-card p-4 mt-4">
    <h6 class="fw-bold mb-1"><i class="fas fa-wand-magic-sparkles me-2" style="color:var(--inv-primary);"></i>Start from a suggested structure</h6>
    <div class="text-muted mb-3" style="font-size:.82rem;">
        Adds the departments a trade usually has. Everything it creates is yours to rename, reorder,
        disable or remove &mdash; nothing you already have is changed.
    </div>
    <form method="post" class="row g-2 align-items-end">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="apply_template">
        <div class="col-md-6">
            <label class="form-label" style="font-size:.82rem;">Business type</label>
            <select name="template" class="form-select">
                <?php foreach ($templates as $tKey => $t): ?>
                    <?php if (!$t['departments']) { continue; } ?>
                    <option value="<?php echo htmlspecialchars($tKey); ?>" <?php echo $tKey === $typeKey ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($t['label']); ?>
                        (<?php echo count($t['departments']); ?> departments)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3">
            <button class="btn btn-outline-secondary w-100" style="border-radius:10px;">
                <i class="fas fa-plus me-1"></i>Add these
            </button>
        </div>
    </form>
</div>

<!-- ============ Add / edit department ============ -->
<!-- One modal for both, filled by MX.initModals() from the trigger's
     data-field-* attributes - the same contract every other list page
     on the admin panel uses. -->
<div class="modal fade" id="departmentModal" tabindex="-1" aria-labelledby="departmentModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" data-ui-loading>
<?php echo csrfField(); ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="department" value="">
                <div class="modal-header">
                    <h5 class="modal-title" id="departmentModalTitle">
                        <i class="fas fa-store me-2" style="color:var(--color-primary);"></i>
                        <span data-ui-modal-title>Add department</span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label" for="dept_name">Name<span class="ui-required">*</span></label>
                        <input type="text" class="form-control" id="dept_name" name="name" required maxlength="60"
                               placeholder="e.g. Plumbing, Medicines, Beverages" autocomplete="off">
                        <div class="form-text">
                            What this part of the shop is called. Renaming later is safe &mdash; products,
                            sales and reports follow the department itself, not its name.
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="dept_icon">Icon</label>
                        <select class="form-select" id="dept_icon" name="icon">
            <?php foreach ($icons as $ic): ?>
              <option value="<?php echo $ic; ?>"><?php echo str_replace('fa-', '', $ic); ?></option>
            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Shown on the till tab and on this screen.</div>
                    </div>
                    <div class="mb-1">
                        <label class="form-label" for="dept_colour">Colour</label>
                        <select class="form-select" id="dept_colour" name="colour">
            <?php foreach ($palette as $c): ?>
              <option value="<?php echo $c; ?>"><?php echo $c; ?></option>
            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button class="btn btn-inv"><i class="fas fa-check me-1"></i>Save department</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$pageScript = <<<'HTML'
<script>
// "Add department" reuses the edit modal, so it has to be reset -
// otherwise it would still hold whichever department was edited last.
function newDepartment() {
    const modal = document.getElementById('departmentModal');
    modal.querySelector('[data-ui-modal-title]').textContent = 'Add department';
    modal.querySelector('[name="department"]').value = '';
    modal.querySelector('[name="name"]').value = '';
    modal.querySelector('[name="icon"]').selectedIndex = 0;
    modal.querySelector('[name="colour"]').selectedIndex = 0;
}
</script>
HTML;
include 'inventory-footer.php';
?>
