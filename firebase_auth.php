
<?php
/*
 * PATIENT MONITORING SYSTEM
 * Firebase Authentication Helper
 *
 * File:
 * C:/xampp/private/firebase_auth.php
 *
 * PHP 8.x + cURL + OpenSSL
 * No Composer required.
 */

function firebaseBase64UrlEncode($data)
{
    return rtrim(
        strtr(base64_encode($data), '+/', '-_'),
        '='
    );
}

function getFirebaseAccessToken()
{
    $serviceAccountFile =
        'C:/xampp/private/firebase-service-account.json';

    if (!is_file($serviceAccountFile)) {
        throw new Exception(
            'Firebase service-account JSON file not found.'
        );
    }

    $json = file_get_contents($serviceAccountFile);

    if ($json === false) {
        throw new Exception(
            'Cannot read Firebase service-account JSON file.'
        );
    }

    $credentials = json_decode($json, true);

    if (
        !is_array($credentials) ||
        empty($credentials['client_email']) ||
        empty($credentials['private_key']) ||
        empty($credentials['token_uri'])
    ) {
        throw new Exception(
            'Invalid Firebase service-account credentials.'
        );
    }

    // Create JWT.

    $now = time();

    $header = array(
        'alg' => 'RS256',
        'typ' => 'JWT'
    );

    $payload = array(
        'iss' => $credentials['client_email'],
        'scope' =>
            'https://www.googleapis.com/auth/firebase.messaging',
        'aud' => $credentials['token_uri'],
        'iat' => $now,
        'exp' => $now + 3600
    );

    $encodedHeader = firebaseBase64UrlEncode(
        json_encode($header)
    );

    $encodedPayload = firebaseBase64UrlEncode(
        json_encode($payload)
    );

    $unsignedJwt =
        $encodedHeader . '.' . $encodedPayload;

    // Sign JWT.

    $privateKey = openssl_pkey_get_private(
        $credentials['private_key']
    );

    if ($privateKey === false) {
        throw new Exception(
            'Unable to load Firebase private key.'
        );
    }

    $signature = '';

    $signed = openssl_sign(
        $unsignedJwt,
        $signature,
        $privateKey,
        OPENSSL_ALGO_SHA256
    );

    if (!$signed) {
        throw new Exception(
            'Could not sign Firebase JWT.'
        );
    }

    $jwt = $unsignedJwt . '.' .
        firebaseBase64UrlEncode($signature);

    // Request Google OAuth access token.

    $postData = http_build_query(array(
        'grant_type' =>
            'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion' => $jwt
    ));

    $ch = curl_init($credentials['token_uri']);

    if ($ch === false) {
        throw new Exception(
            'Could not initialize cURL.'
        );
    }

    curl_setopt_array($ch, array(
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postData,
        CURLOPT_RETURNTRANSFER => true,

        CURLOPT_HTTPHEADER => array(
            'Content-Type: application/x-www-form-urlencoded'
        ),

        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 30,

        CURLOPT_CAINFO =>
            'C:/xampp/php/extras/ssl/cacert-avast.pem',

        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,

        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1
    ));

    $response = curl_exec($ch);

    $curlError = curl_error($ch);

    $httpCode = (int)curl_getinfo(
        $ch,
        CURLINFO_HTTP_CODE
    );

    curl_close($ch);

    if ($response === false) {
        throw new Exception(
            'Google OAuth connection failed: ' . $curlError
        );
    }

    $result = json_decode($response, true);

    if (!is_array($result)) {
        throw new Exception(
            'Google returned an invalid OAuth response.'
        );
    }

    if (
        $httpCode !== 200 ||
        empty($result['access_token'])
    ) {
        $message =
            $result['error_description']
            ?? $result['error']['message']
            ?? $result['error']
            ?? 'Google OAuth authentication failed.';

        if (is_array($message)) {
            $message = json_encode($message);
        }

        throw new Exception(
            'OAuth HTTP ' . $httpCode . ': ' . $message
        );
    }

    // Return token privately. Never print it.

    return $result['access_token'];
}
