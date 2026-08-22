<?php
// ============================================================
// GET /admin/api/products-scan.php?barcode={code}
//   Quick indexed barcode (or SKU) lookup for the POS till and the
//   barcode station. Returns item details, live stock and the price
//   the till should charge.
//
// Response: { ok, found, product:{...}, message }
// ============================================================
require_once '../../includes/auth.php';
// Used by BOTH the till and the barcode station, so either module
// grants access - a storekeeper has no POS rights but must still be
// able to scan an item in.
if (!userCan('pos') && !userCan('barcode')) {
    requireModule('pos');   // handles the redirect / access-denied
}
header('Content-Type: application/json');

include '../../includes/db.php';
require_once '../../includes/pos_functions.php';
posBoot($conn);

$barcode = trim($_GET['barcode'] ?? '');
if ($barcode === '') {
    echo json_encode(['ok' => false, 'found' => false, 'message' => 'No barcode supplied.']);
    exit;
}

$product = posFindByBarcode($conn, $barcode);

if (!$product) {
    echo json_encode([
        'ok' => true,
        'found' => false,
        'message' => 'No product matches barcode "' . $barcode . '".',
        'barcode' => $barcode,
    ]);
    exit;
}

$department    = $product['department'] ?: 'general';
$deptTrading   = catalogDepartmentEnabled($conn, $department);

// Past its expiry date and the shop blocks those - see
// retailExpiryBlockReason() in includes/catalog_functions.php.
$expiredWhy = retailExpiryBlockReason($product);

$sellable = !empty($product['is_enabled'])
    && in_array($product['usage_type'] ?? '', ['sale', 'both'], true)
    && $deptTrading
    && $expiredWhy === null;

// Explain WHY something cannot be rung up - "not set up for sale" sends
// a cashier hunting through the product screen when the real cause is
// that the whole department has been switched off.
if ($expiredWhy !== null) {
    // Named first: a cashier holding an expired pack needs to know to
    // pull it off the shelf, not to go hunting through Products & Prices.
    $reason = $expiredWhy;
} elseif (!$deptTrading) {
    $reason = 'The ' . catalogDepartmentLabel($department) . ' department is not currently trading.';
} elseif (!$sellable) {
    $reason = 'This item is not set up for sale (check Products & Prices).';
} else {
    $reason = (float)$product['current_stock'] > 0 ? 'ok' : 'Out of stock.';
}

echo json_encode([
    'ok' => true,
    'found' => true,
    'product' => [
        'id'            => (int)$product['id'],
        'name'          => $product['name'],
        'sku'           => $product['sku'],
        'barcode'       => $product['barcode'],
        'category'      => $product['category_name'],
        'unit'          => $product['unit'],
        'stock'         => (float)$product['current_stock'],
        'price'         => posUnitPrice($product),
        'base_price'    => (float)($product['selling_price'] ?? 0),
        'promo'         => !empty($product['promo_active']),
        'image'         => $product['image'] ? '../' . ltrim($product['image'], '/') : '',
        'sellable'      => $sellable,
        'pos_visible'   => !empty($product['is_pos_visible']),
        'department'    => $department,
        'dept_trading'  => $deptTrading,
        'average_cost'  => (float)$product['average_cost'],
    ],
    'message' => $reason,
]);
