
<?php
/*
 * PATIENT MONITORING SYSTEM
 * Local XAMPP Configuration
 *
 * Database: patient_monitor
 * Connection: MySQLi
 */

date_default_timezone_set('Asia/Kolkata');

// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'patient_monitor');

// Fixed server-side API token.
// Replace this placeholder with the same secret
// configured in the ESP32/ESP8266 firmware.
define('ESP_API_TOKEN', 'EspSwitch52026');

// Establish MySQL connection
$conn = new mysqli(
    DB_HOST,
    DB_USER,
    DB_PASS,
    DB_NAME
);

// Check connection
if ($conn->connect_error) {
    error_log('Database connection failed: ' . $conn->connect_error);

    http_response_code(500);
    die('Database connection failed. Check config.php.');
}

// Use UTF-8
if (!$conn->set_charset('utf8mb4')) {
    error_log('Could not set database character set.');
    http_response_code(500);
    die('Database character set configuration failed.');
}
?>
