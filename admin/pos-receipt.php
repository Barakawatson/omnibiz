<?php
// ============================================================
// POS receipt - 80mm thermal-printer friendly layout.
// Opens in a small window from the till and prints itself.
// Also used to reprint an old receipt from the sales list.
// ============================================================
require_once '../includes/auth.php';
requireModule('pos_sales');
date_default_timezone_set('Africa/Dar_es_Salaam');
include '../includes/db.php';
require_once '../includes/pos_functions.php';
posBoot($conn);

$txnId = (int)($_GET['id'] ?? 0);
$sale = posGetSale($conn, $txnId);
if (!$sale) {
    http_response_code(404);
    echo 'Receipt not found.';
    exit;
}
// admin/pos-sales.php's own list already scopes a non-admin/manager to
// their own sales (cashier_id = ?) - this page took an id straight from
// the query string with no equivalent check, so any cashier could view
// or print any other cashier's receipt just by editing ?id=.
$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['admin', 'manager'], true) && (int)$sale['cashier_id'] !== (int)($_SESSION['id'] ?? 0)) {
    http_response_code(403);
    echo 'You do not have permission to view this receipt.';
    exit;
}

$autoPrint = !isset($_GET['noprint']);
$footer = posReceiptFooter($conn);
function rMoney($v) { return number_format((float)$v); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receipt <?php echo htmlspecialchars($sale['receipt_no']); ?></title>
    <style>
        /* 80mm thermal roll: keep everything monospaced and narrow. */
        @page { size: 80mm auto; margin: 0; }
        * { box-sizing: border-box; }
        body {
            font-family: 'Courier New', ui-monospace, monospace;
            width: 80mm; margin: 0 auto; padding: 8px 10px 18px;
            color: #000; background: #fff; font-size: 12px; line-height: 1.45;
        }
        .center { text-align: center; }
        .right { text-align: right; }
        .bold { font-weight: 700; }
        .big { font-size: 15px; }
        .shop-name { font-size: 16px; font-weight: 700; letter-spacing: .5px; }
        .muted { color: #444; font-size: 11px; }
        hr { border: none; border-top: 1px dashed #000; margin: 7px 0; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 1px 0; vertical-align: top; }
        .qty-col { width: 34px; }
        .amt-col { width: 74px; text-align: right; white-space: nowrap; }
        .item-name { word-break: break-word; }
        .tot-row td { padding: 2px 0; }
        .grand td { font-size: 15px; font-weight: 700; border-top: 1px solid #000; padding-top: 4px; }
        .shop-logo { max-width: 48mm; max-height: 18mm; object-fit: contain; margin-bottom: 3px; }
        .voided { border: 2px solid #000; padding: 4px; text-align: center; font-weight: 700; margin: 6px 0; letter-spacing: 2px; }
        .no-print { margin-top: 14px; text-align: center; }
        .no-print button, .no-print a {
            font-family: inherit; font-size: 12px; padding: 8px 14px; margin: 0 3px;
            border: 1px solid #000; background: #fff; border-radius: 4px; cursor: pointer; text-decoration: none; color: #000;
        }
        @media print { .no-print { display: none !important; } body { padding-top: 0; } }
    </style>
</head>
<body>
    <?php
    // Business identity comes from Shop Settings. Each line prints only
    // when the administrator has both switched it on AND filled it in,
    // so a receipt never carries an empty label or a placeholder.
    // Layout, width, fonts and numbering are unchanged.
    $shopLogo = shopSettingOn($conn, 'receipt_show_logo') ? shopLogoUrl($conn, '../') : '';
    $shopAddr = shopSettingOn($conn, 'receipt_show_address') ? shopAddressLine($conn) : '';
    $shopTel  = shopSettingOn($conn, 'receipt_show_phone')   ? shopSetting($conn, 'shop_phone') : '';
    $shopMail = shopSettingOn($conn, 'receipt_show_email')   ? shopSetting($conn, 'shop_email') : '';
    $shopTin  = shopSettingOn($conn, 'receipt_show_tin')     ? shopSetting($conn, 'shop_tin') : '';
    $shopVrn  = shopSettingOn($conn, 'receipt_show_tin')     ? shopSetting($conn, 'shop_vrn') : '';
    $rHeader  = shopSetting($conn, 'receipt_header');
    $tagline  = shopSetting($conn, 'shop_tagline');
    ?>
    <div class="center">
        <?php if ($shopLogo !== ''): ?>
        <div><img src="<?php echo htmlspecialchars($shopLogo); ?>" alt="" class="shop-logo"></div>
        <?php endif; ?>
        <div class="shop-name"><?php echo htmlspecialchars(strtoupper(shopName($conn))); ?></div>
        <?php if ($tagline !== ''): ?>
        <div class="muted"><?php echo htmlspecialchars(strtoupper($tagline)); ?></div>
        <?php endif; ?>
        <?php if ($shopAddr !== ''): ?>
        <div class="muted"><?php echo htmlspecialchars($shopAddr); ?></div>
        <?php endif; ?>
        <?php if ($shopTel !== ''): ?>
        <div class="muted">Tel: <?php echo htmlspecialchars($shopTel); ?></div>
        <?php endif; ?>
        <?php if ($shopMail !== ''): ?>
        <div class="muted"><?php echo htmlspecialchars($shopMail); ?></div>
        <?php endif; ?>
        <?php if ($shopTin !== '' || $shopVrn !== ''): ?>
        <div class="muted">
            <?php echo $shopTin !== '' ? 'TIN: ' . htmlspecialchars($shopTin) : ''; ?>
            <?php echo $shopVrn !== '' ? '  VRN: ' . htmlspecialchars($shopVrn) : ''; ?>
        </div>
        <?php endif; ?>
        <?php if ($rHeader !== ''): ?>
        <div class="muted"><?php echo htmlspecialchars($rHeader); ?></div>
        <?php endif; ?>
    </div>

    <hr>

    <?php if ($sale['status'] === 'voided'): ?>
    <div class="voided">*** VOIDED ***</div>
    <?php endif; ?>

    <table>
        <tr><td>Receipt</td><td class="right bold"><?php echo htmlspecialchars($sale['receipt_no']); ?></td></tr>
        <tr><td>Date</td><td class="right"><?php echo date('d/m/Y H:i', strtotime($sale['created_at'])); ?></td></tr>
        <?php if (!empty($sale['terminal_name'])): ?>
        <tr><td>Till</td><td class="right"><?php echo htmlspecialchars($sale['terminal_name']); ?></td></tr>
        <?php endif; ?>
        <tr><td>Department</td><td class="right"><?php echo htmlspecialchars(catalogDepartmentLabel($sale['department'] ?? 'general')); ?></td></tr>
        <tr><td>Served by</td><td class="right"><?php echo htmlspecialchars($sale['cashier_name'] ?? '-'); ?></td></tr>
        <?php if ($sale['customer_type'] === 'registered' && ($sale['customer_name'] || $sale['customer_phone'])): ?>
        <tr><td>Customer</td><td class="right"><?php echo htmlspecialchars($sale['customer_name'] ?: '-'); ?></td></tr>
        <?php if ($sale['customer_phone']): ?>
        <tr><td></td><td class="right"><?php echo htmlspecialchars($sale['customer_phone']); ?></td></tr>
        <?php endif; ?>
        <?php // Institutional details - present only when this sale actually
              // captured them, so an old receipt (predating this feature,
              // or a plain walk-in) renders exactly as it did before. ?>
        <?php if (!empty($sale['customer_address'])): ?>
        <tr><td></td><td class="right"><?php echo htmlspecialchars($sale['customer_address']); ?></td></tr>
        <?php endif; ?>
        <?php if (!empty($sale['customer_tin'])): ?>
        <tr><td>TIN</td><td class="right"><?php echo htmlspecialchars($sale['customer_tin']); ?></td></tr>
        <?php endif; ?>
        <?php if (!empty($sale['customer_email'])): ?>
        <tr><td></td><td class="right"><?php echo htmlspecialchars($sale['customer_email']); ?></td></tr>
        <?php endif; ?>
        <?php else: ?>
        <tr><td>Customer</td><td class="right">Walk-in</td></tr>
        <?php endif; ?>
    </table>

    <hr>

    <table>
        <?php foreach ($sale['items'] as $it):
            $qty = (float)$it['quantity'];
            $qtyLabel = (floor($qty) == $qty) ? (string)(int)$qty : rtrim(rtrim(number_format($qty, 3), '0'), '.');
        ?>
        <tr>
            <td colspan="2" class="item-name"><?php echo htmlspecialchars($it['item_name']); ?></td>
        </tr>
        <tr>
            <td class="muted"><?php echo $qtyLabel; ?> x <?php echo rMoney($it['unit_price']); ?><?php
                if ((float)$it['line_discount'] > 0) { echo ' &minus;' . rMoney($it['line_discount']); }
            ?></td>
            <td class="amt-col"><?php echo rMoney($it['line_total']); ?></td>
        </tr>
        <?php endforeach; ?>
    </table>

    <hr>

    <table>
        <tr class="tot-row"><td>Subtotal</td><td class="amt-col"><?php echo rMoney($sale['subtotal']); ?></td></tr>
        <?php if ((float)$sale['discount'] > 0): ?>
        <tr class="tot-row"><td>Discount</td><td class="amt-col">&minus;<?php echo rMoney($sale['discount']); ?></td></tr>
        <?php endif; ?>
        <?php if ((float)$sale['tax_amount'] > 0): ?>
        <tr class="tot-row"><td>VAT (<?php echo rtrim(rtrim(number_format((float)$sale['tax_rate'], 2), '0'), '.'); ?>%)</td><td class="amt-col"><?php echo rMoney($sale['tax_amount']); ?></td></tr>
        <?php endif; ?>
        <tr class="grand"><td>TOTAL TSH</td><td class="amt-col"><?php echo rMoney($sale['total']); ?></td></tr>

        <?php
        // One line per tender, so a split payment shows exactly what was
        // taken by each method. Sales recorded before split tender have
        // no payment rows, so fall back to the header.
        $tenders = $sale['payments'] ?? [];
        if (!$tenders) {
            $tenders = [['method' => $sale['payment_method'], 'amount' => $sale['amount_paid']]];
        }
        foreach ($tenders as $tender): ?>
        <tr class="tot-row">
            <td><?php echo htmlspecialchars(posPaymentLabel($tender['method'])); ?></td>
            <td class="amt-col"><?php echo rMoney($tender['amount']); ?></td>
        </tr>
        <?php endforeach; ?>

        <?php if (count($tenders) > 1): ?>
        <tr class="tot-row"><td>Total paid</td><td class="amt-col"><?php echo rMoney($sale['amount_paid']); ?></td></tr>
        <?php endif; ?>

        <?php if ((float)$sale['change_due'] > 0): ?>
        <tr class="tot-row bold"><td>Change</td><td class="amt-col"><?php echo rMoney($sale['change_due']); ?></td></tr>
        <?php endif; ?>
    </table>

    <?php
    // Payment instructions, when switched on and actually configured.
    // shopPaymentMethods() already filters to enabled methods that have
    // a number, so nothing here can print a blank or placeholder.
    $payMethods = shopSettingOn($conn, 'receipt_show_payment') ? shopPaymentMethods($conn, true) : [];
    if ($payMethods): ?>
    <hr>
    <div class="muted">
        <div class="bold center">HOW TO PAY</div>
        <?php foreach ($payMethods as $pm): ?>
        <div style="margin-top:3px;">
            <span class="bold"><?php echo htmlspecialchars($pm['provider']); ?></span>:
            <?php echo htmlspecialchars($pm['payment_number']); ?>
            <?php if (!empty($pm['account_name'])): ?><br><?php echo htmlspecialchars($pm['account_name']); ?><?php endif; ?>
            <?php if (!empty($pm['instructions'])): ?><br><?php echo htmlspecialchars($pm['instructions']); ?><?php endif; ?>
            <?php if (!empty($pm['reference_note'])): ?><br><?php echo htmlspecialchars($pm['reference_note']); ?><?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <hr>

    <div class="center muted">
        <?php echo htmlspecialchars($footer); ?><br>
        Goods sold are checked at the counter.<br>
        <span class="bold"><?php echo htmlspecialchars($sale['receipt_no']); ?></span>
    </div>

    <div class="no-print">
        <button onclick="window.print()">Print</button>
        <a href="<?php echo htmlspecialchars(adminUrl('pos.php')); ?>">Back to POS</a>
        <a href="<?php echo htmlspecialchars(adminUrl('pos-sales.php')); ?>">Sales list</a>
    </div>

    <?php if ($autoPrint): ?>
    <script>
        // Print as soon as the layout is ready; close the popup once the
        // print dialog is dismissed (only if we were opened by the till).
        window.addEventListener('load', function () {
            setTimeout(function () {
                window.print();
                if (window.opener) {
                    setTimeout(function () { window.close(); }, 800);
                }
            }, 250);
        });
    </script>
    <?php endif; ?>
</body>
</html>
