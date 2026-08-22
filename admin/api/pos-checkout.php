<?php
// ============================================================
// POST /admin/api/pos-checkout.php
//   Atomic POS checkout: validates stock, records the sale, deducts
//   stock in real time (row-locked, so concurrent tills can't
//   oversell) and returns the receipt payload.
//
// Body (JSON or form-encoded):
//   items:    [{item_id, quantity, line_discount?}, ...]
//   discount: order-level discount amount
//   amount_paid, payment_method ('cash'|'lipa_namba'|'card')
//   customer: {type:'cash'|'registered', name?, phone?}
//   note?
//
// Response: { ok, receipt_no, transaction_id, receipt:{...}, message }
// ============================================================
require_once '../../includes/auth.php';
requireModule('pos');
// The till sends this as an X-CSRF-Token header (see pos.php). A JSON
// body has no form fields, and requiring a custom header also forces a
// CORS preflight, so a cross-site page cannot forge a checkout.
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

// Accept JSON or classic form posts.
$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) { $body = $_POST; }

$items = $body['items'] ?? [];
if (is_string($items)) { $items = json_decode($items, true) ?: []; }

$lines = [];
foreach ((array)$items as $it) {
    $lines[] = [
        'item_id'       => (int)($it['item_id'] ?? 0),
        'quantity'      => (float)($it['quantity'] ?? 0),
        'line_discount' => (float)($it['line_discount'] ?? 0),
    ];
}

$customer = $body['customer'] ?? [];
if (is_string($customer)) { $customer = json_decode($customer, true) ?: []; }

// Split tender: `payments` is [{method, amount, reference?}, …].
// `amount_paid` + `payment_method` remain accepted for a single tender.
$payments = $body['payments'] ?? [];
if (is_string($payments)) { $payments = json_decode($payments, true) ?: []; }

$payment = [
    'amount_paid' => (float)($body['amount_paid'] ?? 0),
    'method'      => $body['payment_method'] ?? 'cash',
    'payments'    => is_array($payments) ? $payments : [],
    'discount'    => (float)($body['discount'] ?? 0),
    'note'        => $body['note'] ?? '',
];

[$ok, $result, $txnId] = posCheckout(
    $conn,
    $lines,
    $payment,
    [
        'type'  => $customer['type'] ?? 'cash',
        'name'  => $customer['name'] ?? '',
        'phone' => $customer['phone'] ?? '',
    ],
    (int)($_SESSION['id'] ?? 0) ?: null,
    (string)($_SESSION['username'] ?? ''),
    (int)($body['terminal_id'] ?? $_SESSION['pos_terminal_id'] ?? 0)
);

if (!$ok) {
    echo json_encode(['ok' => false, 'message' => $result]);
    exit;
}

// Clear the held sale this cart was resumed from, if any.
if (!empty($body['held_id'])) {
    posDeleteHeldSale($conn, (int)$body['held_id']);
}

$sale = posGetSale($conn, $txnId);

echo json_encode([
    'ok'             => true,
    'receipt_no'     => $result,
    'transaction_id' => $txnId,
    'receipt'        => [
        'receipt_no'     => $sale['receipt_no'],
        'created_at'     => $sale['created_at'],
        'cashier'        => $sale['cashier_name'],
        'customer_type'  => $sale['customer_type'],
        'customer_name'  => $sale['customer_name'],
        'customer_phone' => $sale['customer_phone'],
        'subtotal'       => (float)$sale['subtotal'],
        'discount'       => (float)$sale['discount'],
        'tax_rate'       => (float)$sale['tax_rate'],
        'tax_amount'     => (float)$sale['tax_amount'],
        'total'          => (float)$sale['total'],
        'amount_paid'    => (float)$sale['amount_paid'],
        'change_due'     => (float)$sale['change_due'],
        'payment_method' => $sale['payment_method'],
        'payments'       => array_map(function ($p) {
            return ['method' => $p['method'], 'amount' => (float)$p['amount']];
        }, $sale['payments'] ?? []),
        'items'          => array_map(function ($i) {
            return [
                'name'       => $i['item_name'],
                'quantity'   => (float)$i['quantity'],
                'unit_price' => (float)$i['unit_price'],
                'discount'   => (float)$i['line_discount'],
                'line_total' => (float)$i['line_total'],
            ];
        }, $sale['items']),
    ],
    // Absolute: the till may have been opened as /Home/pos/terminal,
    // where a relative URL resolves to a directory that does not exist.
    'print_url' => adminUrl('pos-receipt.php') . '?id=' . $txnId,
    'message'   => 'Sale completed.',
]);
