<?php
declare(strict_types=1);

/*
 * ESP32 PATIENT MONITORING SYSTEM
 * File: index.php
 *
 * Patient Dashboard + Firebase Phone Notifications
 *
 * Security:
 *   - Requires PATIENTSESSID.
 *   - Does not accept patient_id/device_id from URL.
 *   - Revalidates patient account on every request.
 *   - Revalidates administrator-controlled device assignment.
 *   - Checks account and device validity dates.
 *   - Uses Asia/Kolkata time.
 *
 * Platform: XAMPP + PHP + MySQL / Render + TiDB
 *
 * Educational prototype only.
 * Not a medical device.
 */

// --------------------------------------------------
// 1. PATIENT SESSION
// --------------------------------------------------

session_name('PATIENTSESSID');

session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => (
        isset($_SERVER['HTTPS']) &&
        $_SERVER['HTTPS'] !== 'off'
    ),
    'httponly' => true,
    'samesite' => 'Lax'
]);

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');

session_start();

// --------------------------------------------------
// 2. TIMEZONE AND DATABASE
// --------------------------------------------------

date_default_timezone_set('Asia/Kolkata');

require_once __DIR__ . '/config.php';

// --------------------------------------------------
// 3. HELPER FUNCTIONS
// --------------------------------------------------

function h($value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function rejectPatientAccess(): void
{
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {

        $params = session_get_cookie_params();

        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();

    header('Location: patient_login.php');
    exit;
}

// --------------------------------------------------
// 4. REQUIRE PATIENT LOGIN
// --------------------------------------------------

if (
    !isset($_SESSION['patient_logged_in']) ||
    $_SESSION['patient_logged_in'] !== true ||
    !isset(
        $_SESSION['patient_user_id'],
        $_SESSION['patient_device_id'],
        $_SESSION['patient_id']
    )
) {
    rejectPatientAccess();
}

$user_id = (int)$_SESSION['patient_user_id'];

$session_device_id =
    (string)$_SESSION['patient_device_id'];

$session_patient_id =
    (string)$_SESSION['patient_id'];

if (
    $user_id <= 0 ||
    $session_device_id === '' ||
    $session_patient_id === ''
) {
    rejectPatientAccess();
}

// --------------------------------------------------
// 5. VERIFY PATIENT ACCOUNT AND DEVICE ASSIGNMENT
// --------------------------------------------------

try {

    $now = date('Y-m-d H:i:s');

    $sql = "
        SELECT
            wu.id,
            wu.username,
            wu.device_id,
            pd.patient_id,
            p.full_name,
            p.age,
            p.gender,
            p.blood_group,
            p.contact

        FROM web_users AS wu

        INNER JOIN patient_devices AS pd
            ON pd.device_id = wu.device_id

        INNER JOIN patients AS p
            ON p.patient_id = pd.patient_id

        WHERE
            wu.id = ?
            AND wu.device_id = ?
            AND pd.patient_id = ?

            AND wu.role = 'patient'
            AND wu.enabled = 1

            AND (
                wu.valid_from IS NULL
                OR wu.valid_from <= ?
            )

            AND (
                wu.valid_until IS NULL
                OR wu.valid_until >= ?
            )

            AND pd.enabled = 1

            AND (
                pd.start_date_time IS NULL
                OR pd.start_date_time <= ?
            )

            AND (
                pd.end_date_time IS NULL
                OR pd.end_date_time >= ?
            )

        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new RuntimeException(
            'Could not prepare patient access query.'
        );
    }

    $stmt->bind_param(
        'issssss',
        $user_id,
        $session_device_id,
        $session_patient_id,
        $now,
        $now,
        $now,
        $now
    );

    $stmt->execute();

    $stmt->bind_result(
        $verified_user_id,
        $verified_username,
        $verified_device_id,
        $verified_patient_id,
        $patient_name,
        $patient_age,
        $patient_gender,
        $patient_blood_group,
        $patient_contact
    );

    $access_valid = $stmt->fetch();

    $stmt->close();

    if (!$access_valid) {
        rejectPatientAccess();
    }

    $patient_id = (string)$verified_patient_id;
    $device_id = (string)$verified_device_id;

    $_SESSION['patient_username'] = $verified_username;
    $_SESSION['patient_name'] = $patient_name;
    $_SESSION['patient_device_id'] = $device_id;
    $_SESSION['patient_id'] = $patient_id;

} catch (Throwable $e) {

    error_log(
        'Patient dashboard access error: ' . $e->getMessage()
    );

    http_response_code(500);

    exit(
        'Unable to verify patient access. Please try again later.'
    );
}

// --------------------------------------------------
// 6. API AND NAVIGATION URLS
// --------------------------------------------------

$api_url = 'api_latest.php';

$history_url = 'history.php';

$ecg_url = 'ecg_history.php';

$alerts_url = 'alerts.php';

?>
<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport"
      content="width=device-width, initial-scale=1.0">

<title>Patient Monitoring Dashboard</title>

<style>

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    padding: 0;
    font-family: Arial, Helvetica, sans-serif;
    background: #f1f5f9;
    color: #1e293b;
}

.header {
    background: #123b64;
    color: white;
    padding: 20px;
    text-align: center;
}

.header h1 {
    margin: 0 0 8px;
    font-size: 27px;
}

.header p {
    margin: 4px;
    font-size: 14px;
}

.container {
    width: 95%;
    max-width: 1250px;
    margin: 20px auto;
}

.patient-box {
    background: white;
    border-radius: 10px;
    padding: 18px;
    margin-bottom: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.patient-box h2 {
    color: #123b64;
    margin-top: 0;
}

.patient-details {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 12px;
}

.detail {
    background: #f1f5f9;
    padding: 12px;
    border-radius: 6px;
}

.detail strong {
    display: block;
    margin-bottom: 5px;
    color: #475569;
}

.status {
    padding: 12px;
    margin-bottom: 20px;
    border-radius: 7px;
    background: #e0f2fe;
    color: #075985;
    font-weight: bold;
}

.status.error {
    background: #fee2e2;
    color: #991b1b;
}

.section-title {
    color: #123b64;
    margin: 25px 0 15px;
}

.sensor-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
}

.sensor-card {
    background: white;
    padding: 20px;
    border-radius: 10px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    text-align: center;
    border-top: 4px solid #2563eb;
}

.sensor-card h3 {
    margin: 5px 0 12px;
    color: #475569;
    font-size: 16px;
}

.sensor-value {
    font-size: 32px;
    font-weight: bold;
    color: #123b64;
}

.sensor-unit {
    font-size: 14px;
    color: #64748b;
}

.mode-box {
    margin-top: 18px;
    padding: 15px;
    background: white;
    border-radius: 8px;
}

.ecg-box {
    background: white;
    padding: 18px;
    border-radius: 10px;
    margin-top: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

#ecgCanvas {
    width: 100%;
    height: 220px;
    display: block;
    background: #071c2b;
    border-radius: 5px;
}

.ecg-note {
    color: #64748b;
    font-size: 13px;
    margin-top: 10px;
}

.navigation {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    margin-top: 22px;
}

.nav-button {
    display: inline-block;
    background: #2563eb;
    color: white;
    padding: 12px 20px;
    text-decoration: none;
    border-radius: 6px;
    font-weight: bold;
}

.nav-button:hover {
    background: #1d4ed8;
}

/* Firebase phone notification panel */

.push-box {
    background: white;
    border-radius: 10px;
    padding: 20px;
    margin: 20px 0;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    border-left: 5px solid #2563eb;
}

.push-box h2 {
    color: #123b64;
    margin-top: 0;
}

.push-button {
    display: inline-block;
    background: #15803d;
    color: white;
    padding: 13px 22px;
    border: none;
    border-radius: 6px;
    font-size: 16px;
    font-weight: bold;
    cursor: pointer;
}

.push-button:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

.push-status {
    margin-top: 14px;
    padding: 12px;
    border-radius: 6px;
    background: #e0f2fe;
    color: #075985;
    font-size: 14px;
    overflow-wrap: anywhere;
}

.push-status.error {
    background: #fee2e2;
    color: #991b1b;
}

.push-status.success {
    background: #dcfce7;
    color: #166534;
}

.refresh-info {
    margin-top: 15px;
    color: #64748b;
    font-size: 13px;
}

.footer {
    margin-top: 35px;
    padding: 20px;
    text-align: center;
    background: #123b64;
    color: white;
    font-size: 13px;
}

@media(max-width:600px) {

    .header h1 {
        font-size: 21px;
    }

    .sensor-value {
        font-size: 27px;
    }

    .nav-button,
    .push-button {
        width: 100%;
        text-align: center;
    }

}

</style>

<!-- Firebase Web SDK -->

<script src="https://www.gstatic.com/firebasejs/10.13.2/firebase-app-compat.js"></script>

<script src="https://www.gstatic.com/firebasejs/10.13.2/firebase-messaging-compat.js"></script>

</head>

<body>

<div class="header">

    <h1>ESP32 Patient Monitoring System</h1>

    <p>Real-Time Patient Health Monitoring Dashboard</p>

    <p>
        Patient ID:
        <?php echo h($patient_id); ?>
    </p>

</div>

<div class="container">

    <div class="patient-box">

        <h2>Patient Information</h2>

        <div class="patient-details">

            <div class="detail">
                <strong>Patient Name</strong>
                <span id="patientName"><?php echo h($patient_name); ?></span>
            </div>

            <div class="detail">
                <strong>Patient ID</strong>
                <span><?php echo h($patient_id); ?></span>
            </div>

            <div class="detail">
                <strong>Age</strong>
                <span id="patientAge"><?php echo h($patient_age); ?></span>
            </div>

            <div class="detail">
                <strong>Gender</strong>
                <span id="patientGender"><?php echo h($patient_gender); ?></span>
            </div>

            <div class="detail">
                <strong>Blood Group</strong>
                <span id="bloodGroup"><?php echo h($patient_blood_group); ?></span>
            </div>

            <div class="detail">
                <strong>Contact</strong>
                <span id="patientContact"><?php echo h($patient_contact); ?></span>
            </div>

        </div>

    </div>

    <!-- PHONE NOTIFICATION REGISTRATION -->

    <div class="push-box">

        <h2>Android Phone Notifications</h2>

        <p>
            Enable notifications to receive patient monitoring
            alerts on this phone, including when Chrome is in
            the background.
        </p>

        <button
            type="button"
            id="enablePushButton"
            class="push-button"
        >
            Enable Phone Notifications
        </button>

        <div id="pushStatus" class="push-status">
            Phone notification registration has not yet been completed.
        </div>

    </div>

    <div id="connectionStatus" class="status">
        Connecting to patient monitoring server...
    </div>

    <h2 class="section-title">Live Sensor Readings</h2>

    <div class="sensor-grid">

        <div class="sensor-card">
            <h3>Room Temperature</h3>
            <div class="sensor-value" id="roomTemp">--</div>
            <div class="sensor-unit">Degrees Celsius</div>
        </div>

        <div class="sensor-card">
            <h3>Humidity</h3>
            <div class="sensor-value" id="humidity">--</div>
            <div class="sensor-unit">Percent (%)</div>
        </div>

        <div class="sensor-card">
            <h3>Heart Rate</h3>
            <div class="sensor-value" id="bpm">--</div>
            <div class="sensor-unit">Beats per minute</div>
        </div>

        <div class="sensor-card">
            <h3>Blood Oxygen (SpO2)</h3>
            <div class="sensor-value" id="spo2">--</div>
            <div class="sensor-unit">Percent (%)</div>
        </div>

        <div class="sensor-card">
            <h3>Body Temperature</h3>
            <div class="sensor-value" id="bodyTemp">--</div>
            <div class="sensor-unit">Degrees Celsius</div>
        </div>

    </div>

    <div class="mode-box">

        <strong>Operating Mode:</strong>
        <span id="operatingMode">--</span>

        <br><br>

        <strong>Device ID:</strong>
        <span id="deviceID"><?php echo h($device_id); ?></span>

        <br><br>

        <strong>Last Reading:</strong>
        <span id="lastReading">--</span>

    </div>

    <div class="ecg-box">

        <h2 class="section-title">Live ECG Waveform</h2>

        <canvas id="ecgCanvas"></canvas>

        <div class="ecg-note">
            ECG waveform is displayed using the latest stored
            ECG samples received from the monitoring device.
            It is an educational display and not a diagnostic ECG.
        </div>

    </div>

    <h2 class="section-title">Patient Records</h2>

    <div class="navigation">

        <a class="nav-button"
           href="<?php echo h($history_url); ?>">
           Monitoring History
        </a>

        <a class="nav-button"
           href="<?php echo h($ecg_url); ?>">
           ECG History
        </a>

        <a class="nav-button"
           href="<?php echo h($alerts_url); ?>">
           Patient Alerts
        </a>

        <a class="nav-button"
           href="patient_logout.php">
           Logout
        </a>

    </div>

    <div class="refresh-info">

        Dashboard refreshes automatically every 5 seconds.

        <br>

        Server Time:
        <span id="serverTime">--</span>

    </div>

</div>

<div class="footer">

    ESP32 Patient Monitoring Prototype

    <br><br>

    Educational use only. Not a medical device.

</div>

<script>

// ==================================================
// A. FIREBASE CONFIGURATION
// ==================================================

const firebaseConfig = {
    apiKey: "AIzaSyDMvg_UpBpMD2LxXaaEr4nJjqFACmDMwBQ",
    authDomain: "patientmonitoringsystem-7fb4a.firebaseapp.com",
    projectId: "patientmonitoringsystem-7fb4a",
    storageBucket: "patientmonitoringsystem-7fb4a.firebasestorage.app",
    messagingSenderId: "162369941103",
    appId: "1:162369941103:web:cf94586b2c00137264279a",
    measurementId: "G-BS5RCBNLY4"
};

// Public Web Push VAPID key from the same Firebase project.
const FIREBASE_VAPID_KEY =
    "BKcwLmwy8zpY6pQWOwh1RYS1kb_60t9oQX1mX5pCLlNHyqxGfAOjkpLWSTnaHYsNGyfyiSc1TZH6e8FWCW8px-E";

const SAVE_PUSH_TOKEN_URL = 'save_push_token.php';

let firebaseMessaging = null;
let firebaseReady = false;

// ==================================================
// B. NOTIFICATION STATUS
// ==================================================

function setPushStatus(message, type = '') {

    const element = document.getElementById('pushStatus');

    if (!element) return;

    element.textContent = message;

    element.className = 'push-status';

    if (type) {
        element.classList.add(type);
    }

}

// ==================================================
// C. INITIALIZE FIREBASE
// ==================================================

try {

    if (typeof firebase !== 'undefined') {

        if (firebase.apps.length === 0) {
            firebase.initializeApp(firebaseConfig);
        }

        firebaseMessaging = firebase.messaging();

        firebaseReady = true;

    } else {

        console.error('Firebase SDK did not load.');

    }

} catch (error) {

    console.error(
        'Firebase initialization error:',
        error
    );

}

// ==================================================
// D. TIMEOUT HELPER
// ==================================================

function withTimeout(promise, milliseconds, message) {

    let timeoutId;

    const timeoutPromise = new Promise((resolve, reject) => {

        timeoutId = setTimeout(() => {
            reject(new Error(message));
        }, milliseconds);

    });

    return Promise.race([
        promise,
        timeoutPromise
    ]).finally(() => {
        clearTimeout(timeoutId);
    });

}

// ==================================================
// E. REGISTER PHONE / DESKTOP BROWSER
// ==================================================

async function enablePhoneNotifications() {

    const button =
        document.getElementById('enablePushButton');

    button.disabled = true;

    try {

        if (!window.isSecureContext) {

            throw new Error(
                'HTTPS is required for notifications.'
            );

        }

        if (!('serviceWorker' in navigator)) {

            throw new Error(
                'This browser does not support service workers.'
            );

        }

        if (!('Notification' in window)) {

            throw new Error(
                'This browser does not support notifications.'
            );

        }

        if (!firebaseReady || !firebaseMessaging) {

            throw new Error(
                'Firebase could not initialize. Check internet connection.'
            );

        }

        // ------------------------------------------
        // 1. Request notification permission
        // ------------------------------------------

        setPushStatus(
            'Checking browser notification permission...'
        );

        let permission = Notification.permission;

        if (permission !== 'granted') {

            permission = await Notification.requestPermission();

        }

        if (permission !== 'granted') {

            throw new Error(
                'Notification permission was not granted.'
            );

        }

        // ------------------------------------------
        // 2. Register the updated service worker.
        // Always call register so Chrome checks for
        // the new Firebase configuration.
        // ------------------------------------------

        setPushStatus(
            'Registering updated Firebase service worker...'
        );

        const registration = await withTimeout(

            navigator.serviceWorker.register(
                '/firebase-messaging-sw.js',
                {
                    scope: '/',
                    updateViaCache: 'none'
                }
            ),

            30000,

            'Service-worker registration timed out.'

        );

        // Ask the browser to check for a newer worker.
        await withTimeout(

            registration.update(),

            20000,

            'Service-worker update check timed out.'

        );

        // ------------------------------------------
        // 3. Wait for the service worker to be active
        // ------------------------------------------

        setPushStatus(
            'Waiting for Firebase service worker activation...'
        );

        const readyRegistration = await withTimeout(

            navigator.serviceWorker.ready,

            30000,

            'Service worker activation timed out.'

        );

        const activeRegistration =
            readyRegistration || registration;

        if (
            !activeRegistration.active ||
            activeRegistration.active.state !== 'activated'
        ) {

            throw new Error(
                'Service worker is not active. Please reload the page and try again.'
            );

        }

        // ------------------------------------------
        // 4. Obtain Firebase Cloud Messaging token
        // ------------------------------------------

        setPushStatus(
            'Service worker is active. Obtaining browser notification token...'
        );

        const token = await withTimeout(

            firebaseMessaging.getToken({

                vapidKey: FIREBASE_VAPID_KEY,

                serviceWorkerRegistration: activeRegistration

            }),

            30000,

            'Firebase token request timed out. Check Firebase configuration and browser internet access.'

        );

        if (!token) {

            throw new Error(
                'Firebase did not return a registration token.'
            );

        }

        // ------------------------------------------
        // 5. Save token securely on PHP server
        // ------------------------------------------

        setPushStatus(
            'Token obtained. Saving browser registration securely...'
        );

        const response = await withTimeout(

            fetch(
                SAVE_PUSH_TOKEN_URL,
                {

                    method: 'POST',

                    credentials: 'same-origin',

                    cache: 'no-store',

                    headers: {
                        'Content-Type': 'application/json'
                    },

                    body: JSON.stringify({
                        token: token
                    })

                }
            ),

            20000,

            'Server did not respond while saving the notification token.'

        );

        let result;

        try {

            result = await response.json();

        } catch (error) {

            throw new Error(
                'Server returned an invalid response while saving the notification token. HTTP status: ' +
                response.status
            );

        }

        if (!response.ok || !result.success) {

            throw new Error(
                result.message ||
                'Could not save browser registration. HTTP status: ' +
                response.status
            );

        }

        // ------------------------------------------
        // 6. Registration successful
        // ------------------------------------------

        setPushStatus(
            'SUCCESS! This browser is registered for patient monitoring notifications.',
            'success'
        );

        button.textContent =
            'Notifications Enabled';

        console.log(
            'Browser notification registration completed successfully.'
        );

    } catch (error) {

        console.error(
            'Browser notification registration error:',
            error
        );

        setPushStatus(
            'Registration failed: ' + error.message,
            'error'
        );

        button.disabled = false;

    }

}

// Register only after an explicit button click.

document.getElementById('enablePushButton')
    .addEventListener(
        'click',
        enablePhoneNotifications
    );

// ==================================================
// F. FOREGROUND NOTIFICATIONS
// ==================================================

if (firebaseReady && firebaseMessaging) {

    firebaseMessaging.onMessage(function(payload) {

        const data = payload.data || {};

        const title =
            data.title || 'Patient Monitoring Alert';

        const body =
            data.body ||
            'An abnormal patient reading was detected.';

        setPushStatus(
            'A new patient monitoring notification has arrived.',
            'success'
        );

        if (
            'Notification' in window &&
            Notification.permission === 'granted'
        ) {

            try {

                const notification =
                    new Notification(title, {

                        body: body,

                        tag: data.alert_id ||
                             'patient-monitor-alert'

                    });

                notification.onclick = function() {

                    window.focus();

                    notification.close();

                };

            } catch (error) {

                console.warn(
                    'Foreground notification display issue:',
                    error
                );

            }

        }

    });

}

// ==================================================
// G. EXISTING DASHBOARD FUNCTIONS
// ==================================================

const API_URL = <?php echo json_encode($api_url); ?>;

function showValue(id, value, suffix = '') {

    const element = document.getElementById(id);

    if (!element) return;

    if (
        value === null ||
        value === undefined ||
        value === ''
    ) {

        element.textContent = '--';

    } else {

        element.textContent = value + suffix;

    }

}

function showPatient(patient) {

    if (!patient) return;

    showValue('patientName', patient.full_name);

    showValue('patientAge', patient.age);

    showValue('patientGender', patient.gender);

    showValue('bloodGroup', patient.blood_group);

    showValue('patientContact', patient.contact);

}

function showMonitoring(data) {

    if (!data) {

        showValue('roomTemp', '--');

        showValue('humidity', '--');

        showValue('bpm', '--');

        showValue('spo2', '--');

        showValue('bodyTemp', '--');

        showValue('operatingMode', '--');

        showValue('deviceID', '--');

        showValue('lastReading', '--');

        return;
    }

    showValue('roomTemp', data.room_temperature);

    showValue('humidity', data.humidity);

    showValue('bpm', data.bpm);

    showValue('spo2', data.spo2);

    showValue('bodyTemp', data.body_temperature);

    showValue('operatingMode', data.mode);

    showValue('deviceID', data.device_id);

    showValue('lastReading', data.recorded_at);

}

// ==================================================
// H. ECG SAMPLE EXTRACTION
// ==================================================

function extractECGSamples(ecg) {

    if (!ecg) return [];

    let samples = ecg.samples_json;

    if (typeof samples === 'string') {

        try {

            samples = JSON.parse(samples);

        } catch (error) {

            return [];

        }

    }

    if (Array.isArray(samples)) {
        return samples;
    }

    if (
        samples &&
        Array.isArray(samples.samples)
    ) {
        return samples.samples;
    }

    if (
        samples &&
        Array.isArray(samples.data)
    ) {
        return samples.data;
    }

    return [];

}

// ==================================================
// I. DRAW ECG WAVEFORM
// ==================================================

function drawECG(samples) {

    const canvas =
        document.getElementById('ecgCanvas');

    if (!canvas) return;

    const rect = canvas.getBoundingClientRect();

    if (!rect.width || !rect.height) return;

    const ratio = window.devicePixelRatio || 1;

    canvas.width = rect.width * ratio;

    canvas.height = rect.height * ratio;

    const ctx = canvas.getContext('2d');

    ctx.setTransform(
        ratio,
        0,
        0,
        ratio,
        0,
        0
    );

    const width = rect.width;

    const height = rect.height;

    ctx.fillStyle = '#071c2b';

    ctx.fillRect(0, 0, width, height);

    // ECG grid

    ctx.strokeStyle = '#18394a';

    ctx.lineWidth = 1;

    for (let x = 0; x < width; x += 20) {

        ctx.beginPath();

        ctx.moveTo(x, 0);

        ctx.lineTo(x, height);

        ctx.stroke();

    }

    for (let y = 0; y < height; y += 20) {

        ctx.beginPath();

        ctx.moveTo(0, y);

        ctx.lineTo(width, y);

        ctx.stroke();

    }

    if (!samples || samples.length < 2) {

        ctx.fillStyle = '#ffffff';

        ctx.font = '14px Arial';

        ctx.fillText(
            'Waiting for ECG samples...',
            15,
            30
        );

        return;
    }

    const values = samples.map(item => {

        if (typeof item === 'number') {
            return item;
        }

        if (
            typeof item === 'object' &&
            item !== null
        ) {

            return Number(
                item.value ??
                item.ecg ??
                item.sample ??
                0
            );

        }

        return Number(item);

    }).filter(Number.isFinite);

    if (values.length < 2) return;

    const minimum = Math.min(...values);

    const maximum = Math.max(...values);

    const range = maximum - minimum || 1;

    const padding = 15;

    ctx.strokeStyle = '#00ff80';

    ctx.lineWidth = 2;

    ctx.beginPath();

    values.forEach((value, index) => {

        const x = padding +
            (index / (values.length - 1)) *
            (width - 2 * padding);

        const y = height - padding -
            ((value - minimum) / range) *
            (height - 2 * padding);

        if (index === 0) {

            ctx.moveTo(x, y);

        } else {

            ctx.lineTo(x, y);

        }

    });

    ctx.stroke();

}

// ==================================================
// J. LOAD LIVE DASHBOARD
// ==================================================

async function loadDashboard() {

    const status =
        document.getElementById('connectionStatus');

    try {

        const response = await fetch(API_URL, {

            method: 'GET',

            cache: 'no-store',

            credentials: 'same-origin'

        });

        if (
            response.status === 401 ||
            response.status === 403
        ) {

            window.location.href = 'patient_login.php';

            return;

        }

        if (!response.ok) {

            throw new Error(
                'HTTP error: ' + response.status
            );

        }

        const result = await response.json();

        if (!result.success) {

            throw new Error(
                result.message ||
                'Unable to load patient data.'
            );

        }

        showPatient(result.patient);

        showMonitoring(result.monitoring);

        const ecgSamples =
            extractECGSamples(result.ecg);

        drawECG(ecgSamples);

        status.className = 'status';

        status.textContent = result.monitoring
            ? 'Connected - Latest monitoring data received.'
            : 'Connected - Waiting for monitoring readings.';

        showValue('serverTime', result.server_time);

    } catch (error) {

        status.className = 'status error';

        status.textContent =
            'Connection error: ' + error.message;

        console.error(
            'Dashboard loading error:',
            error
        );

    }

}

// ==================================================
// K. INITIALIZE DASHBOARD
// ==================================================

drawECG([]);

loadDashboard();

setInterval(loadDashboard, 5000);

window.addEventListener('resize', function() {

    loadDashboard();

});

</script>

</body>
</html>
