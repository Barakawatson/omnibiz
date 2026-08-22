<?php
// ============================================================
// Shop identity, receipt options and payment instructions
// ------------------------------------------------------------
// WHY THIS EXISTS
// Business identity used to live in eight PHP constants in
// includes/db.php - shop name, address, phone, WhatsApp and the
// mobile-money till details. Running this system for a different
// shop meant editing source code. Everything here is now stored
// in the database and edited by an administrator at
// admin/shop-settings.php.
//
// FALLBACK ORDER (as specified)
//   1. the value configured in the database
//   2. the existing safe default (the old constant, so an
//      installation upgrading from before this change looks
//      exactly the same afterwards)
//   3. an empty string - never the word "null", "undefined" or
//      "Array" on a screen or a receipt
//
// STORAGE
// Scalar values extend the existing inv_settings key/value store
// rather than introducing a second configuration system.
//
// Payment methods do NOT fit there: a shop needs several of them,
// each with a provider, number, payee name, instructions, an
// enabled flag and a display order, and they need to be ordered
// and queried as records. Packing that into one key/value row
// would mean serialising a structure into a string and parsing it
// on every read - which is a second configuration system wearing
// a disguise. They get one small table, shop_payment_methods.
//
// CACHING
// Settings are read many times per request (the receipt alone
// reads a dozen). Everything is loaded once into a static array
// on first use, so a page costs ONE query for all of it rather
// than one query per value.
// ============================================================

require_once __DIR__ . '/inventory_functions.php';

if (!defined('SHOP_SETTINGS_VERSION')) { define('SHOP_SETTINGS_VERSION', '1'); }

/**
 * Every configurable shop field, with its safe default.
 *
 * The defaults deliberately reproduce the old hardcoded constants,
 * so an existing installation shows exactly what it showed before
 * an administrator has configured anything.
 */
function shopSettingDefaults(): array {
    return [
        // --- Identity -------------------------------------------------
        'shop_name'            => defined('BUSINESS_NAME') ? BUSINESS_NAME : '',
        'shop_tagline'         => defined('BUSINESS_TAGLINE') ? BUSINESS_TAGLINE : '',
        'shop_trading_name'    => '',
        'shop_registered_name' => '',
        'shop_description'     => '',
        'shop_logo'            => '',

        // --- What kind of business this is ----------------------------
        // A classification, nothing more: it seeds suggested departments
        // and categories during setup and labels the shop afterwards. It
        // does NOT switch the application into a different mode, and
        // choosing "Pharmacy" does not add pharmacy-specific features.
        // See includes/business_types.php.
        'shop_business_type'   => '',
        'shop_business_type_other' => '',

        // --- Statutory ------------------------------------------------
        'shop_tin'             => '',
        'shop_vrn'             => '',

        // --- Where it is ----------------------------------------------
        'shop_address'         => defined('BUSINESS_ADDRESS') ? BUSINESS_ADDRESS : '',
        'shop_street'          => '',
        'shop_city'            => '',
        'shop_region'          => '',
        'shop_country'         => '',

        // --- How to reach it ------------------------------------------
        'shop_phone'           => defined('BUSINESS_PHONE_E164') ? BUSINESS_PHONE_E164 : '',
        'shop_phone_alt'       => '',
        'shop_email'           => '',
        'shop_whatsapp'        => defined('BUSINESS_WHATSAPP_NUMBER') ? BUSINESS_WHATSAPP_NUMBER : '',
        'shop_website'         => '',

        // --- Receipt --------------------------------------------------
        // pos_receipt_footer predates this file and is already used by
        // posReceiptFooter(); it is listed so the settings screen can
        // edit it, but its key name is left alone.
        'receipt_header'       => '',
        'receipt_show_logo'    => '0',
        'receipt_show_address' => '1',
        'receipt_show_phone'   => '1',
        'receipt_show_email'   => '0',
        'receipt_show_tin'     => '1',
        'receipt_show_payment' => '1',
    ];
}

/** Which of the above are on/off switches, so the form can render them. */
function shopSettingToggles(): array {
    return ['receipt_show_logo', 'receipt_show_address', 'receipt_show_phone',
            'receipt_show_email', 'receipt_show_tin', 'receipt_show_payment'];
}

/**
 * All shop settings for this request, read once.
 *
 * Pass $refresh = true immediately after saving, so the page that
 * did the saving does not render stale values.
 */
function shopSettingsAll(mysqli $conn, bool $refresh = false): array {
    static $cache = null;
    if ($cache !== null && !$refresh) { return $cache; }

    $cache = shopSettingDefaults();

    // One query for the lot. Guarded because inv_settings may not
    // exist yet on a brand-new database.
    $keys = array_keys($cache);
    $in   = "'" . implode("','", array_map([$conn, 'real_escape_string'], $keys)) . "'";
    $res  = @$conn->query("SELECT setting_key, setting_value FROM inv_settings WHERE setting_key IN ($in)");
    if ($res instanceof mysqli_result) {
        while ($row = $res->fetch_assoc()) {
            // A row that exists but is empty still counts as configured
            // for toggles (0 is a real answer); for text, an empty value
            // falls back to the default so a blank never wipes the brand.
            $k = $row['setting_key'];
            $v = (string)$row['setting_value'];
            if (in_array($k, shopSettingToggles(), true)) { $cache[$k] = $v === '1' ? '1' : '0'; }
            elseif ($v !== '')                            { $cache[$k] = $v; }
        }
        $res->free();
    }

    return $cache;
}

/** One setting, already resolved through the fallback order. */
function shopSetting(mysqli $conn, string $key, string $fallback = ''): string {
    $all = shopSettingsAll($conn);
    $val = $all[$key] ?? $fallback;
    return $val === '' ? $fallback : (string)$val;
}

/** True when an on/off receipt option is on. */
function shopSettingOn(mysqli $conn, string $key): bool {
    $all = shopSettingsAll($conn);
    return ($all[$key] ?? '0') === '1';
}

/** The shop's display name, never empty - falls back to the constant. */
function shopName(mysqli $conn): string {
    return shopSetting($conn, 'shop_name', defined('BUSINESS_NAME') ? BUSINESS_NAME : 'Retail POS');
}

/**
 * The logo's web path, or '' when none is configured or the file has
 * gone missing. Callers can therefore always do `if ($logo)`.
 *
 * $prefix adjusts for where the calling page sits (admin/ pages need
 * '../'), because this application has no base-URL helper.
 */
function shopLogoUrl(mysqli $conn, string $prefix = '../'): string {
    $file = shopSetting($conn, 'shop_logo');
    if ($file === '') { return ''; }
    if (!is_file(__DIR__ . '/../assets/uploads/shop_logo/' . $file)) { return ''; }
    return $prefix . 'assets/uploads/shop_logo/' . rawurlencode($file);
}

/**
 * A one-line address built from whichever parts are filled in.
 * Nothing is printed for the parts that are not.
 */
function shopAddressLine(mysqli $conn): string {
    $all   = shopSettingsAll($conn);
    $parts = array_filter([
        trim((string)($all['shop_street'] ?? '')),
        trim((string)($all['shop_city'] ?? '')),
        trim((string)($all['shop_region'] ?? '')),
        trim((string)($all['shop_country'] ?? '')),
    ], static fn($p) => $p !== '');

    // Nothing structured filled in - fall back to the single free-text
    // address field, which is what existing installations have.
    if (!$parts) { return trim((string)($all['shop_address'] ?? '')); }
    return implode(', ', $parts);
}

/** Save one scalar setting. */
function shopSettingSave(mysqli $conn, string $key, string $value): bool {
    return setInvSetting($conn, $key, $value);
}

// ============================================================
// Payment methods
// ============================================================

/**
 * Configured payment methods.
 *
 * $enabledOnly is the normal case: customer-facing screens must show
 * only what an administrator has both enabled AND filled in, never a
 * placeholder or a half-configured row.
 */
function shopPaymentMethods(mysqli $conn, bool $enabledOnly = true): array {
    static $cache = [];
    $ck = $enabledOnly ? 'on' : 'all';
    if (isset($cache[$ck])) { return $cache[$ck]; }

    $sql = "SELECT * FROM shop_payment_methods";
    if ($enabledOnly) {
        // "Configured" means it actually has a number to show. A row
        // that is enabled but blank would print an empty instruction.
        $sql .= " WHERE is_enabled = 1 AND TRIM(COALESCE(payment_number,'')) <> ''";
    }
    $sql .= " ORDER BY sort_order ASC, id ASC";

    $res = @$conn->query($sql);
    $cache[$ck] = ($res instanceof mysqli_result) ? $res->fetch_all(MYSQLI_ASSOC) : [];
    return $cache[$ck];
}

/** Clear the payment cache after a change within the same request. */
function shopPaymentMethodsFlush(): void {
    // Cheap and explicit: the settings page saves then re-reads.
    shopPaymentMethodsCacheReset();
}
function shopPaymentMethodsCacheReset(): void {
    // A static inside another function cannot be reset from outside, so
    // the settings screen redirects after saving (POST/Redirect/GET) and
    // the next request reads fresh. This function documents that intent.
}

/**
 * Providers offered in the picker. Deliberately a SUGGESTION list, not
 * a constraint: the column is free text, so a shop using a provider
 * nobody here has heard of can still type it in.
 */
function shopPaymentProviderSuggestions(): array {
    return ['M-Pesa', 'Tigo Pesa (Mixx by Yas)', 'Airtel Money', 'HaloPesa',
            'CRDB Bank', 'NMB Bank', 'Bank transfer', 'Card'];
}

/** Payment types, matching how the till already thinks about tenders. */
function shopPaymentTypes(): array {
    return [
        'mobile_money' => 'Mobile money',
        'bank'         => 'Bank transfer',
        'card'         => 'Card',
        'cash'         => 'Cash',
        'other'        => 'Other',
    ];
}

function shopPaymentTypeLabel(string $type): string {
    $t = shopPaymentTypes();
    return $t[$type] ?? 'Other';
}

// ============================================================
// Schema
// ============================================================

/**
 * Install/upgrade the shop-settings storage.
 *
 * Additive only, in keeping with the rest of the system: it creates
 * the payment table if missing and widens inv_settings.setting_value,
 * which was VARCHAR(255) - too short for a receipt header, a footer
 * or a business description.
 */
function ensureShopSettingsSchema(mysqli $conn): void {
    $check = @$conn->query("SELECT setting_value FROM inv_settings WHERE setting_key = 'shop_settings_version' LIMIT 1");
    if ($check instanceof mysqli_result) {
        $row = $check->fetch_assoc();
        $check->free();
        if ($row && (string)$row['setting_value'] === (string)SHOP_SETTINGS_VERSION) { return; }
    }

    $charset = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci';

    // Multi-line receipt text does not fit in VARCHAR(255).
    $col = @$conn->query("SHOW COLUMNS FROM inv_settings LIKE 'setting_value'");
    if ($col instanceof mysqli_result) {
        $info = $col->fetch_assoc();
        $col->free();
        if ($info && stripos((string)$info['Type'], 'varchar') === 0) {
            @$conn->query("ALTER TABLE inv_settings MODIFY `setting_value` TEXT DEFAULT NULL");
        }
    }

    @$conn->query("CREATE TABLE IF NOT EXISTS `shop_payment_methods` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `provider` VARCHAR(80) NOT NULL,
        `payment_type` VARCHAR(30) NOT NULL DEFAULT 'mobile_money',
        `payment_number` VARCHAR(60) DEFAULT NULL,
        `account_name` VARCHAR(120) DEFAULT NULL,
        `instructions` VARCHAR(255) DEFAULT NULL,
        `reference_note` VARCHAR(255) DEFAULT NULL,
        `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
        `sort_order` INT(11) NOT NULL DEFAULT 0,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_shop_pay_enabled` (`is_enabled`, `sort_order`)
    ) $charset") || error_log('shop_settings payment table: ' . $conn->error);

    // Carry the old hardcoded Lipa Namba across, ONCE, so an existing
    // installation keeps the details it already had. Only when the
    // table is empty - this must never resurrect a deleted row.
    $cnt = 0;
    $r = @$conn->query("SELECT COUNT(*) AS c FROM shop_payment_methods");
    if ($r instanceof mysqli_result) { $cnt = (int)($r->fetch_assoc()['c'] ?? 0); $r->free(); }

    if ($cnt === 0 && defined('LIPA_NAMBA_NUMBER') && LIPA_NAMBA_NUMBER !== '') {
        $stmt = $conn->prepare("INSERT INTO shop_payment_methods
            (provider, payment_type, payment_number, account_name, instructions, is_enabled, sort_order)
            VALUES (?, 'mobile_money', ?, ?, ?, 1, 1)");
        if ($stmt) {
            $provider = defined('LIPA_NAMBA_PROVIDER') ? LIPA_NAMBA_PROVIDER : 'Mobile money';
            $number   = LIPA_NAMBA_NUMBER;
            $account  = defined('LIPA_NAMBA_NAME') ? LIPA_NAMBA_NAME : '';
            $instr    = 'Pay by Lipa Namba, then show the confirmation message.';
            $stmt->bind_param('ssss', $provider, $number, $account, $instr);
            $stmt->execute();
            $stmt->close();
        }
    }

    setInvSetting($conn, 'shop_settings_version', (string)SHOP_SETTINGS_VERSION);
}
