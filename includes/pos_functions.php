<?php
// ============================================================
// POS - shared backend logic
// ------------------------------------------------------------
// Receipt numbering, barcode lookup, transaction-safe checkout,
// stock-in and held sales.
//
// CONCURRENCY: every stock change goes through recordStockMovement()
// which locks the item row (SELECT ... FOR UPDATE) inside the caller's
// transaction, so two tills selling the last unit at the same moment
// can never oversell - the second one blocks, re-reads the true stock
// and fails cleanly instead of writing a negative quantity.
//
// ACCOUNTING: a checkout writes the sale, deducts the stock AND posts
// the ledger entry inside ONE transaction. If the ledger posting fails,
// the sale rolls back too - there is no path that leaves takings
// recorded on the till but missing from the books.
// ============================================================

require_once __DIR__ . '/inventory_schema.php';
require_once __DIR__ . '/inventory_functions.php';
require_once __DIR__ . '/catalog_schema.php';
require_once __DIR__ . '/catalog_functions.php';
require_once __DIR__ . '/accounting_schema.php';
require_once __DIR__ . '/accounting_functions.php';
require_once __DIR__ . '/pos_schema.php';

/** Include at the top of every POS/barcode page. */
function posBoot(mysqli $conn): void {
    require_once __DIR__ . '/shop_settings.php';
    require_once __DIR__ . '/business_types.php';
    ensureInventorySchema($conn);
    ensureCoreSchema($conn);
    ensureCatalogSchema($conn);
    ensureAccountingSchema($conn);
    ensurePosSchema($conn);
    ensureShopSettingsSchema($conn);
    // An installation that is already trading is never offered the setup
    // wizard - it is configured by definition. Costs nothing once decided.
    businessSyncSetupState($conn);
}

// ---------- Settings ---------------------------------------------------

/** Tax rate percent configured for POS (0 = no tax). */
function posTaxRate(mysqli $conn): float {
    return (float)getInvSetting($conn, 'pos_tax_rate', '0');
}

/** True when displayed prices already include tax. */
function posTaxInclusive(mysqli $conn): bool {
    return (string)getInvSetting($conn, 'pos_tax_inclusive', '0') === '1';
}

/** Footer line printed at the bottom of every receipt. */
function posReceiptFooter(mysqli $conn): string {
    return (string)getInvSetting($conn, 'pos_receipt_footer', 'Thank you for shopping with us!');
}

// ---------- Terminals --------------------------------------------------

/** Active tills, for the terminal picker. */
function posGetTerminals(mysqli $conn): array {
    $res = @$conn->query("SELECT * FROM pos_terminals WHERE is_active = 1 ORDER BY department, name");
    return ($res instanceof mysqli_result) ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

/** One terminal by id, or null. */
function posGetTerminal(mysqli $conn, int $terminalId): ?array {
    if ($terminalId <= 0) { return null; }
    $stmt = $conn->prepare("SELECT * FROM pos_terminals WHERE id = ? AND is_active = 1 LIMIT 1");
    if (!$stmt) { return null; }
    $stmt->bind_param('i', $terminalId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

// ---------- Receipt numbers -------------------------------------------

/**
 * MRT-YYYYMMDD-NNNXXX
 * NNN = daily sequence, XXX = 3 random alphanumerics so a customer
 * cannot guess another receipt's number from their own.
 */
function posGenerateReceiptNo(mysqli $conn): string {
    $prefix = 'MRT-' . date('Ymd') . '-';
    $stmt = $conn->prepare("SELECT receipt_no FROM sales_transactions WHERE receipt_no LIKE CONCAT(?, '%') ORDER BY id DESC LIMIT 1");
    $stmt->bind_param('s', $prefix);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $next = 1;
    if ($row) {
        $next = (int)substr($row['receipt_no'], strlen($prefix), 3) + 1;
    }
    return $prefix . str_pad((string)$next, 3, '0', STR_PAD_LEFT) . retailRandomAlnum(3);
}

// ---------- Product lookup --------------------------------------------

/**
 * Look an item up by barcode (exact) or, when $allowSku is true, by SKU.
 * Returns the POS-relevant fields or null. Uses the unique barcode index,
 * so this stays a single indexed row lookup even with 10k products.
 */
function posFindByBarcode(mysqli $conn, string $code, bool $allowSku = true): ?array {
    $code = trim($code);
    if ($code === '') { return null; }

    $sql = "SELECT i.id, i.name, i.sku, i.barcode, i.department, i.current_stock, i.average_cost,
                   i.expiry_date,
                   c.name AS category_name, u.abbreviation AS unit,
                   d.selling_price, d.promo_price, d.promo_active, d.is_enabled,
                   d.usage_type, d.is_pos_visible, d.package_size, d.brand,
                   (SELECT file_path FROM retail_product_images img
                     WHERE img.item_id = i.id ORDER BY img.sort_order, img.id LIMIT 1) AS image
            FROM inv_items i
            LEFT JOIN retail_product_details d ON d.item_id = i.id
            LEFT JOIN inv_categories c ON c.id = i.category_id
            LEFT JOIN inv_units u ON u.id = i.unit_id
            WHERE i.deleted_at IS NULL AND i.status = 'active'
              AND (i.barcode = ?" . ($allowSku ? " OR i.sku = ?" : "") . ")
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    if ($allowSku) { $stmt->bind_param('ss', $code, $code); }
    else { $stmt->bind_param('s', $code); }
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

/**
 * Products shown on the POS grid (sellable, enabled, POS-visible).
 * $department filters to one department's stock; blank shows everything,
 * which is what a general till or a supervisor wants.
 */
function posGetProducts(mysqli $conn, string $search = '', int $categoryId = 0, string $department = ''): array {
    $sql = "SELECT i.id, i.name, i.sku, i.barcode, i.department, i.current_stock, i.category_id,
                   i.expiry_date,
                   c.name AS category_name, u.abbreviation AS unit,
                   d.selling_price, d.promo_price, d.promo_active, d.package_size, d.brand,
                   (SELECT file_path FROM retail_product_images img
                     WHERE img.item_id = i.id ORDER BY img.sort_order, img.id LIMIT 1) AS image
            FROM inv_items i
            JOIN retail_product_details d ON d.item_id = i.id
            LEFT JOIN inv_categories c ON c.id = i.category_id
            LEFT JOIN inv_units u ON u.id = i.unit_id
            WHERE i.deleted_at IS NULL AND i.status = 'active'
              AND d.is_enabled = 1 AND d.is_pos_visible = 1
              AND d.usage_type IN ('sale','both')";
    $params = [];
    $types = '';
    if ($search !== '') {
        $sql .= " AND (i.name LIKE ? OR i.sku LIKE ? OR i.barcode LIKE ? OR d.brand LIKE ?)";
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like);
        $types .= 'ssss';
    }
    if ($categoryId > 0) {
        $sql .= " AND i.category_id = ?";
        $params[] = $categoryId;
        $types .= 'i';
    }
    if ($department !== '' && isset(catalogDepartments()[$department])) {
        $sql .= " AND i.department = ?";
        $params[] = $department;
        $types .= 's';
    }

    // A disabled department is not trading, so its products never reach
    // the grid regardless of how they are priced or flagged.
    $active = array_keys(catalogActiveDepartments($conn));
    if (!$active) { return []; }
    $sql .= " AND i.department IN (" . implode(',', array_fill(0, count($active), '?')) . ")";
    foreach ($active as $a) { $params[] = $a; $types .= 's'; }

    $sql .= " ORDER BY i.name ASC";
    $stmt = $conn->prepare($sql);
    if ($types !== '') { $stmt->bind_param($types, ...$params); }
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

/** Effective unit price for a POS row (promo wins when active). */
function posUnitPrice(array $product): float {
    return retailEffectivePrice($product);
}

// ---------- Checkout ---------------------------------------------------

/**
 * Complete a POS sale atomically.
 *
 * $lines: [['item_id'=>int, 'quantity'=>float, 'line_discount'=>float], ...]
 *         Quantities and item ids only - unit prices are ALWAYS re-read
 *         from the database, never trusted from the browser.
 * $payment: ['amount_paid'=>float, 'method'=>'cash'|'lipa_namba'|'card', 'discount'=>float]
 * $customer: ['type'=>'cash'|'registered', 'name'=>?, 'phone'=>?]
 *
 * Everything (stock validation, deduction, transaction rows, ledger
 * posting) happens in ONE database transaction with row-level locks, so
 * concurrent tills cannot oversell and a partial sale can never be
 * written.
 *
 * Returns [ok(bool), messageOrReceiptNo(string), transactionId(int)].
 */
function posCheckout(
    mysqli $conn,
    array $lines,
    array $payment,
    array $customer,
    ?int $cashierId,
    string $cashierName,
    int $terminalId = 0
): array {
    $lines = array_values(array_filter($lines, function ($l) {
        return (int)($l['item_id'] ?? 0) > 0 && (float)($l['quantity'] ?? 0) > 0;
    }));
    if (!$lines) {
        return [false, 'The cart is empty.', 0];
    }

    $terminal = posGetTerminal($conn, $terminalId);
    $terminalIdVal = $terminal ? (int)$terminal['id'] : null;

    $conn->begin_transaction();
    try {
        $subtotal = 0.0;
        $totalCost = 0.0;
        $resolved = [];
        $departmentTotals = [];

        foreach ($lines as $l) {
            $itemId = (int)$l['item_id'];
            $qty = round((float)$l['quantity'], 3);
            $lineDiscount = max(0.0, round((float)($l['line_discount'] ?? 0), 2));

            // Lock the row for the whole transaction: any other till
            // touching this item now waits until we commit or roll back.
            $stmt = $conn->prepare(
                "SELECT i.id, i.name, i.barcode, i.department, i.current_stock, i.average_cost,
                        i.expiry_date,
                        d.selling_price, d.promo_price, d.promo_active, d.is_enabled, d.usage_type
                 FROM inv_items i
                 LEFT JOIN retail_product_details d ON d.item_id = i.id
                 WHERE i.id = ? AND i.deleted_at IS NULL AND i.status = 'active'
                 FOR UPDATE");
            $stmt->bind_param('i', $itemId);
            $stmt->execute();
            $product = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$product) {
                throw new Exception('A product in the cart is no longer available.');
            }
            if (empty($product['is_enabled']) || !in_array($product['usage_type'] ?? '', ['sale', 'both'], true)) {
                throw new Exception('"' . $product['name'] . '" is not available for sale.');
            }
            // Expired stock is refused here, inside the transaction, on
            // the row we have just locked - so a cart built before the
            // date rolled over, a held sale resumed the next morning, or
            // a forged request all hit the same wall. This is the gate;
            // the grid and the scanner only save the cashier the trip.
            $expiredWhy = retailExpiryBlockReason($product);
            if ($expiredWhy !== null) {
                throw new Exception($expiredWhy);
            }
            // Re-checked here and not only on the grid: a cart could have
            // been built (or held) before an administrator switched the
            // department off.
            if (!catalogDepartmentEnabled($conn, $product['department'] ?: 'general')) {
                throw new Exception('"' . $product['name'] . '" is in the '
                    . catalogDepartmentLabel($product['department']) . ' department, which is not currently trading.');
            }
            if ($qty > (float)$product['current_stock'] + 0.0005) {
                throw new Exception('Not enough stock for "' . $product['name'] . '". Available: ' . invQty($product['current_stock']) . '.');
            }

            $unitPrice = posUnitPrice($product);
            $unitCost = (float)$product['average_cost'];
            $lineTotal = round($unitPrice * $qty, 2) - $lineDiscount;
            if ($lineTotal < 0) { $lineTotal = 0.0; }
            $subtotal += $lineTotal;
            $totalCost += round($unitCost * $qty, 2);

            $dept = $product['department'] ?: 'general';
            $departmentTotals[$dept] = ($departmentTotals[$dept] ?? 0) + $lineTotal;

            $resolved[] = [
                'item_id'    => $itemId,
                'name'       => $product['name'],
                'barcode'    => $product['barcode'],
                'department' => $dept,
                'qty'        => $qty,
                'price'      => $unitPrice,
                'cost'       => $unitCost,
                'discount'   => $lineDiscount,
                'total'      => $lineTotal,
            ];
        }

        // ---- Totals -----------------------------------------------------
        $orderDiscount = max(0.0, round((float)($payment['discount'] ?? 0), 2));
        if ($orderDiscount > $subtotal) { $orderDiscount = $subtotal; }
        $net = round($subtotal - $orderDiscount, 2);

        $taxRate = posTaxRate($conn);
        $taxInclusive = posTaxInclusive($conn);
        if ($taxRate > 0) {
            if ($taxInclusive) {
                // Price already contains tax - split it out for the receipt.
                $taxAmount = round($net - ($net / (1 + $taxRate / 100)), 2);
                $total = $net;
            } else {
                $taxAmount = round($net * $taxRate / 100, 2);
                $total = round($net + $taxAmount, 2);
            }
        } else {
            $taxAmount = 0.0;
            $total = $net;
        }

        $totalCost = round($totalCost, 2);
        $grossProfit = round($total - $taxAmount - $totalCost, 2);

        // The sale's department is whichever contributed most of its
        // value; a till selling only stationery reports as stationery
        // even when it is the general terminal.
        arsort($departmentTotals);
        $saleDepartment = $departmentTotals ? (string)array_key_first($departmentTotals) : 'general';
        if ($terminal && $terminal['department'] !== 'general' && count($departmentTotals) === 1) {
            $saleDepartment = $terminal['department'];
        }

        // ---- Tenders -----------------------------------------------------
        // A sale may be settled with several methods at once (cash +
        // mobile, mobile + bank, ...). $payment['payments'] carries them;
        // the older single-method form is still accepted so existing
        // callers keep working.
        $validMethods = array_keys(posPaymentMethods());
        $tenders = [];

        if (!empty($payment['payments']) && is_array($payment['payments'])) {
            foreach ($payment['payments'] as $p) {
                $m = in_array($p['method'] ?? '', $validMethods, true) ? $p['method'] : null;
                $a = round((float)($p['amount'] ?? 0), 2);
                if ($m === null || $a <= 0) { continue; }
                $tenders[] = ['method' => $m, 'amount' => $a,
                              'reference' => trim((string)($p['reference'] ?? '')) ?: null];
            }
        } else {
            $m = in_array($payment['method'] ?? 'cash', $validMethods, true) ? $payment['method'] : 'cash';
            $a = round((float)($payment['amount_paid'] ?? 0), 2);
            // A non-cash single tender settles exactly the total.
            if ($m !== 'cash') { $a = $total; }
            if ($a > 0) { $tenders[] = ['method' => $m, 'amount' => $a, 'reference' => null]; }
        }

        if (!$tenders) { throw new Exception('Enter how the customer is paying.'); }

        $cashTendered = 0.0;
        $nonCashTendered = 0.0;
        foreach ($tenders as $t) {
            if ($t['method'] === 'cash') { $cashTendered += $t['amount']; }
            else { $nonCashTendered += $t['amount']; }
        }
        $amountPaid = round($cashTendered + $nonCashTendered, 2);

        // Change can only ever come out of the cash drawer, so an
        // electronic tender must never exceed what is owed - otherwise the
        // till would owe change it has no way to give back.
        if ($nonCashTendered > $total + 0.005) {
            throw new Exception('Electronic payment (' . number_format($nonCashTendered)
                . ') is more than the total (' . number_format($total) . '). Reduce it, or take the difference in cash.');
        }
        if ($amountPaid + 0.005 < $total) {
            throw new Exception('Short by ' . number_format($total - $amountPaid)
                . '. Total is ' . number_format($total) . ', tendered ' . number_format($amountPaid) . '.');
        }

        $changeDue = round(max(0.0, $amountPaid - $total), 2);

        // The header keeps a single method for every existing read
        // (receipts, sales list, reports); 'split' marks a mixed tender
        // and the detail lives in sales_payments.
        $method = count($tenders) === 1 ? $tenders[0]['method'] : 'split';

        // ---- Customer (walk-in cash by default) --------------------------
        $custType = ($customer['type'] ?? 'cash') === 'registered' ? 'registered' : 'cash';
        $custId = null;
        $custName = trim((string)($customer['name'] ?? '')) ?: null;
        $custPhone = trim((string)($customer['phone'] ?? '')) ?: null;
        if ($custType === 'registered' && $custPhone) {
            // Same phone-is-identity rule as the rest of the system.
            $custPhone = retailNormalizePhone($custPhone);
            $custId = retailFindOrCreateCustomer($conn, $custPhone, $custName ?: 'Walk-in customer');
            if ($custId <= 0) { $custId = null; }
        }

        // ---- Institutional details (organisation / government customer) --
        // Merge anything newly typed into the stored customer record -
        // never overwrite a stored value with a blank one, so leaving a
        // field empty on a later visit doesn't erase what was captured
        // before. The sale then snapshots the EFFECTIVE (post-merge)
        // values, never a live join, so an edit to the customer record
        // later can never rewrite an old receipt.
        $newAddress = trim((string)($customer['address'] ?? ''));
        $newTin     = trim((string)($customer['tin'] ?? ''));
        $newEmail   = trim((string)($customer['email'] ?? ''));
        $effAddress = $newAddress;
        $effTin     = $newTin;
        $effEmail   = $newEmail;
        if ($custId) {
            $cStmt = $conn->prepare("SELECT address, tin, email FROM customer WHERE id = ?");
            $cStmt->bind_param('i', $custId);
            $cStmt->execute();
            $stored = $cStmt->get_result()->fetch_assoc() ?: [];
            $cStmt->close();

            if ($newAddress === '') { $effAddress = (string)($stored['address'] ?? ''); }
            if ($newTin === '')     { $effTin     = (string)($stored['tin'] ?? ''); }
            if ($newEmail === '')   { $effEmail   = (string)($stored['email'] ?? ''); }

            if ($newAddress !== '' || $newTin !== '' || $newEmail !== '') {
                $uStmt = $conn->prepare("UPDATE customer SET
                    address = IF(? <> '', ?, address),
                    tin     = IF(? <> '', ?, tin),
                    email   = IF(? <> '', ?, email)
                    WHERE id = ?");
                $uStmt->bind_param('ssssssi', $newAddress, $newAddress, $newTin, $newTin, $newEmail, $newEmail, $custId);
                $uStmt->execute();
                $uStmt->close();
            }
        }
        $snapAddress = $effAddress !== '' ? $effAddress : null;
        $snapTin     = $effTin !== ''     ? $effTin     : null;
        $snapEmail   = $effEmail !== ''   ? $effEmail   : null;

        // ---- Header row (retry once on receipt-number collision) ---------
        $receiptNo = '';
        $txnId = 0;
        $note = trim((string)($payment['note'] ?? '')) ?: null;
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $receiptNo = posGenerateReceiptNo($conn);
            $ins = $conn->prepare("INSERT INTO sales_transactions
                (receipt_no, terminal_id, department, cashier_id, cashier_name,
                 customer_type, customer_id, customer_name, customer_phone,
                 customer_tin, customer_address, customer_email,
                 subtotal, discount, tax_rate, tax_amount, total, total_cost, gross_profit,
                 amount_paid, change_due, payment_method, note)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            // receipt(s) terminal(i) dept(s) cashier_id(i) cashier(s)
            // cust_type(s) cust_id(i) cust_name(s) cust_phone(s)
            // cust_tin(s) cust_address(s) cust_email(s)
            // then 9 decimals, then method(s) note(s) = 23 parameters.
            $ins->bind_param('sisississsssdddddddddss',
                $receiptNo, $terminalIdVal, $saleDepartment, $cashierId, $cashierName,
                $custType, $custId, $custName, $custPhone,
                $snapTin, $snapAddress, $snapEmail,
                $subtotal, $orderDiscount, $taxRate, $taxAmount, $total, $totalCost, $grossProfit,
                $amountPaid, $changeDue, $method, $note);
            if ($ins->execute()) {
                $txnId = (int)$conn->insert_id;
                $ins->close();
                break;
            }
            $dup = ($conn->errno === 1062);
            $ins->close();
            if (!$dup) { throw new Exception('Could not save the sale.'); }
        }
        if ($txnId <= 0) { throw new Exception('Could not save the sale. Please try again.'); }

        // ---- Lines + stock deduction ------------------------------------
        require_once __DIR__ . '/inv_batches_functions.php';
        $lineStmt = $conn->prepare("INSERT INTO sales_transaction_items
            (transaction_id, item_id, item_name, barcode, department, quantity, unit_price, unit_cost, line_discount, line_total)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($resolved as $r) {
            $lineStmt->bind_param('iisssddddd',
                $txnId, $r['item_id'], $r['name'], $r['barcode'], $r['department'],
                $r['qty'], $r['price'], $r['cost'], $r['discount'], $r['total']);
            if (!$lineStmt->execute()) { throw new Exception('Could not save the sale items.'); }
            $saleItemId = (int)$conn->insert_id;

            // FEFO first: deduct from the oldest active batch(es) and remember
            // exactly which batches this line consumed so voids can re-credit them.
            $fefo = deductFefoBatches($conn, (int)$r['item_id'], (float)$r['qty']);
            if ($fefo['ok'] && $fefo['allocations']) {
                $batchStmt = $conn->prepare("INSERT INTO sales_transaction_item_batches
                    (sale_item_id, batch_id, qty, unit_cost) VALUES (?, ?, ?, ?)");
                foreach ($fefo['allocations'] as $alloc) {
                    [$ok, $msg] = recordStockMovement(
                        $conn, (int)$r['item_id'], 'issue', -(float)$alloc['qty'], $cashierId,
                        'POS sale ' . $receiptNo, 0.0, null, 'pos_sale', $txnId, false, (int)$alloc['batch_id']
                    );
                    if (!$ok) { throw new Exception($r['name'] . ': ' . $msg); }

                    $batchId = (int)$alloc['batch_id'];
                    $qty = (float)$alloc['qty'];
                    $unitCost = (float)$alloc['unit_cost'];
                    $batchStmt->bind_param('iidd', $saleItemId, $batchId, $qty, $unitCost);
                    if (!$batchStmt->execute()) { throw new Exception('Could not save the sale batch details.'); }
                }
                $batchStmt->close();
            } else {
                // Legacy or gap fallback: the till must not refuse a sale just
                // because batch rows are missing. Deduct the blended item stock.
                if (!$fefo['ok']) {
                    error_log('FEFO fallback for item ' . (int)$r['item_id'] . ' on sale ' . $receiptNo . ': ' . $fefo['message']);
                }
                [$ok, $msg] = recordStockMovement(
                    $conn, $r['item_id'], 'issue', -$r['qty'], $cashierId,
                    'POS sale ' . $receiptNo, 0.0, null, 'pos_sale', $txnId, false
                );
                if (!$ok) { throw new Exception($r['name'] . ': ' . $msg); }
            }
        }
        $lineStmt->close();

        // ---- Tender rows --------------------------------------------------
        $payStmt = $conn->prepare("INSERT INTO sales_payments (transaction_id, method, amount, reference)
                                   VALUES (?, ?, ?, ?)");
        foreach ($tenders as $t) {
            $payStmt->bind_param('isds', $txnId, $t['method'], $t['amount'], $t['reference']);
            if (!$payStmt->execute()) { throw new Exception('Could not save the payment details.'); }
        }
        $payStmt->close();

        // ---- Accounting ---------------------------------------------------
        // Inside the same transaction: if the books can't take the sale,
        // the sale doesn't happen.
        //
        // The ledger is posted from the tenders, minus any change given.
        // Change leaves the drawer, so debiting the full cash tendered
        // would overstate Cash on Hand by exactly the change.
        $ledgerTenders = [];
        $changeLeft = $changeDue;
        foreach ($tenders as $t) {
            $amount = $t['amount'];
            if ($t['method'] === 'cash' && $changeLeft > 0) {
                $deduct = min($amount, $changeLeft);
                $amount = round($amount - $deduct, 2);
                $changeLeft = round($changeLeft - $deduct, 2);
            }
            if ($amount > 0) { $ledgerTenders[] = ['method' => $t['method'], 'amount' => $amount]; }
        }

        [$accOk, $accMsg] = accPostSale(
            $conn, $txnId, $receiptNo, $total, $taxAmount, $ledgerTenders,
            $totalCost, $saleDepartment, $cashierId, $cashierName, false
        );
        if (!$accOk) { throw new Exception('Ledger posting failed: ' . $accMsg); }

        invAudit($conn, $cashierId, 'pos_sale', 'sales_transaction', $txnId, $receiptNo . ' - ' . number_format($total));

        $conn->commit();
        return [true, $receiptNo, $txnId];
    } catch (Throwable $e) {
        $conn->rollback();
        return [false, $e->getMessage(), 0];
    }
}

/**
 * Void a completed sale: returns every line's stock, reverses the
 * ledger entry and flags the transaction. Idempotent - voiding twice
 * is a no-op.
 */
function posVoidSale(mysqli $conn, int $txnId, ?int $userId, string $reason = '', string $userName = ''): array {
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("SELECT * FROM sales_transactions WHERE id = ? FOR UPDATE");
        $stmt->bind_param('i', $txnId);
        $stmt->execute();
        $txn = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$txn) { throw new Exception('Sale not found.'); }
        if ($txn['status'] === 'voided') { $conn->commit(); return [true, 'This sale was already voided.']; }

        $lstmt = $conn->prepare("SELECT id, item_id, item_name, quantity FROM sales_transaction_items WHERE transaction_id = ?");
        $lstmt->bind_param('i', $txnId);
        $lstmt->execute();
        $lines = $lstmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $lstmt->close();

        require_once __DIR__ . '/inv_batches_functions.php';
        foreach ($lines as $l) {
            $saleItemId = (int)$l['id'];
            // Check if this sale line has batch attribution.
            $bstmt = $conn->prepare("SELECT batch_id, qty, unit_cost FROM sales_transaction_item_batches WHERE sale_item_id = ?");
            $bstmt->bind_param('i', $saleItemId);
            $bstmt->execute();
            $batchRows = $bstmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $bstmt->close();

            if ($batchRows) {
                // Re-credit the exact batches this sale consumed.
                creditFefoBatches($conn, $batchRows);

                foreach ($batchRows as $br) {
                    [$ok, $msg] = recordStockMovement(
                        $conn, (int)$l['item_id'], 'return', (float)$br['qty'], $userId,
                        'Void of POS sale ' . $txn['receipt_no'], 0.0, null, 'pos_sale', $txnId, false, (int)$br['batch_id']
                    );
                    if (!$ok) { throw new Exception($l['item_name'] . ': ' . $msg); }
                }
            } else {
                // No batch attribution (legacy sale or fallback): return via the old path.
                [$ok, $msg] = recordStockMovement(
                    $conn, (int)$l['item_id'], 'return', (float)$l['quantity'], $userId,
                    'Void of POS sale ' . $txn['receipt_no'], 0.0, null, 'pos_sale', $txnId, false
                );
                if (!$ok) { throw new Exception($l['item_name'] . ': ' . $msg); }
            }
        }

        // Reverse the money side too, or the day's takings would still
        // include a sale that no longer exists. Each tender is refunded
        // to the account it originally landed in; the cash leg is reduced
        // by whatever change was already handed back, mirroring exactly
        // what the sale debited.
        $pstmt = $conn->prepare("SELECT method, amount FROM sales_payments WHERE transaction_id = ? ORDER BY id");
        $pstmt->bind_param('i', $txnId);
        $pstmt->execute();
        $paidRows = $pstmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $pstmt->close();

        $refundTenders = [];
        $changeLeft = (float)$txn['change_due'];
        foreach ($paidRows as $p) {
            $amount = (float)$p['amount'];
            if ($p['method'] === 'cash' && $changeLeft > 0) {
                $deduct = min($amount, $changeLeft);
                $amount = round($amount - $deduct, 2);
                $changeLeft = round($changeLeft - $deduct, 2);
            }
            if ($amount > 0) { $refundTenders[] = ['method' => $p['method'], 'amount' => $amount]; }
        }
        // Sales predating the tender table fall back to the header method.
        if (!$refundTenders) {
            $refundTenders = [['method' => $txn['payment_method'], 'amount' => (float)$txn['total']]];
        }

        [$accOk, $accMsg] = accPostSaleVoid(
            $conn, $txnId, $txn['receipt_no'], (float)$txn['total'], (float)$txn['tax_amount'],
            $refundTenders, (float)$txn['total_cost'], $txn['department'],
            $userId, $userName, false
        );
        if (!$accOk) { throw new Exception('Ledger reversal failed: ' . $accMsg); }

        $upd = $conn->prepare("UPDATE sales_transactions SET status = 'voided', void_reason = ?, voided_by = ?, voided_at = NOW() WHERE id = ?");
        $upd->bind_param('sii', $reason, $userId, $txnId);
        $upd->execute();
        $upd->close();

        invAudit($conn, $userId, 'pos_void', 'sales_transaction', $txnId, $txn['receipt_no'] . ' - ' . $reason);
        $conn->commit();
        return [true, 'Sale ' . $txn['receipt_no'] . ' voided, stock returned and ledger reversed.'];
    } catch (Throwable $e) {
        $conn->rollback();
        return [false, $e->getMessage()];
    }
}

// ---------- Stock-in (barcode station) ---------------------------------

/**
 * Increment stock for one item by barcode/id (storekeeper scanning
 * deliveries in). Uses the same locked audit-trail movement.
 * $mode: 'add' (receive N more) or 'set' (physical count = N).
 * $expiryDate: optional best-before date for this delivery (mode 'add'
 * only) - not every item is perishable, so it's fine to leave blank.
 * Returns [ok, message, newQty].
 */
function posStockIn(mysqli $conn, int $itemId, float $qty, ?int $userId, string $mode = 'add', float $unitCost = 0.0, string $reason = '', ?string $expiryDate = null): array {
    if ($itemId <= 0) { return [false, 'Unknown item.', 0.0]; }
    if ($mode === 'add' && $qty <= 0) { return [false, 'Quantity must be greater than zero.', 0.0]; }
    if ($mode === 'set' && $qty < 0) { return [false, 'Counted quantity cannot be negative.', 0.0]; }
    // A malformed or blank date is treated as "not supplied" rather than
    // rejecting the whole stock-in - most goods aren't perishable.
    $expiryDate = ($expiryDate !== null && strtotime($expiryDate) !== false) ? $expiryDate : null;

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("SELECT name, current_stock FROM inv_items WHERE id = ? AND deleted_at IS NULL FOR UPDATE");
        $stmt->bind_param('i', $itemId);
        $stmt->execute();
        $item = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$item) { throw new Exception('Item not found.'); }

        $current = (float)$item['current_stock'];
        if ($mode === 'set') {
            $delta = round($qty - $current, 3);
            if (abs($delta) < 0.0005) {
                $conn->commit();
                return [true, $item['name'] . ': count matches system stock (' . invQty($current) . ') - no change.', $current];
            }
            $type = 'adjust';
            $why = $reason !== '' ? $reason : 'Physical count: system ' . invQty($current) . ' -> counted ' . invQty($qty);
        } else {
            $delta = $qty;
            $type = 'receive';
            $why = $reason !== '' ? $reason : 'Stock-in via barcode scan';
        }

        [$ok, $msg, $after] = recordStockMovement(
            $conn, $itemId, $type, $delta, $userId, $why, $unitCost, null, 'barcode_station', null, false
        );
        if (!$ok) { throw new Exception($msg); }

        // Maintain the batch invariant: any stock added here needs a batch
        // row, or current_stock drifts from SUM(active batches) and FEFO
        // falls back at the till.
        require_once __DIR__ . '/inv_batches_functions.php';
        if ($delta > 0) {
            // Adding stock: create a batch, dated if the storekeeper
            // supplied a best-before date for this delivery. Undated
            // batches sort last in FEFO (treated as non-perishable).
            $batchCost = $unitCost > 0 ? $unitCost : 0.0;
            if ($batchCost <= 0) {
                $costStmt = $conn->prepare("SELECT average_cost, purchase_price FROM inv_items WHERE id = ?");
                $costStmt->bind_param('i', $itemId);
                $costStmt->execute();
                $costRow = $costStmt->get_result()->fetch_assoc();
                $costStmt->close();
                $batchCost = (float)($costRow['average_cost'] > 0 ? $costRow['average_cost'] : $costRow['purchase_price']);
            }
            [$bOk, $bMsg, $batchId] = createBatch(
                $conn, $itemId, null, $delta, $batchCost, $expiryDate, 'barcode_station', null
            );
            if (!$bOk) { error_log("Batch creation failed for barcode stock-in of item {$itemId}: $bMsg"); }
        } elseif ($delta < 0) {
            // Removing stock (physical count lower than system): deduct from
            // batches FEFO-style so the batch invariant holds. If batches
            // can't cover (gap), the stock movement already happened and
            // the drift is logged at checkout.
            $fefo = deductFefoBatches($conn, $itemId, abs($delta));
            if (!$fefo['ok']) {
                error_log("FEFO deduction failed for barcode count adjustment of item {$itemId}: {$fefo['message']}");
            }
        }
        // Either branch can change which batch is now the earliest-active
        // one (a fresh dated batch arriving, or the old one depleting) -
        // keep the item's own expiry_date (what pricing/checkout actually
        // read) in step with it either way.
        refreshItemExpiryFromBatches($conn, $itemId);

        $conn->commit();
        return [true, $item['name'] . ': ' . ($mode === 'set' ? 'adjusted to ' : 'stock now ') . invQty($after) . '.', $after];
    } catch (Throwable $e) {
        $conn->rollback();
        return [false, $e->getMessage(), 0.0];
    }
}

/**
 * Assign a barcode to an item (storekeeper labelling unbarcoded stock).
 * Enforces uniqueness with a friendly message instead of a DB error.
 */
function posAssignBarcode(mysqli $conn, int $itemId, string $barcode, ?int $userId): array {
    $barcode = trim($barcode);
    if ($itemId <= 0) { return [false, 'Unknown item.']; }
    if ($barcode === '') { return [false, 'Barcode cannot be empty.']; }
    if (!preg_match('/^[A-Za-z0-9\-\.\ \$\/\+\%]{4,80}$/', $barcode)) {
        return [false, 'That barcode contains characters Code128 cannot encode.'];
    }

    $chk = $conn->prepare("SELECT id, name FROM inv_items WHERE barcode = ? AND id <> ? AND deleted_at IS NULL LIMIT 1");
    $chk->bind_param('si', $barcode, $itemId);
    $chk->execute();
    $clash = $chk->get_result()->fetch_assoc();
    $chk->close();
    if ($clash) { return [false, 'That barcode is already used by "' . $clash['name'] . '".']; }

    $upd = $conn->prepare("UPDATE inv_items SET barcode = ? WHERE id = ? AND deleted_at IS NULL");
    $upd->bind_param('si', $barcode, $itemId);
    $ok = $upd->execute();
    $upd->close();
    if (!$ok) { return [false, 'Could not save the barcode.']; }

    invAudit($conn, $userId, 'barcode_assign', 'item', $itemId, $barcode);
    return [true, 'Barcode saved.'];
}

/**
 * Generate an unused internal barcode for an item that has none.
 * Format: MRT + 9 digits (Code128-friendly, no checksum needed).
 */
function posGenerateBarcode(mysqli $conn): string {
    do {
        $code = 'MRT' . str_pad((string)random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
        $stmt = $conn->prepare("SELECT id FROM inv_items WHERE barcode = ? LIMIT 1");
        $stmt->bind_param('s', $code);
        $stmt->execute();
        $taken = $stmt->get_result()->num_rows > 0;
        $stmt->close();
    } while ($taken);
    return $code;
}

// ---------- Held sales -------------------------------------------------

function posHoldSale(mysqli $conn, array $cart, ?int $cashierId, string $cashierName, string $label, float $totalEstimate, int $terminalId = 0): array {
    if (!$cart) { return [false, 'Nothing to hold - the cart is empty.', 0]; }
    $json = json_encode(array_values($cart));
    $count = count($cart);
    $label = trim($label) ?: ('Held ' . date('H:i'));
    $terminalIdVal = $terminalId > 0 ? $terminalId : null;
    $stmt = $conn->prepare("INSERT INTO pos_held_sales (label, terminal_id, cashier_id, cashier_name, cart_json, item_count, total_estimate) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param('siissid', $label, $terminalIdVal, $cashierId, $cashierName, $json, $count, $totalEstimate);
    $ok = $stmt->execute();
    $id = (int)$conn->insert_id;
    $stmt->close();
    return $ok ? [true, 'Sale held as "' . $label . '".', $id] : [false, 'Could not hold the sale.', 0];
}

function posGetHeldSales(mysqli $conn): array {
    $res = @$conn->query("SELECT * FROM pos_held_sales ORDER BY created_at DESC LIMIT 30");
    return ($res instanceof mysqli_result) ? $res->fetch_all(MYSQLI_ASSOC) : [];
}

function posDeleteHeldSale(mysqli $conn, int $id): void {
    $stmt = $conn->prepare("DELETE FROM pos_held_sales WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $stmt->close();
}

/**
 * Permanent record of a cart that never became a sale - the live cart
 * emptied via Clear Cart (or its last item removed), or a parked held
 * sale discarded. A reason is mandatory by the time this is called (the
 * caller enforces that; this just refuses to write an empty one, failing
 * closed rather than silently accepting an unaudited cancellation).
 *
 * When $source is 'held_sale', the held sale row is deleted HERE, inside
 * the same transaction as the audit insert - so the row is either fully
 * recorded before the held sale disappears, or (on any failure) the held
 * sale is left untouched rather than vanishing unaudited.
 *
 * Returns [ok, message, id].
 */
function posLogCancelledCart(
    mysqli $conn,
    ?int $cashierId,
    string $cashierName,
    int $terminalId,
    string $source,
    ?int $heldSaleId,
    string $reasonCode,
    string $reasonDetail,
    array $items,
    float $totalValue
): array {
    $reasonCode = trim($reasonCode);
    if ($reasonCode === '') { return [false, 'A cancellation reason is required.', 0]; }
    $source = ($source === 'held_sale') ? 'held_sale' : 'live_cart';
    $terminalIdVal = $terminalId > 0 ? $terminalId : null;
    $heldSaleIdVal = ($source === 'held_sale' && $heldSaleId && $heldSaleId > 0) ? $heldSaleId : null;
    $reasonDetailVal = trim($reasonDetail) ?: null;
    $json = json_encode(array_values($items));
    $itemCount = count($items);

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare(
            "INSERT INTO cancelled_carts
                (cashier_id, cashier_name, terminal_id, source, held_sale_id,
                 reason_code, reason_detail, itemized_cart_json, item_count, total_value)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('isisisssid',
            $cashierId, $cashierName, $terminalIdVal, $source, $heldSaleIdVal,
            $reasonCode, $reasonDetailVal, $json, $itemCount, $totalValue);
        if (!$stmt->execute()) { $stmt->close(); throw new Exception('Could not record the cancellation.'); }
        $id = (int)$conn->insert_id;
        $stmt->close();

        if ($heldSaleIdVal !== null) {
            posDeleteHeldSale($conn, $heldSaleIdVal);
        }

        $conn->commit();
        return [true, 'Cancellation recorded.', $id];
    } catch (Throwable $e) {
        $conn->rollback();
        return [false, $e->getMessage(), 0];
    }
}

// ---------- Receipt payload -------------------------------------------

/** Full sale (header + lines) for the receipt view / API response. */
function posGetSale(mysqli $conn, int $txnId): ?array {
    $stmt = $conn->prepare(
        "SELECT t.*, term.name AS terminal_name, term.code AS terminal_code
         FROM sales_transactions t
         LEFT JOIN pos_terminals term ON term.id = t.terminal_id
         WHERE t.id = ?");
    $stmt->bind_param('i', $txnId);
    $stmt->execute();
    $txn = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$txn) { return null; }

    $stmt = $conn->prepare("SELECT * FROM sales_transaction_items WHERE transaction_id = ? ORDER BY id");
    $stmt->bind_param('i', $txnId);
    $stmt->execute();
    $txn['items'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // Tenders, so the receipt can print each one separately.
    $stmt = $conn->prepare("SELECT method, amount, reference FROM sales_payments WHERE transaction_id = ? ORDER BY id");
    $stmt->bind_param('i', $txnId);
    $stmt->execute();
    $txn['payments'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $txn;
}
