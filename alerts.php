<?php
/*
 * PATIENT MONITORING SYSTEM
 * File: alerts.php
 * Secure alert history for the currently logged-in patient/device.
 */
require_once __DIR__ . '/patient_access.php';

try {
    $access = require_patient_access($conn);
    $patientId = $access['patient_id'];
    $deviceId = $access['device_id'];

    // Restrict alerts to the authenticated patient. If alerts has device_id,
    // this additionally scopes them to the currently assigned device.
    $columns = $conn->query("SHOW COLUMNS FROM alerts");
    $hasDeviceColumn = false;
    while ($column = $columns->fetch_assoc()) {
        if ($column['Field'] === 'device_id') {
            $hasDeviceColumn = true;
            break;
        }
    }

    if ($hasDeviceColumn) {
        $stmt = $conn->prepare("
            SELECT id, parameter_name, measured_value, alert_message,
                   email_to, email_status, recorded_at
            FROM alerts
            WHERE patient_id = ? AND device_id = ?
            ORDER BY recorded_at DESC, id DESC
            LIMIT 200
        ");
        $stmt->bind_param('ss', $patientId, $deviceId);
    } else {
        $stmt = $conn->prepare("
            SELECT id, parameter_name, measured_value, alert_message,
                   email_to, email_status, recorded_at
            FROM alerts
            WHERE patient_id = ?
            ORDER BY recorded_at DESC, id DESC
            LIMIT 200
        ");
        $stmt->bind_param('s', $patientId);
    }

    $stmt->execute();
    $result = $stmt->get_result();

} catch (Throwable $e) {
    error_log('Patient alerts page error: ' . $e->getMessage());
    http_response_code(500);
    exit('Unable to load alerts at this time.');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Patient Alerts</title>
<style>
body{font-family:Arial,sans-serif;background:#f3f6fa;margin:0;padding:20px;color:#263238}
.container{max-width:1100px;margin:auto;background:#fff;padding:22px;border-radius:10px;box-shadow:0 2px 10px rgba(0,0,0,.08)}
h2{margin-top:0;color:#17365d}.patient{margin-bottom:18px;line-height:1.7}
table{width:100%;border-collapse:collapse;margin-top:15px}
th,td{padding:11px;border-bottom:1px solid #ddd;text-align:left;vertical-align:top}
th{background:#eaf0f7}.alert-message{color:#b00020;font-weight:bold}.status{font-weight:bold}
.no-records{padding:20px;background:#eef7ee;border-radius:6px;color:#246b32}
a{display:inline-block;margin:12px 12px 0 0;color:#0759a5;text-decoration:none}
a:hover{text-decoration:underline}
@media(max-width:700px){.container{padding:12px;overflow-x:auto}table{min-width:750px}}
</style>
</head>
<body>
<div class="container">
<h2>Patient Alert History</h2>
<div class="patient">
    <strong>Patient:</strong> <?= patient_h($access['full_name']) ?><br>
    <strong>Patient ID:</strong> <?= patient_h($patientId) ?><br>
    <strong>Device ID:</strong> <?= patient_h($deviceId) ?>
</div>
<a href="index.php">Back to Patient Dashboard</a>
<a href="patient_logout.php">Logout</a>

<?php if ($result->num_rows === 0): ?>
    <div class="no-records">No alerts have been recorded for this patient/device.</div>
<?php else: ?>
<table>
<thead><tr>
<th>ID</th><th>Date and Time</th><th>Parameter</th><th>Measured Value</th>
<th>Alert Message</th><th>Email To</th><th>Email Status</th>
</tr></thead>
<tbody>
<?php while ($row = $result->fetch_assoc()): ?>
<tr>
<td><?= patient_h($row['id']) ?></td>
<td><?= patient_h($row['recorded_at']) ?></td>
<td><?= patient_h($row['parameter_name']) ?></td>
<td><?= patient_h($row['measured_value']) ?></td>
<td class="alert-message"><?= patient_h($row['alert_message']) ?></td>
<td><?= patient_h($row['email_to']) ?></td>
<td class="status"><?= patient_h($row['email_status']) ?></td>
</tr>
<?php endwhile; ?>
</tbody>
</table>
<?php endif; ?>
</div>
</body>
</html>
<?php $stmt->close(); ?>