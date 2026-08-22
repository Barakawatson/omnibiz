<?php
require_once '../includes/auth.php';
requireModule('inventory');
date_default_timezone_set('Africa/Dar_es_Salaam');
$current_page = basename($_SERVER['PHP_SELF']);
include '../includes/db.php';
require_once '../includes/inventory_functions.php';
inventoryBoot($conn);

$expiryDays = (int)getInvSetting($conn, 'expiry_alert_days', 30);

// ---- Summary numbers -------------------------------------------------
$row = $conn->query("SELECT
        COUNT(*) AS total_items,
        COALESCE(SUM(current_stock * IF(average_cost > 0, average_cost, purchase_price)),0) AS total_value,
        SUM(current_stock <= 0) AS out_of_stock,
        SUM(current_stock > 0 AND ((reorder_level > 0 AND current_stock <= reorder_level) OR (min_stock > 0 AND current_stock <= min_stock))) AS low_stock
    FROM inv_items WHERE deleted_at IS NULL AND status='active'")->fetch_assoc();

$todayUsage = $conn->query("SELECT COALESCE(SUM(ABS(m.quantity) * IF(i.average_cost>0,i.average_cost,i.purchase_price)),0) AS v
    FROM inv_stock_movements m JOIN inv_items i ON m.item_id=i.id
    WHERE m.movement_type IN ('issue','damage','expire','loss') AND DATE(m.created_at)=CURDATE()")->fetch_assoc()['v'];

$monthPurchases = $conn->query("SELECT COALESCE(SUM(m.quantity * m.unit_cost),0) AS v
    FROM inv_stock_movements m
    WHERE m.movement_type='receive' AND YEAR(m.created_at)=YEAR(CURDATE()) AND MONTH(m.created_at)=MONTH(CURDATE())")->fetch_assoc()['v'];

$pendingPOs = 0;
$poRes = @$conn->query("SELECT COUNT(*) AS c FROM inv_purchase_orders WHERE status IN ('draft','approved') AND deleted_at IS NULL");
if ($poRes instanceof mysqli_result) { $pendingPOs = (int)$poRes->fetch_assoc()['c']; }

// ---- Alerts ----------------------------------------------------------
$lowStockItems = $conn->query("SELECT i.*, u.abbreviation AS unit_abbr FROM inv_items i
    LEFT JOIN inv_units u ON i.unit_id=u.id
    WHERE i.deleted_at IS NULL AND i.status='active'
      AND (i.current_stock <= 0 OR (i.reorder_level>0 AND i.current_stock<=i.reorder_level) OR (i.min_stock>0 AND i.current_stock<=i.min_stock))
    ORDER BY i.current_stock ASC LIMIT 8")->fetch_all(MYSQLI_ASSOC);

$expiringItems = $conn->query("SELECT i.name, i.expiry_date, i.current_stock, u.abbreviation AS unit_abbr
    FROM inv_items i LEFT JOIN inv_units u ON i.unit_id=u.id
    WHERE i.deleted_at IS NULL AND i.expiry_date IS NOT NULL AND i.current_stock > 0
      AND i.expiry_date <= DATE_ADD(CURDATE(), INTERVAL $expiryDays DAY)
    ORDER BY i.expiry_date ASC LIMIT 8")->fetch_all(MYSQLI_ASSOC);

// ---- Recent movements ------------------------------------------------
$recent = $conn->query("SELECT m.*, i.name AS item_name, a.username AS user_name
    FROM inv_stock_movements m JOIN inv_items i ON m.item_id=i.id
    LEFT JOIN admin a ON m.user_id=a.id
    ORDER BY m.created_at DESC, m.id DESC LIMIT 8")->fetch_all(MYSQLI_ASSOC);

// ---- Top consumed (last 30 days) ------------------------------------
$topConsumed = $conn->query("SELECT i.name, SUM(ABS(m.quantity)) AS qty
    FROM inv_stock_movements m JOIN inv_items i ON m.item_id=i.id
    WHERE m.movement_type IN ('issue','damage','expire','loss') AND m.created_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
    GROUP BY m.item_id ORDER BY qty DESC LIMIT 6")->fetch_all(MYSQLI_ASSOC);

// ---- Chart: value by category ---------------------------------------
$byCategory = $conn->query("SELECT COALESCE(c.name,'Uncategorized') AS name,
        COALESCE(SUM(i.current_stock * IF(i.average_cost>0,i.average_cost,i.purchase_price)),0) AS val
    FROM inv_items i LEFT JOIN inv_categories c ON i.category_id=c.id
    WHERE i.deleted_at IS NULL GROUP BY c.id HAVING val > 0 ORDER BY val DESC")->fetch_all(MYSQLI_ASSOC);

// ---- Chart: monthly consumption value (last 6 months) ---------------
$monthly = $conn->query("SELECT DATE_FORMAT(m.created_at,'%Y-%m') AS ym,
        COALESCE(SUM(ABS(m.quantity) * IF(i.average_cost>0,i.average_cost,i.purchase_price)),0) AS val
    FROM inv_stock_movements m JOIN inv_items i ON m.item_id=i.id
    WHERE m.movement_type IN ('issue','damage','expire','loss') AND m.created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY ym ORDER BY ym ASC")->fetch_all(MYSQLI_ASSOC);

$typeMeta = [
    'receive'=>['Received','#1cc88a'], 'issue'=>['Issued','#4e73df'], 'return'=>['Returned','#858796'],
    'damage'=>['Damaged','#e74a3b'], 'expire'=>['Expired','#e74a3b'], 'loss'=>['Lost','#e74a3b'],
    'adjust'=>['Adjusted','#f6c23e'], 'opening'=>['Opening','#1cc88a'], 'transfer'=>['Transferred','#36b9cc'],
];

$pageTitle = 'Inventory Dashboard';
include 'inventory-header.php';
?>

<div class="inv-page-header">
    <div>
        <h1><i class="fas fa-warehouse" style="color:var(--color-primary);font-size:1rem;margin-right:6px;"></i>Inventory</h1>
        <div class="subtitle">Live overview of stock value, usage and alerts.</div>
    </div>
    <div>
        <a href="inventory-items.php" class="btn btn-outline-secondary"><i class="fas fa-boxes-stacked me-1"></i> Items</a>
        <a href="inventory-movements.php" class="btn btn-inv"><i class="fas fa-plus me-1"></i> Record Movement</a>
    </div>
</div>

<!-- Stat cards -->
<div class="row g-3 mb-1">
    <?php
    $cards = [
        ['Total Inventory Value', invMoney($conn, $row['total_value']), 'fa-sack-dollar', 'bg-grad-teal'],
        ['Low Stock Items', (int)$row['low_stock'], 'fa-triangle-exclamation', 'bg-grad-amber'],
        ['Out of Stock', (int)$row['out_of_stock'], 'fa-circle-xmark', 'bg-grad-red'],
        ["Today's Usage", invMoney($conn, $todayUsage), 'fa-bolt', 'bg-grad-blue'],
        ['Purchases This Month', invMoney($conn, $monthPurchases), 'fa-cart-shopping', 'bg-grad-green'],
        ['Pending Purchase Orders', $pendingPOs, 'fa-file-invoice', 'bg-grad-purple'],
    ];
    foreach ($cards as $c): ?>
        <div class="col-6 col-md-4 col-xl-2">
            <div class="inv-stat-card <?php echo $c[3]; ?>">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="label"><?php echo $c[0]; ?></div>
                        <div class="value"><?php echo $c[1]; ?></div>
                    </div>
                    <i class="fas <?php echo $c[2]; ?> icon"></i>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<div class="row g-3 mt-1">
    <!-- Charts -->
    <div class="col-lg-8">
        <div class="inv-card p-3 mb-3">
            <h6 class="fw-bold mb-3">Monthly Consumption (last 6 months)</h6>
            <?php if (empty($monthly)): ?>
                <div class="empty-state py-4"><i class="fas fa-chart-line d-block"></i>No consumption recorded yet.</div>
            <?php else: ?><canvas id="monthlyChart" height="110"></canvas><?php endif; ?>
        </div>
        <div class="inv-card p-0">
            <div class="p-3 pb-0"><h6 class="fw-bold mb-0">Recent Stock Movements</h6></div>
            <div class="table-responsive">
                <table class="inv-table">
                    <thead><tr><th>Time</th><th>Item</th><th>Type</th><th class="text-end">Qty</th><th>By</th></tr></thead>
                    <tbody>
                    <?php if (empty($recent)): ?>
                        <tr><td colspan="5"><div class="empty-state py-4"><i class="fas fa-right-left d-block"></i>No movements yet.</div></td></tr>
                    <?php else: foreach ($recent as $m):
                        $meta = $typeMeta[$m['movement_type']] ?? [ucfirst($m['movement_type']),'#858796'];
                        $q = (float)$m['quantity']; ?>
                        <tr>
                            <td class="small text-muted"><?php echo date('d M H:i', strtotime($m['created_at'])); ?></td>
                            <td><?php echo htmlspecialchars($m['item_name']); ?></td>
                            <td><span class="inv-badge text-white" style="background:<?php echo $meta[1]; ?>;"><?php echo $meta[0]; ?></span></td>
                            <td class="text-end fw-semibold" style="color:<?php echo $q>0?'#1c8c5f':'#d0362b'; ?>;"><?php echo ($q>0?'+':'') . invQty($q); ?></td>
                            <td class="small"><?php echo htmlspecialchars($m['user_name'] ?: 'System'); ?></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Side column: alerts -->
    <div class="col-lg-4">
        <div class="inv-card p-3 mb-3">
            <h6 class="fw-bold mb-3"><i class="fas fa-triangle-exclamation text-warning me-1"></i>Low / Out of Stock</h6>
            <?php if (empty($lowStockItems)): ?>
                <div class="text-muted small">All items are above their minimum levels. 🎉</div>
            <?php else: foreach ($lowStockItems as $it): [$lbl,$clr]=invStockStatus($it); ?>
                <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                    <div><a href="inventory-movements.php?item=<?php echo (int)$it['id']; ?>" class="text-decoration-none text-dark"><?php echo htmlspecialchars($it['name']); ?></a></div>
                    <div><span class="inv-badge bg-<?php echo $clr; ?><?php echo $clr==='warning'?' text-dark':' text-white'; ?>"><?php echo invQty($it['current_stock']) . ' ' . htmlspecialchars($it['unit_abbr'] ?: ''); ?></span></div>
                </div>
            <?php endforeach; endif; ?>
        </div>

        <div class="inv-card p-3 mb-3">
            <h6 class="fw-bold mb-3"><i class="fas fa-hourglass-end text-danger me-1"></i>Expiring Soon (<?php echo $expiryDays; ?> days)</h6>
            <?php if (empty($expiringItems)): ?>
                <div class="text-muted small">Nothing expiring soon.</div>
            <?php else: foreach ($expiringItems as $e): ?>
                <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                    <span><?php echo htmlspecialchars($e['name']); ?></span>
                    <span class="small text-danger"><?php echo date('d M Y', strtotime($e['expiry_date'])); ?></span>
                </div>
            <?php endforeach; endif; ?>
        </div>

        <div class="inv-card p-3">
            <h6 class="fw-bold mb-3">Value by Category</h6>
            <?php if (empty($byCategory)): ?>
                <div class="text-muted small">No stock value yet.</div>
            <?php else: ?><canvas id="categoryChart" height="180"></canvas><?php endif; ?>
        </div>
    </div>
</div>

<?php
$monthlyLabels = json_encode(array_map(fn($m) => date('M Y', strtotime($m['ym'] . '-01')), $monthly));
$monthlyData   = json_encode(array_map(fn($m) => round((float)$m['val'], 2), $monthly));
$catLabels     = json_encode(array_map(fn($c) => $c['name'], $byCategory));
$catData       = json_encode(array_map(fn($c) => round((float)$c['val'], 2), $byCategory));

$pageScript = <<<JS
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function(){
    var mEl = document.getElementById('monthlyChart');
    if (mEl) new Chart(mEl, {
        type: 'line',
        data: { labels: {$monthlyLabels}, datasets: [{
            label: 'Consumption value', data: {$monthlyData},
            borderColor: '#42c3cf', backgroundColor: 'rgba(66,195,207,.15)',
            fill: true, tension: .35, pointRadius: 3
        }]},
        options: { plugins:{legend:{display:false}}, scales:{y:{beginAtZero:true}} }
    });
    var cEl = document.getElementById('categoryChart');
    if (cEl) new Chart(cEl, {
        type: 'doughnut',
        data: { labels: {$catLabels}, datasets: [{ data: {$catData},
            backgroundColor: ['#42c3cf','#4e73df','#1cc88a','#f6c23e','#e74a3b','#8e5bd6','#36b9cc','#fd7e14','#20c997','#6610f2'] }]},
        options: { plugins:{legend:{position:'bottom',labels:{boxWidth:12,font:{size:11}}}} }
    });
})();
</script>
JS;
include 'inventory-footer.php';
