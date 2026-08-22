<?php
require_once '../includes/auth.php';
requireRole(['admin', 'manager']);

// Reject any POST that does not carry this session's CSRF token.
// Placed before every handler on this page, and a no-op on GET.
csrfRequire();
date_default_timezone_set('Africa/Dar_es_Salaam');

$current_page = basename($_SERVER['PHP_SELF']);
include '../includes/db.php';
require_once '../includes/remember_me.php';

$currentUserId = $_SESSION['id'];
$allowedRoles = allSystemRoles();

// ------------------------------------------------------------
// One-time migration: the legacy role column was an ENUM, so saving a
// role it didn't know about silently stored an EMPTY string and locked
// that user out. Widen it to VARCHAR once, then flag anyone whose role
// is blank or is a retired laundry role (staff, driver, laundry_staff,
// sales_officer, stock_keeper) - those accounts can no longer sign in
// and need reassigning to one of the four retail roles.
// ------------------------------------------------------------
$roleCol = @$conn->query("SHOW COLUMNS FROM `admin` LIKE 'role'");
if ($roleCol instanceof mysqli_result) {
    $colInfo = $roleCol->fetch_assoc();
    $roleCol->free();
    if ($colInfo && stripos((string)$colInfo['Type'], 'enum') === 0) {
        @$conn->query("ALTER TABLE `admin` MODIFY `role` VARCHAR(30) NOT NULL DEFAULT 'cashier'");
    }
}
$blankRoleUsers = [];
$blankRes = @$conn->query("SELECT username, role FROM admin WHERE role IS NULL OR role = ''
    OR role NOT IN ('admin','manager','storekeeper','cashier')");
if ($blankRes instanceof mysqli_result) {
    while ($b = $blankRes->fetch_assoc()) {
        $blankRoleUsers[] = $b['username'] . ($b['role'] ? ' (' . $b['role'] . ')' : '');
    }
}

$success_message = null;
$error_message = null;

// ------------------------------------------------------------
// Handle: Add user
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? '';

    if (!preg_match("/^[a-zA-Z0-9_-]{3,50}$/", $username)) {
        $error_message = "Username must be 3-50 characters and can only contain letters, numbers, underscores, and dashes.";
    } elseif (strlen($password) < 6) {
        $error_message = "Password must be at least 6 characters.";
    } elseif (!in_array($role, $allowedRoles, true)) {
        $error_message = "Invalid role selected.";
    } else {
        $check = $conn->prepare("SELECT id FROM admin WHERE username = ?");
        $check->bind_param("s", $username);
        $check->execute();
        if ($check->get_result()->num_rows > 0) {
            $error_message = "That username is already taken.";
        } else {
            $hashed = password_hash($password, PASSWORD_BCRYPT);
            $insert = $conn->prepare("INSERT INTO admin (username, password, role, status) VALUES (?, ?, ?, 'active')");
            $insert->bind_param("sss", $username, $hashed, $role);
            if ($insert->execute()) {
                $success_message = "User \"$username\" created successfully.";
            } else {
                $error_message = "Failed to create user: " . $conn->error;
            }
        }
    }
}

// ------------------------------------------------------------
// Handle: Update user (role + optional password reset)
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_user'])) {
    $edit_id = (int)($_POST['edit_id'] ?? 0);
    $role = $_POST['role'] ?? '';
    $new_password = $_POST['new_password'] ?? '';

    if (!in_array($role, $allowedRoles, true)) {
        $error_message = "Invalid role selected.";
    } elseif ($edit_id === (int)$currentUserId && $role !== $_SESSION['role']) {
        $error_message = "You can't change your own role. Ask another admin to do that for you.";
    } elseif ($new_password !== '' && strlen($new_password) < 6) {
        $error_message = "New password must be at least 6 characters.";
    } else {
        if ($new_password !== '') {
            $hashed = password_hash($new_password, PASSWORD_BCRYPT);
            $update = $conn->prepare("UPDATE admin SET role = ?, password = ? WHERE id = ?");
            $update->bind_param("ssi", $role, $hashed, $edit_id);
        } else {
            $update = $conn->prepare("UPDATE admin SET role = ? WHERE id = ?");
            $update->bind_param("si", $role, $edit_id);
        }
        if ($update->execute()) {
            $success_message = "User updated successfully.";
            // A changed password or role changes what this account is
            // trusted to do, so every remembered device must sign in
            // again. Without this, a persistent login would keep the old
            // credential alive after an admin had revoked it.
            rememberForgetUser($conn, $edit_id);
            if ($edit_id === (int)$currentUserId) {
                $_SESSION['role'] = $role;
            }
        } else {
            $error_message = "Failed to update user: " . $conn->error;
        }
    }
}

// ------------------------------------------------------------
// Handle: Activate / Deactivate
// ------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_status'])) {
    $toggle_id = (int)($_POST['toggle_id'] ?? 0);
    $new_status = $_POST['new_status'] ?? '';

    if (!in_array($new_status, ['active', 'inactive'], true)) {
        $error_message = "Invalid status.";
    } elseif ($toggle_id === (int)$currentUserId && $new_status === 'inactive') {
        $error_message = "You can't deactivate your own account.";
    } else {
        // Don't allow deactivating the last remaining active admin
        if ($new_status === 'inactive') {
            $target = $conn->prepare("SELECT role FROM admin WHERE id = ?");
            $target->bind_param("i", $toggle_id);
            $target->execute();
            $targetRow = $target->get_result()->fetch_assoc();

            if ($targetRow && $targetRow['role'] === 'admin') {
                $countActiveAdmins = $conn->query("SELECT COUNT(*) as c FROM admin WHERE role = 'admin' AND status = 'active'")->fetch_assoc()['c'];
                if ((int)$countActiveAdmins <= 1) {
                    $error_message = "Can't deactivate the last remaining active admin account.";
                }
            }
        }

        if (!isset($error_message)) {
            $update = $conn->prepare("UPDATE admin SET status = ? WHERE id = ?");
            $update->bind_param("si", $new_status, $toggle_id);
            if ($update->execute()) {
                $success_message = $new_status === 'active' ? "User activated." : "User deactivated.";
                // Deactivating must take effect immediately, including on
                // any till where this user ticked "keep me signed in".
                if ($new_status !== 'active') {
                    rememberForgetUser($conn, $toggle_id);
                }
            } else {
                $error_message = "Failed to update status: " . $conn->error;
            }
        }
    }
}

// ------------------------------------------------------------
// Fetch all users + stats
// ------------------------------------------------------------
$users_result = $conn->query("SELECT id, username, role, status, profile_photo FROM admin ORDER BY id ASC");

$stats_result = $conn->query("SELECT
    COUNT(*) as total,
    SUM(CASE WHEN role = 'admin' THEN 1 ELSE 0 END) as admins,
    SUM(CASE WHEN role = 'manager' THEN 1 ELSE 0 END) as managers,
    SUM(CASE WHEN role = 'storekeeper' THEN 1 ELSE 0 END) as storekeepers,
    SUM(CASE WHEN role = 'cashier' THEN 1 ELSE 0 END) as cashiers,
    SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive
FROM admin");
$stats = $stats_result->fetch_assoc();

function getRoleBadge($role) {
    switch ($role) {
        case 'admin':
            return '<span class="mx-status s-success"><i class="fas fa-crown me-1"></i>Administrator</span>';
        case 'manager':
            return '<span class="mx-status s-success"><i class="fas fa-user-tie me-1"></i>Manager</span>';
        case 'storekeeper':
            return '<span class="mx-status s-info"><i class="fas fa-warehouse me-1"></i>Storekeeper</span>';
        case 'cashier':
            return '<span class="mx-status s-info"><i class="fas fa-cash-register me-1"></i>Cashier</span>';
        default:
            // Retired laundry roles land here too - the account cannot
            // sign in until it is reassigned.
            return '<span class="mx-status s-danger"><i class="fas fa-triangle-exclamation me-1"></i>'
                 . ($role ? 'Invalid: ' . htmlspecialchars($role) : 'No role') . '</span>';
    }
}

function getStatusBadgeUser($status) {
    if ($status === 'active') {
        return '<span class="mx-status s-success"><i class="fas fa-check-circle me-1"></i>Active</span>';
    }
    return '<span class="mx-status s-danger"><i class="fas fa-ban me-1"></i>Inactive</span>';
}
?>

<?php
$pageTitle = 'Manage Users';
$breadcrumbs = [['Dashboard', 'index.php'], ['Account'], ['Manage Users']];
include 'inventory-header.php';

$ph = [
    'title'    => 'Manage Users',
    'icon'     => 'fa-user-shield',
    'subtitle' => 'Staff accounts, role assignment and access control.',
    'actions'  => '<button type="button" class="ui-btn ui-btn-primary" data-bs-toggle="modal" data-bs-target="#addUserModal">'
                . '<i class="fas fa-user-plus"></i>Add user</button>',
];
include 'partials/page-header.php';
?>

<?php if ($success_message): ?>
    <div class="alert alert-success" role="alert">
        <i class="fas fa-circle-check"></i><div><?php echo htmlspecialchars($success_message); ?></div>
    </div>
<?php endif; ?>
<?php if ($error_message): ?>
    <div class="alert alert-danger" role="alert">
        <i class="fas fa-circle-exclamation"></i><div><?php echo htmlspecialchars($error_message); ?></div>
    </div>
<?php endif; ?>

<?php if ($blankRoleUsers): ?>
<div class="alert alert-danger" role="alert">
    <i class="fas fa-triangle-exclamation"></i>
    <div>
        <strong>These accounts have no valid role and cannot sign in:</strong>
        <?php echo htmlspecialchars(implode(', ', $blankRoleUsers)); ?>.
        <div class="ui-caption mt-1">
            Open <strong>Edit</strong> on each account and choose one of the four current roles.
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ============ Headline counts ============ -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="inv-stat-card bg-grad-teal">
            <div class="d-flex justify-content-between align-items-start">
                <div><div class="label">Total users</div><div class="value"><?php echo (int)($stats['total'] ?? 0); ?></div></div>
                <i class="fas fa-users icon"></i>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="inv-stat-card bg-grad-green">
            <div class="d-flex justify-content-between align-items-start">
                <div><div class="label">Administrators</div><div class="value"><?php echo (int)($stats['admins'] ?? 0); ?></div></div>
                <i class="fas fa-crown icon"></i>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="inv-stat-card bg-grad-blue">
            <div class="d-flex justify-content-between align-items-start">
                <div><div class="label">Managers</div><div class="value"><?php echo (int)($stats['managers'] ?? 0); ?></div></div>
                <i class="fas fa-user-tie icon"></i>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="inv-stat-card bg-grad-purple">
            <div class="d-flex justify-content-between align-items-start">
                <div><div class="label">Storekeepers</div><div class="value"><?php echo (int)($stats['storekeepers'] ?? 0); ?></div></div>
                <i class="fas fa-warehouse icon"></i>
            </div>
        </div>
    </div>
</div>

<!-- ============ Users ============ -->
<div class="inv-card p-0">
    <div class="ui-card-head">
        <h2 class="ui-card-title">Staff accounts</h2>
        <div class="ui-toolbar mb-0">
            <div class="ui-search">
                <i class="fas fa-magnifying-glass"></i>
                <input type="search" data-mx-filter="#usersTable"
                       placeholder="Search username, role or status…" aria-label="Search users">
                <button type="button" class="ui-search-clear" aria-label="Clear search"><i class="fas fa-xmark"></i></button>
            </div>
            <span class="ui-result-count">
                <span data-mx-count="#usersTable"><?php echo $users_result->num_rows; ?></span> user(s)
            </span>
        </div>
    </div>

    <div class="ui-table-wrap">
        <table class="inv-table" id="usersTable">
            <thead>
                <tr>
                    <th class="ui-col-optional" style="width:70px;">ID</th>
                    <th>User</th>
                    <th>Role</th>
                    <th>Status</th>
                    <th style="width:110px;"><span class="visually-hidden">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                <?php $users_result->data_seek(0); while ($row = $users_result->fetch_assoc()):
                    $searchStr = strtolower($row['username'] . ' ' . $row['role'] . ' ' . $row['status']);
                    $isSelf = (int)$row['id'] === (int)$currentUserId;
                ?>
                <tr data-search="<?php echo htmlspecialchars($searchStr, ENT_QUOTES); ?>">
                    <td class="ui-col-optional"><span class="ui-code">#<?php echo (int)$row['id']; ?></span></td>
                    <td>
                        <span style="font-weight:500;"><?php echo htmlspecialchars($row['username']); ?></span>
                        <?php if ($isSelf): ?>
                            <span class="ui-badge ui-badge-info ms-1">You</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo getRoleBadge($row['role']); ?></td>
                    <td><?php echo getStatusBadgeUser($row['status']); ?></td>
                    <td>
                        <div class="d-flex gap-1 justify-content-end">
                            <button type="button" class="ui-btn ui-btn-secondary ui-btn-sm ui-btn-icon"
                                    data-bs-toggle="modal" data-bs-target="#editUserModal"
                                    data-id="<?php echo (int)$row['id']; ?>"
                                    data-username="<?php echo htmlspecialchars($row['username'], ENT_QUOTES); ?>"
                                    data-role="<?php echo htmlspecialchars($row['role']); ?>"
                                    data-isself="<?php echo $isSelf ? '1' : '0'; ?>"
                                    title="Edit <?php echo htmlspecialchars($row['username'], ENT_QUOTES); ?>"
                                    aria-label="Edit <?php echo htmlspecialchars($row['username'], ENT_QUOTES); ?>">
                                <i class="fas fa-pen"></i>
                            </button>
                            <?php if (!$isSelf): ?>
                                <?php if ($row['status'] === 'active'): ?>
                                    <form method="POST" class="toggle-status-form">
<?php echo csrfField(); ?>
                                        <input type="hidden" name="toggle_id" value="<?php echo (int)$row['id']; ?>">
                                        <input type="hidden" name="new_status" value="inactive">
                                        <button type="submit" name="toggle_status" class="ui-btn ui-btn-danger ui-btn-sm ui-btn-icon"
                                                title="Deactivate" aria-label="Deactivate <?php echo htmlspecialchars($row['username'], ENT_QUOTES); ?>">
                                            <i class="fas fa-ban"></i>
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <form method="POST">
<?php echo csrfField(); ?>
                                        <input type="hidden" name="toggle_id" value="<?php echo (int)$row['id']; ?>">
                                        <input type="hidden" name="new_status" value="active">
                                        <button type="submit" name="toggle_status" class="ui-btn ui-btn-secondary ui-btn-sm ui-btn-icon"
                                                title="Activate" aria-label="Activate <?php echo htmlspecialchars($row['username'], ENT_QUOTES); ?>">
                                            <i class="fas fa-check"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>

    <div data-mx-empty="#usersTable" style="display:none;">
        <div class="ui-empty">
            <i class="fas fa-user-slash"></i>
            <div class="ui-empty-title">No matching users</div>
            <div class="ui-empty-msg">Try a different username, role or status.</div>
        </div>
    </div>
</div>

<!-- ============ Add user ============ -->
<div class="modal fade" id="addUserModal" tabindex="-1" aria-labelledby="addUserTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" data-ui-loading>
<?php echo csrfField(); ?>
                <div class="modal-header">
                    <h5 class="modal-title" id="addUserTitle"><i class="fas fa-user-plus me-2" style="color:var(--color-primary);"></i>Add user</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label" for="au_username">Username<span class="ui-required">*</span></label>
                        <input type="text" class="form-control" id="au_username" name="username" required
                               maxlength="50" pattern="[a-zA-Z0-9_-]{3,50}" autocomplete="off">
                        <div class="form-text">3–50 characters: letters, numbers, underscore, dash.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="au_password">Password<span class="ui-required">*</span></label>
                        <input type="password" class="form-control" id="au_password" name="password" required
                               minlength="6" autocomplete="new-password">
                        <div class="form-text">At least 6 characters.</div>
                    </div>
                    <div class="mb-0">
                        <label class="form-label" for="au_role">Role<span class="ui-required">*</span></label>
                        <select class="form-select" id="au_role" name="role" required>
                            <option value="cashier">Cashier — POS till, receipts, customers</option>
                            <option value="storekeeper">Storekeeper — stock intake, purchase orders, reorder alerts</option>
                            <option value="manager">Manager — sales reports, inventory overrides, daily summaries</option>
                            <option value="admin">Administrator — full unrestricted system control</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="ui-btn ui-btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_user" class="ui-btn ui-btn-primary"><i class="fas fa-check"></i>Create user</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============ Edit user ============ -->
<div class="modal fade" id="editUserModal" tabindex="-1" aria-labelledby="editUserTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" data-ui-loading>
<?php echo csrfField(); ?>
                <div class="modal-header">
                    <h5 class="modal-title" id="editUserTitle">
                        <i class="fas fa-pen me-2" style="color:var(--color-primary);"></i>Edit <span id="editUsernameLabel"></span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="edit_id" id="edit_id">
                    <div class="mb-3">
                        <label class="form-label" for="edit_role">Role</label>
                        <select class="form-select" name="role" id="edit_role">
                            <option value="cashier">Cashier — POS till, receipts, customers</option>
                            <option value="storekeeper">Storekeeper — stock intake, purchase orders, reorder alerts</option>
                            <option value="manager">Manager — sales reports, inventory overrides, daily summaries</option>
                            <option value="admin">Administrator — full unrestricted system control</option>
                        </select>
                        <div class="form-text" id="editSelfNote" style="display:none;">
                            You cannot change your own role — ask another administrator.
                        </div>
                    </div>
                    <div class="mb-0">
                        <label class="form-label" for="edit_password">New password</label>
                        <input type="password" class="form-control" id="edit_password" name="new_password"
                               minlength="6" autocomplete="new-password">
                        <div class="form-text">Leave blank to keep the current password.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="ui-btn ui-btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_user" class="ui-btn ui-btn-primary"><i class="fas fa-check"></i>Save changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$pageScript = <<<'HTML'
<script>
document.addEventListener('DOMContentLoaded', function () {
    // Populate the edit modal from the row's data-* attributes.
    const editModal = document.getElementById('editUserModal');
    if (editModal) {
        editModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            if (!button) { return; }
            const isSelf = button.getAttribute('data-isself') === '1';

            document.getElementById('edit_id').value = button.getAttribute('data-id');
            document.getElementById('editUsernameLabel').textContent = button.getAttribute('data-username');
            document.getElementById('edit_role').value = button.getAttribute('data-role');
            // The server enforces this too - disabling is only a hint.
            document.getElementById('edit_role').disabled = isSelf;
            document.getElementById('editSelfNote').style.display = isSelf ? 'block' : 'none';
        });
    }

    document.querySelectorAll('.toggle-status-form').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            if (!confirm('Deactivate this user? They will no longer be able to sign in.')) {
                e.preventDefault();
            }
        });
    });
});
</script>
HTML;
include 'inventory-footer.php';
?>
