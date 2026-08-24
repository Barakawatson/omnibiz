<?php
// ============================================================
// GET /admin/api/customer-lookup.php?phone={number}
//   Look an existing registered customer up by phone, so the till
//   can autofill their stored name/address/TIN/email instead of the
//   cashier retyping them on every visit.
//
// Read-only - no CSRF token needed (GET requests are never checked;
// see csrfRequire()'s own doc comment).
//
// Response: { ok, found, customer:{name,phone,address,tin,email} }
// ============================================================
require_once '../../includes/auth.php';
requireModule('pos');
header('Content-Type: application/json');

include '../../includes/db.php';
require_once '../../includes/catalog_functions.php';
require_once '../../includes/core_schema.php';
ensureCoreSchema($conn);

$phone = retailNormalizePhone((string)($_GET['phone'] ?? ''));
if ($phone === '') {
    echo json_encode(['ok' => true, 'found' => false]);
    exit;
}

$stmt = $conn->prepare("SELECT name, phone_number, address, tin, email FROM customer WHERE phone_number = ? LIMIT 1");
$stmt->bind_param('s', $phone);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    echo json_encode(['ok' => true, 'found' => false]);
    exit;
}

echo json_encode([
    'ok'    => true,
    'found' => true,
    'customer' => [
        'name'    => (string)($row['name'] ?? ''),
        'phone'   => (string)($row['phone_number'] ?? ''),
        'address' => (string)($row['address'] ?? ''),
        'tin'     => (string)($row['tin'] ?? ''),
        'email'   => (string)($row['email'] ?? ''),
    ],
]);
