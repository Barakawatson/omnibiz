<?php
// ============================================================
// Inventory - Units of Measure
// ------------------------------------------------------------
// The unit a product is counted in: pieces, packs, reams, cartons,
// kilograms. Shown beside every stock figure and printed on
// receipts, so the abbreviation matters as much as the name.
//
// Like categories, units existed only as seed data. A unit in use
// is deactivated rather than deleted - removing it would blank the
// unit on every product counted in it and on past stock reports.
// ============================================================
require_once '../includes/auth.php';
requireModule('inventory');
// Reject any POST that does not carry this session's CSRF token.
// Placed before every handler on this page, and a no-op on GET.
csrfRequire();
$current_page = basename($_SERVER['PHP_SELF']);
include '../includes/db.php';
require_once '../includes/inventory_functions.php';
inventoryBoot($conn);

$userId = (int)($_SESSION['id'] ?? 0);

function retailFlashLocal(string $type, string $msg): void {
    $_SESSION['inv_flash'] = ['type' => $type, 'msg' => $msg];
}

/** How many live products are counted in this unit. */
function unitUsage(mysqli $conn, int $id): int {
    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM inv_items WHERE unit_id = ? AND deleted_at IS NULL");
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
        $id      = (int)($_POST['id'] ?? 0);
        $name    = trim($_POST['name'] ?? '');
        $abbr    = trim($_POST['abbreviation'] ?? '');
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if ($name === '' || $abbr === '') {
            retailFlashLocal('danger', 'A unit needs both a name and an abbreviation.');
        } elseif (mb_strlen($name) > 50) {
            retailFlashLocal('danger', 'Unit name is too long (50 characters maximum).');
        } elseif (mb_strlen($abbr) > 15) {
            retailFlashLocal('danger', 'Abbreviation is too long (15 characters maximum).');
        } else {
            if ($id > 0) {
                $stmt = $conn->prepare("UPDATE inv_units SET name = ?, abbreviation = ?, is_active = ?
                                        WHERE id = ? AND deleted_at IS NULL");
                $stmt->bind_param('ssii', $name, $abbr, $isActive, $id);
                $ok = $stmt->execute();
                $dup = ($conn->errno === 1062);
                $stmt->close();

                retailFlashLocal($ok ? 'success' : 'danger',
                    $ok ? 'Unit updated.'
                        : ($dup ? 'Another unit is already called "' . $name . '".' : 'Could not update the unit.'));
                if ($ok) { invAudit($conn, $userId, 'unit_update', 'unit', $id, $name . ' (' . $abbr . ')'); }
            } else {
                $stmt = $conn->prepare("INSERT INTO inv_units (name, abbreviation, is_active) VALUES (?, ?, ?)");
                $stmt->bind_param('ssi', $name, $abbr, $isActive);
                $ok = $stmt->execute();
                $newId = (int)$conn->insert_id;
                $dup = ($conn->errno === 1062);
                $stmt->close();

                retailFlashLocal($ok ? 'success' : 'danger',
                    $ok ? 'Unit "' . $name . '" added.'
                        : ($dup ? 'A unit called "' . $name . '" already exists.' : 'Could not add the unit.'));
                if ($ok) { invAudit($conn, $userId, 'unit_create', 'unit', $newId, $name . ' (' . $abbr . ')'); }
            }
        }
        header('Location: inventory-units.php'); exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);

        $stmt = $conn->prepare("SELECT name FROM inv_units WHERE id = ? AND deleted_at IS NULL");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $unit = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$unit) {
            retailFlashLocal('danger', 'That unit does not exist.');
        } else {
            $inUse = unitUsage($conn, $id);
            if ($inUse > 0) {
                $stmt = $conn->prepare("UPDATE inv_units SET is_active = 0 WHERE id = ?");
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $stmt->close();
                retailFlashLocal('success', '"' . $unit['name'] . '" is used by ' . $inUse
                    . ' product(s), so it was deactivated rather than deleted - those products keep their unit.');
            } else {
                $stmt = $conn->prepare("UPDATE inv_units SET deleted_at = NOW(), is_active = 0 WHERE id = ?");
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $stmt->close();
                invAudit($conn, $userId, 'unit_delete', 'unit', $id, $unit['name']);
                retailFlashLocal('success', 'Unit "' . $unit['name'] . '" deleted.');
            }
        }
        header('Location: inventory-units.php'); exit;
    }
}

// ---------- Data ----------
$search = trim($_GET['q'] ?? '');

$where  = "u.deleted_at IS NULL";
$params = [];
$types  = '';
if ($search !== '') {
    $where .= " AND (u.name LIKE ? OR u.abbreviation LIKE ?)";
    $like = '%' . $search . '%';
    array_push($params, $like, $like);
    $types .= 'ss';
}

$sql = "SELECT u.*,
               (SELECT COUNT(*) FROM inv_items i WHERE i.unit_id = u.id AND i.deleted_at IS NULL) AS item_count
        FROM inv_units u
        WHERE $where
        ORDER BY u.name";
$stmt = $conn->prepare($sql);
if ($types !== '') { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$units = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$totals = ['all' => 0, 'active' => 0, 'unused' => 0];
$uRes = $conn->query("SELECT COUNT(*) AS all_u,
                             SUM(is_active = 1) AS active_u,
                             SUM((SELECT COUNT(*) FROM inv_items i WHERE i.unit_id = u.id AND i.deleted_at IS NULL) = 0) AS unused_u
                      FROM inv_units u WHERE u.deleted_at IS NULL");
if ($uRes && ($r = $uRes->fetch_assoc())) {
    $totals = ['all' => (int)$r['all_u'], 'active' => (int)$r['active_u'], 'unused' => (int)$r['unused_u']];
}

$pageTitle = 'Units of Measure';
$breadcrumbs = [['Dashboard', 'index.php'], ['Inventory', 'inventory-dashboard.php'], ['Units']];
include 'inventory-header.php';

$ph = [
    'title'    => 'Units of Measure',
    'icon'     => 'fa-ruler',
    'subtitle' => 'How each product is counted — pieces, packs, reams, cartons, weight. '
                . 'The abbreviation is what appears beside stock figures.',
    'actions'  => '<button type="button" class="ui-btn ui-btn-primary" data-bs-toggle="modal" data-bs-target="#unitModal" onclick="newUnit()">'
                . '<i class="fas fa-plus"></i>Add unit</button>',
];
include 'partials/page-header.php';
?>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-4">
        <div class="inv-stat-card bg-grad-teal">
            <div class="d-flex justify-content-between align-items-start">
                <div><div class="label">Units</div><div class="value"><?php echo number_format($totals['all']); ?></div></div>
                <i class="fas fa-ruler icon"></i>
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
                <div><div class="label">Unused</div><div class="value"><?php echo number_format($totals['unused']); ?></div></div>
                <i class="fas fa-ruler-horizontal icon"></i>
            </div>
        </div>
    </div>
</div>

<form method="get" class="ui-toolbar" role="search">
    <div class="ui-search <?php echo $search !== '' ? 'has-value' : ''; ?>">
        <i class="fas fa-magnifying-glass"></i>
        <input type="search" name="q" value="<?php echo htmlspecialchars($search); ?>"
               placeholder="Search units…" aria-label="Search units">
        <button type="button" class="ui-search-clear" aria-label="Clear search"><i class="fas fa-xmark"></i></button>
    </div>
    <button class="ui-btn ui-btn-secondary"><i class="fas fa-magnifying-glass"></i>Search</button>
    <?php if ($search !== ''): ?>
        <a href="inventory-units.php" class="ui-btn ui-btn-ghost"><i class="fas fa-xmark"></i>Clear</a>
    <?php endif; ?>
    <span class="ui-toolbar-spacer"></span>
    <span class="ui-result-count"><strong><?php echo count($units); ?></strong> shown</span>
</form>

<div class="inv-card p-0">
    <div class="ui-table-wrap">
        <table class="inv-table">
            <thead>
                <tr>
                    <th>Unit</th>
                    <th>Abbreviation</th>
                    <th class="text-end">Products</th>
                    <th>Status</th>
                    <th style="width:96px;"><span class="visually-hidden">Actions</span></th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$units): ?>
                <tr><td colspan="5">
                    <?php
                    $es = $search !== ''
                        ? ['icon' => 'fa-magnifying-glass', 'title' => 'No units match',
                           'msg'  => 'Try a different name or abbreviation.',
                           'action' => '<a class="ui-btn ui-btn-secondary" href="inventory-units.php">Clear search</a>']
                        : ['icon' => 'fa-ruler', 'title' => 'No units yet',
                           'msg'  => 'Add one so products can be counted.',
                           'action' => '<button type="button" class="ui-btn ui-btn-primary" data-bs-toggle="modal" data-bs-target="#unitModal" onclick="newUnit()"><i class="fas fa-plus"></i>Add unit</button>'];
                    include 'partials/empty-state.php';
                    ?>
                </td></tr>
            <?php endif; ?>

            <?php foreach ($units as $u): $count = (int)$u['item_count']; ?>
                <tr>
                    <td style="font-weight:500;"><?php echo htmlspecialchars($u['name']); ?></td>
                    <td><span class="ui-code"><?php echo htmlspecialchars($u['abbreviation']); ?></span></td>
                    <td class="text-end ui-num"><?php echo number_format($count); ?></td>
                    <td>
                        <?php if ((int)$u['is_active'] === 1): ?>
                            <span class="ui-badge ui-badge-success"><span class="ui-dot ui-dot-success"></span>Active</span>
                        <?php else: ?>
                            <span class="ui-badge ui-badge-neutral">Inactive</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="d-flex gap-1 justify-content-end">
                            <button type="button" class="ui-btn ui-btn-secondary ui-btn-sm ui-btn-icon"
                                    title="Edit <?php echo htmlspecialchars($u['name'], ENT_QUOTES); ?>"
                                    aria-label="Edit <?php echo htmlspecialchars($u['name'], ENT_QUOTES); ?>"
                                    data-ui-modal="#unitModal"
                                    data-title="Edit unit"
                                    data-field-id="<?php echo (int)$u['id']; ?>"
                                    data-field-name="<?php echo htmlspecialchars($u['name'], ENT_QUOTES); ?>"
                                    data-field-abbreviation="<?php echo htmlspecialchars($u['abbreviation'], ENT_QUOTES); ?>"
                                    data-field-is-active="<?php echo (int)$u['is_active'] === 1 ? '1' : '0'; ?>">
                                <i class="fas fa-pen"></i>
                            </button>
                            <form method="post" onsubmit="return confirm(<?php
                                echo $count > 0
                                    ? "'\"" . htmlspecialchars($u['name'], ENT_QUOTES) . "\" is used by " . $count . " product(s). It will be deactivated rather than deleted, so those products keep their unit. Continue?'"
                                    : "'Delete \"" . htmlspecialchars($u['name'], ENT_QUOTES) . "\"? Nothing is using it.'";
                            ?>);">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo (int)$u['id']; ?>">
                                <button class="ui-btn ui-btn-danger ui-btn-sm ui-btn-icon"
                                        title="<?php echo $count > 0 ? 'Deactivate' : 'Delete'; ?>"
                                        aria-label="<?php echo $count > 0 ? 'Deactivate' : 'Delete'; ?> <?php echo htmlspecialchars($u['name'], ENT_QUOTES); ?>">
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

<!-- ============ Add / edit unit ============ -->
<div class="modal fade" id="unitModal" tabindex="-1" aria-labelledby="unitModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" data-ui-loading>
<?php echo csrfField(); ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="0">
                <div class="modal-header">
                    <h5 class="modal-title" id="unitModalTitle">
                        <i class="fas fa-ruler me-2" style="color:var(--color-primary);"></i>
                        <span data-ui-modal-title>Add unit</span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-7">
                            <label class="form-label" for="unit_name">Name<span class="ui-required">*</span></label>
                            <input type="text" class="form-control" id="unit_name" name="name" required maxlength="50"
                                   placeholder="e.g. Crates" autocomplete="off">
                            <div class="form-text">Must be unique.</div>
                        </div>
                        <div class="col-md-5">
                            <label class="form-label" for="unit_abbr">Abbreviation<span class="ui-required">*</span></label>
                            <input type="text" class="form-control" id="unit_abbr" name="abbreviation" required maxlength="15"
                                   placeholder="e.g. crt" autocomplete="off">
                            <div class="form-text">Shown beside stock.</div>
                        </div>
                        <div class="col-12">
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" name="is_active" id="unit_active" checked>
                                <label class="form-check-label" for="unit_active">Active — can be assigned to products</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="ui-btn ui-btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="ui-btn ui-btn-primary"><i class="fas fa-check"></i>Save unit</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$pageScript = <<<'HTML'
<script>
// The modal is shared between Add and Edit, so Add has to clear it.
function newUnit() {
    const modal = document.getElementById('unitModal');
    modal.querySelector('[data-ui-modal-title]').textContent = 'Add unit';
    modal.querySelector('[name="id"]').value = '0';
    modal.querySelector('[name="name"]').value = '';
    modal.querySelector('[name="abbreviation"]').value = '';
    modal.querySelector('[name="is_active"]').checked = true;
}
</script>
HTML;
include 'inventory-footer.php';
?>
