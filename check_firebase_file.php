<?php

$file = '/etc/secrets/firebase-service-account.json';

echo "<h3>Firebase Permission Diagnostic</h3>";

echo "PHP user: " . get_current_user() . "<br>";
echo "File exists: " . (file_exists($file) ? "YES" : "NO") . "<br>";
echo "Readable: " . (is_readable($file) ? "YES" : "NO") . "<br>";

$perms = @fileperms($file);

echo "Permissions: ";
echo ($perms !== false)
    ? substr(sprintf('%o', $perms), -4)
    : "UNKNOWN";

echo "<br>";

$result = @file_get_contents($file);

echo "Read result: ";

if ($result === false) {
    echo "FAILED";
} else {
    echo "SUCCESS (" . strlen($result) . " bytes)";
}
