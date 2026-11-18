    // =================================================
    // 15. EMAIL STATUS
    // =================================================

    /*
     * Email is intentionally not sent in this version.
     *
     * The patient's email address is stored separately.
     * SMTP configuration will be added and tested next.
     *
     * This prevents PHP mail() from falsely reporting
     * success or failure on a local XAMPP installation.
     */

    $emailPendingConfiguration = 0;
    $emailRecipientsConfigured = 0;

    foreach ($alertIds as $alert) {

        if ($alert['email_status'] === 'NOT_CONFIGURED') {
            $emailPendingConfiguration++;
        }

        if ($alert['email_recipient_configured']) {
            $emailRecipientsConfigured++;
        }
    }

    // =================================================
    // 16. SEND FIREBASE PUSH NOTIFICATIONS
    // =================================================
    // Send only after the database transaction commits. The alert
    // cooldown above ensures the ESP32's 5-second uploads do not
    // produce a new notification every 5 seconds.

    $pushResult = array(
        'attempted' => 0,
        'sent' => 0,
        'failed' => 0,
        'registered_tokens' => 0,
        'status' => 'NO_NEW_ALERTS'
    );

    if ($alertsCreated > 0) {
        try {
            $pushResult = sendFirebasePushNotifications(
                $conn,
                $patientId,
                $deviceId,
                $alertIds,
                $mode
            );
        } catch (Throwable $pushError) {
            // A Firebase outage must not undo readings/alerts already saved.
            $pushResult['status'] = 'ERROR';
            $pushResult['error'] = $pushError->getMessage();
            error_log('Firebase push notification error: ' . $pushError->getMessage());
        }
    }

    // =================================================
    // 17. SUCCESS RESPONSE TO ESP32
    // =================================================

    sendResponse(
        true,
        'Monitoring readings saved successfully.',
        array(
            'patient_id' => $patientId,
            'device_id' => $deviceId,

            'monitoring_id' => $monitorId,

            'ecg_saved' => $hasECG,
            'ecg_id' => $ecgId,
            'sample_count' => $sampleCount,

            'mode' => $mode,
            'recorded_at' => $recordedAt,
            'timezone' => $timezone,

            'alerts_created' => $alertsCreated,
            'alerts_repeated' => $alertsRepeated,
            'alerts_suppressed_by_cooldown' => $alertsSuppressed,

            'push_notifications_attempted' => $pushResult['attempted'],
            'push_notifications_sent' => $pushResult['sent'],
            'push_notifications_failed' => $pushResult['failed'],
            'push_registered_tokens' => $pushResult['registered_tokens'],
            'push_status' => $pushResult['status'],

            'email_notifications_sent' => 0,
            'email_pending_smtp_configuration' => $emailPendingConfiguration,
            'email_recipients_configured' => $emailRecipientsConfigured,

            'alerts' => $alertIds
        ),
        200
    );

} catch (Throwable $e) {

    if (isset($conn) && $conn instanceof mysqli) {

        try {
            $conn->rollback();
        } catch (Throwable $rollbackError) {
            // Ignore rollback failure.
        }
    }

    error_log(
        'api_save_readings.php error: ' . $e->getMessage()
    );

    sendResponse(
        false,
        'Database operation failed. Check PHP error log.',
        array(),
        500
    );
}
