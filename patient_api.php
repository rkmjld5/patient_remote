<?php
/*
 * PATIENT MONITORING SYSTEM
 * File: patient_api.php
 * Purpose: Patient registration and management
 */

session_start();
require_once 'config.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

// Security token
if (empty($_SESSION['patient_csrf'])) {
    $_SESSION['patient_csrf'] = bin2hex(random_bytes(32));
}

$message = '';
$message_type = 'success';

// HTML escaping
function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// Process submitted forms
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    try {
        // Verify CSRF token
        $token = $_POST['csrf_token'] ?? '';

        if (!hash_equals($_SESSION['patient_csrf'], $token)) {
            throw new Exception('Invalid request. Please reload the page.');
        }

        $action = $_POST['action'] ?? '';

        // REGISTER PATIENT
        if ($action === 'add') {

            $patient_id = strtoupper(trim($_POST['patient_id'] ?? ''));
            $full_name = trim($_POST['full_name'] ?? '');
            $age_input = trim($_POST['age'] ?? '');
            $blood_group = trim($_POST['blood_group'] ?? '');
            $gender = trim($_POST['gender'] ?? '');
            $contact = trim($_POST['contact'] ?? '');
            $notes = trim($_POST['notes'] ?? '');

            if ($patient_id === '' || $full_name === '') {
                throw new Exception('Patient ID and patient name are required.');
            }

            if (strlen($patient_id) > 30 || strlen($full_name) > 100) {
                throw new Exception('Patient ID or name is too long.');
            }

            $age = null;

            if ($age_input !== '') {
                if (!ctype_digit($age_input) ||
                    (int)$age_input > 255) {
                    throw new Exception('Please enter a valid age.');
                }
                $age = (int)$age_input;
            }

            if (strlen($blood_group) > 5 ||
                strlen($gender) > 20 ||
                strlen($contact) > 150) {
                throw new Exception('One or more fields exceed their allowed length.');
            }

            $stmt = $conn->prepare(
                "INSERT INTO patients
                (patient_id, full_name, age, blood_group,
                 gender, contact, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?)"
            );

            $stmt->bind_param(
                "ssissss",
                $patient_id,
                $full_name,
                $age,
                $blood_group,
                $gender,
                $contact,
                $notes
            );

            $stmt->execute();
            $stmt->close();

            $message = 'Patient registered successfully.';
        }

        // UPDATE PATIENT
        elseif ($action === 'update') {

            $patient_id = strtoupper(trim($_POST['patient_id'] ?? ''));
            $full_name = trim($_POST['full_name'] ?? '');
            $age_input = trim($_POST['age'] ?? '');
            $blood_group = trim($_POST['blood_group'] ?? '');
            $gender = trim($_POST['gender'] ?? '');
            $contact = trim($_POST['contact'] ?? '');
            $notes = trim($_POST['notes'] ?? '');

            if ($patient_id === '' || $full_name === '') {
                throw new Exception('Patient ID and name are required.');
            }

            if (strlen($full_name) > 100 ||
                strlen($blood_group) > 5 ||
                strlen($gender) > 20 ||
                strlen($contact) > 150) {
                throw new Exception('One or more fields exceed their allowed length.');
            }

            $age = null;

            if ($age_input !== '') {
                if (!ctype_digit($age_input) ||
                    (int)$age_input > 255) {
                    throw new Exception('Please enter a valid age.');
                }
                $age = (int)$age_input;
            }

            $stmt = $conn->prepare(
                "UPDATE patients SET
                 full_name = ?,
                 age = ?,
                 blood_group = ?,
                 gender = ?,
                 contact = ?,
                 notes = ?
                 WHERE patient_id = ?"
            );

            $stmt->bind_param(
                "sisssss",
                $full_name,
                $age,
                $blood_group,
                $gender,
                $contact,
                $notes,
                $patient_id
            );

            $stmt->execute();

            if ($stmt->affected_rows === 0) {
                $stmt->close();

                $check = $conn->prepare(
                    "SELECT patient_id FROM patients WHERE patient_id = ?"
                );
                $check->bind_param("s", $patient_id);
                $check->execute();
                $exists = $check->get_result()->num_rows > 0;
                $check->close();

                if (!$exists) {
                    throw new Exception('Patient record not found.');
                }
            } else {
                $stmt->close();
            }

            $message = 'Patient details updated successfully.';
        }

        // DELETE PATIENT
        elseif ($action === 'delete') {

            $patient_id = strtoupper(trim($_POST['patient_id'] ?? ''));

            if ($patient_id === '') {
                throw new Exception('Invalid patient ID.');
            }

            // Do not delete a patient with an assigned device.
            $stmt = $conn->prepare(
                "SELECT device_id FROM patient_devices
                 WHERE patient_id = ? LIMIT 1"
            );

            $stmt->bind_param("s", $patient_id);
            $stmt->execute();

            if ($stmt->get_result()->num_rows > 0) {
                $stmt->close();

                throw new Exception(
                    'Cannot delete this patient. An ESP device is assigned. Remove the device assignment first.'
                );
            }

            $stmt->close();

            // Protect existing monitoring history.
            $tables = array(
                'monitoring_readings',
                'ecg_readings'
            );

            foreach ($tables as $table) {
                $stmt = $conn->prepare(
                    "SELECT id FROM `$table`
                     WHERE patient_id = ? LIMIT 1"
                );

                $stmt->bind_param("s", $patient_id);
                $stmt->execute();

                $has_records = $stmt->get_result()->num_rows > 0;
                $stmt->close();

                if ($has_records) {
                    throw new Exception(
                        'Cannot delete this patient because monitoring history exists.'
                    );
                }
            }

            $stmt = $conn->prepare(
                "DELETE FROM patients WHERE patient_id = ?"
            );

            $stmt->bind_param("s", $patient_id);
            $stmt->execute();

            if ($stmt->affected_rows === 0) {
                throw new Exception('Patient record not found.');
            }

            $stmt->close();

            $message = 'Patient deleted successfully.';
        }

        else {
            throw new Exception('Invalid operation.');
        }

    } catch (Throwable $e) {

        error_log('Patient API error: ' . $e->getMessage());

        if ($e instanceof mysqli_sql_exception &&
            $e->getCode() == 1062) {
            $message = 'This Patient ID already exists.';
        } else {
            $message = $e->getMessage();
        }

        $message_type = 'error';
    }
}

// Load patient for editing
$edit_patient = null;

if (isset($_GET['edit'])) {

    $edit_id = trim($_GET['edit']);

    $stmt = $conn->prepare(
        "SELECT * FROM patients WHERE patient_id = ?"
    );

    $stmt->bind_param("s", $edit_id);
    $stmt->execute();

    $edit_patient = $stmt->get_result()->fetch_assoc();

    $stmt->close();
}

// Display all patients
$result = $conn->query(
    "SELECT * FROM patients ORDER BY created_at DESC"
);

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Patient Management</title>

<style>
body {
    font-family: Arial, sans-serif;
    background: #f2f5f9;
    margin: 0;
    padding: 20px;
    color: #263445;
}

.container {
    max-width: 1150px;
    margin: auto;
}

h1 {
    text-align: center;
    color: #173d66;
}

.panel {
    background: white;
    padding: 22px;
    margin: 20px 0;
    border-radius: 9px;
    box-shadow: 0 2px 8px rgba(0,0,0,.08);
}

.grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 14px;
}

label {
    display: block;
    font-weight: bold;
    margin-bottom: 6px;
}

input, select, textarea {
    width: 100%;
    box-sizing: border-box;
    padding: 10px;
    border: 1px solid #cbd5df;
    border-radius: 5px;
    font-size: 15px;
}

textarea {
    min-height: 75px;
}

button, .btn {
    display: inline-block;
    padding: 10px 16px;
    border: none;
    border-radius: 5px;
    color: white;
    cursor: pointer;
    text-decoration: none;
    font-size: 14px;
}

.save {
    background: #18794e;
}

.edit {
    background: #2469a8;
}

.delete {
    background: #c0392b;
}

.cancel {
    background: #687786;
}

.message {
    padding: 12px;
    border-radius: 5px;
    margin: 15px 0;
}

.success {
    background: #d9f3e5;
    color: #155b37;
}

.error {
    background: #fbe0df;
    color: #a12020;
}

.table-wrap {
    overflow-x: auto;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 850px;
}

th, td {
    border: 1px solid #ddd;
    padding: 10px;
    text-align: left;
}

th {
    background: #173d66;
    color: white;
}

tr:nth-child(even) {
    background: #f5f8fb;
}

.inline-form {
    display: inline;
}

.actions {
    white-space: nowrap;
}

@media(max-width:650px) {
    .grid {
        grid-template-columns: 1fr;
    }

    body {
        padding: 8px;
    }
}
</style>
</head>

<body>

<div class="container">

<h1>Patient Management</h1>

<?php if ($message !== ''): ?>
<div class="message <?= $message_type ?>">
    <?= h($message) ?>
</div>
<?php endif; ?>

<div class="panel">

<h2>
<?= $edit_patient ? 'Edit Patient Details' : 'Register New Patient' ?>
</h2>

<form method="POST" action="patient_api.php">

<input type="hidden" name="csrf_token"
       value="<?= h($_SESSION['patient_csrf']) ?>">

<input type="hidden" name="action"
       value="<?= $edit_patient ? 'update' : 'add' ?>">

<div class="grid">

<div>
<label>Patient ID *</label>
<input type="text"
       name="patient_id"
       maxlength="30"
       required
       value="<?= h($edit_patient['patient_id'] ?? '') ?>"
       <?= $edit_patient ? 'readonly' : '' ?>>
</div>

<div>
<label>Patient Full Name *</label>
<input type="text"
       name="full_name"
       maxlength="100"
       required
       value="<?= h($edit_patient['full_name'] ?? '') ?>">
</div>

<div>
<label>Age</label>
<input type="number"
       name="age"
       min="0"
       max="255"
       value="<?= h($edit_patient['age'] ?? '') ?>">
</div>

<div>
<label>Blood Group</label>
<select name="blood_group">
<option value="">Select Blood Group</option>

<?php
$groups = array(
    'A+', 'A-', 'B+', 'B-',
    'AB+', 'AB-', 'O+', 'O-'
);

foreach ($groups as $group):
?>
<option value="<?= h($group) ?>"
    <?= (($edit_patient['blood_group'] ?? '') === $group)
        ? 'selected' : '' ?>>
    <?= h($group) ?>
</option>
<?php endforeach; ?>

</select>
</div>

<div>
<label>Gender</label>
<select name="gender">
<?php
$genders = array('Male', 'Female', 'Other');
$current_gender = $edit_patient['gender'] ?? '';
?>
<option value="">Select Gender</option>

<?php foreach ($genders as $g): ?>
<option value="<?= h($g) ?>"
    <?= ($current_gender === $g) ? 'selected' : '' ?>>
    <?= h($g) ?>
</option>
<?php endforeach; ?>

</select>
</div>

<div>
<label>Contact (Phone / Email)</label>
<input type="text"
       name="contact"
       maxlength="150"
       value="<?= h($edit_patient['contact'] ?? '') ?>">
</div>

<div style="grid-column:1/-1">
<label>Medical Notes</label>
<textarea name="notes"><?= h($edit_patient['notes'] ?? '') ?></textarea>
</div>

</div>

<br>

<button type="submit" class="save">
<?= $edit_patient ? 'Update Patient' : 'Register Patient' ?>
</button>

<?php if ($edit_patient): ?>
<a href="patient_api.php" class="btn cancel">
Cancel Edit
</a>
<?php endif; ?>

</form>
</div>

<div class="panel">

<h2>Registered Patients</h2>

<div class="table-wrap">

<table>

<thead>
<tr>
<th>Patient ID</th>
<th>Name</th>
<th>Age</th>
<th>Blood Group</th>
<th>Gender</th>
<th>Contact</th>
<th>Actions</th>
</tr>
</thead>

<tbody>

<?php if ($result->num_rows > 0): ?>

<?php while ($patient = $result->fetch_assoc()): ?>

<tr>

<td><?= h($patient['patient_id']) ?></td>

<td><?= h($patient['full_name']) ?></td>

<td><?= h($patient['age']) ?></td>

<td><?= h($patient['blood_group']) ?></td>

<td><?= h($patient['gender']) ?></td>

<td><?= h($patient['contact']) ?></td>

<td class="actions">

<a class="btn edit"
   href="patient_api.php?edit=<?= urlencode($patient['patient_id']) ?>">
   Edit
</a>

<form class="inline-form"
      method="POST"
      action="patient_api.php"
      onsubmit="return confirm('Are you sure you want to delete this patient?');">

<input type="hidden" name="csrf_token"
       value="<?= h($_SESSION['patient_csrf']) ?>">

<input type="hidden" name="action" value="delete">

<input type="hidden" name="patient_id"
       value="<?= h($patient['patient_id']) ?>">

<button type="submit" class="delete">
Delete
</button>

</form>

</td>

</tr>

<?php endwhile; ?>

<?php else: ?>

<tr>
<td colspan="7" style="text-align:center">
No patients registered yet.
</td>
</tr>

<?php endif; ?>

</tbody>
</table>

</div>
</div>

</div>

</body>
</html>
