<?php
/*
 * PATIENT MONITORING SYSTEM
 * File: save_push_token.php
 *
 * Receives Firebase Cloud Messaging browser token.
 * Saves token in patient_push_tokens.
 *
 * Security:
 * - Requires PATIENTSESSID.
 * - Revalidates patient account and device assignment.
 * - Checks account and device expiry dates.
 * - Does not accept patient_id or device_id from browser.
 * - Always returns JSON.
 */

ob_start();

ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

function jsonResponse($success, $message, $httpCode = 200)
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($httpCode);

    echo json_encode([
        'success' => $success,
        'message' => $message
    ]);

    exit;
}

try {

    // Only POST requests are accepted.
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        jsonResponse(false, 'POST request required.', 405);
    }

    // Start the existing patient session.
    if (session_status() === PHP_SESSION_NONE) {
        session_name('PATIENTSESSID');
        session_start();
    }

    // Verify patient login session.
    if (
        !isset($_SESSION['patient_logged_in']) ||
        $_SESSION['patient_logged_in'] !== true ||
        !isset($_SESSION['patient_user_id']) ||
        !isset($_SESSION['patient_device_id']) ||
        !isset($_SESSION['patient_id'])
    ) {
        jsonResponse(false, 'Patient login required. Please log in again.', 401);
    }

    $userId = (int) $_SESSION['patient_user_id'];
    $deviceId = trim((string) $_SESSION['patient_device_id']);
    $patientId = trim((string) $_SESSION['patient_id']);

    if ($userId <= 0 || $deviceId === '' || $patientId === '') {
        jsonResponse(false, 'Invalid patient session.', 401);
    }

    // Load existing database connection.
    require_once __DIR__ . '/config.php';

    if (!isset($conn) || !($conn instanceof mysqli)) {
        throw new Exception('Database connection is unavailable.');
    }

    $conn->set_charset('utf8mb4');

    // Read Firebase token from JSON request.
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);

    if (!is_array($input) || !isset($input['token'])) {
        jsonResponse(false, 'Firebase token is missing.', 400);
    }

    $fcmToken = trim((string) $input['token']);

    if (
        $fcmToken === '' ||
        strlen($fcmToken) < 20 ||
        strlen($fcmToken) > 8192
    ) {
        jsonResponse(false, 'Invalid Firebase token.', 400);
    }

    // Revalidate account, device, patient and validity dates.
    $now = date('Y-m-d H:i:s');

    $sql = "
        SELECT
            wu.id,
            wu.device_id,
            pd.patient_id
        FROM web_users wu
        INNER JOIN patient_devices pd
            ON pd.device_id = wu.device_id
        INNER JOIN patients p
            ON p.patient_id = pd.patient_id
        WHERE
            wu.id = ?
            AND wu.device_id = ?
            AND pd.patient_id = ?
            AND wu.role = 'patient'
            AND wu.enabled = 1
            AND (wu.valid_from IS NULL OR wu.valid_from <= ?)
            AND (wu.valid_until IS NULL OR wu.valid_until >= ?)
            AND pd.enabled = 1
            AND (pd.start_date_time IS NULL OR pd.start_date_time <= ?)
            AND (pd.end_date_time IS NULL OR pd.end_date_time >= ?)
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new Exception('Could not prepare patient validation query.');
    }

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

    if (!$stmt->execute()) {
        throw new Exception('Patient validation query failed.');
    }

    $result = $stmt->get_result();
    $validPatient = $result->fetch_assoc();
    $stmt->close();

    if (!$validPatient) {
        jsonResponse(
            false,
            'Patient account or device is disabled or expired.',
            403
        );
    }

    // Create a unique hash for the Firebase token.
    $tokenHash = hash('sha256', $fcmToken);

    // Save or update the browser token.
    $saveSql = "
        INSERT INTO patient_push_tokens
        (
            patient_id,
            device_id,
            fcm_token,
            token_hash,
            platform,
            enabled
        )
        VALUES (?, ?, ?, ?, 'ANDROID_CHROME', 1)
        ON DUPLICATE KEY UPDATE
            patient_id = VALUES(patient_id),
            device_id = VALUES(device_id),
            fcm_token = VALUES(fcm_token),
            platform = VALUES(platform),
            enabled = 1,
            updated_at = CURRENT_TIMESTAMP
    ";

    $saveStmt = $conn->prepare($saveSql);

    if (!$saveStmt) {
        throw new Exception('Could not prepare token storage query.');
    }

    $saveStmt->bind_param(
        'ssss',
        $patientId,
        $deviceId,
        $fcmToken,
        $tokenHash
    );

    if (!$saveStmt->execute()) {
        throw new Exception('Could not save Firebase token.');
    }

    $saveStmt->close();

    jsonResponse(true, 'Phone notification token registered successfully.');

} catch (Throwable $e) {

    error_log('save_push_token.php error: ' . $e->getMessage());

    jsonResponse(
        false,
        'Server error while saving notification token. Check the PHP error log.',
        500
    );
}
