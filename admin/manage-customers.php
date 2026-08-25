<?php
require_once '../includes/auth.php';
requireModule('customers');

// Reject any POST that does not carry this session's CSRF token.
// Placed before every handler on this page, and a no-op on GET.
csrfRequire();
// Set timezone to Asia/Jakarta (WIB)
date_default_timezone_set('Africa/Dar_es_Salaam');

$current_page = basename($_SERVER['PHP_SELF']);
include '../includes/db.php';

// Format full date
function formatFullDate($date) {
    $days = array(
        'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'
    );

    $months = array(
        1 => 'January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December'
    );

    $timestamp = strtotime($date);
    $day_name = $days[date('w', $timestamp)];
    $day_num = date('j', $timestamp);
    $month_name = $months[date('n', $timestamp)];
    $year = date('Y', $timestamp);
    $time = date('H:i', $timestamp);

    return "$day_name, $day_num $month_name $year $time";
}

// Format phone number to +255 format
function formatPhoneNumber($phone) {
    // Remove all non-digit characters
    $phone = preg_replace('/[^0-9]/', '', $phone);

 // If starts with 0, replace with +255
if (substr($phone, 0, 1) === '0') {
    $phone = '+255' . substr($phone, 1);
}
// If no country code yet, add +255
elseif (substr($phone, 0, 2) !== '255' && substr($phone, 0, 4) !== '+255') {
    $phone = '+255' . $phone;
}
// If starts with 255 without +, add +
elseif (substr($phone, 0, 3) === '255') {
    $phone = '+' . $phone;
}

    return $phone;
}

$current_date_display = formatFullDate(date('Y-m-d H:i:s'));

/**
 * Customers matching a name or phone fragment, newest search rules in
 * one place so the page and the live-search endpoint cannot drift apart.
 *
 * A prepared statement rather than real_escape_string() + interpolation:
 * escaping puts the burden on every future edit remembering to do it,
 * and it was the last place in the codebase where user input reached a
 * query by concatenation. Binding removes the question entirely.
 *
 * The LIKE wildcards are added to the BOUND VALUE, not to the SQL, so a
 * customer searching for "100%" matches literally rather than altering
 * the pattern.
 */
function customerSearch(mysqli $conn, string $search): array {
    $sql = "SELECT id, name, phone_number, address, tin, email FROM customer";
    $search = trim($search);

    if ($search === '') {
        $stmt = $conn->prepare($sql . " ORDER BY name ASC");
        if (!$stmt) { return []; }
    } else {
        $stmt = $conn->prepare($sql . " WHERE name LIKE ? OR phone_number LIKE ? ORDER BY name ASC");
        if (!$stmt) { return []; }
        // escape_like() is not a thing in mysqli, so neutralise the
        // wildcards ourselves before wrapping the term.
        $term = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
        $stmt->bind_param('ss', $term, $term);
    }

    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

// Initialize search variables
$search = isset($_GET['search']) ? (string)$_GET['search'] : '';

// The live-search endpoint further down exits before rendering the page,
// so skip the list query here when that is what was asked for - it would
// otherwise run twice on every keystroke.
$customers = isset($_GET['ajax_search']) ? [] : customerSearch($conn, $search);

// Process add customer if there's request
if (isset($_POST['add_customer'])) {
    $name = $_POST['name'];
    $phone_number = formatPhoneNumber($_POST['phone_number']);
    $address = trim($_POST['address'] ?? '') ?: null;
    $tin = trim($_POST['tin'] ?? '') ?: null;
    $email = trim($_POST['email'] ?? '') ?: null;

    // Validate input
    $errors = [];

    if (empty($name)) {
        $errors[] = "Customer name must be filled";
    }

    if (empty($phone_number)) {
        $errors[] = "Phone number must be filled";
    }

    // Check if phone number is already registered
    $check_phone = $conn->prepare("SELECT id FROM customer WHERE phone_number = ?");
    $check_phone->bind_param("s", $phone_number);
    $check_phone->execute();
    $check_result = $check_phone->get_result();

    if ($check_result->num_rows > 0) {
        $errors[] = "Phone number is already registered";
    }

    if (empty($errors)) {
        // Add new customer
        $insert_query = $conn->prepare("INSERT INTO customer (name, phone_number, address, tin, email, registration_date) VALUES (?, ?, ?, ?, ?, NOW())");
        $insert_query->bind_param("sssss", $name, $phone_number, $address, $tin, $email);

        if ($insert_query->execute()) {
            $success_message = "Customer successfully added";
            // Refresh page to update data
            header("Location: manage-customers.php?added=true");
            exit();
        } else {
            $error_message = "Failed to add customer: " . $conn->error;
        }
    } else {
        $error_message = implode("<br>", $errors);
    }
}

// Process update customer if there's request
if (isset($_POST['update_customer'])) {
    $id = $_POST['id'];
    $name = $_POST['name'];
    $phone_number = formatPhoneNumber($_POST['phone_number']);
    $address = trim($_POST['address'] ?? '') ?: null;
    $tin = trim($_POST['tin'] ?? '') ?: null;
    $email = trim($_POST['email'] ?? '') ?: null;

    // Validate input
    $errors = [];

    if (empty($name)) {
        $errors[] = "Customer name must be filled";
    }

    if (empty($phone_number)) {
        $errors[] = "Phone number must be filled";
    }

    // Check if phone number is already registered (except for the customer being edited)
    $check_phone = $conn->prepare("SELECT id FROM customer WHERE phone_number = ? AND id != ?");
    $check_phone->bind_param("si", $phone_number, $id);
    $check_phone->execute();
    $check_result = $check_phone->get_result();

    if ($check_result->num_rows > 0) {
        $errors[] = "Phone number is already registered for another customer";
    }

    if (empty($errors)) {
        // Update customer data
        $update_query = $conn->prepare("UPDATE customer SET name = ?, phone_number = ?, address = ?, tin = ?, email = ? WHERE id = ?");
        $update_query->bind_param("sssssi", $name, $phone_number, $address, $tin, $email, $id);

        if ($update_query->execute()) {
            $success_message = "Customer data successfully updated";
            // Refresh page to update data
            header("Location: manage-customers.php?updated=true");
            exit();
        } else {
            $error_message = "Failed to update customer data: " . $conn->error;
        }
    } else {
        $error_message = implode("<br>", $errors);
    }
}

// Process delete customer if there's request
if (isset($_POST['delete_id']) && !empty($_POST['delete_id'])) {
    $delete_id = $_POST['delete_id'];

    // A customer attached to a sale must not be deleted - the receipt
    // history would lose the name it was issued to.
    $check_query = $conn->prepare("SELECT COUNT(*) as count FROM sales_transactions WHERE customer_id = ?");
    $check_query->bind_param("i", $delete_id);
    $check_query->execute();
    $check_result = $check_query->get_result();
    $check_data = $check_result->fetch_assoc();

    if ($check_data['count'] > 0) {
        $delete_error = "Customer cannot be deleted because they have recorded sales.";
    } else {
        // Delete customer if they have no sales
        $delete_query = $conn->prepare("DELETE FROM customer WHERE id = ?");
        $delete_query->bind_param("i", $delete_id);

        if ($delete_query->execute()) {
            $delete_success = "Customer successfully deleted.";
            // Refresh page to update data
            header("Location: manage-customers.php?deleted=true");
            exit();
        } else {
            $delete_error = "Failed to delete customer: " . $conn->error;
        }
    }
}

// Check if there are active filters
$has_active_filters = !empty($search);

// AJAX handler for real-time search
if (isset($_GET['ajax_search'])) {
    $search = isset($_GET['search']) ? (string)$_GET['search'] : '';

    // Same helper as the page-load list, so the two can never disagree
    // about what a search means.
    $rows = customerSearch($conn, $search);

    // Rendered into #customerData by the live-search XHR. The markup
    // below must stay in step with the table rendered further down the
    // page, or a search would repaint the list in a different style.
    //
    // Every value is escaped on the way out. The previous version echoed
    // the customer name raw, so a name containing markup was injected
    // into the page on every search.
    if ($rows) {
        echo '<div class="ui-table-wrap">
                <table class="inv-table" id="customersTable">
                    <thead>
                        <tr>
                            <th style="width:64px;" class="ui-col-optional">No</th>
                            <th>Customer</th>
                            <th>Phone number</th>
                            <th>TIN</th>
                            <th style="width:110px;"><span class="visually-hidden">Actions</span></th>
                        </tr>
                    </thead>
                    <tbody>';

        $no = 1;
        foreach ($rows as $row) {
            $name    = htmlspecialchars($row['name'], ENT_QUOTES);
            $phone   = htmlspecialchars($row['phone_number'], ENT_QUOTES);
            $address = htmlspecialchars($row['address'] ?? '', ENT_QUOTES);
            $tin     = htmlspecialchars($row['tin'] ?? '', ENT_QUOTES);
            $email   = htmlspecialchars($row['email'] ?? '', ENT_QUOTES);
            $id      = (int)$row['id'];
            $searchHay = htmlspecialchars(strtolower($row['name'] . ' ' . $row['phone_number']), ENT_QUOTES);

            echo '<tr data-search="' . $searchHay . '">
                    <td class="ui-col-optional"><span class="ui-code">' . $no++ . '</span></td>
                    <td style="font-weight:500;">' . $name . '</td>
                    <td><span class="ui-num">' . $phone . '</span></td>
                    <td>' . ($tin !== '' ? $tin : '<span class="text-muted">—</span>') . '</td>
                    <td>
                        <div class="d-flex gap-1 justify-content-end">
                            <button type="button" class="ui-btn ui-btn-secondary ui-btn-sm ui-btn-icon"
                                    title="Edit ' . $name . '" aria-label="Edit ' . $name . '"
                                    onclick="openEditModal(' . $id . ', this.dataset.name, this.dataset.phone, this.dataset.address, this.dataset.tin, this.dataset.email)"
                                    data-name="' . $name . '" data-phone="' . $phone . '"
                                    data-address="' . $address . '" data-tin="' . $tin . '" data-email="' . $email . '">
                                <i class="fas fa-pen"></i>
                            </button>
                            <button type="button" class="ui-btn ui-btn-danger ui-btn-sm ui-btn-icon"
                                    title="Delete ' . $name . '" aria-label="Delete ' . $name . '"
                                    onclick="confirmDelete(' . $id . ', this.dataset.name)"
                                    data-name="' . $name . '">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </td>
                </tr>';
        }

        echo '</tbody></table></div>';
    } else {
        echo '<div class="ui-empty">
                <i class="fas fa-users"></i>
                <div class="ui-empty-title">No customers found</div>
                <div class="ui-empty-msg">Try a different name or phone number.</div>
                <button type="button" class="ui-btn ui-btn-primary" data-bs-toggle="modal" data-bs-target="#addCustomerModal">
                    <i class="fas fa-user-plus"></i>Add customer
                </button>
            </div>';
    }

    exit; // Terminate script after AJAX response
}
?>

<?php
$pageTitle = 'Customers';
$breadcrumbs = [['Dashboard', 'index.php'], ['Sales'], ['Customers']];
include 'inventory-header.php';

$ph = [
    'title'    => 'Customers',
    'icon'     => 'fa-users',
    'subtitle' => 'Walk-in customers are identified by phone number, which links their sales history.',
    'actions'  => '<button type="button" class="ui-btn ui-btn-primary" data-bs-toggle="modal" data-bs-target="#addCustomerModal">'
                . '<i class="fas fa-user-plus"></i>Add customer</button>',
];
include 'partials/page-header.php';
?>

<?php if (!empty($success_message)): ?>
    <div class="alert alert-success" role="alert"><i class="fas fa-circle-check"></i><div><?php echo htmlspecialchars($success_message); ?></div></div>
<?php endif; ?>
<?php if (!empty($error_message)): ?>
    <div class="alert alert-danger" role="alert"><i class="fas fa-circle-exclamation"></i><div><?php echo $error_message; ?></div></div>
<?php endif; ?>
<?php if (!empty($delete_success)): ?>
    <div class="alert alert-success" role="alert"><i class="fas fa-circle-check"></i><div><?php echo htmlspecialchars($delete_success); ?></div></div>
<?php endif; ?>
<?php if (!empty($delete_error)): ?>
    <div class="alert alert-danger" role="alert"><i class="fas fa-circle-exclamation"></i><div><?php echo htmlspecialchars($delete_error); ?></div></div>
<?php endif; ?>
<?php if (isset($_GET['added'])): ?>
    <div class="alert alert-success" role="alert"><i class="fas fa-circle-check"></i><div>Customer added.</div></div>
<?php endif; ?>
<?php if (isset($_GET['updated'])): ?>
    <div class="alert alert-success" role="alert"><i class="fas fa-circle-check"></i><div>Customer updated.</div></div>
<?php endif; ?>
<?php if (isset($_GET['deleted'])): ?>
    <div class="alert alert-success" role="alert"><i class="fas fa-circle-check"></i><div>Customer deleted.</div></div>
<?php endif; ?>

<div class="inv-card p-0">
    <div class="ui-card-head">
        <h2 class="ui-card-title">Customer directory</h2>
        <div class="ui-toolbar mb-0">
            <!-- Live search: types into the same ?ajax_search endpoint the
                 page has always used, repainting #customerData. -->
            <div class="ui-search <?php echo $search !== '' ? 'has-value' : ''; ?>">
                <i class="fas fa-magnifying-glass"></i>
                <input type="search" id="searchInput" value="<?php echo htmlspecialchars($search); ?>"
                       placeholder="Search name or phone number…" aria-label="Search customers"
                       autocomplete="off">
                <button type="button" class="ui-search-clear" aria-label="Clear search"><i class="fas fa-xmark"></i></button>
            </div>
            <span class="ui-spinner" id="searchSpinner" style="display:none;"></span>
        </div>
    </div>

    <!-- Replaced wholesale by the live search; the initial render below
         uses exactly the same markup the AJAX response produces. -->
    <div id="customerData">
        <?php if ($customers): ?>
        <div class="ui-table-wrap">
            <table class="inv-table" id="customersTable">
                <thead>
                    <tr>
                        <th style="width:64px;" class="ui-col-optional">No</th>
                        <th>Customer</th>
                        <th>Phone number</th>
                        <th>TIN</th>
                        <th style="width:110px;"><span class="visually-hidden">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    <?php $no = 1; foreach ($customers as $row):
                        $cName    = htmlspecialchars($row['name'], ENT_QUOTES);
                        $cPhone   = htmlspecialchars($row['phone_number'], ENT_QUOTES);
                        $cAddress = htmlspecialchars($row['address'] ?? '', ENT_QUOTES);
                        $cTin     = htmlspecialchars($row['tin'] ?? '', ENT_QUOTES);
                        $cEmail   = htmlspecialchars($row['email'] ?? '', ENT_QUOTES);
                        $searchHay = htmlspecialchars(strtolower($row['name'] . ' ' . $row['phone_number']), ENT_QUOTES);
                    ?>
                    <tr data-search="<?php echo $searchHay; ?>">
                        <td class="ui-col-optional"><span class="ui-code"><?php echo $no++; ?></span></td>
                        <td style="font-weight:500;"><?php echo $cName; ?></td>
                        <td><span class="ui-num"><?php echo $cPhone; ?></span></td>
                        <td><?php echo $cTin !== '' ? $cTin : '<span class="text-muted">—</span>'; ?></td>
                        <td>
                            <div class="d-flex gap-1 justify-content-end">
                                <button type="button" class="ui-btn ui-btn-secondary ui-btn-sm ui-btn-icon"
                                        title="Edit <?php echo $cName; ?>" aria-label="Edit <?php echo $cName; ?>"
                                        data-name="<?php echo $cName; ?>" data-phone="<?php echo $cPhone; ?>"
                                        data-address="<?php echo $cAddress; ?>" data-tin="<?php echo $cTin; ?>" data-email="<?php echo $cEmail; ?>"
                                        onclick="openEditModal(<?php echo (int)$row['id']; ?>, this.dataset.name, this.dataset.phone, this.dataset.address, this.dataset.tin, this.dataset.email)">
                                    <i class="fas fa-pen"></i>
                                </button>
                                <button type="button" class="ui-btn ui-btn-danger ui-btn-sm ui-btn-icon"
                                        title="Delete <?php echo $cName; ?>" aria-label="Delete <?php echo $cName; ?>"
                                        data-name="<?php echo $cName; ?>"
                                        onclick="confirmDelete(<?php echo (int)$row['id']; ?>, this.dataset.name)">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
            <?php
            $es = [
                'icon'   => 'fa-users',
                'title'  => $search !== '' ? 'No customers match that search' : 'No customers yet',
                'msg'    => $search !== '' ? 'Try a different name or phone number.'
                                           : 'Customers are added here, or automatically at the till when a phone number is entered.',
                'action' => '<button type="button" class="ui-btn ui-btn-primary" data-bs-toggle="modal" data-bs-target="#addCustomerModal">'
                          . '<i class="fas fa-user-plus"></i>Add customer</button>',
            ];
            include 'partials/empty-state.php';
            ?>
        <?php endif; ?>
    </div>
</div>

<!-- ============ Add customer ============ -->
<div class="modal fade" id="addCustomerModal" tabindex="-1" aria-labelledby="addCustomerModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="" data-ui-loading>
<?php echo csrfField(); ?>
                <div class="modal-header">
                    <h5 class="modal-title" id="addCustomerModalLabel">
                        <i class="fas fa-user-plus me-2" style="color:var(--color-primary);"></i>Add customer
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label" for="add_name">Name<span class="ui-required">*</span></label>
                        <input type="text" class="form-control" id="add_name" name="name" required maxlength="150">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="add_phone">Phone number<span class="ui-required">*</span></label>
                        <input type="text" class="form-control" id="add_phone" name="phone_number" required maxlength="30"
                               placeholder="07XX XXX XXX" inputmode="tel">
                        <div class="form-text">Saved as +255… — this is how the customer is identified at the till.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="add_address">Address <span class="text-muted">(optional)</span></label>
                        <input type="text" class="form-control" id="add_address" name="address" maxlength="255">
                    </div>
                    <div class="row g-2 mb-0">
                        <div class="col-6">
                            <label class="form-label" for="add_tin">TIN <span class="text-muted">(optional)</span></label>
                            <input type="text" class="form-control" id="add_tin" name="tin" maxlength="30">
                        </div>
                        <div class="col-6">
                            <label class="form-label" for="add_email">Email <span class="text-muted">(optional)</span></label>
                            <input type="email" class="form-control" id="add_email" name="email" maxlength="120">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="ui-btn ui-btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="add_customer" class="ui-btn ui-btn-primary"><i class="fas fa-check"></i>Save customer</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============ Edit customer ============ -->
<div class="modal fade" id="editModal" tabindex="-1" aria-labelledby="editModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="" data-ui-loading>
<?php echo csrfField(); ?>
                <div class="modal-header">
                    <h5 class="modal-title" id="editModalLabel">
                        <i class="fas fa-pen me-2" style="color:var(--color-primary);"></i>Edit customer
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="id" id="edit_id">
                    <div class="mb-3">
                        <label class="form-label" for="edit_name">Name<span class="ui-required">*</span></label>
                        <input type="text" class="form-control" id="edit_name" name="name" required maxlength="150">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="edit_phone_number">Phone number<span class="ui-required">*</span></label>
                        <input type="text" class="form-control" id="edit_phone_number" name="phone_number" required maxlength="30" inputmode="tel">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="edit_address">Address <span class="text-muted">(optional)</span></label>
                        <input type="text" class="form-control" id="edit_address" name="address" maxlength="255">
                    </div>
                    <div class="row g-2 mb-0">
                        <div class="col-6">
                            <label class="form-label" for="edit_tin">TIN <span class="text-muted">(optional)</span></label>
                            <input type="text" class="form-control" id="edit_tin" name="tin" maxlength="30">
                        </div>
                        <div class="col-6">
                            <label class="form-label" for="edit_email">Email <span class="text-muted">(optional)</span></label>
                            <input type="email" class="form-control" id="edit_email" name="email" maxlength="120">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="ui-btn ui-btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_customer" class="ui-btn ui-btn-primary"><i class="fas fa-check"></i>Save changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============ Delete customer ============ -->
<div class="modal fade ui-modal-danger" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="deleteModalLabel">
                    <i class="fas fa-triangle-exclamation me-2"></i>Delete customer
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="ui-body mb-0">
                    Delete <strong id="customerName"></strong>? This cannot be undone.
                </p>
                <p class="ui-caption mt-2 mb-0">
                    A customer with recorded sales cannot be deleted — their receipts would lose the name they were issued to.
                </p>
            </div>
            <div class="modal-footer">
                <form method="POST" action="" class="d-flex gap-2 w-100 justify-content-end" data-ui-loading>
<?php echo csrfField(); ?>
                    <input type="hidden" name="delete_id" id="deleteId">
                    <button type="button" class="ui-btn ui-btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="ui-btn ui-btn-danger-solid"><i class="fas fa-trash"></i>Delete</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php
$pageScript = <<<'HTML'
<script>
function openEditModal(id, name, phone_number, address, tin, email) {
    document.getElementById('edit_id').value = id;
    document.getElementById('edit_name').value = name;
    document.getElementById('edit_phone_number').value = phone_number;
    document.getElementById('edit_address').value = address || '';
    document.getElementById('edit_tin').value = tin || '';
    document.getElementById('edit_email').value = email || '';
    bootstrap.Modal.getOrCreateInstance(document.getElementById('editModal')).show();
}

function confirmDelete(id, name) {
    document.getElementById('deleteId').value = id;
    document.getElementById('customerName').textContent = name;
    bootstrap.Modal.getOrCreateInstance(document.getElementById('deleteModal')).show();
}

document.addEventListener('DOMContentLoaded', function () {
    const searchInput = document.getElementById('searchInput');
    const spinner     = document.getElementById('searchSpinner');
    if (!searchInput) { return; }

    let timer = null;

    // Live search against the page's existing ?ajax_search endpoint.
    // Unchanged contract - only the markup it returns was restyled.
    function fetchCustomers(term) {
        if (spinner) { spinner.style.display = 'inline-block'; }

        const xhr = new XMLHttpRequest();
        xhr.open('GET', 'manage-customers.php?ajax_search=1&search=' + encodeURIComponent(term), true);
        xhr.onload = function () {
            if (xhr.status === 200) {
                document.getElementById('customerData').innerHTML = xhr.responseText;
            }
            if (spinner) { spinner.style.display = 'none'; }
        };
        xhr.onerror = function () {
            if (spinner) { spinner.style.display = 'none'; }
            if (window.MX) { MX.toast('Could not load customers - check your connection.', 'danger'); }
        };
        xhr.send();
    }

    searchInput.addEventListener('input', function () {
        clearTimeout(timer);
        timer = setTimeout(function () { fetchCustomers(searchInput.value); }, 250);
    });

    // Escape clears the field and restores the full list.
    searchInput.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            searchInput.value = '';
            searchInput.dispatchEvent(new Event('input', { bubbles: true }));
        }
    });
});
</script>
HTML;
include 'inventory-footer.php';
?>
