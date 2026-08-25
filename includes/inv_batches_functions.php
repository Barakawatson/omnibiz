<?php
// ============================================================
// Batch / lot tracking - FEFO (First-Expiry-First-Out)
// ------------------------------------------------------------
// inv_batches breaks an item's current_stock down into distinct
// receipts, each with its own quantity, cost and expiry date, so a
// shop that has both a August-dated batch and a December-dated batch
// of the same item no longer has to collapse that into one field.
//
// inv_items.current_stock remains the single source of truth for "how
// much of this do we have" - these functions never bypass
// recordStockMovement()'s own bookkeeping. They exist to answer a
// narrower question: WHICH batch(es) should a given deduction come
// from, oldest-expiry first, and to keep inv_batches.quantity in step
// with whatever recordStockMovement() just did to current_stock.
//
// Invariant this module exists to protect:
//   SUM(quantity) FROM inv_batches WHERE item_id = X AND status='active'
//     == inv_items.current_stock for item X
// ============================================================

/**
 * Create one new batch (a receipt, or a legacy backfill row). Does not
 * touch inv_items.current_stock - the caller's recordStockMovement()
 * call is what does that; this only records the breakdown.
 * Returns [ok(bool), messageOrBatchId, batchId(int)].
 */
function createBatch(
    mysqli $conn,
    int $itemId,
    ?string $batchNo,
    float $qty,
    float $unitCost,
    ?string $expiryDate,
    ?string $referenceType = null,
    ?int $referenceId = null
): array {
    if ($qty <= 0) {
        return [false, 'Batch quantity must be greater than zero.', 0];
    }
    $expiryDate = ($expiryDate !== null && $expiryDate !== '') ? $expiryDate : null;

    $stmt = $conn->prepare(
        "INSERT INTO inv_batches (item_id, batch_no, quantity, unit_cost, expiry_date, status, reference_type, reference_id, received_at)
         VALUES (?, ?, ?, ?, ?, 'active', ?, ?, NOW())"
    );
    $stmt->bind_param('isddssi', $itemId, $batchNo, $qty, $unitCost, $expiryDate, $referenceType, $referenceId);
    if (!$stmt->execute()) {
        $err = $stmt->error;
        $stmt->close();
        return [false, 'Could not create batch: ' . $err, 0];
    }
    $batchId = (int)$conn->insert_id;
    $stmt->close();
    return [true, 'Batch created.', $batchId];
}

/**
 * Work out which active batch(es) would cover $qtyNeeded of $itemId,
 * oldest expiry first (batches with no expiry date sort last - treated
 * as effectively non-perishable). Locks the candidate rows with
 * FOR UPDATE, so MUST be called inside a transaction the caller already
 * started - same contract as recordStockMovement().
 *
 * Read-only: nothing is deducted here. Returns:
 *   ['allocations' => [['batch_id','qty','unit_cost'], ...],
 *    'shortfall'   => bool,   // true if active batches can't cover it
 *    'remaining'   => float]  // uncovered quantity, when shortfall
 */
function allocateFefoBatches(mysqli $conn, int $itemId, float $qtyNeeded): array {
    if ($qtyNeeded <= 0) {
        return ['allocations' => [], 'shortfall' => false, 'remaining' => 0.0];
    }

    $stmt = $conn->prepare(
        "SELECT id, quantity, unit_cost, expiry_date FROM inv_batches
         WHERE item_id = ? AND status = 'active' AND quantity > 0
         ORDER BY (expiry_date IS NULL) ASC, expiry_date ASC, id ASC
         FOR UPDATE"
    );
    $stmt->bind_param('i', $itemId);
    $stmt->execute();
    $batches = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $allocations = [];
    $remaining = $qtyNeeded;
    foreach ($batches as $b) {
        if ($remaining <= 0.0005) { break; }
        $take = min($remaining, (float)$b['quantity']);
        if ($take <= 0) { continue; }
        $allocations[] = ['batch_id' => (int)$b['id'], 'qty' => $take, 'unit_cost' => (float)$b['unit_cost']];
        $remaining -= $take;
    }

    return ['allocations' => $allocations, 'shortfall' => $remaining > 0.0005, 'remaining' => max(0.0, $remaining)];
}

/**
 * Allocate AND deduct $qtyNeeded of $itemId across its active batches,
 * oldest-expiry first. Does NOT call recordStockMovement() - the caller
 * still owns that, once per batch consumed (not once per cart line),
 * each tagged with that batch's own id/unit_cost via recordStockMovement()'s
 * optional $batchId parameter. Must run inside the caller's transaction.
 *
 * If batches can't fully cover the request (legacy stock, or a data
 * gap), deducts nothing and returns ok=false - the caller is expected
 * to fall back to the pre-batch, average_cost-based deduction path
 * rather than fail the operation. A checkout or receipt must never be
 * blocked by a batch-tracking gap.
 *
 * Returns ['ok' => bool, 'allocations' => [...], 'message' => string].
 */
function deductFefoBatches(mysqli $conn, int $itemId, float $qtyNeeded): array {
    $alloc = allocateFefoBatches($conn, $itemId, $qtyNeeded);
    if ($alloc['shortfall']) {
        return ['ok' => false, 'allocations' => [], 'message' => 'Not enough batch-tracked stock to cover this quantity - falling back.'];
    }
    if (!$alloc['allocations']) {
        return ['ok' => true, 'allocations' => [], 'message' => 'Nothing to deduct.'];
    }

    $upd = $conn->prepare(
        "UPDATE inv_batches
         SET quantity = quantity - ?,
             status = IF(quantity - ? <= 0.0005, 'depleted', status)
         WHERE id = ?"
    );
    foreach ($alloc['allocations'] as $a) {
        $qty = $a['qty'];
        $upd->bind_param('ddi', $qty, $qty, $a['batch_id']);
        if (!$upd->execute()) {
            $err = $upd->error;
            $upd->close();
            return ['ok' => false, 'allocations' => [], 'message' => 'Could not update batch ' . $a['batch_id'] . ': ' . $err];
        }
    }
    $upd->close();

    return ['ok' => true, 'allocations' => $alloc['allocations'], 'message' => 'Deducted across ' . count($alloc['allocations']) . ' batch(es).'];
}

/**
 * Deduct a disposal quantity from ONE specific batch (chosen by the
 * requester, not FEFO-allocated) - unlike deductFefoBatches(), which
 * spreads a deduction across whichever batches are earliest-active,
 * a disposal targets exactly the batch the storekeeper flagged as
 * expired. Sets status to 'disposed' (not 'depleted' - that status is
 * for stock sold through normally) once the batch reaches ~0.
 * Must run inside the caller's transaction. Returns [ok, message].
 */
function disposeFromBatch(mysqli $conn, int $batchId, float $qty): array {
    if ($batchId <= 0 || $qty <= 0) { return [false, 'Nothing to dispose.']; }

    $stmt = $conn->prepare("SELECT quantity FROM inv_batches WHERE id = ? FOR UPDATE");
    $stmt->bind_param('i', $batchId);
    $stmt->execute();
    $batch = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$batch) { return [false, 'Batch not found.']; }
    if ($qty > (float)$batch['quantity'] + 0.0005) {
        return [false, 'Cannot dispose more than the ' . $batch['quantity'] . ' remaining in this batch.'];
    }

    $upd = $conn->prepare(
        "UPDATE inv_batches
         SET quantity = quantity - ?,
             status = IF(quantity - ? <= 0.0005, 'disposed', status)
         WHERE id = ?"
    );
    $upd->bind_param('ddi', $qty, $qty, $batchId);
    if (!$upd->execute()) { $err = $upd->error; $upd->close(); return [false, 'Could not update the batch: ' . $err]; }
    $upd->close();

    return [true, 'Disposed from batch.'];
}

/**
 * Re-credit specific batches (a void/return). $items: [['batch_id','qty'], ...].
 * Reactivates a 'depleted' batch back to 'active' when stock returns to
 * it. Must run inside the caller's transaction.
 */
function creditFefoBatches(mysqli $conn, array $items): void {
    if (!$items) { return; }
    $upd = $conn->prepare(
        "UPDATE inv_batches
         SET quantity = quantity + ?,
             status = IF(status = 'depleted', 'active', status)
         WHERE id = ?"
    );
    foreach ($items as $item) {
        $qty = (float)$item['qty'];
        $batchId = (int)$item['batch_id'];
        if ($qty <= 0 || $batchId <= 0) { continue; }
        $upd->bind_param('di', $qty, $batchId);
        $upd->execute();
    }
    $upd->close();
}

/**
 * The earliest active batch's expiry date for an item, or null if the
 * item has no batches yet (falls back to the item's own expiry_date in
 * that case - see retailExpiryBlockReason()'s caller).
 */
function earliestActiveBatchExpiry(mysqli $conn, int $itemId): ?string {
    $stmt = $conn->prepare(
        "SELECT expiry_date FROM inv_batches
         WHERE item_id = ? AND status = 'active' AND quantity > 0 AND expiry_date IS NOT NULL
         ORDER BY expiry_date ASC LIMIT 1"
    );
    $stmt->bind_param('i', $itemId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? $row['expiry_date'] : null;
}

/**
 * Keep inv_items.expiry_date in step with the item's own batches.
 *
 * retailIsExpired()/retailExpiryBlockReason()/retailExpiryDiscount() -
 * the checkout block and the near-expiry markdown - are NOT batch-aware:
 * they read this one item-level field. FEFO always sells the earliest
 * active batch first, so that batch's date is exactly "when would the
 * next unit sold actually expire" - setting the field to it keeps the
 * old single-field logic correct without having to touch it.
 *
 * Call this any time a batch is created or a batch's active/quantity
 * status changes for this item (received stock, FEFO deduction,
 * disposal). Null when no active batch carries a date - nothing
 * currently on hand is known to expire.
 */
function refreshItemExpiryFromBatches(mysqli $conn, int $itemId): void {
    $date = earliestActiveBatchExpiry($conn, $itemId);
    $stmt = $conn->prepare("UPDATE inv_items SET expiry_date = ? WHERE id = ?");
    $stmt->bind_param('si', $date, $itemId);
    $stmt->execute();
    $stmt->close();
}
