<?php

$file = '/etc/secrets/firebase-service-account.json';

echo "<h3>Firebase Secret File Diagnostic</h3>";

echo "Expected path: " . htmlspecialchars($file) . "<br>";

if (!file_exists($file)) {
    exit("ERROR: File does not exist on Render.");
}

echo "File exists: YES<br>";

if (!is_readable($file)) {
    exit("ERROR: PHP cannot read this file.");
}

echo "File readable: YES<br>";

$contents = file_get_contents($file);

echo "File size: " . strlen($contents) . " bytes<br>";

if (trim($contents) === '') {
    exit("ERROR: File exists but contents are EMPTY.");
}

$data = json_decode($contents, true);

if (!is_array($data)) {
    exit("ERROR: Invalid JSON. Check the file contents.");
}

echo "JSON valid: YES<br>";

if (
    empty($data['project_id']) ||
    empty($data['private_key']) ||
    empty($data['client_email'])
) {
    exit("ERROR: Required Firebase credentials are missing.");
}

echo "Firebase credentials: PRESENT<br>";
echo "Firebase secret file test PASSED.";
