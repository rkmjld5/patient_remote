<?php
/*
 * Temporary patient session diagnostic.
 * Delete this file after testing.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

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

echo json_encode([
    'session_started' => session_status() === PHP_SESSION_ACTIVE,
    'session_cookie_received' => isset($_COOKIE['PATIENTSESSID']),
    'patient_logged_in' => $_SESSION['patient_logged_in'] ?? null,
    'patient_user_id_exists' => isset($_SESSION['patient_user_id']),
    'patient_device_id_exists' => isset($_SESSION['patient_device_id']),
    'patient_id_exists' => isset($_SESSION['patient_id'])
], JSON_PRETTY_PRINT);

session_write_close();
