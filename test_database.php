<?php
/*
 * PATIENT MONITORING SYSTEM
 * Render + TiDB Cloud Connection Test
 *
 * URL:
 * https://patient_remote.onrender.com/test_db.php
 */

date_default_timezone_set('Asia/Kolkata');

header('Content-Type: text/plain; charset=utf-8');

echo "========================================\n";
echo "PATIENT MONITOR DATABASE CONNECTION TEST\n";
echo "========================================\n\n";

// ------------------------------------------
// STEP 1: READ ENVIRONMENT VARIABLES
// ------------------------------------------

$host = getenv('DB_HOST') ?: '';
$user = getenv('DB_USER') ?: '';
$password = getenv('DB_PASSWORD') ?: (getenv('DB_PASS') ?: '');
$database = getenv('DB_NAME') ?: '';
$port = (int)(getenv('DB_PORT') ?: 3306);

echo "STEP 1: ENVIRONMENT VARIABLES\n";
echo "------------------------------------------\n";

echo "DB_HOST: " . ($host ?: 'NOT SET') . "\n";
echo "DB_USER: " . ($user ?: 'NOT SET') . "\n";
echo "DB_NAME: " . ($database ?: 'NOT SET') . "\n";
echo "DB_PORT: " . $port . "\n";
echo "DB_PASSWORD: " . ($password !== '' ? 'SET' : 'NOT SET') . "\n\n";

if (!$host || !$user || !$password || !$database) {
    die("RESULT: FAILED - Missing environment variable.\n");
}

// ------------------------------------------
// STEP 2: CHECK MYSQLI
// ------------------------------------------

echo "STEP 2: MYSQLI CHECK\n";
echo "------------------------------------------\n";

if (!extension_loaded('mysqli')) {
    die("RESULT: FAILED - MySQLi extension not installed.\n");
}

echo "MySQLi extension: AVAILABLE\n\n";

// ------------------------------------------
// STEP 3: TEST TCP CONNECTION
// ------------------------------------------

echo "STEP 3: HOST REACHABILITY\n";
echo "------------------------------------------\n";

$socket = @fsockopen($host, $port, $errno, $errstr, 10);

if (!$socket) {
    die("RESULT: FAILED - Cannot reach database host.\n"
        . "Error: $errstr ($errno)\n");
}

fclose($socket);

echo "TiDB host is reachable on port $port.\n\n";

// ------------------------------------------
// STEP 4: CONNECT USING MYSQLI + SSL
// ------------------------------------------

echo "STEP 4: MYSQLI DATABASE CONNECTION\n";
echo "------------------------------------------\n";

mysqli_report(MYSQLI_REPORT_OFF);

$conn = mysqli_init();

if (!$conn) {
    die("RESULT: FAILED - MySQLi initialization failed.\n");
}

$conn->options(MYSQLI_OPT_CONNECT_TIMEOUT, 15);

// TiDB Cloud SSL connection
$conn->ssl_set(null, null, null, null, null);

$connected = $conn->real_connect(
    $host,
    $user,
    $password,
    $database,
    $port,
    null,
    MYSQLI_CLIENT_SSL
);

if (!$connected) {
    die("RESULT: FAILED - Database connection failed.\n"
        . "MySQL Error Number: " . $conn->connect_errno . "\n"
        . "MySQL Error: " . $conn->connect_error . "\n");
}

echo "DATABASE CONNECTION: SUCCESSFUL\n\n";

// ------------------------------------------
// STEP 5: CHARACTER SET
// ------------------------------------------

echo "STEP 5: CHARACTER SET\n";
echo "------------------------------------------\n";

if (!$conn->set_charset('utf8mb4')) {
    echo "WARNING: " . $conn->error . "\n";
} else {
    echo "Character set: utf8mb4\n";
}

// ------------------------------------------
// STEP 6: DATABASE INFORMATION
// ------------------------------------------

echo "\nSTEP 6: DATABASE INFORMATION\n";
echo "------------------------------------------\n";

$result = $conn->query(
    "SELECT DATABASE() AS db_name,
            VERSION() AS db_version,
            @@port AS db_port"
);

if (!$result) {
    die("Database information query failed: " . $conn->error);
}

$info = $result->fetch_assoc();

echo "Database: " . $info['db_name'] . "\n";
echo "TiDB Version: " . $info['db_version'] . "\n";
echo "Port: " . $info['db_port'] . "\n";

// ------------------------------------------
// STEP 7: CHECK PROJECT TABLES
// ------------------------------------------

echo "\nSTEP 7: PATIENT MONITOR TABLES\n";
echo "------------------------------------------\n";

$expectedTables = [
    'alert_state',
    'alerts',
    'ecg_readings',
    'monitoring_readings',
    'owner_users',
    'patient_alert_settings',
    'patient_devices',
    'patient_push_tokens',
    'patients',
    'web_users'
];

$allTablesFound = true;

foreach ($expectedTables as $table) {

    $safeTable = str_replace('`', '``', $table);

    $check = $conn->query(
        "SHOW TABLES LIKE '" . $conn->real_escape_string($table) . "'"
    );

    if ($check && $check->num_rows > 0) {
        echo "[OK] $table\n";
    } else {
        echo "[MISSING] $table\n";
        $allTablesFound = false;
    }
}

// ------------------------------------------
// STEP 8: FINAL RESULT
// ------------------------------------------

echo "\n========================================\n";

if ($allTablesFound) {
    echo "FINAL RESULT: ALL TESTS SUCCESSFUL\n";
    echo "Render is connected to TiDB Cloud.\n";
} else {
    echo "FINAL RESULT: DATABASE CONNECTED\n";
    echo "BUT SOME PROJECT TABLES ARE MISSING.\n";
}

echo "========================================\n";

$conn->close();

?>
