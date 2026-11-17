<?php
/*
 * PATIENT MONITORING SYSTEM
 * File: ecg_history.php
 *
 * Secure ECG history for the logged-in patient and assigned device.
 * Search supports Start Date & Time and End Date & Time.
 */

require_once __DIR__ . '/patient_access.php';

$error = '';
$recordings = [];
$selected_record = null;
$ecg_samples = [];

$start_datetime = trim($_GET['start_datetime'] ?? '');
$end_datetime   = trim($_GET['end_datetime'] ?? '');

$record_id = isset($_GET['record_id'])
    ? filter_var($_GET['record_id'], FILTER_VALIDATE_INT)
    : 0;

try {

    // Verify patient login, account and assigned device.
    $access = require_patient_access($conn);

    $patient_id   = $access['patient_id'];
    $device_id    = $access['device_id'];
    $patient_name = $access['full_name'];

    /*
     * Validate datetime-local input.
     * Expected format: YYYY-MM-DDTHH:MM
     */

    $validDateTime = function ($value) {

        if ($value === '') {
            return true;
        }

        $date = DateTime::createFromFormat(
            '!Y-m-d\TH:i',
            $value
        );

        return $date &&
               $date->format('Y-m-d\TH:i') === $value;
    };

    if (
        !$validDateTime($start_datetime) ||
        !$validDateTime($end_datetime)
    ) {
        throw new Exception('Invalid date and time format.');
    }

    // Convert HTML datetime-local format to MySQL format.
    $start_sql = ($start_datetime !== '')
        ? str_replace('T', ' ', $start_datetime) . ':00'
        : '';

    $end_sql = ($end_datetime !== '')
        ? str_replace('T', ' ', $end_datetime) . ':00'
        : '';

    // Check chronological order.
    if (
        $start_datetime !== '' &&
        $end_datetime !== '' &&
        $start_datetime > $end_datetime
    ) {
        throw new Exception(
            'Start date and time cannot be later than end date and time.'
        );
    }

    if ($record_id === false || (int)$record_id < 0) {
        throw new Exception('Invalid ECG record selection.');
    }

    $record_id = (int)$record_id;

    /*
     * SEARCH ECG RECORDINGS
     *
     * End time is inclusive to the selected minute.
     * For example, an end time of 18:30 includes
     * recordings up to 18:30:59.
     */

    $stmt = $conn->prepare("
        SELECT
            id,
            patient_id,
            device_id,
            sample_rate,
            sample_count,
            mode,
            timezone,
            recorded_at
        FROM ecg_readings
        WHERE patient_id = ?
          AND device_id = ?
          AND (? = '' OR recorded_at >= ?)
          AND (? = '' OR recorded_at < DATE_ADD(?, INTERVAL 1 MINUTE))
        ORDER BY recorded_at DESC, id DESC
        LIMIT 50
    ");

    $stmt->bind_param(
        'ssssss',
        $patient_id,
        $device_id,
        $start_sql,
        $start_sql,
        $end_sql,
        $end_sql
    );

    $stmt->execute();

    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $recordings[] = $row;
    }

    $stmt->close();

    /*
     * LOAD SELECTED ECG RECORDING
     */

    if ($record_id > 0) {

        $stmt = $conn->prepare("
            SELECT
                id,
                patient_id,
                device_id,
                samples_json,
                sample_rate,
                sample_count,
                mode,
                timezone,
                recorded_at
            FROM ecg_readings
            WHERE id = ?
              AND patient_id = ?
              AND device_id = ?
              AND (? = '' OR recorded_at >= ?)
              AND (? = '' OR recorded_at < DATE_ADD(?, INTERVAL 1 MINUTE))
            LIMIT 1
        ");

        $stmt->bind_param(
            'isssssss',
            $record_id,
            $patient_id,
            $device_id,
            $start_sql,
            $start_sql,
            $end_sql,
            $end_sql
        );

        $stmt->execute();

        $selected_record = $stmt->get_result()->fetch_assoc();

        $stmt->close();

        if (!$selected_record) {

            $error = 'The selected ECG recording was not found for your account or selected period.';

            $record_id = 0;
        }

    } elseif (count($recordings) > 0) {

        // Automatically display the newest recording.

        $record_id = (int)$recordings[0]['id'];

        $stmt = $conn->prepare("
            SELECT
                id,
                patient_id,
                device_id,
                samples_json,
                sample_rate,
                sample_count,
                mode,
                timezone,
                recorded_at
            FROM ecg_readings
            WHERE id = ?
              AND patient_id = ?
              AND device_id = ?
            LIMIT 1
        ");

        $stmt->bind_param(
            'iss',
            $record_id,
            $patient_id,
            $device_id
        );

        $stmt->execute();

        $selected_record = $stmt->get_result()->fetch_assoc();

        $stmt->close();
    }

    /*
     * DECODE ECG SAMPLE DATA
     */

    if ($selected_record !== null) {

        $decoded = json_decode(
            $selected_record['samples_json'],
            true
        );

        if (!is_array($decoded)) {

            $error = 'ECG sample data is not valid JSON.';

        } else {

            foreach ($decoded as $sample) {

                if (is_numeric($sample)) {
                    $ecg_samples[] = (float)$sample;
                }
            }
        }
    }

} catch (Throwable $e) {

    error_log(
        'Patient ECG history page error: ' . $e->getMessage()
    );

    if (!isset($patient_id)) {

        http_response_code(500);

        exit('Unable to load ECG history. Please log in again.');
    }

    $error = 'Unable to retrieve ECG history.';
}


// ECG chart preparation.

$sample_rate = $selected_record !== null
    ? (float)$selected_record['sample_rate']
    : 0;

$chart_labels = [];

foreach ($ecg_samples as $index => $value) {

    $chart_labels[] = $sample_rate > 0
        ? round($index / $sample_rate, 4)
        : $index;
}


// Safe JSON output for JavaScript.

function ecg_json($value) {

    return json_encode(
        $value,
        JSON_HEX_TAG |
        JSON_HEX_APOS |
        JSON_HEX_QUOT |
        JSON_HEX_AMP |
        JSON_INVALID_UTF8_SUBSTITUTE
    );
}

?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta name="viewport" content="width=device-width, initial-scale=1">

<title>ECG History</title>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>

body {
    font-family: Arial, sans-serif;
    background: #f3f6fa;
    margin: 0;
    padding: 20px;
    color: #222;
}

.container {
    max-width: 1200px;
    margin: auto;
}

h2, h3 {
    color: #174b75;
}

.panel {
    background: #fff;
    padding: 18px;
    margin-bottom: 20px;
    border-radius: 8px;
    box-shadow: 0 2px 7px rgba(0,0,0,.08);
}

form {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    align-items: end;
}

.field {
    display: flex;
    flex-direction: column;
    gap: 5px;
}

label {
    font-weight: bold;
    font-size: 13px;
}

input, button {
    padding: 9px;
    border: 1px solid #bbb;
    border-radius: 4px;
    font-size: 14px;
}

input[type="datetime-local"] {
    min-width: 220px;
}

button, .button {
    background: #1769aa;
    color: #fff;
    border: 0;
    cursor: pointer;
    text-decoration: none;
    display: inline-block;
    padding: 10px 15px;
    border-radius: 4px;
}

button:hover, .button:hover {
    background: #104b7a;
}

table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}

th, td {
    padding: 10px;
    border: 1px solid #ddd;
    text-align: left;
}

th {
    background: #e8f0f7;
}

tr:nth-child(even) {
    background: #f8fafc;
}

.selected {
    background: #dceeff !important;
}

.error {
    background: #ffe6e6;
    color: #a00000;
    padding: 12px;
    border-radius: 5px;
    margin-bottom: 15px;
}

.info {
    background: #e8f5e9;
    padding: 12px;
    border-radius: 5px;
    margin-bottom: 15px;
}

.chart-area {
    width: 100%;
    height: 420px;
}

.meta {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 12px;
}

.meta div {
    background: #f1f5f9;
    padding: 12px;
    border-radius: 5px;
}

.meta strong {
    display: block;
    color: #174b75;
    margin-bottom: 5px;
}

.nav {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin-bottom: 18px;
}

.search-note {
    font-size: 12px;
    color: #555;
    margin-top: 10px;
}

@media(max-width:700px) {

    body {
        padding: 8px;
    }

    .chart-area {
        height: 300px;
    }

    table {
        font-size: 11px;
    }

    th, td {
        padding: 6px;
    }

    input[type="datetime-local"] {
        min-width: 0;
        width: 100%;
        box-sizing: border-box;
    }

    .field {
        width: 100%;
    }

    form button {
        width: 100%;
    }
}

</style>

</head>

<body>

<div class="container">

<h2>Historical ECG Recordings</h2>

<div class="nav">

<a class="button" href="index.php">Back to Dashboard</a>

<a class="button" href="history.php">Monitoring History</a>

<a class="button" href="alerts.php">Alerts</a>

<a class="button" href="patient_logout.php">Logout</a>

</div>


<!-- PATIENT AND RECORDING PERIOD -->

<div class="panel">

<h3>Patient and Recording Period</h3>

<div class="info">

<strong>Patient:</strong>
<?= patient_h($patient_name) ?>
(<?= patient_h($patient_id) ?>)

&nbsp;

<strong>Assigned Device:</strong>
<?= patient_h($device_id) ?>

</div>


<!-- DATE AND TIME SEARCH -->

<form method="GET" action="ecg_history.php">

<div class="field">

<label for="start_datetime">
Start Date &amp; Time
</label>

<input
    id="start_datetime"
    type="datetime-local"
    name="start_datetime"
    step="60"
    value="<?= patient_h($start_datetime) ?>"
>

</div>


<div class="field">

<label for="end_datetime">
End Date &amp; Time
</label>

<input
    id="end_datetime"
    type="datetime-local"
    name="end_datetime"
    step="60"
    value="<?= patient_h($end_datetime) ?>"
>

</div>


<div class="field">

<button type="submit">
Search ECG
</button>

</div>

</form>

<p class="search-note">
Select both date and time to search a specific recording period.
Leave either field blank if you do not want to apply that time limit.
The selected end minute is included in the search.
</p>

</div>


<?php if ($error !== ''): ?>

<div class="error">
<?= patient_h($error) ?>
</div>

<?php endif; ?>


<!-- ECG RECORDING LIST -->

<div class="panel">

<h3>
ECG Recordings for <?= patient_h($patient_name) ?>
</h3>

<?php if (count($recordings) === 0): ?>

<p>No ECG recordings found for the selected period.</p>

<?php else: ?>

<p>
Showing the latest <?= count($recordings) ?> recordings
(maximum 50).
</p>

<div style="overflow-x:auto">

<table>

<thead>

<tr>
<th>Recording ID</th>
<th>Device ID</th>
<th>Recorded At</th>
<th>Sample Rate</th>
<th>Sample Count</th>
<th>Mode</th>
<th>Timezone</th>
<th>View</th>
</tr>

</thead>

<tbody>

<?php foreach ($recordings as $record): ?>

<tr class="<?= ((int)$record['id'] === $record_id) ? 'selected' : '' ?>">

<td>
<?= patient_h($record['id']) ?>
</td>

<td>
<?= patient_h($record['device_id']) ?>
</td>

<td>
<?= patient_h($record['recorded_at']) ?>
</td>

<td>
<?= patient_h($record['sample_rate']) ?>
</td>

<td>
<?= patient_h($record['sample_count']) ?>
</td>

<td>
<?= patient_h($record['mode']) ?>
</td>

<td>
<?= patient_h($record['timezone']) ?>
</td>

<td>

<a class="button"
href="ecg_history.php?<?= patient_h(http_build_query([

    'start_datetime' => $start_datetime,

    'end_datetime' => $end_datetime,

    'record_id' => $record['id']

])) ?>">

View ECG

</a>

</td>

</tr>

<?php endforeach; ?>

</tbody>

</table>

</div>

<?php endif; ?>

</div>


<!-- SELECTED ECG WAVEFORM -->

<?php if ($selected_record !== null): ?>

<div class="panel">

<h3>Selected ECG Waveform</h3>

<div class="meta">

<div>
<strong>Recording ID</strong>
<?= patient_h($selected_record['id']) ?>
</div>

<div>
<strong>Device ID</strong>
<?= patient_h($selected_record['device_id']) ?>
</div>

<div>
<strong>Recorded At</strong>
<?= patient_h($selected_record['recorded_at']) ?>
</div>

<div>
<strong>Sample Rate</strong>
<?= patient_h($selected_record['sample_rate']) ?> Hz
</div>

<div>
<strong>Sample Count</strong>
<?= patient_h(count($ecg_samples)) ?>
</div>

<div>
<strong>Mode</strong>
<?= patient_h($selected_record['mode']) ?>
</div>

<div>
<strong>Timezone</strong>
<?= patient_h($selected_record['timezone']) ?>
</div>

</div>


<?php if (count($ecg_samples) > 0): ?>

<p>
The horizontal axis shows elapsed time in seconds
when the sample rate is available; otherwise it
shows the sample number.
</p>

<div class="chart-area">

<canvas id="ecgChart"></canvas>

</div>

<?php else: ?>

<p>No valid ECG samples are available in this recording.</p>

<?php endif; ?>

</div>

<?php endif; ?>

</div>


<!-- ECG CHART -->

<?php if (count($ecg_samples) > 0): ?>

<script>

const ecgLabels = <?= ecg_json($chart_labels) ?>;

const ecgValues = <?= ecg_json($ecg_samples) ?>;

const sampleRate = <?= ecg_json($sample_rate) ?>;

const ecgPoints = ecgValues.map((value, index) => ({
    x: ecgLabels[index],
    y: value
}));

const ctx = document.getElementById('ecgChart').getContext('2d');

new Chart(ctx, {

    type: 'line',

    data: {

        datasets: [{

            label: 'ECG Signal',

            data: ecgPoints,

            borderColor: '#d62828',

            borderWidth: 1.5,

            pointRadius: 0,

            fill: false,

            tension: 0

        }]

    },

    options: {

        responsive: true,

        maintainAspectRatio: false,

        animation: false,

        parsing: false,

        scales: {

            x: {

                type: 'linear',

                title: {

                    display: true,

                    text: sampleRate > 0
                        ? 'Elapsed Time (seconds)'
                        : 'Sample Number'

                }

            },

            y: {

                title: {

                    display: true,

                    text: 'ECG Sample Value'

                }

            }

        },

        plugins: {

            legend: {

                display: true

            }

        }

    }

});

</script>

<?php endif; ?>

</body>
</html>
