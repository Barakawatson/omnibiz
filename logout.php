<?php
// ============================================================
// Sign out
// ------------------------------------------------------------
// Clearing the cookie is not enough on its own: the token also has
// to be deleted server-side, or a copy taken from this browser
// would still work after the user "logged out".
// ============================================================
require_once __DIR__ . '/includes/session.php';
appSessionStart();

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/remember_me.php';

// The database may be down; signing out must still work.
$selector = rememberCurrentSelector();
if ($selector !== '') {
    include __DIR__ . '/includes/db.php';
    if (isset($conn) && $conn) {
        rememberForgetSelector($conn, $selector);
    }
}
rememberClearCookie();

$_SESSION = [];

// Remove the session cookie itself, not just its server-side data.
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires'  => time() - 42000,
        'path'     => $p['path'],
        'domain'   => $p['domain'],
        'secure'   => $p['secure'],
        'httponly' => $p['httponly'],
        'samesite' => $p['samesite'] ?? 'Lax',
    ]);
}

session_destroy();

header('Location: admin/login.php');
exit();
