<?php
// ============================================================
// Barcode Label Printing
// Generates printable Code128-B sticker sheets for items, rendered
// client-side as SVG (no external library or internet needed).
// Choose items, label size and copies, then print to a normal A4
// sticker sheet or a dedicated label printer.
// ============================================================
require_once '../includes/auth.php';
requireModule('inventory');
date_default_timezone_set('Africa/Dar_es_Salaam');
$current_page = 'barcode-station.php'; // keep the sidebar on Barcode Station
include '../includes/db.php';
require_once '../includes/pos_functions.php';
posBoot($conn);

$items = $conn->query(
    "SELECT i.id, i.name, i.sku, i.barcode, i.current_stock, c.name AS category_name,
            d.selling_price, d.promo_price, d.promo_active
     FROM inv_items i
     LEFT JOIN inv_categories c ON c.id = i.category_id
     LEFT JOIN retail_product_details d ON d.item_id = i.id
     WHERE i.deleted_at IS NULL AND i.status = 'active' AND i.barcode IS NOT NULL AND i.barcode <> ''"
     . catalogDepartmentFilterSql($conn, 'i') . "
     ORDER BY i.name")->fetch_all(MYSQLI_ASSOC);

$missing = (int)$conn->query(
    "SELECT COUNT(*) AS c FROM inv_items i WHERE i.deleted_at IS NULL AND i.status = 'active'
       AND (i.barcode IS NULL OR i.barcode = '')" . catalogDepartmentFilterSql($conn, 'i'))->fetch_assoc()['c'];

$pageTitle = 'Barcode Labels';
$breadcrumbs = [['Dashboard', 'index.php'], ['Inventory', 'inventory-dashboard.php'], ['Barcode Station', 'barcode-station.php'], ['Labels']];
include 'inventory-header.php';
?>
<div class="inv-page-header no-print">
    <div>
        <h1><i class="fas fa-tags me-2" style="color:var(--inv-primary);"></i>Barcode Labels</h1>
        <div class="subtitle">Select items, then print Code128 stickers. Labels are drawn as crisp vector SVG, so they scan reliably at any size.</div>
    </div>
    <a href="barcode-station.php" class="btn btn-outline-secondary" style="border-radius:10px;"><i class="fas fa-arrow-left me-1"></i>Barcode Station</a>
</div>

<?php if ($missing > 0): ?>
<div class="alert alert-warning no-print" style="border-radius:10px;">
    <i class="fas fa-triangle-exclamation me-2"></i>
    <?php echo $missing; ?> active item(s) have no barcode yet.
    <a href="barcode-station.php?tab=assign">Assign or generate barcodes</a> so they can be scanned and labelled.
</div>
<?php endif; ?>

<div class="inv-card p-3 mb-3 no-print">
    <div class="row g-3 align-items-end">
        <div class="col-md-3">
            <label class="form-label">Label size</label>
            <select id="labelSize" class="form-select" style="border-radius:10px;">
                <option value="small">Small (38 x 22 mm)</option>
                <option value="medium" selected>Medium (50 x 30 mm)</option>
                <option value="large">Large (70 x 40 mm)</option>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">Copies each</label>
            <input type="number" id="copies" class="form-control" value="1" min="1" max="60" style="border-radius:10px;">
        </div>
        <div class="col-md-4">
            <label class="form-label">Show on label</label>
            <div class="d-flex gap-3 pt-1">
                <div class="form-check"><input class="form-check-input" type="checkbox" id="showName" checked><label class="form-check-label" for="showName">Name</label></div>
                <div class="form-check"><input class="form-check-input" type="checkbox" id="showPrice" checked><label class="form-check-label" for="showPrice">Price</label></div>
                <div class="form-check"><input class="form-check-input" type="checkbox" id="showCode" checked><label class="form-check-label" for="showCode">Number</label></div>
            </div>
        </div>
        <div class="col-md-3 text-md-end">
            <button class="btn btn-inv w-100" id="printBtn"><i class="fas fa-print me-1"></i>Print Selected</button>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-5 no-print">
        <div class="inv-card p-0">
            <div class="p-3 d-flex justify-content-between align-items-center" style="border-bottom:1px solid var(--color-border);">
                <strong><i class="fas fa-list me-2"></i>Items (<?php echo count($items); ?>)</strong>
                <div>
                    <button class="btn btn-sm btn-outline-secondary" style="border-radius:8px;" id="selectAll">All</button>
                    <button class="btn btn-sm btn-outline-secondary" style="border-radius:8px;" id="selectNone">None</button>
                </div>
            </div>
            <div class="p-2">
                <input type="text" id="itemSearch" class="form-control form-control-sm mb-2" placeholder="Search items…" style="border-radius:8px;">
            </div>
            <div style="max-height:520px;overflow-y:auto;">
                <table class="inv-table">
                    <tbody>
                    <?php if (!$items): ?>
                        <tr><td><div class="empty-state"><i class="fas fa-barcode d-block"></i>No items have barcodes yet.</div></td></tr>
                    <?php endif; ?>
                    <?php foreach ($items as $it):
                        $price = (!empty($it['promo_active']) && $it['promo_price'] !== null && (float)$it['promo_price'] > 0)
                            ? (float)$it['promo_price'] : (float)($it['selling_price'] ?? 0);
                    ?>
                        <tr class="item-row" data-search="<?php echo htmlspecialchars(strtolower($it['name'] . ' ' . $it['sku'] . ' ' . $it['barcode'])); ?>">
                            <td>
                                <div class="form-check">
                                    <input class="form-check-input item-check" type="checkbox"
                                           id="chk<?php echo (int)$it['id']; ?>"
                                           data-name="<?php echo htmlspecialchars($it['name']); ?>"
                                           data-code="<?php echo htmlspecialchars($it['barcode']); ?>"
                                           data-price="<?php echo $price; ?>">
                                    <label class="form-check-label" for="chk<?php echo (int)$it['id']; ?>">
                                        <strong><?php echo htmlspecialchars($it['name']); ?></strong>
                                        <div class="text-muted" style="font-size:.75rem;">
                                            <?php echo htmlspecialchars($it['barcode']); ?>
                                            <?php if ($price > 0): ?>&middot; Tsh <?php echo number_format($price); ?><?php endif; ?>
                                        </div>
                                    </label>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="inv-card p-3" id="previewCard">
            <strong class="no-print"><i class="fas fa-eye me-2"></i>Label Preview</strong>
            <div id="labelSheet" class="mt-2" style="display:flex;flex-wrap:wrap;gap:4mm;">
                <div class="text-muted no-print" style="padding:40px 10px;text-align:center;width:100%;">
                    Select items on the left to preview their labels.
                </div>
            </div>
        </div>
    </div>
</div>

<style>
    .label-card { border:1px dashed #c8d4d9; padding:2mm; display:flex; flex-direction:column; align-items:center; justify-content:center; background:#fff; page-break-inside:avoid; break-inside:avoid; }
    .label-name { font-size:2.6mm; font-weight:600; text-align:center; line-height:1.15; max-width:100%; overflow:hidden; }
    .label-price { font-size:3.4mm; font-weight:700; }
    .label-code { font-family:'Courier New',monospace; font-size:2.4mm; letter-spacing:.4mm; }
    @media print {
        @page { margin: 6mm; }
        body { background:#fff !important; }
        .no-print, .sidebar, .toggle-btn, .toggle-btn-mobile, .overlay, .inv-page-header, .breadcrumb, nav, .alert { display:none !important; }
        .content { margin:0 !important; padding:0 !important; }
        .container-fluid { padding:0 !important; }
        .inv-card { box-shadow:none !important; border:none !important; padding:0 !important; }
        .col-lg-7 { width:100% !important; max-width:100% !important; flex:0 0 100% !important; }
        .label-card { border:1px dotted #999; }
    }
</style>

<?php
$pageScript = <<<'HTML'
<script>
// =====================================================================
// Code128-B encoder -> SVG. Self-contained (no external library), which
// keeps label printing working offline on the shop floor.
// =====================================================================
const CODE128_PATTERNS = [
    "212222","222122","222221","121223","121322","131222","122213","122312","132212","221213",
    "221312","231212","112232","122132","122231","113222","123122","123221","223211","221132",
    "221231","213212","223112","312131","311222","321122","321221","312212","322112","322211",
    "212123","212321","232121","111323","131123","131321","112313","132113","132311","211313",
    "231113","231311","112133","112331","132131","113123","113321","133121","313121","211331",
    "231131","213113","213311","213131","311123","311321","331121","312113","312311","332111",
    "314111","221411","431111","111224","111422","121124","121421","141122","141221","112214",
    "112412","122114","122411","142112","142211","241211","221114","413111","241112","134111",
    "111242","121142","121241","114212","124112","124211","411212","421112","421211","212141",
    "214121","412121","111143","111341","131141","114113","114311","411113","411311","113141",
    "114131","311141","411131","211412","211214","211232","2331112"
];
const CODE128_START_B = 104, CODE128_STOP = 106;

function code128bValues(text) {
    const values = [CODE128_START_B];
    let checksum = CODE128_START_B;
    for (let i = 0; i < text.length; i++) {
        const code = text.charCodeAt(i);
        if (code < 32 || code > 126) continue;   // Code128-B printable range
        const v = code - 32;
        values.push(v);
        checksum += v * (i + 1);
    }
    values.push(checksum % 103);
    values.push(CODE128_STOP);
    return values;
}

function code128Svg(text, widthMm, heightMm) {
    const values = code128bValues(text);
    // Build the bar/space run-length string.
    let runs = '';
    values.forEach(v => { runs += CODE128_PATTERNS[v]; });

    const totalUnits = runs.split('').reduce((s, c) => s + parseInt(c), 0);
    const unit = widthMm / totalUnits;

    let x = 0, bars = '', isBar = true;
    for (const ch of runs) {
        const w = parseInt(ch) * unit;
        if (isBar) {
            bars += '<rect x="' + x.toFixed(3) + '" y="0" width="' + w.toFixed(3) + '" height="' + heightMm + '" fill="#000"/>';
        }
        x += w;
        isBar = !isBar;
    }
    return '<svg xmlns="http://www.w3.org/2000/svg" width="' + widthMm + 'mm" height="' + heightMm + 'mm" ' +
           'viewBox="0 0 ' + widthMm + ' ' + heightMm + '" preserveAspectRatio="none">' + bars + '</svg>';
}

// ---------------------------------------------------------------------
// Preview / print sheet
// ---------------------------------------------------------------------
const SIZES = {
    small:  { w: 38, h: 22, barH: 9,  name: 2.2, price: 2.8, code: 2.0 },
    medium: { w: 50, h: 30, barH: 12, name: 2.6, price: 3.4, code: 2.4 },
    large:  { w: 70, h: 40, barH: 17, name: 3.2, price: 4.2, code: 2.8 }
};

function renderSheet() {
    const sheet = document.getElementById('labelSheet');
    const size = SIZES[document.getElementById('labelSize').value];
    const copies = Math.max(1, Math.min(60, parseInt(document.getElementById('copies').value) || 1));
    const showName = document.getElementById('showName').checked;
    const showPrice = document.getElementById('showPrice').checked;
    const showCode = document.getElementById('showCode').checked;

    const selected = Array.from(document.querySelectorAll('.item-check:checked'));
    if (!selected.length) {
        sheet.innerHTML = '<div class="text-muted no-print" style="padding:40px 10px;text-align:center;width:100%;">Select items on the left to preview their labels.</div>';
        return;
    }

    let html = '';
    selected.forEach(chk => {
        const name = chk.dataset.name;
        const code = chk.dataset.code;
        const price = parseFloat(chk.dataset.price) || 0;
        for (let c = 0; c < copies; c++) {
            html += '<div class="label-card" style="width:' + size.w + 'mm;height:' + size.h + 'mm;">' +
                (showName ? '<div class="label-name" style="font-size:' + size.name + 'mm;">' + escapeHtml(name) + '</div>' : '') +
                (showPrice && price > 0 ? '<div class="label-price" style="font-size:' + size.price + 'mm;">Tsh ' + Math.round(price).toLocaleString() + '</div>' : '') +
                code128Svg(code, size.w - 6, size.barH) +
                (showCode ? '<div class="label-code" style="font-size:' + size.code + 'mm;">' + escapeHtml(code) + '</div>' : '') +
                '</div>';
        }
    });
    sheet.innerHTML = html;
}

function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, c => ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c]));
}

document.querySelectorAll('.item-check').forEach(c => c.addEventListener('change', renderSheet));
['labelSize','copies','showName','showPrice','showCode'].forEach(id =>
    document.getElementById(id).addEventListener('change', renderSheet));
document.getElementById('copies').addEventListener('input', renderSheet);

document.getElementById('selectAll').addEventListener('click', () => {
    document.querySelectorAll('.item-row').forEach(r => {
        if (r.style.display !== 'none') r.querySelector('.item-check').checked = true;
    });
    renderSheet();
});
document.getElementById('selectNone').addEventListener('click', () => {
    document.querySelectorAll('.item-check').forEach(c => c.checked = false);
    renderSheet();
});

document.getElementById('itemSearch').addEventListener('input', function() {
    const q = this.value.trim().toLowerCase();
    document.querySelectorAll('.item-row').forEach(r => {
        r.style.display = (q === '' || r.dataset.search.includes(q)) ? '' : 'none';
    });
});

document.getElementById('printBtn').addEventListener('click', () => {
    if (!document.querySelectorAll('.item-check:checked').length) {
        alert('Select at least one item to print.');
        return;
    }
    window.print();
});

renderSheet();
</script>
HTML;
include 'inventory-footer.php';
?>
