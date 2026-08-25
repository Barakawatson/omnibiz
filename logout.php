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

// Held sales and the terminal lock are cleaned up as two separate,
// independent calls at logout - not one combined step - so a bug in
// one can never accidentally couple to the other. This is the one exit
// path that always runs (unlike a browser crash, which relies on the
// heartbeat timeout for the terminal, and leaves held sales exactly as
// they were until a manager recovers them). Same "the database may be
// down; signing out must still work" resilience as the block above -
// db.php is only included here if the remember-me branch didn't already
// do it.
$posTerminalId = (int)($_SESSION['pos_terminal_id'] ?? 0);
$posUserId = (int)($_SESSION['id'] ?? 0);
if ($posUserId > 0) {
    if (!isset($conn) || !$conn) {
        include __DIR__ . '/includes/db.php';
    }
    if (isset($conn) && $conn) {
        require_once __DIR__ . '/includes/pos_functions.php';
        // Orphan first: any held/stale/resumed sale this cashier still
        // has becomes 'orphaned', never deleted and never silently
        // inherited by whoever logs into this or any other terminal next.
        posOrphanHeldSalesForCashier($conn, $posUserId);
        // Then release the terminal lock - independent of the above.
        if ($posTerminalId > 0) {
            posReleaseTerminal($conn, $posTerminalId, $posUserId);
        }
    }
}

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
