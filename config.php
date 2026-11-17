
<?php
/*
 * PATIENT MONITORING SYSTEM
 * Render.com + TiDB Cloud Configuration
 *
 * Website: patient_remote.onrender.com
 * Database: patient_monitor
 * Connection: MySQLi
 * Port: 4000
 */

date_default_timezone_set('Asia/Kolkata');

// --------------------------------------------------
// 1. DATABASE CONFIGURATION
// --------------------------------------------------

// Read environment variables from Render.
// Local XAMPP defaults are retained for local testing.

define(
    'DB_HOST',
    getenv('DB_HOST') ?: 'localhost'
);

define(
    'DB_USER',
    getenv('DB_USER') ?: 'root'
);

define(
    'DB_PASS',
    getenv('DB_PASSWORD') !== false
        ? getenv('DB_PASSWORD')
        : (getenv('DB_PASS') !== false ? getenv('DB_PASS') : '')
);

define(
    'DB_NAME',
    getenv('DB_NAME') ?: 'patient_monitor'
);

define(
    'DB_PORT',
    (int)(getenv('DB_PORT') ?: 3306)
);

// --------------------------------------------------
// 2. ESP32 API TOKEN
// --------------------------------------------------

$apiToken = getenv('ESP_API_TOKEN')
    ?: getenv('API_TOKEN')
    ?: 'EspSwitch52026';

define('ESP_API_TOKEN', $apiToken);

// Compatibility for PHP files using API_TOKEN.
if (!defined('API_TOKEN')) {
    define('API_TOKEN', $apiToken);
}

// --------------------------------------------------
// 3. MYSQLI CONNECTION
// --------------------------------------------------

mysqli_report(MYSQLI_REPORT_OFF);

$conn = mysqli_init();

if (!$conn) {
    error_log('MySQLi initialization failed.');

    http_response_code(500);
    die('Database initialization failed.');
}

// Connection timeout
$conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, 15);

// --------------------------------------------------
// 4. SSL CONNECTION FOR TIDB CLOUD
// --------------------------------------------------

$isRemoteDatabase = !in_array(
    strtolower(DB_HOST),
    ['localhost', '127.0.0.1']
);

$flags = 0;

if ($isRemoteDatabase) {

    // TiDB Cloud requires an encrypted connection.
    $conn->ssl_set(
        null,
        null,
        null,
        null,
        null
    );

    $flags = MYSQLI_CLIENT_SSL;
}

// --------------------------------------------------
// 5. CONNECT TO DATABASE
// --------------------------------------------------

$connected = $conn->real_connect(
    DB_HOST,
    DB_USER,
    DB_PASS,
    DB_NAME,
    DB_PORT,
    null,
    $flags
);

if (!$connected) {

    error_log(
        'Database connection failed: ' . $conn->connect_error
    );

    http_response_code(500);

    die('Database connection failed. Please check Render environment variables and TiDB connection settings.');
}

// --------------------------------------------------
// 6. CHARACTER SET
// --------------------------------------------------

if (!$conn->set_charset('utf8mb4')) {

    error_log(
        'Could not set database character set: ' . $conn->error
    );

    http_response_code(500);

    die('Database character set configuration failed.');
}

// --------------------------------------------------
// 7. DATABASE SESSION TIMEZONE
// --------------------------------------------------

if (!$conn->query("SET time_zone = '+05:30'")) {

    error_log(
        'Could not set database timezone: ' . $conn->error
    );
}

?>
