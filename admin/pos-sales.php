<?php
// ============================================================
// POS Sales - completed till transactions: search, reprint,
// void (admin/manager only, returns stock).
// ============================================================
require_once '../includes/auth.php';
requireModule('pos_sales');
// Reject any POST that does not carry this session's CSRF token.
// Placed before every handler on this page, and a no-op on GET.
csrfRequire();
date_default_timezone_set('Africa/Dar_es_Salaam');
$current_page = basename($_SERVER['PHP_SELF']);
include '../includes/db.php';
require_once '../includes/pos_functions.php';
posBoot($conn);

$userId = (int)($_SESSION['id'] ?? 0);
$currentRole = $_SESSION['role'] ?? '';
$canVoid = in_array($currentRole, ['admin', 'manager'], true);
function psFlash($type, $msg) { $_SESSION['inv_flash'] = ['type' => $type, 'msg' => $msg]; }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'void') {
    if (!$canVoid) {
        psFlash('danger', 'Only admin/manager can void a sale.');
    } else {
        [$ok, $msg] = posVoidSale($conn, (int)($_POST['txn_id'] ?? 0), $userId, trim($_POST['reason'] ?? ''), (string)($_SESSION['username'] ?? ''));
        psFlash($ok ? 'success' : 'danger', $msg);
    }
    header('Location: pos-sales.php' . (!empty($_POST['back_query']) ? '?' . $_POST['back_query'] : ''));
    exit;
}

// ---------- Filters ----------
$period = $_GET['period'] ?? 'today';
$search = trim($_GET['search'] ?? '');
switch ($period) {
    case 'week':  $from = date('Y-m-d', strtotime('monday this week')); $to = date('Y-m-d'); break;
    case 'month': $from = date('Y-m-01'); $to = date('Y-m-d'); break;
    case 'all':   $from = '2000-01-01'; $to = date('Y-m-d'); break;
    default:      $period = 'today'; $from = date('Y-m-d'); $to = date('Y-m-d');
}
$fromDt = $from . ' 00:00:00';
$toDt   = $to . ' 23:59:59';

$where = "t.created_at BETWEEN ? AND ?";
$params = [$fromDt, $toDt];
$types = 'ss';
if ($search !== '') {
    $where .= " AND (t.receipt_no LIKE ? OR t.customer_name LIKE ? OR t.customer_phone LIKE ? OR t.cashier_name LIKE ?)";
    $like = "%$search%";
    array_push($params, $like, $like, $like, $like);
    $types .= 'ssss';
}
// Cashiers see their own till; supervisors see everything.
if (!in_array($currentRole, ['admin', 'manager'], true)) {
    $where .= " AND t.cashier_id = ?";
    $params[] = $userId;
    $types .= 'i';
}

$stmt = $conn->prepare(
    "SELECT t.*, (SELECT COUNT(*) FROM sales_transaction_items i WHERE i.transaction_id = t.id) AS line_count
     FROM sales_transactions t WHERE $where ORDER BY t.created_at DESC LIMIT 300");
$stmt->bind_param($types, ...$params);
$stmt->execute();
$sales = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$stmt->close();

$totals = ['count' => 0, 'gross' => 0.0, 'voided' => 0];
foreach ($sales as $s) {
    if ($s['status'] === 'voided') { $totals['voided']++; continue; }
    $totals['count']++;
    $totals['gross'] += (float)$s['total'];
}

$pageTitle = 'POS Sales';
$breadcrumbs = [['Dashboard', 'index.php'], ['POS Sales']];
include 'inventory-header.php';
?>
<div class="inv-page-header">
    <div>
        <h1><i class="fas fa-receipt me-2" style="color:var(--inv-primary);"></i>POS Sales</h1>
        <div class="subtitle">
            <?php echo $totals['count']; ?> sale(s) &middot; <strong>Tsh <?php echo number_format($totals['gross']); ?></strong>
            <?php if ($totals['voided']): ?> &middot; <?php echo $totals['voided']; ?> voided<?php endif; ?>
            <?php if (!in_array($currentRole, ['admin','manager'], true)): ?> &middot; your till only<?php endif; ?>
        </div>
    </div>
    <a href="pos.php" class="btn btn-inv"><i class="fas fa-cash-register me-1"></i> Open POS</a>
</div>

<div class="d-flex justify-content-between flex-wrap gap-2 mb-3">
    <div>
        <?php foreach (['today' => 'Today', 'week' => 'This Week', 'month' => 'This Month', 'all' => 'All'] as $k => $label): ?>
        <a href="?period=<?php echo $k; ?><?php echo $search !== '' ? '&search=' . urlencode($search) : ''; ?>"
           class="btn btn-sm <?php echo $period === $k ? 'btn-inv' : 'btn-outline-secondary'; ?>" style="border-radius:20px;"><?php echo $label; ?></a>
        <?php endforeach; ?>
    </div>
    <form method="get" class="d-flex gap-2">
        <input type="hidden" name="period" value="<?php echo htmlspecialchars($period); ?>">
        <input type="text" name="search" class="form-control" value="<?php echo htmlspecialchars($search); ?>"
               placeholder="Receipt no. / customer / cashier" style="border-radius:10px;min-width:250px;">
        <button class="btn btn-inv"><i class="fas fa-search"></i></button>
    </form>
</div>

<div class="inv-card p-0">
    <div class="table-responsive">
        <table class="inv-table">
            <thead>
                <tr><th>Receipt</th><th>Time</th><th>Cashier</th><th>Customer</th><th>Items</th><th>Total</th><th>Paid</th><th>Change</th><th style="width:150px;">Actions</th></tr>
            </thead>
            <tbody>
            <?php if (!$sales): ?>
                <tr><td colspan="9"><div class="empty-state"><i class="fas fa-receipt d-block"></i>No POS sales in this period.</div></td></tr>
            <?php endif; ?>
            <?php foreach ($sales as $s): $voided = $s['status'] === 'voided'; ?>
                <tr style="<?php echo $voided ? 'opacity:.55;' : ''; ?>">
                    <td>
                        <strong><?php echo htmlspecialchars($s['receipt_no']); ?></strong>
                        <?php if ($voided): ?><div><span class="inv-badge bg-danger text-white">VOIDED</span></div><?php endif; ?>
                    </td>
                    <td style="white-space:nowrap;"><?php echo date('d M, H:i', strtotime($s['created_at'])); ?></td>
                    <td><?php echo htmlspecialchars($s['cashier_name'] ?? '-'); ?></td>
                    <td>
                        <?php if ($s['customer_type'] === 'registered'): ?>
                            <?php echo htmlspecialchars($s['customer_name'] ?: 'Registered'); ?>
                            <div class="text-muted" style="font-size:.75rem;"><?php echo htmlspecialchars($s['customer_phone'] ?? ''); ?></div>
                        <?php else: ?>
                            <span class="text-muted">Walk-in</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo (int)$s['line_count']; ?></td>
                    <td><strong>Tsh <?php echo number_format((float)$s['total']); ?></strong>
                        <?php if ((float)$s['discount'] > 0): ?><div class="text-muted" style="font-size:.72rem;">disc. <?php echo number_format((float)$s['discount']); ?></div><?php endif; ?>
                    </td>
                    <td><?php echo number_format((float)$s['amount_paid']); ?>
                        <div class="text-muted" style="font-size:.72rem;"><?php echo htmlspecialchars(posPaymentLabel($s['payment_method'])); ?></div>
                    </td>
                    <td><?php echo number_format((float)$s['change_due']); ?></td>
                    <td>
                        <a class="btn btn-sm btn-outline-secondary" style="border-radius:8px;" target="_blank"
                           href="pos-receipt.php?id=<?php echo (int)$s['id']; ?>&noprint=1" title="View / reprint receipt">
                            <i class="fas fa-print"></i>
                        </a>
                        <?php if ($canVoid && !$voided): ?>
                        <button class="btn btn-sm btn-outline-danger" style="border-radius:8px;" title="Void sale" aria-label="Void sale <?php echo htmlspecialchars($s['receipt_no']); ?>"
                                data-ui-modal="#voidModal"
                                data-title="Void <?php echo htmlspecialchars($s['receipt_no']); ?>"
                                data-field-txn-id="<?php echo (int)$s['id']; ?>"
                                data-field-back-query="<?php echo htmlspecialchars($_SERVER['QUERY_STRING'] ?? ''); ?>">
                            <i class="fas fa-ban"></i>
                        </button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($canVoid): ?>
<!-- ONE modal reused for every row via MX.fillModal (see retail-products.php
     for the same pattern) - previously this rendered one modal per sale,
     up to 300 in the DOM for a single page load. -->
<div class="modal fade" id="voidModal" tabindex="-1" aria-labelledby="voidModalTitle" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="post" id="voidForm">
<?php echo csrfField(); ?>
                <input type="hidden" name="action" value="void">
                <input type="hidden" name="txn_id" value="">
                <input type="hidden" name="back_query" value="">
                <div class="modal-header">
                    <h5 class="modal-title" id="voidModalTitle"><span data-ui-modal-title>Void sale</span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p style="font-size:.92rem;">This returns every item on this receipt to stock and marks the sale voided. The record is kept for audit.</p>
                    <label class="form-label" for="voidReason">Reason</label>
                    <input type="text" name="reason" id="voidReason" class="form-control" placeholder="e.g. Customer returned goods" required style="border-radius:10px;">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger" style="border-radius:10px;">Void Sale</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php
$pageScript = <<<'HTML'
<script>
// MX.fillModal only touches fields named by a data-field-* attribute, so
// a reason typed for one sale would otherwise still be sitting in the
// box the next time the shared modal is opened for a different sale.
document.addEventListener('click', function (e) {
    if (e.target.closest('[data-ui-modal="#voidModal"]')) {
        const reason = document.getElementById('voidReason');
        if (reason) { reason.value = ''; }
    }
});
</script>
HTML;
include 'inventory-footer.php';
?>
