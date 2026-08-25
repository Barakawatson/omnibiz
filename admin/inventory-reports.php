<?php
require_once '../includes/auth.php';
requireModule('inventory');
date_default_timezone_set('Africa/Dar_es_Salaam');
$current_page = basename($_SERVER['PHP_SELF']);
include '../includes/db.php';
require_once '../includes/inventory_functions.php';
require_once '../includes/catalog_functions.php';   // catalogDepartmentLabel()
require_once '../includes/shop_settings.php';       // shopName()
catalogBoot($conn);

// ---------- Inputs ----------
$reports = [
    'valuation'        => 'Inventory Valuation',
    'usage'            => 'Usage (daily / weekly / monthly)',
    'most_consumed'    => 'Most Consumed Products',
    'slow_moving'      => 'Slow-moving Items',
    'supplier_purchases'=> 'Supplier Purchases',
    'waste'            => 'Waste (damage / expire / loss)',
    'adjustments'      => 'Stock Adjustment History',
    'transactions'     => 'Inventory Transaction History',
];
$report = $_GET['report'] ?? 'valuation';
if (!isset($reports[$report])) { $report = 'valuation'; }

$today = date('Y-m-d');
$from  = (isset($_GET['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'])) ? $_GET['from'] : date('Y-m-01');
$to    = (isset($_GET['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'])) ? $_GET['to'] : $today;
$granularity = in_array($_GET['granularity'] ?? '', ['daily','weekly','monthly'], true) ? $_GET['granularity'] : 'monthly';
$export = $_GET['export'] ?? '';

$fromDT = $from . ' 00:00:00';
$toDT   = $to . ' 23:59:59';

/**
 * Run one report query over the selected period.
 *
 * Every report here is "some aggregate BETWEEN two dates", so the dates
 * are the only user-supplied values in any of them and they are always
 * the same two. Binding them through one helper means no report can be
 * added later that forgets to - and the SQL text stays readable, which
 * matters because these queries are the part people actually edit.
 *
 * The rest of each statement ($period, $valExpr, $consumeTypes) is SQL
 * this file builds from allow-listed values, never from the request.
 */
function reportRows(mysqli $conn, string $sql, string $fromDT, string $toDT): array {
    $stmt = $conn->prepare($sql);
    if (!$stmt) { return []; }
    $stmt->bind_param('ss', $fromDT, $toDT);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $rows;
}

$cur = invCurrency($conn);
$consumeTypes = "'issue','damage','expire','loss'";
$valExpr = "ABS(m.quantity) * IF(i.average_cost>0,i.average_cost,i.purchase_price)";

// ---------- Build report ----------
$columns = [];
$rows = [];
$footer = null;          // optional totals row (array aligned to columns)
$align = [];             // per-column 'r' for right-align numeric
$title = $reports[$report];
$dateScoped = !in_array($report, ['valuation','slow_moving'], true);

switch ($report) {

    case 'valuation':
        $columns = ['Item','Category','Unit','Stock','Avg Cost','Stock Value','Status'];
        $align = [3=>'r',4=>'r',5=>'r'];
        $res = $conn->query("SELECT i.name, c.name AS cat, u.abbreviation AS ua,
                i.current_stock, i.average_cost, i.purchase_price, i.min_stock, i.reorder_level,
                i.current_stock * IF(i.average_cost>0,i.average_cost,i.purchase_price) AS val
            FROM inv_items i
            LEFT JOIN inv_categories c ON i.category_id=c.id
            LEFT JOIN inv_units u ON i.unit_id=u.id
            WHERE i.deleted_at IS NULL AND i.status='active'"
            . catalogDepartmentFilterSql($conn, 'i') . " ORDER BY val DESC")->fetch_all(MYSQLI_ASSOC);
        $total = 0;
        foreach ($res as $r) {
            $total += (float)$r['val'];
            [$lbl] = invStockStatus($r);
            $rows[] = [$r['name'], $r['cat'] ?: '—', $r['ua'] ?: '', invQty($r['current_stock']),
                number_format((float)($r['average_cost']>0?$r['average_cost']:$r['purchase_price']),2,'.',','),
                number_format((float)$r['val'],2,'.',','), $lbl];
        }
        $footer = ['Total','','','','', number_format($total,2,'.',','), ''];
        break;

    case 'usage':
        if ($granularity === 'daily') { $period = "DATE(m.created_at)"; $plabel = "DATE(m.created_at)"; }
        elseif ($granularity === 'weekly') { $period = "YEARWEEK(m.created_at,3)"; $plabel = "CONCAT('Week of ', DATE_FORMAT(MIN(m.created_at),'%d %b %Y'))"; }
        else { $period = "DATE_FORMAT(m.created_at,'%Y-%m')"; $plabel = "DATE_FORMAT(MIN(m.created_at),'%M %Y')"; }
        $columns = ['Period','Movements','Total Qty','Consumption Value'];
        $align = [1=>'r',2=>'r',3=>'r'];
        $res = reportRows($conn, "SELECT $plabel AS period, COUNT(*) AS cnt, SUM(ABS(m.quantity)) AS qty, SUM($valExpr) AS val
            FROM inv_stock_movements m JOIN inv_items i ON m.item_id=i.id
            WHERE m.movement_type IN ($consumeTypes) AND m.created_at BETWEEN ? AND ?
            GROUP BY $period ORDER BY MIN(m.created_at) ASC", $fromDT, $toDT);
        $tq=0; $tv=0;
        foreach ($res as $r) { $tq+=(float)$r['qty']; $tv+=(float)$r['val'];
            $rows[] = [$r['period'], (int)$r['cnt'], invQty($r['qty']), number_format((float)$r['val'],2,'.',',')]; }
        $footer = ['Total','', invQty($tq), number_format($tv,2,'.',',')];
        break;

    case 'most_consumed':
        $columns = ['Item','Category','Qty Consumed','Value'];
        $align = [2=>'r',3=>'r'];
        $res = reportRows($conn, "SELECT i.name, c.name AS cat, SUM(ABS(m.quantity)) AS qty, SUM($valExpr) AS val
            FROM inv_stock_movements m JOIN inv_items i ON m.item_id=i.id
            LEFT JOIN inv_categories c ON i.category_id=c.id
            WHERE m.movement_type IN ($consumeTypes) AND m.created_at BETWEEN ? AND ?
            GROUP BY m.item_id ORDER BY qty DESC", $fromDT, $toDT);
        foreach ($res as $r) $rows[] = [$r['name'], $r['cat'] ?: '—', invQty($r['qty']), number_format((float)$r['val'],2,'.',',')];
        break;

    case 'slow_moving':
        $columns = ['Item','Category','Current Stock','Consumed (30d)','Last Movement'];
        $align = [2=>'r',3=>'r'];
        $res = $conn->query("SELECT i.name, c.name AS cat, i.current_stock,
                COALESCE((SELECT SUM(ABS(m.quantity)) FROM inv_stock_movements m
                    WHERE m.item_id=i.id AND m.movement_type IN ($consumeTypes)
                    AND m.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)),0) AS consumed30,
                (SELECT MAX(m2.created_at) FROM inv_stock_movements m2 WHERE m2.item_id=i.id) AS last_mv
            FROM inv_items i LEFT JOIN inv_categories c ON i.category_id=c.id
            WHERE i.deleted_at IS NULL AND i.status='active'"
            . catalogDepartmentFilterSql($conn, 'i') . "
            ORDER BY consumed30 ASC, i.current_stock DESC")->fetch_all(MYSQLI_ASSOC);
        foreach ($res as $r) $rows[] = [$r['name'], $r['cat'] ?: '—', invQty($r['current_stock']),
            invQty($r['consumed30']), $r['last_mv'] ? date('d M Y', strtotime($r['last_mv'])) : 'never'];
        break;

    case 'supplier_purchases':
        $columns = ['Supplier','Orders','Total Purchased','Outstanding (unpaid)'];
        $align = [1=>'r',2=>'r',3=>'r'];
        $res = reportRows($conn, "SELECT s.name,
                COUNT(po.id) AS orders,
                COALESCE(SUM(po.total_amount),0) AS total,
                COALESCE(SUM(IF(po.payment_status<>'paid', po.total_amount, 0)),0) AS outstanding
            FROM inv_purchase_orders po
            LEFT JOIN inv_suppliers s ON po.supplier_id=s.id
            WHERE po.deleted_at IS NULL AND po.status<>'cancelled' AND po.order_date BETWEEN ? AND ?
            GROUP BY po.supplier_id ORDER BY total DESC", $fromDT, $toDT);
        $tt=0; $to_=0;
        foreach ($res as $r) { $tt+=(float)$r['total']; $to_+=(float)$r['outstanding'];
            $rows[] = [$r['name'] ?: 'Unassigned', (int)$r['orders'], number_format((float)$r['total'],2,'.',','), number_format((float)$r['outstanding'],2,'.',',')]; }
        $footer = ['Total','', number_format($tt,2,'.',','), number_format($to_,2,'.',',')];
        break;

    case 'waste':
        $columns = ['Date','Item','Type','Qty','Value','Reason','User'];
        $align = [3=>'r',4=>'r'];
        $res = reportRows($conn, "SELECT m.created_at, i.name, m.movement_type, m.quantity, $valExpr AS val, m.reason, a.username
            FROM inv_stock_movements m JOIN inv_items i ON m.item_id=i.id
            LEFT JOIN admin a ON m.user_id=a.id
            WHERE m.movement_type IN ('damage','expire','loss') AND m.created_at BETWEEN ? AND ?
            ORDER BY m.created_at DESC", $fromDT, $toDT);
        $tv=0;
        foreach ($res as $r) { $tv+=(float)$r['val'];
            $rows[] = [date('d M Y H:i', strtotime($r['created_at'])), $r['name'], ucfirst($r['movement_type']),
                invQty(abs($r['quantity'])), number_format((float)$r['val'],2,'.',','), $r['reason'] ?: '—', $r['username'] ?: 'System']; }
        $footer = ['','','Total', '', number_format($tv,2,'.',','), '', ''];
        break;

    case 'adjustments':
        $columns = ['Date','Item','Qty','Before','After','Reason','User'];
        $align = [2=>'r',3=>'r',4=>'r'];
        $res = reportRows($conn, "SELECT m.created_at, i.name, m.quantity, m.qty_before, m.qty_after, m.reason, a.username
            FROM inv_stock_movements m JOIN inv_items i ON m.item_id=i.id
            LEFT JOIN admin a ON m.user_id=a.id
            WHERE m.movement_type='adjust' AND m.created_at BETWEEN ? AND ?
            ORDER BY m.created_at DESC", $fromDT, $toDT);
        foreach ($res as $r) $rows[] = [date('d M Y H:i', strtotime($r['created_at'])), $r['name'],
            ($r['quantity']>0?'+':'').invQty($r['quantity']), invQty($r['qty_before']), invQty($r['qty_after']), $r['reason'] ?: '—', $r['username'] ?: 'System'];
        break;

    // sales_by_department / best_sellers were removed from here - both
    // duplicated reports that already exist, correctly gated, in the
    // main Reporting Centre (sales.by_department, sales.by_product), and
    // their only distinguishing effect was leaking Sales Value, Cost of
    // Sales, Gross Profit and Margin to anyone holding just 'inventory'
    // (Stock Keeper), who has no 'sales_reports' or 'accounting' key.

    case 'transactions':
        $columns = ['Date','Item','Type','Qty','Before','After','Unit Cost','Reason','User'];
        $align = [3=>'r',4=>'r',5=>'r',6=>'r'];
        $res = reportRows($conn, "SELECT m.created_at, i.name, m.movement_type, m.quantity, m.qty_before, m.qty_after, m.unit_cost, m.reason, a.username
            FROM inv_stock_movements m JOIN inv_items i ON m.item_id=i.id
            LEFT JOIN admin a ON m.user_id=a.id
            WHERE m.created_at BETWEEN ? AND ?
            ORDER BY m.created_at DESC LIMIT 1000", $fromDT, $toDT);
        foreach ($res as $r) $rows[] = [date('d M Y H:i', strtotime($r['created_at'])), $r['name'], ucfirst($r['movement_type']),
            ($r['quantity']>0?'+':'').invQty($r['quantity']), invQty($r['qty_before']), invQty($r['qty_after']),
            number_format((float)$r['unit_cost'],2,'.',','), $r['reason'] ?: '—', $r['username'] ?: 'System'];
        break;
}

$rangeLabel = $dateScoped ? (date('d M Y', strtotime($from)) . ' – ' . date('d M Y', strtotime($to))) : 'As of ' . date('d M Y');

// ---------- Exports ----------
if ($export === 'csv') {
    $fname = 'inventory_' . $report . '_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    $out = fopen('php://output', 'w');
    fputcsv($out, [$title]);
    fputcsv($out, ['Range', $rangeLabel]);
    fputcsv($out, []);
    fputcsv($out, $columns);
    foreach ($rows as $r) { fputcsv($out, $r); }
    if ($footer) { fputcsv($out, $footer); }
    fclose($out);
    exit;
}

if ($export === 'print') {
    // Minimal printable page -> user prints / saves as PDF.
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>' . htmlspecialchars($title) . '</title>';
    echo '<style>body{font-family:Arial,sans-serif;padding:24px;color:#222}h2{margin:0 0 4px}.muted{color:#666;font-size:13px;margin-bottom:16px}
        table{width:100%;border-collapse:collapse;font-size:12px}th,td{border:1px solid #ccc;padding:6px 8px;text-align:left}
        th{background:#eef6f8}tfoot td{font-weight:bold;background:#f7f7f7}.r{text-align:right}@media print{.noprint{display:none}}</style></head><body>';
    echo '<h2>' . htmlspecialchars($title) . '</h2><div class="muted">' . htmlspecialchars(shopName($conn)) . ' • ' . htmlspecialchars($rangeLabel) . ' • generated ' . date('d M Y H:i') . '</div>';
    echo '<button class="noprint" onclick="window.print()" style="margin-bottom:12px;padding:8px 14px;">Print / Save as PDF</button>';
    echo '<table><thead><tr>';
    foreach ($columns as $ci => $c) { echo '<th class="' . (isset($align[$ci])?'r':'') . '">' . htmlspecialchars($c) . '</th>'; }
    echo '</tr></thead><tbody>';
    foreach ($rows as $r) { echo '<tr>'; foreach ($r as $ci=>$cell) { echo '<td class="' . (isset($align[$ci])?'r':'') . '">' . htmlspecialchars((string)$cell) . '</td>'; } echo '</tr>'; }
    echo '</tbody>';
    if ($footer) { echo '<tfoot><tr>'; foreach ($footer as $ci=>$cell) { echo '<td class="' . (isset($align[$ci])?'r':'') . '">' . htmlspecialchars((string)$cell) . '</td>'; } echo '</tr></tfoot>'; }
    echo '</table><script>window.onload=function(){window.print();}</script></body></html>';
    exit;
}

// ---------- Screen render ----------
$qsBase = ['report'=>$report,'from'=>$from,'to'=>$to,'granularity'=>$granularity];
$pageTitle = 'Inventory Reports';
include 'inventory-header.php';
?>

<div class="inv-page-header">
    <div>
        <h1><i class="fas fa-chart-column me-2" style="color:var(--inv-primary);"></i>Inventory Reports</h1>
        <div class="subtitle"><?php echo htmlspecialchars($title); ?> • <?php echo htmlspecialchars($rangeLabel); ?></div>
    </div>
    <div>
        <a class="btn btn-outline-success" href="?<?php echo http_build_query($qsBase + ['export'=>'csv']); ?>"><i class="fas fa-file-excel me-1"></i> Excel (CSV)</a>
        <a class="btn btn-outline-danger" href="?<?php echo http_build_query($qsBase + ['export'=>'print']); ?>" target="_blank"><i class="fas fa-file-pdf me-1"></i> PDF</a>
    </div>
</div>

<div class="inv-card p-3 mb-3">
    <form class="row g-2 align-items-end" method="get">
        <div class="col-md-4">
            <label class="form-label">Report</label>
            <select name="report" class="form-select" onchange="this.form.submit()">
                <?php foreach ($reports as $k=>$v): ?><option value="<?php echo $k; ?>" <?php echo $report===$k?'selected':''; ?>><?php echo htmlspecialchars($v); ?></option><?php endforeach; ?>
            </select>
        </div>
        <?php if ($report === 'usage'): ?>
        <div class="col-md-2">
            <label class="form-label">Group by</label>
            <select name="granularity" class="form-select">
                <option value="daily" <?php echo $granularity==='daily'?'selected':''; ?>>Daily</option>
                <option value="weekly" <?php echo $granularity==='weekly'?'selected':''; ?>>Weekly</option>
                <option value="monthly" <?php echo $granularity==='monthly'?'selected':''; ?>>Monthly</option>
            </select>
        </div>
        <?php endif; ?>
        <?php if ($dateScoped): ?>
        <div class="col-md-2"><label class="form-label">From</label><input type="date" name="from" class="form-control" value="<?php echo htmlspecialchars($from); ?>"></div>
        <div class="col-md-2"><label class="form-label">To</label><input type="date" name="to" class="form-control" value="<?php echo htmlspecialchars($to); ?>"></div>
        <?php endif; ?>
        <div class="col-auto"><button class="btn btn-inv" type="submit">Run</button></div>
    </form>
</div>

<div class="inv-card p-0">
    <div class="table-responsive">
        <table class="inv-table">
            <thead><tr><?php foreach ($columns as $ci=>$c): ?><th class="<?php echo isset($align[$ci])?'text-end':''; ?>"><?php echo htmlspecialchars($c); ?></th><?php endforeach; ?></tr></thead>
            <tbody>
            <?php if (empty($rows)): ?>
                <tr><td colspan="<?php echo count($columns); ?>"><div class="empty-state"><i class="fas fa-chart-column d-block"></i>No data for this report / range.</div></td></tr>
            <?php else: foreach ($rows as $r): ?>
                <tr><?php foreach ($r as $ci=>$cell): ?><td class="<?php echo isset($align[$ci])?'text-end':''; ?>"><?php echo htmlspecialchars((string)$cell); ?></td><?php endforeach; ?></tr>
            <?php endforeach; endif; ?>
            </tbody>
            <?php if ($footer): ?>
            <tfoot><tr style="background:var(--color-primary-softer);font-weight:700;"><?php foreach ($footer as $ci=>$cell): ?><td class="<?php echo isset($align[$ci])?'text-end':''; ?>"><?php echo htmlspecialchars((string)$cell); ?></td><?php endforeach; ?></tr></tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<?php include 'inventory-footer.php'; ?>
