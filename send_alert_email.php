<?php
/*
 * Patient Monitoring System
 * File: send_alert_email.php
 *
 * Purpose:
 * Send an email notification for a particular alert.
 *
 * Usage from another PHP file:
 * require_once __DIR__ . '/send_alert_email.php';
 * sendAlertEmail($conn, $alert_id);
 */

if (!function_exists('sendAlertEmail')) {

    function sendAlertEmail(mysqli $conn, int $alertId): bool
    {
        if ($alertId <= 0) {
            return false;
        }

        // Retrieve the alert.
        $stmt = $conn->prepare(
            "SELECT id, patient_id, parameter_name, measured_value,
                    alert_message, email_to, email_status, recorded_at
             FROM alerts
             WHERE id = ?
             LIMIT 1"
        );

        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('i', $alertId);
        $stmt->execute();

        $result = $stmt->get_result();
        $alert = $result->fetch_assoc();
        $stmt->close();

        if (!$alert) {
            return false;
        }

        // Do not send the same successful alert email again.
        if (strtolower(trim((string)$alert['email_status'])) === 'sent') {
            return true;
        }

        $emailTo = trim((string)$alert['email_to']);

        if (!filter_var($emailTo, FILTER_VALIDATE_EMAIL)) {
            updateAlertEmailStatus($conn, $alertId, 'Failed');
            return false;
        }

        // Retrieve patient name.
        $patientStmt = $conn->prepare(
            "SELECT full_name
             FROM patients
             WHERE patient_id = ?
             LIMIT 1"
        );

        if (!$patientStmt) {
            updateAlertEmailStatus($conn, $alertId, 'Failed');
            return false;
        }

        $patientStmt->bind_param('s', $alert['patient_id']);
        $patientStmt->execute();

        $patientResult = $patientStmt->get_result();
        $patient = $patientResult->fetch_assoc();
        $patientStmt->close();

        $patientName = $patient
            ? $patient['full_name']
            : $alert['patient_id'];

        // Email subject and message.
        $subject = 'Patient Monitoring System - Health Alert';

        $message =
            "PATIENT MONITORING SYSTEM - HEALTH ALERT\n\n" .
            "Patient Name: " . $patientName . "\n" .
            "Patient ID: " . $alert['patient_id'] . "\n" .
            "Alert Parameter: " . $alert['parameter_name'] . "\n" .
            "Measured Value: " . $alert['measured_value'] . "\n" .
            "Alert Message: " . $alert['alert_message'] . "\n" .
            "Recorded At: " . $alert['recorded_at'] . "\n\n" .
            "Please check the patient's condition and take " .
            "appropriate action.\n\n" .
            "This is an automated notification from the " .
            "Patient Monitoring System.\n";

        // Change this to an email address belonging to your domain
        // when you configure a real mail service.
        $fromEmail = 'patientmonitor@localhost';

        $headers = [];
        $headers[] = 'From: Patient Monitoring System <' .
                     $fromEmail . '>';
        $headers[] = 'Reply-To: ' . $fromEmail;
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $headers[] = 'X-Mailer: PHP/' . phpversion();

        // PHP mail() requires a working mail transport configuration.
        $sent = @mail(
            $emailTo,
            $subject,
            $message,
            implode("\r\n", $headers)
        );

        if ($sent) {
            updateAlertEmailStatus($conn, $alertId, 'Sent');
            return true;
        }

        updateAlertEmailStatus($conn, $alertId, 'Failed');
        return false;
    }
}

if (!function_exists('updateAlertEmailStatus')) {

    function updateAlertEmailStatus(
        mysqli $conn,
        int $alertId,
        string $status
    ): void {
        $stmt = $conn->prepare(
            "UPDATE alerts SET email_status = ? WHERE id = ?"
        );

        if ($stmt) {
            $stmt->bind_param('si', $status, $alertId);
            $stmt->execute();
            $stmt->close();
        }
    }
}
?>
