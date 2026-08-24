<?php
// ============================================================
// Inventory & Stock Management - shared helper functions
// ------------------------------------------------------------
// Included by every inventory page (and by the order-status hook).
// Requires an active mysqli $conn from includes/db.php.
// ============================================================

require_once __DIR__ . '/inventory_schema.php';
require_once __DIR__ . '/core_schema.php';

/**
 * Convenience wrapper so pages only need one include.
 *
 * Inventory runs first because it creates inv_settings, which every
 * other installer (including core) uses for its version flag.
 */
function inventoryBoot(mysqli $conn): void {
    ensureInventorySchema($conn);
    ensureCoreSchema($conn);
    // Shop identity / receipt options / payment instructions.
    require_once __DIR__ . '/shop_settings.php';
    ensureShopSettingsSchema($conn);
}

// ---------- Settings -------------------------------------------------

function getInvSetting(mysqli $conn, string $key, $default = null) {
    $stmt = $conn->prepare("SELECT setting_value FROM inv_settings WHERE setting_key = ? LIMIT 1");
    if (!$stmt) return $default;
    $stmt->bind_param('s', $key);
    $stmt->execute();
    $res = $stmt->get_result();
    $row = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return $row ? $row['setting_value'] : $default;
}

function setInvSetting(mysqli $conn, string $key, string $value): bool {
    $stmt = $conn->prepare("INSERT INTO inv_settings (setting_key, setting_value) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    if (!$stmt) return false;
    $stmt->bind_param('ss', $key, $value);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
}

// ---------- Formatting ----------------------------------------------

function invCurrency(mysqli $conn): string {
    static $cur = null;
    if ($cur === null) { $cur = getInvSetting($conn, 'currency', 'Tsh'); }
    return $cur;
}

function invMoney(mysqli $conn, $amount): string {
    return invCurrency($conn) . ' ' . number_format((float)$amount, 2, '.', ',');
}

/** Trim trailing zeros from a stock quantity for display. */
function invQty($qty): string {
    $qty = (float)$qty;
    if ($qty == (int)$qty) return (string)(int)$qty;
    return rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.');
}

// ---------- Audit log ------------------------------------------------

function invAudit(mysqli $conn, ?int $userId, string $action, string $entityType, ?int $entityId, string $details = ''): void {
    $stmt = $conn->prepare("INSERT INTO inv_audit_log (user_id, action, entity_type, entity_id, details)
        VALUES (?, ?, ?, ?, ?)");
    if (!$stmt) return;
    $stmt->bind_param('issis', $userId, $action, $entityType, $entityId, $details);
    $stmt->execute();
    $stmt->close();
}

/** An item's name, for ledger memos. */
function stockItemName(mysqli $conn, int $itemId): string {
    $stmt = $conn->prepare("SELECT name FROM inv_items WHERE id = ? LIMIT 1");
    if (!$stmt) { return 'Item #' . $itemId; }
    $stmt->bind_param('i', $itemId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row['name'] ?? ('Item #' . $itemId);
}

// ---------- Stock movement (the heart of the module) -----------------

/**
 * Record a single stock movement and update the item's current_stock
 * atomically. Positive $signedQty increases stock, negative decreases.
 * Writes a full audit row (qty_before / qty_after / user / reason).
 *
 * MUST be called inside a transaction started by the caller when part
 * of a larger operation; if $ownTransaction is true it manages its own.
 *
 * Returns [ok(bool), message(string), qtyAfter(float)].
 */
function recordStockMovement(
    mysqli $conn,
    int $itemId,
    string $movementType,
    float $signedQty,
    ?int $userId,
    string $reason = '',
    float $unitCost = 0.0,
    ?int $orderId = null,
    ?string $referenceType = null,
    ?int $referenceId = null,
    bool $ownTransaction = true,
    ?int $batchId = null
): array {
    $validTypes = ['receive','issue','transfer','damage','expire','loss','adjust','return','opening'];
    if (!in_array($movementType, $validTypes, true)) {
        return [false, 'Invalid movement type.', 0.0];
    }
    if ($signedQty == 0.0) {
        return [false, 'Quantity cannot be zero.', 0.0];
    }

    if ($ownTransaction) { $conn->begin_transaction(); }

    try {
        // Lock the item row so concurrent movements can't race on stock.
        $lock = $conn->prepare("SELECT current_stock, average_cost FROM inv_items WHERE id = ? AND deleted_at IS NULL FOR UPDATE");
        $lock->bind_param('i', $itemId);
        $lock->execute();
        $row = $lock->get_result()->fetch_assoc();
        $lock->close();

        if (!$row) { throw new Exception('Item not found.'); }

        $before = (float)$row['current_stock'];
        $after  = $before + $signedQty;
        if ($after < 0) {
            throw new Exception('Not enough stock. Available: ' . invQty($before) . ', requested: ' . invQty(abs($signedQty)) . '.');
        }

        // Weighted-average cost recalculation on positive receipts.
        $avgCost = (float)$row['average_cost'];
        if ($signedQty > 0 && in_array($movementType, ['receive','opening'], true) && $unitCost > 0) {
            $totalValue = ($before * $avgCost) + ($signedQty * $unitCost);
            $avgCost = $after > 0 ? ($totalValue / $after) : $unitCost;
        }

        $upd = $conn->prepare("UPDATE inv_items SET current_stock = ?, average_cost = ?" .
            ($signedQty > 0 && $unitCost > 0 ? ", last_purchased_at = NOW(), purchase_price = ?" : "") .
            " WHERE id = ?");
        if ($signedQty > 0 && $unitCost > 0) {
            $upd->bind_param('dddi', $after, $avgCost, $unitCost, $itemId);
        } else {
            $upd->bind_param('ddi', $after, $avgCost, $itemId);
        }
        $upd->execute();
        $upd->close();

        $mov = $conn->prepare("INSERT INTO inv_stock_movements
            (item_id, batch_id, movement_type, quantity, qty_before, qty_after, unit_cost, reference_type, reference_id, order_id, reason, user_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $mov->bind_param(
            'iisddddsiisi',
            $itemId, $batchId, $movementType, $signedQty, $before, $after, $unitCost,
            $referenceType, $referenceId, $orderId, $reason, $userId
        );
        $mov->execute();
        $movementId = (int)$conn->insert_id;
        $mov->close();

        // ---- The money side --------------------------------------------
        // Stock that moves on the shelf must move in the books too, or
        // Inventory Asset drifts away from what is actually in the shop.
        // Required here rather than at the top of the file because
        // accounting depends on inventory - a top-level include the other
        // way round would be a cycle. Movements already posted in
        // aggregate by a higher layer (till sales, PO receipts) are
        // skipped inside stockPostMovementToLedger().
        //
        // A movement with no unit cost is valued at the item's
        // weighted-average cost; writing off stock at zero would hide the
        // loss entirely.
        require_once __DIR__ . '/stock_ledger.php';
        $valuation = $unitCost > 0 ? $unitCost : $avgCost;
        [$accOk, $accMsg] = stockPostMovementToLedger(
            $conn, $movementId, $itemId, stockItemName($conn, $itemId), $movementType,
            $signedQty, $valuation, $referenceType, $reason, $userId,
            (string)($_SESSION['username'] ?? '')
        );
        if (!$accOk) { throw new Exception('Ledger posting failed: ' . $accMsg); }

        if ($ownTransaction) { $conn->commit(); }
        return [true, 'Movement recorded.', $after];
    } catch (Throwable $e) {
        if ($ownTransaction) { $conn->rollback(); }
        return [false, $e->getMessage(), 0.0];
    }
}

// ---------- Stock requests (request -> approve -> deduct) -------------

/** Next SR-YYYYMMDD-NNXX request number (XX = random hex, unpredictable). */
function nextStockRequestNo(mysqli $conn): string {
    $prefix = 'SR-' . date('Ymd') . '-';
    $stmt = $conn->prepare("SELECT request_no FROM inv_stock_requests WHERE request_no LIKE CONCAT(?, '%') ORDER BY id DESC LIMIT 1");
    $stmt->bind_param('s', $prefix);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $next = 1;
    if ($row) {
        // Sequence is the first 2 digits after the prefix (hex tail ignored).
        $next = (int)substr($row['request_no'], strlen($prefix), 2) + 1;
    }
    return $prefix . str_pad((string)$next, 2, '0', STR_PAD_LEFT) . strtoupper(bin2hex(random_bytes(1)));
}

/**
 * Create a stock request (status pending). $lines: [['item_id'=>, 'qty'=>], ...]
 * Nothing is deducted here - deduction happens only on approval.
 * Returns [ok, requestNoOrError].
 */
function createStockRequest(mysqli $conn, ?int $userId, string $userName, string $purpose, array $lines): array {
    $lines = array_values(array_filter($lines, function ($l) {
        return (int)($l['item_id'] ?? 0) > 0 && (float)($l['qty'] ?? 0) > 0;
    }));
    if (!$lines) { return [false, 'Add at least one item with a quantity.']; }

    $conn->begin_transaction();
    try {
        $requestNo = nextStockRequestNo($conn);
        $stmt = $conn->prepare("INSERT INTO inv_stock_requests (request_no, requested_by, requested_by_name, purpose) VALUES (?, ?, ?, ?)");
        $stmt->bind_param('siss', $requestNo, $userId, $userName, $purpose);
        if (!$stmt->execute()) { throw new Exception('Could not create the request.'); }
        $requestId = (int)$conn->insert_id;
        $stmt->close();

        $lineStmt = $conn->prepare("INSERT INTO inv_stock_request_lines (request_id, item_id, qty_requested) VALUES (?, ?, ?)");
        foreach ($lines as $l) {
            $itemId = (int)$l['item_id'];
            $qty = (float)$l['qty'];
            $lineStmt->bind_param('iid', $requestId, $itemId, $qty);
            if (!$lineStmt->execute()) { throw new Exception('Could not save request items.'); }
        }
        $lineStmt->close();

        invAudit($conn, $userId, 'stock_request_create', 'stock_request', $requestId, $requestNo);
        $conn->commit();
        return [true, $requestNo];
    } catch (Throwable $e) {
        $conn->rollback();
        return [false, $e->getMessage()];
    }
}

/**
 * Review a stock request. $approvedQtys: [line_id => qty_approved].
 * Approved quantities (capped at the requested amount) are deducted from
 * inventory via recordStockMovement, all inside one transaction. Status
 * becomes approved / partially_approved / rejected accordingly.
 * Returns [ok, message].
 */
function reviewStockRequest(mysqli $conn, int $requestId, array $approvedQtys, ?int $userId, string $note = ''): array {
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("SELECT * FROM inv_stock_requests WHERE id = ? FOR UPDATE");
        $stmt->bind_param('i', $requestId);
        $stmt->execute();
        $req = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$req) { throw new Exception('Request not found.'); }
        if ($req['status'] !== 'pending') { throw new Exception('This request was already reviewed.'); }

        $lineStmt = $conn->prepare("SELECT l.id, l.item_id, l.qty_requested, i.name
            FROM inv_stock_request_lines l JOIN inv_items i ON i.id = l.item_id WHERE l.request_id = ?");
        $lineStmt->bind_param('i', $requestId);
        $lineStmt->execute();
        $lines = $lineStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $lineStmt->close();
        if (!$lines) { throw new Exception('Request has no items.'); }

        $totalRequested = 0.0;
        $totalApproved  = 0.0;
        foreach ($lines as $line) {
            $lineId = (int)$line['id'];
            $requested = (float)$line['qty_requested'];
            $approved = max(0.0, min((float)($approvedQtys[$lineId] ?? 0), $requested));
            $totalRequested += $requested;
            $totalApproved  += $approved;

            $upd = $conn->prepare("UPDATE inv_stock_request_lines SET qty_approved = ? WHERE id = ?");
            $upd->bind_param('di', $approved, $lineId);
            $upd->execute();
            $upd->close();

            if ($approved > 0) {
                [$ok, $msg] = recordStockMovement(
                    $conn, (int)$line['item_id'], 'issue', -$approved, $userId,
                    'Stock request ' . $req['request_no'] . ($req['requested_by_name'] ? ' (for ' . $req['requested_by_name'] . ')' : ''),
                    0.0, null, 'stock_request', $requestId, false
                );
                if (!$ok) { throw new Exception($line['name'] . ': ' . $msg); }
            }
        }

        if ($totalApproved <= 0) { $status = 'rejected'; }
        elseif ($totalApproved + 0.0005 >= $totalRequested) { $status = 'approved'; }
        else { $status = 'partially_approved'; }

        $upd = $conn->prepare("UPDATE inv_stock_requests SET status = ?, review_note = ?, reviewed_by = ?, reviewed_at = NOW() WHERE id = ?");
        $upd->bind_param('ssii', $status, $note, $userId, $requestId);
        $upd->execute();
        $upd->close();

        invAudit($conn, $userId, 'stock_request_' . $status, 'stock_request', $requestId, $req['request_no']);
        $conn->commit();
        $labels = ['approved' => 'approved', 'partially_approved' => 'partially approved', 'rejected' => 'rejected'];
        return [true, 'Request ' . $req['request_no'] . ' ' . $labels[$status] . ($totalApproved > 0 ? ' - stock deducted.' : '.')];
    } catch (Throwable $e) {
        $conn->rollback();
        return [false, $e->getMessage()];
    }
}

// ---------- Expired-goods disposal (request -> approve -> dispose) -----
//
// Deliberately three states, not two: approveDisposalRequest() only ever
// authorizes - it never calls recordStockMovement(). Only
// executeDisposalRequest(), a separate later action, actually deducts
// stock and posts the loss, and only once status is already 'approved'.
// This keeps "who may write off value" (admin/manager, disposal_approve)
// separate from "who does the physical disposal" (anyone with inventory
// access), the same separation this app already draws between
// purchasing_approve and plain purchasing for receiving PO stock.

/** Next DR-YYYYMMDD-NNXX request number (XX = random hex, unpredictable). */
function nextDisposalRequestNo(mysqli $conn): string {
    $prefix = 'DR-' . date('Ymd') . '-';
    $stmt = $conn->prepare("SELECT request_no FROM inv_disposal_requests WHERE request_no LIKE CONCAT(?, '%') ORDER BY id DESC LIMIT 1");
    $stmt->bind_param('s', $prefix);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $next = 1;
    if ($row) {
        $next = (int)substr($row['request_no'], strlen($prefix), 2) + 1;
    }
    return $prefix . str_pad((string)$next, 2, '0', STR_PAD_LEFT) . strtoupper(bin2hex(random_bytes(1)));
}

/**
 * Create a disposal request (status pending) for currently expired,
 * in-stock items. $lines: [['batch_id'=>, 'item_id'=>, 'qty'=>], ...] -
 * batch_id targets one specific expired inv_batches row (the normal
 * path, from the disposal screen's per-batch picker); a line with no
 * batch_id falls back to the old item-level behavior, for the
 * uncovered-by-any-batch defensive case and for backward compatibility.
 * Nothing is deducted here - deduction happens only in
 * executeDisposalRequest(), after approval.
 *
 * Every line is re-validated server-side (still expired, still active,
 * still enough quantity left in that exact batch or item): the
 * browser's expired-items list can go stale between page load and
 * submit, and is never trusted here. Returns [ok, requestNoOrError].
 */
function createDisposalRequest(mysqli $conn, ?int $userId, string $userName, string $reason, array $lines): array {
    $lines = array_values(array_filter($lines, function ($l) {
        $hasTarget = (int)($l['batch_id'] ?? 0) > 0 || (int)($l['item_id'] ?? 0) > 0;
        return $hasTarget && (float)($l['qty'] ?? 0) > 0;
    }));
    if (!$lines) { return [false, 'Add at least one expired item with a quantity.']; }

    $conn->begin_transaction();
    try {
        $requestNo = nextDisposalRequestNo($conn);
        $stmt = $conn->prepare("INSERT INTO inv_disposal_requests (request_no, requested_by, requested_by_name, reason) VALUES (?, ?, ?, ?)");
        $stmt->bind_param('siss', $requestNo, $userId, $userName, $reason);
        if (!$stmt->execute()) { throw new Exception('Could not create the disposal request.'); }
        $requestId = (int)$conn->insert_id;
        $stmt->close();

        // Batch path: lock and re-check the ONE specific batch the
        // requester picked, not the item's blended current_stock - this
        // is what actually enforces "can't request more than what's
        // genuinely expired in this delivery."
        $batchStmt = $conn->prepare("SELECT b.item_id, b.quantity, b.expiry_date FROM inv_batches b
            JOIN inv_items i ON i.id = b.item_id
            WHERE b.id = ? AND i.deleted_at IS NULL AND b.status = 'active' AND b.quantity > 0
              AND b.expiry_date IS NOT NULL AND b.expiry_date < CURDATE() FOR UPDATE");
        // Item-level fallback path (no batch_id supplied): today's
        // behavior, unchanged.
        $itemStmt = $conn->prepare("SELECT name, current_stock, expiry_date FROM inv_items
            WHERE id = ? AND deleted_at IS NULL AND expiry_date IS NOT NULL AND expiry_date < CURDATE() FOR UPDATE");
        $lineStmt = $conn->prepare("INSERT INTO inv_disposal_request_lines (request_id, item_id, batch_id, qty_requested, expiry_date) VALUES (?, ?, ?, ?, ?)");

        $saved = 0;
        foreach ($lines as $l) {
            $batchId = (int)($l['batch_id'] ?? 0);
            $qty = (float)$l['qty'];

            if ($batchId > 0) {
                $batchStmt->bind_param('i', $batchId);
                $batchStmt->execute();
                $batch = $batchStmt->get_result()->fetch_assoc();
                if (!$batch) {
                    // Not expired/active any more, or never existed - skip
                    // rather than trust what the browser sent.
                    continue;
                }
                $itemId = (int)$batch['item_id'];
                $qty = min($qty, (float)$batch['quantity']);
                if ($qty <= 0) { continue; }
                $lineExpiry = $batch['expiry_date'];
                $lineStmt->bind_param('iiids', $requestId, $itemId, $batchId, $qty, $lineExpiry);
            } else {
                $itemId = (int)($l['item_id'] ?? 0);
                if ($itemId <= 0) { continue; }
                $itemStmt->bind_param('i', $itemId);
                $itemStmt->execute();
                $item = $itemStmt->get_result()->fetch_assoc();
                if (!$item) { continue; }
                $qty = min($qty, (float)$item['current_stock']);
                if ($qty <= 0) { continue; }
                $noBatch = null;
                $lineExpiry = $item['expiry_date'];
                $lineStmt->bind_param('iiids', $requestId, $itemId, $noBatch, $qty, $lineExpiry);
            }

            if (!$lineStmt->execute()) { throw new Exception('Could not save disposal request items.'); }
            $saved++;
        }
        $batchStmt->close();
        $itemStmt->close();
        $lineStmt->close();

        if ($saved === 0) { throw new Exception('None of the selected items are still expired and in stock. Refresh and try again.'); }

        invAudit($conn, $userId, 'disposal_request_create', 'disposal_request', $requestId, $requestNo);
        $conn->commit();
        return [true, $requestNo];
    } catch (Throwable $e) {
        $conn->rollback();
        return [false, $e->getMessage()];
    }
}

/**
 * Approve or reject a disposal request. Authorization only - no stock
 * movement happens here (see executeDisposalRequest() for that).
 * $decision is 'approve' or 'reject'. $approvedQtys: [line_id => qty],
 * capped at qty_requested and defaulting to the full requested quantity
 * when a line is missing from the map. Returns [ok, message].
 */
function approveDisposalRequest(mysqli $conn, int $requestId, string $decision, array $approvedQtys, ?int $userId, string $userName, string $note = ''): array {
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("SELECT * FROM inv_disposal_requests WHERE id = ? FOR UPDATE");
        $stmt->bind_param('i', $requestId);
        $stmt->execute();
        $req = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$req) { throw new Exception('Disposal request not found.'); }
        if ($req['status'] !== 'pending') { throw new Exception('This request was already reviewed.'); }

        if ($decision === 'reject') {
            $status = 'rejected';
        } else {
            $lineStmt = $conn->prepare("SELECT id, qty_requested FROM inv_disposal_request_lines WHERE request_id = ?");
            $lineStmt->bind_param('i', $requestId);
            $lineStmt->execute();
            $lines = $lineStmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $lineStmt->close();
            if (!$lines) { throw new Exception('Request has no items.'); }

            $upd = $conn->prepare("UPDATE inv_disposal_request_lines SET qty_approved = ? WHERE id = ?");
            foreach ($lines as $line) {
                $lineId = (int)$line['id'];
                $requested = (float)$line['qty_requested'];
                $approved = max(0.0, min((float)($approvedQtys[$lineId] ?? $requested), $requested));
                $upd->bind_param('di', $approved, $lineId);
                $upd->execute();
            }
            $upd->close();
            $status = 'approved';
        }

        $upd = $conn->prepare("UPDATE inv_disposal_requests SET status = ?, review_note = ?, reviewed_by = ?, reviewed_by_name = ?, reviewed_at = NOW() WHERE id = ?");
        $upd->bind_param('ssisi', $status, $note, $userId, $userName, $requestId);
        $upd->execute();
        $upd->close();

        invAudit($conn, $userId, 'disposal_request_' . $status, 'disposal_request', $requestId, $req['request_no']);
        $conn->commit();
        return [true, 'Disposal request ' . $req['request_no'] . ' ' . $status . '.'];
    } catch (Throwable $e) {
        $conn->rollback();
        return [false, $e->getMessage()];
    }
}

/**
 * Confirm disposal of an approved request: deducts exactly qty_approved
 * per line via recordStockMovement('expire', ...) and posts the loss to
 * the ledger (DR Stock Loss & Shrinkage / CR Inventory Asset, at
 * weighted-average cost - stock_ledger.php does this automatically,
 * required inside recordStockMovement()). When a line carries a
 * batch_id, also deducts from that exact inv_batches row via
 * disposeFromBatch() and, once all lines are processed, resyncs each
 * touched item's expiry_date via refreshItemExpiryFromBatches() - this
 * is what makes an item sellable again once its expired batch is fully
 * disposed of, with no change to checkout needed.
 *
 * No partial-quantity adjustment here by design: if the physical count
 * differs from qty_approved, that is the existing "Correct Current
 * Stock" mechanism on the item's edit form, not this workflow.
 * Returns [ok, message].
 */
function executeDisposalRequest(mysqli $conn, int $requestId, ?int $userId, string $userName, string $note = ''): array {
    require_once __DIR__ . '/inv_batches_functions.php';
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("SELECT * FROM inv_disposal_requests WHERE id = ? FOR UPDATE");
        $stmt->bind_param('i', $requestId);
        $stmt->execute();
        $req = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$req) { throw new Exception('Disposal request not found.'); }
        if ($req['status'] !== 'approved') { throw new Exception('Only an approved request can be disposed.'); }

        $lineStmt = $conn->prepare("SELECT l.id, l.item_id, l.batch_id, l.qty_approved, i.name
            FROM inv_disposal_request_lines l JOIN inv_items i ON i.id = l.item_id
            WHERE l.request_id = ? AND l.qty_approved > 0");
        $lineStmt->bind_param('i', $requestId);
        $lineStmt->execute();
        $lines = $lineStmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $lineStmt->close();
        if (!$lines) { throw new Exception('Nothing was approved for disposal on this request.'); }

        $reason = 'Expired goods disposal ' . $req['request_no'] . ($note !== '' ? ' - ' . $note : '');
        $touchedItems = [];
        foreach ($lines as $line) {
            [$ok, $msg] = recordStockMovement(
                $conn, (int)$line['item_id'], 'expire', -(float)$line['qty_approved'], $userId,
                $reason, 0.0, null, 'disposal_request', $requestId, false
            );
            if (!$ok) { throw new Exception($line['name'] . ': ' . $msg); }

            // recordStockMovement() only owns inv_items.current_stock -
            // when this line targeted one specific batch, mirror the same
            // deduction into inv_batches so the batch's own quantity/
            // status stay correct (otherwise it would still look active
            // and expired forever, and the resync below would have
            // nothing to resync away from).
            if (!empty($line['batch_id'])) {
                [$bOk, $bMsg] = disposeFromBatch($conn, (int)$line['batch_id'], (float)$line['qty_approved']);
                if (!$bOk) { throw new Exception($line['name'] . ': ' . $bMsg); }
            }
            $touchedItems[(int)$line['item_id']] = true;
        }

        // One resync per distinct item, after all its lines are done -
        // an item can have more than one expired batch on the same
        // request, so this must run after the loop, not inside it.
        foreach (array_keys($touchedItems) as $touchedItemId) {
            refreshItemExpiryFromBatches($conn, $touchedItemId);
        }

        $upd = $conn->prepare("UPDATE inv_disposal_requests SET status = 'disposed', disposed_by = ?, disposed_by_name = ?, disposed_at = NOW() WHERE id = ?");
        $upd->bind_param('isi', $userId, $userName, $requestId);
        $upd->execute();
        $upd->close();

        invAudit($conn, $userId, 'disposal_request_disposed', 'disposal_request', $requestId, $req['request_no']);
        $conn->commit();
        return [true, 'Request ' . $req['request_no'] . ' disposed - stock deducted and loss posted to the ledger.'];
    } catch (Throwable $e) {
        $conn->rollback();
        return [false, $e->getMessage()];
    }
}

// ---------- Small view helpers --------------------------------------

/** Stock status label for an item row (associative array). */
function invStockStatus(array $item): array {
    $stock = (float)$item['current_stock'];
    $reorder = (float)$item['reorder_level'];
    $min = (float)$item['min_stock'];
    if ($stock <= 0) {
        return ['Out of Stock', 'danger'];
    }
    if ($reorder > 0 && $stock <= $reorder) {
        return ['Reorder', 'warning'];
    }
    if ($min > 0 && $stock <= $min) {
        return ['Low', 'warning'];
    }
    return ['In Stock', 'success'];
}
