<?php
require_once '../includes/auth.php';
requireRole(allSystemRoles());

// Reject any POST that does not carry this session's CSRF token.
// Placed before every handler on this page, and a no-op on GET.
csrfRequire();

// Set timezone to Africa/Dar_es_Salaam (EAT)
date_default_timezone_set('Africa/Dar_es_Salaam');

$current_page = basename($_SERVER['PHP_SELF']);
include '../includes/db.php'; // Make sure this file defines $conn
require_once '../includes/remember_me.php';
require_once '../includes/uploads.php';

// Get admin data from session
$adminUsername = $_SESSION['username'];
$query_admin = $conn->prepare("SELECT id, username, profile_photo FROM admin WHERE username = ?");
$query_admin->bind_param("s", $adminUsername);
$query_admin->execute();
$result_admin = $query_admin->get_result();

if ($result_admin->num_rows === 0) {
    echo "Admin data not found. Please login again.";
    exit();
}

$adminData = $result_admin->fetch_assoc();
$adminId = $adminData['id'];
$adminName = $adminData['username'];
$adminPhoto = $adminData['profile_photo'];

// Save admin ID to session if not exists
if (!isset($_SESSION['id'])) {
    $_SESSION['id'] = $adminId;
}

// Format full date
function formatFullDate($date_str_input) {
    $day_names = array(
        'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'
    );

    $month_names = array(
        1 => 'January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December'
    );

    $timestamp = strtotime($date_str_input);
    $day_name = $day_names[date('w', $timestamp)];
    $day_num = date('j', $timestamp);
    $month_name = $month_names[date('n', $timestamp)];
    $year_now = date('Y', $timestamp);
    $time_str = date('H:i', $timestamp);

    return "$day_name, $day_num $month_name $year_now $time_str";
}

$current_date_display = formatFullDate(date('Y-m-d H:i:s'));

// Where profile photos live, and the name the DATABASE holds for this
// user's current one.
//
// The filename is deliberately re-read from the database rather than
// taken from $_POST['current_photo']: the posted value used to be
// concatenated straight into an unlink() path, so a request carrying
// "../../../includes/db.php" could delete any file the web server
// could reach. The form still posts the field (the page uses it for
// display), but nothing destructive trusts it any more.
$photoDir = __DIR__ . '/../assets/uploads/profile_admin';

function profileStoredPhoto(mysqli $conn, int $adminId): string {
    $stmt = $conn->prepare("SELECT profile_photo FROM admin WHERE id = ?");
    if (!$stmt) { return ''; }
    $stmt->bind_param('i', $adminId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (string)($row['profile_photo'] ?? '');
}

// Process delete profile photo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_photo'])) {
    $stored = profileStoredPhoto($conn, (int)$adminId);
    if ($stored !== '') {
        uploadDeleteFile($photoDir, $stored);
    }

    // Update database, set profile_photo to NULL
    $update_query = $conn->prepare("UPDATE admin SET profile_photo = NULL WHERE id = ?");
    $update_query->bind_param("i", $adminId);

    if ($update_query->execute()) {
        $success_message = "Profile photo successfully deleted";
        // Refresh page to update data
        header("Location: profile.php?updated=true&message=photo_deleted");
        exit();
    } else {
        $error_message = "Failed to delete profile photo: " . $conn->error;
    }
}

// Process direct photo upload (without crop)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_photo'])) {
    if (!isset($_FILES['profile_photo']) || ($_FILES['profile_photo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        $error_message = "Please select a photo file first";
    } else {
        // Content-checked, size-capped, and named by the server - the
        // extension comes from the type detected in the file, never
        // from the name the browser sent.
        [$ok, $result] = uploadStoreImage($_FILES['profile_photo'], $photoDir, 'admin');

        if (!$ok) {
            $error_message = $result;
        } else {
            $profile_photo = $result;
            $previous = profileStoredPhoto($conn, (int)$adminId);

            $update_query = $conn->prepare("UPDATE admin SET profile_photo = ? WHERE id = ?");
            $update_query->bind_param("si", $profile_photo, $adminId);

            if ($update_query->execute()) {
                // Only remove the old file once the new one is recorded,
                // so a failed update never leaves the user with neither.
                if ($previous !== '') { uploadDeleteFile($photoDir, $previous); }
                $success_message = "Profile photo successfully updated";
                header("Location: profile.php?updated=true&message=photo_uploaded");
                exit();
            } else {
                uploadDeleteFile($photoDir, $profile_photo);   // don't orphan it
                $error_message = "Failed to update profile photo: " . $conn->error;
            }
        }
    }
}

// Process profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $username = $_POST['username'];
    // FIX: Using null coalescing operator to avoid "Undefined array key"
    $password = $_POST['password'] ?? '';
    $current_photo = $_POST['current_photo'];

    // Validate input
    $errors = [];

    if (empty($username)) {
        $errors[] = "Username must be filled";
    }

    // Check if username is already used by another admin
    if ($username !== $adminName) {
        $check_username = $conn->prepare("SELECT id FROM admin WHERE username = ? AND id != ?");
        $check_username->bind_param("si", $username, $adminId);
        $check_username->execute();
        $check_result = $check_username->get_result();

        if ($check_result->num_rows > 0) {
            $errors[] = "Username is already used by another admin";
        }
    }

    // Handle cropped image upload.
    //
    // This path used to take the file extension straight out of the
    // posted data URI - "data:image/php;base64,…" wrote a .php file into
    // a web-served folder, which was remote code execution. The declared
    // type is now ignored entirely: the payload is decoded, written to a
    // temporary file, and put through exactly the same content checks as
    // any other upload.
    $previousPhoto = profileStoredPhoto($conn, (int)$adminId);
    $profile_photo = $previousPhoto;      // keep the existing one by default
    $newPhotoStored = '';

    if (!empty($_POST['cropped_image'])) {
        [$ok, $result] = uploadStoreDataUriImage((string)$_POST['cropped_image'], $photoDir, 'admin');
        if (!$ok) {
            $errors[] = $result;
        } else {
            $profile_photo  = $result;
            $newPhotoStored = $result;
        }
    }

    if (empty($errors)) {
        // Update admin data
        if (!empty($password)) {
            // If password is filled, update username, password, and photo
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            $update_query = $conn->prepare("UPDATE admin SET username = ?, password = ?, profile_photo = ? WHERE id = ?");
            $update_query->bind_param("sssi", $username, $hashed_password, $profile_photo, $adminId);
        } else {
            // If password is empty, only update username and photo
            $update_query = $conn->prepare("UPDATE admin SET username = ?, profile_photo = ? WHERE id = ?");
            $update_query->bind_param("ssi", $username, $profile_photo, $adminId);
        }

        if ($update_query->execute()) {
            // The new photo is recorded, so the old file can go.
            if ($newPhotoStored !== '' && $previousPhoto !== '' && $previousPhoto !== $newPhotoStored) {
                uploadDeleteFile($photoDir, $previousPhoto);
            }

            // Changing your own password signs out every other remembered
            // device, then re-issues one for this browser if it had one.
            // That is the point of changing a password: any copy of the
            // old credential stops working.
            if (!empty($password)) {
                $hadToken = rememberCurrentSelector() !== '';
                rememberForgetUser($conn, (int)$adminId);
                if ($hadToken) { rememberIssue($conn, (int)$adminId); }
            }

            // Update session username if username changed
            if ($username !== $adminName) {
                $_SESSION['username'] = $username;
            }

            $success_message = "Profile successfully updated";
            // Refresh page to update data
            header("Location: profile.php?updated=true");
            exit();
        } else {
            // The row was not saved, so the file we just wrote is an
            // orphan - remove it rather than leaving it on disk.
            if ($newPhotoStored !== '') { uploadDeleteFile($photoDir, $newPhotoStored); }
            $error_message = "Failed to update profile: " . $conn->error;
        }
    } else {
        if ($newPhotoStored !== '') { uploadDeleteFile($photoDir, $newPhotoStored); }
        $error_message = implode("<br>", $errors);
    }
}
?>

<?php
$pageTitle = 'My Profile';
$breadcrumbs = [['Dashboard', 'index.php'], ['Account'], ['Profile']];

// Cropper.js is used only on this screen (avatar cropping), so it is
// loaded here rather than for the whole application. jQuery used to be
// loaded alongside it but was never called, so it has been dropped.
$pageHead = '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.12/cropper.min.css">'
          . '<script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.12/cropper.min.js"></script>';

include 'inventory-header.php';

$ph = [
    'title'    => 'My Profile',
    'icon'     => 'fa-id-badge',
    'subtitle' => 'Your sign-in details and profile photo.',
];
include 'partials/page-header.php';

$photoUrl = !empty($adminPhoto)
    ? '../assets/uploads/profile_admin/' . htmlspecialchars($adminPhoto)
    : '../assets/images/default-avatar.png';
?>

<?php if (!empty($success_message)): ?>
    <div class="alert alert-success" role="alert"><i class="fas fa-circle-check"></i><div><?php echo htmlspecialchars($success_message); ?></div></div>
<?php endif; ?>
<?php if (!empty($error_message)): ?>
    <div class="alert alert-danger" role="alert"><i class="fas fa-circle-exclamation"></i><div><?php echo htmlspecialchars($error_message); ?></div></div>
<?php endif; ?>

<div class="row g-4">

    <!-- ============ Identity card ============ -->
    <div class="col-lg-4">
        <div class="inv-card">
            <div class="ui-card-body text-center">
                <div class="avatar-container">
                    <img src="<?php echo $photoUrl; ?>" alt="Profile photo"
                         style="width:104px;height:104px;border-radius:50%;object-fit:cover;border:3px solid var(--color-surface);box-shadow:var(--shadow-md);">
                </div>

                <h2 class="ui-section-title mt-1"><?php echo htmlspecialchars($adminName); ?></h2>
                <div class="ui-muted mb-1"><?php echo htmlspecialchars(roleLabel($_SESSION['role'] ?? '')); ?></div>
                <span class="ui-badge ui-badge-success"><span class="ui-dot ui-dot-success"></span>Active</span>

                <div class="d-grid gap-2 mt-4">
                    <button type="button" class="ui-btn ui-btn-secondary" id="uploadPhotoBtn">
                        <i class="fas fa-camera"></i>Change photo
                    </button>
                    <?php if (!empty($adminPhoto)): ?>
                    <button type="button" class="ui-btn ui-btn-danger" data-bs-toggle="modal" data-bs-target="#deletePhotoModal">
                        <i class="fas fa-trash"></i>Remove photo
                    </button>
                    <?php endif; ?>
                </div>

                <!-- Opened programmatically by the Change photo button. -->
                <input type="file" id="hiddenFileInput" accept="image/*" style="display:none;">
            </div>
        </div>
    </div>

    <!-- ============ Account details ============ -->
    <div class="col-lg-8">
        <div class="inv-card">
            <div class="ui-card-head">
                <h2 class="ui-card-title">Account details</h2>
                <button type="button" class="ui-btn ui-btn-secondary ui-btn-sm" data-bs-toggle="modal" data-bs-target="#editProfileModal">
                    <i class="fas fa-pen"></i>Edit
                </button>
            </div>
            <div class="ui-card-body">
                <dl class="row mb-0" style="font-size:.875rem;">
                    <dt class="col-sm-4 ui-muted" style="font-weight:500;">Username</dt>
                    <dd class="col-sm-8 mb-3"><?php echo htmlspecialchars($adminName); ?></dd>

                    <dt class="col-sm-4 ui-muted" style="font-weight:500;">Role</dt>
                    <dd class="col-sm-8 mb-3"><?php echo htmlspecialchars(roleLabel($_SESSION['role'] ?? '')); ?></dd>

                    <dt class="col-sm-4 ui-muted" style="font-weight:500;">Password</dt>
                    <dd class="col-sm-8 mb-0">
                        <span class="ui-muted">••••••••</span>
                        <div class="ui-caption">Change it from Edit above.</div>
                    </dd>
                </dl>
            </div>
        </div>

        <div class="inv-card mt-4">
            <div class="ui-card-head"><h2 class="ui-card-title">Access</h2></div>
            <div class="ui-card-body">
                <p class="ui-muted mb-2">Your role grants access to:</p>
                <div class="d-flex flex-wrap gap-2">
                    <?php
                    $moduleLabels = [
                        'dashboard' => 'Dashboard', 'manager_overview' => 'Manager overview',
                        'pos' => 'POS terminal', 'pos_sales' => 'Sales & receipts',
                        'products' => 'Products', 'inventory' => 'Inventory',
                        'purchasing' => 'Purchasing', 'barcode' => 'Barcodes',
                        'stock_requests' => 'Stock requests', 'customers' => 'Customers',
                        'accounting' => 'Accounting', 'accounting_manage' => 'Chart of accounts',
                        'users' => 'Manage users', 'settings' => 'Settings',
                        'departments' => 'Departments',
                    ];
                    foreach (roleModules($_SESSION['role'] ?? '') as $m) {
                        if (!isset($moduleLabels[$m])) { continue; }
                        echo '<span class="ui-badge ui-badge-neutral">' . htmlspecialchars($moduleLabels[$m]) . '</span>';
                    }
                    ?>
                </div>
                <div class="ui-caption mt-3">
                    Access is set by your role. Ask an administrator if you need more.
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ============ Edit profile ============ -->
<div class="modal fade" id="editProfileModal" tabindex="-1" aria-labelledby="editProfileModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="editProfileModalLabel">
                    <i class="fas fa-pen me-2" style="color:var(--color-primary);"></i>Edit profile
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" data-ui-loading>
                <?php echo csrfField(); ?>
                <input type="hidden" name="current_photo" value="<?php echo htmlspecialchars($adminPhoto ?? ''); ?>">
                <input type="hidden" name="cropped_image" id="cropped_image_data">
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="username" class="form-label">Username<span class="ui-required">*</span></label>
                        <input type="text" class="form-control" id="username" name="username"
                               value="<?php echo htmlspecialchars($adminName); ?>" required autocomplete="username">
                    </div>
                    <div class="mb-0">
                        <label for="password" class="form-label">New password</label>
                        <input type="password" class="form-control" id="password" name="password" autocomplete="new-password">
                        <div class="form-text">Leave blank to keep your current password.</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="ui-btn ui-btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="ui-btn ui-btn-primary" name="update_profile"><i class="fas fa-check"></i>Save changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ============ Crop photo (Cropper.js) ============ -->
<div class="modal fade" id="uploadPhotoModal" tabindex="-1" aria-labelledby="uploadPhotoModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="uploadPhotoModalLabel">
                    <i class="fas fa-camera me-2" style="color:var(--color-primary);"></i>Crop your photo
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <div class="crop-step" id="cropStep">
                    <div class="img-container" style="height:420px;max-height:56vh;background:var(--color-surface-alt);">
                        <img id="cropImage" src="#" alt="Image to crop" style="max-width:100%;">
                    </div>
                </div>
                <div class="py-2 text-center" style="background:var(--color-surface-alt);border-top:1px solid var(--color-border);">
                    <button type="button" class="ui-btn ui-btn-secondary ui-btn-sm ui-btn-icon mx-1" id="zoomInBtn" title="Zoom in" aria-label="Zoom in"><i class="fas fa-magnifying-glass-plus"></i></button>
                    <button type="button" class="ui-btn ui-btn-secondary ui-btn-sm ui-btn-icon mx-1" id="zoomOutBtn" title="Zoom out" aria-label="Zoom out"><i class="fas fa-magnifying-glass-minus"></i></button>
                    <button type="button" class="ui-btn ui-btn-secondary ui-btn-sm ui-btn-icon mx-1" id="rotateBtn" title="Rotate" aria-label="Rotate"><i class="fas fa-rotate"></i></button>
                </div>
            </div>
            <div class="modal-footer" id="cropFooter">
                <button type="button" class="ui-btn ui-btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="ui-btn ui-btn-primary" id="saveButton"><i class="fas fa-check"></i>Save photo</button>
            </div>
        </div>
    </div>
</div>

<!-- ============ Remove photo ============ -->
<div class="modal fade ui-modal-danger" id="deletePhotoModal" tabindex="-1" aria-labelledby="deletePhotoModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="deletePhotoModalLabel">
                    <i class="fas fa-triangle-exclamation me-2"></i>Remove photo
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="post" data-ui-loading>
                <?php echo csrfField(); ?>
                <input type="hidden" name="current_photo" value="<?php echo htmlspecialchars($adminPhoto ?? ''); ?>">
                <div class="modal-body">
                    <p class="ui-body mb-0">Your photo will be replaced with the default avatar.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="ui-btn ui-btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="ui-btn ui-btn-danger-solid" name="delete_photo"><i class="fas fa-trash"></i>Remove</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// FIX: Correct JavaScript without syntax errors
let cropper;
const isMobile = window.matchMedia("(max-width: 768px)").matches;

// PHP variables properly escaped
const adminPhoto = <?php echo json_encode($adminPhoto); ?>;
const adminName = <?php echo json_encode($adminName); ?>;
const currentScript = <?php echo json_encode($_SERVER["PHP_SELF"]); ?>;

document.addEventListener('DOMContentLoaded', function() {
    // Event listener for "Change Photo" button
    document.getElementById('uploadPhotoBtn').addEventListener('click', function(e) {
        e.preventDefault();
        console.log('Upload button clicked');
        document.getElementById('hiddenFileInput').click();
    });

    // Event listener for file input
    document.getElementById('hiddenFileInput').addEventListener('change', function(e) {
        const file = e.target.files[0];
        console.log('File selected:', file);

        if (file) {
            const reader = new FileReader();
            reader.onload = function(event) {
                // Show crop modal
                const uploadModal = new bootstrap.Modal(document.getElementById('uploadPhotoModal'));
                uploadModal.show();

                // Apply fullscreen on mobile
                if (isMobile) {
                    setTimeout(() => {
                        document.querySelector('#uploadPhotoModal .modal-dialog').classList.add('mobile-fullscreen');
                    }, 300);
                }

                // Set image for cropping
                const cropImage = document.getElementById('cropImage');
                cropImage.src = event.target.result;

                cropImage.onload = function() {
                    // Destroy cropper if exists
                    if (cropper) {
                        cropper.destroy();
                    }

                    // Cropper configuration
                    const cropperOptions = {
                        aspectRatio: 1,
                        viewMode: isMobile ? 0 : 1,
                        guides: true,
                        autoCropArea: 0.8,
                        responsive: true,
                        dragMode: 'move',
                        cropBoxResizable: true,
                        cropBoxMovable: true,
                        toggleDragModeOnDblclick: false,
                        minContainerWidth: 250,
                        minContainerHeight: 250,
                        minCropBoxWidth: 100,
                        minCropBoxHeight: 100,
                        wheelZoomRatio: 0.1,
                        background: true,
                        modal: true,
                        center: true
                    };

                    // Initialize cropper
                    cropper = new Cropper(cropImage, cropperOptions);

                    // Resize after initialization
                    setTimeout(() => {
                        window.dispatchEvent(new Event('resize'));
                    }, 500);
                };
            };
            reader.readAsDataURL(file);
        }
    });

    // Zoom and rotation controls
    document.getElementById('zoomInBtn').addEventListener('click', function() {
        if (cropper) cropper.zoom(0.1);
    });

    document.getElementById('zoomOutBtn').addEventListener('click', function() {
        if (cropper) cropper.zoom(-0.1);
    });

    document.getElementById('rotateBtn').addEventListener('click', function() {
        if (cropper) cropper.rotate(90);
    });

    // Save button
    document.getElementById('saveButton').addEventListener('click', function() {
        if (cropper) {
            // Get cropped canvas
            const canvas = cropper.getCroppedCanvas({
                width: 600,
                height: 600,
                minWidth: 100,
                minHeight: 100,
                maxWidth: 1000,
                maxHeight: 1000,
                fillColor: '#fff',
                imageSmoothingEnabled: true,
                imageSmoothingQuality: 'high',
            });

            // Convert to base64
            const croppedImageData = canvas.toDataURL('image/jpeg');

            // Submit form
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = currentScript;

            // Hidden inputs
            const inputs = [
                {name: 'current_photo', value: adminPhoto || ''},
                {name: 'cropped_image', value: croppedImageData},
                {name: 'username', value: adminName},
                {name: 'update_profile', value: '1'}
            ];

            inputs.forEach(input => {
                const hiddenInput = document.createElement('input');
                hiddenInput.type = 'hidden';
                hiddenInput.name = input.name;
                hiddenInput.value = input.value;
                form.appendChild(hiddenInput);
            });

            document.body.appendChild(form);
            form.submit();
        } else {
            alert('Please select an image first');
        }
    });

    // Reset modal when closed
    document.getElementById('uploadPhotoModal').addEventListener('hidden.bs.modal', function() {
        document.getElementById('hiddenFileInput').value = '';

        if (cropper) {
            cropper.destroy();
            cropper = null;
        }

        document.querySelector('#uploadPhotoModal .modal-dialog').classList.remove('mobile-fullscreen');
    });

    // Resize handler
    window.addEventListener('resize', function() {
        if (cropper) {
            cropper.resize();
        }
    });
});
</script>


<?php include 'inventory-footer.php'; ?>
