<?php
require_once '../includes/auth.php';
requireRole(['admin', 'manager']);
// Reject any POST that does not carry this session's CSRF token.
// Placed before every handler on this page, and a no-op on GET.
csrfRequire();
date_default_timezone_set('Africa/Dar_es_Salaam');
$current_page = basename($_SERVER['PHP_SELF']);
include '../includes/db.php';
require_once '../includes/inventory_functions.php';
inventoryBoot($conn);

$userId = (int)($_SESSION['id'] ?? 0);
function invFlash($type, $msg) { $_SESSION['inv_flash'] = ['type' => $type, 'msg' => $msg]; }

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    $currency = trim($_POST['currency'] ?? 'Tsh') ?: 'Tsh';
    $expiry   = (int)($_POST['expiry_alert_days'] ?? 30);
    if ($expiry < 1) { $expiry = 30; }

    // Automatic near-expiry mark-down. The percentage is capped at 90 so
    // a mistyped figure cannot price stock at nothing, and the window is
    // capped at a year so it cannot swallow the whole shelf.
    $discOn   = isset($_POST['expiry_discount_enabled']) ? '1' : '0';
    $discDays = (int)($_POST['expiry_discount_days'] ?? 30);
    $discPct  = (float)($_POST['expiry_discount_percent'] ?? 5);
    if ($discDays < 1)   { $discDays = 30; }
    if ($discDays > 365) { $discDays = 365; }
    if ($discPct < 0)    { $discPct = 0; }
    if ($discPct > 90)   { $discPct = 90; }

    setInvSetting($conn, 'currency', $currency);
    setInvSetting($conn, 'expiry_alert_days', (string)$expiry);
    setInvSetting($conn, 'expiry_discount_enabled', $discOn);
    setInvSetting($conn, 'expiry_discount_days', (string)$discDays);
    setInvSetting($conn, 'expiry_discount_percent', (string)$discPct);
    setInvSetting($conn, 'expiry_block_sales', isset($_POST['expiry_block_sales']) ? '1' : '0');
    invAudit($conn, $userId, 'update', 'settings', null, 'Inventory settings updated');
    invFlash('success', 'Settings saved.');
    header('Location: inventory-settings.php'); exit;
}

$currency = getInvSetting($conn, 'currency', 'Tsh');
$expiry   = (int)getInvSetting($conn, 'expiry_alert_days', 30);
$discOn   = (string)getInvSetting($conn, 'expiry_discount_enabled', '1') === '1';
$discDays = (int)getInvSetting($conn, 'expiry_discount_days', 30);
$discPct  = (float)getInvSetting($conn, 'expiry_discount_percent', 5);
$blockExp = (string)getInvSetting($conn, 'expiry_block_sales', '1') === '1';

$pageTitle = 'Inventory Settings';
include 'inventory-header.php';
?>

<div class="inv-page-header">
    <div>
        <h1><i class="fas fa-sliders me-2" style="color:var(--inv-primary);"></i>Inventory Settings</h1>
        <div class="subtitle">Configure how the inventory module behaves.</div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="inv-card p-4">
            <form method="post">
<?php echo csrfField(); ?>
                <input type="hidden" name="action" value="save">

                <h6 class="fw-bold mb-3"><i class="fas fa-coins me-2 text-secondary"></i>Display &amp; Alerts</h6>
                <div class="row g-3" style="max-width:520px;">
                    <div class="col-sm-6">
                        <label class="form-label">Currency label</label>
                        <input type="text" name="currency" class="form-control" value="<?php echo htmlspecialchars($currency); ?>" maxlength="10">
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label">Expiry alert (days before)</label>
                        <input type="number" min="1" name="expiry_alert_days" class="form-control" value="<?php echo $expiry; ?>">
                    </div>
                </div>

                <hr class="my-4">

                <h6 class="fw-bold mb-1"><i class="fas fa-tags me-2 text-secondary"></i>Near-expiry mark-down</h6>
                <p class="small text-muted" style="max-width:60ch;">
                    Stock approaching its expiry date is sold at a reduced price automatically, and is
                    shown to customers on the customer display between sales. The reduction is applied by
                    the till itself, so the shelf, the receipt and the books always agree.
                    Stock that has <strong>already expired</strong> is never discounted &mdash; it should be
                    written off, not sold.
                </p>

                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="expiry_block_sales"
                           name="expiry_block_sales" value="1" <?php echo $blockExp ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="expiry_block_sales">
                        <strong>Refuse to sell stock that has passed its expiry date</strong>
                        <span class="d-block text-muted" style="font-size:.82rem;">
                            The till will not ring it up, and the cashier is told to take it off the shelf.
                            Leave this on unless the shop knowingly sells past a &ldquo;best before&rdquo;
                            date &mdash; it is the only thing standing between expired goods and a customer.
                        </span>
                    </label>
                </div>

                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="expiry_discount_enabled"
                           name="expiry_discount_enabled" value="1" <?php echo $discOn ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="expiry_discount_enabled">
                        Mark down stock that is close to expiry
                    </label>
                </div>

                <div class="row g-3" style="max-width:520px;">
                    <div class="col-sm-6">
                        <label class="form-label">Start this many days before expiry</label>
                        <input type="number" min="1" max="365" name="expiry_discount_days"
                               class="form-control" value="<?php echo $discDays; ?>">
                    </div>
                    <div class="col-sm-6">
                        <label class="form-label">Reduce the price by (%)</label>
                        <input type="number" min="0" max="90" step="0.5" name="expiry_discount_percent"
                               class="form-control" value="<?php echo rtrim(rtrim(number_format($discPct, 1, '.', ''), '0'), '.'); ?>">
                    </div>
                </div>

                <div class="mt-4">
                    <button type="submit" class="btn btn-inv"><i class="fas fa-save me-1"></i> Save Settings</button>
                </div>
            </form>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="inv-card p-4">
            <h6 class="fw-bold mb-2"><i class="fas fa-circle-info me-2 text-secondary"></i>How stock changes are recorded</h6>
            <p class="small text-muted mb-2">Every change to stock &mdash; a till sale, a goods receipt, a count, a write-off &mdash; is written to <strong>Stock Movements</strong> with the quantity before and after, who made it and why.</p>
            <p class="small text-muted mb-0">The same movement also posts to the ledger, so the value of stock in the books always tracks what is on the shelf.</p>
        </div>
    </div>
</div>

<?php include 'inventory-footer.php'; ?>
