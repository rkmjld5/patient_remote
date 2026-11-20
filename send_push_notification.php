<?php
/*
 * PATIENT MONITORING SYSTEM
 * File: send_push_notification.php
 *
 * Render / TiDB version
 * Firebase HTTP v1 test notification
 *
 * Service account:
 * /etc/secrets/firebase-service-account.json
 *
 * Features:
 * - Authenticated patient session
 * - Patient and device validity checks
 * - CSRF protection
 * - Firebase OAuth JWT authentication
 * - Sends test notification to all enabled patient tokens
 * - Detailed Firebase error logging
 * - Data-only FCM messages
 */

declare(strict_types=1);

date_default_timezone_set('Asia/Kolkata');

// --------------------------------------------------
// 1. SESSION CONFIGURATION
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
// 2. FIREBASE CONFIGURATION
// --------------------------------------------------

$expectedProject = 'patientmonitoringsystem-7fb4a';

$serviceFile = '/etc/secrets/firebase-service-account.json';

// --------------------------------------------------
// 3. OUTPUT HTML PAGE
// --------------------------------------------------

function outputPage(
    string $message,
    bool $success = false
): void {

    if (!headers_sent()) {

        http_response_code($success ? 200 : 400);

        header('Content-Type: text/html; charset=utf-8');

        header('Cache-Control: no-store');
    }

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

.message {
    padding: 14px;
    background: #f1f5f9;
    border-left: 5px solid ' . $color . ';
    overflow-wrap: anywhere;
    line-height: 1.6;
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
// 4. BASE64 URL ENCODING
// --------------------------------------------------

function base64UrlEncode(string $data): string
{
    return rtrim(
        strtr(base64_encode($data), '+/', '-_'),
        '='
    );
}

// --------------------------------------------------
// 5. HTTP JSON POST FUNCTION
// --------------------------------------------------

function postJson(
    string $url,
    array $headers,
    array $data
): array {

    $json = json_encode($data);

    if ($json === false) {

        throw new RuntimeException(
            'Could not encode JSON request.'
        );
    }

    $ch = curl_init($url);

    if ($ch === false) {

        throw new RuntimeException(
            'Could not initialize HTTP connection.'
        );
    }

    curl_setopt_array($ch, [

        CURLOPT_POST => true,

        CURLOPT_RETURNTRANSFER => true,

        CURLOPT_HTTPHEADER => $headers,

        CURLOPT_POSTFIELDS => $json,

        CURLOPT_SSL_VERIFYPEER => true,

        CURLOPT_SSL_VERIFYHOST => 2,

        CURLOPT_CONNECTTIMEOUT => 15,

        CURLOPT_TIMEOUT => 30

    ]);

    $response = curl_exec($ch);

    $status = (int)curl_getinfo(
        $ch,
        CURLINFO_HTTP_CODE
    );

    $error = curl_error($ch);

    curl_close($ch);

    if ($response === false) {

        throw new RuntimeException(
            'HTTP network error: ' . $error
        );
    }

    return [

        'status' => $status,

        'raw' => $response,

        'body' => json_decode($response, true)

    ];
}

// --------------------------------------------------
// 6. MAIN PROGRAM
// --------------------------------------------------

try {

    // ----------------------------------------------
    // A. VERIFY PATIENT SESSION
    // ----------------------------------------------

    if (
        ($_SESSION['patient_logged_in'] ?? false) !== true ||
        empty($_SESSION['patient_user_id']) ||
        empty($_SESSION['patient_device_id']) ||
        empty($_SESSION['patient_id'])
    ) {

        http_response_code(401);

        outputPage(
            'Please log in to the patient dashboard first.'
        );
    }

    $userId = (int)$_SESSION['patient_user_id'];

    $deviceId = (string)$_SESSION['patient_device_id'];

    $patientId = (string)$_SESSION['patient_id'];

    if (
        $userId <= 0 ||
        $deviceId === '' ||
        $patientId === ''
    ) {

        http_response_code(401);

        outputPage('Invalid patient session.');
    }

    // ----------------------------------------------
    // B. VERIFY DATABASE CONNECTION
    // ----------------------------------------------

    if (
        !isset($conn) ||
        !($conn instanceof mysqli)
    ) {

        throw new RuntimeException(
            'Database connection unavailable.'
        );
    }

    // ----------------------------------------------
    // C. REVALIDATE PATIENT AND DEVICE
    // ----------------------------------------------

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

    if (!$stmt) {

        throw new RuntimeException(
            'Could not prepare patient validation query.'
        );
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

        outputPage(
            'Patient account or device assignment is disabled or expired.'
        );
    }

    // ----------------------------------------------
    // D. CREATE CSRF TOKEN
    // ----------------------------------------------

    if (empty($_SESSION['push_test_csrf'])) {

        $_SESSION['push_test_csrf'] =
            bin2hex(random_bytes(32));
    }

    // ----------------------------------------------
    // E. DISPLAY TEST FORM
    // ----------------------------------------------

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

<meta name="viewport"
content="width=device-width, initial-scale=1">

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

<p>Patient ID: '
. htmlspecialchars($patientId, ENT_QUOTES, 'UTF-8') .
'</p>

<p>Device ID: '
. htmlspecialchars($deviceId, ENT_QUOTES, 'UTF-8') .
'</p>

<p>
Send a test notification to this patient\'s enabled registered phones.
</p>

<form method="POST">

<input
    type="hidden"
    name="csrf"
    value="' . $csrf . '"
>

<button type="submit">
Send Test Notification
</button>

</form>

<p>
<a href="index.php">Return to Dashboard</a>
</p>

</div>

</body>

</html>';

        exit;
    }

    // ----------------------------------------------
    // F. ACCEPT ONLY POST REQUEST
    // ----------------------------------------------

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {

        http_response_code(405);

        outputPage('POST request required.');
    }

    // ----------------------------------------------
    // G. VERIFY CSRF TOKEN
    // ----------------------------------------------

    if (
        !isset($_POST['csrf']) ||
        !hash_equals(
            $_SESSION['push_test_csrf'],
            (string)$_POST['csrf']
        )
    ) {

        http_response_code(403);

        outputPage(
            'Security verification failed. Reload the page.'
        );
    }

    // ----------------------------------------------
    // H. LOAD FIREBASE SERVICE ACCOUNT
    // ----------------------------------------------

    if (
        !is_file($serviceFile) ||
        !is_readable($serviceFile)
    ) {

        throw new RuntimeException(
            'Firebase service-account secret file is unavailable.'
        );
    }

    $serviceJson = file_get_contents($serviceFile);

    if ($serviceJson === false) {

        throw new RuntimeException(
            'Could not read Firebase service-account file.'
        );
    }

    $service = json_decode($serviceJson, true);

    if (
        !is_array($service) ||
        empty($service['project_id']) ||
        empty($service['client_email']) ||
        empty($service['private_key']) ||
        empty($service['token_uri'])
    ) {

        throw new RuntimeException(
            'Firebase service-account JSON is incomplete.'
        );
    }

    // ----------------------------------------------
    // I. VERIFY FIREBASE PROJECT
    // ----------------------------------------------

    if (
        $service['project_id'] !== $expectedProject
    ) {

        throw new RuntimeException(
            'Firebase service-account project ID mismatch.'
        );
    }

    // ----------------------------------------------
    // J. CHECK REQUIRED PHP EXTENSIONS
    // ----------------------------------------------

    if (!function_exists('curl_init')) {

        throw new RuntimeException(
            'PHP cURL is not enabled.'
        );
    }

    if (!function_exists('openssl_sign')) {

        throw new RuntimeException(
            'PHP OpenSSL is not enabled.'
        );
    }

    // ----------------------------------------------
    // K. READ ENABLED FCM TOKENS
    // ----------------------------------------------

    $tokenStmt = $conn->prepare("
        SELECT fcm_token

        FROM patient_push_tokens

        WHERE patient_id = ?

          AND device_id = ?

          AND enabled = 1
    ");

    if (!$tokenStmt) {

        throw new RuntimeException(
            'Could not prepare push-token query.'
        );
    }

    $tokenStmt->bind_param(
        'ss',
        $patientId,
        $deviceId
    );

    $tokenStmt->execute();

    $tokenStmt->bind_result($fcmToken);

    $tokens = [];

    while ($tokenStmt->fetch()) {

        if (
            is_string($fcmToken) &&
            $fcmToken !== ''
        ) {

            $tokens[] = $fcmToken;
        }
    }

    $tokenStmt->close();

    if (count($tokens) === 0) {

        http_response_code(404);

        outputPage(
            'No enabled phone registration was found. Please enable phone notifications from the dashboard first.'
        );
    }

    // ----------------------------------------------
    // L. CREATE GOOGLE OAUTH JWT
    // ----------------------------------------------

    $issuedAt = time();

    $headerJson = json_encode([

        'alg' => 'RS256',

        'typ' => 'JWT'

    ]);

    $claimsJson = json_encode([

        'iss' => $service['client_email'],

        'scope' =>
            'https://www.googleapis.com/auth/firebase.messaging',

        'aud' => $service['token_uri'],

        'iat' => $issuedAt,

        'exp' => $issuedAt + 3600

    ]);

    if (
        $headerJson === false ||
        $claimsJson === false
    ) {

        throw new RuntimeException(
            'Could not create OAuth JWT.'
        );
    }

    $unsignedJwt =
        base64UrlEncode($headerJson) .
        '.' .
        base64UrlEncode($claimsJson);

    $privateKey = openssl_pkey_get_private(
        $service['private_key']
    );

    if (!$privateKey) {

        throw new RuntimeException(
            'Could not load Firebase private key. Check the Render secret file.'
        );
    }

    $signature = '';

    $signed = openssl_sign(
        $unsignedJwt,
        $signature,
        $privateKey,
        OPENSSL_ALGO_SHA256
    );

    // Do not call openssl_free_key().
    // PHP releases the key resource automatically.

    if (!$signed) {

        throw new RuntimeException(
            'Could not sign OAuth JWT.'
        );
    }

    $jwt =
        $unsignedJwt .
        '.' .
        base64UrlEncode($signature);

    // ----------------------------------------------
    // M. EXCHANGE JWT FOR GOOGLE ACCESS TOKEN
    // ----------------------------------------------

    // Only ONE OAuth request is made here.
    // The JWT bearer grant is sent as form data.

    $ch = curl_init($service['token_uri']);

    if ($ch === false) {

        throw new RuntimeException(
            'Could not initialize Google OAuth connection.'
        );
    }

    curl_setopt_array($ch, [

        CURLOPT_POST => true,

        CURLOPT_RETURNTRANSFER => true,

        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded'
        ],

        CURLOPT_POSTFIELDS => http_build_query([

            'grant_type' =>
                'urn:ietf:params:oauth:grant-type:jwt-bearer',

            'assertion' => $jwt

        ]),

        CURLOPT_SSL_VERIFYPEER => true,

        CURLOPT_SSL_VERIFYHOST => 2,

        CURLOPT_CONNECTTIMEOUT => 15,

        CURLOPT_TIMEOUT => 30

    ]);

    $oauthResponse = curl_exec($ch);

    $oauthStatus = (int)curl_getinfo(
        $ch,
        CURLINFO_HTTP_CODE
    );

    $oauthError = curl_error($ch);

    curl_close($ch);

    if ($oauthResponse === false) {

        throw new RuntimeException(
            'Google authentication network error: ' .
            $oauthError
        );
    }

    $oauthData = json_decode(
        $oauthResponse,
        true
    );

    if (
        $oauthStatus < 200 ||
        $oauthStatus >= 300 ||
        !is_array($oauthData) ||
        empty($oauthData['access_token'])
    ) {

        error_log(
            'Firebase OAuth failure HTTP ' .
            $oauthStatus .
            ': ' .
            $oauthResponse
        );

        throw new RuntimeException(
            'Google rejected Firebase authentication. HTTP ' .
            $oauthStatus .
            '. Check Render logs.'
        );
    }

    $accessToken = $oauthData['access_token'];

    // ----------------------------------------------
    // N. FIREBASE HTTP V1 URL
    // ----------------------------------------------

    $fcmUrl =
        'https://fcm.googleapis.com/v1/projects/' .
        rawurlencode($expectedProject) .
        '/messages:send';

    // ----------------------------------------------
    // O. SEND DATA-ONLY TEST NOTIFICATIONS
    // ----------------------------------------------

    $successCount = 0;

    $failureCount = 0;

    $failureDetails = [];

    foreach ($tokens as $token) {

        $message = [

            'message' => [

                'token' => $token,

                // Data-only message.
                // The service worker displays
                // background notifications.

                'data' => [

                    'type' => 'TEST',

                    'title' => 'Patient Monitoring Test',

                    'body' =>
                        'SUCCESS! Firebase push notification test for patient ' .
                        $patientId . '.',

                    'patient_id' => $patientId,

                    'url' => '/index.php',

                    'alert_id' =>
                        'test-' . bin2hex(random_bytes(6))

                ],

                'webpush' => [

                    'headers' => [

                        'Urgency' => 'high'

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

            $message

        );

        if (
            $result['status'] >= 200 &&
            $result['status'] < 300
        ) {

            $successCount++;

        } else {

            $failureCount++;

            $errorData = $result['body'];

            $firebaseMessage =
                'No error description returned.';

            $firebaseStatus = '';

            if (
                is_array($errorData) &&
                isset($errorData['error']['message'])
            ) {

                $firebaseMessage =
                    (string)$errorData['error']['message'];
            }

            if (
                is_array($errorData) &&
                isset($errorData['error']['status'])
            ) {

                $firebaseStatus =
                    (string)$errorData['error']['status'];
            }

            $detail =
                'HTTP ' . $result['status'];

            if ($firebaseStatus !== '') {

                $detail .=
                    ' (' . $firebaseStatus . ')';
            }

            $detail .= ': ' . $firebaseMessage;

            $failureDetails[] = $detail;

            // Never log the FCM registration token.

            error_log(
                'Firebase FCM rejection: ' . $detail
            );
        }
    }

    // ----------------------------------------------
    // P. DISPLAY RESULT
    // ----------------------------------------------

    if ($successCount > 0) {

        outputPage(

            'Firebase accepted ' .
            $successCount .
            ' test notification(s). Registered tokens: ' .
            count($tokens) .
            '. Failed sends: ' .
            $failureCount .
            '. Please check your phone notification panel.',

            true

        );
    }

    throw new RuntimeException(

        'Firebase rejected all ' .
        count($tokens) .
        ' registered token(s). Details: ' .
        implode(' | ', $failureDetails)

    );

} catch (Throwable $e) {

    // ----------------------------------------------
    // Q. ERROR LOGGING
    // ----------------------------------------------

    error_log(
        'Push notification test error: ' .
        $e->getMessage()
    );

    http_response_code(500);

    outputPage(
        'Test notification failed. Please check the Render application logs for the technical error.'
    );
}
