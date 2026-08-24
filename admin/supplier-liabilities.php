<?php
// ============================================================
// Supplier Liabilities
// ------------------------------------------------------------
// Cross-PO, cross-supplier view of everything the shop still owes.
// Every figure here is read from data that already exists and is
// already correct - inv_po_receipts (what was actually billed),
// inv_po_payments (what was actually paid) - the same arithmetic
// poPaymentSummary() uses per PO, just aggregated in one query
// instead of one call per row so this page stays fast with many
// open orders.
//
// Recording a payment is NOT done from here: this page links to the
// existing payment modal on inventory-po-view.php, which is - and
// stays - the only call site for poRecordPayment() in the app
// (CLAUDE.md: "one call site, one gate"). Nothing here bypasses the
// purchasing_approve gate or the EFD-on-final-payment rule.
// ============================================================
require_once '../includes/auth.php';
requireModule('supplier_liabilities');
date_default_timezone_set('Africa/Dar_es_Salaam');
$current_page = basename($_SERVER['PHP_SELF']);
include '../includes/db.php';
require_once '../includes/inventory_functions.php';
require_once '../includes/purchasing_functions.php';
purchasingBoot($conn);

// Payment is a supervisor decision, same gate as everywhere else money
// reaches a supplier - a storekeeper sees these figures, never a way
// to act on them.
$canApprove = userCan('purchasing_approve');

$rows = $conn->query(
    "SELECT po.id, po.po_number, po.supplier_id, po.status, po.payment_status,
            po.due_date, po.order_date,
            s.name AS supplier_name,
            COALESCE(r.billed, 0) AS billed,
            COALESCE(p.paid, 0) AS paid
     FROM inv_purchase_orders po
     LEFT JOIN inv_suppliers s ON s.id = po.supplier_id
     LEFT JOIN (SELECT po_id, SUM(total_value) AS billed FROM inv_po_receipts GROUP BY po_id) r ON r.po_id = po.id
     LEFT JOIN (SELECT po_id, SUM(amount) AS paid FROM inv_po_payments GROUP BY po_id) p ON p.po_id = po.id
     WHERE po.deleted_at IS NULL AND po.payment_status IN ('unpaid','partial')
     ORDER BY (po.due_date IS NULL), po.due_date ASC, po.id DESC"
)->fetch_all(MYSQLI_ASSOC);

$today = strtotime(date('Y-m-d'));
$totalOutstanding = 0.0;
$overdueCount = 0;
$overdueValue = 0.0;
$bySupplier = [];

foreach ($rows as &$r) {
    $r['outstanding'] = round((float)$r['billed'] - (float)$r['paid'], 2);
    $r['days_overdue'] = null;
    if ($r['due_date']) {
        $diff = (int)round((strtotime($r['due_date']) - $today) / 86400);
        if ($diff < 0) { $r['days_overdue'] = abs($diff); }
    }
    $totalOutstanding += $r['outstanding'];
    if ($r['days_overdue'] !== null) {
        $overdueCount++;
        $overdueValue += $r['outstanding'];
    }
    $supName = $r['supplier_name'] ?: 'Unknown supplier';
    $bySupplier[$supName] = ($bySupplier[$supName] ?? 0) + $r['outstanding'];
}
unset($r);
arsort($bySupplier);

$pageTitle = 'Supplier Liabilities';
$breadcrumbs = [['Dashboard', 'index.php'], ['Finance'], ['Supplier Liabilities']];
include 'inventory-header.php';
?>
<div class="inv-page-header">
    <div>
        <h1><i class="fas fa-hand-holding-dollar me-2" style="color:var(--inv-primary);"></i>Supplier Liabilities</h1>
        <div class="subtitle">Everything still owed to suppliers, across every purchase order.</div>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-md-4">
        <div class="inv-stat-card bg-grad-teal"><div class="d-flex justify-content-between">
            <div><div class="label">Total outstanding</div><div class="value" style="font-size:1.15rem;"><?php echo invMoney($conn, $totalOutstanding); ?></div></div>
            <i class="fas fa-scale-balanced icon"></i>
        </div></div>
    </div>
    <div class="col-6 col-md-4">
        <div class="inv-stat-card bg-grad-red"><div class="d-flex justify-content-between">
            <div><div class="label">Overdue</div><div class="value"><?php echo $overdueCount; ?></div></div>
            <i class="fas fa-triangle-exclamation icon"></i>
        </div></div>
    </div>
    <div class="col-12 col-md-4">
        <div class="inv-stat-card bg-grad-amber"><div class="d-flex justify-content-between">
            <div><div class="label">Overdue value</div><div class="value" style="font-size:1.1rem;"><?php echo invMoney($conn, $overdueValue); ?></div></div>
            <i class="fas fa-sack-dollar icon"></i>
        </div></div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="inv-card p-0">
            <div class="table-responsive">
                <table class="inv-table">
                    <thead>
                        <tr>
                            <th>PO</th><th>Supplier</th><th>Ordered</th><th>Due</th>
                            <th>Status</th><th class="text-end">Outstanding</th><th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$rows): ?>
                        <tr><td colspan="7"><div class="empty-state"><i class="fas fa-circle-check d-block" style="color:var(--color-success);"></i>Nothing outstanding - every supplier is paid up.</div></td></tr>
                        <?php endif; ?>
                        <?php foreach ($rows as $r): ?>
                        <tr>
                            <td><a href="inventory-po-view.php?id=<?php echo (int)$r['id']; ?>"><?php echo htmlspecialchars($r['po_number']); ?></a></td>
                            <td><?php echo htmlspecialchars($r['supplier_name'] ?: '—'); ?></td>
                            <td><?php echo $r['order_date'] ? date('d M Y', strtotime($r['order_date'])) : '—'; ?></td>
                            <td>
                                <?php if ($r['days_overdue'] !== null): ?>
                                    <span class="inv-badge bg-danger"><?php echo date('d M Y', strtotime($r['due_date'])); ?> (<?php echo $r['days_overdue']; ?>d overdue)</span>
                                <?php elseif ($r['due_date']): ?>
                                    <?php echo date('d M Y', strtotime($r['due_date'])); ?>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="inv-badge <?php echo $r['payment_status'] === 'partial' ? 'bg-warning' : 'bg-secondary'; ?>"><?php echo ucfirst($r['payment_status']); ?></span></td>
                            <td class="text-end"><strong><?php echo invMoney($conn, $r['outstanding']); ?></strong></td>
                            <td class="text-end">
                                <?php if ($canApprove): ?>
                                <a href="inventory-po-view.php?id=<?php echo (int)$r['id']; ?>" class="btn btn-sm btn-inv">Pay</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <?php if ($rows): ?>
                    <tfoot>
                        <tr class="fw-bold"><td colspan="5" class="text-end">Total</td><td class="text-end"><?php echo invMoney($conn, $totalOutstanding); ?></td><td></td></tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="inv-card p-0">
            <div class="p-3" style="border-bottom:1px solid var(--color-border);"><strong><i class="fas fa-truck-field me-2"></i>By supplier</strong></div>
            <div class="table-responsive">
                <table class="inv-table">
                    <tbody>
                        <?php if (!$bySupplier): ?>
                        <tr><td><div class="empty-state">No open balances.</div></td></tr>
                        <?php endif; ?>
                        <?php foreach ($bySupplier as $name => $amount): ?>
                        <tr><td><?php echo htmlspecialchars($name); ?></td><td class="text-end"><?php echo invMoney($conn, $amount); ?></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include 'inventory-footer.php'; ?>
