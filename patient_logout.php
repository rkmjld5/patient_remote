<?php
/*
 * PATIENT MONITORING SYSTEM
 * File: patient_logout.php
 *
 * Destroys only PATIENTSESSID. It does not log out the administrator
 * or modify the administrator's separate session.
 */
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

// Clear all patient-session data.
$_SESSION = [];

// Expire only the patient session cookie.
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

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Location: patient_login.php?logged_out=1');
exit;
?>