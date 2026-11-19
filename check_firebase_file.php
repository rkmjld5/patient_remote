<?php

$file = '/etc/secrets/firebase-service-account.json';

echo "<h3>Render Firebase Group Diagnostic</h3>";

echo "PHP effective user ID: ";

if (function_exists('posix_geteuid')) {
    $uid = posix_geteuid();
    echo $uid . "<br>";

    $user = posix_getpwuid($uid);
    echo "PHP process user: " .
         htmlspecialchars($user['name'] ?? 'Unknown') . "<br>";
} else {
    echo "POSIX extension unavailable<br>";
}

echo "<br><b>PHP process groups:</b><br>";

if (function_exists('posix_getgroups')) {
    $groups = posix_getgroups();

    foreach ($groups as $gid) {
        $group = posix_getgrgid($gid);
        echo "Group ID: $gid";

        if ($group) {
            echo " (" . htmlspecialchars($group['name']) . ")";
        }

        echo "<br>";
    }
} else {
    echo "Cannot inspect supplementary groups<br>";
}

echo "<br><b>Secret file:</b><br>";

echo "Exists: " . (file_exists($file) ? "YES" : "NO") . "<br>";
echo "Readable: " . (is_readable($file) ? "YES" : "NO") . "<br>";

if (file_exists($file)) {
    echo "Owner ID: " . fileowner($file) . "<br>";
    echo "Group ID: " . filegroup($file) . "<br>";
    echo "Permissions: " .
         substr(sprintf('%o', fileperms($file)), -4) . "<br>";
}

echo "<br><b>Read test:</b><br>";

$data = @file_get_contents($file);

if ($data === false) {
    echo "FAILED: PHP cannot read the file.";
} else {
    echo "SUCCESS: " . strlen($data) . " bytes read.";
}
