<?php
// ============================================================
// Stock movements -> the ledger
// ------------------------------------------------------------
// Every stock change already goes through recordStockMovement().
// This file is what makes each of those changes hit the books too,
// so stock value in the ledger tracks stock value on the shelf.
//
// It is required from INSIDE recordStockMovement() at call time
// rather than at the top of inventory_functions.php: accounting
// depends on inventory, so a top-level include the other way would
// be a cycle. By the time any movement is recorded, both layers
// are loaded.
//
// What each movement type means in money, valued at cost:
//
//   damage / expire / loss   DR Stock Loss & Shrinkage / CR Inventory
//   adjust  (count down)     DR Stock Loss & Shrinkage / CR Inventory
//   adjust  (count up)       DR Inventory / CR Stock Loss & Shrinkage
//   issue   (internal use)   DR Store Consumables Used / CR Inventory
//   receive (no PO)          DR Inventory / CR Accounts Payable
//   opening                  DR Inventory / CR Owner's Capital
//   transfer                 nothing - the asset only changed shelf
//
// Movements that a higher layer already posts as one aggregate entry
// are skipped here, or the same money would be counted twice:
//   reference_type 'pos_sale'       -> accPostSale / accPostSaleVoid
//   reference_type 'purchase_order' -> poReceiveStock
// ============================================================

require_once __DIR__ . '/accounting_functions.php';

/** Reference types whose ledger effect is posted by a higher layer. */
function stockLedgerSkippedReferences(): array {
    return ['pos_sale', 'purchase_order'];
}

/**
 * Post one stock movement to the ledger.
 *
 * $signedQty is the movement as recorded (negative = stock left).
 * $unitValue is the cost to value it at; when the caller passed no
 * unit cost we fall back to the item's weighted-average cost, because
 * a movement valued at zero would silently under-report shrinkage.
 *
 * Always called with ownTransaction = false: the caller is already
 * inside recordStockMovement's transaction, so the stock change and
 * its ledger entry commit or roll back together.
 *
 * Returns [ok, message]. A movement with no monetary effect returns
 * ok with nothing written.
 */
function stockPostMovementToLedger(
    mysqli $conn,
    int $movementId,
    int $itemId,
    string $itemName,
    string $movementType,
    float $signedQty,
    float $unitValue,
    ?string $referenceType,
    string $reason,
    ?int $userId,
    string $userName = ''
): array {
    if (in_array((string)$referenceType, stockLedgerSkippedReferences(), true)) {
        return [true, 'posted by a higher layer'];
    }
    // A transfer moves stock between locations, so it nets to zero and
    // has no ledger effect. NOTE: inv_items.current_stock is a single
    // global quantity, so a transfer must be recorded as a matched pair
    // (out of one location, into another) or it will quietly destroy
    // stock value. Nothing in the UI creates transfers today.
    if ($movementType === 'transfer') {
        return [true, 'transfers have no ledger effect'];
    }

    $value = round(abs($signedQty) * $unitValue, 2);
    if ($value <= 0) {
        return [true, 'no cost value to post'];
    }

    $codes = accSystemAccounts();
    $label = $itemName . ($reason !== '' ? ' - ' . $reason : '');

    switch ($movementType) {
        case 'damage':
        case 'expire':
        case 'loss':
            $lines = [
                ['account' => $codes['shrinkage'], 'debit'  => $value, 'memo' => 'Stock written off: ' . $label],
                ['account' => $codes['inventory'], 'credit' => $value, 'memo' => 'Stock removed: ' . $itemName],
            ];
            $memo = ucfirst($movementType) . ' - ' . $itemName;
            break;

        case 'adjust':
            if ($signedQty < 0) {
                // Counted less than the system said: stock is missing.
                $lines = [
                    ['account' => $codes['shrinkage'], 'debit'  => $value, 'memo' => 'Count shortfall: ' . $label],
                    ['account' => $codes['inventory'], 'credit' => $value, 'memo' => 'Stock written down: ' . $itemName],
                ];
                $memo = 'Stock count shortfall - ' . $itemName;
            } else {
                // Counted more than expected. Credited back against
                // shrinkage rather than booked as income, so the account
                // shows NET stock loss over a period instead of two
                // unrelated-looking numbers.
                $lines = [
                    ['account' => $codes['inventory'], 'debit'  => $value, 'memo' => 'Stock found on count: ' . $itemName],
                    ['account' => $codes['shrinkage'], 'credit' => $value, 'memo' => 'Count surplus offsets shrinkage: ' . $label],
                ];
                $memo = 'Stock count surplus - ' . $itemName;
            }
            break;

        case 'issue':
            // Stock consumed by the shop itself (cleaning supplies, till
            // rolls, display samples). It leaves inventory and becomes an
            // operating cost now, not when something sells.
            $lines = [
                ['account' => $codes['consumables'], 'debit'  => $value, 'memo' => 'Used in store: ' . $label],
                ['account' => $codes['inventory'],   'credit' => $value, 'memo' => 'Stock issued: ' . $itemName],
            ];
            $memo = 'Store consumables used - ' . $itemName;
            break;

        case 'receive':
            // Goods arriving without a purchase order (barcode station
            // stock-in). They are still an asset you presumably owe for,
            // so a payable is raised. Reclassify or settle it from the
            // ledger if it was paid in cash on the spot.
            $lines = [
                ['account' => $codes['inventory'], 'debit'  => $value, 'memo' => 'Stock received: ' . $label],
                ['account' => $codes['payable'],   'credit' => $value, 'memo' => 'Owed for stock received (no purchase order)'],
            ];
            $memo = 'Stock received without a PO - ' . $itemName;
            break;

        case 'return':
            // Goods going BACK to the supplier (the till's own returns
            // carry reference_type 'pos_sale' and were skipped above).
            // The stock leaves and so does the debt for it.
            $lines = [
                ['account' => $codes['payable'],   'debit'  => $value, 'memo' => 'Returned to supplier: ' . $label],
                ['account' => $codes['inventory'], 'credit' => $value, 'memo' => 'Stock returned: ' . $itemName],
            ];
            $memo = 'Returned to supplier - ' . $itemName;
            break;

        case 'opening':
            $lines = [
                ['account' => $codes['inventory'], 'debit'  => $value, 'memo' => 'Opening stock: ' . $itemName],
                ['account' => $codes['capital'],   'credit' => $value, 'memo' => 'Stock introduced by owner'],
            ];
            $memo = 'Opening stock - ' . $itemName;
            break;

        default:
            return [true, 'no posting rule for movement type "' . $movementType . '"'];
    }

    // source_id is the movement's own id, so each movement posts exactly
    // once - a retry after a failure cannot double-count.
    [$ok, $msg] = accPostEntry(
        $conn, $lines, $memo, 'stock_movement', $movementId,
        $userId, $userName, stockItemDepartment($conn, $itemId), date('Y-m-d'), false
    );

    return [$ok, $msg];
}

/** The department a movement should be reported under. */
function stockItemDepartment(mysqli $conn, int $itemId): string {
    $stmt = $conn->prepare("SELECT department FROM inv_items WHERE id = ? LIMIT 1");
    if (!$stmt) { return 'general'; }
    $stmt->bind_param('i', $itemId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row['department'] ?? 'general';
}
