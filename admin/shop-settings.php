<?php
// ============================================================
// Shop Settings - business identity, receipt options, payments
// ------------------------------------------------------------
// Administrator-only. This is what makes the system reusable for a
// different business without editing PHP: everything the shop is
// called, where it is, how to reach it, what prints on a receipt
// and how customers may pay is configured here.
//
// Three tabs rather than one long form, because these are three
// different jobs done at different times.
//
// Nothing here touches sales, stock or accounting.
// ============================================================
require_once '../includes/auth.php';
requireModule('shop_settings');
csrfRequire();

$current_page = basename($_SERVER['PHP_SELF']);
include '../includes/db.php';
require_once '../includes/inventory_functions.php';
require_once '../includes/shop_settings.php';
require_once '../includes/uploads.php';
inventoryBoot($conn);

$userId   = (int)($_SESSION['id'] ?? 0);
$logoDir  = __DIR__ . '/../assets/uploads/shop_logo';
$tab      = in_array($_GET['tab'] ?? '', ['shop','payments','receipt'], true) ? $_GET['tab'] : 'shop';

function shopFlash(string $type, string $msg): void {
    $_SESSION['inv_flash'] = ['type' => $type, 'msg' => $msg];
}

// ------------------------------------------------------------------
// POST
// ------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ---- Shop information -----------------------------------------
    if ($action === 'save_shop') {
        $fields = ['shop_name','shop_tagline','shop_trading_name','shop_registered_name',
                   'shop_description','shop_tin','shop_vrn','shop_address','shop_street',
                   'shop_city','shop_region','shop_country','shop_phone','shop_phone_alt',
                   'shop_email','shop_whatsapp','shop_website'];

        $email = trim((string)($_POST['shop_email'] ?? ''));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            shopFlash('danger', 'That email address is not valid, so nothing was saved.');
            header('Location: shop-settings.php?tab=shop'); exit;
        }
        if (trim((string)($_POST['shop_name'] ?? '')) === '') {
            shopFlash('danger', 'The shop name cannot be empty - it appears on every receipt.');
            header('Location: shop-settings.php?tab=shop'); exit;
        }

        foreach ($fields as $f) {
            shopSettingSave($conn, $f, trim((string)($_POST[$f] ?? '')));
        }

        // ---- Logo ---------------------------------------------------
        if (!empty($_POST['remove_logo'])) {
            $old = shopSetting($conn, 'shop_logo');
            if ($old !== '') { uploadDeleteFile($logoDir, $old); }
            shopSettingSave($conn, 'shop_logo', '');
        } elseif (isset($_FILES['logo']) && ($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            // Same validated pipeline as every other upload: content is
            // sniffed, size capped, filename generated here.
            [$ok, $res] = uploadStoreImage($_FILES['logo'], $logoDir, 'logo');
            if (!$ok) {
                shopFlash('danger', 'Shop details saved, but the logo was not: ' . $res);
                invAudit($conn, $userId, 'shop_settings_update', 'settings', null, 'Shop information updated');
                header('Location: shop-settings.php?tab=shop'); exit;
            }
            $old = shopSetting($conn, 'shop_logo');
            shopSettingSave($conn, 'shop_logo', $res);
            if ($old !== '' && $old !== $res) { uploadDeleteFile($logoDir, $old); }
        }

        invAudit($conn, $userId, 'shop_settings_update', 'settings', null, 'Shop information updated');
        shopFlash('success', 'Shop information saved.');
        header('Location: shop-settings.php?tab=shop'); exit;
    }

    // ---- Receipt options -------------------------------------------
    if ($action === 'save_receipt') {
        shopSettingSave($conn, 'receipt_header', trim((string)($_POST['receipt_header'] ?? '')));
        setInvSetting($conn, 'pos_receipt_footer', trim((string)($_POST['pos_receipt_footer'] ?? '')));
        foreach (shopSettingToggles() as $t) {
            shopSettingSave($conn, $t, isset($_POST[$t]) ? '1' : '0');
        }
        invAudit($conn, $userId, 'shop_settings_update', 'settings', null, 'Receipt options updated');
        shopFlash('success', 'Receipt options saved.');
        header('Location: shop-settings.php?tab=receipt'); exit;
    }

    // ---- Payment methods -------------------------------------------
    if ($action === 'save_payment') {
        $id       = (int)($_POST['id'] ?? 0);
        $provider = trim((string)($_POST['provider'] ?? ''));
        $type     = isset(shopPaymentTypes()[$_POST['payment_type'] ?? '']) ? $_POST['payment_type'] : 'other';
        $number   = trim((string)($_POST['payment_number'] ?? ''));
        $account  = trim((string)($_POST['account_name'] ?? ''));
        $instr    = trim((string)($_POST['instructions'] ?? ''));
        $ref      = trim((string)($_POST['reference_note'] ?? ''));
        $enabled  = isset($_POST['is_enabled']) ? 1 : 0;
        $order    = (int)($_POST['sort_order'] ?? 0);

        if ($provider === '') {
            shopFlash('danger', 'Enter the provider name.');
        } else {
            if ($id > 0) {
                $stmt = $conn->prepare("UPDATE shop_payment_methods
                    SET provider=?, payment_type=?, payment_number=?, account_name=?,
                        instructions=?, reference_note=?, is_enabled=?, sort_order=?
                    WHERE id=?");
                $stmt->bind_param('ssssssiii', $provider, $type, $number, $account,
                                  $instr, $ref, $enabled, $order, $id);
                $ok = $stmt->execute(); $stmt->close();
                invAudit($conn, $userId, 'payment_method_update', 'payment_method', $id, $provider);
                shopFlash($ok ? 'success' : 'danger', $ok ? '"' . $provider . '" updated.' : 'Could not save.');
            } else {
                $stmt = $conn->prepare("INSERT INTO shop_payment_methods
                    (provider, payment_type, payment_number, account_name, instructions, reference_note, is_enabled, sort_order)
                    VALUES (?,?,?,?,?,?,?,?)");
                $stmt->bind_param('ssssssii', $provider, $type, $number, $account,
                                  $instr, $ref, $enabled, $order);
                $ok = $stmt->execute();
                $newId = (int)$conn->insert_id;
                $stmt->close();
                invAudit($conn, $userId, 'payment_method_create', 'payment_method', $newId, $provider);
                shopFlash($ok ? 'success' : 'danger', $ok ? '"' . $provider . '" added.' : 'Could not save.');
            }
        }
        header('Location: shop-settings.php?tab=payments'); exit;
    }

    if ($action === 'delete_payment') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $conn->prepare("SELECT provider FROM shop_payment_methods WHERE id = ?");
        $stmt->bind_param('i', $id); $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc(); $stmt->close();

        if ($row) {
            // Payment methods carry no history of their own - a sale
            // records its tender in sales_payments, not here - so this
            // is a genuine delete rather than a deactivate.
            $del = $conn->prepare("DELETE FROM shop_payment_methods WHERE id = ?");
            $del->bind_param('i', $id); $del->execute(); $del->close();
            invAudit($conn, $userId, 'payment_method_delete', 'payment_method', $id, $row['provider']);
            shopFlash('success', '"' . $row['provider'] . '" removed.');
        }
        header('Location: shop-settings.php?tab=payments'); exit;
    }

    if ($action === 'toggle_payment') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $conn->prepare("UPDATE shop_payment_methods SET is_enabled = 1 - is_enabled WHERE id = ?");
        $stmt->bind_param('i', $id); $stmt->execute(); $stmt->close();
        invAudit($conn, $userId, 'payment_method_toggle', 'payment_method', $id, '');
        shopFlash('success', 'Payment method updated.');
        header('Location: shop-settings.php?tab=payments'); exit;
    }
}

// ------------------------------------------------------------------
// Read
// ------------------------------------------------------------------
$s        = shopSettingsAll($conn, true);
$payments = shopPaymentMethods($conn, false);
$logoUrl  = shopLogoUrl($conn, '../');
$footer   = (string)getInvSetting($conn, 'pos_receipt_footer', 'Thank you for shopping with us!');

$pageTitle = 'Shop Settings';
$breadcrumbs = [['Dashboard', 'index.php'], ['Administration'], ['Shop Settings']];
include 'inventory-header.php';

$ph = [
    'title'    => 'Shop Settings',
    'icon'     => 'fa-store',
    'subtitle' => 'Your business details, receipt layout and how customers may pay. Used across the till, receipts and reports.',
];
include 'partials/page-header.php';

/** Small helper so every field renders the same way. */
function fld(string $name, string $label, array $s, string $help = '', string $type = 'text', int $max = 150): void {
    $v = htmlspecialchars((string)($s[$name] ?? ''), ENT_QUOTES);
    echo '<div class="col-md-6">'
       . '<label class="form-label" for="' . $name . '">' . htmlspecialchars($label) . '</label>'
       . '<input type="' . $type . '" class="form-control" id="' . $name . '" name="' . $name . '"'
       . ' value="' . $v . '" maxlength="' . $max . '">'
       . ($help !== '' ? '<div class="form-text">' . htmlspecialchars($help) . '</div>' : '')
       . '</div>';
}
?>

<div class="ui-toolbar" role="tablist" aria-label="Settings sections">
    <a href="?tab=shop"     class="ui-chip <?php echo $tab==='shop'?'active':''; ?>" role="tab" aria-selected="<?php echo $tab==='shop'?'true':'false'; ?>"><i class="fas fa-store me-1"></i>Shop information</a>
    <a href="?tab=payments" class="ui-chip <?php echo $tab==='payments'?'active':''; ?>" role="tab" aria-selected="<?php echo $tab==='payments'?'true':'false'; ?>"><i class="fas fa-money-bill-transfer me-1"></i>Payment methods</a>
    <a href="?tab=receipt"  class="ui-chip <?php echo $tab==='receipt'?'active':''; ?>" role="tab" aria-selected="<?php echo $tab==='receipt'?'true':'false'; ?>"><i class="fas fa-receipt me-1"></i>Receipt</a>
</div>

<?php if ($tab === 'shop'): ?>
<!-- ============ Shop information ============ -->
<form method="post" enctype="multipart/form-data" data-ui-loading>
    <?php echo csrfField(); ?>
    <input type="hidden" name="action" value="save_shop">

    <div class="inv-card p-4 mb-3">
        <h2 class="ui-section-title mb-1">Identity</h2>
        <p class="ui-muted mb-3">How the business is named on screens, receipts and reports.</p>
        <div class="row g-3">
            <?php
            fld('shop_name', 'Shop name *', $s, 'Shown on the till, the sidebar and every receipt.');
            fld('shop_tagline', 'Tagline', $s, 'A short line under the name, e.g. what you sell.');
            fld('shop_trading_name', 'Trading name', $s, 'If you trade under a different name from the registered one.');
            fld('shop_registered_name', 'Registered business name', $s, 'The legal name, if different.');
            ?>
            <div class="col-12">
                <label class="form-label" for="shop_description">Business description</label>
                <textarea class="form-control" id="shop_description" name="shop_description" rows="2" maxlength="500"><?php echo htmlspecialchars((string)$s['shop_description']); ?></textarea>
                <div class="form-text">Optional. Used on printed reports where there is room.</div>
            </div>
        </div>
    </div>

    <div class="inv-card p-4 mb-3">
        <h2 class="ui-section-title mb-1">Logo</h2>
        <p class="ui-muted mb-3">JPEG, PNG or WebP, up to 3&nbsp;MB. Shown on the sign-in screen, the till and — if you switch it on — receipts.</p>
        <div class="d-flex align-items-center gap-4 flex-wrap">
            <div class="ui-thumb" style="width:96px;height:96px;display:flex;align-items:center;justify-content:center;">
                <?php if ($logoUrl): ?>
                    <img src="<?php echo htmlspecialchars($logoUrl); ?>" alt="Current shop logo" style="max-width:88px;max-height:88px;object-fit:contain;">
                <?php else: ?>
                    <i class="fas fa-image ui-muted" aria-hidden="true"></i>
                <?php endif; ?>
            </div>
            <div style="flex:1;min-width:240px;">
                <label class="form-label" for="logo">Upload a new logo</label>
                <input type="file" class="form-control" id="logo" name="logo" accept="image/jpeg,image/png,image/webp">
                <?php if ($logoUrl): ?>
                <div class="form-check mt-2">
                    <input class="form-check-input" type="checkbox" id="remove_logo" name="remove_logo" value="1">
                    <label class="form-check-label" for="remove_logo">Remove the current logo</label>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="inv-card p-4 mb-3">
        <h2 class="ui-section-title mb-1">Statutory details</h2>
        <p class="ui-muted mb-3">Leave blank if they do not apply. Blank fields are never printed.</p>
        <div class="row g-3">
            <?php
            fld('shop_tin', 'TIN', $s, 'Taxpayer Identification Number.', 'text', 40);
            fld('shop_vrn', 'VRN', $s, 'VAT Registration Number, if registered.', 'text', 40);
            ?>
        </div>
    </div>

    <div class="inv-card p-4 mb-3">
        <h2 class="ui-section-title mb-1">Address</h2>
        <p class="ui-muted mb-3">The parts you fill in are joined into one line on receipts. Anything left blank is skipped.</p>
        <div class="row g-3">
            <?php
            fld('shop_street', 'Street / location', $s);
            fld('shop_city', 'City / town', $s);
            fld('shop_region', 'Region', $s);
            fld('shop_country', 'Country', $s);
            ?>
            <div class="col-12">
                <label class="form-label" for="shop_address">Address (single line)</label>
                <input type="text" class="form-control" id="shop_address" name="shop_address" value="<?php echo htmlspecialchars((string)$s['shop_address'], ENT_QUOTES); ?>" maxlength="255">
                <div class="form-text">Used only when the parts above are all empty.</div>
            </div>
        </div>
    </div>

    <div class="inv-card p-4 mb-3">
        <h2 class="ui-section-title mb-1">Contact</h2>
        <div class="row g-3">
            <?php
            fld('shop_phone', 'Primary phone', $s, 'Printed on receipts when enabled.', 'text', 40);
            fld('shop_phone_alt', 'Alternative phone', $s, '', 'text', 40);
            fld('shop_email', 'Email', $s, '', 'email', 120);
            fld('shop_whatsapp', 'WhatsApp number', $s, 'Digits with country code, e.g. 2557XXXXXXXX.', 'text', 40);
            fld('shop_website', 'Website', $s, '', 'text', 150);
            ?>
        </div>
    </div>

    <div class="d-flex gap-2">
        <button type="submit" class="ui-btn ui-btn-primary"><i class="fas fa-check"></i>Save shop information</button>
    </div>
</form>

<?php elseif ($tab === 'payments'): ?>
<!-- ============ Payment methods ============ -->
<div class="inv-card p-4 mb-3">
    <p class="ui-muted mb-0">
        These are the payment instructions customers are shown. Only methods that are
        <strong>enabled</strong> and have a <strong>number</strong> ever appear on the till or a receipt,
        so a half-finished entry can never print a wrong number.
        This is display only &mdash; the system does not connect to any payment provider.
    </p>
</div>

<div class="ui-toolbar">
    <button type="button" class="ui-btn ui-btn-primary" data-bs-toggle="modal" data-bs-target="#payModal" onclick="newPayment()">
        <i class="fas fa-plus"></i>Add payment method
    </button>
    <span class="ui-toolbar-spacer"></span>
    <span class="ui-result-count"><strong><?php echo count($payments); ?></strong> configured</span>
</div>

<div class="inv-card p-0">
    <div class="ui-table-wrap">
        <table class="inv-table">
            <thead>
                <tr>
                    <th style="width:64px;">Order</th>
                    <th>Provider</th>
                    <th class="ui-col-optional">Type</th>
                    <th>Number</th>
                    <th class="ui-col-optional">Account name</th>
                    <th class="ui-col-secondary">Instructions</th>
                    <th>Status</th>
                    <th style="width:120px;"><span class="visually-hidden">Actions</span></th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$payments): ?>
                <tr><td colspan="8">
                    <?php
                    $es = ['icon' => 'fa-money-bill-transfer',
                           'title' => 'No payment methods yet',
                           'msg' => 'Add the ways your customers can pay — mobile money, bank transfer, card. Nothing is shown to customers until you add one.',
                           'action' => '<button type="button" class="ui-btn ui-btn-primary" data-bs-toggle="modal" data-bs-target="#payModal" onclick="newPayment()"><i class="fas fa-plus"></i>Add payment method</button>'];
                    include 'partials/empty-state.php';
                    ?>
                </td></tr>
            <?php endif; ?>

            <?php foreach ($payments as $p):
                $configured = trim((string)$p['payment_number']) !== '';
                $live = ((int)$p['is_enabled'] === 1) && $configured;
            ?>
                <tr>
                    <td class="text-end"><span class="ui-num"><?php echo (int)$p['sort_order']; ?></span></td>
                    <td style="font-weight:500;"><?php echo htmlspecialchars($p['provider']); ?></td>
                    <td class="ui-col-optional"><?php echo htmlspecialchars(shopPaymentTypeLabel($p['payment_type'])); ?></td>
                    <td>
                        <?php if ($configured): ?>
                            <span class="ui-num"><?php echo htmlspecialchars($p['payment_number']); ?></span>
                        <?php else: ?>
                            <span class="ui-badge ui-badge-warning">Not set</span>
                        <?php endif; ?>
                    </td>
                    <td class="ui-col-optional"><?php echo htmlspecialchars($p['account_name'] ?: '—'); ?></td>
                    <td class="ui-col-secondary ui-caption"><?php echo htmlspecialchars($p['instructions'] ?: '—'); ?></td>
                    <td>
                        <?php if ($live): ?>
                            <span class="ui-badge ui-badge-success"><span class="ui-dot ui-dot-success"></span>Shown</span>
                        <?php elseif ((int)$p['is_enabled'] === 1): ?>
                            <span class="ui-badge ui-badge-warning">Needs a number</span>
                        <?php else: ?>
                            <span class="ui-badge ui-badge-neutral">Hidden</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="d-flex gap-1 justify-content-end">
                            <button type="button" class="ui-btn ui-btn-secondary ui-btn-sm ui-btn-icon"
                                    title="Edit <?php echo htmlspecialchars($p['provider'], ENT_QUOTES); ?>"
                                    aria-label="Edit <?php echo htmlspecialchars($p['provider'], ENT_QUOTES); ?>"
                                    data-ui-modal="#payModal" data-title="Edit payment method"
                                    data-field-id="<?php echo (int)$p['id']; ?>"
                                    data-field-provider="<?php echo htmlspecialchars($p['provider'], ENT_QUOTES); ?>"
                                    data-field-payment-type="<?php echo htmlspecialchars($p['payment_type'], ENT_QUOTES); ?>"
                                    data-field-payment-number="<?php echo htmlspecialchars((string)$p['payment_number'], ENT_QUOTES); ?>"
                                    data-field-account-name="<?php echo htmlspecialchars((string)$p['account_name'], ENT_QUOTES); ?>"
                                    data-field-instructions="<?php echo htmlspecialchars((string)$p['instructions'], ENT_QUOTES); ?>"
                                    data-field-reference-note="<?php echo htmlspecialchars((string)$p['reference_note'], ENT_QUOTES); ?>"
                                    data-field-sort-order="<?php echo (int)$p['sort_order']; ?>"
                                    data-field-is-active="<?php echo (int)$p['is_enabled'] === 1 ? '1' : '0'; ?>">
                                <i class="fas fa-pen"></i>
                            </button>
                            <form method="post" style="display:inline;">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="toggle_payment">
                                <input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
                                <button class="ui-btn ui-btn-secondary ui-btn-sm ui-btn-icon"
                                        title="<?php echo (int)$p['is_enabled'] === 1 ? 'Hide from customers' : 'Show to customers'; ?>"
                                        aria-label="<?php echo (int)$p['is_enabled'] === 1 ? 'Hide' : 'Show'; ?> <?php echo htmlspecialchars($p['provider'], ENT_QUOTES); ?>">
                                    <i class="fas <?php echo (int)$p['is_enabled'] === 1 ? 'fa-eye-slash' : 'fa-eye'; ?>"></i>
                                </button>
                            </form>
                            <form method="post" style="display:inline;"
                                  onsubmit="return confirm('Remove <?php echo htmlspecialchars($p['provider'], ENT_QUOTES); ?>? Past sales are not affected.');">
                                <?php echo csrfField(); ?>
                                <input type="hidden" name="action" value="delete_payment">
                                <input type="hidden" name="id" value="<?php echo (int)$p['id']; ?>">
                                <button class="ui-btn ui-btn-danger ui-btn-sm ui-btn-icon"
                                        title="Remove" aria-label="Remove <?php echo htmlspecialchars($p['provider'], ENT_QUOTES); ?>">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add / edit payment method -->
<div class="modal fade" id="payModal" tabindex="-1" aria-labelledby="payModalTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <form method="post" data-ui-loading>
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="save_payment">
                <input type="hidden" name="id" value="0">
                <div class="modal-header">
                    <h5 class="modal-title" id="payModalTitle">
                        <i class="fas fa-money-bill-transfer me-2" style="color:var(--color-primary);"></i>
                        <span data-ui-modal-title>Add payment method</span>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="p_provider">Provider<span class="ui-required">*</span></label>
                            <input type="text" class="form-control" id="p_provider" name="provider" list="providerList" required maxlength="80" autocomplete="off">
                            <datalist id="providerList">
                                <?php foreach (shopPaymentProviderSuggestions() as $sug): ?>
                                <option value="<?php echo htmlspecialchars($sug); ?>"></option>
                                <?php endforeach; ?>
                            </datalist>
                            <div class="form-text">Type any provider — the suggestions are only shortcuts.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="p_type">Type</label>
                            <select class="form-select" id="p_type" name="payment_type">
                                <?php foreach (shopPaymentTypes() as $k => $lbl): ?>
                                <option value="<?php echo $k; ?>"><?php echo htmlspecialchars($lbl); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="p_number">Payment number</label>
                            <input type="text" class="form-control" id="p_number" name="payment_number" maxlength="60" inputmode="numeric">
                            <div class="form-text">The till number or account number customers pay into. Nothing is shown until this is filled in.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="p_account">Account / business name</label>
                            <input type="text" class="form-control" id="p_account" name="account_name" maxlength="120">
                            <div class="form-text">The name customers will see when confirming payment.</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="p_instr">Customer instructions</label>
                            <input type="text" class="form-control" id="p_instr" name="instructions" maxlength="255"
                                   placeholder="e.g. Pay by Lipa Namba, then show the confirmation message.">
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="p_ref">Reference note</label>
                            <input type="text" class="form-control" id="p_ref" name="reference_note" maxlength="255"
                                   placeholder="Optional. e.g. Use the receipt number as the reference.">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="p_order">Display order</label>
                            <input type="number" class="form-control" id="p_order" name="sort_order" value="0" min="0" max="999">
                            <div class="form-text">Lower numbers appear first.</div>
                        </div>
                        <div class="col-md-8 d-flex align-items-end">
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" name="is_enabled" id="p_enabled" checked>
                                <label class="form-check-label" for="p_enabled">Show this method to customers</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="ui-btn ui-btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="ui-btn ui-btn-primary"><i class="fas fa-check"></i>Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php else: ?>
<!-- ============ Receipt ============ -->
<form method="post" data-ui-loading>
    <?php echo csrfField(); ?>
    <input type="hidden" name="action" value="save_receipt">

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="inv-card p-4 mb-3">
                <h2 class="ui-section-title mb-1">Receipt text</h2>
                <p class="ui-muted mb-3">Printed at the top and bottom of every receipt.</p>
                <div class="mb-3">
                    <label class="form-label" for="receipt_header">Header line</label>
                    <input type="text" class="form-control" id="receipt_header" name="receipt_header"
                           value="<?php echo htmlspecialchars((string)$s['receipt_header'], ENT_QUOTES); ?>" maxlength="120">
                    <div class="form-text">Optional. Appears under the shop name, e.g. &ldquo;Cash sale receipt&rdquo;.</div>
                </div>
                <div>
                    <label class="form-label" for="pos_receipt_footer">Footer message</label>
                    <input type="text" class="form-control" id="pos_receipt_footer" name="pos_receipt_footer"
                           value="<?php echo htmlspecialchars($footer, ENT_QUOTES); ?>" maxlength="200">
                    <div class="form-text">The thank-you line at the bottom.</div>
                </div>
            </div>

            <div class="inv-card p-4">
                <h2 class="ui-section-title mb-1">What to print</h2>
                <p class="ui-muted mb-3">A detail is only printed when it is switched on <em>and</em> filled in on the Shop information tab.</p>
                <?php
                $toggleLabels = [
                    'receipt_show_logo'    => ['Logo', 'Prints the logo above the shop name. Thermal printers render logos coarsely — check a test print.'],
                    'receipt_show_address' => ['Address', ''],
                    'receipt_show_phone'   => ['Phone number', ''],
                    'receipt_show_email'   => ['Email address', ''],
                    'receipt_show_tin'     => ['TIN / VRN', 'Required on receipts in some jurisdictions.'],
                    'receipt_show_payment' => ['Payment instructions', 'Prints your enabled payment methods at the foot of the receipt.'],
                ];
                foreach ($toggleLabels as $k => [$lbl, $help]): ?>
                <div class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" name="<?php echo $k; ?>" id="<?php echo $k; ?>"
                           <?php echo ($s[$k] ?? '0') === '1' ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="<?php echo $k; ?>"><?php echo htmlspecialchars($lbl); ?></label>
                    <?php if ($help): ?><div class="form-text"><?php echo htmlspecialchars($help); ?></div><?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="inv-card p-4">
                <h2 class="ui-section-title mb-1">Preview</h2>
                <p class="ui-muted mb-3">Roughly how the top of an 80&nbsp;mm receipt will look. Always stays light for printing.</p>
                <div class="receipt-preview">
                    <?php if (($s['receipt_show_logo'] ?? '0') === '1' && $logoUrl): ?>
                        <img src="<?php echo htmlspecialchars($logoUrl); ?>" alt="" style="max-height:40px;max-width:70%;object-fit:contain;margin-bottom:6px;">
                    <?php endif; ?>
                    <div style="font-weight:bold;font-size:13px;"><?php echo htmlspecialchars(strtoupper((string)$s['shop_name'])); ?></div>
                    <?php if (!empty($s['shop_tagline'])): ?>
                        <div><?php echo htmlspecialchars(strtoupper((string)$s['shop_tagline'])); ?></div>
                    <?php endif; ?>
                    <?php if (($s['receipt_show_address'] ?? '0') === '1' && shopAddressLine($conn) !== ''): ?>
                        <div><?php echo htmlspecialchars(shopAddressLine($conn)); ?></div>
                    <?php endif; ?>
                    <?php if (($s['receipt_show_phone'] ?? '0') === '1' && !empty($s['shop_phone'])): ?>
                        <div>Tel: <?php echo htmlspecialchars((string)$s['shop_phone']); ?></div>
                    <?php endif; ?>
                    <?php if (($s['receipt_show_email'] ?? '0') === '1' && !empty($s['shop_email'])): ?>
                        <div><?php echo htmlspecialchars((string)$s['shop_email']); ?></div>
                    <?php endif; ?>
                    <?php if (($s['receipt_show_tin'] ?? '0') === '1' && (!empty($s['shop_tin']) || !empty($s['shop_vrn']))): ?>
                        <div>
                            <?php if (!empty($s['shop_tin'])): ?>TIN: <?php echo htmlspecialchars((string)$s['shop_tin']); ?><?php endif; ?>
                            <?php if (!empty($s['shop_vrn'])): ?> &nbsp;VRN: <?php echo htmlspecialchars((string)$s['shop_vrn']); ?><?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($s['receipt_header'])): ?>
                        <div style="margin-top:4px;"><?php echo htmlspecialchars((string)$s['receipt_header']); ?></div>
                    <?php endif; ?>
                    <div style="border-top:1px dashed #000;margin:6px 0;"></div>
                    <div style="text-align:left;">1 x Sample item &nbsp;&nbsp; 1,000</div>
                    <div style="border-top:1px dashed #000;margin:6px 0;"></div>
                    <div style="font-weight:bold;">TOTAL &nbsp; Tsh 1,000</div>
                    <div style="margin-top:6px;"><?php echo htmlspecialchars($footer); ?></div>
                </div>
                <p class="ui-caption mt-2 mb-0">Reload after saving to refresh the preview.</p>
            </div>
        </div>
    </div>

    <div class="mt-3">
        <button type="submit" class="ui-btn ui-btn-primary"><i class="fas fa-check"></i>Save receipt options</button>
    </div>
</form>
<?php endif; ?>

<?php
$pageScript = <<<'HTML'
<script>
// One modal serves Add and Edit, so Add must clear the last edit.
function newPayment() {
    const m = document.getElementById('payModal');
    if (!m) { return; }
    m.querySelector('[data-ui-modal-title]').textContent = 'Add payment method';
    m.querySelector('[name="id"]').value = '0';
    ['provider','payment_number','account_name','instructions','reference_note'].forEach(n => {
        const el = m.querySelector('[name="' + n + '"]'); if (el) el.value = '';
    });
    m.querySelector('[name="payment_type"]').value = 'mobile_money';
    m.querySelector('[name="sort_order"]').value = '0';
    m.querySelector('[name="is_enabled"]').checked = true;
}

// The shared modal filler maps data-field-is-active onto a control named
// is_active; this form's switch is named is_enabled, so mirror it.
document.addEventListener('click', function (e) {
    const t = e.target.closest('[data-ui-modal="#payModal"]');
    if (!t) { return; }
    const sw = document.querySelector('#payModal [name="is_enabled"]');
    if (sw) { sw.checked = t.dataset.fieldIsActive === '1'; }
});
</script>
HTML;
include 'inventory-footer.php';
?>
