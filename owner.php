<?php
/*
 * owner.php
 * Patient Monitoring System
 * Administrator-only page
 * Local XAMPP / PHP / MySQL
 */

session_start();
date_default_timezone_set('Asia/Kolkata');

require_once 'config.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    die("Database connection not available. Please check config.php");
}

$conn->set_charset("utf8mb4");
$conn->query("SET time_zone = '+05:30'");

/* =========================================================
   HELPER FUNCTIONS
========================================================= */

function h($value) {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function postString($name, $default = '') {
    return isset($_POST[$name]) ? trim((string)$_POST[$name]) : $default;
}

function validDateTime($value) {
    if ($value === '') {
        return true;
    }

    $date = DateTime::createFromFormat('!Y-m-d\TH:i', $value);
    $errors = DateTime::getLastErrors();

    if ($date === false) {
        return false;
    }

    if (
        $errors !== false &&
        ($errors['warning_count'] > 0 || $errors['error_count'] > 0)
    ) {
        return false;
    }

    return $date->format('Y-m-d\TH:i') === $value;
}

function htmlDateTime($value) {
    if (!$value) {
        return '';
    }

    $timestamp = strtotime($value);

    return $timestamp !== false ? date('Y-m-d\TH:i', $timestamp) : '';
}

function sqlDateTime($value) {
    if ($value === '') {
        return null;
    }

    return str_replace('T', ' ', $value) . ':00';
}

function setMessage($type, $message) {
    $_SESSION['owner_message'] = [
        'type' => $type,
        'message' => $message
    ];
}

function redirectOwner() {
    header("Location: owner.php");
    exit;
}

function prepareOrFail($conn, $sql) {
    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        setMessage('error', 'Database error: ' . $conn->error);
        redirectOwner();
    }

    return $stmt;
}

/* =========================================================
   ADMINISTRATOR SESSION CHECK
========================================================= */

if (
    empty($_SESSION['owner_logged_in']) ||
    empty($_SESSION['owner_id'])
) {
    header("Location: login.php");
    exit;
}

/* =========================================================
   CSRF PROTECTION
========================================================= */

if (empty($_SESSION['owner_csrf'])) {
    $_SESSION['owner_csrf'] = bin2hex(random_bytes(32));
}

$csrfToken = $_SESSION['owner_csrf'];

/* =========================================================
   AUTOMATIC EXPIRY
========================================================= */

$conn->query("
    UPDATE patient_devices
    SET enabled = 0
    WHERE enabled = 1
      AND end_date_time IS NOT NULL
      AND end_date_time <> '0000-00-00 00:00:00'
      AND end_date_time <= NOW()
");

$conn->query("
    UPDATE web_users
    SET enabled = 0
    WHERE role = 'patient'
      AND enabled = 1
      AND valid_until IS NOT NULL
      AND valid_until <> '0000-00-00 00:00:00'
      AND valid_until <= NOW()
");

/* =========================================================
   PROCESS FORM ACTIONS
========================================================= */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (
        !isset($_POST['csrf_token']) ||
        !hash_equals($csrfToken, (string)$_POST['csrf_token'])
    ) {
        setMessage('error', 'Invalid request. Please try again.');
        redirectOwner();
    }

    $action = postString('action');

    /* -----------------------------------------------------
       REGISTER PATIENT - MANUAL PATIENT ID
       contact = mobile number
       email   = patient email address
    ----------------------------------------------------- */

    if ($action === 'add_patient') {

        $patient_id = postString('patient_id');
        $full_name = postString('full_name');
        $age_input = postString('age');
        $gender = postString('gender');
        $blood_group = postString('blood_group');
        $contact = postString('contact');
        $email = strtolower(postString('email'));
        $notes = postString('notes');

        if ($patient_id === '') {
            setMessage('error', 'Patient ID is required.');
            redirectOwner();
        }

        if (strlen($patient_id) > 30) {
            setMessage('error', 'Patient ID cannot exceed 30 characters.');
            redirectOwner();
        }

        if ($full_name === '') {
            setMessage('error', 'Patient name is required.');
            redirectOwner();
        }

        if (
            $age_input !== '' &&
            (!ctype_digit($age_input) || (int)$age_input > 120)
        ) {
            setMessage('error', 'Please enter a valid patient age.');
            redirectOwner();
        }

        $allowed_genders = ['', 'Male', 'Female', 'Other'];

        if (!in_array($gender, $allowed_genders, true)) {
            setMessage('error', 'Invalid gender selection.');
            redirectOwner();
        }

        if (
            $email === '' ||
            strlen($email) > 150 ||
            !filter_var($email, FILTER_VALIDATE_EMAIL)
        ) {
            setMessage('error', 'Please enter a valid patient email address.');
            redirectOwner();
        }

        $age = ($age_input === '') ? null : (int)$age_input;

        /* Check duplicate Patient ID */

        $duplicate = prepareOrFail($conn, "
            SELECT patient_id
            FROM patients
            WHERE patient_id = ?
            LIMIT 1
        ");

        $duplicate->bind_param("s", $patient_id);
        $duplicate->execute();

        $already_exists = $duplicate->get_result()->num_rows > 0;
        $duplicate->close();

        if ($already_exists) {
            setMessage('error', 'This Patient ID is already registered. Please enter a different ID.');
            redirectOwner();
        }

        $stmt = prepareOrFail($conn, "
            INSERT INTO patients
            (
                patient_id,
                full_name,
                age,
                gender,
                blood_group,
                contact,
                email,
                notes
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->bind_param(
            "ssisssss",
            $patient_id,
            $full_name,
            $age,
            $gender,
            $blood_group,
            $contact,
            $email,
            $notes
        );

        if ($stmt->execute()) {
            setMessage(
                'success',
                'Patient registered successfully. Patient ID: ' . $patient_id
            );
        } else {
            if ($stmt->errno === 1062) {
                setMessage('error', 'This Patient ID is already registered.');
            } else {
                setMessage('error', 'Unable to register patient: ' . $stmt->error);
            }
        }

        $stmt->close();
        redirectOwner();
    }

    /* -----------------------------------------------------
       ASSIGN OR UPDATE DEVICE
    ----------------------------------------------------- */

    if ($action === 'assign_device' || $action === 'update_device') {

        $device_id = postString('device_id');
        $patient_id = postString('patient_id');
        $description = postString('description');

        $start_date_time = postString('start_date_time');
        $end_date_time = postString('end_date_time');

        if (
            $device_id === '' ||
            strlen($device_id) > 32 ||
            $patient_id === '' ||
            strlen($patient_id) > 30 ||
            !validDateTime($start_date_time) ||
            !validDateTime($end_date_time)
        ) {
            setMessage('error', 'Please enter valid device and date/time details.');
            redirectOwner();
        }

        if (
            $start_date_time !== '' &&
            $end_date_time !== '' &&
            strtotime($end_date_time) <= strtotime($start_date_time)
        ) {
            setMessage('error', 'End date/time must be later than start date/time.');
            redirectOwner();
        }

        $start_sql = sqlDateTime($start_date_time);
        $end_sql = sqlDateTime($end_date_time);

        $check = prepareOrFail($conn, "
            SELECT patient_id
            FROM patients
            WHERE patient_id = ?
        ");

        $check->bind_param("s", $patient_id);
        $check->execute();

        $patient_exists = $check->get_result()->num_rows > 0;
        $check->close();

        if (!$patient_exists) {
            setMessage('error', 'Selected patient does not exist.');
            redirectOwner();
        }

        if ($end_sql !== null && strtotime($end_sql) <= time()) {
            setMessage('error', 'Cannot assign a device with an expired end date.');
            redirectOwner();
        }

        if ($action === 'assign_device') {

            /*
             * New device assignments start enabled.
             * Existing device status is preserved on duplicate.
             */

            $stmt = prepareOrFail($conn, "
                INSERT INTO patient_devices
                (
                    device_id,
                    patient_id,
                    description,
                    enabled,
                    start_date_time,
                    end_date_time
                )
                VALUES (?, ?, ?, 1, ?, ?)

                ON DUPLICATE KEY UPDATE
                    patient_id = VALUES(patient_id),
                    description = VALUES(description),
                    start_date_time = VALUES(start_date_time),
                    end_date_time = VALUES(end_date_time)
            ");

            $stmt->bind_param(
                "sssss",
                $device_id,
                $patient_id,
                $description,
                $start_sql,
                $end_sql
            );

        } else {

            $stmt = prepareOrFail($conn, "
                UPDATE patient_devices
                SET
                    patient_id = ?,
                    description = ?,
                    start_date_time = ?,
                    end_date_time = ?
                WHERE device_id = ?
            ");

            $stmt->bind_param(
                "sssss",
                $patient_id,
                $description,
                $start_sql,
                $end_sql,
                $device_id
            );
        }

        if ($stmt->execute()) {
            setMessage('success', 'Device assignment saved successfully.');
        } else {
            setMessage('error', 'Unable to save device assignment: ' . $stmt->error);
        }

        $stmt->close();
        redirectOwner();
    }

    /* -----------------------------------------------------
       ENABLE / DISABLE DEVICE
    ----------------------------------------------------- */

    if ($action === 'toggle_device') {

        $device_id = postString('device_id');
        $enabled = postString('enabled');

        if (
            $device_id === '' ||
            !in_array($enabled, ['0', '1'], true)
        ) {
            setMessage('error', 'Invalid device status request.');
            redirectOwner();
        }

        $enabled = (int)$enabled;

        if ($enabled === 1) {

            $check = prepareOrFail($conn, "
                SELECT device_id, start_date_time, end_date_time
                FROM patient_devices
                WHERE device_id = ?
            ");

            $check->bind_param("s", $device_id);
            $check->execute();

            $device = $check->get_result()->fetch_assoc();
            $check->close();

            if (!$device) {
                setMessage('error', 'Device assignment not found.');
                redirectOwner();
            }

            if (
                !empty($device['start_date_time']) &&
                strtotime($device['start_date_time']) > time()
            ) {
                setMessage('error', 'Cannot enable a device before its scheduled start time.');
                redirectOwner();
            }

            if (
                !empty($device['end_date_time']) &&
                strtotime($device['end_date_time']) <= time()
            ) {
                setMessage('error', 'Cannot enable an expired device assignment.');
                redirectOwner();
            }
        }

        $stmt = prepareOrFail($conn, "
            UPDATE patient_devices
            SET enabled = ?
            WHERE device_id = ?
        ");

        $stmt->bind_param("is", $enabled, $device_id);

        if ($stmt->execute()) {
            setMessage('success', $enabled ? 'Device enabled.' : 'Device disabled.');
        } else {
            setMessage('error', 'Unable to update device status.');
        }

        $stmt->close();
        redirectOwner();
    }

    /* -----------------------------------------------------
       DELETE DEVICE ASSIGNMENT
    ----------------------------------------------------- */

    if ($action === 'delete_device') {

        $device_id = postString('device_id');

        if ($device_id === '') {
            setMessage('error', 'Invalid device ID.');
            redirectOwner();
        }

        $stmt = prepareOrFail($conn, "
            DELETE FROM patient_devices
            WHERE device_id = ?
        ");

        $stmt->bind_param("s", $device_id);

        if ($stmt->execute() && $stmt->affected_rows > 0) {
            setMessage('success', 'Device assignment deleted.');
        } else {
            setMessage('error', 'Device assignment was not found or could not be deleted.');
        }

        $stmt->close();
        redirectOwner();
    }

    /* -----------------------------------------------------
       CREATE OR UPDATE PATIENT LOGIN
    ----------------------------------------------------- */

    if ($action === 'save_patient_login') {

        $patient_id = postString('patient_id');
        $username = postString('username');
        $password = postString('password');
        $device_id = postString('login_device_id');

        $valid_from = postString('valid_from');
        $valid_until = postString('valid_until');

        if (
            $patient_id === '' ||
            strlen($patient_id) > 30 ||
            $username === '' ||
            strlen($username) > 100 ||
            $device_id === '' ||
            !validDateTime($valid_from) ||
            !validDateTime($valid_until)
        ) {
            setMessage('error', 'Please enter valid login details.');
            redirectOwner();
        }

        if (
            $valid_from !== '' &&
            $valid_until !== '' &&
            strtotime($valid_until) <= strtotime($valid_from)
        ) {
            setMessage('error', 'Login expiry must be later than its start time.');
            redirectOwner();
        }

        $patient_check = prepareOrFail($conn, "
            SELECT patient_id
            FROM patients
            WHERE patient_id = ?
        ");

        $patient_check->bind_param("s", $patient_id);
        $patient_check->execute();

        $patient_exists = $patient_check->get_result()->num_rows > 0;
        $patient_check->close();

        if (!$patient_exists) {
            setMessage('error', 'Patient not found.');
            redirectOwner();
        }

        $device_check = prepareOrFail($conn, "
            SELECT device_id
            FROM patient_devices
            WHERE device_id = ?
              AND patient_id = ?
        ");

        $device_check->bind_param("ss", $device_id, $patient_id);
        $device_check->execute();

        $device_exists = $device_check->get_result()->num_rows > 0;
        $device_check->close();

        if (!$device_exists) {
            setMessage('error', 'Selected device is not assigned to this patient.');
            redirectOwner();
        }

        $from_sql = sqlDateTime($valid_from);
        $until_sql = sqlDateTime($valid_until);

        $existing_stmt = prepareOrFail($conn, "
            SELECT wu.id
            FROM web_users wu
            INNER JOIN patient_devices pd
                ON pd.device_id = wu.device_id
            WHERE pd.patient_id = ?
              AND wu.role = 'patient'
            ORDER BY wu.id DESC
            LIMIT 1
        ");

        $existing_stmt->bind_param("s", $patient_id);
        $existing_stmt->execute();

        $existing = $existing_stmt->get_result()->fetch_assoc();
        $existing_stmt->close();

        /* Check username uniqueness */

        if ($existing) {

            $username_check = prepareOrFail($conn, "
                SELECT id
                FROM web_users
                WHERE username = ?
                  AND id <> ?
                LIMIT 1
            ");

            $username_check->bind_param("si", $username, $existing['id']);

        } else {

            $username_check = prepareOrFail($conn, "
                SELECT id
                FROM web_users
                WHERE username = ?
                LIMIT 1
            ");

            $username_check->bind_param("s", $username);
        }

        $username_check->execute();

        $username_taken = $username_check->get_result()->num_rows > 0;
        $username_check->close();

        if ($username_taken) {
            setMessage('error', 'This username is already being used.');
            redirectOwner();
        }

        if ($existing) {

            if ($password !== '') {

                if (strlen($password) < 8) {
                    setMessage('error', 'Password must contain at least 8 characters.');
                    redirectOwner();
                }

                $password_hash = password_hash($password, PASSWORD_DEFAULT);

                $stmt = prepareOrFail($conn, "
                    UPDATE web_users
                    SET
                        username = ?,
                        password_hash = ?,
                        device_id = ?,
                        valid_from = ?,
                        valid_until = ?
                    WHERE id = ?
                      AND role = 'patient'
                ");

                $stmt->bind_param(
                    "sssssi",
                    $username,
                    $password_hash,
                    $device_id,
                    $from_sql,
                    $until_sql,
                    $existing['id']
                );

            } else {

                $stmt = prepareOrFail($conn, "
                    UPDATE web_users
                    SET
                        username = ?,
                        device_id = ?,
                        valid_from = ?,
                        valid_until = ?
                    WHERE id = ?
                      AND role = 'patient'
                ");

                $stmt->bind_param(
                    "ssssi",
                    $username,
                    $device_id,
                    $from_sql,
                    $until_sql,
                    $existing['id']
                );
            }

        } else {

            if ($password === '') {
                setMessage('error', 'Enter a password when creating a new login.');
                redirectOwner();
            }

            if (strlen($password) < 8) {
                setMessage('error', 'Password must contain at least 8 characters.');
                redirectOwner();
            }

            $password_hash = password_hash($password, PASSWORD_DEFAULT);

            $stmt = prepareOrFail($conn, "
                INSERT INTO web_users
                (
                    username,
                    password_hash,
                    role,
                    enabled,
                    device_id,
                    valid_from,
                    valid_until
                )
                VALUES (?, ?, 'patient', 1, ?, ?, ?)
            ");

            $stmt->bind_param(
                "sssss",
                $username,
                $password_hash,
                $device_id,
                $from_sql,
                $until_sql
            );
        }

        if ($stmt->execute()) {
            setMessage('success', 'Patient login saved successfully.');
        } else {
            setMessage('error', 'Unable to save patient login: ' . $stmt->error);
        }

        $stmt->close();
        redirectOwner();
    }

    /* -----------------------------------------------------
       ENABLE / DISABLE PATIENT LOGIN
    ----------------------------------------------------- */

    if ($action === 'toggle_patient_login') {

        $login_id = (int)postString('login_id');
        $enabled = postString('enabled');

        if (
            $login_id <= 0 ||
            !in_array($enabled, ['0', '1'], true)
        ) {
            setMessage('error', 'Invalid patient login request.');
            redirectOwner();
        }

        $enabled = (int)$enabled;

        if ($enabled === 1) {

            $check = prepareOrFail($conn, "
                SELECT id, valid_from, valid_until
                FROM web_users
                WHERE id = ?
                  AND role = 'patient'
            ");

            $check->bind_param("i", $login_id);
            $check->execute();

            $login = $check->get_result()->fetch_assoc();
            $check->close();

            if (!$login) {
                setMessage('error', 'Patient login account not found.');
                redirectOwner();
            }

            if (
                !empty($login['valid_from']) &&
                strtotime($login['valid_from']) > time()
            ) {
                setMessage('error', 'Cannot enable a login before its scheduled start time.');
                redirectOwner();
            }

            if (
                !empty($login['valid_until']) &&
                strtotime($login['valid_until']) <= time()
            ) {
                setMessage('error', 'Cannot enable an expired patient login.');
                redirectOwner();
            }
        }

        $stmt = prepareOrFail($conn, "
            UPDATE web_users
            SET enabled = ?
            WHERE id = ?
              AND role = 'patient'
        ");

        $stmt->bind_param("ii", $enabled, $login_id);

        if ($stmt->execute()) {
            setMessage('success', $enabled ? 'Patient login enabled.' : 'Patient login disabled.');
        } else {
            setMessage('error', 'Unable to update patient login.');
        }

        $stmt->close();
        redirectOwner();
    }

    /* -----------------------------------------------------
       DELETE PATIENT LOGIN ONLY
    ----------------------------------------------------- */

    if ($action === 'delete_patient_login') {

        $login_id = (int)postString('login_id');

        if ($login_id <= 0) {
            setMessage('error', 'Invalid patient login ID.');
            redirectOwner();
        }

        $stmt = prepareOrFail($conn, "
            DELETE FROM web_users
            WHERE id = ?
              AND role = 'patient'
        ");

        $stmt->bind_param("i", $login_id);

        if ($stmt->execute() && $stmt->affected_rows > 0) {
            setMessage(
                'success',
                'Patient login deleted. The patient record and device assignment have been preserved.'
            );
        } else {
            setMessage('error', 'Patient login was not found or could not be deleted.');
        }

        $stmt->close();
        redirectOwner();
    }

    setMessage('error', 'Unknown action.');
    redirectOwner();
}

/* =========================================================
   READ MESSAGE
========================================================= */

$message = $_SESSION['owner_message'] ?? null;
unset($_SESSION['owner_message']);

/* =========================================================
   FETCH PATIENTS
========================================================= */

$patients = [];

$result = $conn->query("
    SELECT
        patient_id,
        full_name,
        age,
        gender,
        blood_group,
        contact,
        email,
        notes,
        created_at
    FROM patients
    ORDER BY patient_id DESC
");

if (!$result) {
    die("Unable to read patients: " . h($conn->error));
}

while ($row = $result->fetch_assoc()) {
    $patients[$row['patient_id']] = $row;
    $patients[$row['patient_id']]['devices'] = [];
}

/* =========================================================
   FETCH DEVICE ASSIGNMENTS
========================================================= */

$devices = [];

$result = $conn->query("
    SELECT
        pd.device_id,
        pd.patient_id,
        pd.description,
        pd.enabled,
        pd.created_at,
        pd.start_date_time,
        pd.end_date_time,
        p.full_name
    FROM patient_devices pd
    LEFT JOIN patients p
        ON p.patient_id = pd.patient_id
    ORDER BY pd.device_id ASC
");

if (!$result) {
    die("Unable to read devices: " . h($conn->error));
}

while ($row = $result->fetch_assoc()) {

    $devices[] = $row;

    if (isset($patients[$row['patient_id']])) {
        $patients[$row['patient_id']]['devices'][] = $row;
    }
}

/* =========================================================
   FETCH PATIENT LOGIN ACCOUNTS
========================================================= */

$logins = [];

$result = $conn->query("
    SELECT
        wu.id,
        wu.username,
        wu.enabled,
        wu.device_id,
        wu.valid_from,
        wu.valid_until,
        pd.patient_id,
        p.full_name
    FROM web_users wu
    INNER JOIN patient_devices pd
        ON pd.device_id = wu.device_id
    INNER JOIN patients p
        ON p.patient_id = pd.patient_id
    WHERE wu.role = 'patient'
    ORDER BY p.patient_id DESC, wu.id DESC
");

if (!$result) {
    die("Unable to read patient logins: " . h($conn->error));
}

while ($row = $result->fetch_assoc()) {
    $logins[] = $row;
}

?>

<!DOCTYPE html>
<html lang="en">

<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Patient Monitoring - Administrator</title>

<style>
* {
    box-sizing: border-box;
}

body {
    font-family: Arial, sans-serif;
    background: #f2f5f9;
    margin: 0;
    padding: 20px;
    color: #222;
}

.container {
    max-width: 1400px;
    margin: auto;
}

h1, h2 {
    color: #17365d;
}

h2 {
    margin-top: 30px;
    border-bottom: 2px solid #d9e2f3;
    padding-bottom: 8px;
}

.panel {
    background: #fff;
    padding: 20px;
    margin-bottom: 25px;
    border-radius: 8px;
    box-shadow: 0 2px 7px rgba(0,0,0,0.08);
}

.grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: 12px;
}

input, select, textarea {
    width: 100%;
    padding: 9px;
    margin-top: 5px;
    border: 1px solid #bbb;
    border-radius: 4px;
}

textarea {
    min-height: 65px;
}

label {
    font-weight: bold;
    display: block;
    margin-top: 8px;
}

button, .button {
    display: inline-block;
    padding: 9px 14px;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    color: white;
    background: #1769aa;
    text-decoration: none;
    margin: 3px;
}

button:hover, .button:hover {
    opacity: 0.85;
}

button:disabled {
    opacity: 0.45;
    cursor: not-allowed;
}

.green {
    background: #238636;
}

.red {
    background: #c62828;
}

.orange {
    background: #e68a00;
}

.table-wrap {
    overflow-x: auto;
    width: 100%;
}

table {
    width: 100%;
    border-collapse: collapse;
    background: white;
    margin-top: 12px;
}

th, td {
    border: 1px solid #ddd;
    padding: 9px;
    text-align: left;
    vertical-align: top;
}

th {
    background: #e8eef7;
    color: #17365d;
}

tr:nth-child(even) {
    background: #fafafa;
}

.success-message {
    background: #e3f5e7;
    color: #176329;
    padding: 12px;
    border-radius: 5px;
    margin-bottom: 15px;
}

.error-message {
    background: #fde8e8;
    color: #a00000;
    padding: 12px;
    border-radius: 5px;
    margin-bottom: 15px;
}

.status-enabled {
    color: #16803c;
    font-weight: bold;
}

.status-disabled {
    color: #c62828;
    font-weight: bold;
}

.status-scheduled {
    color: #b36b00;
    font-weight: bold;
}

.small {
    font-size: 12px;
    color: #555;
}

.inline-form {
    display: inline-block;
    margin: 2px;
}

.login-form {
    min-width: 250px;
}

.login-form input,
.login-form select {
    margin-bottom: 5px;
}

@media(max-width: 600px) {
    body {
        padding: 8px;
    }

    .panel {
        padding: 12px;
    }
}
</style>
</head>

<body>

<div class="container">

<h1>Patient Monitoring System - Administrator</h1>

<p>
    Welcome,
    <?php echo h($_SESSION['owner_name'] ?? $_SESSION['owner_username'] ?? 'Administrator'); ?>

    <a class="button red" href="logout.php">Logout</a>
</p>

<?php if ($message): ?>
<div class="<?php echo $message['type'] === 'success' ? 'success-message' : 'error-message'; ?>">
    <?php echo h($message['message']); ?>
</div>
<?php endif; ?>


<!-- REGISTER NEW PATIENT -->

<div class="panel">

<h2>Register New Patient</h2>

<form method="POST">

<input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">
<input type="hidden" name="action" value="add_patient">

<div class="grid">

    <div>
        <label>Patient ID *</label>
        <input
            type="text"
            name="patient_id"
            required
            maxlength="30"
            placeholder="Example: P1001"
        >
        <span class="small">Enter a unique ID. Maximum 30 characters.</span>
    </div>

    <div>
        <label>Patient Name *</label>
        <input type="text" name="full_name" required maxlength="100">
    </div>

    <div>
        <label>Age</label>
        <input type="number" name="age" min="0" max="120">
    </div>

    <div>
        <label>Gender</label>
        <select name="gender">
            <option value="">Select</option>
            <option value="Male">Male</option>
            <option value="Female">Female</option>
            <option value="Other">Other</option>
        </select>
    </div>

    <div>
        <label>Blood Group</label>
        <input type="text" name="blood_group" maxlength="5">
    </div>

    <div>
        <label>Contact / Mobile Number</label>
        <input
            type="text"
            name="contact"
            maxlength="150"
            placeholder="Patient mobile number"
        >
    </div>

    <div>
        <label>Email Address *</label>
        <input
            type="email"
            name="email"
            required
            maxlength="150"
            placeholder="patient@example.com"
        >
        <span class="small">Alert emails will be sent to this address.</span>
    </div>

    <div>
        <label>Notes</label>
        <textarea name="notes"></textarea>
    </div>

</div>

<br>
<button type="submit" class="green">Register Patient</button>

</form>
</div>


<!-- ASSIGN ESP32 DEVICE -->

<div class="panel">

<h2>Assign ESP32 Device</h2>

<form method="POST">

<input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">
<input type="hidden" name="action" value="assign_device">

<div class="grid">

    <div>
        <label>Device ID *</label>
        <input type="text" name="device_id" required maxlength="32" placeholder="ESP32-01">
    </div>

    <div>
        <label>Select Patient *</label>

        <select name="patient_id" required>
            <option value="">Select Patient</option>

            <?php foreach ($patients as $patient): ?>
                <option value="<?php echo h($patient['patient_id']); ?>">
                    <?php echo h($patient['patient_id'] . ' - ' . $patient['full_name']); ?>
                </option>
            <?php endforeach; ?>

        </select>
    </div>

    <div>
        <label>Description</label>
        <input type="text" name="description">
    </div>

    <div>
        <label>Start Date and Time</label>
        <input type="datetime-local" name="start_date_time">
    </div>

    <div>
        <label>End Date and Time</label>
        <input type="datetime-local" name="end_date_time">
    </div>

</div>

<p class="small">
    Leave Start Date/Time blank for immediate validity.
    Leave End Date/Time blank for no expiry.
    A future start time prevents access until that time.
    An expired assignment is automatically disabled.
</p>

<button type="submit" class="green">Assign Device</button>

</form>
</div>


<!-- REGISTERED PATIENTS -->

<div class="panel">

<h2>Registered Patients</h2>

<div class="table-wrap">
<table>

<thead>
<tr>
    <th>Patient ID</th>
    <th>Patient Name</th>
    <th>Age</th>
    <th>Gender</th>
    <th>Blood Group</th>
    <th>Mobile Number</th>
    <th>Email Address</th>
    <th>Assigned Devices</th>
</tr>
</thead>

<tbody>

<?php if (empty($patients)): ?>

<tr>
    <td colspan="8">No patients registered.</td>
</tr>

<?php else: ?>

<?php foreach ($patients as $patient): ?>

<tr>
    <td><?php echo h($patient['patient_id']); ?></td>
    <td><?php echo h($patient['full_name']); ?></td>
    <td><?php echo h($patient['age']); ?></td>
    <td><?php echo h($patient['gender']); ?></td>
    <td><?php echo h($patient['blood_group']); ?></td>
    <td><?php echo h($patient['contact']); ?></td>
    <td><?php echo h($patient['email']); ?></td>

    <td>

        <?php if (!empty($patient['devices'])): ?>

            <?php foreach ($patient['devices'] as $device): ?>

                <div>
                    <strong><?php echo h($device['device_id']); ?></strong> -

                    <?php if (!$device['enabled']): ?>
                        <span class="status-disabled">Disabled</span>

                    <?php elseif (
                        !empty($device['start_date_time']) &&
                        strtotime($device['start_date_time']) > time()
                    ): ?>
                        <span class="status-scheduled">Scheduled</span>

                    <?php elseif (
                        !empty($device['end_date_time']) &&
                        strtotime($device['end_date_time']) <= time()
                    ): ?>
                        <span class="status-disabled">Expired</span>

                    <?php else: ?>
                        <span class="status-enabled">Enabled</span>
                    <?php endif; ?>
                </div>

            <?php endforeach; ?>

        <?php else: ?>
            <span class="small">No device assigned</span>
        <?php endif; ?>

    </td>
</tr>

<?php endforeach; ?>

<?php endif; ?>

</tbody>
</table>
</div>
</div>


<!-- ESP32 DEVICE MANAGEMENT -->

<div class="panel">

<h2>ESP32 Device Management</h2>

<div class="table-wrap">
<table>

<thead>
<tr>
    <th>Device ID</th>
    <th>Patient</th>
    <th>Description</th>
    <th>Validity</th>
    <th>Status</th>
    <th>Actions</th>
</tr>
</thead>

<tbody>

<?php if (empty($devices)): ?>

<tr>
    <td colspan="6">No device assignments found.</td>
</tr>

<?php else: ?>

<?php foreach ($devices as $device): ?>

<?php
$device_expired = (
    !empty($device['end_date_time']) &&
    strtotime($device['end_date_time']) <= time()
);

$device_future = (
    !empty($device['start_date_time']) &&
    strtotime($device['start_date_time']) > time()
);
?>

<tr>

    <td>
        <strong><?php echo h($device['device_id']); ?></strong>
    </td>

    <td>
        <?php echo h($device['full_name'] ?? 'Patient not found'); ?>
        <br>
        <span class="small">
            Patient ID: <?php echo h($device['patient_id']); ?>
        </span>
    </td>

    <td><?php echo h($device['description']); ?></td>

    <td>
        <strong>Start:</strong><br>
        <?php echo h($device['start_date_time'] ?: 'Not specified'); ?>

        <br><br>

        <strong>End:</strong><br>
        <?php echo h($device['end_date_time'] ?: 'No expiry'); ?>
    </td>

    <td>

        <?php if ($device_expired): ?>
            <span class="status-disabled">Expired</span>

        <?php elseif (!$device['enabled']): ?>
            <span class="status-disabled">Disabled</span>

        <?php elseif ($device_future): ?>
            <span class="status-scheduled">Scheduled</span>

        <?php else: ?>
            <span class="status-enabled">Enabled</span>
        <?php endif; ?>

    </td>

    <td>

        <!-- EDIT DEVICE -->

        <form method="POST" class="inline-form">

            <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">
            <input type="hidden" name="action" value="update_device">
            <input type="hidden" name="device_id" value="<?php echo h($device['device_id']); ?>">

            <label>Select Patient</label>

            <select name="patient_id" required>

                <?php foreach ($patients as $patient): ?>

                    <option
                        value="<?php echo h($patient['patient_id']); ?>"
                        <?php echo ((string)$patient['patient_id'] === (string)$device['patient_id']) ? 'selected' : ''; ?>
                    >
                        <?php echo h($patient['patient_id'] . ' - ' . $patient['full_name']); ?>
                    </option>

                <?php endforeach; ?>

            </select>

            <input
                type="text"
                name="description"
                value="<?php echo h($device['description']); ?>"
                placeholder="Description"
            >

            <label>Start Date/Time</label>
            <input
                type="datetime-local"
                name="start_date_time"
                value="<?php echo h(htmlDateTime($device['start_date_time'])); ?>"
            >

            <label>End Date/Time</label>
            <input
                type="datetime-local"
                name="end_date_time"
                value="<?php echo h(htmlDateTime($device['end_date_time'])); ?>"
            >

            <button type="submit" class="orange">Save Edit</button>

        </form>

        <!-- ENABLE / DISABLE DEVICE -->

        <form method="POST" class="inline-form">

            <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">
            <input type="hidden" name="action" value="toggle_device">
            <input type="hidden" name="device_id" value="<?php echo h($device['device_id']); ?>">

            <?php if ($device['enabled']): ?>

                <input type="hidden" name="enabled" value="0">
                <button type="submit" class="red">Disable</button>

            <?php else: ?>

                <input type="hidden" name="enabled" value="1">

                <button
                    type="submit"
                    class="green"
                    <?php echo ($device_expired || $device_future) ? 'disabled title="Device cannot be enabled before its start time or after expiry"' : ''; ?>
                >
                    Enable
                </button>

            <?php endif; ?>

        </form>

        <!-- DELETE DEVICE ASSIGNMENT -->

        <form
            method="POST"
            class="inline-form"
            onsubmit="return confirm('Delete this device assignment? This does not delete the patient record.');"
        >

            <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">
            <input type="hidden" name="action" value="delete_device">
            <input type="hidden" name="device_id" value="<?php echo h($device['device_id']); ?>">

            <button type="submit" class="red">Delete</button>

        </form>

    </td>

</tr>

<?php endforeach; ?>

<?php endif; ?>

</tbody>
</table>
</div>
</div>


<!-- PATIENT LOGIN MANAGEMENT -->

<div class="panel">

<h2>Patient Login Management</h2>

<p class="small">
    Leave the password blank when updating an existing account
    if you do not want to change its password.
    Passwords must contain at least 8 characters.
    Delete Login removes only the login account, not the patient
    record, device assignment, or monitoring history.
</p>

<div class="table-wrap">
<table>

<thead>
<tr>
    <th>Patient</th>
    <th>Login Account</th>
    <th>Validity</th>
    <th>Status</th>
    <th>Actions</th>
</tr>
</thead>

<tbody>

<?php if (empty($patients)): ?>

<tr>
    <td colspan="5">Register a patient before creating a login.</td>
</tr>

<?php else: ?>

<?php foreach ($patients as $patient): ?>

<?php
$patient_login = null;

foreach ($logins as $login) {
    if ((string)$login['patient_id'] === (string)$patient['patient_id']) {
        $patient_login = $login;
        break;
    }
}

$login_expired = (
    $patient_login &&
    !empty($patient_login['valid_until']) &&
    strtotime($patient_login['valid_until']) <= time()
);

$login_future = (
    $patient_login &&
    !empty($patient_login['valid_from']) &&
    strtotime($patient_login['valid_from']) > time()
);
?>

<tr>

<td>
    <strong><?php echo h($patient['full_name']); ?></strong>
    <br>
    <span class="small">
        Patient ID: <?php echo h($patient['patient_id']); ?>
    </span>
</td>

<td>

    <form method="POST" class="login-form">

        <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">
        <input type="hidden" name="action" value="save_patient_login">
        <input type="hidden" name="patient_id" value="<?php echo h($patient['patient_id']); ?>">

        <label>Username</label>

        <input
            type="text"
            name="username"
            required
            maxlength="100"
            value="<?php echo h($patient_login['username'] ?? ''); ?>"
        >

        <label>Password</label>

        <input
            type="password"
            name="password"
            <?php echo $patient_login ? '' : 'required'; ?>
            minlength="8"
            autocomplete="new-password"
            placeholder="<?php echo $patient_login ? 'Leave blank to keep current password' : 'Enter new password'; ?>"
        >

        <label>Assigned Device</label>

        <select name="login_device_id" required>

            <option value="">Select Device</option>

            <?php foreach ($patient['devices'] as $device): ?>

                <option
                    value="<?php echo h($device['device_id']); ?>"
                    <?php
                    echo (
                        $patient_login &&
                        $patient_login['device_id'] === $device['device_id']
                    ) ? 'selected' : '';
                    ?>
                >
                    <?php echo h($device['device_id']); ?>
                </option>

            <?php endforeach; ?>

        </select>

        <label>Login Valid From</label>

        <input
            type="datetime-local"
            name="valid_from"
            value="<?php echo h(
                $patient_login
                    ? htmlDateTime($patient_login['valid_from'])
                    : ''
            ); ?>"
        >

        <label>Login Valid Until</label>

        <input
            type="datetime-local"
            name="valid_until"
            value="<?php echo h(
                $patient_login
                    ? htmlDateTime($patient_login['valid_until'])
                    : ''
            ); ?>"
        >

        <button type="submit" class="green">
            <?php echo $patient_login ? 'Update Login' : 'Create Login'; ?>
        </button>

    </form>

</td>

<td>

    <?php if ($patient_login): ?>

        <strong>From:</strong><br>
        <?php echo h($patient_login['valid_from'] ?: 'Not specified'); ?>

        <br><br>

        <strong>Until:</strong><br>
        <?php echo h($patient_login['valid_until'] ?: 'No expiry'); ?>

    <?php else: ?>

        <span class="small">Login not created</span>

    <?php endif; ?>

</td>

<td>

    <?php if (!$patient_login): ?>

        <span class="small">Not created</span>

    <?php elseif ($login_expired): ?>

        <span class="status-disabled">Expired</span>

    <?php elseif (!$patient_login['enabled']): ?>

        <span class="status-disabled">Disabled</span>

    <?php elseif ($login_future): ?>

        <span class="status-scheduled">Scheduled</span>

    <?php else: ?>

        <span class="status-enabled">Enabled</span>

    <?php endif; ?>

</td>

<td>

    <?php if ($patient_login): ?>

        <!-- ENABLE / DISABLE LOGIN -->

        <form method="POST" class="inline-form">

            <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">
            <input type="hidden" name="action" value="toggle_patient_login">
            <input type="hidden" name="login_id" value="<?php echo h($patient_login['id']); ?>">

            <?php if ($patient_login['enabled']): ?>

                <input type="hidden" name="enabled" value="0">
                <button type="submit" class="red">Disable Login</button>

            <?php else: ?>

                <input type="hidden" name="enabled" value="1">

                <button
                    type="submit"
                    class="green"
                    <?php echo ($login_expired || $login_future) ? 'disabled title="Login cannot be enabled before its start time or after expiry"' : ''; ?>
                >
                    Enable Login
                </button>

            <?php endif; ?>

        </form>

        <!-- DELETE LOGIN ONLY -->

        <form
            method="POST"
            class="inline-form"
            onsubmit="return confirm('Are you sure you want to delete this patient login? The patient record, device assignment and monitoring records will NOT be deleted.');"
        >

            <input type="hidden" name="csrf_token" value="<?php echo h($csrfToken); ?>">
            <input type="hidden" name="action" value="delete_patient_login">
            <input type="hidden" name="login_id" value="<?php echo h($patient_login['id']); ?>">

            <button type="submit" class="red">Delete Login</button>

        </form>

    <?php else: ?>

        <span class="small">Create login first</span>

    <?php endif; ?>

</td>

</tr>

<?php endforeach; ?>

<?php endif; ?>

</tbody>
</table>
</div>
</div>

</div>

</body>
</html>
