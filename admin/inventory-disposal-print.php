<?php
// ============================================================
// Certificate of Disposal - printable record of an approved/disposed
// expired-goods write-off. Opened deliberately from the Expired Goods
// Disposal list (Print button), not auto-printed like a till receipt.
// Fixed light theme always, same reasoning as pos-receipt.php: a
// document meant to travel with physical goods, or sit in a paper
// file, must never print white-on-black.
// ============================================================
require_once '../includes/auth.php';
requireModule('inventory');
date_default_timezone_set('Africa/Dar_es_Salaam');
include '../includes/db.php';
require_once '../includes/shop_settings.php';   // shopName() / shopLogoUrl()

$requestId = (int)($_GET['id'] ?? 0);
$stmt = $conn->prepare("SELECT * FROM inv_disposal_requests WHERE id = ?");
$stmt->bind_param('i', $requestId);
$stmt->execute();
$req = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Nothing to certify before a manager/admin has actually approved it.
if (!$req || in_array($req['status'], ['pending', 'rejected'], true)) {
    http_response_code(404);
    echo 'Nothing to print for this disposal request yet.';
    exit;
}

$lineStmt = $conn->prepare(
    "SELECT l.qty_requested, l.qty_approved, l.expiry_date, i.name, i.sku, i.average_cost, u.abbreviation AS unit
     FROM inv_disposal_request_lines l
     JOIN inv_items i ON i.id = l.item_id
     LEFT JOIN inv_units u ON u.id = i.unit_id
     WHERE l.request_id = ? AND l.qty_approved > 0
     ORDER BY l.id");
$lineStmt->bind_param('i', $requestId);
$lineStmt->execute();
$lines = $lineStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$lineStmt->close();

$totalLoss = 0.0;
foreach ($lines as $l) { $totalLoss += (float)$l['qty_approved'] * (float)$l['average_cost']; }

function dpMoney($v) { return number_format((float)$v, 2); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Disposal Certificate <?php echo htmlspecialchars($req['request_no']); ?></title>
    <style>
        /* Fixed light document, regardless of the admin theme toggle -
           this is meant to be printed and filed or travel with the goods. */
        * { box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            max-width: 760px; margin: 0 auto; padding: 32px 28px 60px;
            color: #16242b; background: #fff; font-size: 14px; line-height: 1.5;
        }
        .center { text-align: center; }
        .right { text-align: right; }
        .muted { color: #5f7480; }
        h1 { font-size: 1.4rem; margin: 4px 0 2px; }
        .shop-name { font-size: 1.1rem; font-weight: 700; }
        .shop-logo { max-height: 48px; object-fit: contain; margin-bottom: 6px; }
        hr { border: none; border-top: 1px solid #dde5e9; margin: 16px 0; }
        table { width: 100%; border-collapse: collapse; font-size: .92rem; }
        th, td { padding: 8px 6px; border-bottom: 1px solid #eef1f4; text-align: left; }
        th { font-size: .72rem; text-transform: uppercase; letter-spacing: .04em; color: #5f7480; }
        td.num, th.num { text-align: right; font-variant-numeric: tabular-nums; }
        .tot-row td { font-weight: 700; border-top: 2px solid #cfdae1; border-bottom: none; }
        .meta-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 4px 24px; margin: 16px 0; font-size: .92rem; }
        .meta-grid dt { color: #5f7480; display: inline; }
        .meta-grid dd { display: inline; margin: 0; font-weight: 600; }
        .sign-block { display: grid; grid-template-columns: 1fr 1fr; gap: 40px; margin-top: 48px; }
        .sign-line { border-top: 1px solid #16242b; padding-top: 6px; font-size: .85rem; color: #5f7480; }
        .no-print { margin-top: 28px; text-align: center; }
        .no-print button, .no-print a {
            font-family: inherit; font-size: .9rem; padding: 9px 18px; margin: 0 4px;
            border: 1px solid #cfdae1; background: #fff; border-radius: 8px; cursor: pointer;
            text-decoration: none; color: #16242b;
        }
        @media print { .no-print { display: none !important; } body { padding-top: 20px; } }
    </style>
</head>
<body>
    <div class="center">
        <?php $logo = shopLogoUrl($conn, '../'); if ($logo !== ''): ?>
        <div><img src="<?php echo htmlspecialchars($logo); ?>" alt="" class="shop-logo"></div>
        <?php endif; ?>
        <div class="shop-name"><?php echo htmlspecialchars(shopName($conn)); ?></div>
        <h1>Certificate of Disposal &mdash; Expired Goods</h1>
        <div class="muted">Request <?php echo htmlspecialchars($req['request_no']); ?></div>
    </div>

    <hr>

    <dl class="meta-grid">
        <div><dt>Requested by:</dt> <dd><?php echo htmlspecialchars($req['requested_by_name'] ?: '-'); ?></dd></div>
        <div><dt>Requested on:</dt> <dd><?php echo date('d M Y', strtotime($req['created_at'])); ?></dd></div>
        <div><dt>Approved by:</dt> <dd><?php echo htmlspecialchars($req['reviewed_by_name'] ?: '-'); ?></dd></div>
        <div><dt>Approved on:</dt> <dd><?php echo $req['reviewed_at'] ? date('d M Y', strtotime($req['reviewed_at'])) : '-'; ?></dd></div>
        <?php if ($req['status'] === 'disposed'): ?>
        <div><dt>Disposed by:</dt> <dd><?php echo htmlspecialchars($req['disposed_by_name'] ?: '-'); ?></dd></div>
        <div><dt>Disposed on:</dt> <dd><?php echo date('d M Y', strtotime($req['disposed_at'])); ?></dd></div>
        <?php else: ?>
        <div><dt>Disposal status:</dt> <dd>Pending physical disposal</dd></div>
        <?php endif; ?>
    </dl>

    <?php if ($req['reason']): ?>
    <p><strong>Reason:</strong> <?php echo htmlspecialchars($req['reason']); ?></p>
    <?php endif; ?>

    <table>
        <thead>
            <tr><th>Item</th><th>Expired</th><th class="num">Qty</th><th class="num">Unit Cost</th><th class="num">Loss Value</th></tr>
        </thead>
        <tbody>
            <?php foreach ($lines as $l): $lineValue = (float)$l['qty_approved'] * (float)$l['average_cost']; ?>
            <tr>
                <td><?php echo htmlspecialchars($l['name']); ?><?php if ($l['sku']): ?> <span class="muted">(<?php echo htmlspecialchars($l['sku']); ?>)</span><?php endif; ?></td>
                <td><?php echo $l['expiry_date'] ? date('d M Y', strtotime($l['expiry_date'])) : '-'; ?></td>
                <td class="num"><?php echo rtrim(rtrim(number_format((float)$l['qty_approved'], 3), '0'), '.'); ?> <?php echo htmlspecialchars($l['unit'] ?? ''); ?></td>
                <td class="num"><?php echo dpMoney($l['average_cost']); ?></td>
                <td class="num"><?php echo dpMoney($lineValue); ?></td>
            </tr>
            <?php endforeach; ?>
            <tr class="tot-row">
                <td colspan="4">Total estimated loss</td>
                <td class="num">Tsh <?php echo dpMoney($totalLoss); ?></td>
            </tr>
        </tbody>
    </table>

    <div class="sign-block">
        <div class="sign-line">Approved by (signature)</div>
        <div class="sign-line">Disposed by (signature)</div>
    </div>

    <div class="no-print">
        <button onclick="window.print()">Print</button>
        <a href="<?php echo htmlspecialchars(adminUrl('inventory-disposal.php')); ?>">Back</a>
    </div>
</body>
</html>
