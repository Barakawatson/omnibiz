<?php
require_once '../includes/auth.php';
// 'purchasing', not 'inventory' - already granted to the same three
// roles (admin, manager, storekeeper); this is the key that actually
// names what this page is for, matching its sidebar link and report
// group. The separate 'purchasing_approve' key still gates the
// supervisor-only actions inside this page (approve/cancel/pay/set due
// date) exactly as before.
requireModule('purchasing');
// Reject any POST that does not carry this session's CSRF token.
// Placed before every handler on this page, and a no-op on GET.
csrfRequire();
date_default_timezone_set('Africa/Dar_es_Salaam');
$current_page = basename($_SERVER['PHP_SELF']);
include '../includes/db.php';
require_once '../includes/inventory_functions.php';
require_once '../includes/purchasing_functions.php';
require_once '../includes/uploads.php';
purchasingBoot($conn);

$userId   = (int)($_SESSION['id'] ?? 0);
$userName = (string)($_SESSION['username'] ?? '');
function invFlash($type, $msg) { $_SESSION['inv_flash'] = ['type' => $type, 'msg' => $msg]; }

$poId = (int)($_GET['id'] ?? ($_POST['po_id'] ?? 0));
if ($poId <= 0) { header('Location: inventory-purchase-orders.php'); exit; }

function loadPO($conn, $poId) {
    $stmt = $conn->prepare("SELECT po.*, s.name AS supplier_name FROM inv_purchase_orders po
        LEFT JOIN inv_suppliers s ON po.supplier_id = s.id WHERE po.id = ? AND po.deleted_at IS NULL");
    $stmt->bind_param('i', $poId); $stmt->execute();
    $po = $stmt->get_result()->fetch_assoc(); $stmt->close();
    return $po;
}

$po = loadPO($conn, $poId);
if (!$po) { invFlash('danger', 'Purchase order not found.'); header('Location: inventory-purchase-orders.php'); exit; }

// Approving an order commits the shop to spending; recording a payment
// moves real money out of an account; cancelling an order that has
// already been approved reverses that commitment. All three are
// supervisor decisions - only admin and manager hold 'purchasing_approve'
// (see roleModules() in includes/auth.php).
//
// A storekeeper keeps the rest of the job: raise the order, upload the
// invoice, and book the goods in when they arrive.
$canApprove = userCan('purchasing_approve');

// ---------- Actions ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Enforced here, before any handler. Hiding the buttons is a courtesy
    // to the user, not a security control - a forged POST from a
    // storekeeper's session is refused just the same, and logged.
    $needsApproval = ($action === 'approve' || $action === 'payment' || $action === 'set_due_date'
                      || ($action === 'cancel' && $po['status'] === 'approved'));
    if ($needsApproval && !$canApprove) {
        invAudit($conn, $userId, 'denied_' . $action, 'purchase_order', $poId, $po['po_number']);
        invFlash('danger', 'Only a manager or administrator can '
            . ($action === 'payment' ? 'record a payment to a supplier.'
               : ($action === 'set_due_date' ? 'change the payment due date.'
               : ($action === 'cancel' ? 'cancel an approved order.'
                                       : 'approve a purchase order.'))));
        header('Location: inventory-po-view.php?id=' . $poId);
        exit;
    }

    if ($action === 'approve' && $po['status'] === 'draft') {
        // Due date is informational (nothing enforces it) - defaulted
        // from the order date + the shop's standard payment terms, but
        // never overwritten if one was already set by hand beforehand.
        $dueDate = $po['due_date'];
        if (!$dueDate) {
            $termsDays = (int)getInvSetting($conn, 'default_payment_terms_days', '30');
            $baseDate = $po['order_date'] ?: date('Y-m-d');
            $dueDate = date('Y-m-d', strtotime($baseDate . ' + ' . $termsDays . ' days'));
        }
        $stmt = $conn->prepare("UPDATE inv_purchase_orders SET status='approved', approved_by=?, due_date=? WHERE id=?");
        $stmt->bind_param('isi', $userId, $dueDate, $poId); $stmt->execute(); $stmt->close();
        invAudit($conn, $userId, 'approve', 'purchase_order', $poId, $po['po_number']);
        invFlash('success', 'Purchase order approved.');
    }
    elseif ($action === 'set_due_date') {
        $newDue = trim($_POST['due_date'] ?? '');
        $newDue = ($newDue !== '' && strtotime($newDue) !== false) ? $newDue : null;
        $stmt = $conn->prepare("UPDATE inv_purchase_orders SET due_date=? WHERE id=?");
        $stmt->bind_param('si', $newDue, $poId); $stmt->execute(); $stmt->close();
        invAudit($conn, $userId, 'due_date_update', 'purchase_order', $poId, $po['po_number'] . ' - ' . ($newDue ?: 'cleared'));
        invFlash('success', 'Due date updated.');
    }
    elseif ($action === 'cancel' && in_array($po['status'], ['draft','approved'], true)) {
        $stmt = $conn->prepare("UPDATE inv_purchase_orders SET status='cancelled' WHERE id=?");
        $stmt->bind_param('i', $poId); $stmt->execute(); $stmt->close();
        invAudit($conn, $userId, 'cancel', 'purchase_order', $poId, $po['po_number']);
        invFlash('success', 'Purchase order cancelled.');
    }
    elseif ($action === 'payment') {
        // If an EFD receipt was attached, validate and store it BEFORE
        // touching money - so a bad file never leaves a payment half
        // recorded. poRecordPayment() decides whether one was actually
        // required (only the payment that fully settles the order needs
        // it); if it still refuses for that or any other reason, the
        // just-stored file is deleted so nothing orphans.
        $efdDir = __DIR__ . '/../assets/uploads/efd_receipts';
        $efdFile = null;
        $efdUploadError = null;
        if (!empty($_FILES['efd_receipt']['name'])) {
            [$efdOk, $efdResult] = uploadStoreDocument($_FILES['efd_receipt'], $efdDir, 'efd_po' . $poId);
            if ($efdOk) { $efdFile = $efdResult; } else { $efdUploadError = $efdResult; }
        }

        if ($efdUploadError !== null) {
            invFlash('danger', $efdUploadError);
        } else {
            // A payment is money actually leaving an account, so it needs an
            // amount and a source - it is no longer a status you can simply
            // declare. payment_status is derived from what was recorded.
            [$ok, $msg] = poRecordPayment(
                $conn, $poId,
                (float)($_POST['amount'] ?? 0),
                (int)($_POST['paid_from_account_id'] ?? 0),
                $_POST['payment_date'] ?? date('Y-m-d'),
                trim($_POST['reference'] ?? ''),
                $userId, $userName,
                $efdFile
            );
            if (!$ok && $efdFile !== null) { uploadDeleteFile($efdDir, $efdFile); }
            invFlash($ok ? 'success' : 'danger', $msg);
        }
    }
    elseif ($action === 'invoice' && isset($_FILES['invoice'])) {
        // Validated by content, not by the name the browser sent, and
        // stored under a filename this side generates.
        $dir = __DIR__ . '/../assets/uploads/po_invoices';
        [$ok, $result] = uploadStoreDocument($_FILES['invoice'], $dir, 'po' . $poId);

        if (!$ok) {
            invFlash('danger', $result);
        } else {
            // Replace, don't accumulate: a PO has one invoice.
            $prevStmt = $conn->prepare("SELECT invoice_file FROM inv_purchase_orders WHERE id = ?");
            $prevStmt->bind_param('i', $poId);
            $prevStmt->execute();
            $previous = (string)($prevStmt->get_result()->fetch_assoc()['invoice_file'] ?? '');
            $prevStmt->close();

            $stmt = $conn->prepare("UPDATE inv_purchase_orders SET invoice_file=? WHERE id=?");
            $stmt->bind_param('si', $result, $poId);
            if ($stmt->execute()) {
                if ($previous !== '' && $previous !== $result) { uploadDeleteFile($dir, $previous); }
                invFlash('success', 'Invoice uploaded.');
            } else {
                uploadDeleteFile($dir, $result);
                invFlash('danger', 'Could not record the invoice.');
            }
            $stmt->close();
        }
    }
    elseif ($action === 'receive') {
        // Stock movement, goods-received note and the ledger entry
        // (DR Inventory Asset / CR Accounts Payable) all happen in one
        // transaction inside poReceiveStock().
        [$ok, $msg] = poReceiveStock(
            $conn, $poId, (array)($_POST['receive'] ?? []),
            $userId, $userName, trim($_POST['receipt_note'] ?? ''),
            (array)($_POST['expiry'] ?? [])
        );
        invFlash($ok ? 'success' : 'danger', $ok ? $msg : 'Receive failed: ' . $msg);
    }
    header('Location: inventory-po-view.php?id=' . $poId); exit;
}

// Reload after potential changes.
$po = loadPO($conn, $poId);
$lines = $conn->query("SELECT l.*, i.name AS item_name, u.abbreviation AS unit_abbr
    FROM inv_purchase_order_lines l
    JOIN inv_items i ON l.item_id = i.id
    LEFT JOIN inv_units u ON i.unit_id = u.id
    WHERE l.po_id = " . (int)$poId . " ORDER BY l.id")->fetch_all(MYSQLI_ASSOC);

$statusMeta = [
    'draft'=>['Draft','secondary'], 'approved'=>['Approved','info'],
    'partially_received'=>['Partially Received','warning'], 'received'=>['Received','success'],
    'cancelled'=>['Cancelled','danger'],
];
$sm = $statusMeta[$po['status']] ?? [ucfirst($po['status']),'secondary'];
$canReceive = in_array($po['status'], ['approved','partially_received'], true);

// Money position on this order, plus its receiving and payment history.
$money    = poPaymentSummary($conn, $poId);
$receipts = poGetReceipts($conn, $poId);
$payments = poGetPayments($conn, $poId);

// Accounts a supplier can be paid from: real assets (cash, mobile
// money, bank). Paying "from" a payable would just move debt around.
$payFromAccounts = accGetAccounts($conn, 'asset');
$payFromAccounts = array_values(array_filter($payFromAccounts, function ($a) {
    return !in_array($a['code'], ['1100', '1200'], true); // not receivables or stock
}));

$pageTitle = 'PO ' . $po['po_number'];
include 'inventory-header.php';
?>

<div class="inv-page-header">
    <div>
        <h1><i class="fas fa-file-invoice-dollar me-2" style="color:var(--inv-primary);"></i><?php echo htmlspecialchars($po['po_number']); ?></h1>
        <div class="subtitle">
            <span class="inv-badge bg-<?php echo $sm[1]; ?><?php echo $sm[1]==='warning'?' text-dark':' text-white'; ?>"><?php echo $sm[0]; ?></span>
            • <?php echo htmlspecialchars($po['supplier_name'] ?: 'No supplier'); ?>
        </div>
    </div>
    <div>
        <a href="inventory-purchase-orders.php" class="btn btn-outline-secondary"><i class="fas fa-arrow-left me-1"></i> Back</a>
        <?php if ($po['status'] === 'draft' && $canApprove): ?>
            <form method="post" class="d-inline" onsubmit="return confirm('Approve this purchase order?')">
<?php echo csrfField(); ?>
                <input type="hidden" name="action" value="approve"><input type="hidden" name="po_id" value="<?php echo $poId; ?>">
                <button class="btn btn-inv"><i class="fas fa-check me-1"></i> Approve</button>
            </form>
        <?php endif; ?>
        <?php if ($po['status'] === 'draft' && !$canApprove): ?>
            <span class="ui-badge ui-badge-muted" title="Only a manager or administrator can approve a purchase order">
                <i class="fas fa-lock me-1"></i>Awaiting approval by a manager
            </span>
        <?php endif; ?>
        <?php if ($canReceive): ?>
            <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#receiveModal"><i class="fas fa-truck-ramp-box me-1"></i> Receive Stock</button>
        <?php endif; ?>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="inv-card p-0">
            <div class="p-3 pb-0"><h6 class="fw-bold mb-0">Order Lines</h6></div>
            <div class="table-responsive">
                <table class="inv-table">
                    <thead><tr><th>Item</th><th class="text-end">Ordered</th><th class="text-end">Received</th><th class="text-end">Unit Price</th><th class="text-end">Line Total</th></tr></thead>
                    <tbody>
                    <?php foreach ($lines as $ln): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($ln['item_name']); ?></td>
                            <td class="text-end"><?php echo invQty($ln['quantity']) . ' ' . htmlspecialchars($ln['unit_abbr'] ?: ''); ?></td>
                            <td class="text-end"><?php echo invQty($ln['received_qty']); ?></td>
                            <td class="text-end"><?php echo number_format($ln['unit_price'], 2, '.', ','); ?></td>
                            <td class="text-end"><?php echo number_format($ln['quantity'] * $ln['unit_price'], 2, '.', ','); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr><td colspan="4" class="text-end fw-bold">Total</td><td class="text-end fw-bold"><?php echo invMoney($conn, $po['total_amount']); ?></td></tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="inv-card p-3 mb-3">
            <h6 class="fw-bold mb-3">Details</h6>
            <div class="d-flex justify-content-between py-1 border-bottom"><span class="text-muted small">Order date</span><span><?php echo $po['order_date'] ? date('d M Y', strtotime($po['order_date'])) : '—'; ?></span></div>
            <div class="d-flex justify-content-between py-1 border-bottom"><span class="text-muted small">Expected</span><span><?php echo $po['expected_date'] ? date('d M Y', strtotime($po['expected_date'])) : '—'; ?></span></div>
            <div class="d-flex justify-content-between py-1 border-bottom"><span class="text-muted small">Received</span><span><?php echo $po['received_date'] ? date('d M Y', strtotime($po['received_date'])) : '—'; ?></span></div>
            <div class="d-flex justify-content-between align-items-center py-1 border-bottom">
                <span class="text-muted small">Payment due</span>
                <?php if ($po['due_date'] && strtotime($po['due_date']) < strtotime(date('Y-m-d')) && $po['payment_status'] !== 'paid'): ?>
                    <span class="text-danger fw-bold"><?php echo date('d M Y', strtotime($po['due_date'])); ?> (overdue)</span>
                <?php else: ?>
                    <span><?php echo $po['due_date'] ? date('d M Y', strtotime($po['due_date'])) : '—'; ?></span>
                <?php endif; ?>
            </div>
            <?php if ($canApprove): ?>
            <form method="post" class="d-flex gap-1 align-items-center py-1">
<?php echo csrfField(); ?>
                <input type="hidden" name="action" value="set_due_date">
                <input type="hidden" name="po_id" value="<?php echo $poId; ?>">
                <input type="date" name="due_date" value="<?php echo htmlspecialchars($po['due_date'] ?? ''); ?>"
                       class="form-control form-control-sm" style="max-width:150px;">
                <button class="btn btn-sm btn-outline-secondary">Set</button>
            </form>
            <?php endif; ?>
            <?php if (!empty($po['notes'])): ?><div class="pt-2 small text-muted"><?php echo nl2br(htmlspecialchars($po['notes'])); ?></div><?php endif; ?>
        </div>

        <div class="inv-card p-3 mb-3">
            <h6 class="fw-bold mb-3">Payment</h6>
            <div class="d-flex justify-content-between py-1 border-bottom">
                <span class="text-muted small">Goods received</span>
                <span><?php echo invMoney($conn, $money['billed']); ?></span>
            </div>
            <div class="d-flex justify-content-between py-1 border-bottom">
                <span class="text-muted small">Paid so far</span>
                <span class="text-success"><?php echo invMoney($conn, $money['paid']); ?></span>
            </div>
            <div class="d-flex justify-content-between py-1 border-bottom fw-bold">
                <span>Outstanding</span>
                <span class="<?php echo $money['outstanding'] > 0.005 ? 'text-danger' : 'text-success'; ?>">
                    <?php echo invMoney($conn, $money['outstanding']); ?>
                </span>
            </div>
            <div class="small text-muted mt-2">
                You owe for what has actually been <strong>received</strong>, not what was ordered.
            </div>

            <?php if ($money['outstanding'] > 0.005 && $canApprove): ?>
            <button class="btn btn-sm btn-inv w-100 mt-3" data-bs-toggle="modal" data-bs-target="#payModal">
                <i class="fas fa-money-bill-wave me-1"></i> Record Payment
            </button>
            <?php elseif ($money['outstanding'] > 0.005): ?>
            <div class="alert alert-light mt-3 mb-0 py-2 small" style="border-radius:10px;">
                <i class="fas fa-lock me-1"></i>Only a manager or administrator can record a payment to a supplier.
            </div>
            <?php elseif ($money['billed'] > 0): ?>
            <div class="alert alert-success mt-3 mb-0 py-2 small" style="border-radius:10px;">
                <i class="fas fa-check-circle me-1"></i>This order is fully paid.
            </div>
            <?php endif; ?>

            <?php if ($payments): ?>
            <hr>
            <div class="small fw-bold mb-2">Payments</div>
            <?php foreach ($payments as $pay): ?>
            <div class="d-flex justify-content-between align-items-start py-1 border-bottom small">
                <div>
                    <code style="font-size:.72rem;"><?php echo htmlspecialchars($pay['payment_no']); ?></code>
                    <div class="text-muted" style="font-size:.72rem;">
                        <?php echo date('d M Y', strtotime($pay['payment_date'])); ?>
                        &middot; <?php echo htmlspecialchars($pay['account_name'] ?? '—'); ?>
                        <?php if ($pay['reference']): ?><br><?php echo htmlspecialchars($pay['reference']); ?><?php endif; ?>
                    </div>
                </div>
                <div class="text-end">
                    <div><?php echo number_format((float)$pay['amount'], 2); ?></div>
                    <?php if ($pay['journal_id']): ?>
                    <a href="journal-entry.php?id=<?php echo (int)$pay['journal_id']; ?>" style="font-size:.7rem;text-decoration:none;color:var(--inv-primary);">ledger</a>
                    <?php endif; ?>
                    <?php if (!empty($pay['efd_receipt_file'])): ?>
                    <br><a href="../assets/uploads/efd_receipts/<?php echo htmlspecialchars($pay['efd_receipt_file']); ?>" target="_blank" style="font-size:.7rem;text-decoration:none;color:var(--inv-primary);">EFD receipt</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <?php if ($receipts): ?>
        <div class="inv-card p-3 mb-3">
            <h6 class="fw-bold mb-3">Goods Received</h6>
            <?php foreach ($receipts as $rc): ?>
            <div class="d-flex justify-content-between align-items-start py-1 border-bottom small">
                <div>
                    <code style="font-size:.72rem;"><?php echo htmlspecialchars($rc['receipt_no']); ?></code>
                    <div class="text-muted" style="font-size:.72rem;">
                        <?php echo date('d M Y H:i', strtotime($rc['created_at'])); ?>
                        &middot; <?php echo (int)$rc['line_count']; ?> line(s)
                        &middot; <?php echo htmlspecialchars($rc['received_by_name'] ?: '—'); ?>
                    </div>
                </div>
                <div class="text-end">
                    <div><?php echo number_format((float)$rc['total_value'], 2); ?></div>
                    <?php if ($rc['journal_id']): ?>
                    <a href="journal-entry.php?id=<?php echo (int)$rc['journal_id']; ?>" style="font-size:.7rem;text-decoration:none;color:var(--inv-primary);">ledger</a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="inv-card p-3">
            <h6 class="fw-bold mb-3">Invoice</h6>
            <?php if (!empty($po['invoice_file'])): ?>
                <a href="../assets/uploads/po_invoices/<?php echo htmlspecialchars($po['invoice_file']); ?>" target="_blank" class="btn btn-sm btn-outline-info mb-2"><i class="fas fa-file me-1"></i> View current invoice</a>
            <?php endif; ?>
            <form method="post" enctype="multipart/form-data" class="d-flex gap-2 align-items-center">
<?php echo csrfField(); ?>
                <input type="hidden" name="action" value="invoice"><input type="hidden" name="po_id" value="<?php echo $poId; ?>">
                <input type="file" name="invoice" class="form-control form-control-sm" accept=".pdf,.jpg,.jpeg,.png" required>
                <button class="btn btn-sm btn-inv">Upload</button>
            </form>
            <?php // Cancelling a draft is ordinary work; cancelling an order
                  // that has already been approved reverses a commitment. ?>
            <?php if ($po['status'] === 'draft' || ($po['status'] === 'approved' && $canApprove)): ?>
                <hr>
                <form method="post" onsubmit="return confirm('Cancel this purchase order?')">
<?php echo csrfField(); ?>
                    <input type="hidden" name="action" value="cancel"><input type="hidden" name="po_id" value="<?php echo $poId; ?>">
                    <button class="btn btn-sm btn-outline-danger w-100"><i class="fas fa-ban me-1"></i> Cancel PO</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php if ($canReceive): ?>
<div class="modal fade" id="receiveModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
<?php echo csrfField(); ?>
                <input type="hidden" name="action" value="receive"><input type="hidden" name="po_id" value="<?php echo $poId; ?>">
                <div class="modal-header"><h5 class="modal-title">Receive Stock</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <p class="small text-muted">
                        Enter the quantity received for each item. Stock increases immediately, and the
                        value received is posted to the ledger as
                        <strong>Inventory Asset</strong> owed to <strong>Accounts Payable</strong>.
                        An expiry date is optional per line and dates this delivery's own batch,
                        distinct from anything already on the shelf.
                    </p>
                    <table class="table table-sm align-middle">
                        <thead><tr><th>Item</th><th class="text-end">Remaining</th><th style="width:110px;">Receive</th><th style="width:150px;">Expiry <span class="text-muted">(optional)</span></th></tr></thead>
                        <tbody>
                        <?php foreach ($lines as $ln): $remaining = (float)$ln['quantity'] - (float)$ln['received_qty']; ?>
                            <tr>
                                <td><?php echo htmlspecialchars($ln['item_name']); ?></td>
                                <td class="text-end"><?php echo invQty($remaining) . ' ' . htmlspecialchars($ln['unit_abbr'] ?: ''); ?></td>
                                <td><input type="number" step="0.001" min="0" max="<?php echo $remaining; ?>" name="receive[<?php echo $ln['id']; ?>]" class="form-control form-control-sm" value="<?php echo $remaining > 0 ? invQty($remaining) : 0; ?>" <?php echo $remaining <= 0 ? 'disabled' : ''; ?>></td>
                                <td><input type="date" name="expiry[<?php echo $ln['id']; ?>]" class="form-control form-control-sm" <?php echo $remaining <= 0 ? 'disabled' : ''; ?>></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <label class="form-label small">Note <span class="text-muted">(optional)</span></label>
                    <input type="text" name="receipt_note" class="form-control form-control-sm" maxlength="255"
                           placeholder="e.g. delivery note number, driver name">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">Confirm Receipt</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($money['outstanding'] > 0.005 && $canApprove): ?>
<!-- ============ Record a supplier payment ============ -->
<div class="modal fade" id="payModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post" enctype="multipart/form-data">
<?php echo csrfField(); ?>
                <input type="hidden" name="action" value="payment">
                <input type="hidden" name="po_id" value="<?php echo $poId; ?>">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-money-bill-wave me-2"></i>Record Payment</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-muted">
                        Posts <strong>Accounts Payable</strong> down and credits the account the money
                        actually left. This is not an expense &mdash; the cost entered the books as
                        inventory when the goods arrived.
                    </p>
                    <div class="row g-3">
                        <div class="col-6">
                            <label class="form-label">Amount</label>
                            <input type="number" step="0.01" min="0.01" max="<?php echo $money['outstanding']; ?>"
                                   name="amount" id="payAmount" class="form-control" required
                                   value="<?php echo number_format($money['outstanding'], 2, '.', ''); ?>"
                                   data-outstanding="<?php echo number_format($money['outstanding'], 2, '.', ''); ?>">
                            <div class="form-text">Outstanding: <?php echo number_format($money['outstanding'], 2); ?></div>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Date</label>
                            <input type="date" name="payment_date" class="form-control"
                                   value="<?php echo date('Y-m-d'); ?>" max="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Paid from</label>
                            <select name="paid_from_account_id" class="form-select" required>
                                <?php foreach ($payFromAccounts as $a): ?>
                                <option value="<?php echo (int)$a['id']; ?>" <?php echo $a['code'] === '1000' ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($a['code'] . ' - ' . $a['name']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Paying from Cash on Hand reduces today's expected drawer total.</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Reference <span class="text-muted">(optional)</span></label>
                            <input type="text" name="reference" class="form-control" maxlength="120"
                                   placeholder="e.g. Mobile money transaction ID">
                        </div>
                        <div class="col-12">
                            <label class="form-label" id="payEfdLabel">Supplier's EFD receipt</label>
                            <input type="file" name="efd_receipt" id="payEfdReceipt" class="form-control"
                                   accept=".pdf,.jpg,.jpeg,.png,.webp">
                            <div class="form-text" id="payEfdHint">Required to record the final payment on this order.</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-inv">Save &amp; Post</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
// Pure UX signal: the server-side check in poRecordPayment() is the
// actual control, this just tells the cashier up front whether the
// receipt is required for the amount they've typed.
(function() {
    const amt = document.getElementById('payAmount');
    const efd = document.getElementById('payEfdReceipt');
    const hint = document.getElementById('payEfdHint');
    if (!amt || !efd) { return; }
    function sync() {
        const outstanding = parseFloat(amt.dataset.outstanding) || 0;
        const isFinal = (parseFloat(amt.value) || 0) >= outstanding - 0.005;
        efd.required = isFinal;
        hint.textContent = isFinal
            ? 'Required to record the final payment on this order.'
            : 'Optional for a partial payment.';
    }
    amt.addEventListener('input', sync);
    sync();
})();
</script>
<?php endif; ?>

<?php include 'inventory-footer.php'; ?>
