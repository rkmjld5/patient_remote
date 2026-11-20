<?php
/*
 * TEMPORARY DATABASE CONNECTION TEST
 * Render + TiDB Cloud
 *
 * Open:
 * https://patient-remote.onrender.com/test_db_connection.php
 *
 * DELETE THIS FILE AFTER TESTING.
 */

header('Content-Type: text/plain; charset=utf-8');

echo "PATIENT MONITOR - DATABASE CONNECTION TEST\n";
echo "==========================================\n\n";

echo "PHP version: " . PHP_VERSION . "\n";
echo "Database host configured: ";

$host = getenv('DB_HOST');
echo ($host !== false && $host !== '') ? $host : "NOT SET (localhost fallback)";

echo "\nDatabase port configured: ";

$port = getenv('DB_PORT');
echo ($port !== false && $port !== '') ? $port : "NOT SET (3306 fallback)";

echo "\nDatabase name configured: ";

$dbname = getenv('DB_NAME');
echo ($dbname !== false && $dbname !== '') ? $dbname : "NOT SET (patient_monitor fallback)";

echo "\nDatabase username configured: ";
$user = getenv('DB_USER');
echo ($user !== false && $user !== '') ? "YES" : "NO";

echo "\nDatabase password configured: ";
$pass = getenv('DB_PASSWORD');
$pass2 = getenv('DB_PASS');

echo (
    ($pass !== false && $pass !== '') ||
    ($pass2 !== false && $pass2 !== '')
) ? "YES" : "NO";

echo "\n\n------------------------------------------\n";
echo "Loading config.php...\n";

require_once __DIR__ . '/config.php';

echo "config.php loaded.\n";

if (!isset($conn) || !($conn instanceof mysqli)) {
    echo "RESULT: FAIL - MySQLi connection object unavailable.\n";
    exit;
}

if ($conn->connect_errno) {
    echo "RESULT: FAIL - Database connection error.\n";
    exit;
}

echo "RESULT: DATABASE CONNECTION SUCCESSFUL\n\n";

$result = $conn->query(
    "SELECT DATABASE() AS db_name,
            @@hostname AS db_host,
            CURRENT_USER() AS db_user"
);

if (!$result) {
    echo "Could not read database connection details.\n";
    exit;
}

$row = $result->fetch_assoc();

echo "Connected database: " . $row['db_name'] . "\n";
echo "Database server hostname: " . $row['db_host'] . "\n";
echo "Connected database user: " . $row['db_user'] . "\n";

echo "\n------------------------------------------\n";

$result = $conn->query(
    "SELECT COUNT(*) AS total_rows
     FROM patient_push_tokens"
);

if ($result) {
    $row = $result->fetch_assoc();
    echo "Rows in patient_push_tokens: " . $row['total_rows'] . "\n";
} else {
    echo "Could not read patient_push_tokens.\n";
}

echo "\nTEST COMPLETED.\n";

$conn->close();
?>
