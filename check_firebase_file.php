<?php

$file = '/etc/secrets/firebase-service-account.json';

echo "<h3>Firebase Credential Validation</h3>";

if (!file_exists($file) || !is_readable($file)) {
    exit("ERROR: Firebase file missing or unreadable.");
}

$json = file_get_contents($file);
$data = json_decode($json, true);

if (!is_array($data)) {
    exit("ERROR: Invalid JSON: " . json_last_error_msg());
}

echo "JSON syntax: VALID<br><br>";

$required = [
    'type',
    'project_id',
    'private_key',
    'client_email',
    'token_uri'
];

foreach ($required as $field) {
    $present = isset($data[$field]) &&
               is_string($data[$field]) &&
               trim($data[$field]) !== '';

    echo htmlspecialchars($field) . ": ";
    echo $present ? "PRESENT" : "MISSING";
    echo "<br>";
}

echo "<br>Account type: ";
echo htmlspecialchars($data['type'] ?? 'MISSING');

echo "<br>Project ID: ";
echo htmlspecialchars($data['project_id'] ?? 'MISSING');

echo "<br>Expected project ID: patientmonitoringsystem-62f35";

echo "<br>Project ID matches: ";
echo (($data['project_id'] ?? '') ===
      'patientmonitoringsystem-62f35') ? "YES" : "NO";

echo "<br>Private key format: ";

$key = $data['private_key'] ?? '';

if (is_string($key) &&
    strpos($key, '-----BEGIN PRIVATE KEY-----') !== false &&
    strpos($key, '-----END PRIVATE KEY-----') !== false) {
    echo "PEM MARKERS PRESENT";
} else {
    echo "INVALID OR MISSING";
}

echo "<br>OpenSSL validation: ";

if (is_string($key) && function_exists('openssl_pkey_get_private')) {
    $privateKey = @openssl_pkey_get_private($key);

    echo $privateKey !== false ? "VALID" : "FAILED";

    if ($privateKey !== false) {
        openssl_pkey_free($privateKey);
    }
} else {
    echo "NOT AVAILABLE";
}
