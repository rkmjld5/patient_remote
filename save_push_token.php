<?php
/*
 * PATIENT MONITORING SYSTEM
 * File: save_push_token.php
 *
 * Purpose:
 *   Save Firebase Cloud Messaging (FCM) token
 *   for the logged-in patient.
 *
 * Security:
 *   - Uses the same PATIENTSESSID as patient_login.php.
 *   - Requires an authenticated patient session.
 *   - Revalidates patient account and device assignment.
 *   - Checks account and device validity dates.
 *   - Does not trust patient/device IDs from the browser.
 *   - Uses prepared SQL statements.
 *
 * Database:
 *   config.php ($conn)
 */

declare(strict_types=1);

// --------------------------------------------------
// 1. Start the SAME patient session
// --------------------------------------------------

if (session_status() === PHP_SESSION_NONE) {

    session_name('PATIENTSESSID');

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => (
            isset($_SERVER['HTTPS']) &&
            $_SERVER['HTTPS'] !== 'off'
        ),
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');

    session_start();
}

// --------------------------------------------------
// 2. JSON response helper
// --------------------------------------------------

ob_start();

ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

function sendJson(int $status, array $data): void
{
    if (ob_get_length()) {
        ob_clean();
    }

    http_response_code($status);

    echo json_encode(
        $data,
        JSON_UNESCAPED_SLASHES
    );

    exit;
}

// --------------------------------------------------
// 3. Allow POST only
// --------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

    sendJson(405, [
        'success' => false,
        'message' => 'POST request required.'
    ]);
}

// --------------------------------------------------
// 4. Verify patient session
// --------------------------------------------------

if (
    ($_SESSION['patient_logged_in'] ?? false) !== true ||
    empty($_SESSION['patient_user_id']) ||
    empty($_SESSION['patient_device_id']) ||
    empty($_SESSION['patient_id'])
) {

    sendJson(401, [
        'success' => false,
        'message' => 'Patient login required. Please log in again.'
    ]);
}

$userId = (int)$_SESSION['patient_user_id'];

$deviceId = (string)$_SESSION['patient_device_id'];

$patientId = (string)$_SESSION['patient_id'];

if (
    $userId <= 0 ||
    $deviceId === '' ||
    $patientId === ''
) {

    sendJson(401, [
        'success' => false,
        'message' => 'Invalid patient session. Please log in again.'
    ]);
}

// --------------------------------------------------
// 5. Timezone and database connection
// --------------------------------------------------

date_default_timezone_set('Asia/Kolkata');

try {

    mysqli_report(
        MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT
    );

    require_once __DIR__ . '/config.php';

    if (
        !isset($conn) ||
        !($conn instanceof mysqli)
    ) {

        throw new RuntimeException(
            'Database connection unavailable.'
        );
    }

    $conn->set_charset('utf8mb4');

    // --------------------------------------------------
    // 6. Read FCM token from JSON request
    // --------------------------------------------------

    $rawInput = file_get_contents('php://input');

    if ($rawInput === false || $rawInput === '') {

        sendJson(400, [
            'success' => false,
            'message' => 'Empty request.'
        ]);
    }

    $input = json_decode($rawInput, true);

    if (!is_array($input)) {

        sendJson(400, [
            'success' => false,
            'message' => 'Invalid JSON request.'
        ]);
    }

    $token = $input['token'] ?? '';

    if (
        !is_string($token) ||
        trim($token) === '' ||
        strlen($token) > 8192
    ) {

        sendJson(400, [
            'success' => false,
            'message' => 'Invalid Firebase notification token.'
        ]);
    }

    $token = trim($token);

    $tokenHash = hash('sha256', $token);

    // --------------------------------------------------
    // 7. Revalidate patient and device access
    // --------------------------------------------------

    $now = date('Y-m-d H:i:s');

    $sql = "
        SELECT 1

        FROM web_users AS wu

        INNER JOIN patient_devices AS pd
            ON pd.device_id = wu.device_id

        INNER JOIN patients AS p
            ON p.patient_id = pd.patient_id

        WHERE
            wu.id = ?
            AND wu.device_id = ?
            AND pd.patient_id = ?

            AND wu.role = 'patient'
            AND wu.enabled = 1

            AND (
                wu.valid_from IS NULL
                OR wu.valid_from <= ?
            )

            AND (
                wu.valid_until IS NULL
                OR wu.valid_until >= ?
            )

            AND pd.enabled = 1

            AND (
                pd.start_date_time IS NULL
                OR pd.start_date_time <= ?
            )

            AND (
                pd.end_date_time IS NULL
                OR pd.end_date_time >= ?
            )

        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);

    $stmt->bind_param(
        'issssss',
        $userId,
        $deviceId,
        $patientId,
        $now,
        $now,
        $now,
        $now
    );

    $stmt->execute();

    $stmt->bind_result($validRecord);

    $validPatient = ($stmt->fetch() === true);

    $stmt->close();

    if (!$validPatient) {

        sendJson(403, [
            'success' => false,
            'message' => 'Patient account or device access is disabled or expired.'
        ]);
    }

    // --------------------------------------------------
    // 8. Save or update Firebase notification token
    // --------------------------------------------------

    $platform = 'ANDROID_CHROME';

    $sql = "
        INSERT INTO patient_push_tokens
        (
            patient_id,
            device_id,
            fcm_token,
            token_hash,
            platform,
            enabled,
            created_at,
            updated_at
        )

        VALUES (?, ?, ?, ?, ?, 1, NOW(), NOW())

        ON DUPLICATE KEY UPDATE

            patient_id = VALUES(patient_id),
            device_id = VALUES(device_id),
            fcm_token = VALUES(fcm_token),
            platform = VALUES(platform),
            enabled = 1,
            updated_at = NOW()
    ";

    $stmt = $conn->prepare($sql);

    $stmt->bind_param(
        'sssss',
        $patientId,
        $deviceId,
        $token,
        $tokenHash,
        $platform
    );

    $stmt->execute();

    $stmt->close();

    // --------------------------------------------------
    // 9. Success response
    // --------------------------------------------------

    sendJson(200, [
        'success' => true,
        'message' => 'Firebase notification token saved successfully.',
        'patient_id' => $patientId,
        'device_id' => $deviceId
    ]);

} catch (Throwable $e) {

    error_log(
        '[save_push_token.php] ' .
        get_class($e) . ': ' .
        $e->getMessage()
    );

    sendJson(500, [
        'success' => false,
        'message' => 'Server error while saving notification token. Check the PHP error log.'
    ]);
}
