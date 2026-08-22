<?php
// ============================================================
// Inventory - Suppliers
// ------------------------------------------------------------
// Supplier profiles and the purchasing relationship. Every
// purchase order and most stock receipts point back here, so a
// supplier that has traded with you is never destroyed: deleting
// one would leave its purchase orders and items pointing at
// nothing. Those are deactivated instead, exactly as categories
// and units behave.
//
// "Outstanding" is what is still owed on received goods, read
// from the same purchasing figures the PO screen uses - it is
// never typed in.
// ============================================================
require_once '../includes/auth.php';
requireModule('inventory');
// Reject any POST that does not carry this session's CSRF token.
// Placed before every handler on this page, and a no-op on GET.
csrfRequire();
$current_page = basename($_SERVER['PHP_SELF']);
include '../includes/db.php';
require_once '../includes/inventory_functions.php';
require_once '../includes/purchasing_functions.php';
purchasingBoot($conn);

$userId = (int)($_SESSION['id'] ?? 0);

function supFlash(string $type, string $msg): void {
    $_SESSION['inv_flash'] = ['type' => $type, 'msg' => $msg];
}

/** Items and purchase orders attached to a supplier. */
function supplierUsage(mysqli $conn, int $id): array {
    $out = ['items' => 0, 'pos' => 0];

    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM inv_items WHERE supplier_id = ? AND deleted_at IS NULL");
    if ($stmt) {
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $out['items'] = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
        $stmt->close();
    }

    $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM inv_purchase_orders WHERE supplier_id = ? AND deleted_at IS NULL");
    if ($stmt) {
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $out['pos'] = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
        $stmt->close();
    }

    return $out;
}

// ---------- POST ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $id       = (int)($_POST['id'] ?? 0);
        $name     = trim($_POST['name'] ?? '');
        $contact  = trim($_POST['contact_person'] ?? '');
        $phone    = trim($_POST['phone'] ?? '');
        $email    = trim($_POST['email'] ?? '');
        $address  = trim($_POST['address'] ?? '');
        $opening  = (float)($_POST['opening_balance'] ?? 0);
        $notes    = trim($_POST['notes'] ?? '');
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if ($name === '') {
            supFlash('danger', 'Enter a supplier name.');
        } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            supFlash('danger', 'That email address is not valid.');
        } else {
            if ($id > 0) {
                $stmt = $conn->prepare("UPDATE inv_suppliers
                    SET name = ?, contact_person = ?, phone = ?, email = ?, address = ?,
                        opening_balance = ?, notes = ?, is_active = ?
                    WHERE id = ? AND deleted_at IS NULL");
                $stmt->bind_param('sssssdsii', $name, $contact, $phone, $email, $address, $opening, $notes, $isActive, $id);
                $ok = $stmt->execute();
                $stmt->close();
                if ($ok) { invAudit($conn, $userId, 'supplier_update', 'supplier', $id, $name); }
                supFlash($ok ? 'success' : 'danger', $ok ? 'Supplier updated.' : 'Could not update the supplier.');
            } else {
                $stmt = $conn->prepare("INSERT INTO inv_suppliers
                    (name, contact_person, phone, email, address, opening_balance, notes, is_active)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->bind_param('sssssdsi', $name, $contact, $phone, $email, $address, $opening, $notes, $isActive);
                $ok = $stmt->execute();
                $newId = (int)$conn->insert_id;
                $stmt->close();
                if ($ok) { invAudit($conn, $userId, 'supplier_create', 'supplier', $newId, $name); }
                supFlash($ok ? 'success' : 'danger',
                    $ok ? 'Supplier "' . $name . '" added.' : 'Could not add the supplier.');
            }
        }
        header('Location: inventory-suppliers.php'); exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);

        $stmt = $conn->prepare("SELECT name FROM inv_suppliers WHERE id = ? AND deleted_at IS NULL");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $sup = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$sup) {
            supFlash('danger', 'That supplier does not exist.');
        } else {
            $use = supplierUsage($conn, $id);
            if ($use['items'] > 0 || $use['pos'] > 0) {
                // Purchase orders and item records point at this supplier.
                // Removing it would strand that history, so it is retired
                // instead - it stops appearing on new orders and keeps
                // everything already recorded intact.
                $stmt = $conn->prepare("UPDATE inv_suppliers SET is_active = 0 WHERE id = ?");
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $stmt->close();
                invAudit($conn, $userId, 'supplier_deactivate', 'supplier', $id, $sup['name']);

                $parts = [];
                if ($use['pos'] > 0)   { $parts[] = $use['pos'] . ' purchase order(s)'; }
                if ($use['items'] > 0) { $parts[] = $use['items'] . ' product(s)'; }
                supFlash('success', '"' . $sup['name'] . '" is linked to ' . implode(' and ', $parts)
                    . ', so it was deactivated rather than deleted - that history is kept.');
            } else {
                $stmt = $conn->prepare("UPDATE inv_suppliers SET deleted_at = NOW(), is_active = 0 WHERE id = ?");
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $stmt->close();
                invAudit($conn, $userId, 'supplier_delete', 'supplier', $id, $sup['name']);
                supFlash('success', 'Supplier "' . $sup['name'] . '" deleted.');
            }
        }
        header('Location: inventory-suppliers.php'); exit;
    }
}

// ---------- Listing ----------
$search = trim($_GET['q'] ?? $_GET['search'] ?? '');   // ?search= kept for old links
$status = $_GET['status'] ?? '';

$where  = "s.deleted_at IS NULL";
$params = [];
$types  = '';

if ($search !== '') {
    $where .= " AND (s.name LIKE ? OR s.contact_person LIKE ? OR s.phone LIKE ? OR s.email LIKE ?)";
    $like = '%' . $search . '%';
    array_push($params, $like, $like, $like, $like);
    $types .= 'ssss';
}
if ($status === 'active')   { $where .= " AND s.is_active = 1"; }
if ($status === 'inactive') { $where .= " AND s.is_active = 0"; }

$sql = "SELECT s.*,
               (SELECT COUNT(*) FROM inv_items i WHERE i.supplier_id = s.id AND i.deleted_at IS NULL) AS item_count,
               (SELECT COUNT(*) FROM inv_purchase_orders po WHERE po.supplier_id = s.id AND po.deleted_at IS NULL) AS po_count,
               (SELECT COUNT(*) FROM inv_purchase_orders po WHERE po.supplier_id = s.id AND po.deleted_at IS NULL
                  AND po.status IN ('draft','approved','partially_received')) AS open_po_count,
               -- Owed = value of goods actually received, less what has been paid.
               (SELECT COALESCE(SUM(r.total_value), 0) FROM inv_po_receipts r
                  JOIN inv_purchase_orders po ON po.id = r.po_id
                  WHERE po.supplier_id = s.id AND po.deleted_at IS NULL) AS received_value,
               (SELECT COALESCE(SUM(p.amount), 0) FROM inv_po_payments p
                  JOIN inv_purchase_orders po ON po.id = p.po_id
                  WHERE po.supplier_id = s.id AND po.deleted_at IS NULL) AS paid_value
        FROM inv_suppliers s
        WHERE $where
        ORDER BY s.is_active DESC, s.name ASC";
$stmt = $conn->prepare($sql);
if ($types !== '') { $stmt->bind_param($types, ...$params); }
$stmt->execute();
$suppliers = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

// ---------- Headline figures ----------
$totals = ['all' => 0, 'active' => 0, 'open_pos' => 0, 'outstanding' => 0.0];
$tRes = $conn->query(
    "SELECT COUNT(*) AS all_s, SUM(is_active = 1) AS active_s FROM inv_suppliers WHERE deleted_at IS NULL");
if ($tRes && ($r = $tRes->fetch_assoc())) {
    $totals['all'] = (int)$r['all_s'];
    $totals['active'] = (int)$r['active_s'];
}
$tRes = $conn->query(
    "SELECT COUNT(*) AS c FROM inv_purchase_orders
      WHERE deleted_at IS NULL AND status IN ('draft','approved','partially_received')");
if ($tRes && ($r = $tRes->fetch_assoc())) { $totals['open_pos'] = (int)$r['c']; }

$tRes = $conn->query(
    "SELECT COALESCE((SELECT SUM(total_value) FROM inv_po_receipts), 0)
          - COALESCE((SELECT SUM(amount) FROM inv_po_payments), 0) AS owed");
if ($tRes && ($r = $tRes->fetch_assoc())) { $totals['outstanding'] = (float)$r['owed']; }

$pageTitle = 'Suppliers';
$breadcrumbs = [['Dashboard', 'index.php'], ['Inventory', 'inventory-dashboard.php'], ['Suppliers']];
include 'inventory-header.php';

$ph = [
    'title'    => 'Suppliers',
    'icon'     => 'fa-truck-field',
    'subtitle' => 'Who you buy from, and what you still owe them.',
    'actions'  => '<button type="button" class="ui-btn ui-btn-primary" data-bs-toggle="modal" data-bs-target="#supplierModal" onclick="newSupplier()">'
                . '<i class="fas fa-plus"></i>Add supplier</button>',
];
include 'partials/page-header.php';
?>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="inv-stat-card bg-grad-teal">
            <div class="d-flex justify-content-between align-items-start">
                <div><div class="label">Suppliers</div><div class="value"><?php echo number_format($totals['all']); ?></div></div>
                <i class="fas fa-truck-field icon"></i>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="inv-stat-card bg-grad-green">
            <div class="d-flex justify-content-between align-items-start">
                <div><div class="label">Active</div><div class="value"><?php echo number_format($totals['active']); ?></div></div>
                <i class="fas fa-circle-check icon"></i>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="inv-stat-card bg-grad-blue">
            <div class="d-flex justify-content-between align-items-start">
                <div><div class="label">Open purchase orders</div><div class="value"><?php echo number_format($totals['open_pos']); ?></div></div>
                <i class="fas fa-file-invoice-dollar icon"></i>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="inv-stat-card <?php echo $totals['outstanding'] > 0.005 ? 'bg-grad-amber' : 'bg-grad-purple'; ?>">
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <div class="label">Outstanding</div>
                    <div class="value">Tsh <?php echo number_format($totals['outstanding']); ?></div>
                    <div class="label">On goods received</div>
                </div>
                <i class="fas fa-scale-balanced icon"></i>
            </div>
        </div>
    </div>
</div>

<form method="get" class="ui-toolbar" role="search">
    <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?php echo htmlspecialchars($status); ?>"><?php endif; ?>
    <div class="ui-search <?php echo $search !== '' ? 'has-value' : ''; ?>">
        <i class="fas fa-magnifying-glass"></i>
        <input type="search" name="q" value="<?php echo htmlspecialchars($search); ?>"
               placeholder="Search name, contact, phone or email…" aria-label="Search suppliers">
        <button type="button" class="ui-search-clear" aria-label="Clear search"><i class="fas fa-xmark"></i></button>
    </div>
    <button class="ui-btn ui-btn-secondary"><i class="fas fa-magnifying-glass"></i>Search</button>

    <span style="width:1px;height:20px;background:var(--color-border);margin:0 4px;"></span>

    <?php
    $statusChips = ['' => 'All', 'active' => 'Active', 'inactive' => 'Inactive'];
    foreach ($statusChips as $key => $label):
        $qs = array_filter(['q' => $search, 'status' => $key]);
    ?>
    <a href="?<?php echo htmlspecialchars(http_build_query($qs)); ?>"
       class="ui-chip <?php echo $status === $key ? 'active' : ''; ?>"><?php echo $label; ?></a>
    <?php endforeach; ?>

    <?php if ($search !== '' || $status !== ''): ?>
        <a href="inventory-suppliers.php" class="ui-btn ui-btn-ghost"><i class="fas fa-xmark"></i>Clear</a>
    <?php endif; ?>

    <span class="ui-toolbar-spacer"></span>
    <span class="ui-result-count"><strong><?php echo count($suppliers); ?></strong> shown</span>
</form>

<div class="inv-card p-0">
    <div class="ui-table-wrap">
        <table class="inv-table">
            <thead>
                <tr>
                    <th>Supplier</th>
                    <th class="ui-col-optional">Contact</th>
                    <th class="ui-col-secondary">Phone</th>
                    <th class="ui-col-optional">Email</th>
                    <th class="text-end">Products</th>
                    <th class="text-end">Orders</th>
                    <th class="text-end">Outstanding</th>
                    <th>Status</th>
                    <th style="width:96px;"><span class="visually-hidden">Actions</span></th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$suppliers): ?>
                <tr><td colspan="9">
                    <?php
                    $es = ($search !== '' || $status !== '')
                        ? ['icon' => 'fa-magnifying-glass', 'title' => 'No suppliers match',
                           'msg'  => 'Try a different search or status.',
                           'action' => '<a class="ui-btn ui-btn-secondary" href="inventory-suppliers.php">Clear filters</a>']
                        : ['icon' => 'fa-truck-field', 'title' => 'No suppliers yet',
                           'msg'  => 'Add the businesses you buy stock from.',
                           'action' => '<button type="button" class="ui-btn ui-btn-primary" data-bs-toggle="modal" data-bs-target="#supplierModal" onclick="newSupplier()"><i class="fas fa-plus"></i>Add supplier</button>'];
                    include 'partials/empty-state.php';
                    ?>
                </td></tr>
            <?php endif; ?>

            <?php foreach ($suppliers as $s):
                $owed   = round((float)$s['received_value'] - (float)$s['paid_value'], 2);
                $inUse  = ((int)$s['item_count'] > 0 || (int)$s['po_count'] > 0);
                $openPo = (int)$s['open_po_count'];
            ?>
                <tr>
                    <td>
                        <div style="font-weight:500;"><?php echo htmlspecialchars($s['name']); ?></div>
                        <?php if (!empty($s['address'])): ?>
                        <div class="ui-caption"><?php echo htmlspecialchars($s['address']); ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="ui-col-optional"><?php echo htmlspecialchars($s['contact_person'] ?: '—'); ?></td>
                    <td class="ui-col-secondary"><span class="ui-num"><?php echo htmlspecialchars($s['phone'] ?: '—'); ?></span></td>
                    <td class="ui-col-optional ui-muted"><?php echo htmlspecialchars($s['email'] ?: '—'); ?></td>
                    <td class="text-end ui-num"><?php echo number_format((int)$s['item_count']); ?></td>
                    <td class="text-end">
                        <span class="ui-num"><?php echo number_format((int)$s['po_count']); ?></span>
                        <?php if ($openPo > 0): ?>
                        <div><span class="ui-badge ui-badge-info"><?php echo $openPo; ?> open</span></div>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <?php if ($owed > 0.005): ?>
                            <span class="ui-money" style="color:var(--color-warning);font-weight:600;">Tsh <?php echo number_format($owed); ?></span>
                        <?php else: ?>
                            <span class="ui-caption">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ((int)$s['is_active'] === 1): ?>
                            <span class="ui-badge ui-badge-success"><span class="ui-dot ui-dot-success"></span>Active</span>
                        <?php else: ?>
                            <span class="ui-badge ui-badge-neutral">Inactive</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="d-flex gap-1 justify-content-end">
                            <button type="button" class="ui-btn ui-btn-secondary ui-btn-sm ui-btn-icon"
                                    title="Edit <?php echo htmlspecialchars($s['name'], ENT_QUOTES); ?>"
                                    aria-label="Edit <?php echo htmlspecialchars($s['name'], ENT_QUOTES); ?>"
                                    data-ui-modal="#supplierModal"
                                    data-title="Edit supplier"
                                    data-field-id="<?php echo (int)$s['id']; ?>"
                                    data-field-name="<?php echo htmlspecialchars($s['name'], ENT_QUOTES); ?>"
                                    data-field-contact-person="<?php echo htmlspecialchars($s['contact_person'] ?? '', ENT_QUOTES); ?>"
                                    data-field-phone="<?php echo htmlspecialchars($s['phone'] ?? '', ENT_QUOTES); ?>"
                                    data-field-email="<?php echo htmlspecialchars($s['email'] ?? '', ENT_QUOTES); ?>"
                                    data-field-address="<?php echo htmlspecialchars($s['address'] ?? '', ENT_QUOTES); ?>"
                                    data-field-opening-balance="<?php echo (float)$s['opening_balance']; ?>"
                                    data-field-notes="<?php echo htmlspecialchars($s['notes'] ?? '', ENT_QUOTES); ?>"
                                    data-field-is-active="<?php echo (int)$s['is_active'] === 1 ? '1' : '0'; ?>">
                                <i class="fas fa-pen"></i>
                            </button>
                            <form method="post" onsubmit="return confirm(<?php
                                echo $inUse
                                    ? "'\"" . htmlspecialchars($s['name'], ENT_QUOTES) . "\" has purchase history. It will be deactivated rather than deleted, so its orders and products keep their supplier. Continue?'"
                                    : "'Delete \"" . htmlspecialchars($s['name'], ENT_QUOTES) . "\"? Nothing is linked to it.'";
                            ?>
);">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?php echo (int)$s['id']; ?>">
                                <button class="ui-btn ui-btn-danger ui-btn-sm ui-btn-icon"
                                        title="<?php echo $inUse ? 'Deactivate' : 'Delete'; ?>"
                                        aria-label="<?php echo $inUse ? 'Deactivate' : 'Delete'; ?> <?php echo htmlspecialchars($s['name'], ENT_QUOTES); ?>">
                                    <i class="fas <?php echo $inUse ? 'fa-ban' : 'fa-trash'; ?>"></i>
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

<!-- ============ Add / edit supplier ============ -->
<div class="modal fade" id="supplierModal" tabindex="-1" aria-labelledby="supplierModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <form method="post" data-ui-loading>
<?php echo csrfField(); ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="0">
                <div class="modal-header">
                    <h5 class="modal-title" id="supplierModalTitle">
                        <i class="fas fa-truck-field me-2" style="color:var(--color-primary);"></i>
                        <span data-ui-modal-title>Add supplier</span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="s_name">Supplier name<span class="ui-required">*</span></label>
                            <input type="text" class="form-control" id="s_name" name="name" required maxlength="150" autocomplete="off">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="s_contact">Contact person</label>
                            <input type="text" class="form-control" id="s_contact" name="contact_person" maxlength="100">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="s_phone">Phone</label>
                            <input type="text" class="form-control" id="s_phone" name="phone" maxlength="30" inputmode="tel">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="s_email">Email</label>
                            <input type="email" class="form-control" id="s_email" name="email" maxlength="120">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label" for="s_address">Address</label>
                            <input type="text" class="form-control" id="s_address" name="address" maxlength="255">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="s_opening">Opening balance (Tsh)</label>
                            <input type="number" step="0.01" class="form-control" id="s_opening" name="opening_balance" value="0">
                            <div class="form-text">What you already owed when they were added.</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="s_notes">Notes</label>
                            <textarea class="form-control" id="s_notes" name="notes" rows="2"
                                      placeholder="Delivery days, payment terms, anything worth remembering"></textarea>
                        </div>
                        <div class="col-12">
                            <div class="form-check form-switch mb-0">
                                <input class="form-check-input" type="checkbox" name="is_active" id="s_active" checked>
                                <label class="form-check-label" for="s_active">Active — can be chosen on new purchase orders</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="ui-btn ui-btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="ui-btn ui-btn-primary"><i class="fas fa-check"></i>Save supplier</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$pageScript = <<<'HTML'
<script>
// One modal serves both Add and Edit, so Add has to clear whatever the
// last edit left behind.
function newSupplier() {
    const modal = document.getElementById('supplierModal');
    modal.querySelector('[data-ui-modal-title]').textContent = 'Add supplier';
    modal.querySelector('[name="id"]').value = '0';
    ['name','contact_person','phone','email','address','notes'].forEach(function (n) {
        modal.querySelector('[name="' + n + '"]').value = '';
    });
    modal.querySelector('[name="opening_balance"]').value = '0';
    modal.querySelector('[name="is_active"]').checked = true;
}
</script>
HTML;
include 'inventory-footer.php';
?>
