<?php
require_once '../includes/session.php';
appSessionStart();
if (!isset($_SESSION['username']) || !isset($_SESSION['role'])) {
    header("Location: login.php");
    exit();
}
$current_page = basename($_SERVER['PHP_SELF']);
include '../includes/db.php';
require_once '../includes/auth.php';

// Self-heal: the session may be carrying a stale role (e.g. an admin
// changed it while this user was logged in, or the old ENUM column
// blanked it out). Re-read the real role from the database so the
// user isn't stuck behind a wrong session value.
$freshRole = null;
$stmt = $conn->prepare("SELECT role FROM admin WHERE username = ? LIMIT 1");
if ($stmt) {
    $stmt->bind_param('s', $_SESSION['username']);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $freshRole = $row['role'] ?? null;
}
if ($freshRole !== null && $freshRole !== '' && $freshRole !== $_SESSION['role']) {
    $_SESSION['role'] = $freshRole;
    header('Location: ' . roleHome($freshRole));
    exit();
}

$roleLabel = trim((string)($_SESSION['role'] ?? ''));
$roleMissing = ($roleLabel === '');
$homeUrl = $roleMissing ? 'profile.php' : roleHome($roleLabel);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Access Denied - Admin</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/admin/styles.css">
    <link rel="icon" type="image/png" href="../assets/images/favicon.png">
    <style>
        .denied-wrapper {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            min-height: 70vh;
            text-align: center;
            padding: 2rem;
        }
        .denied-icon {
            font-size: 4rem;
            color: #dc3545;
            margin-bottom: 1.5rem;
        }
        .denied-title {
            font-size: 1.8rem;
            font-weight: 700;
            color: #333;
            margin-bottom: 0.75rem;
        }
        .denied-text {
            color: #6c757d;
            margin-bottom: 2rem;
            max-width: 420px;
        }
        .btn-back {
            background: linear-gradient(135deg, #42c3cf, #36b5c0);
            color: white;
            border: none;
            border-radius: 50px;
            padding: 0.75rem 2rem;
            font-weight: 600;
            text-decoration: none;
        }
        .btn-back:hover {
            color: white;
            opacity: 0.9;
        }
    </style>
</head>
<body>
    <?php include 'sidebar-admin.php'; ?>
    <div class="content" id="content">
        <div class="container">
            <div class="denied-wrapper">
                <i class="fas fa-lock denied-icon"></i>
                <div class="denied-title">Access Denied</div>
                <?php if ($roleMissing): ?>
                <p class="denied-text">
                    Your account doesn't have a role assigned yet, so there's nothing it can open.
                    Please ask an administrator to open <strong>Manage Users</strong>, edit your account
                    (<strong><?php echo htmlspecialchars($_SESSION['username']); ?></strong>) and set your role.
                </p>
                <?php else: ?>
                <p class="denied-text">
                    Your account role (<?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $roleLabel))); ?>)
                    doesn't have permission to view this page. If you believe this is a mistake, ask an admin to check your account's role.
                </p>
                <?php endif; ?>
                <a href="<?php echo htmlspecialchars($homeUrl); ?>" class="btn-back"><i class="fas fa-arrow-left me-2"></i><?php echo $roleMissing ? 'Go to My Profile' : 'Back to My Dashboard'; ?></a>
            </div>
        </div>
    </div>
</body>
</html>
