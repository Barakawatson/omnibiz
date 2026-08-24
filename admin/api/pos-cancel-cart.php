<?php
// ============================================================
// POST /admin/api/pos-cancel-cart.php
//   Records a cart that never became a sale - either the live,
//   in-progress cart being cleared, or a parked held sale being
//   discarded - with a mandatory reason. This is the ONLY path that
//   deletes a held sale without completing it: the audit row is
//   written first, inside the same transaction, so a held sale can
//   never disappear unaudited (see posLogCancelledCart()).
//
// Body (JSON):
//   source        - 'live_cart' | 'held_sale'
//   held_sale_id? - required when source is 'held_sale'
//   items         - [{item_id, name, qty}, ...] - the cart being discarded
//   total         - cart grand total at the moment of cancellation
//   reason_code   - required, one of the dropdown's option values
//   reason_detail - optional free text (required by the UI when reason_code is 'other')
//   terminal_id?
//
// Response: { ok, message }
// ============================================================
require_once '../../includes/auth.php';
requireModule('pos');
// Sent as an X-CSRF-Token header by pos.php, same convention as checkout.
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

$source = ($body['source'] ?? '') === 'held_sale' ? 'held_sale' : 'live_cart';
$heldSaleId = (int)($body['held_sale_id'] ?? 0);
$reasonCode = trim((string)($body['reason_code'] ?? ''));
$reasonDetail = trim((string)($body['reason_detail'] ?? ''));
$total = (float)($body['total'] ?? 0);
$terminalId = (int)($body['terminal_id'] ?? $_SESSION['pos_terminal_id'] ?? 0);

$items = $body['items'] ?? [];
if (is_string($items)) { $items = json_decode($items, true) ?: []; }
if (!is_array($items)) { $items = []; }

if ($source === 'held_sale' && $heldSaleId <= 0) {
    echo json_encode(['ok' => false, 'message' => 'Missing held sale id.']);
    exit;
}

[$ok, $message] = posLogCancelledCart(
    $conn,
    (int)($_SESSION['id'] ?? 0) ?: null,
    (string)($_SESSION['username'] ?? ''),
    $terminalId,
    $source,
    $source === 'held_sale' ? $heldSaleId : null,
    $reasonCode,
    $reasonDetail,
    $items,
    $total
);

echo json_encode(['ok' => $ok, 'message' => $message]);
