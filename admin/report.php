<?php
// ============================================================
// Report renderer
// ------------------------------------------------------------
// One page renders every report, so the header, filters, summary
// cards, table, CSV and print behave identically everywhere.
//
// ACCURACY RULE FOR THIS FILE
// Financial reports call the EXISTING accounting functions -
// accProfitAndLoss(), accTrialBalance(), accGetJournal(),
// accGetExpenses(), accRecentCloses(). Their arithmetic is not
// reimplemented here, not adjusted, and not moved into JavaScript.
// Sales and stock reports aggregate the recorded rows directly; they
// never recompute a total that the system already stored.
//
// Every query is a prepared statement, and every report is bounded.
// ============================================================
require_once '../includes/auth.php';
requireRole(allSystemRoles());
csrfRequire();

$current_page = 'reports.php';           // keep Reports highlighted in the sidebar
include '../includes/db.php';
require_once '../includes/report_functions.php';
require_once '../includes/accounting_functions.php';
require_once '../includes/catalog_functions.php';
require_once '../includes/pos_functions.php';
posBoot($conn);

$group = (string)($_GET['g'] ?? '');
$key   = (string)($_GET['r'] ?? '');
$def   = reportFind($group, $key);

if (!$def) { header('Location: reports.php'); exit; }
// Each report is gated by the module its data belongs to - the same
// permission that already protects the screen that data lives on.
requireModule($def['module']);

[$from, $to, $rangeLabel, $preset] = reportResolveRange(
    $_GET['preset'] ?? null, $_GET['from'] ?? null, $_GET['to'] ?? null);

$fromDT = $from . ' 00:00:00';
$toDT   = $to   . ' 23:59:59';
$export = $_GET['export'] ?? '';

// ---- Optional filters, only where the report supports them --------
$fDept     = isset(catalogDepartments()[$_GET['dept'] ?? '']) ? $_GET['dept'] : '';
$fCashier  = (int)($_GET['cashier'] ?? 0);
$fTerminal = (int)($_GET['terminal'] ?? 0);
$fMethod   = isset(posPaymentMethods()[$_GET['method'] ?? '']) ? $_GET['method'] : '';
$fSupplier = (int)($_GET['supplier'] ?? 0);
$fCategory = (int)($_GET['category'] ?? 0);
$fItem     = (int)($_GET['item'] ?? 0);      // set by a drill-down from a product row

$columns = []; $rows = []; $footer = null; $align = []; $metrics = []; $chart = null;
$supports = [];                    // which filter controls to render
$error = '';

// ---- Pagination ---------------------------------------------------
// Only the reports that can genuinely grow are paginated; an aggregate
// over departments or ledger accounts is bounded by its own dimension
// and a pager would be noise.
//
// EXPORTS ARE NEVER PAGED. A CSV or PDF must contain the whole filtered
// set - handing someone page 3 of 9 as "the report" would be worse than
// useless. $isExport therefore lifts the window and applies a high
// safety cap instead.
$isExport   = in_array($export, ['csv','pdf','print'], true);
$page       = max(1, (int)($_GET['page'] ?? 1));
// Rows per page, from a fixed set so the value can never be abused to
// pull the whole table in one request.
$perPage    = in_array((int)($_GET['per'] ?? 0), [25, 50, 100, 200], true) ? (int)$_GET['per'] : 50;
$totalRows  = null;               // null = this report is not paginated
$exportCap  = 10000;

/**
 * Run a paginated SELECT.
 *
 * $sql must end just before any LIMIT. Returns [rows, totalCount] so the
 * pager can show "51-100 of 412" without a second hand-written query.
 */
function rqPaged(mysqli $conn, string $sql, string $types, array $params,
                 int $page, int $perPage, bool $isExport, int $cap): array {
    // COUNT over the same FROM/WHERE, so the total always agrees with
    // the rows actually shown.
    $from = stripos($sql, 'FROM');
    $countSql = 'SELECT COUNT(*) AS c FROM (' . $sql . ') AS sub';
    $cRows = rq($conn, $countSql, $types, $params);
    $total = (int)($cRows[0]['c'] ?? 0);

    $sql .= $isExport
        ? ' LIMIT ' . (int)$cap
        : ' LIMIT ' . (int)$perPage . ' OFFSET ' . (int)(($page - 1) * $perPage);

    return [rq($conn, $sql, $types, $params), $total];
}

/** Run a prepared SELECT and return rows; failures are logged, never shown. */
function rq(mysqli $conn, string $sql, string $types = '', array $params = []): array {
    $stmt = $conn->prepare($sql);
    if (!$stmt) { error_log('report query prepare failed: ' . $conn->error); return []; }
    if ($types !== '') { $stmt->bind_param($types, ...$params); }
    if (!$stmt->execute()) { error_log('report query failed: ' . $stmt->error); $stmt->close(); return []; }
    $out = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $out;
}
function m($v) { return number_format((float)$v, 2, '.', ','); }
function q($v) { return invQty($v); }

// Shared WHERE fragment for completed sales in range, with filters.
$saleWhere  = "t.status = 'completed' AND t.created_at BETWEEN ? AND ?";
$saleTypes  = 'ss';
$saleParams = [$fromDT, $toDT];
if ($fDept !== '')   { $saleWhere .= " AND t.department = ?";  $saleTypes .= 's'; $saleParams[] = $fDept; }
if ($fCashier > 0)   { $saleWhere .= " AND t.cashier_id = ?";  $saleTypes .= 'i'; $saleParams[] = $fCashier; }
if ($fTerminal > 0)  { $saleWhere .= " AND t.terminal_id = ?"; $saleTypes .= 'i'; $saleParams[] = $fTerminal; }

try {
switch ("$group.$key") {

// ============================================================
// SALES
// ============================================================
case 'sales.summary':
    $supports = ['dept','cashier','terminal'];
    $columns = ['Date','Sales','Items','Gross','Discount','Tax','Net total','Cost','Gross profit'];
    $align = [1=>1,2=>1,3=>1,4=>1,5=>1,6=>1,7=>1,8=>1];
    $rows_raw = rq($conn,
        "SELECT DATE(t.created_at) AS d, COUNT(*) AS sales,
                COALESCE(SUM(t.subtotal),0) AS gross, COALESCE(SUM(t.discount),0) AS disc,
                COALESCE(SUM(t.tax_amount),0) AS tax, COALESCE(SUM(t.total),0) AS net,
                COALESCE(SUM(t.total_cost),0) AS cost, COALESCE(SUM(t.gross_profit),0) AS profit,
                (SELECT COALESCE(SUM(li.quantity),0) FROM sales_transaction_items li
                  WHERE li.transaction_id IN (SELECT id FROM sales_transactions x
                                               WHERE DATE(x.created_at)=DATE(t.created_at) AND x.status='completed')) AS items
         FROM sales_transactions t WHERE $saleWhere
         GROUP BY DATE(t.created_at) ORDER BY d DESC", $saleTypes, $saleParams);
    $tS=$tI=$tG=$tD=$tX=$tN=$tC=$tP=0;
    foreach ($rows_raw as $r) {
        $tS+=(int)$r['sales']; $tI+=(float)$r['items']; $tG+=(float)$r['gross']; $tD+=(float)$r['disc'];
        $tX+=(float)$r['tax']; $tN+=(float)$r['net']; $tC+=(float)$r['cost']; $tP+=(float)$r['profit'];
        // Drill-down: a day opens the transactions that made it up.
        $rows[] = [['text'=>date('D, d M Y', strtotime($r['d'])),
                    'href'=>'report.php?g=sales&r=transactions&preset=custom&from=' . $r['d'] . '&to=' . $r['d']],
                   (int)$r['sales'], q($r['items']),
                   m($r['gross']), m($r['disc']), m($r['tax']), m($r['net']), m($r['cost']), m($r['profit'])];
    }
    $footer = ['Total', $tS, q($tI), m($tG), m($tD), m($tX), m($tN), m($tC), m($tP)];
    $metrics = [
        ['Net sales', 'Tsh ' . m($tN), count($rows_raw) . ' trading day(s)', ''],
        ['Transactions', number_format($tS), $tS > 0 ? 'Avg basket Tsh ' . m($tN / max(1,$tS)) : '', ''],
        ['Gross profit', 'Tsh ' . m($tP), $tN > 0 ? round(($tP / $tN) * 100, 1) . '% margin' : '', $tP >= 0 ? 'is-good' : 'is-bad'],
        ['Discounts given', 'Tsh ' . m($tD), '', $tD > 0 ? 'is-warn' : ''],
    ];
    // A trend answers "is trade rising or falling" - a question the
    // table above cannot answer at a glance.
    $chart = ['type'=>'line','label'=>'Net sales',
              'labels'=>array_reverse(array_map(fn($r)=>date('d M', strtotime($r['d'])), $rows_raw)),
              'data'=>array_reverse(array_map(fn($r)=>round((float)$r['net'],2), $rows_raw))];
    break;

case 'sales.by_product':
    $supports = ['dept','category'];
    $columns = ['Product','Category','Department','Units','Revenue','Cost','Gross profit','Margin'];
    $align = [3=>1,4=>1,5=>1,6=>1,7=>1];
    $w = $saleWhere; $ty = $saleTypes; $pa = $saleParams;
    if ($fCategory > 0) { $w .= " AND i.category_id = ?"; $ty .= 'i'; $pa[] = $fCategory; }
    [$rows_raw, $totalRows] = rqPaged($conn,
        "SELECT li.item_id, li.item_name, c.name AS cat, li.department,
                SUM(li.quantity) AS units, SUM(li.line_total) AS revenue,
                SUM(li.quantity * li.unit_cost) AS cost
         FROM sales_transaction_items li
         JOIN sales_transactions t ON t.id = li.transaction_id
         LEFT JOIN inv_items i ON i.id = li.item_id
         LEFT JOIN inv_categories c ON c.id = i.category_id
         WHERE $w GROUP BY li.item_id, li.item_name, c.name, li.department
         ORDER BY revenue DESC", $ty, $pa, $page, $perPage, $isExport, $exportCap);
    $tU=$tR=$tC=0;
    foreach ($rows_raw as $r) {
        $profit = (float)$r['revenue'] - (float)$r['cost'];
        $tU+=(float)$r['units']; $tR+=(float)$r['revenue']; $tC+=(float)$r['cost'];
        // Drill-down: a product opens its own stock movement history.
        $rows[] = [['text'=>$r['item_name'],
                    'href'=>'report.php?g=inventory&r=movements&preset=' . $preset . '&from=' . $from . '&to=' . $to . '&item=' . (int)$r['item_id']],
                   $r['cat'] ?: '—', catalogDepartmentLabel($r['department']),
                   q($r['units']), m($r['revenue']), m($r['cost']), m($profit),
                   (float)$r['revenue'] > 0 ? round(($profit/(float)$r['revenue'])*100,1).'%' : '—'];
    }
    $footer = ['Total','','', q($tU), m($tR), m($tC), m($tR-$tC), $tR>0 ? round((($tR-$tC)/$tR)*100,1).'%' : '—'];
    $metrics = [['Products sold', number_format(count($rows_raw)), '', ''],
                ['Units', q($tU), '', ''],
                ['Revenue', 'Tsh ' . m($tR), '', ''],
                ['Gross profit', 'Tsh ' . m($tR-$tC), '', ($tR-$tC) >= 0 ? 'is-good' : 'is-bad']];
    $top = array_slice($rows_raw, 0, 8);
    $chart = ['type'=>'bar','label'=>'Revenue',
              'labels'=>array_map(fn($r)=>$r['item_name'], $top),
              'data'=>array_map(fn($r)=>round((float)$r['revenue'],2), $top)];
    break;

case 'sales.by_category':
case 'sales.by_department':
    $isDept = ($key === 'by_department');
    $supports = $isDept ? [] : ['dept'];
    $columns = [$isDept ? 'Department' : 'Category','Products','Units','Revenue','Cost','Gross profit','Margin'];
    $align = [1=>1,2=>1,3=>1,4=>1,5=>1,6=>1];
    $groupExpr = $isDept ? "li.department" : "COALESCE(c.name,'Uncategorised')";
    $rows_raw = rq($conn,
        "SELECT $groupExpr AS grp, COUNT(DISTINCT li.item_id) AS products,
                SUM(li.quantity) AS units, SUM(li.line_total) AS revenue,
                SUM(li.quantity * li.unit_cost) AS cost
         FROM sales_transaction_items li
         JOIN sales_transactions t ON t.id = li.transaction_id
         LEFT JOIN inv_items i ON i.id = li.item_id
         LEFT JOIN inv_categories c ON c.id = i.category_id
         WHERE $saleWhere GROUP BY grp ORDER BY revenue DESC", $saleTypes, $saleParams);
    $tP=$tU=$tR=$tC=0;
    foreach ($rows_raw as $r) {
        $profit = (float)$r['revenue'] - (float)$r['cost'];
        $tP+=(int)$r['products']; $tU+=(float)$r['units']; $tR+=(float)$r['revenue']; $tC+=(float)$r['cost'];
        $rows[] = [$isDept ? catalogDepartmentLabel($r['grp']) : $r['grp'], (int)$r['products'],
                   q($r['units']), m($r['revenue']), m($r['cost']), m($profit),
                   (float)$r['revenue'] > 0 ? round(($profit/(float)$r['revenue'])*100,1).'%' : '—'];
    }
    $footer = ['Total', $tP, q($tU), m($tR), m($tC), m($tR-$tC), $tR>0 ? round((($tR-$tC)/$tR)*100,1).'%' : '—'];
    $metrics = [['Revenue','Tsh ' . m($tR),'',''],
                ['Gross profit','Tsh ' . m($tR-$tC),'',($tR-$tC)>=0?'is-good':'is-bad'],
                [$isDept?'Departments':'Categories', number_format(count($rows_raw)),'','']];
    $chart = ['type'=>'doughnut','label'=>'Revenue',
              'labels'=>array_map(fn($r)=>$isDept?catalogDepartmentLabel($r['grp']):$r['grp'], array_slice($rows_raw,0,8)),
              'data'=>array_map(fn($r)=>round((float)$r['revenue'],2), array_slice($rows_raw,0,8))];
    break;

case 'sales.by_cashier':
case 'sales.by_terminal':
    $isCashier = ($key === 'by_cashier');
    $supports = ['dept'];
    $columns = [$isCashier ? 'Cashier' : 'Terminal','Sales','Voided','Items','Net total','Gross profit','Avg basket'];
    $align = [1=>1,2=>1,3=>1,4=>1,5=>1,6=>1];
    $sel = $isCashier ? "COALESCE(t.cashier_name,'(unknown)')" : "COALESCE(term.name,'(no terminal)')";
    $join = $isCashier ? "" : "LEFT JOIN pos_terminals term ON term.id = t.terminal_id";
    $rows_raw = rq($conn,
        "SELECT $sel AS grp, COUNT(*) AS sales,
                COALESCE(SUM(t.total),0) AS net, COALESCE(SUM(t.gross_profit),0) AS profit,
                (SELECT COALESCE(SUM(li.quantity),0) FROM sales_transaction_items li WHERE li.transaction_id = t.id) * 0 AS zero
         FROM sales_transactions t $join WHERE $saleWhere GROUP BY grp ORDER BY net DESC", $saleTypes, $saleParams);
    // Item counts and voids need their own pass - a correlated subquery
    // inside the GROUP BY would count only one transaction's lines.
    $itemsBy = []; $voidsBy = [];
    foreach (rq($conn,
        "SELECT $sel AS grp, COALESCE(SUM(li.quantity),0) AS items
         FROM sales_transactions t $join
         JOIN sales_transaction_items li ON li.transaction_id = t.id
         WHERE $saleWhere GROUP BY grp", $saleTypes, $saleParams) as $r) { $itemsBy[$r['grp']] = (float)$r['items']; }
    $vTypes = 'ss'; $vParams = [$fromDT, $toDT];
    foreach (rq($conn,
        "SELECT $sel AS grp, COUNT(*) AS c FROM sales_transactions t $join
         WHERE t.status='voided' AND t.created_at BETWEEN ? AND ? GROUP BY grp", $vTypes, $vParams) as $r) { $voidsBy[$r['grp']] = (int)$r['c']; }
    $tS=$tV=$tI=$tN=$tP=0;
    foreach ($rows_raw as $r) {
        $it = $itemsBy[$r['grp']] ?? 0; $vd = $voidsBy[$r['grp']] ?? 0;
        $tS+=(int)$r['sales']; $tV+=$vd; $tI+=$it; $tN+=(float)$r['net']; $tP+=(float)$r['profit'];
        $rows[] = [$r['grp'], (int)$r['sales'], $vd ?: '—', q($it), m($r['net']), m($r['profit']),
                   m((float)$r['net'] / max(1,(int)$r['sales']))];
    }
    $footer = ['Total', $tS, $tV ?: '—', q($tI), m($tN), m($tP), m($tN / max(1,$tS))];
    $metrics = [[$isCashier?'Cashiers':'Terminals', number_format(count($rows_raw)),'',''],
                ['Net sales','Tsh ' . m($tN),'',''],
                ['Transactions', number_format($tS),'',''],
                ['Voided', number_format($tV),'', $tV>0?'is-warn':'']];
    break;

case 'sales.by_payment':
    $supports = ['dept'];
    $columns = ['Payment method','Tenders','Amount','Share'];
    $align = [1=>1,2=>1,3=>1];
    // Read per-tender, so a split sale counts under BOTH methods -
    // the same source the daily close uses.
    $rows_raw = rq($conn,
        "SELECT p.method, COUNT(*) AS tenders, COALESCE(SUM(p.amount),0) AS amount
         FROM sales_payments p JOIN sales_transactions t ON t.id = p.transaction_id
         WHERE $saleWhere GROUP BY p.method ORDER BY amount DESC", $saleTypes, $saleParams);
    $tT=0; $tA=0;
    foreach ($rows_raw as $r) { $tT+=(int)$r['tenders']; $tA+=(float)$r['amount']; }
    foreach ($rows_raw as $r) {
        $rows[] = [posPaymentLabel($r['method']), (int)$r['tenders'], m($r['amount']),
                   $tA>0 ? round(((float)$r['amount']/$tA)*100,1).'%' : '—'];
    }
    $footer = ['Total', $tT, m($tA), '100%'];
    $metrics = [['Tendered','Tsh ' . m($tA),'across ' . $tT . ' tender(s)','']];
    $chart = ['type'=>'doughnut','label'=>'Amount',
              'labels'=>array_map(fn($r)=>posPaymentLabel($r['method']), $rows_raw),
              'data'=>array_map(fn($r)=>round((float)$r['amount'],2), $rows_raw)];
    break;

case 'sales.transactions':
    $supports = ['dept','cashier','terminal','method'];
    $columns = ['Receipt','Date & time','Till','Cashier','Items','Discount','Total','Paid by','Status'];
    $align = [4=>1,5=>1,6=>1];
    $w = "t.created_at BETWEEN ? AND ?"; $ty='ss'; $pa=[$fromDT,$toDT];
    if ($fDept !== '')  { $w .= " AND t.department = ?";  $ty.='s'; $pa[]=$fDept; }
    if ($fCashier > 0)  { $w .= " AND t.cashier_id = ?";  $ty.='i'; $pa[]=$fCashier; }
    if ($fTerminal > 0) { $w .= " AND t.terminal_id = ?"; $ty.='i'; $pa[]=$fTerminal; }
    if ($fMethod !== '') { $w .= " AND EXISTS (SELECT 1 FROM sales_payments sp WHERE sp.transaction_id=t.id AND sp.method=?)"; $ty.='s'; $pa[]=$fMethod; }
    [$rows_raw, $totalRows] = rqPaged($conn,
        "SELECT t.*, term.name AS terminal_name,
                (SELECT COALESCE(SUM(li.quantity),0) FROM sales_transaction_items li WHERE li.transaction_id=t.id) AS items
         FROM sales_transactions t LEFT JOIN pos_terminals term ON term.id=t.terminal_id
         WHERE $w ORDER BY t.id DESC", $ty, $pa, $page, $perPage, $isExport, $exportCap);
    $tT=0; $tD=0; $nv=0;
    foreach ($rows_raw as $r) {
        $voided = $r['status'] === 'voided';
        if (!$voided) { $tT += (float)$r['total']; $tD += (float)$r['discount']; } else { $nv++; }
        // Drill-down: the receipt number opens the existing receipt view.
        $rows[] = [['text'=>$r['receipt_no'], 'href'=>'pos-receipt.php?id=' . (int)$r['id'] . '&noprint=1'],
                   date('d M Y H:i', strtotime($r['created_at'])),
                   $r['terminal_name'] ?: '—', $r['cashier_name'] ?: '—',
                   q($r['items']), m($r['discount']), m($r['total']),
                   posPaymentLabel($r['payment_method']),
                   ['text' => $voided ? 'Voided' : 'Completed', 'badge' => $voided ? 'danger' : 'success']];
    }
    $footer = ['Completed total','','','','', m($tD), m($tT), '', ''];
    $metrics = [['Transactions', number_format(count($rows_raw)), $nv ? $nv . ' voided' : 'none voided', $nv?'is-warn':''],
                ['Completed total','Tsh ' . m($tT),'','']];

    break;

// ============================================================
// INVENTORY  (stock reports are a position, not a period)
// ============================================================
case 'inventory.valuation':
    $supports = ['dept','category'];
    $columns = ['Item','SKU','Category','Department','In stock','Unit','Avg cost','Stock value','Status'];
    $align = [4=>1,6=>1,7=>1];
    $w = "i.deleted_at IS NULL AND i.status='active'"; $ty=''; $pa=[];
    if ($fDept !== '')     { $w .= " AND i.department = ?"; $ty.='s'; $pa[]=$fDept; }
    if ($fCategory > 0)    { $w .= " AND i.category_id = ?"; $ty.='i'; $pa[]=$fCategory; }
    [$rows_raw, $totalRows] = rqPaged($conn,
        "SELECT i.*, c.name AS cat, u.abbreviation AS unit
         FROM inv_items i LEFT JOIN inv_categories c ON c.id=i.category_id
         LEFT JOIN inv_units u ON u.id=i.unit_id
         WHERE $w ORDER BY (i.current_stock * i.average_cost) DESC",
        $ty, $pa, $page, $perPage, $isExport, $exportCap);
    $tQ=0; $tV=0;
    foreach ($rows_raw as $r) {
        // Weighted-average cost, exactly as recordStockMovement() maintains
        // it. No alternative valuation method is introduced here.
        $cost = (float)$r['average_cost'] > 0 ? (float)$r['average_cost'] : (float)$r['purchase_price'];
        $val  = (float)$r['current_stock'] * $cost;
        $tQ += (float)$r['current_stock']; $tV += $val;
        [$lbl, $tone] = invStockStatus($r);
        // Drill-down: an item opens its batch breakdown (FEFO order).
        $rows[] = [['text'=>$r['name'],
                    'href'=>'report.php?g=inventory&r=batches&item=' . (int)$r['id']],
                   $r['sku'] ?: '—', $r['cat'] ?: '—', catalogDepartmentLabel($r['department']),
                   q($r['current_stock']), $r['unit'] ?: '', m($cost), m($val),
                   ['text'=>$lbl, 'badge'=>$tone==='danger'?'danger':($tone==='warning'?'warning':'success')]];
    }
    $footer = ['Total','','','', q($tQ), '', '', m($tV), ''];
    $metrics = [['Stock value','Tsh ' . m($tV),'at weighted-average cost',''],
                ['Active items', number_format(count($rows_raw)),'',''],
                ['Total units', q($tQ),'','']];
    break;

case 'inventory.low_stock':
    $supports = ['dept','category'];
    $columns = ['Item','SKU','Category','In stock','Min','Reorder at','Shortfall','Value at cost','Status'];
    $align = [3=>1,4=>1,5=>1,6=>1,7=>1];
    $w = "i.deleted_at IS NULL AND i.status='active'
          AND (i.current_stock <= 0 OR (i.reorder_level > 0 AND i.current_stock <= i.reorder_level))";
    $ty=''; $pa=[];
    if ($fDept !== '')  { $w .= " AND i.department = ?"; $ty.='s'; $pa[]=$fDept; }
    if ($fCategory > 0) { $w .= " AND i.category_id = ?"; $ty.='i'; $pa[]=$fCategory; }
    $rows_raw = rq($conn,
        "SELECT i.*, c.name AS cat FROM inv_items i LEFT JOIN inv_categories c ON c.id=i.category_id
         WHERE $w ORDER BY (i.current_stock <= 0) DESC, i.current_stock ASC", $ty, $pa);
    $out=0; $low=0; $tV=0;
    foreach ($rows_raw as $r) {
        $isOut = (float)$r['current_stock'] <= 0;
        $isOut ? $out++ : $low++;
        $cost = (float)$r['average_cost'] > 0 ? (float)$r['average_cost'] : (float)$r['purchase_price'];
        $tV  += (float)$r['current_stock'] * $cost;
        // The gap to the shop's OWN reorder level - not an invented one.
        $short = max(0, (float)$r['reorder_level'] - (float)$r['current_stock']);
        $rows[] = [['text'=>$r['name'],
                    'href'=>'report.php?g=inventory&r=batches&item=' . (int)$r['id']],
                   $r['sku'] ?: '—', $r['cat'] ?: '—',
                   q($r['current_stock']), q($r['min_stock']), q($r['reorder_level']),
                   $short > 0 ? q($short) : '—', m((float)$r['current_stock'] * $cost),
                   ['text'=>$isOut?'Out of stock':'Low','badge'=>$isOut?'danger':'warning']];
    }
    $metrics = [['Out of stock', number_format($out), 'cannot be sold', $out?'is-bad':'is-good'],
                ['Low stock', number_format($low), 'at or below reorder level', $low?'is-warn':'is-good'],
                ['Value still on shelf','Tsh ' . m($tV),'','']];
    break;

case 'inventory.batches':
    $supports = ['dept','category','item'];
    $columns = ['Item','Batch No','Qty','Unit Cost','Value','Expiry Date','Status'];
    $align = [2=>1,3=>1,4=>1];
    $w = "i.deleted_at IS NULL AND i.status='active' AND b.status='active' AND b.quantity > 0"; $ty=''; $pa=[];
    if ($fDept !== '')     { $w .= " AND i.department = ?"; $ty.='s'; $pa[]=$fDept; }
    if ($fCategory > 0)    { $w .= " AND i.category_id = ?"; $ty.='i'; $pa[]=$fCategory; }
    if ($fItem > 0)        { $w .= " AND i.id = ?"; $ty.='i'; $pa[]=$fItem; }
    [$rows_raw, $totalRows] = rqPaged($conn,
        "SELECT b.id, b.batch_no, b.quantity, b.unit_cost, b.expiry_date, b.status,
                i.id AS item_id, i.name AS item_name, c.name AS cat
         FROM inv_batches b
         JOIN inv_items i ON i.id = b.item_id
         LEFT JOIN inv_categories c ON c.id = i.category_id
         WHERE $w ORDER BY (b.expiry_date IS NULL) ASC, b.expiry_date ASC, b.id ASC",
        $ty, $pa, $page, $perPage, $isExport, $exportCap);
    $tQ=0; $tV=0; $expiringSoon=0;
    foreach ($rows_raw as $r) {
        $val = (float)$r['quantity'] * (float)$r['unit_cost'];
        $tQ += (float)$r['quantity']; $tV += $val;
        $exp = $r['expiry_date'];
        if ($exp && strtotime($exp) <= strtotime('+7 days')) { $expiringSoon++; }
        $rows[] = [['text'=>$r['item_name'],
                    'href'=>'report.php?g=inventory&r=batches&item=' . (int)$r['item_id']],
                   $r['batch_no'] ?: '—',
                   q($r['quantity']),
                   m($r['unit_cost']),
                   m($val),
                   $exp ? date('d M Y', strtotime($exp)) : '—',
                   ['text'=>ucfirst($r['status']), 'badge'=>$r['status']==='active'?'success':'secondary']];
    }
    $footer = ['Total','', q($tQ), '', m($tV), '', ''];
    $metrics = [['Active batches', number_format(count($rows_raw)), '', ''],
                ['Total qty', q($tQ), '', ''],
                ['Batch value', 'Tsh ' . m($tV), '', ''],
                ['Expiring within 7 days', number_format($expiringSoon), '', $expiringSoon?'is-warn':'']];
    break;

case 'inventory.movements':
case 'inventory.waste':
case 'inventory.adjustments':
    $supports = ['dept'];
    $columns = ['Date & time','Item','Type','Quantity','Before','After','Value at cost','Reason','User'];
    $align = [3=>1,4=>1,5=>1,6=>1];
    $typeFilter = '';
    if ($key === 'waste')       { $typeFilter = " AND m.movement_type IN ('damage','expire','loss')"; }
    if ($key === 'adjustments') { $typeFilter = " AND m.movement_type = 'adjust'"; }
    $w = "m.created_at BETWEEN ? AND ?$typeFilter"; $ty='ss'; $pa=[$fromDT,$toDT];
    if ($fDept !== '') { $w .= " AND i.department = ?"; $ty.='s'; $pa[]=$fDept; }
    // Set when arriving from a product row, so the report shows that
    // item's history rather than the whole shop's.
    if ($fItem > 0)    { $w .= " AND m.item_id = ?";    $ty.='i'; $pa[]=$fItem; }
    [$rows_raw, $totalRows] = rqPaged($conn,
        "SELECT m.*, i.name AS item_name, i.average_cost, i.purchase_price, a.username
         FROM inv_stock_movements m JOIN inv_items i ON i.id=m.item_id
         LEFT JOIN admin a ON a.id=m.user_id
         WHERE $w ORDER BY m.created_at DESC, m.id DESC",
        $ty, $pa, $page, $perPage, $isExport, $exportCap);
    $tV=0;
    foreach ($rows_raw as $r) {
        $cost = (float)$r['unit_cost'] > 0 ? (float)$r['unit_cost']
              : ((float)$r['average_cost'] > 0 ? (float)$r['average_cost'] : (float)$r['purchase_price']);
        $val  = abs((float)$r['quantity']) * $cost;
        $tV  += $val;
        $rows[] = [date('d M Y H:i', strtotime($r['created_at'])), $r['item_name'],
                   ucfirst($r['movement_type']),
                   ((float)$r['quantity'] > 0 ? '+' : '') . q($r['quantity']),
                   q($r['qty_before']), q($r['qty_after']), m($val),
                   $r['reason'] ?: '—', $r['username'] ?: 'System'];
    }
    $footer = ['Total','','','','','', m($tV), '', ''];
    $metrics = [['Movements', number_format(count($rows_raw)),'',''],
                ['Value moved','Tsh ' . m($tV),'at cost', $key==='waste' && $tV>0 ? 'is-bad':'']];

    break;

case 'inventory.most_consumed':
case 'inventory.slow_moving':
    $slow = ($key === 'slow_moving');
    $supports = ['dept','category'];
    $columns = $slow
        ? ['Item','Category','In stock','Consumed in period','Value idle','Last movement']
        : ['Item','Category','Quantity consumed','Value at cost'];
    $align = $slow ? [2=>1,3=>1,4=>1] : [2=>1,3=>1];
    $w = "i.deleted_at IS NULL AND i.status='active'"; $ty=''; $pa=[];
    if ($fDept !== '')  { $w .= " AND i.department = ?"; $ty.='s'; $pa[]=$fDept; }
    if ($fCategory > 0) { $w .= " AND i.category_id = ?"; $ty.='i'; $pa[]=$fCategory; }
    $consume = "'issue','damage','expire','loss'";
    $rows_raw = rq($conn,
        "SELECT i.name, i.current_stock, i.average_cost, i.purchase_price, c.name AS cat,
                COALESCE((SELECT SUM(ABS(m.quantity)) FROM inv_stock_movements m
                    WHERE m.item_id=i.id AND m.movement_type IN ($consume)
                      AND m.created_at BETWEEN ? AND ?),0) AS consumed,
                (SELECT MAX(m2.created_at) FROM inv_stock_movements m2 WHERE m2.item_id=i.id) AS last_mv
         FROM inv_items i LEFT JOIN inv_categories c ON c.id=i.category_id
         WHERE $w ORDER BY consumed " . ($slow ? "ASC, i.current_stock DESC" : "DESC") . " LIMIT 300",
        'ss' . $ty, array_merge([$fromDT,$toDT], $pa));
    $tC=0; $tV=0;
    foreach ($rows_raw as $r) {
        $cost = (float)$r['average_cost'] > 0 ? (float)$r['average_cost'] : (float)$r['purchase_price'];
        $tC += (float)$r['consumed'];
        if ($slow) {
            $idle = (float)$r['current_stock'] * $cost; $tV += $idle;
            $rows[] = [$r['name'], $r['cat'] ?: '—', q($r['current_stock']), q($r['consumed']), m($idle),
                       $r['last_mv'] ? date('d M Y', strtotime($r['last_mv'])) : 'never'];
        } else {
            if ((float)$r['consumed'] <= 0) { continue; }
            $v = (float)$r['consumed'] * $cost; $tV += $v;
            $rows[] = [$r['name'], $r['cat'] ?: '—', q($r['consumed']), m($v)];
        }
    }
    $footer = $slow ? ['Total','','', q($tC), m($tV), ''] : ['Total','', q($tC), m($tV)];
    $metrics = [['Items', number_format(count($rows)),'',''],
                [$slow ? 'Value sitting idle' : 'Value consumed','Tsh ' . m($tV),'at cost', $slow?'is-warn':'']];
    break;

// ============================================================
// PURCHASING
// ============================================================
case 'purchasing.supplier_purchases':
    $supports = [];
    $columns = ['Supplier','Orders','Ordered value','Received value','Paid','Outstanding'];
    $align = [1=>1,2=>1,3=>1,4=>1,5=>1];
    $rows_raw = rq($conn,
        "SELECT COALESCE(s.name,'(no supplier)') AS name, COUNT(DISTINCT po.id) AS orders,
                COALESCE(SUM(po.total_amount),0) AS ordered,
                COALESCE((SELECT SUM(r.total_value) FROM inv_po_receipts r
                          JOIN inv_purchase_orders p2 ON p2.id=r.po_id
                          WHERE p2.supplier_id <=> po.supplier_id AND p2.deleted_at IS NULL),0) AS received,
                COALESCE((SELECT SUM(pm.amount) FROM inv_po_payments pm
                          JOIN inv_purchase_orders p3 ON p3.id=pm.po_id
                          WHERE p3.supplier_id <=> po.supplier_id AND p3.deleted_at IS NULL),0) AS paid
         FROM inv_purchase_orders po LEFT JOIN inv_suppliers s ON s.id=po.supplier_id
         WHERE po.deleted_at IS NULL AND po.status <> 'cancelled' AND po.order_date BETWEEN ? AND ?
         GROUP BY po.supplier_id, s.name ORDER BY ordered DESC", 'ss', [$from, $to]);
    $tO=0;$tOv=0;$tR=0;$tP=0;
    foreach ($rows_raw as $r) {
        $outst = (float)$r['received'] - (float)$r['paid'];
        $tO+=(int)$r['orders']; $tOv+=(float)$r['ordered']; $tR+=(float)$r['received']; $tP+=(float)$r['paid'];
        $rows[] = [$r['name'], (int)$r['orders'], m($r['ordered']), m($r['received']), m($r['paid']), m($outst)];
    }
    $footer = ['Total', $tO, m($tOv), m($tR), m($tP), m($tR-$tP)];
    $metrics = [['Suppliers', number_format(count($rows_raw)),'',''],
                ['Ordered','Tsh ' . m($tOv),'',''],
                ['Outstanding','Tsh ' . m($tR-$tP),'on goods received', ($tR-$tP)>0?'is-warn':'is-good']];
    break;

case 'purchasing.purchase_orders':
    $supports = ['supplier'];
    $columns = ['PO number','Supplier','Order date','Expected','Total','Status','Payment'];
    $align = [4=>1];
    $w = "po.deleted_at IS NULL AND po.order_date BETWEEN ? AND ?"; $ty='ss'; $pa=[$from,$to];
    if ($fSupplier > 0) { $w .= " AND po.supplier_id = ?"; $ty.='i'; $pa[]=$fSupplier; }
    [$rows_raw, $totalRows] = rqPaged($conn,
        "SELECT po.*, s.name AS supplier FROM inv_purchase_orders po
         LEFT JOIN inv_suppliers s ON s.id=po.supplier_id
         WHERE $w ORDER BY po.order_date DESC, po.id DESC",
        $ty, $pa, $page, $perPage, $isExport, $exportCap);
    $tT=0;
    foreach ($rows_raw as $r) {
        $tT += (float)$r['total_amount'];
        $st = $r['status'];
        $tone = $st==='received' ? 'success' : ($st==='cancelled' ? 'danger' : ($st==='draft' ? 'neutral' : 'info'));
        $pay = $r['payment_status'];
        // Drill-down: the PO number opens the existing purchase order.
        $rows[] = [['text'=>$r['po_number'], 'href'=>'inventory-po-view.php?id=' . (int)$r['id']],
                   $r['supplier'] ?: '—',
                   $r['order_date'] ? date('d M Y', strtotime($r['order_date'])) : '—',
                   $r['expected_date'] ? date('d M Y', strtotime($r['expected_date'])) : '—',
                   m($r['total_amount']),
                   ['text'=>ucfirst(str_replace('_',' ',$st)),'badge'=>$tone],
                   ['text'=>ucfirst($pay),'badge'=>$pay==='paid'?'success':($pay==='partial'?'warning':'neutral')]];
    }
    $footer = ['Total','','','', m($tT), '', ''];
    $metrics = [['Purchase orders', number_format(count($rows_raw)),'',''],
                ['Ordered value','Tsh ' . m($tT),'','']];
    break;

case 'purchasing.goods_received':
    $supports = ['supplier'];
    $columns = ['Receipt no','PO number','Supplier','Received','Lines','Value','Received by'];
    $align = [4=>1,5=>1];
    $w = "r.created_at BETWEEN ? AND ? AND po.deleted_at IS NULL"; $ty='ss'; $pa=[$fromDT,$toDT];
    if ($fSupplier > 0) { $w .= " AND po.supplier_id = ?"; $ty.='i'; $pa[]=$fSupplier; }
    [$rows_raw, $totalRows] = rqPaged($conn,
        "SELECT r.*, po.po_number, s.name AS supplier FROM inv_po_receipts r
         JOIN inv_purchase_orders po ON po.id=r.po_id
         LEFT JOIN inv_suppliers s ON s.id=po.supplier_id
         WHERE $w ORDER BY r.created_at DESC",
        $ty, $pa, $page, $perPage, $isExport, $exportCap);
    $tV=0; $tL=0;
    foreach ($rows_raw as $r) {
        $tV += (float)$r['total_value']; $tL += (int)$r['line_count'];
        $rows[] = [$r['receipt_no'],
                   ['text'=>$r['po_number'], 'href'=>'inventory-po-view.php?id=' . (int)$r['po_id']],
                   $r['supplier'] ?: '—',
                   date('d M Y H:i', strtotime($r['created_at'])), (int)$r['line_count'],
                   m($r['total_value']), $r['received_by_name'] ?: '—'];
    }
    $footer = ['Total','','','', $tL, m($tV), ''];
    $metrics = [['Deliveries', number_format(count($rows_raw)),'',''],
                ['Value received','Tsh ' . m($tV),'added to inventory','']];
    break;

// ============================================================
// ACCOUNTING - all figures from the existing helpers
// ============================================================
case 'accounting.profit_loss':
    $supports = [];
    $columns = ['Account','Code','Amount'];
    $align = [2=>1];
    // Exactly the calculation profit-loss.php performs, unchanged.
    $pl = accProfitAndLoss($conn, $from, $to);
    $cogsCode = accSystemAccounts()['cogs'];
    $costOfSales = 0.0; $operating = [];
    foreach ($pl['expenses'] as $e) {
        if ($e['code'] === $cogsCode) { $costOfSales += (float)$e['amount']; }
        else { $operating[] = $e; }
    }
    $operatingTotal = array_sum(array_map(fn($e)=>(float)$e['amount'], $operating));
    $grossProfit = round($pl['total_revenue'] - $costOfSales, 2);
    $netProfit   = round($grossProfit - $operatingTotal, 2);

    $rows[] = [['text'=>'REVENUE','row'=>'section'],'',''];
    // Drill-down: an account opens its ledger, which lists the journal
    // entries that produced the figure.
    foreach ($pl['revenue'] as $r) {
        $rows[] = [['text'=>$r['name'],'row'=>'sub',
                    'href'=>'journal-entry.php?account_id=' . (int)$r['id'] . '&from=' . $from . '&to=' . $to],
                   $r['code'], m($r['amount'])];
    }
    $rows[] = [['text'=>'Total revenue','row'=>'total'],'', m($pl['total_revenue'])];
    $rows[] = [['text'=>'COST OF SALES','row'=>'section'],'',''];
    $rows[] = [['text'=>'Cost of goods sold','row'=>'sub'], $cogsCode, m($costOfSales)];
    $rows[] = [['text'=>'Gross profit','row'=>'total'],'', m($grossProfit)];
    $rows[] = [['text'=>'OPERATING EXPENSES','row'=>'section'],'',''];
    foreach ($operating as $e) {
        $rows[] = [['text'=>$e['name'],'row'=>'sub',
                    'href'=>'journal-entry.php?account_id=' . (int)$e['id'] . '&from=' . $from . '&to=' . $to],
                   $e['code'], m($e['amount'])];
    }
    $rows[] = [['text'=>'Total operating expenses','row'=>'total'],'', m($operatingTotal)];
    $rows[] = [['text'=>'NET PROFIT','row'=>'total'],'', m($netProfit)];

    $metrics = [
        ['Revenue','Tsh ' . m($pl['total_revenue']),'',''],
        ['Gross profit','Tsh ' . m($grossProfit), $pl['total_revenue']>0 ? round(($grossProfit/$pl['total_revenue'])*100,1).'% margin':'', $grossProfit>=0?'is-good':'is-bad'],
        ['Operating expenses','Tsh ' . m($operatingTotal),'',''],
        ['Net profit','Tsh ' . m($netProfit), $pl['total_revenue']>0 ? round(($netProfit/$pl['total_revenue'])*100,1).'% margin':'', $netProfit>=0?'is-good':'is-bad'],
    ];
    break;

case 'accounting.trial_balance':
    $supports = [];
    $columns = ['Code','Account','Type','Debit','Credit'];
    $align = [3=>1,4=>1];
    $tb = accTrialBalance($conn, $from, $to);   // existing helper, untouched
    $tD=0; $tC=0;
    foreach ($tb as $r) {
        // accTrialBalance() returns 'debits'/'credits' (plural).
        $d = (float)($r['debits'] ?? 0); $c = (float)($r['credits'] ?? 0);
        $tD += $d; $tC += $c;
        $rows[] = [$r['code'],
                   ['text'=>$r['name'],
                    'href'=>'journal-entry.php?account_id=' . (int)$r['id'] . '&from=' . $from . '&to=' . $to],
                   ucfirst($r['type']), $d ? m($d) : '', $c ? m($c) : ''];
    }
    $footer = ['','Total','', m($tD), m($tC)];
    $balanced = abs($tD - $tC) < 0.005;
    $metrics = [['Total debits','Tsh ' . m($tD),'',''],
                ['Total credits','Tsh ' . m($tC),'',''],
                ['Balance', $balanced ? 'Balanced' : 'OUT BY ' . m(abs($tD-$tC)),
                 $balanced ? 'debits equal credits' : 'investigate before relying on these figures',
                 $balanced ? 'is-good' : 'is-bad']];
    break;

case 'accounting.journal':
    $supports = [];
    $columns = ['Entry','Date','Memo','Source','Debit','Credit','Status'];
    $align = [4=>1,5=>1];
    // accGetJournal() is the authoritative reader and its logic is not
    // changed; the page window is applied to what it returns.
    $entriesAll = accGetJournal($conn, $from, $to, '', $exportCap);
    $totalRows  = count($entriesAll);
    $entries    = $isExport ? $entriesAll : array_slice($entriesAll, ($page-1)*$perPage, $perPage);
    $tD=0;$tC=0;
    foreach ($entries as $e) {
        $tD += (float)$e['total_debit']; $tC += (float)$e['total_credit'];
        // Drill-down: the entry number opens its individual lines.
        $rows[] = [['text'=>$e['entry_no'], 'href'=>'journal-entry.php?id=' . (int)$e['id']],
                   date('d M Y', strtotime($e['entry_date'])),
                   $e['memo'] ?: '—', str_replace('_',' ', $e['source_type']),
                   m($e['total_debit']), m($e['total_credit']),
                   ['text'=>ucfirst($e['status']),'badge'=>$e['status']==='posted'?'success':'neutral']];
    }
    // Totals are for the PAGE shown; the count card reports the whole set.
    $footer = ['','','Page total','', m($tD), m($tC), ''];
    $allD = array_sum(array_map(fn($e)=>(float)$e['total_debit'], $entriesAll));
    $allC = array_sum(array_map(fn($e)=>(float)$e['total_credit'], $entriesAll));
    $metrics = [['Entries', number_format($totalRows),'in this period',''],
                ['Total debits','Tsh ' . m($allD),'whole period',''],
                ['Total credits','Tsh ' . m($allC),'whole period','']];
    break;

case 'accounting.expenses':
    $supports = [];
    $columns = ['Reference','Date','Expense account','Paid from','Payee','Department','Amount'];
    $align = [6=>1];
    $expAll    = accGetExpenses($conn, $from, $to, 0, $exportCap);  // existing helper
    $totalRows = count($expAll);
    $exp       = $isExport ? $expAll : array_slice($expAll, ($page-1)*$perPage, $perPage);
    $tA=0;
    foreach ($exp as $e) {
        $tA += (float)$e['amount'];
        $rows[] = [$e['reference_no'], date('d M Y', strtotime($e['expense_date'])),
                   $e['account_name'] ?? '—', $e['paid_from_name'] ?? '—',
                   $e['payee'] ?: '—', catalogDepartmentLabel($e['department'] ?? 'general'),
                   m($e['amount'])];
    }
    $footer = ['','','','','','Page total', m($tA)];
    $allA = array_sum(array_map(fn($e)=>(float)$e['amount'], $expAll));
    $metrics = [['Expenses', number_format($totalRows),'in this period',''],
                ['Total spent','Tsh ' . m($allA),'whole period', $allA>0?'is-warn':'']];
    break;

case 'accounting.daily_close':
    $supports = [];
    $columns = ['Date','Sales','Net sales','Cost','Gross profit','Cash','Mobile','Expected','Counted','Variance','Closed by'];
    $align = [1=>1,2=>1,3=>1,4=>1,5=>1,6=>1,7=>1,8=>1,9=>1];
    $closes = accRecentCloses($conn, 365);                  // existing helper
    $tN=0;$tV=0;$n=0;
    foreach ($closes as $c) {
        if ($c['close_date'] < $from || $c['close_date'] > $to) { continue; }
        $n++; $tN += (float)$c['net_sales']; $tV += (float)$c['variance'];
        $var = (float)$c['variance'];
        $rows[] = [date('d M Y', strtotime($c['close_date'])), (int)$c['sales_count'],
                   m($c['net_sales']), m($c['cost_of_sales']), m($c['gross_profit']),
                   m($c['cash_sales']), m($c['mobile_sales']),
                   m($c['expected_cash']), m($c['counted_cash']),
                   ['text'=>m($var), 'tone'=> abs($var) < 0.005 ? '' : ($var < 0 ? 'neg' : 'pos')],
                   $c['closed_by_name'] ?: '—'];
    }
    $footer = ['Total','', m($tN),'','','','','','', m($tV), ''];
    $metrics = [['Days closed', number_format($n),'',''],
                ['Net sales','Tsh ' . m($tN),'',''],
                ['Net variance','Tsh ' . m($tV), abs($tV) < 0.005 ? 'drawer balanced' : ($tV<0?'short overall':'over overall'),
                 abs($tV) < 0.005 ? 'is-good' : 'is-warn']];
    break;

case 'audit.cancelled_carts':
    // A cart that never became a sale - Clear Cart, the last item
    // removed, or a held sale discarded - each with the reason the
    // cashier gave. Straight off cancelled_carts; nothing recomputed.
    $supports = ['cashier'];
    $columns = ['Date & time', 'Cashier', 'Source', 'Reason', 'Items', 'Value'];
    $align = [4 => 1, 5 => 1];
    $reasonLabels = [
        'customer_changed_mind' => 'Customer changed mind',
        'wrong_items_scanned'   => 'Wrong items scanned',
        'price_dispute'         => 'Price dispute',
        'customer_left'         => 'Customer left',
        'duplicate_test_scan'   => 'Duplicate / test scan',
        'other'                 => 'Other',
    ];
    $w = 'c.created_at BETWEEN ? AND ?'; $ty = 'ss'; $pa = [$fromDT, $toDT];
    if ($fCashier > 0) { $w .= ' AND c.cashier_id = ?'; $ty .= 'i'; $pa[] = $fCashier; }
    [$rows_raw, $totalRows] = rqPaged($conn,
        "SELECT c.* FROM cancelled_carts c WHERE $w ORDER BY c.created_at DESC, c.id DESC",
        $ty, $pa, $page, $perPage, $isExport, $exportCap);
    $tV = 0.0; $tItems = 0;
    foreach ($rows_raw as $r) {
        $tV += (float)$r['total_value'];
        $tItems += (int)$r['item_count'];
        $reason = $reasonLabels[$r['reason_code']] ?? ucfirst(str_replace('_', ' ', $r['reason_code']));
        if ($r['reason_detail']) { $reason .= ' — ' . $r['reason_detail']; }
        $rows[] = [
            date('d M Y H:i', strtotime($r['created_at'])),
            $r['cashier_name'] ?: 'Unknown',
            $r['source'] === 'held_sale' ? 'Held sale' : 'Live cart',
            $reason,
            q($r['item_count']),
            m($r['total_value']),
        ];
    }
    $footer = ['Total', '', '', '', q($tItems), m($tV)];
    $metrics = [
        ['Cancellations', number_format(count($rows_raw)), '', ''],
        ['Value discarded', 'Tsh ' . m($tV), '', $tV > 0 ? 'is-bad' : ''],
    ];
    break;

default:
    $error = 'That report is not available.';
}
} catch (Throwable $e) {
    // Never expose SQL, paths or stack traces to a user.
    error_log('report ' . $group . '.' . $key . ' failed: ' . $e->getMessage());
    $error = 'This report could not be produced. The problem has been logged.';
    $rows = []; $footer = null; $metrics = [];
}

// ---- Export paths use the SAME $rows the screen would render -------
if ($error === '') {
    if ($export === 'csv')   { reportEmitCsv($def['title'], $rangeLabel, $columns, $rows, $footer, shopName($conn)); }
    if ($export === 'pdf')   { reportEmitPdf($conn, $def['title'], $rangeLabel, $columns, $rows, $footer, $align); }
    if ($export === 'print') { reportEmitPrint($conn, $def['title'], $rangeLabel, $columns, $rows, $footer, $align); }
}

// ---- Filter option data -------------------------------------------
$optCashiers = in_array('cashier', $supports, true)
    ? rq($conn, "SELECT DISTINCT cashier_id AS id, cashier_name AS name FROM sales_transactions WHERE cashier_id IS NOT NULL ORDER BY cashier_name") : [];
$optTerminals = in_array('terminal', $supports, true) ? posGetTerminals($conn) : [];
$optCategories = in_array('category', $supports, true)
    ? rq($conn, "SELECT id, name FROM inv_categories WHERE deleted_at IS NULL ORDER BY name") : [];
$optSuppliers = in_array('supplier', $supports, true)
    ? rq($conn, "SELECT id, name FROM inv_suppliers WHERE deleted_at IS NULL ORDER BY name") : [];

$pageTitle = $def['title'];
$breadcrumbs = [['Dashboard','index.php'], ['Reports','reports.php'], [$def['title']]];
$pageHead = $chart ? '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>' : '';
include 'inventory-header.php';

$qs = ['g'=>$group,'r'=>$key,'preset'=>$preset,'from'=>$from,'to'=>$to,
       'dept'=>$fDept,'cashier'=>$fCashier ?: '','terminal'=>$fTerminal ?: '',
       'method'=>$fMethod,'supplier'=>$fSupplier ?: '','category'=>$fCategory ?: '',
       'item'=>$fItem ?: '','page'=>$page > 1 ? $page : '','per'=>$perPage !== 50 ? $perPage : ''];
$qsClean = array_filter($qs, fn($v) => $v !== '' && $v !== null);

$ph = [
    'title'    => $def['title'],
    'icon'     => $def['icon'],
    'subtitle' => $def['blurb'],
    'actions'  => '<a class="ui-btn ui-btn-secondary" href="reports.php"><i class="fas fa-arrow-left"></i>All reports</a>'
                // Exports drop 'page' - a CSV or PDF is always the whole set.
                . '<a class="ui-btn ui-btn-secondary" target="_blank" rel="noopener" href="?' . htmlspecialchars(http_build_query(array_diff_key($qsClean, ['page'=>1]) + ['export'=>'print'])) . '"><i class="fas fa-print"></i>Print</a>'
                . '<a class="ui-btn ui-btn-secondary" href="?' . htmlspecialchars(http_build_query(array_diff_key($qsClean, ['page'=>1]) + ['export'=>'pdf'])) . '"><i class="fas fa-file-pdf"></i>PDF</a>'
                . '<a class="ui-btn ui-btn-primary" href="?' . htmlspecialchars(http_build_query(array_diff_key($qsClean, ['page'=>1]) + ['export'=>'csv'])) . '"><i class="fas fa-file-csv"></i>Export CSV</a>',
];
include 'partials/page-header.php';
?>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger" role="alert">
        <i class="fas fa-circle-exclamation"></i>
        <div><?php echo htmlspecialchars($error); ?></div>
    </div>
<?php else: ?>

<button type="button" class="ui-btn ui-btn-secondary rp-filter-toggle" id="filterToggle"
        aria-expanded="false" aria-controls="reportFilters">
    <i class="fas fa-filter"></i>Filters
</button>

<form method="get" class="rp-filters" id="reportFilters">
    <input type="hidden" name="g" value="<?php echo htmlspecialchars($group); ?>">
    <input type="hidden" name="r" value="<?php echo htmlspecialchars($key); ?>">

    <div class="rp-filter">
        <label for="f_preset">Period</label>
        <select class="form-select form-select-sm" name="preset" id="f_preset">
            <?php foreach (reportDatePresets() as $pk => $plabel): ?>
            <option value="<?php echo $pk; ?>" <?php echo $preset===$pk?'selected':''; ?>><?php echo htmlspecialchars($plabel); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="rp-filter">
        <label for="f_from">From</label>
        <input type="date" class="form-control form-control-sm" name="from" id="f_from" value="<?php echo htmlspecialchars($from); ?>">
    </div>
    <div class="rp-filter">
        <label for="f_to">To</label>
        <input type="date" class="form-control form-control-sm" name="to" id="f_to" value="<?php echo htmlspecialchars($to); ?>">
    </div>

    <?php if (in_array('dept', $supports, true)): ?>
    <div class="rp-filter">
        <label for="f_dept">Department</label>
        <select class="form-select form-select-sm" name="dept" id="f_dept">
            <option value="">All departments</option>
            <?php foreach (catalogDepartments() as $dk => $dmeta): ?>
            <option value="<?php echo $dk; ?>" <?php echo $fDept===$dk?'selected':''; ?>><?php echo htmlspecialchars($dmeta['label']); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>

    <?php if ($optCashiers): ?>
    <div class="rp-filter">
        <label for="f_cashier">Cashier</label>
        <select class="form-select form-select-sm" name="cashier" id="f_cashier">
            <option value="">All cashiers</option>
            <?php foreach ($optCashiers as $c): ?>
            <option value="<?php echo (int)$c['id']; ?>" <?php echo $fCashier===(int)$c['id']?'selected':''; ?>><?php echo htmlspecialchars($c['name']); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>

    <?php if ($optTerminals): ?>
    <div class="rp-filter">
        <label for="f_terminal">Terminal</label>
        <select class="form-select form-select-sm" name="terminal" id="f_terminal">
            <option value="">All terminals</option>
            <?php foreach ($optTerminals as $t): ?>
            <option value="<?php echo (int)$t['id']; ?>" <?php echo $fTerminal===(int)$t['id']?'selected':''; ?>><?php echo htmlspecialchars($t['name']); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>

    <?php if (in_array('method', $supports, true)): ?>
    <div class="rp-filter">
        <label for="f_method">Payment</label>
        <select class="form-select form-select-sm" name="method" id="f_method">
            <option value="">All methods</option>
            <?php foreach (posPaymentMethods() as $mk => $mm): ?>
            <option value="<?php echo $mk; ?>" <?php echo $fMethod===$mk?'selected':''; ?>><?php echo htmlspecialchars($mm['label']); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>

    <?php if ($optCategories): ?>
    <div class="rp-filter">
        <label for="f_category">Category</label>
        <select class="form-select form-select-sm" name="category" id="f_category">
            <option value="">All categories</option>
            <?php foreach ($optCategories as $c): ?>
            <option value="<?php echo (int)$c['id']; ?>" <?php echo $fCategory===(int)$c['id']?'selected':''; ?>><?php echo htmlspecialchars($c['name']); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>

    <?php if ($optSuppliers): ?>
    <div class="rp-filter">
        <label for="f_supplier">Supplier</label>
        <select class="form-select form-select-sm" name="supplier" id="f_supplier">
            <option value="">All suppliers</option>
            <?php foreach ($optSuppliers as $sp): ?>
            <option value="<?php echo (int)$sp['id']; ?>" <?php echo $fSupplier===(int)$sp['id']?'selected':''; ?>><?php echo htmlspecialchars($sp['name']); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>

    <div class="rp-filter-actions">
        <a class="ui-btn ui-btn-ghost ui-btn-sm" href="report.php?g=<?php echo urlencode($group); ?>&amp;r=<?php echo urlencode($key); ?>">Clear</a>
        <button type="submit" class="ui-btn ui-btn-primary ui-btn-sm"><i class="fas fa-filter"></i>Apply</button>
    </div>
</form>

<?php if ($metrics): ?>
<div class="rp-summary">
    <?php foreach ($metrics as [$label, $value, $foot, $tone]): ?>
    <div class="rp-metric <?php echo htmlspecialchars($tone); ?>">
        <div class="rp-metric-label"><?php echo htmlspecialchars($label); ?></div>
        <div class="rp-metric-value"><?php echo htmlspecialchars($value); ?></div>
        <?php if ($foot !== ''): ?><div class="rp-metric-foot"><?php echo htmlspecialchars($foot); ?></div><?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($chart && $rows): ?>
<div class="inv-card p-3 mb-3 rp-chart-wrap">
    <canvas id="reportChart" height="90" aria-label="<?php echo htmlspecialchars($def['title']); ?> chart" role="img"></canvas>
</div>
<?php endif; ?>

<div class="inv-card p-0">
    <div class="ui-table-wrap">
        <table class="rp-table">
            <thead>
                <tr><?php foreach ($columns as $i => $c): ?>
                    <th class="<?php echo isset($align[$i]) ? 'r' : ''; ?>"><?php echo htmlspecialchars($c); ?></th>
                <?php endforeach; ?></tr>
            </thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="<?php echo max(1, count($columns)); ?>">
                    <?php
                    $es = ['icon' => 'fa-chart-column',
                           'title' => 'Nothing to report for ' . $rangeLabel,
                           'msg'   => 'No matching records were found. Try a wider date range, or clear the filters.',
                           'action'=> '<a class="ui-btn ui-btn-secondary" href="report.php?g=' . urlencode($group) . '&r=' . urlencode($key) . '">Clear filters</a>'];
                    include 'partials/empty-state.php';
                    ?>
                </td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $r):
                $rowClass = '';
                foreach ($r as $cell) { if (is_array($cell) && !empty($cell['row'])) { $rowClass = 'rp-row-' . $cell['row']; break; } }
            ?>
                <tr class="<?php echo $rowClass; ?>">
                <?php foreach (array_values($r) as $i => $cell): ?>
                    <td class="<?php echo isset($align[$i]) ? 'r' : ''; ?>">
                        <?php if (is_array($cell) && !empty($cell['href'])): ?>
                            <a class="rp-drill" href="<?php echo htmlspecialchars($cell['href']); ?>"><?php echo htmlspecialchars($cell['text']); ?><i class="fas fa-arrow-right-long" aria-hidden="true"></i></a>
                        <?php elseif (is_array($cell) && !empty($cell['badge'])): ?>
                            <span class="ui-badge ui-badge-<?php echo htmlspecialchars($cell['badge']); ?>"><?php echo htmlspecialchars($cell['text']); ?></span>
                        <?php elseif (is_array($cell) && !empty($cell['tone'])): ?>
                            <span class="rp-<?php echo htmlspecialchars($cell['tone']); ?>"><?php echo htmlspecialchars($cell['text']); ?></span>
                        <?php else: ?>
                            <?php echo htmlspecialchars(reportPlainCell($cell)); ?>
                        <?php endif; ?>
                    </td>
                <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
            <?php if ($footer && $rows): ?>
            <tfoot><tr><?php foreach (array_values($footer) as $i => $cell): ?>
                <td class="<?php echo isset($align[$i]) ? 'r' : ''; ?>"><?php echo htmlspecialchars(reportPlainCell($cell)); ?></td>
            <?php endforeach; ?></tr></tfoot>
            <?php endif; ?>
        </table>
    </div>
</div>

<?php
if ($totalRows !== null && $rows) {
    $pgQs = $qsClean; unset($pgQs['page']);
    $perQs = $pgQs; unset($perQs['per']);
    echo '<div class="rp-perpage"><span>Rows per page</span>';
    foreach ([25, 50, 100, 200] as $ppOpt) {
        $cls = $ppOpt === $perPage ? 'ui-chip active' : 'ui-chip';
        echo '<a class="' . $cls . '" href="?' . htmlspecialchars(http_build_query($perQs + ['per' => $ppOpt])) . '">' . $ppOpt . '</a>';
    }
    echo '</div>';
    $pg = ['page' => $page, 'pages' => (int)max(1, ceil($totalRows / $perPage)),
           'total' => $totalRows, 'per_page' => $perPage,
           'base' => '?' . http_build_query($pgQs), 'label' => 'rows'];
    include 'partials/pagination.php';
}
?>

<p class="ui-caption mt-2">
    <?php echo $totalRows !== null ? number_format($totalRows) . ' row(s) in total' : count($rows) . ' row(s)'; ?>
    &middot; <?php echo htmlspecialchars($rangeLabel); ?>
    &middot; generated <?php echo date('d M Y H:i'); ?>.
    All figures are calculated on the server from the live records.
</p>

<?php endif; ?>

<?php
$chartJson = $chart ? json_encode($chart) : 'null';
$pageScript = <<<HTML
<script>
// Mobile filter panel.
(function () {
    var btn = document.getElementById('filterToggle');
    var box = document.getElementById('reportFilters');
    if (!btn || !box) { return; }
    btn.addEventListener('click', function () {
        var open = box.classList.toggle('is-open');
        btn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
})();

// Choosing a preset fills the two date boxes, so the range shown always
// matches the label. "Custom range" leaves them alone to be edited.
(function () {
    var sel = document.getElementById('f_preset');
    if (!sel) { return; }
    sel.addEventListener('change', function () {
        if (this.value !== 'custom') { this.form.submit(); }
    });
})();

// Chart. Purely a second view of the server-computed rows - it never
// calculates a figure of its own.
(function () {
    var spec = {$chartJson};
    if (!spec || typeof Chart === 'undefined') { return; }
    var el = document.getElementById('reportChart');
    if (!el) { return; }
    var css = getComputedStyle(document.documentElement);
    var accent = css.getPropertyValue('--color-primary').trim() || '#0f9aa8';
    var text   = css.getPropertyValue('--color-text-muted').trim() || '#5f7480';
    var grid   = css.getPropertyValue('--color-border').trim() || '#e3eaee';
    var palette = [accent, '#2f6fd0', '#7048ba', '#a5620a', '#157f47', '#c02626', '#0b7285', '#55707d'];
    new Chart(el, {
        type: spec.type,
        data: {
            labels: spec.labels,
            datasets: [{
                label: spec.label,
                data: spec.data,
                backgroundColor: spec.type === 'line' ? 'rgba(15,154,168,.14)' : palette,
                borderColor: spec.type === 'line' ? accent : 'transparent',
                borderWidth: spec.type === 'line' ? 2 : 0,
                fill: spec.type === 'line',
                tension: .3
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: true,
            plugins: {
                legend: { display: spec.type === 'doughnut', position: 'right', labels: { color: text } }
            },
            scales: spec.type === 'doughnut' ? {} : {
                x: { ticks: { color: text }, grid: { color: grid } },
                y: { ticks: { color: text }, grid: { color: grid }, beginAtZero: true }
            }
        }
    });
})();
</script>
HTML;
include 'inventory-footer.php';
?>
