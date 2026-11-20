<?php
/*
 * PATIENT MONITORING SYSTEM
 * File: history.php
 *
 * Secure historical monitoring records and graphs.
 * Search by Start Date, Start Time, End Date and End Time.
 */

require_once __DIR__ . '/patient_access.php';

$message = '';
$rows = [];

$start_date = trim($_GET['start_date'] ?? '');
$start_time = trim($_GET['start_time'] ?? '');
$end_date   = trim($_GET['end_date'] ?? '');
$end_time   = trim($_GET['end_time'] ?? '');

try {

    // Verify patient login and assigned device.
    $access = require_patient_access($conn);

    $patient_id = $access['patient_id'];
    $device_id  = $access['device_id'];

    $patient = [
        'patient_id' => $patient_id,
        'full_name'  => $access['full_name']
    ];

    // Validate date.
    $validDate = function ($date) {

        if ($date === '') {
            return true;
        }

        $d = DateTime::createFromFormat('!Y-m-d', $date);

        return $d && $d->format('Y-m-d') === $date;
    };

    // Validate time including optional seconds.
    $validTime = function ($time) {

        if ($time === '') {
            return true;
        }

        foreach (['H:i', 'H:i:s'] as $format) {

            $t = DateTime::createFromFormat('!' . $format, $time);

            if ($t && $t->format($format) === $time) {
                return true;
            }
        }

        return false;
    };

    if (
        !$validDate($start_date) ||
        !$validDate($end_date) ||
        !$validTime($start_time) ||
        !$validTime($end_time)
    ) {
        throw new Exception('Invalid date or time format.');
    }

    // Time requires its corresponding date.
    if ($start_date === '' && $start_time !== '') {
        throw new Exception('Please select Start Date.');
    }

    if ($end_date === '' && $end_time !== '') {
        throw new Exception('Please select End Date.');
    }

    // Build start datetime.
    $start_datetime = '';

    if ($start_date !== '') {

        $time = ($start_time !== '') ? $start_time : '00:00:00';

        if (strlen($time) === 5) {
            $time .= ':00';
        }

        $start_datetime = $start_date . ' ' . $time;
    }

    // Build exclusive end datetime.
    $end_datetime = '';

    if ($end_date !== '') {

        if ($end_time !== '') {

            $time = $end_time;

            if (strlen($time) === 5) {
                $time .= ':00';
            }

            // Include the selected second.
            $end_datetime = (new DateTimeImmutable(
                $end_date . ' ' . $time
            ))->modify('+1 second')->format('Y-m-d H:i:s');

        } else {

            // No end time: include the complete end date.
            $end_datetime = (new DateTimeImmutable($end_date))
                ->modify('+1 day')
                ->format('Y-m-d H:i:s');
        }
    }

    // Validate search range.
    if (
        $start_datetime !== '' &&
        $end_datetime !== '' &&
        $start_datetime >= $end_datetime
    ) {
        throw new Exception(
            'Start Date/Time must be earlier than End Date/Time.'
        );
    }

    /*
     * Retrieve only records belonging to the logged-in
     * patient and their assigned device.
     */

    $stmt = $conn->prepare("
        SELECT
            id,
            patient_id,
            device_id,
            room_temperature,
            humidity,
            bpm,
            spo2,
            body_temperature,
            mode,
            recorded_at,
            timezone
        FROM monitoring_readings
        WHERE patient_id = ?
          AND device_id = ?
          AND (? = '' OR recorded_at >= ?)
          AND (? = '' OR recorded_at < ?)
        ORDER BY recorded_at ASC, id ASC
        LIMIT 1000
    ");

    $stmt->bind_param(
        'ssssss',
        $patient_id,
        $device_id,
        $start_datetime,
        $start_datetime,
        $end_datetime,
        $end_datetime
    );

    $stmt->execute();

    $result = $stmt->get_result();

    while ($record = $result->fetch_assoc()) {
        $rows[] = $record;
    }

    $stmt->close();

} catch (Throwable $e) {

    error_log('Patient history page error: ' . $e->getMessage());

    if (!isset($patient_id)) {
        http_response_code(500);
        exit('Unable to load patient history. Please log in again.');
    }

    $message = 'Unable to retrieve historical records. ' . $e->getMessage();
}


// --------------------------------------------------
// PREPARE GRAPH DATA
// --------------------------------------------------

$labels = [];
$room_temperature = [];
$humidity = [];
$bpm = [];
$spo2 = [];
$body_temperature = [];

foreach ($rows as $r) {

    $labels[] = $r['recorded_at'];

    $room_temperature[] =
        $r['room_temperature'] !== null
        ? (float)$r['room_temperature'] : null;

    $humidity[] =
        $r['humidity'] !== null
        ? (float)$r['humidity'] : null;

    $bpm[] =
        $r['bpm'] !== null
        ? (float)$r['bpm'] : null;

    $spo2[] =
        $r['spo2'] !== null
        ? (float)$r['spo2'] : null;

    $body_temperature[] =
        $r['body_temperature'] !== null
        ? (float)$r['body_temperature'] : null;
}

// Latest records first in table.
$table_rows = array_reverse($rows);


// Safely encode graph data.
function history_json($value) {

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

<title>Patient Monitoring History</title>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>

/* GENERAL */

body {
    font-family: Arial, sans-serif;
    background: #f2f5f9;
    margin: 0;
    padding: 20px;
    color: #263445;
}

.container {
    max-width: 1250px;
    margin: auto;
}

h1 {
    text-align: center;
    color: #173d66;
}

.panel {
    background: #fff;
    padding: 20px;
    margin: 20px 0;
    border-radius: 9px;
    box-shadow: 0 2px 8px rgba(0,0,0,.08);
}


/* NAVIGATION */

.nav {
    display: flex;
    gap: 15px;
    flex-wrap: wrap;
}

button, .btn {
    display: inline-block;
    padding: 11px 18px;
    background: #2469a8;
    color: #fff;
    border: 0;
    border-radius: 5px;
    cursor: pointer;
    text-decoration: none;
    font-size: 14px;
}

.clear {
    background: #687786;
}


/* SEARCH FILTERS */

.filters {
    display: grid;
    grid-template-columns: repeat(4, minmax(150px, 1fr));
    gap: 15px;
    margin-top: 20px;
}

.filter-item {
    min-width: 0;
}

label {
    display: block;
    font-weight: bold;
    margin-bottom: 8px;
    color: #173d66;
}

.filters input {
    display: block;
    width: 100%;
    min-height: 46px;
    box-sizing: border-box;
    padding: 10px;
    border: 1px solid #8b9bad;
    border-radius: 5px;
    font-size: 15px;
    background: #ffffff;
    color: #263445;
}

.filters input:focus {
    outline: 2px solid #2469a8;
    border-color: #2469a8;
}

.filter-hint {
    color: #687786;
    font-size: 13px;
    margin-top: 15px;
}


/* MESSAGES */

.message {
    padding: 12px;
    background: #fbe0df;
    color: #a12020;
    border-radius: 5px;
    margin: 15px 0;
}


/* PATIENT SUMMARY */

.summary {
    display: flex;
    flex-wrap: wrap;
    gap: 15px;
}

.summary-item {
    background: #edf3f9;
    padding: 14px;
    border-radius: 7px;
    flex: 1;
    min-width: 160px;
}

.summary-item strong {
    display: block;
    font-size: 20px;
    margin-top: 7px;
}


/* GRAPHS */

.chart-grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 18px;
}

.chart-box {
    background: #fff;
    padding: 15px;
    border: 1px solid #e1e6ec;
    border-radius: 8px;
    min-width: 0;
}

.chart-box canvas {
    width: 100% !important;
    height: 260px !important;
}

.chart-full {
    grid-column: 1 / -1;
}


/* HISTORY TABLE */

.table-wrap {
    overflow-x: auto;
}

table {
    width: 100%;
    min-width: 1050px;
    border-collapse: collapse;
}

th, td {
    border: 1px solid #ddd;
    padding: 10px;
    text-align: center;
}

th {
    background: #173d66;
    color: #fff;
}

tr:nth-child(even) {
    background: #f5f8fb;
}

.no-data {
    padding: 25px;
    text-align: center;
    color: #687786;
}


/* RESPONSIVE */

@media(max-width:850px) {

    .filters {
        grid-template-columns: repeat(2, minmax(0, 1fr));
    }

}

@media(max-width:750px) {

    body {
        padding: 8px;
    }

    .chart-grid {
        grid-template-columns: 1fr;
    }

    .chart-full {
        grid-column: auto;
    }

    .panel {
        padding: 14px;
    }

}

@media(max-width:450px) {

    .filters {
        grid-template-columns: 1fr;
    }

}

</style>

</head>

<body>

<div class="container">

<h1>Patient Monitoring History</h1>


<!-- NAVIGATION -->

<div class="nav">

<a class="btn" href="index.php">Back to Dashboard</a>

<a class="btn" href="ecg_history.php">ECG History</a>

<a class="btn" href="alerts.php">Alerts</a>

<a class="btn clear" href="patient_logout.php">Logout</a>

</div>


<?php if ($message !== ''): ?>

<div class="message">
    <?= patient_h($message) ?>
</div>

<?php endif; ?>


<!-- SEARCH HISTORICAL RECORDS -->

<div class="panel">

<h2>Search Historical Records</h2>

<div>

<strong>Patient:</strong>

<?= patient_h($patient['full_name']) ?>

(<?= patient_h($patient_id) ?>)

&nbsp;&nbsp;

<strong>Assigned Device:</strong>

<?= patient_h($device_id) ?>

</div>


<form method="GET" action="history.php">

<div class="filters">

    <!-- START DATE -->

    <div class="filter-item">

        <label for="start_date">
            Start Date
        </label>

        <input
            type="date"
            id="start_date"
            name="start_date"
            value="<?= patient_h($start_date) ?>"
        >

    </div>


    <!-- START TIME -->

    <div class="filter-item">

        <label for="start_time">
            Start Time
        </label>

        <input
            type="time"
            id="start_time"
            name="start_time"
            step="1"
            value="<?= patient_h($start_time) ?>"
        >

    </div>


    <!-- END DATE -->

    <div class="filter-item">

        <label for="end_date">
            End Date
        </label>

        <input
            type="date"
            id="end_date"
            name="end_date"
            value="<?= patient_h($end_date) ?>"
        >

    </div>


    <!-- END TIME -->

    <div class="filter-item">

        <label for="end_time">
            End Time
        </label>

        <input
            type="time"
            id="end_time"
            name="end_time"
            step="1"
            value="<?= patient_h($end_time) ?>"
        >

    </div>

</div>


<p class="filter-hint">

Select both dates and times to search a specific period.

If time is left blank, the complete selected date is included.

</p>

<br>

<button type="submit">
    Search Records
</button>

<a href="history.php" class="btn clear">
    Clear Search
</a>

</form>

</div>


<!-- PATIENT INFORMATION -->

<div class="panel">

<h2>Patient Information</h2>

<div class="summary">

    <div class="summary-item">

        Patient ID

        <strong>
            <?= patient_h($patient_id) ?>
        </strong>

    </div>


    <div class="summary-item">

        Patient Name

        <strong>
            <?= patient_h($patient['full_name']) ?>
        </strong>

    </div>


    <div class="summary-item">

        Assigned Device

        <strong>
            <?= patient_h($device_id) ?>
        </strong>

    </div>


    <div class="summary-item">

        Total Records Found

        <strong>
            <?= count($rows) ?>
        </strong>

    </div>

</div>


<?php if (count($rows) === 1000): ?>

<p>
    Showing the first 1000 records in the selected period.
    Narrow the date/time range to examine other records.
</p>

<?php endif; ?>

</div>


<!-- GRAPHS AND TABLE -->

<?php if (count($rows) > 0): ?>


<!-- HISTORICAL GRAPHS -->

<div class="panel">

<h2>Historical Sensor Graphs</h2>

<div class="chart-grid">


    <div class="chart-box">

        <h3>Room Temperature (°C)</h3>

        <canvas id="roomChart"></canvas>

    </div>


    <div class="chart-box">

        <h3>Humidity (%)</h3>

        <canvas id="humidityChart"></canvas>

    </div>


    <div class="chart-box">

        <h3>Heart Rate (BPM)</h3>

        <canvas id="bpmChart"></canvas>

    </div>


    <div class="chart-box">

        <h3>Oxygen Saturation (SpO2 %)</h3>

        <canvas id="spo2Chart"></canvas>

    </div>


    <div class="chart-box chart-full">

        <h3>Body Temperature (°C)</h3>

        <canvas id="bodyChart"></canvas>

    </div>


</div>

</div>


<!-- HISTORICAL READINGS TABLE -->

<div class="panel">

<h2>Historical Readings Table</h2>

<div class="table-wrap">

<table>

<thead>

<tr>

    <th>Record ID</th>

    <th>Device ID</th>

    <th>Room Temp (°C)</th>

    <th>Humidity (%)</th>

    <th>BPM</th>

    <th>SpO2 (%)</th>

    <th>Body Temp (°C)</th>

    <th>Mode</th>

    <th>Recorded At</th>

    <th>Timezone</th>

</tr>

</thead>


<tbody>

<?php foreach ($table_rows as $r): ?>

<tr>

    <td>
        <?= patient_h($r['id']) ?>
    </td>

    <td>
        <?= patient_h($r['device_id']) ?>
    </td>

    <td>
        <?= patient_h($r['room_temperature']) ?>
    </td>

    <td>
        <?= patient_h($r['humidity']) ?>
    </td>

    <td>
        <?= patient_h($r['bpm']) ?>
    </td>

    <td>
        <?= patient_h($r['spo2']) ?>
    </td>

    <td>
        <?= patient_h($r['body_temperature']) ?>
    </td>

    <td>
        <?= patient_h($r['mode']) ?>
    </td>

    <td>
        <?= patient_h($r['recorded_at']) ?>
    </td>

    <td>
        <?= patient_h($r['timezone']) ?>
    </td>

</tr>

<?php endforeach; ?>

</tbody>

</table>

</div>

</div>


<?php else: ?>


<!-- NO RECORDS -->

<div class="panel no-data">

<h3>No historical readings found.</h3>

<p>
    No monitoring records were found for this assigned device
    and selected search period.
</p>

</div>


<?php endif; ?>

</div>


<!-- CHART.JS -->

<?php if (count($rows) > 0): ?>

<script>

// Historical timestamps.
const labels = <?= history_json($labels) ?>;

// Sensor values.
const roomData = <?= history_json($room_temperature) ?>;

const humidityData = <?= history_json($humidity) ?>;

const bpmData = <?= history_json($bpm) ?>;

const spo2Data = <?= history_json($spo2) ?>;

const bodyData = <?= history_json($body_temperature) ?>;


// Create sensor graph.
function createGraph(element, title, values) {

    new Chart(document.getElementById(element), {

        type: 'line',

        data: {

            labels: labels,

            datasets: [{

                label: title,

                data: values,

                borderWidth: 2,

                pointRadius: 2,

                pointHoverRadius: 5,

                fill: false,

                tension: 0.15

            }]

        },

        options: {

            responsive: true,

            maintainAspectRatio: false,

            interaction: {

                intersect: false,

                mode: 'index'

            },

            scales: {

                x: {

                    title: {

                        display: true,

                        text: 'Recorded Date and Time'

                    }

                },

                y: {

                    beginAtZero: false,

                    title: {

                        display: true,

                        text: title

                    }

                }

            }

        }

    });

}


// Generate all graphs.

createGraph(
    'roomChart',
    'Room Temperature (°C)',
    roomData
);

createGraph(
    'humidityChart',
    'Humidity (%)',
    humidityData
);

createGraph(
    'bpmChart',
    'Heart Rate (BPM)',
    bpmData
);

createGraph(
    'spo2Chart',
    'SpO2 (%)',
    spo2Data
);

createGraph(
    'bodyChart',
    'Body Temperature (°C)',
    bodyData
);

</script>

<?php endif; ?>


</body>
</html>
