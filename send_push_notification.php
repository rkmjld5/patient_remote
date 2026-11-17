<?php
/*
 * PATIENT MONITORING SYSTEM
 * File: send_push_notification.php
 *
 * Secure Firebase HTTP v1 test notification.
 *
 * Sends a test notification only to the registered
 * phone tokens of the currently logged-in patient.
 *
 * Uses:
 *   config.php
 *   patient_push_tokens
 *   patient_devices
 *   web_users
 *   patients
 *
 * Service account:
 *   C:\xampp\private\firebase-service-account.json
 *
 * This is a test sender. Automatic alert integration
 * will be added separately after successful testing.
 */

declare(strict_types=1);

date_default_timezone_set('Asia/Kolkata');

// --------------------------------------------------
// 1. PATIENT SESSION
// --------------------------------------------------

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

require_once __DIR__ . '/config.php';

// --------------------------------------------------
// 2. RESPONSE HELPER
// --------------------------------------------------

function outputPage(string $message, bool $success = false): void
{
    $color = $success ? '#166534' : '#991b1b';

    echo '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Patient Push Notification Test</title>
<style>
body {
    font-family: Arial, sans-serif;
    background: #f1f5f9;
    padding: 25px;
}
.box {
    max-width: 600px;
    margin: 35px auto;
    padding: 25px;
    background: white;
    border-radius: 10px;
    box-shadow: 0 2px 8px #0002;
}
button {
    padding: 13px 20px;
    background: #15803d;
    color: white;
    border: 0;
    border-radius: 6px;
    cursor: pointer;
    font-size: 16px;
}
.message {
    padding: 14px;
    background: #f1f5f9;
    border-left: 5px solid ' . $color . ';
    overflow-wrap: anywhere;
}
a {
    display: inline-block;
    margin-top: 20px;
}
</style>
</head>
<body>
<div class="box">
<h2>Patient Monitoring - Push Test</h2>
<div class="message">'
. htmlspecialchars($message, ENT_QUOTES, 'UTF-8') .
'</div>
<a href="index.php">Return to Patient Dashboard</a>
</div>
</body>
</html>';

    exit;
}

// --------------------------------------------------
// 3. VERIFY PATIENT SESSION
// --------------------------------------------------

if (
    !isset($_SESSION['patient_logged_in']) ||
    $_SESSION['patient_logged_in'] !== true ||
    !isset(
        $_SESSION['patient_user_id'],
        $_SESSION['patient_device_id'],
        $_SESSION['patient_id']
    )
) {
    http_response_code(401);
    outputPage('Please log in to the patient dashboard first.');
}

$userId = (int)$_SESSION['patient_user_id'];
$deviceId = (string)$_SESSION['patient_device_id'];
$patientId = (string)$_SESSION['patient_id'];

if ($userId <= 0 || $deviceId === '' || $patientId === '') {
    http_response_code(401);
    outputPage('Invalid patient session.');
}

// --------------------------------------------------
// 4. VERIFY ACCOUNT AND DEVICE
// --------------------------------------------------

$now = date('Y-m-d H:i:s');

$sql = "
    SELECT wu.id
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
        AND (wu.valid_from IS NULL OR wu.valid_from <= ?)
        AND (wu.valid_until IS NULL OR wu.valid_until >= ?)
        AND pd.enabled = 1
        AND (pd.start_date_time IS NULL OR pd.start_date_time <= ?)
        AND (pd.end_date_time IS NULL OR pd.end_date_time >= ?)
    LIMIT 1
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    error_log('Push test: patient verification prepare failed.');
    http_response_code(500);
    outputPage('Unable to verify patient access.');
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

$stmt->execute();
$stmt->store_result();

$valid = ($stmt->num_rows === 1);

$stmt->close();

if (!$valid) {
    http_response_code(403);
    outputPage('Patient account or device assignment is not valid.');
}

// --------------------------------------------------
// 5. CSRF TOKEN
// --------------------------------------------------

if (empty($_SESSION['push_test_csrf'])) {
    $_SESSION['push_test_csrf'] = bin2hex(random_bytes(32));
}

// --------------------------------------------------
// 6. SHOW TEST FORM ON GET
// --------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    $csrf = htmlspecialchars(
        $_SESSION['push_test_csrf'],
        ENT_QUOTES,
        'UTF-8'
    );

    echo '<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Patient Push Notification Test</title>
<style>
body {
    font-family: Arial, sans-serif;
    background: #f1f5f9;
    padding: 25px;
}
.box {
    max-width: 600px;
    margin: 35px auto;
    padding: 25px;
    background: white;
    border-radius: 10px;
    box-shadow: 0 2px 8px #0002;
}
button {
    padding: 13px 20px;
    background: #15803d;
    color: white;
    border: 0;
    border-radius: 6px;
    cursor: pointer;
    font-size: 16px;
}
</style>
</head>
<body>
<div class="box">
<h2>Patient Monitoring - Push Test</h2>
<p>Patient ID: ' .
htmlspecialchars($patientId, ENT_QUOTES, 'UTF-8') .
'</p>
<p>Device ID: ' .
htmlspecialchars($deviceId, ENT_QUOTES, 'UTF-8') .
'</p>
<p>This sends one test notification to this patient\'s enabled registered phone tokens.</p>
<form method="POST">
<input type="hidden" name="csrf" value="' . $csrf . '">
<button type="submit">Send Test Notification</button>
</form>
<p><a href="index.php">Return to Dashboard</a></p>
</div>
</body>
</html>';

    exit;
}

// --------------------------------------------------
// 7. ACCEPT ONLY POST
// --------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    outputPage('Method not allowed.');
}

if (
    !isset($_POST['csrf']) ||
    !hash_equals(
        $_SESSION['push_test_csrf'],
        (string)$_POST['csrf']
    )
) {
    http_response_code(403);
    outputPage('Security verification failed. Reload the page.');
}

// --------------------------------------------------
// 8. LOAD FIREBASE SERVICE ACCOUNT
// --------------------------------------------------

$serviceFile =
    'C:\\xampp\\private\\firebase-service-account.json';

if (!is_file($serviceFile) || !is_readable($serviceFile)) {
    error_log('Push test: Firebase service account file unavailable.');
    http_response_code(500);
    outputPage('Firebase service-account file was not found or cannot be read.');
}

$serviceJson = file_get_contents($serviceFile);

$service = json_decode($serviceJson, true);

if (
    !is_array($service) ||
    empty($service['project_id']) ||
    empty($service['client_email']) ||
    empty($service['private_key']) ||
    empty($service['token_uri'])
) {
    error_log('Push test: Firebase service-account JSON is incomplete.');
    http_response_code(500);
    outputPage('Firebase service-account JSON is invalid or incomplete.');
}

$expectedProject = 'patientmonitoringsystem-62f35';

if ($service['project_id'] !== $expectedProject) {
    error_log('Push test: Firebase project ID mismatch.');
    http_response_code(500);
    outputPage('The service-account key belongs to a different Firebase project.');
}

// --------------------------------------------------
// 9. CHECK REQUIRED PHP EXTENSIONS
// --------------------------------------------------

if (!function_exists('curl_init')) {
    http_response_code(500);
    outputPage('PHP cURL extension is not enabled in XAMPP.');
}

if (!function_exists('openssl_sign')) {
    http_response_code(500);
    outputPage('PHP OpenSSL extension is not enabled in XAMPP.');
}

// Use the CA certificate bundle already present in this XAMPP installation.
// SSL certificate verification remains enabled.
$caBundle = 'C:/xampp/php/extras/ssl/cacert-avast.pem';

if (!is_file($caBundle) || !is_readable($caBundle)) {
    http_response_code(500);
    outputPage('The SSL CA certificate file is missing or unreadable: ' . $caBundle);
}

// --------------------------------------------------
// 10. JWT HELPERS
// --------------------------------------------------

function base64UrlEncode(string $data): string
{
    return rtrim(
        strtr(base64_encode($data), '+/', '-_'),
        '='
    );
}

function postJson(string $url, array $headers, array $data, string $caBundle): array
{
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($data),
        CURLOPT_CAINFO => $caBundle,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 30
    ]);

    $response = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);

    curl_close($ch);

    if ($response === false) {
        throw new RuntimeException('Network request failed: ' . $error);
    }

    $decoded = json_decode($response, true);

    return [
        'status' => $status,
        'body' => is_array($decoded) ? $decoded : [],
        'raw' => $response
    ];
}

// --------------------------------------------------
// 11. CREATE GOOGLE OAUTH ACCESS TOKEN
// --------------------------------------------------

try {

    $issuedAt = time();

    $jwtHeader = [
        'alg' => 'RS256',
        'typ' => 'JWT'
    ];

    $jwtClaims = [
        'iss' => $service['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud' => $service['token_uri'],
        'iat' => $issuedAt,
        'exp' => $issuedAt + 3600
    ];

    $unsignedJwt =
        base64UrlEncode(json_encode($jwtHeader)) . '.' .
        base64UrlEncode(json_encode($jwtClaims));

    $privateKey = openssl_pkey_get_private(
        $service['private_key']
    );

    if (!$privateKey) {
        throw new RuntimeException('Could not load Firebase private key.');
    }

    $signature = '';

    $signed = openssl_sign(
        $unsignedJwt,
        $signature,
        $privateKey,
        OPENSSL_ALGO_SHA256
    );

    openssl_free_key($privateKey);

    if (!$signed) {
        throw new RuntimeException('Could not sign Firebase authentication token.');
    }

    $jwt = $unsignedJwt . '.' . base64UrlEncode($signature);

    // OAuth token exchange uses form data, not JSON.
    $ch = curl_init($service['token_uri']);

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded'
        ],
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt
        ]),
        CURLOPT_CAINFO => $caBundle,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 30
    ]);

    $oauthResponse = curl_exec($ch);
    $oauthStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $oauthError = curl_error($ch);

    curl_close($ch);

    if ($oauthResponse === false) {
        throw new RuntimeException('Google authentication network error: ' . $oauthError);
    }

    $oauthData = json_decode($oauthResponse, true);

    if (
        $oauthStatus < 200 ||
        $oauthStatus >= 300 ||
        empty($oauthData['access_token'])
    ) {
        error_log('Push test OAuth failure: HTTP ' . $oauthStatus . ' ' . $oauthResponse);
        throw new RuntimeException('Google rejected Firebase authentication. Check the service-account key and permissions.');
    }

    $accessToken = $oauthData['access_token'];

    // --------------------------------------------------
    // 12. GET ENABLED TOKENS FOR THIS PATIENT AND DEVICE
    // --------------------------------------------------

    $tokenStmt = $conn->prepare("
        SELECT fcm_token
        FROM patient_push_tokens
        WHERE
            patient_id = ?
            AND device_id = ?
            AND enabled = 1
    ");

    if (!$tokenStmt) {
        throw new RuntimeException('Could not prepare push-token query.');
    }

    $tokenStmt->bind_param('ss', $patientId, $deviceId);
    $tokenStmt->execute();
    $tokenStmt->bind_result($fcmToken);

    $tokens = [];

    while ($tokenStmt->fetch()) {
        if (!empty($fcmToken)) {
            $tokens[] = $fcmToken;
        }
    }

    $tokenStmt->close();

    if (count($tokens) === 0) {
        outputPage('No enabled phone registration was found for this patient and device.');
    }

    // --------------------------------------------------
    // 13. SEND TEST NOTIFICATION
    // --------------------------------------------------

    $successCount = 0;
    $failureCount = 0;

    $fcmUrl =
        'https://fcm.googleapis.com/v1/projects/' .
        rawurlencode($service['project_id']) .
        '/messages:send';

    foreach ($tokens as $token) {

        $message = [
            'message' => [
                'token' => $token,
                'notification' => [
                    'title' => 'Patient Monitoring Test',
                    'body' => 'SUCCESS! Firebase push notification delivery test for patient ' . $patientId . '.'
                ],
                'data' => [
                    'type' => 'TEST',
                    'title' => 'Patient Monitoring Test',
                    'body' => 'Firebase push notification delivery test.',
                    'patient_id' => $patientId
                ],
                'webpush' => [
                    'notification' => [
                        'icon' => 'https://www.gstatic.com/firebasejs/ui/2.0.0/images/auth/firebase-logo.png',
                        'tag' => 'patient-monitor-test'
                    ]
                ]
            ]
        ];

        $result = postJson(
            $fcmUrl,
            [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json'
            ],
            $message,
            $caBundle
        );

        if ($result['status'] >= 200 && $result['status'] < 300) {

            $successCount++;

        } else {

            $failureCount++;

            // Do not log the actual FCM registration token.
            error_log(
                'Push test FCM failure: HTTP ' .
                $result['status'] . ' ' .
                $result['raw']
            );
        }
    }

    if ($successCount > 0) {

        outputPage(
            'Firebase accepted the test notification for ' .
            $successCount .
            ' registered phone(s). Please check your Android notification panel. ' .
            'Failed sends: ' . $failureCount . '.',
            true
        );

    }

    throw new RuntimeException(
        'Firebase did not accept the test notification. Check the PHP error log.'
    );

} catch (Throwable $e) {

    error_log(
        'Push notification test error: ' . $e->getMessage()
    );

    http_response_code(500);

    outputPage(
        'Test notification failed: ' . $e->getMessage()
    );
}
