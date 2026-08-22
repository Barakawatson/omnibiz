<?php
// ============================================================
// Reporting - shared helpers
// ------------------------------------------------------------
// WHAT THIS IS NOT
// This file computes no business figures of its own. Every number a
// report shows still comes from the authoritative source it always
// came from - accProfitAndLoss(), accDayFigures(), accTrialBalance(),
// recordStockMovement()'s audit trail, sales_transactions and so on.
//
// What lives here is the plumbing every report repeats: resolving a
// date range, building the filter bar, rendering a consistent table,
// and emitting CSV and print output from THE SAME server-side rows
// the screen rendered. Nothing is recalculated in the browser.
// ============================================================

require_once __DIR__ . '/inventory_functions.php';
require_once __DIR__ . '/shop_settings.php';

/**
 * Date-range presets.
 *
 * Uses the application's own timezone (set once in db.php), so a
 * "today" here is the same "today" the till and the daily close mean.
 * Accounting period logic is untouched - this only picks two dates.
 */
function reportDatePresets(): array {
    return [
        'today'      => 'Today',
        'yesterday'  => 'Yesterday',
        'this_week'  => 'This week',
        'this_month' => 'This month',
        'last_month' => 'Last month',
        'this_year'  => 'This year',
        'custom'     => 'Custom range',
    ];
}

/**
 * Resolve the requested range to [from, to, label, preset].
 *
 * An unknown or malformed value falls back to this month rather than
 * erroring - a report should still render something sensible if a
 * bookmarked URL carries nonsense.
 */
function reportResolveRange(?string $preset, ?string $from, ?string $to): array {
    $valid = static fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
    $preset = $preset ?: 'this_month';

    switch ($preset) {
        case 'today':      $f = $t = date('Y-m-d'); break;
        case 'yesterday':  $f = $t = date('Y-m-d', strtotime('-1 day')); break;
        case 'this_week':  $f = date('Y-m-d', strtotime('monday this week')); $t = date('Y-m-d'); break;
        case 'last_month': $f = date('Y-m-01', strtotime('first day of last month'));
                           $t = date('Y-m-t', strtotime('last day of last month')); break;
        case 'this_year':  $f = date('Y-01-01'); $t = date('Y-m-d'); break;
        case 'custom':
            $f = $valid($from) ? $from : date('Y-m-01');
            $t = $valid($to)   ? $to   : date('Y-m-d');
            // An inverted range is a typo, not a query - swap it rather
            // than silently returning nothing.
            if ($f > $t) { [$f, $t] = [$t, $f]; }
            break;
        case 'this_month':
        default:           $preset = 'this_month'; $f = date('Y-m-01'); $t = date('Y-m-d'); break;
    }
    return [$f, $t, reportRangeLabel($f, $t), $preset];
}

/** "01 Aug 2026 - 13 Aug 2026", or a single date when they match. */
function reportRangeLabel(string $from, string $to): string {
    $f = date('d M Y', strtotime($from));
    $t = date('d M Y', strtotime($to));
    return $f === $t ? $f : $f . ' – ' . $t;
}

/** Every report this installation offers, grouped for the landing page. */
function reportCatalogue(): array {
    return [
        'sales' => [
            'label' => 'Sales', 'icon' => 'fa-cart-shopping', 'accent' => 'sales',
            'blurb' => 'What sold, who sold it and how it was paid for.',
            'module' => 'pos_sales',
            'reports' => [
                ['summary',       'Sales summary',      'Totals, discounts, tax and profit for a period, day by day.'],
                ['by_product',    'Sales by product',   'Units and revenue per product, best first.'],
                ['by_category',   'Sales by category',  'Which categories earn their shelf space.'],
                ['by_department', 'Sales by department','How each part of the shop performs.'],
                ['by_cashier',    'Sales by cashier',   'Transactions and takings per member of staff.'],
                ['by_terminal',   'Sales by terminal',  'Performance of each till.'],
                ['by_payment',    'Sales by payment',   'Cash against mobile money, bank and card.'],
                ['transactions',  'Transaction history','Every sale, searchable, with voids marked.'],
            ],
        ],
        'inventory' => [
            'label' => 'Inventory', 'icon' => 'fa-boxes-stacked', 'accent' => 'inventory',
            'blurb' => 'What is on the shelf, what it is worth and how it moved.',
            'module' => 'inventory',
            'reports' => [
                ['valuation',    'Stock valuation',      'Every item at weighted-average cost.'],
                ['low_stock',    'Low & out of stock',   'What needs reordering, against its own reorder level.'],
                ['movements',    'Stock movements',      'The full audit trail of every change.'],
                ['most_consumed','Most consumed',        'What leaves the shelf fastest.'],
                ['slow_moving',  'Slow-moving items',    'Stock that is not turning over.'],
                ['waste',        'Waste & shrinkage',    'Damage, expiry and loss, valued at cost.'],
                ['adjustments',  'Stock adjustments',    'Count corrections and manual changes.'],
            ],
        ],
        'purchasing' => [
            'label' => 'Purchasing', 'icon' => 'fa-truck-field', 'accent' => 'inventory',
            'blurb' => 'Orders raised, goods received and what is still owed.',
            'module' => 'purchasing',
            'reports' => [
                ['supplier_purchases', 'Supplier purchases', 'Orders and outstanding balance per supplier.'],
                ['purchase_orders',    'Purchase orders',    'Every order with its status and payment state.'],
                ['goods_received',     'Goods received',     'Deliveries booked in, with their value.'],
            ],
        ],
        'accounting' => [
            'label' => 'Accounting', 'icon' => 'fa-book', 'accent' => 'accounting',
            'blurb' => 'The books themselves. Every figure comes from the ledger.',
            'module' => 'accounting',
            'reports' => [
                ['profit_loss',    'Profit & loss',   'Revenue, cost of sales and expenses from the ledger.'],
                ['trial_balance',  'Trial balance',   'Every account, with debits and credits that must agree.'],
                ['journal',        'Journal',         'Entries in the order they were posted.'],
                ['expenses',       'Expenses',        'Money out, by account and payee.'],
                ['daily_close',    'Daily close history', 'Z-report closes, with each day\'s variance.'],
            ],
        ],
    ];
}

/** A single report's definition, or null. */
function reportFind(string $group, string $key): ?array {
    $cat = reportCatalogue();
    if (!isset($cat[$group])) { return null; }
    foreach ($cat[$group]['reports'] as $r) {
        if ($r[0] === $key) {
            return ['group' => $group, 'key' => $key, 'title' => $r[1], 'blurb' => $r[2],
                    'module' => $cat[$group]['module'], 'icon' => $cat[$group]['icon']];
        }
    }
    return null;
}

/**
 * Reports the signed-in role may open.
 *
 * Uses the EXISTING module permissions - no new permission system, and
 * no report is more visible than the screen its data already lives on.
 * A cashier has pos_sales, so they see sales reports; they have neither
 * inventory nor accounting, so financial reports never appear for them.
 */
function reportVisibleGroups(): array {
    $out = [];
    foreach (reportCatalogue() as $key => $group) {
        if (userCan($group['module'])) { $out[$key] = $group; }
    }
    return $out;
}

// ============================================================
// Output: CSV and print
// ============================================================

/**
 * Stream a report as CSV.
 *
 * $rows are the SAME arrays the screen rendered - the export is never a
 * second, differently-shaped query, and never a client-side copy of
 * what the browser happened to draw.
 */
function reportEmitCsv(string $title, string $rangeLabel, array $columns, array $rows, ?array $footer = null, string $shopName = ''): void {
    $slug = preg_replace('/[^a-z0-9]+/', '_', strtolower($title));
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . trim($slug, '_') . '_' . date('Ymd_His') . '.csv"');

    $out = fopen('php://output', 'w');
    // BOM so Excel opens UTF-8 correctly - shop names carry accents.
    fwrite($out, "\xEF\xBB\xBF");
    if ($shopName !== '') { fputcsv($out, [$shopName]); }
    fputcsv($out, [$title]);
    fputcsv($out, ['Period', $rangeLabel]);
    fputcsv($out, ['Generated', date('d M Y H:i')]);
    fputcsv($out, []);
    fputcsv($out, $columns);
    foreach ($rows as $r) { fputcsv($out, array_map('reportPlainCell', $r)); }
    if ($footer) { fputcsv($out, array_map('reportPlainCell', $footer)); }
    fclose($out);
    exit;
}

/**
 * A cell as plain text for CSV.
 *
 * Screen cells may carry a small array (value + alignment/badge); the
 * export takes the value only, never the presentation.
 */
function reportPlainCell($cell): string {
    if (is_array($cell)) { return (string)($cell['text'] ?? ''); }
    return (string)$cell;
}

/**
 * Stream a report as a PDF download.
 *
 * Same contract as the CSV and print paths: $rows are the rows the
 * screen would have rendered, produced by the same server-side query.
 * Nothing is recalculated here - this only lays them out.
 *
 * Column widths are derived from the content so a narrow "Qty" column
 * does not get the same space as a product name, and wide tables use
 * landscape automatically.
 */
function reportEmitPdf(mysqli $conn, string $title, string $rangeLabel, array $columns, array $rows, ?array $footer = null, array $align = []): void
{
    require_once __DIR__ . '/pdf_writer.php';

    $colCount  = count($columns);
    // Anything past six columns is more comfortable on its side.
    $landscape = $colCount > 6;
    $pdf = new SimplePdf($landscape);

    $shop  = shopName($conn);
    $addr  = shopAddressLine($conn);
    $phone = shopSetting($conn, 'shop_phone');
    $tin   = shopSetting($conn, 'shop_tin');
    $vrn   = shopSetting($conn, 'shop_vrn');

    $L     = $pdf->left();
    $W     = $pdf->usableWidth();
    $right = $L + $W;

    // ---- Column widths -------------------------------------------
    // Weighted by the longest cell each column actually contains, so
    // the layout suits the data rather than splitting evenly.
    $weights = [];
    foreach ($columns as $i => $c) {
        $max = strlen((string)$c);
        foreach ($rows as $r) {
            $v = reportPlainCell(array_values($r)[$i] ?? '');
            $max = max($max, strlen($v));
        }
        // Clamp: no column starves, none dominates.
        $weights[$i] = min(max($max, 6), 34);
    }
    $totalWeight = array_sum($weights) ?: 1;
    $colW = [];
    foreach ($weights as $i => $wt) { $colW[$i] = ($wt / $totalWeight) * $W; }

    $fontSize = $colCount > 9 ? 7.0 : ($colCount > 6 ? 7.5 : 8.5);
    $rowH     = $fontSize + 6.0;

    /** Header block, repeated at the top of every page. */
    $drawHeader = function (SimplePdf $p) use ($shop, $addr, $phone, $tin, $vrn, $title, $rangeLabel, $L, $right) {
        $p->text($shop, $L, $p->cursorY() + 10, 13.0, true);
        $p->advance(22);
        $meta = array_filter([$addr, $phone !== '' ? 'Tel: ' . $phone : '',
                              $tin !== '' ? 'TIN: ' . $tin : '', $vrn !== '' ? 'VRN: ' . $vrn : '']);
        if ($meta) { $p->text(implode('  |  ', $meta), $L, $p->cursorY(), 7.5, false, [0.35, 0.35, 0.35]); $p->advance(12); }

        $p->textRight($title, $right, $p->cursorY() - 34, 12.0, true);
        $p->textRight($rangeLabel, $right, $p->cursorY() - 22, 8.0, false, [0.3, 0.3, 0.3]);

        $p->line($L, $p->cursorY() + 2, $right, $p->cursorY() + 2, 1.0, [0.2, 0.2, 0.2]);
        $p->advance(14);
    };

    /** Table head, repeated on every page so a long report stays readable. */
    $drawColumns = function (SimplePdf $p) use ($columns, $colW, $align, $L, $W, $fontSize, $rowH) {
        $p->rect($L, $p->cursorY() - 3, $W, $rowH + 2, [0.93, 0.96, 0.97]);
        $x = $L;
        foreach ($columns as $i => $c) {
            $cw = $colW[$i];
            $label = $p->fit((string)$c, $cw - 6, $fontSize, true);
            if (isset($align[$i])) { $p->textRight($label, $x + $cw - 3, $p->cursorY() + $fontSize, $fontSize, true, [0.25, 0.25, 0.25]); }
            else                   { $p->text($label, $x + 3, $p->cursorY() + $fontSize, $fontSize, true, [0.25, 0.25, 0.25]); }
            $x += $cw;
        }
        $p->advance($rowH);
        $p->line($L, $p->cursorY() - 2, $L + $W, $p->cursorY() - 2, 0.6, [0.6, 0.6, 0.6]);
    };

    $drawHeader($pdf);
    $drawColumns($pdf);

    if (!$rows) {
        $pdf->text('No data for this period.', $L + 3, $pdf->cursorY() + $fontSize, $fontSize, false, [0.4, 0.4, 0.4]);
        $pdf->advance($rowH);
    }

    $zebra = false;
    foreach ($rows as $r) {
        if ($pdf->wouldOverflow($rowH)) {
            $pdf->newPage();
            $drawHeader($pdf);
            $drawColumns($pdf);
            $zebra = false;
        }
        if ($zebra) { $pdf->rect($L, $pdf->cursorY() - 3, $W, $rowH, [0.975, 0.975, 0.975]); }
        $zebra = !$zebra;

        // Financial statements mark their own section and total rows.
        $rowStyle = '';
        foreach ($r as $cell) { if (is_array($cell) && !empty($cell['row'])) { $rowStyle = $cell['row']; break; } }
        $bold = in_array($rowStyle, ['section', 'total'], true);
        if ($rowStyle === 'total') {
            $pdf->line($L, $pdf->cursorY() - 3, $L + $W, $pdf->cursorY() - 3, 0.8, [0.4, 0.4, 0.4]);
        }

        $x = $L;
        foreach (array_values($r) as $i => $cell) {
            $cw   = $colW[$i] ?? 0;
            $text = reportPlainCell($cell);
            if ($rowStyle === 'sub' && $i === 0) { $text = '   ' . $text; }
            $text = $pdf->fit($text, $cw - 6, $fontSize, $bold);
            if (isset($align[$i])) { $pdf->textRight($text, $x + $cw - 3, $pdf->cursorY() + $fontSize, $fontSize, $bold); }
            else                   { $pdf->text($text, $x + 3, $pdf->cursorY() + $fontSize, $fontSize, $bold); }
            $x += $cw;
        }
        $pdf->advance($rowH);
    }

    // ---- Totals row ----------------------------------------------
    if ($footer && $rows) {
        if ($pdf->wouldOverflow($rowH + 6)) { $pdf->newPage(); $drawHeader($pdf); $drawColumns($pdf); }
        $pdf->line($L, $pdf->cursorY() - 2, $L + $W, $pdf->cursorY() - 2, 1.2, [0.2, 0.2, 0.2]);
        $pdf->rect($L, $pdf->cursorY(), $W, $rowH, [0.94, 0.94, 0.94]);
        $x = $L;
        foreach (array_values($footer) as $i => $cell) {
            $cw   = $colW[$i] ?? 0;
            $text = $pdf->fit(reportPlainCell($cell), $cw - 6, $fontSize, true);
            if (isset($align[$i])) { $pdf->textRight($text, $x + $cw - 3, $pdf->cursorY() + $fontSize + 2, $fontSize, true); }
            else                   { $pdf->text($text, $x + 3, $pdf->cursorY() + $fontSize + 2, $fontSize, true); }
            $x += $cw;
        }
        $pdf->advance($rowH + 4);
    }

    // ---- Footer stamp ---------------------------------------------
    $pdf->advance(8);
    $stamp = 'Generated ' . date('d M Y H:i')
           . (isset($_SESSION['username']) ? ' by ' . $_SESSION['username'] : '')
           . '  |  ' . count($rows) . ' row(s)';
    $pdf->text($stamp, $L, $pdf->cursorY(), 7.0, false, [0.45, 0.45, 0.45]);
    $pdf->textRight($shop, $right, $pdf->cursorY(), 7.0, false, [0.45, 0.45, 0.45]);

    $body = $pdf->output();
    $slug = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower($title)), '_');

    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $slug . '_' . date('Ymd_His') . '.pdf"');
    header('Content-Length: ' . strlen($body));
    header('Cache-Control: private, max-age=0, must-revalidate');
    echo $body;
    exit;
}

/**
 * A clean printable page: shop identity, report title, range, the data,
 * totals and a generated stamp. No sidebar, no filters, no buttons.
 *
 * Deliberately its own minimal document rather than a print stylesheet
 * over the admin shell - and entirely separate from the 80mm POS
 * receipt, which is untouched.
 */
function reportEmitPrint(mysqli $conn, string $title, string $rangeLabel, array $columns, array $rows, ?array $footer = null, array $align = []): void {
    $shop     = shopName($conn);
    $addr     = shopAddressLine($conn);
    $phone    = shopSetting($conn, 'shop_phone');
    $tin      = shopSetting($conn, 'shop_tin');
    $logo     = shopLogoUrl($conn, '../');
    $h        = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?php echo $h($title); ?></title>
<style>
    /* Always light: this is paper, whatever theme the screen uses. */
    @page { size: A4; margin: 14mm; }
    body { font-family: Arial, Helvetica, sans-serif; color: #1a1a1a; margin: 0; padding: 18px; background: #fff; }
    .rp-head { display: flex; align-items: flex-start; gap: 14px; border-bottom: 2px solid #333; padding-bottom: 10px; margin-bottom: 14px; }
    .rp-logo { max-height: 52px; max-width: 130px; object-fit: contain; }
    .rp-shop { font-size: 15px; font-weight: bold; }
    .rp-meta { font-size: 11px; color: #555; line-height: 1.45; }
    .rp-title { font-size: 17px; font-weight: bold; margin: 0 0 2px; }
    .rp-range { font-size: 12px; color: #444; }
    .rp-right { margin-left: auto; text-align: right; }
    table { width: 100%; border-collapse: collapse; font-size: 11px; }
    th, td { border: 1px solid #bbb; padding: 5px 7px; text-align: left; vertical-align: top; }
    th { background: #eef4f6; font-weight: bold; }
    tr:nth-child(even) td { background: #fafafa; }
    td.r, th.r { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
    tfoot td { font-weight: bold; background: #f0f0f0; border-top: 2px solid #666; }
    .rp-foot { margin-top: 12px; font-size: 10px; color: #666; display: flex; justify-content: space-between; }
    .rp-actions { margin-bottom: 14px; }
    .rp-actions button { font: inherit; padding: 8px 16px; cursor: pointer; }
    @media print { .rp-actions { display: none !important; } body { padding: 0; } }
</style>
</head>
<body>
<div class="rp-actions"><button onclick="window.print()">Print / Save as PDF</button></div>

<div class="rp-head">
    <?php if ($logo !== ''): ?><img src="<?php echo $h($logo); ?>" alt="" class="rp-logo"><?php endif; ?>
    <div>
        <div class="rp-shop"><?php echo $h($shop); ?></div>
        <div class="rp-meta">
            <?php if ($addr  !== ''): ?><?php echo $h($addr); ?><br><?php endif; ?>
            <?php if ($phone !== ''): ?>Tel: <?php echo $h($phone); ?><br><?php endif; ?>
            <?php if ($tin   !== ''): ?>TIN: <?php echo $h($tin); ?><?php endif; ?>
        </div>
    </div>
    <div class="rp-right">
        <h1 class="rp-title"><?php echo $h($title); ?></h1>
        <div class="rp-range"><?php echo $h($rangeLabel); ?></div>
    </div>
</div>

<table>
    <thead><tr>
        <?php foreach ($columns as $i => $c): ?>
        <th class="<?php echo isset($align[$i]) ? 'r' : ''; ?>"><?php echo $h($c); ?></th>
        <?php endforeach; ?>
    </tr></thead>
    <tbody>
    <?php if (!$rows): ?>
        <tr><td colspan="<?php echo count($columns); ?>">No data for this period.</td></tr>
    <?php endif; ?>
    <?php foreach ($rows as $r): ?>
        <tr><?php foreach (array_values($r) as $i => $cell): ?>
            <td class="<?php echo isset($align[$i]) ? 'r' : ''; ?>"><?php echo $h(reportPlainCell($cell)); ?></td>
        <?php endforeach; ?></tr>
    <?php endforeach; ?>
    </tbody>
    <?php if ($footer): ?>
    <tfoot><tr><?php foreach (array_values($footer) as $i => $cell): ?>
        <td class="<?php echo isset($align[$i]) ? 'r' : ''; ?>"><?php echo $h(reportPlainCell($cell)); ?></td>
    <?php endforeach; ?></tr></tfoot>
    <?php endif; ?>
</table>

<div class="rp-foot">
    <span>Generated <?php echo date('d M Y H:i'); ?><?php echo isset($_SESSION['username']) ? ' by ' . $h($_SESSION['username']) : ''; ?></span>
    <span><?php echo count($rows); ?> row(s)</span>
</div>
</body>
</html><?php
    exit;
}
