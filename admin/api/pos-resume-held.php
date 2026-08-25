<?php
// ============================================================
// POST /admin/api/pos-resume-held.php
//   Resumes a held sale - the ownership + concurrency gate for the
//   held-sales feature. This is the ONLY way a held sale's cart_json
//   ever reaches a browser: admin/pos.php's held-sales list only ever
//   contains this cashier's own rows on their own terminal (see
//   posGetHeldSalesForCashier()), and this endpoint re-checks ownership
//   again, server-side, against the session - never a client-supplied
//   cashier/terminal id - before returning anything.
//
// Body (JSON):
//   held_sale_id - required
//
// Response: { ok, message, cart? }
//   cart is the decoded item array, only present when ok is true.
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

$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) { $body = $_POST; }

$heldSaleId = (int)($body['held_sale_id'] ?? 0);

// Never trust a client-supplied cashier/terminal id here - both come
// from the session, exactly like the heartbeat endpoint.
[$ok, $message, $cartJson] = posResumeHeldSale(
    $conn,
    $heldSaleId,
    (int)($_SESSION['id'] ?? 0),
    (int)($_SESSION['pos_terminal_id'] ?? 0)
);

if (!$ok) {
    echo json_encode(['ok' => false, 'message' => $message]);
    exit;
}

$cart = json_decode((string)$cartJson, true);
echo json_encode(['ok' => true, 'message' => $message, 'cart' => is_array($cart) ? array_values($cart) : []]);
