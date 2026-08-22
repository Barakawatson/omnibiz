<?php
// ============================================================
// Core tables - self-installing schema
// ------------------------------------------------------------
// `admin` (staff logins) and `customer` (walk-in customers,
// identified by phone number) predate the self-installing-schema
// convention and were historically created by hand from a SQL
// dump. That left a gap: a brand-new database had every module
// table but nothing to sign in with.
//
// This file closes that gap. It only ever CREATEs when the table
// is missing, so on an existing install - where these tables are
// full of real staff and customer records - it does nothing at
// all and never alters their structure.
// ============================================================

// v2: `admin_remember_tokens`. "Keep me signed in" used to be a cookie
// containing nothing but a username in plain JSON, which anyone could
// type into their own browser to become an administrator. Tokens are
// now random, hashed at rest, per-device and expiring - see
// includes/remember_me.php.
if (!defined('CORE_SCHEMA_VERSION')) {
    define('CORE_SCHEMA_VERSION', '2');
}

function ensureCoreSchema(mysqli $conn): void {
    // Fast path - already installed. Guarded because inv_settings may
    // not exist yet on a completely empty database.
    $check = @$conn->query("SELECT setting_value FROM inv_settings WHERE setting_key = 'core_schema_version' LIMIT 1");
    if ($check instanceof mysqli_result) {
        $row = $check->fetch_assoc();
        $check->free();
        if ($row && (string)$row['setting_value'] === (string)CORE_SCHEMA_VERSION) {
            return;
        }
    }

    $charset = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci';

    // --- Staff logins -----------------------------------------------------
    // `role` is VARCHAR rather than ENUM on purpose: an ENUM silently
    // stores an empty string when given a value it doesn't know, which
    // is exactly how the old system locked users out of their accounts.
    @$conn->query("CREATE TABLE IF NOT EXISTS `admin` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `username` VARCHAR(50) NOT NULL,
        `password` VARCHAR(255) NOT NULL,
        `role` VARCHAR(30) NOT NULL DEFAULT 'cashier',
        `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
        `profile_photo` VARCHAR(255) DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_admin_username` (`username`)
    ) $charset") || error_log('core_schema admin: ' . $conn->error);

    // --- Customers --------------------------------------------------------
    // The phone number is the customer's identity across the system, so
    // it is unique. Customers never log in - a cashier attaches one to a
    // sale by phone number at the till.
    @$conn->query("CREATE TABLE IF NOT EXISTS `customer` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `name` VARCHAR(150) NOT NULL,
        `phone_number` VARCHAR(30) NOT NULL,
        `registration_date` DATETIME DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_customer_phone` (`phone_number`),
        KEY `idx_customer_name` (`name`)
    ) $charset") || error_log('core_schema customer: ' . $conn->error);

    // --- "Keep me signed in" tokens ---------------------------------------
    // Split into a selector and a validator. The selector is what we look
    // the row up by (indexed, and safe to expose); the validator is the
    // secret, stored only as a SHA-256 digest and compared with
    // hash_equals(). Storing only the digest means a dump of this table
    // cannot be replayed as a login.
    //
    // SHA-256 rather than bcrypt is deliberate: the validator is 32 bytes
    // of CSPRNG output, not a guessable password, so there is nothing for
    // a slow hash to defend against - and this runs on every page load.
    @$conn->query("CREATE TABLE IF NOT EXISTS `admin_remember_tokens` (
        `id` INT(11) NOT NULL AUTO_INCREMENT,
        `user_id` INT(11) NOT NULL,
        `selector` CHAR(32) NOT NULL,
        `validator_hash` CHAR(64) NOT NULL,
        `user_agent` VARCHAR(255) DEFAULT NULL,
        `expires_at` DATETIME NOT NULL,
        `last_used_at` DATETIME DEFAULT NULL,
        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uq_remember_selector` (`selector`),
        KEY `idx_remember_user` (`user_id`),
        KEY `idx_remember_expires` (`expires_at`),
        CONSTRAINT `fk_remember_user` FOREIGN KEY (`user_id`)
            REFERENCES `admin` (`id`) ON DELETE CASCADE
    ) $charset") || error_log('core_schema admin_remember_tokens: ' . $conn->error);

    // --- First-run administrator -----------------------------------------
    // A database with no staff account cannot be signed into, and there
    // is no other way in. Seed one - but ONLY when the table is
    // completely empty, so this can never resurrect a deleted account or
    // reset a real password on an existing install.
    $count = 0;
    $res = @$conn->query("SELECT COUNT(*) AS c FROM `admin`");
    if ($res instanceof mysqli_result) {
        $count = (int)($res->fetch_assoc()['c'] ?? 0);
        $res->free();
    }
    if ($count === 0) {
        $hash = password_hash('12345', PASSWORD_BCRYPT);
        $stmt = $conn->prepare("INSERT INTO `admin` (username, password, role, status)
                                VALUES ('admin', ?, 'admin', 'active')");
        if ($stmt) {
            $stmt->bind_param('s', $hash);
            $stmt->execute();
            $stmt->close();
            error_log('core_schema: seeded first-run administrator "admin" / "12345" - change this password before the shop goes live.');
        }
    }

    // Mark installed (reuses inv_settings, same as the other modules).
    $stmt = $conn->prepare("INSERT INTO inv_settings (setting_key, setting_value) VALUES ('core_schema_version', ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    if ($stmt) {
        $v = (string)CORE_SCHEMA_VERSION;
        $stmt->bind_param('s', $v);
        $stmt->execute();
        $stmt->close();
    }
}
