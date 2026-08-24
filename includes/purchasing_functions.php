<?php
// ============================================================
// Purchasing - receiving goods and paying suppliers
// ------------------------------------------------------------
// This file sits ABOVE both inventory and accounting: it is the
// only place that knows a goods receipt is simultaneously a stock
// movement and a ledger entry. Neither of the modules below it
// depends on the other, which keeps their includes acyclic.
//
// The accounting behind purchasing, in one place:
//
//   Receive goods   DR Inventory Asset   /  CR Accounts Payable
//   Pay supplier    DR Accounts Payable  /  CR Cash / Mobile / Bank
//   Sell the goods  DR Cost of Goods Sold /  CR Inventory Asset
//                                            (done by accPostSale)
//
// Stock is an asset from the moment it arrives and only becomes an
// expense when it sells. Skipping the first line is what makes an
// Inventory Asset account drift negative.
// ============================================================

require_once __DIR__ . '/inventory_functions.php';
require_once __DIR__ . '/accounting_functions.php';

/** Include at the top of any purchasing page. */
function purchasingBoot(mysqli $conn): void {
    ensureInventorySchema($conn);
    ensureCoreSchema($conn);
    ensureAccountingSchema($conn);
}

// ---------- Reference numbers ------------------------------------------

/** GRN-YYYYMMDD-NNN (goods received note). */
function poNextReceiptNo(mysqli $conn): string {
    return poNextSequential($conn, 'inv_po_receipts', 'receipt_no', 'GRN-');
}

/** SP-YYYYMMDD-NNN (supplier payment). */
function poNextPaymentNo(mysqli $conn): string {
    return poNextSequential($conn, 'inv_po_payments', 'payment_no', 'SP-');
}

function poNextSequential(mysqli $conn, string $table, string $column, string $prefix): string {
    $prefix .= date('Ymd') . '-';
    $stmt = $conn->prepare("SELECT `$column` FROM `$table` WHERE `$column` LIKE CONCAT(?, '%') ORDER BY id DESC LIMIT 1");
    $stmt->bind_param('s', $prefix);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $next = $row ? (int)substr($row[$column], strlen($prefix)) + 1 : 1;
    return $prefix . str_pad((string)$next, 3, '0', STR_PAD_LEFT);
}

// ---------- Receiving ---------------------------------------------------

/**
 * Receive quantities against a purchase order.
 *
 * $quantities: [po_line_id => qty]. Anything above what is still
 * outstanding on a line is trimmed down rather than rejected, so a
 * fat-fingered entry can never inflate stock beyond what was ordered.
 *
 * Stock movements, the receipt record, the PO status and the ledger
 * entry all happen in ONE transaction: if the books refuse the receipt,
 * the stock does not move either.
 *
 * Returns [ok, message, receiptId].
 */
function poReceiveStock(mysqli $conn, int $poId, array $quantities, ?int $userId, string $userName, string $note = ''): array {
    $stmt = $conn->prepare("SELECT po.*, s.name AS supplier_name FROM inv_purchase_orders po
        LEFT JOIN inv_suppliers s ON s.id = po.supplier_id
        WHERE po.id = ? AND po.deleted_at IS NULL");
    $stmt->bind_param('i', $poId);
    $stmt->execute();
    $po = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$po) { return [false, 'Purchase order not found.', 0]; }
    if (!in_array($po['status'], ['approved', 'partially_received'], true)) {
        return [false, 'Only an approved purchase order can be received against.', 0];
    }

    $conn->begin_transaction();
    try {
        $lines = $conn->query("SELECT * FROM inv_purchase_order_lines WHERE po_id = " . (int)$poId)->fetch_all(MYSQLI_ASSOC);

        $totalValue = 0.0;
        $lineCount = 0;

        foreach ($lines as $ln) {
            $outstanding = (float)$ln['quantity'] - (float)$ln['received_qty'];
            $take = (float)($quantities[$ln['id']] ?? 0);
            if ($take <= 0 || $outstanding <= 0) { continue; }
            if ($take > $outstanding) { $take = $outstanding; }

            $unitPrice = (float)$ln['unit_price'];

            // Stock in, through the shared audit trail. Passing the unit
            // price lets recordStockMovement maintain weighted-average
            // cost, which is what the next sale's COGS will be based on.
            [$ok, $msg] = recordStockMovement(
                $conn, (int)$ln['item_id'], 'receive', $take, $userId,
                'Received on PO ' . $po['po_number'], $unitPrice, null, 'purchase_order', $poId, false
            );
            if (!$ok) { throw new Exception($msg); }

            // Store the line data for batch creation after receipt ID is available.
            $receivedLines[] = [
                'item_id' => (int)$ln['item_id'],
                'line_id' => (int)$ln['id'],
                'qty' => $take,
                'unit_price' => $unitPrice,
            ];

            $upd = $conn->prepare("UPDATE inv_purchase_order_lines SET received_qty = received_qty + ? WHERE id = ?");
            $upd->bind_param('di', $take, $ln['id']);
            $upd->execute();
            $upd->close();

            $totalValue += $take * $unitPrice;
            $lineCount++;
        }

        if ($lineCount === 0) { throw new Exception('Enter at least one quantity to receive.'); }
        $totalValue = round($totalValue, 2);

        // The receipt record - one row per delivery, so a PO received in
        // stages has a full goods-received history.
        $receiptNo = poNextReceiptNo($conn);
        $noteVal = trim($note) ?: null;
        $ins = $conn->prepare("INSERT INTO inv_po_receipts
            (po_id, receipt_no, total_value, line_count, note, received_by, received_by_name)
            VALUES (?, ?, ?, ?, ?, ?, ?)");
        $ins->bind_param('isdisis', $poId, $receiptNo, $totalValue, $lineCount, $noteVal, $userId, $userName);
        if (!$ins->execute()) { $ins->close(); throw new Exception('Could not save the goods receipt.'); }
        $receiptId = (int)$conn->insert_id;
        $ins->close();

        // Create batches for each received line (now that receipt ID is available).
        require_once __DIR__ . '/inv_batches_functions.php';
        foreach ($receivedLines as $rl) {
            $batchNo = 'PO-' . $po['po_number'] . '-L' . $rl['line_id'];
            [$bOk, $bMsg, $batchId] = createBatch(
                $conn, $rl['item_id'], $batchNo, $rl['qty'], $rl['unit_price'],
                null, 'purchase_order', $receiptId
            );
            // Non-fatal: if batch creation fails, the stock movement still succeeded.
            if (!$bOk) { error_log("Batch creation failed for PO line {$rl['line_id']}: $bMsg"); }
        }

        // The ledger half. Inside this transaction on purpose.
        [$accOk, $accMsg, $journalId] = accPostGoodsReceipt(
            $conn, $receiptId, $receiptNo . ' (PO ' . $po['po_number'] . ')',
            $totalValue, $userId, $userName, false
        );
        if (!$accOk) { throw new Exception('Ledger posting failed: ' . $accMsg); }

        if ($journalId > 0) {
            $u = $conn->prepare("UPDATE inv_po_receipts SET journal_id = ? WHERE id = ?");
            $u->bind_param('ii', $journalId, $receiptId);
            $u->execute();
            $u->close();
        }

        // Fully or partially received?
        $chk = $conn->query("SELECT SUM(quantity) AS q, SUM(received_qty) AS r
                             FROM inv_purchase_order_lines WHERE po_id = " . (int)$poId)->fetch_assoc();
        $newStatus = ((float)$chk['r'] + 0.0005 >= (float)$chk['q']) ? 'received' : 'partially_received';
        $rd = $conn->prepare("UPDATE inv_purchase_orders SET status = ?, received_date = CURDATE() WHERE id = ?");
        $rd->bind_param('si', $newStatus, $poId);
        $rd->execute();
        $rd->close();

        // Remember where the goods came from, when the item has no
        // supplier recorded yet.
        if (!empty($po['supplier_id'])) {
            $conn->query("UPDATE inv_items i
                JOIN inv_purchase_order_lines l ON l.item_id = i.id
                SET i.supplier_id = " . (int)$po['supplier_id'] . "
                WHERE l.po_id = " . (int)$poId . " AND i.supplier_id IS NULL");
        }

        invAudit($conn, $userId, 'po_receive', 'purchase_order', $poId,
            $receiptNo . ' - ' . number_format($totalValue, 2));

        $conn->commit();
        return [true, 'Received ' . $lineCount . ' line(s) worth ' . number_format($totalValue, 2)
                    . ' as ' . $receiptNo . '. Stock and ledger both updated.', $receiptId];
    } catch (Throwable $e) {
        $conn->rollback();
        return [false, $e->getMessage(), 0];
    }
}

// ---------- Paying the supplier ----------------------------------------

/**
 * Record a payment against a purchase order and post it to the ledger.
 * Refuses to pay more than is actually outstanding, so Accounts Payable
 * can never be driven negative by a typo.
 *
 * Returns [ok, message, paymentId].
 */
function poRecordPayment(
    mysqli $conn,
    int $poId,
    float $amount,
    int $paidFromAccountId,
    string $paymentDate,
    string $reference,
    ?int $userId,
    string $userName,
    ?string $efdReceiptFile = null
): array {
    $amount = round($amount, 2);
    if ($amount <= 0) { return [false, 'Enter an amount greater than zero.', 0]; }
    if ($paidFromAccountId <= 0) { return [false, 'Choose which account paid the supplier.', 0]; }
    if (!strtotime($paymentDate)) { $paymentDate = date('Y-m-d'); }

    $stmt = $conn->prepare("SELECT * FROM inv_purchase_orders WHERE id = ? AND deleted_at IS NULL");
    $stmt->bind_param('i', $poId);
    $stmt->execute();
    $po = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$po) { return [false, 'Purchase order not found.', 0]; }

    $summary = poPaymentSummary($conn, $poId);
    if ($summary['outstanding'] <= 0.005) {
        return [false, 'Nothing is outstanding on this purchase order.', 0];
    }
    if ($amount > $summary['outstanding'] + 0.005) {
        return [false, 'That is more than the ' . number_format($summary['outstanding'], 2)
                     . ' still outstanding on this order.', 0];
    }
    // A payment that fully settles the order requires the supplier's TRA
    // EFD receipt on file first - a partial/interim payment may still
    // carry one, but isn't blocked without it. Checked before the
    // transaction opens, so nothing is written on a rejection.
    if ($amount >= $summary['outstanding'] - 0.005 && ($efdReceiptFile === null || $efdReceiptFile === '')) {
        return [false, "Attach the supplier's EFD receipt to record the final payment on this order.", 0];
    }

    $conn->begin_transaction();
    try {
        $paymentNo = poNextPaymentNo($conn);
        $refVal = trim($reference) ?: null;

        $efdVal = ($efdReceiptFile !== null && $efdReceiptFile !== '') ? $efdReceiptFile : null;
        $ins = $conn->prepare("INSERT INTO inv_po_payments
            (po_id, payment_no, amount, paid_from_account_id, payment_date, reference, efd_receipt_file, paid_by, paid_by_name)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $ins->bind_param('isdisssis', $poId, $paymentNo, $amount, $paidFromAccountId,
                         $paymentDate, $refVal, $efdVal, $userId, $userName);
        if (!$ins->execute()) { $ins->close(); throw new Exception('Could not save the payment.'); }
        $paymentId = (int)$conn->insert_id;
        $ins->close();

        [$accOk, $accMsg, $journalId] = accPostSupplierPayment(
            $conn, $paymentId, $paymentNo . ' (PO ' . $po['po_number'] . ')',
            $amount, $paidFromAccountId, $userId, $userName, false
        );
        if (!$accOk) { throw new Exception('Ledger posting failed: ' . $accMsg); }

        $u = $conn->prepare("UPDATE inv_po_payments SET journal_id = ? WHERE id = ?");
        $u->bind_param('ii', $journalId, $paymentId);
        $u->execute();
        $u->close();

        // payment_status is derived, never typed in.
        poRefreshPaymentStatus($conn, $poId);

        invAudit($conn, $userId, 'po_payment', 'purchase_order', $poId,
            $paymentNo . ' - ' . number_format($amount, 2));

        $conn->commit();
        return [true, 'Payment ' . $paymentNo . ' of ' . number_format($amount, 2)
                    . ' recorded and posted to the ledger.', $paymentId];
    } catch (Throwable $e) {
        $conn->rollback();
        return [false, $e->getMessage(), 0];
    }
}

/**
 * What this purchase order is worth, has been paid, and still owes.
 * "Billed" is the value actually RECEIVED, not the value ordered -
 * you do not owe a supplier for goods that never arrived.
 */
function poPaymentSummary(mysqli $conn, int $poId): array {
    $billed = 0.0;
    $r = $conn->query("SELECT COALESCE(SUM(total_value), 0) AS v FROM inv_po_receipts WHERE po_id = " . (int)$poId);
    if ($r instanceof mysqli_result) { $billed = (float)$r->fetch_assoc()['v']; }

    $paid = 0.0;
    $r = $conn->query("SELECT COALESCE(SUM(amount), 0) AS v FROM inv_po_payments WHERE po_id = " . (int)$poId);
    if ($r instanceof mysqli_result) { $paid = (float)$r->fetch_assoc()['v']; }

    return [
        'billed'      => round($billed, 2),
        'paid'        => round($paid, 2),
        'outstanding' => round($billed - $paid, 2),
    ];
}

/** Recompute payment_status from the payments actually recorded. */
function poRefreshPaymentStatus(mysqli $conn, int $poId): string {
    $s = poPaymentSummary($conn, $poId);

    if ($s['paid'] <= 0.005)                    { $status = 'unpaid'; }
    elseif ($s['outstanding'] <= 0.005)         { $status = 'paid'; }
    else                                        { $status = 'partial'; }

    $stmt = $conn->prepare("UPDATE inv_purchase_orders SET payment_status = ? WHERE id = ?");
    $stmt->bind_param('si', $status, $poId);
    $stmt->execute();
    $stmt->close();
    return $status;
}

// ---------- History for the PO screen -----------------------------------

function poGetReceipts(mysqli $conn, int $poId): array {
    $stmt = $conn->prepare("SELECT * FROM inv_po_receipts WHERE po_id = ? ORDER BY id DESC");
    if (!$stmt) { return []; }
    $stmt->bind_param('i', $poId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

function poGetPayments(mysqli $conn, int $poId): array {
    $stmt = $conn->prepare(
        "SELECT p.*, a.code AS account_code, a.name AS account_name
         FROM inv_po_payments p
         LEFT JOIN acc_accounts a ON a.id = p.paid_from_account_id
         WHERE p.po_id = ? ORDER BY p.id DESC");
    if (!$stmt) { return []; }
    $stmt->bind_param('i', $poId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}
