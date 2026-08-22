<?php
// ============================================================
// Setup - describe the business to the system.
// ------------------------------------------------------------
// This application is a general retail management and POS system.
// It does not know, and must not assume, what kind of shop it is
// running. This wizard is where an administrator says so: the type
// of business, its identity, and the structure it organises stock
// by (departments, categories, units, locations, suppliers,
// payment methods, receipt).
//
// TWO THINGS IT DELIBERATELY DOES NOT DO
//
//  * It never resets anything. Every step is additive. An existing
//    shop can open this page to fill in what it never configured,
//    and its products, stock, sales and books are untouched.
//
//  * It never forces a structure. A business type only SUGGESTS
//    departments and categories; each is editable, removable and
//    reorderable before, during and after setup. Finishing setup
//    locks nothing - the same screens stay available afterwards.
//
// An installation that already has products or sales is marked
// complete automatically (businessSyncSetupState), so an upgrade
// never drops a working shop into a wizard.
// ============================================================
require_once '../includes/auth.php';
requireModule('shop_settings');   // administrator only - no new permission
csrfRequire();
$current_page = basename($_SERVER['PHP_SELF']);
include '../includes/db.php';
require_once '../includes/pos_functions.php';
require_once '../includes/business_types.php';
posBoot($conn);

$userId = (int)($_SESSION['id'] ?? 0);

// Steps, in order. The keys are what appears in the URL.
$steps = [
    1  => ['key' => 'type',       'label' => 'Business type',   'icon' => 'fa-shop'],
    2  => ['key' => 'info',       'label' => 'Business info',   'icon' => 'fa-id-card'],
    3  => ['key' => 'departments','label' => 'Departments',     'icon' => 'fa-store'],
    4  => ['key' => 'categories', 'label' => 'Categories',      'icon' => 'fa-folder-tree'],
    5  => ['key' => 'units',      'label' => 'Units',           'icon' => 'fa-ruler'],
    6  => ['key' => 'locations',  'label' => 'Locations',       'icon' => 'fa-warehouse'],
    7  => ['key' => 'suppliers',  'label' => 'Suppliers',       'icon' => 'fa-truck-field'],
    8  => ['key' => 'payments',   'label' => 'Payment methods', 'icon' => 'fa-money-bill-transfer'],
    9  => ['key' => 'receipt',    'label' => 'Receipt',         'icon' => 'fa-receipt'],
    10 => ['key' => 'review',     'label' => 'Review',          'icon' => 'fa-clipboard-check'],
];

$step = max(1, min(10, (int)($_GET['step'] ?? 1)));

// ------------------------------------------------------------------
// Handlers. Each saves its own step and moves on; nothing is deleted.
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $next   = (int)($_POST['next'] ?? ($step + 1));
    $flash  = null;

    if ($action === 'save_type') {
        $type  = (string)($_POST['business_type'] ?? '');
        $other = trim((string)($_POST['business_type_other'] ?? ''));
        if (!businessType($type)) { $type = 'general_retail'; }
        setInvSetting($conn, 'shop_business_type', $type);
        setInvSetting($conn, 'shop_business_type_other', $type === 'other' ? $other : '');

        // Suggested structure, only if asked for. Additive.
        if (($_POST['apply_template'] ?? '') === '1') {
            $made = businessApplyTemplate($conn, $type, true, true);
            $flash = ['success', 'Suggested structure added: ' . $made['departments'] . ' department(s), '
                     . $made['categories'] . ' category(ies), ' . $made['units'] . ' unit(s). '
                     . 'Change anything you do not want.'];
        }

    } elseif ($action === 'save_info') {
        $fields = ['shop_name','shop_trading_name','shop_description','shop_tagline',
                   'shop_street','shop_city','shop_region','shop_country','shop_address',
                   'shop_phone','shop_phone_alt','shop_whatsapp','shop_email','shop_website',
                   'shop_tin','shop_vrn'];
        foreach ($fields as $f) {
            if (array_key_exists($f, $_POST)) {
                setInvSetting($conn, $f, trim((string)$_POST[$f]));
            }
        }

    } elseif ($action === 'add_department') {
        [$ok, $msg] = catalogCreateDepartment($conn, (string)($_POST['name'] ?? ''),
                                              (string)($_POST['icon'] ?? ''), (string)($_POST['colour'] ?? ''));
        $flash = [$ok ? 'success' : 'danger', $msg];
        $next  = 3;

    } elseif ($action === 'remove_department') {
        [$ok, $msg] = catalogDeleteDepartment($conn, (string)($_POST['department'] ?? ''));
        $flash = [$ok ? 'success' : 'danger', $msg];
        $next  = 3;

    } elseif ($action === 'add_category') {
        $name = trim((string)($_POST['name'] ?? ''));
        $dept = (string)($_POST['department'] ?? '');
        if ($name === '') {
            $flash = ['danger', 'A category needs a name.'];
        } else {
            if (!isset(catalogDepartments($conn)[$dept])) { $dept = catalogFirstActiveDepartment($conn); }
            $stmt = $conn->prepare("INSERT INTO inv_categories (name, department, is_active) VALUES (?, ?, 1)");
            if ($stmt) {
                $stmt->bind_param('ss', $name, $dept);
                $flash = $stmt->execute()
                    ? ['success', '"' . $name . '" added.']
                    : ['danger', 'There is already a category with that name.'];
                $stmt->close();
            }
        }
        $next = 4;

    } elseif ($action === 'add_unit') {
        $name = trim((string)($_POST['name'] ?? ''));
        $abbr = trim((string)($_POST['abbreviation'] ?? ''));
        if ($name === '' || $abbr === '') {
            $flash = ['danger', 'A unit needs both a name and a short form.'];
        } else {
            $stmt = $conn->prepare("INSERT INTO inv_units (name, abbreviation, is_active) VALUES (?, ?, 1)");
            if ($stmt) {
                $stmt->bind_param('ss', $name, $abbr);
                $flash = $stmt->execute()
                    ? ['success', '"' . $name . '" added.']
                    : ['danger', 'There is already a unit with that name.'];
                $stmt->close();
            }
        }
        $next = 5;

    } elseif ($action === 'add_location') {
        $name = trim((string)($_POST['name'] ?? ''));
        $type = in_array($_POST['type'] ?? '', ['branch','warehouse','storage'], true) ? $_POST['type'] : 'branch';
        $addr = trim((string)($_POST['address'] ?? ''));
        if ($name === '') {
            $flash = ['danger', 'A location needs a name.'];
        } else {
            $stmt = $conn->prepare("INSERT INTO inv_locations (name, type, address, is_active) VALUES (?, ?, ?, 1)");
            if ($stmt) {
                $stmt->bind_param('sss', $name, $type, $addr);
                $flash = $stmt->execute()
                    ? ['success', '"' . $name . '" added.']
                    : ['danger', 'There is already a location with that name.'];
                $stmt->close();
            }
        }
        $next = 6;

    } elseif ($action === 'add_supplier') {
        $name  = trim((string)($_POST['name'] ?? ''));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $person= trim((string)($_POST['contact_person'] ?? ''));
        if ($name === '') {
            $flash = ['danger', 'A supplier needs a name.'];
        } else {
            $stmt = $conn->prepare("INSERT INTO inv_suppliers (name, contact_person, phone, is_active) VALUES (?, ?, ?, 1)");
            if ($stmt) {
                $stmt->bind_param('sss', $name, $person, $phone);
                $flash = $stmt->execute() ? ['success', '"' . $name . '" added.'] : ['danger', 'The supplier could not be saved.'];
                $stmt->close();
            }
        }
        $next = 7;

    } elseif ($action === 'add_payment') {
        $provider = trim((string)($_POST['provider'] ?? ''));
        $type     = (string)($_POST['payment_type'] ?? 'mobile_money');
        $number   = trim((string)($_POST['payment_number'] ?? ''));
        $accName  = trim((string)($_POST['account_name'] ?? ''));
        if (!isset(shopPaymentTypes()[$type])) { $type = 'mobile_money'; }
        if ($provider === '') {
            $flash = ['danger', 'A payment method needs a name.'];
        } else {
            // Next position, read first: MySQL will not let an INSERT
            // select from the table it is inserting into.
            $sort = 1;
            $res  = @$conn->query("SELECT COALESCE(MAX(sort_order), 0) + 1 AS n FROM shop_payment_methods");
            if ($res instanceof mysqli_result) { $sort = (int)($res->fetch_assoc()['n'] ?? 1); $res->free(); }

            $stmt = $conn->prepare("INSERT INTO shop_payment_methods
                                      (provider, payment_type, payment_number, account_name, is_enabled, sort_order)
                                    VALUES (?, ?, ?, ?, 1, ?)");
            if ($stmt) {
                $stmt->bind_param('ssssi', $provider, $type, $number, $accName, $sort);
                $flash = $stmt->execute() ? ['success', '"' . $provider . '" added.'] : ['danger', 'The payment method could not be saved.'];
                $stmt->close();
            }
        }
        $next = 8;

    } elseif ($action === 'save_receipt') {
        setInvSetting($conn, 'receipt_header', trim((string)($_POST['receipt_header'] ?? '')));
        setInvSetting($conn, 'pos_receipt_footer', trim((string)($_POST['pos_receipt_footer'] ?? '')));
        foreach (shopSettingToggles() as $t) {
            setInvSetting($conn, $t, isset($_POST[$t]) ? '1' : '0');
        }

    } elseif ($action === 'complete') {
        businessMarkSetupComplete($conn);
        invAudit($conn, $userId, 'setup_complete', 'setup', null, businessTypeLabel($conn));
        retailFlash('success', 'Setup complete. Everything here stays editable from Administration.');
        header('Location: index.php'); exit;
    }

    if ($flash) { retailFlash($flash[0], $flash[1]); }
    header('Location: setup.php?step=' . max(1, min(10, $next)));
    exit;
}

// ------------------------------------------------------------------
// Read the current state for whichever step is showing.
// ------------------------------------------------------------------
$settings     = shopSettingsAll($conn);
$typeKey      = (string)($settings['shop_business_type'] ?? '');
$departments  = catalogDepartments($conn);
$activeDepts  = catalogActiveDepartments($conn);
$isConfigured = businessSetupComplete($conn);
$hasData      = businessHasOperationalData($conn);

$categories = [];
$res = @$conn->query("SELECT id, name, department, is_active FROM inv_categories WHERE deleted_at IS NULL ORDER BY department, name");
if ($res instanceof mysqli_result) { $categories = $res->fetch_all(MYSQLI_ASSOC); }

$units = [];
$res = @$conn->query("SELECT id, name, abbreviation, is_active FROM inv_units WHERE deleted_at IS NULL ORDER BY name");
if ($res instanceof mysqli_result) { $units = $res->fetch_all(MYSQLI_ASSOC); }

$locations = [];
$res = @$conn->query("SELECT id, name, type, address FROM inv_locations WHERE deleted_at IS NULL ORDER BY name");
if ($res instanceof mysqli_result) { $locations = $res->fetch_all(MYSQLI_ASSOC); }

$suppliers = [];
$res = @$conn->query("SELECT id, name, phone, contact_person FROM inv_suppliers WHERE deleted_at IS NULL AND is_active = 1 ORDER BY name");
if ($res instanceof mysqli_result) { $suppliers = $res->fetch_all(MYSQLI_ASSOC); }

$payments = shopPaymentMethods($conn, false);
$types    = businessTypeCatalogue();

$pageTitle   = 'Setup';
$breadcrumbs = [['Dashboard', 'index.php'], ['Setup']];
include 'inventory-header.php';

function setupPill(bool $done): string {
    return $done
        ? '<span class="ui-badge ui-badge-success"><i class="fas fa-check me-1"></i>Configured</span>'
        : '<span class="ui-badge ui-badge-muted">Not set</span>';
}
?>

<div class="inv-page-header">
    <div>
        <h1><i class="fas fa-wand-magic-sparkles me-2" style="color:var(--inv-primary);"></i>Setup</h1>
        <div class="subtitle">Describe the business, and the system organises itself around it.</div>
    </div>
    <div>
        <a href="index.php" class="btn btn-outline-secondary" style="border-radius:10px;">
            <i class="fas fa-xmark me-1"></i>Leave setup
        </a>
    </div>
</div>

<?php if ($hasData): ?>
<div class="alert alert-info" style="border-radius:12px;font-size:.86rem;">
    <i class="fas fa-circle-info me-1"></i>
    <strong>This shop is already operating.</strong> Nothing on these screens resets or deletes anything &mdash;
    every step only adds to what you already have. Use it to fill in whatever was never configured.
</div>
<?php endif; ?>

<!-- Step rail. Scrolls sideways on a phone rather than wrapping into a wall. -->
<div class="inv-card p-2 mb-3" style="overflow-x:auto;">
    <div class="d-flex gap-1" style="min-width:max-content;">
        <?php foreach ($steps as $n => $s): ?>
            <a href="setup.php?step=<?php echo $n; ?>"
               class="btn btn-sm <?php echo $n === $step ? 'btn-inv' : 'btn-outline-secondary'; ?>"
               style="border-radius:9px;white-space:nowrap;">
                <i class="fas <?php echo $s['icon']; ?> me-1"></i>
                <span class="d-none d-md-inline"><?php echo $n; ?>. </span><?php echo htmlspecialchars($s['label']); ?>
            </a>
        <?php endforeach; ?>
    </div>
</div>

<?php // ---------------------------------------------------------- STEP 1 ?>
<?php if ($step === 1): ?>
<div class="inv-card p-4">
    <h5 class="fw-bold mb-1">What type of business is this?</h5>
    <p class="text-muted" style="font-size:.86rem;">
        This is a classification. It suggests a starting structure and labels the shop &mdash; it does
        <strong>not</strong> change how the system works. Every business type uses the same till, the same
        stock control and the same books.
    </p>
    <form method="post">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="save_type">
        <div class="row g-2">
            <?php foreach ($types as $key => $t): ?>
            <div class="col-lg-4 col-md-6">
                <label class="inv-card p-3 h-100 d-flex gap-2 align-items-start" style="cursor:pointer;<?php echo $key === $typeKey ? 'border:2px solid var(--inv-primary);' : ''; ?>">
                    <input type="radio" name="business_type" value="<?php echo htmlspecialchars($key); ?>"
                           class="form-check-input mt-1" <?php echo $key === $typeKey ? 'checked' : ''; ?>
                           <?php echo ($typeKey === '' && $key === 'general_retail') ? 'checked' : ''; ?>>
                    <div>
                        <div class="fw-bold"><i class="fas <?php echo $t['icon']; ?> me-1" style="color:var(--inv-primary);"></i><?php echo htmlspecialchars($t['label']); ?></div>
                        <div class="text-muted" style="font-size:.8rem;"><?php echo htmlspecialchars($t['description']); ?></div>
                        <?php if (!empty($t['note'])): ?>
                            <div class="text-warning-emphasis mt-1" style="font-size:.76rem;">
                                <i class="fas fa-triangle-exclamation me-1"></i><?php echo htmlspecialchars($t['note']); ?>
                            </div>
                        <?php endif; ?>
                        <?php if ($t['departments']): ?>
                            <div class="text-muted mt-1" style="font-size:.76rem;">
                                Suggests: <?php echo htmlspecialchars(implode(', ', array_column($t['departments'], 0))); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </label>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="mt-3">
            <label class="form-label" for="business_type_other">If you chose "Other", what is this business called?</label>
            <input type="text" class="form-control" id="business_type_other" name="business_type_other"
                   maxlength="60" placeholder="e.g. Building Materials Shop"
                   value="<?php echo htmlspecialchars($settings['shop_business_type_other'] ?? ''); ?>">
        </div>

        <div class="form-check mt-3">
            <input class="form-check-input" type="checkbox" id="apply_template" name="apply_template" value="1"
                   <?php echo $hasData ? '' : 'checked'; ?>>
            <label class="form-check-label" for="apply_template">
                Add the suggested departments, categories and units for this type.
                <span class="text-muted d-block" style="font-size:.8rem;">
                    Additive only &mdash; nothing you already have is renamed or removed, and you can change
                    everything it creates in the next steps.
                </span>
            </label>
        </div>

        <div class="d-flex justify-content-end gap-2 mt-4">
            <button class="btn btn-inv">Save and continue <i class="fas fa-arrow-right ms-1"></i></button>
        </div>
    </form>
</div>

<?php // ---------------------------------------------------------- STEP 2 ?>
<?php elseif ($step === 2): ?>
<div class="inv-card p-4">
    <h5 class="fw-bold mb-1">Business information</h5>
    <p class="text-muted" style="font-size:.86rem;">
        This is what appears on receipts, reports and the sign-in screen. The logo is uploaded on the
        <a href="shop-settings.php">Shop settings</a> screen.
    </p>
    <form method="post">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="save_info">
        <div class="row g-3">
            <?php
            $infoFields = [
                ['shop_name', 'Business name', 'text', 'The name customers know'],
                ['shop_trading_name', 'Trading name', 'text', 'If it trades under a different name'],
                ['shop_tagline', 'Tagline', 'text', 'Shown under the name in the sidebar'],
                ['shop_description', 'Description', 'text', 'One line about the business'],
                ['shop_street', 'Street', 'text', ''],
                ['shop_city', 'City', 'text', ''],
                ['shop_region', 'Region', 'text', ''],
                ['shop_country', 'Country', 'text', ''],
                ['shop_phone', 'Phone', 'text', ''],
                ['shop_phone_alt', 'Alternative phone', 'text', ''],
                ['shop_whatsapp', 'WhatsApp', 'text', ''],
                ['shop_email', 'Email', 'email', ''],
                ['shop_website', 'Website', 'text', ''],
                ['shop_tin', 'TIN', 'text', 'Taxpayer identification number'],
                ['shop_vrn', 'VRN', 'text', 'VAT registration number, if registered'],
            ];
            foreach ($infoFields as [$name, $label, $type, $hint]): ?>
            <div class="col-md-6">
                <label class="form-label" for="<?php echo $name; ?>"><?php echo htmlspecialchars($label); ?></label>
                <input type="<?php echo $type; ?>" class="form-control" id="<?php echo $name; ?>" name="<?php echo $name; ?>"
                       value="<?php echo htmlspecialchars((string)($settings[$name] ?? '')); ?>">
                <?php if ($hint !== ''): ?><div class="form-text"><?php echo htmlspecialchars($hint); ?></div><?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="d-flex justify-content-between gap-2 mt-4">
            <a href="setup.php?step=1" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i>Back</a>
            <button class="btn btn-inv">Save and continue <i class="fas fa-arrow-right ms-1"></i></button>
        </div>
    </form>
</div>

<?php // ---------------------------------------------------------- STEP 3 ?>
<?php elseif ($step === 3): ?>
<div class="inv-card p-4">
    <h5 class="fw-bold mb-1">Departments</h5>
    <p class="text-muted" style="font-size:.86rem;">
        How this shop divides what it sells. There is no fixed list &mdash; a hardware shop might use
        Plumbing, Electrical and Tools; a duka la dawa Medicines and Personal Care.
        <strong>At least one is required</strong>, because every product belongs to one.
    </p>

    <div class="row g-2 mb-3">
        <?php foreach ($departments as $key => $meta):
            $refs  = catalogDepartmentReferences($conn, $key);
            $inUse = array_sum($refs) > 0; ?>
        <div class="col-md-6 col-lg-4">
            <div class="inv-card p-3 d-flex justify-content-between align-items-center" style="border-left:4px solid <?php echo htmlspecialchars($meta['colour']); ?>;">
                <div>
                    <i class="fas <?php echo htmlspecialchars($meta['icon']); ?> me-1" style="color:<?php echo htmlspecialchars($meta['colour']); ?>;"></i>
                    <strong><?php echo htmlspecialchars($meta['label']); ?></strong>
                    <?php if (empty($meta['active'])): ?><span class="ui-badge ui-badge-muted ms-1">disabled</span><?php endif; ?>
                    <div class="text-muted" style="font-size:.76rem;">
                        <?php echo $inUse ? 'In use (' . (int)$refs['product(s)'] . ' product(s))' : 'Not used yet'; ?>
                    </div>
                </div>
                <?php if (!$inUse && count($departments) > 1): ?>
                <form method="post" onsubmit="return confirm('Remove <?php echo htmlspecialchars($meta['label'], ENT_QUOTES); ?>?');">
                    <?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="remove_department">
                    <input type="hidden" name="department" value="<?php echo htmlspecialchars($key); ?>">
                    <button class="btn btn-sm btn-link text-danger p-0" title="Remove"><i class="fas fa-trash"></i></button>
                </form>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <form method="post" class="row g-2 align-items-end">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="add_department">
        <div class="col-md-5">
            <label class="form-label" for="dept_name">Add a department</label>
            <input type="text" class="form-control" id="dept_name" name="name" maxlength="60" required
                   placeholder="e.g. Beverages">
        </div>
        <div class="col-md-3">
            <label class="form-label" for="dept_icon">Icon</label>
            <select class="form-select" id="dept_icon" name="icon">
                <?php foreach (['fa-boxes-stacked','fa-basket-shopping','fa-bowl-food','fa-bottle-water','fa-house',
                                'fa-pump-soap','fa-pills','fa-briefcase-medical','fa-pen','fa-book','fa-laptop',
                                'fa-bolt','fa-faucet','fa-screwdriver-wrench','fa-shirt'] as $ic): ?>
                    <option value="<?php echo $ic; ?>"><?php echo str_replace('fa-', '', $ic); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label" for="dept_colour">Colour</label>
            <select class="form-select" id="dept_colour" name="colour">
                <?php foreach (['#42c3cf','#22c55e','#f6c23e','#ec4899','#8b5cf6','#ef4444','#38bdf8','#8b9aa2'] as $c): ?>
                    <option value="<?php echo $c; ?>"><?php echo $c; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <button class="btn btn-outline-secondary w-100" style="border-radius:10px;"><i class="fas fa-plus me-1"></i>Add</button>
        </div>
    </form>

    <div class="d-flex justify-content-between gap-2 mt-4">
        <a href="setup.php?step=2" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i>Back</a>
        <a href="setup.php?step=4" class="btn btn-inv">Continue <i class="fas fa-arrow-right ms-1"></i></a>
    </div>
</div>

<?php // ---------------------------------------------------------- STEP 4 ?>
<?php elseif ($step === 4): ?>
<div class="inv-card p-4">
    <h5 class="fw-bold mb-1">Categories</h5>
    <p class="text-muted" style="font-size:.86rem;">
        Categories group products inside a department &mdash; Pens inside Writing Materials, Switches
        inside Electrical. Full editing lives on <a href="inventory-categories.php">Categories</a>.
    </p>

    <?php if ($categories): ?>
    <div class="table-responsive mb-3">
        <table class="inv-table">
            <thead><tr><th>Category</th><th>Department</th></tr></thead>
            <tbody>
            <?php foreach ($categories as $c): ?>
                <tr>
                    <td><?php echo htmlspecialchars($c['name']); ?></td>
                    <td><span class="ui-badge ui-badge-muted"><?php echo htmlspecialchars(catalogDepartmentLabel($c['department'])); ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div class="alert alert-light" style="border-radius:10px;font-size:.85rem;">
        No categories yet. A product does not strictly need one, but stock is much easier to read with them.
    </div>
    <?php endif; ?>

    <form method="post" class="row g-2 align-items-end">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="add_category">
        <div class="col-md-5">
            <label class="form-label" for="cat_name">Add a category</label>
            <input type="text" class="form-control" id="cat_name" name="name" maxlength="100" required placeholder="e.g. Pens">
        </div>
        <div class="col-md-5">
            <label class="form-label" for="cat_dept">In department</label>
            <select class="form-select" id="cat_dept" name="department">
                <?php foreach ($departments as $key => $meta): ?>
                    <option value="<?php echo htmlspecialchars($key); ?>">
                        <?php echo htmlspecialchars($meta['label']); ?><?php echo empty($meta['active']) ? ' (disabled)' : ''; ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <button class="btn btn-outline-secondary w-100" style="border-radius:10px;"><i class="fas fa-plus me-1"></i>Add</button>
        </div>
    </form>

    <div class="d-flex justify-content-between gap-2 mt-4">
        <a href="setup.php?step=3" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i>Back</a>
        <a href="setup.php?step=5" class="btn btn-inv">Continue <i class="fas fa-arrow-right ms-1"></i></a>
    </div>
</div>

<?php // ---------------------------------------------------------- STEP 5 ?>
<?php elseif ($step === 5): ?>
<div class="inv-card p-4">
    <h5 class="fw-bold mb-1">Units of measure</h5>
    <p class="text-muted" style="font-size:.86rem;">
        How stock is counted: pieces, boxes, kilograms, metres, strips. Every product needs one.
        Full editing lives on <a href="inventory-units.php">Units of measure</a>.
    </p>

    <div class="d-flex flex-wrap gap-2 mb-3">
        <?php foreach ($units as $u): ?>
            <span class="ui-badge ui-badge-muted"><?php echo htmlspecialchars($u['name']); ?> (<?php echo htmlspecialchars($u['abbreviation']); ?>)</span>
        <?php endforeach; ?>
        <?php if (!$units): ?><span class="text-muted" style="font-size:.85rem;">None yet.</span><?php endif; ?>
    </div>

    <form method="post" class="row g-2 align-items-end">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="add_unit">
        <div class="col-md-5">
            <label class="form-label" for="unit_name">Add a unit</label>
            <input type="text" class="form-control" id="unit_name" name="name" maxlength="50" required placeholder="e.g. Cartons">
        </div>
        <div class="col-md-5">
            <label class="form-label" for="unit_abbr">Short form</label>
            <input type="text" class="form-control" id="unit_abbr" name="abbreviation" maxlength="15" required placeholder="e.g. ctn">
        </div>
        <div class="col-md-2">
            <button class="btn btn-outline-secondary w-100" style="border-radius:10px;"><i class="fas fa-plus me-1"></i>Add</button>
        </div>
    </form>

    <div class="d-flex justify-content-between gap-2 mt-4">
        <a href="setup.php?step=4" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i>Back</a>
        <a href="setup.php?step=6" class="btn btn-inv">Continue <i class="fas fa-arrow-right ms-1"></i></a>
    </div>
</div>

<?php // ---------------------------------------------------------- STEP 6 ?>
<?php elseif ($step === 6): ?>
<div class="inv-card p-4">
    <h5 class="fw-bold mb-1">Locations</h5>
    <p class="text-muted" style="font-size:.86rem;">
        Where stock is held &mdash; the shop floor, a store room, a warehouse.
        <strong>Note:</strong> stock is tracked as one quantity per product, not per location, so a
        location is a label rather than a separate stock balance.
    </p>

    <div class="d-flex flex-wrap gap-2 mb-3">
        <?php foreach ($locations as $l): ?>
            <span class="ui-badge ui-badge-muted"><?php echo htmlspecialchars($l['name']); ?> &middot; <?php echo htmlspecialchars($l['type']); ?></span>
        <?php endforeach; ?>
        <?php if (!$locations): ?><span class="text-muted" style="font-size:.85rem;">None yet.</span><?php endif; ?>
    </div>

    <form method="post" class="row g-2 align-items-end">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="add_location">
        <div class="col-md-4">
            <label class="form-label" for="loc_name">Add a location</label>
            <input type="text" class="form-control" id="loc_name" name="name" maxlength="100" required placeholder="e.g. Main Shop">
        </div>
        <div class="col-md-3">
            <label class="form-label" for="loc_type">Type</label>
            <select class="form-select" id="loc_type" name="type">
                <option value="branch">Branch</option>
                <option value="warehouse">Warehouse</option>
                <option value="storage">Storage</option>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label" for="loc_addr">Address</label>
            <input type="text" class="form-control" id="loc_addr" name="address" maxlength="255">
        </div>
        <div class="col-md-2">
            <button class="btn btn-outline-secondary w-100" style="border-radius:10px;"><i class="fas fa-plus me-1"></i>Add</button>
        </div>
    </form>

    <div class="d-flex justify-content-between gap-2 mt-4">
        <a href="setup.php?step=5" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i>Back</a>
        <a href="setup.php?step=7" class="btn btn-inv">Continue <i class="fas fa-arrow-right ms-1"></i></a>
    </div>
</div>

<?php // ---------------------------------------------------------- STEP 7 ?>
<?php elseif ($step === 7): ?>
<div class="inv-card p-4">
    <h5 class="fw-bold mb-1">Suppliers</h5>
    <p class="text-muted" style="font-size:.86rem;">
        Who the shop buys from. Optional now &mdash; a supplier can be added at any time, and is only
        needed when you raise a purchase order. Full editing lives on
        <a href="inventory-suppliers.php">Suppliers</a>.
    </p>

    <div class="d-flex flex-wrap gap-2 mb-3">
        <?php foreach ($suppliers as $sp): ?>
            <span class="ui-badge ui-badge-muted"><?php echo htmlspecialchars($sp['name']); ?></span>
        <?php endforeach; ?>
        <?php if (!$suppliers): ?><span class="text-muted" style="font-size:.85rem;">None yet.</span><?php endif; ?>
    </div>

    <form method="post" class="row g-2 align-items-end">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="add_supplier">
        <div class="col-md-4">
            <label class="form-label" for="sup_name">Add a supplier</label>
            <input type="text" class="form-control" id="sup_name" name="name" maxlength="150" required placeholder="e.g. Iringa Wholesalers">
        </div>
        <div class="col-md-3">
            <label class="form-label" for="sup_person">Contact person</label>
            <input type="text" class="form-control" id="sup_person" name="contact_person" maxlength="100">
        </div>
        <div class="col-md-3">
            <label class="form-label" for="sup_phone">Phone</label>
            <input type="text" class="form-control" id="sup_phone" name="phone" maxlength="30">
        </div>
        <div class="col-md-2">
            <button class="btn btn-outline-secondary w-100" style="border-radius:10px;"><i class="fas fa-plus me-1"></i>Add</button>
        </div>
    </form>

    <div class="d-flex justify-content-between gap-2 mt-4">
        <a href="setup.php?step=6" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i>Back</a>
        <a href="setup.php?step=8" class="btn btn-inv">Continue <i class="fas fa-arrow-right ms-1"></i></a>
    </div>
</div>

<?php // ---------------------------------------------------------- STEP 8 ?>
<?php elseif ($step === 8): ?>
<div class="inv-card p-4">
    <h5 class="fw-bold mb-1">Payment methods</h5>
    <p class="text-muted" style="font-size:.86rem;">
        The payment details printed on receipts &mdash; a Lipa Namba, a bank account, a till number.
        Enabling and reordering lives on <a href="shop-settings.php">Shop settings</a>.
    </p>
    <div class="alert alert-light" style="border-radius:10px;font-size:.82rem;">
        <i class="fas fa-circle-info me-1"></i>
        Separate from how the till <em>records</em> a payment. The POS always offers cash, mobile money,
        bank and card, because each one posts to its own ledger account.
    </div>

    <?php if ($payments): ?>
    <div class="table-responsive mb-3">
        <table class="inv-table">
            <thead><tr><th>Provider</th><th>Type</th><th>Number</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($payments as $pm): ?>
                <tr>
                    <td><?php echo htmlspecialchars($pm['provider']); ?></td>
                    <td><?php echo htmlspecialchars(shopPaymentTypes()[$pm['payment_type']] ?? $pm['payment_type']); ?></td>
                    <td><?php echo htmlspecialchars((string)$pm['payment_number']); ?></td>
                    <td><?php echo ((int)$pm['is_enabled'] === 1) ? '<span class="ui-badge ui-badge-success">Enabled</span>' : '<span class="ui-badge ui-badge-muted">Off</span>'; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <form method="post" class="row g-2 align-items-end">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="add_payment">
        <div class="col-md-3">
            <label class="form-label" for="pm_provider">Add a method</label>
            <input type="text" class="form-control" id="pm_provider" name="provider" maxlength="80" required placeholder="e.g. M-Pesa">
        </div>
        <div class="col-md-3">
            <label class="form-label" for="pm_type">Type</label>
            <select class="form-select" id="pm_type" name="payment_type">
                <?php foreach (shopPaymentTypes() as $k => $label): ?>
                    <option value="<?php echo htmlspecialchars($k); ?>"><?php echo htmlspecialchars($label); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label" for="pm_number">Number</label>
            <input type="text" class="form-control" id="pm_number" name="payment_number" maxlength="60">
        </div>
        <div class="col-md-2">
            <label class="form-label" for="pm_account">Account name</label>
            <input type="text" class="form-control" id="pm_account" name="account_name" maxlength="120">
        </div>
        <div class="col-md-2">
            <button class="btn btn-outline-secondary w-100" style="border-radius:10px;"><i class="fas fa-plus me-1"></i>Add</button>
        </div>
    </form>

    <div class="d-flex justify-content-between gap-2 mt-4">
        <a href="setup.php?step=7" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i>Back</a>
        <a href="setup.php?step=9" class="btn btn-inv">Continue <i class="fas fa-arrow-right ms-1"></i></a>
    </div>
</div>

<?php // ---------------------------------------------------------- STEP 9 ?>
<?php elseif ($step === 9): ?>
<div class="inv-card p-4">
    <h5 class="fw-bold mb-1">Receipt</h5>
    <p class="text-muted" style="font-size:.86rem;">What the customer's receipt shows.</p>
    <form method="post">
        <?php echo csrfField(); ?>
        <input type="hidden" name="action" value="save_receipt">
        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label" for="receipt_header">Header line</label>
                <input type="text" class="form-control" id="receipt_header" name="receipt_header" maxlength="120"
                       value="<?php echo htmlspecialchars((string)($settings['receipt_header'] ?? '')); ?>"
                       placeholder="Printed above the shop name">
            </div>
            <div class="col-md-6">
                <label class="form-label" for="pos_receipt_footer">Footer line</label>
                <input type="text" class="form-control" id="pos_receipt_footer" name="pos_receipt_footer" maxlength="120"
                       value="<?php echo htmlspecialchars(getInvSetting($conn, 'pos_receipt_footer', '')); ?>"
                       placeholder="e.g. Thank you for shopping with us!">
            </div>
        </div>
        <div class="row g-2 mt-2">
            <?php
            $toggleLabels = [
                'receipt_show_logo'    => 'Show the logo',
                'receipt_show_address' => 'Show the address',
                'receipt_show_phone'   => 'Show the phone number',
                'receipt_show_email'   => 'Show the email',
                'receipt_show_tin'     => 'Show the TIN',
                'receipt_show_payment' => 'Show payment instructions',
            ];
            foreach (shopSettingToggles() as $t): ?>
            <div class="col-md-4">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="<?php echo $t; ?>" name="<?php echo $t; ?>" value="1"
                           <?php echo ((string)($settings[$t] ?? '0') === '1') ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="<?php echo $t; ?>"><?php echo htmlspecialchars($toggleLabels[$t] ?? $t); ?></label>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="d-flex justify-content-between gap-2 mt-4">
            <a href="setup.php?step=8" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i>Back</a>
            <button class="btn btn-inv">Save and continue <i class="fas fa-arrow-right ms-1"></i></button>
        </div>
    </form>
</div>

<?php // ---------------------------------------------------------- STEP 10 ?>
<?php else:
    $activeCount   = count($activeDepts);
    $inactiveCount = count($departments) - $activeCount;
    $enabledPay    = 0;
    foreach ($payments as $pm) { if ((int)$pm['is_enabled'] === 1) { $enabledPay++; } }
?>
<div class="inv-card p-4">
    <h5 class="fw-bold mb-3">Review</h5>

    <div class="row g-3">
        <div class="col-md-6">
            <div class="inv-card p-3 h-100">
                <div class="text-muted text-uppercase mb-2" style="font-size:.72rem;letter-spacing:.05em;">Business</div>
                <div><strong><?php echo htmlspecialchars(shopName($conn)); ?></strong></div>
                <div class="text-muted" style="font-size:.85rem;"><?php echo htmlspecialchars(businessTypeLabel($conn)); ?></div>
                <div class="text-muted" style="font-size:.85rem;"><?php echo htmlspecialchars(shopAddressLine($conn)); ?></div>
                <div class="mt-2"><?php echo setupPill(trim((string)($settings['shop_name'] ?? '')) !== ''); ?></div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="inv-card p-3 h-100">
                <div class="text-muted text-uppercase mb-2" style="font-size:.72rem;letter-spacing:.05em;">Structure</div>
                <table class="table table-sm mb-0" style="font-size:.86rem;">
                    <tr><td>Departments</td><td class="text-end"><strong><?php echo $activeCount; ?></strong> active<?php echo $inactiveCount ? ', ' . $inactiveCount . ' inactive' : ''; ?></td></tr>
                    <tr><td>Categories</td><td class="text-end"><strong><?php echo count($categories); ?></strong></td></tr>
                    <tr><td>Units</td><td class="text-end"><strong><?php echo count($units); ?></strong></td></tr>
                    <tr><td>Locations</td><td class="text-end"><strong><?php echo count($locations); ?></strong></td></tr>
                    <tr><td>Suppliers</td><td class="text-end"><strong><?php echo count($suppliers); ?></strong></td></tr>
                    <tr><td>Payment methods</td><td class="text-end"><strong><?php echo $enabledPay; ?></strong> enabled</td></tr>
                </table>
            </div>
        </div>
    </div>

    <div class="mt-3">
        <div class="text-muted text-uppercase mb-2" style="font-size:.72rem;letter-spacing:.05em;">Departments</div>
        <div class="d-flex flex-wrap gap-2">
            <?php foreach ($departments as $key => $meta): ?>
                <span class="ui-badge <?php echo !empty($meta['active']) ? 'ui-badge-success' : 'ui-badge-muted'; ?>">
                    <i class="fas <?php echo htmlspecialchars($meta['icon']); ?> me-1"></i><?php echo htmlspecialchars($meta['label']); ?>
                </span>
            <?php endforeach; ?>
        </div>
    </div>

    <?php if ($activeCount === 0): ?>
    <div class="alert alert-danger mt-3" style="border-radius:10px;font-size:.85rem;">
        No department is active. Products cannot be created until at least one is.
        <a href="setup.php?step=3">Fix that first</a>.
    </div>
    <?php endif; ?>

    <div class="alert alert-light mt-3" style="border-radius:10px;font-size:.84rem;">
        <i class="fas fa-circle-info me-1"></i>
        Finishing setup changes nothing about the data and locks nothing. Every screen here stays
        available under <strong>Administration</strong>, and the structure can be changed whenever the
        business changes.
    </div>

    <div class="d-flex justify-content-between gap-2 mt-4">
        <a href="setup.php?step=9" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i>Back</a>
        <form method="post">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="complete">
            <button class="btn btn-inv" <?php echo $activeCount === 0 ? 'disabled' : ''; ?>>
                <i class="fas fa-check me-1"></i>Complete setup
            </button>
        </form>
    </div>
</div>
<?php endif; ?>

<?php include 'inventory-footer.php'; ?>
