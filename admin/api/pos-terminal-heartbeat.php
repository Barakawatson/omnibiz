<?php
// ============================================================
// POST /admin/api/pos-terminal-heartbeat.php
//   Called every 45s by admin/pos.php while a cashier is on a claimed
//   till, to prove the session is still alive. Advances
//   pos_terminals.last_activity_at for the terminal THIS session's own
//   login owns - the terminal id comes from the session, never from the
//   request body, so a tampered call can only ever refresh (or fail to
//   refresh) the caller's own lock, never someone else's.
//
// Response: { ok }
//   ok:false means this session no longer holds the terminal it thinks
//   it does (force-released by an admin, reclaimed as stale by another
//   cashier, or never claimed) - the client stops the heartbeat and
//   re-shows the till picker. The in-progress cart is untouched; it is
//   just a JS variable, already protected by the existing shadow copy.
// ============================================================
require_once '../../includes/auth.php';
requireModule('pos');
// Sent as an X-CSRF-Token header by pos.php, same convention as checkout
// and cancel-cart.
csrfRequire();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'POST required.']);
    exit;
}

include '../../includes/db.php';
require_once '../../includes/pos_functions.php';
posBoot($conn);

$terminalId = (int)($_SESSION['pos_terminal_id'] ?? 0);
$userId = (int)($_SESSION['id'] ?? 0);

echo json_encode(['ok' => posHeartbeat($conn, $terminalId, $userId)]);
