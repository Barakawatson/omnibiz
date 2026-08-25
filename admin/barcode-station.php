<?php
// ============================================================
// Barcode Inventory & Stock-In (Stock Keeper)
// Three scan modes, all driven by the same global scanner listener:
//   - Stock In   : scan an item, add the received quantity
//   - Audit      : scan an item, enter the counted quantity (adjust)
//   - Assign     : attach a barcode to an item that has none
// Every change is written through the shared audit trail.
// ============================================================
require_once '../includes/auth.php';
// 'barcode', not 'inventory' - already granted to the same three roles
// (admin, manager, storekeeper). api/products-scan.php already checks
// userCan('pos') || userCan('barcode') for this exact page's scans; that
// OR-branch was effectively dead until this page's own gate matched it.
requireModule('barcode');
// Reject any POST that does not carry this session's CSRF token.
// Placed before every handler on this page, and a no-op on GET.
csrfRequire();
date_default_timezone_set('Africa/Dar_es_Salaam');
$current_page = basename($_SERVER['PHP_SELF']);
include '../includes/db.php';
require_once '../includes/pos_functions.php';
posBoot($conn);

$userId = (int)($_SESSION['id'] ?? 0);
function bcFlash($type, $msg) { $_SESSION['inv_flash'] = ['type' => $type, 'msg' => $msg]; }

// ---------- POST (non-AJAX fallbacks / barcode assignment) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'assign_barcode') {
        $itemId  = (int)($_POST['item_id'] ?? 0);
        $barcode = trim($_POST['barcode'] ?? '');
        if ($barcode === '' && !empty($_POST['generate'])) {
            $barcode = posGenerateBarcode($conn);
        }
        [$ok, $msg] = posAssignBarcode($conn, $itemId, $barcode, $userId);
        bcFlash($ok ? 'success' : 'danger', $ok ? ($msg . ' (' . $barcode . ')') : $msg);
        header('Location: barcode-station.php?tab=assign'); exit;
    }

    if ($action === 'bulk_generate') {
        // Give every barcode-less active item an internal barcode.
        $items = $conn->query(
            "SELECT i.id FROM inv_items i
             WHERE i.deleted_at IS NULL AND i.status = 'active' AND (i.barcode IS NULL OR i.barcode = '')"
             . catalogDepartmentFilterSql($conn, 'i'))->fetch_all(MYSQLI_ASSOC);
        $done = 0;
        foreach ($items as $it) {
            [$ok] = posAssignBarcode($conn, (int)$it['id'], posGenerateBarcode($conn), $userId);
            if ($ok) { $done++; }
        }
        bcFlash('success', $done . ' item(s) received a generated barcode. Print labels from the Labels page.');
        header('Location: barcode-station.php?tab=assign'); exit;
    }
}

$tab = $_GET['tab'] ?? 'stockin';

// Items missing a barcode (for the Assign tab).
$deptFilterSql = catalogDepartmentFilterSql($conn, 'i');

$noBarcode = $conn->query(
    "SELECT i.id, i.name, i.sku, i.current_stock, u.abbreviation AS unit
     FROM inv_items i LEFT JOIN inv_units u ON u.id = i.unit_id
     WHERE i.deleted_at IS NULL AND i.status = 'active' AND (i.barcode IS NULL OR i.barcode = '')"
     . $deptFilterSql . "
     ORDER BY i.name")->fetch_all(MYSQLI_ASSOC);

$withBarcode = (int)$conn->query(
    "SELECT COUNT(*) AS c FROM inv_items i WHERE i.deleted_at IS NULL AND i.status = 'active'
       AND i.barcode IS NOT NULL AND i.barcode <> ''" . $deptFilterSql)->fetch_assoc()['c'];

// Recent stock-in movements from this station.
$recent = $conn->query(
    "SELECT m.*, i.name AS item_name, u.abbreviation AS unit
     FROM inv_stock_movements m
     JOIN inv_items i ON i.id = m.item_id
     LEFT JOIN inv_units u ON u.id = i.unit_id
     WHERE m.reference_type = 'barcode_station'
     ORDER BY m.created_at DESC LIMIT 15")->fetch_all(MYSQLI_ASSOC);

$pageTitle = 'Barcode Station';
$breadcrumbs = [['Dashboard', 'index.php'], ['Inventory', 'inventory-dashboard.php'], ['Barcode Station']];
include 'inventory-header.php';
?>
<div class="inv-page-header">
    <div>
        <h1><i class="fas fa-barcode me-2" style="color:var(--inv-primary);"></i>Barcode Inventory &amp; Stock-In</h1>
        <div class="subtitle">Scan with your USB/Bluetooth scanner anywhere on this page - no need to click into the box first.</div>
    </div>
    <a href="barcode-labels.php" class="btn btn-inv"><i class="fas fa-tags me-1"></i> Print Labels</a>
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-md-3"><div class="inv-stat-card bg-grad-teal"><div class="d-flex justify-content-between"><div><div class="label">Items with Barcode</div><div class="value"><?php echo $withBarcode; ?></div></div><i class="fas fa-barcode icon"></i></div></div></div>
    <div class="col-6 col-md-3"><div class="inv-stat-card bg-grad-amber"><div class="d-flex justify-content-between"><div><div class="label">Missing Barcode</div><div class="value"><?php echo count($noBarcode); ?></div></div><i class="fas fa-triangle-exclamation icon"></i></div></div></div>
    <div class="col-12 col-md-6">
        <div class="inv-card p-3 h-100">
            <div style="font-size:.85rem;color:var(--color-text-muted);">
                <strong><i class="fas fa-lightbulb me-1"></i>How to use:</strong>
                pick a mode below, set the quantity, then scan items one after another. Each successful scan beeps and updates
                stock immediately. Press <kbd>F2</kbd> at any time to jump back to the scan box.
            </div>
        </div>
    </div>
</div>

<!-- Mode tabs -->
<ul class="nav nav-pills mb-3" style="gap:6px;">
    <li class="nav-item"><a class="nav-link <?php echo $tab === 'stockin' ? 'active' : ''; ?>" href="?tab=stockin" style="border-radius:20px;"><i class="fas fa-arrow-down me-1"></i>Stock In</a></li>
    <li class="nav-item"><a class="nav-link <?php echo $tab === 'audit' ? 'active' : ''; ?>" href="?tab=audit" style="border-radius:20px;"><i class="fas fa-clipboard-check me-1"></i>Physical Audit</a></li>
    <li class="nav-item"><a class="nav-link <?php echo $tab === 'assign' ? 'active' : ''; ?>" href="?tab=assign" style="border-radius:20px;"><i class="fas fa-tag me-1"></i>Assign Barcodes</a></li>
</ul>

<?php if ($tab === 'assign'): ?>
<!-- ================= ASSIGN BARCODES ================= -->
<div class="inv-card p-3 mb-3">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <strong><i class="fas fa-tag me-2"></i>Items without a barcode (<?php echo count($noBarcode); ?>)</strong>
        <?php if ($noBarcode): ?>
        <form method="post" onsubmit="return confirm('Generate an internal barcode for all <?php echo count($noBarcode); ?> items?');">
            <?php echo csrfField(); ?>
            <input type="hidden" name="action" value="bulk_generate">
            <button class="btn btn-sm btn-inv"><i class="fas fa-wand-magic-sparkles me-1"></i>Generate for all</button>
        </form>
        <?php endif; ?>
    </div>
    <div class="text-muted mt-1" style="font-size:.85rem;">
        Scan a product's existing barcode into the field to attach it, or generate an internal one (MCS…) and print a sticker.
    </div>
    <div class="table-responsive mt-2">
        <table class="inv-table">
            <thead><tr><th>Item</th><th>SKU</th><th>Stock</th><th style="width:340px;">Barcode</th></tr></thead>
            <tbody>
                <?php if (!$noBarcode): ?>
                <tr><td colspan="4"><div class="empty-state"><i class="fas fa-check-circle d-block" style="color:var(--color-success);"></i>Every active item has a barcode.</div></td></tr>
                <?php endif; ?>
                <?php foreach ($noBarcode as $it): ?>
                <tr>
                    <td><strong><?php echo htmlspecialchars($it['name']); ?></strong></td>
                    <td class="text-muted"><?php echo htmlspecialchars($it['sku'] ?? '-'); ?></td>
                    <td><?php echo invQty($it['current_stock']); ?> <?php echo htmlspecialchars($it['unit'] ?? ''); ?></td>
                    <td>
                        <form method="post" class="d-flex gap-1">
<?php echo csrfField(); ?>
                            <input type="hidden" name="action" value="assign_barcode">
                            <input type="hidden" name="item_id" value="<?php echo (int)$it['id']; ?>">
                            <input type="text" name="barcode" class="form-control form-control-sm assign-input"
                                   placeholder="Scan or type barcode" style="border-radius:8px;">
                            <button class="btn btn-sm btn-inv" title="Save"><i class="fas fa-save"></i></button>
                            <button class="btn btn-sm btn-outline-secondary" name="generate" value="1" title="Generate internal barcode" style="border-radius:8px;">
                                <i class="fas fa-wand-magic-sparkles"></i>
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php else: ?>
<!-- ================= STOCK IN / AUDIT ================= -->
<?php $isAudit = ($tab === 'audit'); ?>
<div class="row g-3">
    <div class="col-lg-5">
        <div class="inv-card p-3">
            <strong>
                <i class="fas <?php echo $isAudit ? 'fa-clipboard-check' : 'fa-arrow-down'; ?> me-2"></i>
                <?php echo $isAudit ? 'Physical Audit' : 'Stock In'; ?>
            </strong>
            <div class="text-muted mt-1 mb-3" style="font-size:.85rem;">
                <?php echo $isAudit
                    ? 'Enter the quantity you actually counted, then scan the item. The system records the difference as an adjustment.'
                    : 'Set how many units you received, then scan each item. The quantity is added to stock.'; ?>
            </div>

            <label class="form-label"><?php echo $isAudit ? 'Counted quantity' : 'Quantity to add'; ?></label>
            <input type="number" step="0.001" min="0" id="qtyInput" class="form-control form-control-lg mb-2"
                   value="1" style="border-radius:10px;font-weight:700;">

            <?php if (!$isAudit): ?>
            <label class="form-label">Unit cost (optional)</label>
            <input type="number" step="0.01" min="0" id="costInput" class="form-control mb-2" placeholder="0.00" style="border-radius:10px;">
            <div class="form-text mb-2">Filling this in updates the item's weighted-average cost.</div>

            <label class="form-label">Expiry date <span class="text-muted">(optional)</span></label>
            <input type="date" id="expiryInput" class="form-control mb-2" style="border-radius:10px;">
            <div class="form-text mb-2">
                Leave blank for non-perishable goods. Filling this in dates the new
                batch and keeps this item's expiry status accurate for pricing and the till.
            </div>
            <?php endif; ?>

            <label class="form-label">Reason / note (optional)</label>
            <input type="text" id="reasonInput" class="form-control mb-3" style="border-radius:10px;"
                   placeholder="<?php echo $isAudit ? 'e.g. Monthly stock count' : 'e.g. Delivery from supplier'; ?>">

            <label class="form-label">Scan barcode</label>
            <div style="position:relative;">
                <i class="fas fa-barcode" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--inv-primary);"></i>
                <input type="text" id="scanInput" class="form-control form-control-lg" autocomplete="off" autofocus
                       placeholder="Scan or type, then Enter" style="border-radius:10px;padding-left:38px;">
            </div>
            <div class="form-text">Keep scanning - the quantity above is reused for each scan.</div>

            <div id="scanResult" class="mt-3"></div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="inv-card p-0">
            <div class="p-3" style="border-bottom:1px solid var(--color-border);"><strong><i class="fas fa-clock-rotate-left me-2"></i>This Session</strong></div>
            <div class="table-responsive">
                <table class="inv-table">
                    <thead><tr><th>Time</th><th>Item</th><th>Change</th><th>New Stock</th></tr></thead>
                    <tbody id="sessionLog">
                        <tr id="sessionEmpty"><td colspan="4"><div class="empty-state"><i class="fas fa-barcode d-block"></i>Scanned items will appear here.</div></td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="inv-card p-0 mt-3">
            <div class="p-3" style="border-bottom:1px solid var(--color-border);"><strong><i class="fas fa-history me-2"></i>Recent Barcode Movements</strong></div>
            <div class="table-responsive">
                <table class="inv-table">
                    <thead><tr><th>When</th><th>Item</th><th>Type</th><th>Qty</th><th>After</th></tr></thead>
                    <tbody>
                        <?php if (!$recent): ?><tr><td colspan="5"><div class="empty-state">No barcode movements yet.</div></td></tr><?php endif; ?>
                        <?php foreach ($recent as $m): ?>
                        <tr>
                            <td style="white-space:nowrap;"><?php echo date('d M, H:i', strtotime($m['created_at'])); ?></td>
                            <td><?php echo htmlspecialchars($m['item_name']); ?></td>
                            <td><span class="inv-badge bg-<?php echo $m['movement_type'] === 'receive' ? 'success' : 'info'; ?> text-white"><?php echo ucfirst($m['movement_type']); ?></span></td>
                            <td><?php echo ((float)$m['quantity'] > 0 ? '+' : '') . invQty($m['quantity']); ?></td>
                            <td><?php echo invQty($m['qty_after']); ?> <?php echo htmlspecialchars($m['unit'] ?? ''); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php
$mode = ($tab === 'audit') ? 'set' : 'add';
// Interpolated into the heredoc below so the stock-in fetch can send it.
$csrfToken = csrfToken();
$pageScript = <<<HTML
<script>
(function() {
    const scanInput = document.getElementById('scanInput');
    if (!scanInput) {
        // Assign tab: just focus the first barcode field for convenience.
        const first = document.querySelector('.assign-input');
        if (first) first.focus();
        return;
    }

    // This panel's own workflow fields. Unlike pos.php (which has many
    // unrelated fields - customer phone, payment amounts - that a stray
    // scan must never disturb), the normal flow HERE is "set the
    // quantity, then scan" - so focus is often left in qtyInput when the
    // operator fires the scanner. Exempting these from the "typing
    // elsewhere" guard below is what lets scanning work from them too.
    const OWN_FIELDS = ['qtyInput', 'costInput', 'expiryInput', 'reasonInput'];

    const MODE = '{$mode}';
    const CSRF_TOKEN = '{$csrfToken}';

    // ---- audio feedback -------------------------------------------------
    let audioCtx = null;
    function beep(ok) {
        try {
            audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)();
            const osc = audioCtx.createOscillator(), gain = audioCtx.createGain();
            osc.connect(gain); gain.connect(audioCtx.destination);
            if (ok) {
                osc.frequency.value = 1180;
                gain.gain.setValueAtTime(0.09, audioCtx.currentTime);
                osc.start(); osc.stop(audioCtx.currentTime + 0.08);
            } else {
                osc.type = 'square'; osc.frequency.value = 220;
                gain.gain.setValueAtTime(0.08, audioCtx.currentTime);
                osc.start(); osc.stop(audioCtx.currentTime + 0.30);
            }
        } catch (e) {}
    }

    function showResult(ok, title, detail) {
        document.getElementById('scanResult').innerHTML =
            '<div class="alert alert-' + (ok ? 'success' : 'danger') + ' mb-0" style="border-radius:10px;">' +
            '<strong><i class="fas fa-' + (ok ? 'check-circle' : 'circle-exclamation') + ' me-1"></i>' + title + '</strong>' +
            (detail ? '<div style="font-size:.85rem;">' + detail + '</div>' : '') + '</div>';
    }

    function logRow(name, delta, after) {
        const tbody = document.getElementById('sessionLog');
        document.getElementById('sessionEmpty')?.remove();
        const tr = document.createElement('tr');
        const now = new Date().toTimeString().slice(0, 5);
        tr.innerHTML = '<td>' + now + '</td><td></td>' +
            '<td><span class="inv-badge bg-success text-white">' + delta + '</span></td>' +
            '<td><strong>' + after + '</strong></td>';
        tr.children[1].textContent = name;
        tbody.insertBefore(tr, tbody.firstChild);
    }

    function focusScanner() { scanInput.focus(); scanInput.select(); }

    // ---- submit a scan --------------------------------------------------
    let busy = false;
    function submitScan(code) {
        if (busy || !code) return;
        const qty = parseFloat(document.getElementById('qtyInput').value);
        if (isNaN(qty) || (MODE === 'add' && qty <= 0) || qty < 0) {
            beep(false); showResult(false, 'Enter a valid quantity first.');
            return;
        }
        busy = true;
        const costEl = document.getElementById('costInput');
        const expiryEl = document.getElementById('expiryInput');

        fetch('api/inventory-stock-in.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
            body: JSON.stringify({
                barcode: code,
                quantity: qty,
                mode: MODE,
                unit_cost: costEl ? (parseFloat(costEl.value) || 0) : 0,
                expiry_date: expiryEl ? expiryEl.value : '',
                reason: document.getElementById('reasonInput').value
            })
        })
        .then(r => r.json())
        .then(d => {
            if (!d.ok) {
                beep(false);
                showResult(false, 'Scan failed', d.message || '');
                return;
            }
            beep(true);
            showResult(true, d.item.name, d.message);
            logRow(d.item.name, (MODE === 'add' ? '+' : '=') + qty, d.item.stock);
        })
        .catch(() => { beep(false); showResult(false, 'Network error', 'Nothing was changed.'); })
        .finally(() => { busy = false; scanInput.value = ''; focusScanner(); });
    }

    // ---- global hardware-scanner listener --------------------------------
    // Scanners emit characters far faster than a human types and finish
    // with Enter. When focus is elsewhere we buffer that burst ourselves.
    const MAX_GAP = 45;
    let buffer = '', lastKey = 0;

    document.addEventListener('keydown', function(e) {
        const el = document.activeElement;
        const editable = el && ['INPUT','TEXTAREA','SELECT'].includes(el.tagName);

        if (e.key === 'F2') { e.preventDefault(); focusScanner(); return; }
        if (editable && el.id !== 'scanInput' && !OWN_FIELDS.includes(el.id)) return;

        if (e.key === 'Enter') {
            if (el === scanInput) {
                e.preventDefault();
                const code = scanInput.value.trim();
                scanInput.value = '';
                submitScan(code);
            } else if (buffer.length >= 4) {
                e.preventDefault();
                const code = buffer; buffer = '';
                submitScan(code);
            }
            return;
        }
        if (el === scanInput) return;
        if (e.key.length === 1) {
            const now = Date.now();
            if (now - lastKey > MAX_GAP) buffer = '';
            buffer += e.key;
            lastKey = now;
        }
    });

    window.addEventListener('load', focusScanner);
    setInterval(function() { if (document.activeElement === document.body) focusScanner(); }, 2000);
})();
</script>
HTML;
include 'inventory-footer.php';
?>
