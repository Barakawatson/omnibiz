<?php
// ============================================================
// POST /admin/api/inventory-stock-in.php
//   Stock keeper receives stock (or records a physical count) by
//   scanning a barcode. Row-locked and written through the shared
//   stock-movement audit trail.
//
// Body (JSON or form-encoded):
//   barcode | item_id   - which item (barcode preferred, from a scan)
//   quantity            - amount received, or counted quantity in 'set' mode
//   mode                - 'add' (default) or 'set' (physical count)
//   unit_cost?          - purchase cost, updates weighted-average cost
//   expiry_date?        - best-before date for this delivery ('add' mode
//                         only); creates a dated batch and refreshes the
//                         item's own expiry_date to match
//   reason?
//
// Response: { ok, item:{id,name,stock}, message }
// ============================================================
require_once '../../includes/auth.php';
requireModule('inventory');
// Sent as an X-CSRF-Token header by barcode-station.php.
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

$itemId   = (int)($body['item_id'] ?? 0);
$barcode  = trim((string)($body['barcode'] ?? ''));
$quantity = (float)($body['quantity'] ?? 0);
$mode     = ($body['mode'] ?? 'add') === 'set' ? 'set' : 'add';
$unitCost = (float)($body['unit_cost'] ?? 0);
$reason   = trim((string)($body['reason'] ?? ''));
$expiry   = trim((string)($body['expiry_date'] ?? '')) ?: null;

// Resolve the item from the scanned barcode when no id was given.
if ($itemId <= 0 && $barcode !== '') {
    $found = posFindByBarcode($conn, $barcode);
    if (!$found) {
        echo json_encode([
            'ok' => false,
            'not_found' => true,
            'barcode' => $barcode,
            'message' => 'No product matches barcode "' . $barcode . '". Assign it to an item first.',
        ]);
        exit;
    }
    $itemId = (int)$found['id'];
}

if ($itemId <= 0) {
    echo json_encode(['ok' => false, 'message' => 'No item specified.']);
    exit;
}

[$ok, $message, $newQty] = posStockIn(
    $conn,
    $itemId,
    $quantity,
    (int)($_SESSION['id'] ?? 0) ?: null,
    $mode,
    $unitCost,
    $reason,
    $expiry
);

if (!$ok) {
    echo json_encode(['ok' => false, 'message' => $message]);
    exit;
}

$nameRes = $conn->prepare("SELECT name, barcode FROM inv_items WHERE id = ?");
$nameRes->bind_param('i', $itemId);
$nameRes->execute();
$item = $nameRes->get_result()->fetch_assoc();
$nameRes->close();

echo json_encode([
    'ok' => true,
    'item' => [
        'id'      => $itemId,
        'name'    => $item['name'] ?? '',
        'barcode' => $item['barcode'] ?? '',
        'stock'   => (float)$newQty,
    ],
    'message' => $message,
]);
