<?php
// ============================================================
// Single login portal for the whole system.
// ------------------------------------------------------------
// Every role (admin, manager, storekeeper, cashier) signs in here.
// On success the user is sent straight to their role's home screen
// via roleHomeUrl() - no intermediate splash page.
// ============================================================
require_once '../includes/session.php';
appSessionStart();
require_once '../includes/auth.php';
require '../includes/db.php';
require_once '../includes/core_schema.php';
require_once '../includes/remember_me.php';
require_once '../includes/inventory_functions.php';
require_once '../includes/shop_settings.php';
ensureCoreSchema($conn);
// The sign-in screen carries the shop's identity, so it needs the
// settings table to exist even on a first-ever page load.
ensureShopSettingsSchema($conn);
$loginShopName    = shopName($conn);
$loginShopTagline = shopSetting($conn, 'shop_tagline', 'Retail');
$loginShopLogo    = shopLogoUrl($conn, '../');

// Already signed in? Go straight to the role's home.
if (isset($_SESSION['username'], $_SESSION['role'])) {
    header('Location: ../' . roleHomeUrl($_SESSION['role']));
    exit;
}

$error = '';

// "Keep me signed in" - re-establishes the session from a random,
// hashed, rotating token. See includes/remember_me.php for why the
// previous username-in-a-cookie approach was replaced.
//
// The old 'remember_admin' cookie is never read; rememberClearCookie()
// deletes it on sight, because anyone still holding one is carrying a
// credential that could be forged.
$remembered = rememberValidate($conn);
if ($remembered) {
    // Same session hardening as a password login - a persistent login
    // must not be a weaker session than a typed one.
    session_regenerate_id(true);
    csrfRotate();
    $_SESSION['role']     = $remembered['role'];
    $_SESSION['username'] = $remembered['username'];
    $_SESSION['id']       = $remembered['id'];
    header('Location: ../' . roleHomeUrl($remembered['role']));
    exit;
}
rememberClearCookie();   // clears the legacy cookie and any failed token

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');
    $remember = isset($_POST['remember']);

    // Login CSRF matters too: without it, a hostile page could sign a
    // cashier into an account the attacker controls, so anything they
    // then do is recorded against that account.
    //
    // Handled inline rather than via csrfRequire() because this page has
    // no flash-message area, and because the failure here is usually
    // innocent - a sign-in page left open until the session expired.
    // The form below always re-renders with a fresh token, so one retry
    // succeeds; a redirect would just look like the password was wrong.
    if (!csrfValid()) {
        $error = 'Your sign-in form had expired, so nothing was sent. Please try again.';
    } elseif ($username === '' || $password === '') {
        $error = 'Please enter both your username and password.';
    } elseif (!preg_match('/^[a-zA-Z0-9_.-]{1,50}$/', $username)) {
        $error = 'Incorrect username or password.';
    } else {
        $stmt = $conn->prepare("SELECT id, username, password, role, status FROM admin WHERE username = ?");
        $stmt->bind_param('s', $username);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($user && password_verify($password, $user['password'])) {
            if (($user['status'] ?? 'active') !== 'active') {
                $error = 'This account has been deactivated. Contact an administrator for access.';
            } elseif (!in_array($user['role'] ?? '', allSystemRoles(), true)) {
                // Legacy laundry roles (staff, driver, sales_officer...) no
                // longer exist. Logging in would just bounce the user around
                // access-denied, so say what actually needs fixing.
                $error = 'Your account role is not valid for this system. Ask an administrator to reassign your role in Manage Users.';
            } else {
                session_regenerate_id(true);
                csrfRotate();   // a token captured before sign-in must not survive it
                $_SESSION['role']     = $user['role'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['id']       = $user['id'];

                // Only ever issued here, after a verified password.
                if ($remember) {
                    rememberPurgeExpired($conn);
                    rememberIssue($conn, (int)$user['id']);
                }

                header('Location: ../' . roleHomeUrl($user['role']));
                exit;
            }
        } else {
            $error = 'Incorrect username or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign in &middot; <?php echo htmlspecialchars($loginShopName); ?></title>
    <link rel="icon" type="image/png" href="../assets/images/favicon.png">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600&display=swap" rel="stylesheet">
    <!-- Same design system as the rest of the app, so the sign-in
         screen and the application it opens look like one product. -->
    <link rel="stylesheet" href="../assets/css/admin/ui.css">
    <style>
        /* Page-specific only: this is the one full-bleed centred screen
           in the application, so its shell does not belong in ui.css. */
        body {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: var(--space-5);
            background: var(--color-background);
        }
        .login-shell { width: 100%; max-width: 380px; }

        .login-brand { text-align: center; margin-bottom: var(--space-6); }
        .login-brand-logo {
            width: 46px; height: 46px; margin: 0 auto var(--space-3);
            border-radius: var(--radius-lg);
            background: var(--color-primary);
            color: #fff;
            display: flex; align-items: center; justify-content: center;
            font-size: 1.25rem;
        }
        .login-brand-name { font-size: 1.0625rem; font-weight: 600; color: var(--color-text); }
        .login-brand-sub  { font-size: .8125rem; color: var(--color-text-muted); margin-top: 2px; }

        .login-card {
            background: var(--color-surface);
            border: 1px solid var(--color-border);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-md);
            padding: var(--space-6);
        }
        .login-foot {
            text-align: center;
            font-size: .75rem;
            color: var(--color-text-faint);
            margin-top: var(--space-5);
        }
        .login-pw-wrap { position: relative; }
        .login-pw-toggle {
            position: absolute; right: 4px; top: 50%; transform: translateY(-50%);
            width: 30px; height: 30px;
            border: none; background: transparent;
            color: var(--color-text-faint);
            border-radius: var(--radius-sm);
            display: flex; align-items: center; justify-content: center;
        }
        .login-pw-toggle:hover { color: var(--color-text); background: var(--color-surface-alt); }
        .login-card .form-control { height: 40px; }
    </style>
</head>
<body>
    <div class="login-shell">

        <div class="login-brand">
            <div class="login-brand-logo">
                <?php if ($loginShopLogo !== ''): ?>
                    <img src="<?php echo htmlspecialchars($loginShopLogo); ?>" alt="">
                <?php else: ?>
                    <i class="fas fa-cash-register"></i>
                <?php endif; ?>
            </div>
            <div class="login-brand-name"><?php echo htmlspecialchars($loginShopName); ?></div>
            <div class="login-brand-sub"><?php echo htmlspecialchars($loginShopTagline); ?> &middot; Point of Sale</div>
        </div>

        <div class="login-card">
            <?php if ($error !== ''): ?>
                <div class="alert alert-danger" role="alert">
                    <i class="fas fa-circle-exclamation"></i>
                    <div><?php echo htmlspecialchars($error); ?></div>
                </div>
            <?php endif; ?>

            <form method="post" autocomplete="off" data-ui-loading>
<?php echo csrfField(); ?>
                <div class="mb-3">
                    <label class="form-label" for="username">Username</label>
                    <input type="text" class="form-control" id="username" name="username"
                           value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>"
                           placeholder="Your username" required autofocus
                           autocapitalize="none" spellcheck="false">
                </div>

                <div class="mb-3">
                    <label class="form-label" for="password">Password</label>
                    <div class="login-pw-wrap">
                        <input type="password" class="form-control" id="password" name="password"
                               placeholder="Your password" required style="padding-right:38px;">
                        <button type="button" class="login-pw-toggle" id="togglePw"
                                aria-label="Show password" aria-pressed="false">
                            <i class="fas fa-eye" aria-hidden="true"></i>
                        </button>
                    </div>
                </div>

                <div class="form-check mb-4">
                    <input class="form-check-input" type="checkbox" id="remember" name="remember">
                    <label class="form-check-label" for="remember">Keep me signed in on this till</label>
                </div>

                <button type="submit" class="ui-btn ui-btn-primary ui-btn-block ui-btn-lg">
                    Sign in
                </button>
            </form>
        </div>

        <div class="login-foot">Authorised staff only</div>
    </div>

    <script>
        // Show/hide password. Purely presentational - nothing about how
        // the credential is submitted or handled changes.
        (function () {
            var btn = document.getElementById('togglePw');
            var pw  = document.getElementById('password');
            if (!btn || !pw) { return; }
            btn.addEventListener('click', function () {
                var showing = pw.type === 'text';
                pw.type = showing ? 'password' : 'text';
                btn.setAttribute('aria-pressed', showing ? 'false' : 'true');
                btn.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
                btn.querySelector('i').className = showing ? 'fas fa-eye' : 'fas fa-eye-slash';
            });
        })();
    </script>
</body>
</html>
