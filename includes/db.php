<?php
// Database Configuration

// PHP 8.1 changed mysqli's default error mode to "throw an exception".
// This codebase is written against the older behaviour: the schema
// installers and dashboard widgets deliberately probe for tables and
// columns that may not exist yet, using `@$conn->query(...)` and
// checking the return value. Under the 8.1+ default those probes throw
// a fatal instead of returning false, which breaks a fresh install on
// the very first page load. Restore the return-false behaviour so the
// guarded-query convention used throughout the app keeps working.
mysqli_report(MYSQLI_REPORT_OFF);

$host     = '127.0.0.1';
$port     = '3306';
$username = 'root';
$password = '';
$database = 'retailer_shop';

$conn = mysqli_connect("$host:$port", $username, $password, $database);

// Check connection
if (!$conn) {
    // In production: hide technical details
    error_log("Database connection failed: " . mysqli_connect_error());
    die("Sorry, the system is under maintenance. Please try again later.");
}

// Optional: Set charset to avoid encoding issues
mysqli_set_charset($conn, "utf8mb4");

// Every timestamp the system writes or prints is local shop time.
date_default_timezone_set('Africa/Dar_es_Salaam');

// ---- Business identity ------------------------------------------------
// Printed on receipts and shown on the till. Single source of truth so
// these only ever need changing in one place.
if (!defined('BUSINESS_NAME')) {
    define('BUSINESS_NAME', 'Example Retail');
    define('BUSINESS_TAGLINE', 'Supermarket & Stationery');
    define('BUSINESS_ADDRESS', 'Your City, Your Country');
}

// Mobile-money payment details (Lipa Namba), shown wherever a till
// operator or customer is told how to pay. These are placeholder
// values - real ones are configured per-install in Shop Settings
// (admin/shop-settings.php), which is the actual source of truth;
// see includes/shop_settings.php. These constants are only the
// third-tier fallback for an install that never opens that screen.
if (!defined('LIPA_NAMBA_NUMBER')) {
    define('LIPA_NAMBA_NUMBER', '00000000');
    define('LIPA_NAMBA_PROVIDER', 'Mobile Money');
    define('LIPA_NAMBA_NAME', 'Example Retail');
}

// The business's WhatsApp/call number - placeholder, see note above.
if (!defined('BUSINESS_WHATSAPP_NUMBER')) {
    define('BUSINESS_WHATSAPP_NUMBER', '255000000000');
    define('BUSINESS_PHONE_E164', '+255000000000');
}
