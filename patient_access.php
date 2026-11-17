<?php
/*
 * PATIENT SESSION AND ACCESS VALIDATION
 * Patient pages must never trust patient_id or device_id from a URL.
 */
date_default_timezone_set('Asia/Kolkata');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('PATIENTSESSID');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_strict_mode', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

require_once __DIR__ . '/config.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function require_patient_access(mysqli $conn): array {
    if (
        empty($_SESSION['patient_logged_in']) ||
        $_SESSION['patient_logged_in'] !== true ||
        empty($_SESSION['patient_user_id']) ||
        empty($_SESSION['patient_id']) ||
        empty($_SESSION['patient_device_id'])
    ) {
        header('Location: patient_login.php');
        exit;
    }

    $user_id = (int)$_SESSION['patient_user_id'];
    $patient_id = (string)$_SESSION['patient_id'];
    $device_id = (string)$_SESSION['patient_device_id'];

    if ($user_id <= 0 || $patient_id === '' || $device_id === '') {
        $_SESSION = [];
        header('Location: patient_login.php');
        exit;
    }

    $now = date('Y-m-d H:i:s');

    $sql = "
        SELECT
            u.id AS user_id,
            u.username,
            u.role,
            u.enabled AS account_enabled,
            u.valid_from,
            u.valid_until,
            d.device_id,
            d.patient_id,
            d.enabled AS device_enabled,
            d.start_date_time,
            d.end_date_time,
            p.full_name
        FROM web_users u
        INNER JOIN patient_devices d
            ON d.device_id = u.device_id
        INNER JOIN patients p
            ON p.patient_id = d.patient_id
        WHERE u.id = ?
          AND u.role = 'patient'
          AND u.device_id = ?
          AND d.device_id = ?
          AND d.patient_id = ?
          AND u.enabled = 1
          AND d.enabled = 1
          AND (u.valid_from IS NULL OR u.valid_from <= ?)
          AND (u.valid_until IS NULL OR u.valid_until >= ?)
          AND (d.start_date_time IS NULL OR d.start_date_time <= ?)
          AND (d.end_date_time IS NULL OR d.end_date_time >= ?)
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('isssssss', $user_id, $device_id, $device_id, $patient_id, $now, $now, $now, $now);
    $stmt->execute();
    $access = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$access) {
        // Destroy only the patient session when permission is revoked/expired.
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'] ?: '/',
                'domain' => $params['domain'] ?? '',
                'secure' => (bool)$params['secure'],
                'httponly' => (bool)$params['httponly'],
                'samesite' => $params['samesite'] ?? 'Lax'
            ]);
        }
        session_destroy();
        header('Location: patient_login.php?expired=1');
        exit;
    }

    return [
        'user_id' => (int)$access['user_id'],
        'patient_id' => $access['patient_id'],
        'device_id' => $access['device_id'],
        'full_name' => $access['full_name']
    ];
}

function patient_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>