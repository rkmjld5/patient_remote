<?php
/*
 * PATIENT MONITORING SYSTEM
 * File: patient_login.php
 *
 * Purpose:
 *   Patient login using web_users table.
 *
 * Security:
 *   - Separate patient session.
 *   - Checks patient account enabled status.
 *   - Checks account validity dates.
 *   - Checks administrator-controlled device assignment.
 *   - Checks device assignment validity dates.
 *   - Verifies password using password_verify().
 *   - Regenerates session ID after successful login.
 *   - Uses Asia/Kolkata server time.
 *
 * Database connection: config.php ($conn)
 */

declare(strict_types=1);

// --------------------------------------------------
// 1. Separate patient session
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
// 2. Timezone and database
// --------------------------------------------------

date_default_timezone_set('Asia/Kolkata');

require_once __DIR__ . '/config.php';

// --------------------------------------------------
// 3. Helper functions
// --------------------------------------------------

function h($value): string
{
    return htmlspecialchars(
        (string)$value,
        ENT_QUOTES,
        'UTF-8'
    );
}

function redirectPatient(string $page): void
{
    header('Location: ' . $page);
    exit;
}

// --------------------------------------------------
// 4. Check existing patient session
// --------------------------------------------------

/*
 * Do not automatically trust an old session.
 * The dashboard must independently validate the
 * account and device assignment on every request.
 */

if (
    isset($_SESSION['patient_logged_in']) &&
    $_SESSION['patient_logged_in'] === true
) {
    redirectPatient('index.php');
}

// --------------------------------------------------
// 5. CSRF token
// --------------------------------------------------

if (empty($_SESSION['patient_login_csrf'])) {
    $_SESSION['patient_login_csrf'] =
        bin2hex(random_bytes(32));
}

$error = '';
$username = '';

// --------------------------------------------------
// 6. Process login
// --------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $rawUsername = $_POST['username'] ?? '';
	$username = is_string($rawUsername) ? trim($rawUsername) : '';
    $password = $_POST['password'] ?? '';
    $csrfToken = $_POST['csrf_token'] ?? '';

    if (
        !is_string($csrfToken) ||
        !hash_equals(
            $_SESSION['patient_login_csrf'],
            $csrfToken
        )
    ) {

        $error =
            'Invalid request. Please refresh the page and try again.';

    } elseif (
        !is_string($username) ||
        !is_string($password) ||
        $username === '' ||
        $password === ''
    ) {

        $error = 'Please enter your username and password.';

    } elseif (strlen($username) > 50) {

        $error = 'Invalid username or password.';

    } else {

        try {

            $now = date('Y-m-d H:i:s');

            /*
             * A patient account must have:
             *
             * 1. role = patient
             * 2. enabled = 1
             * 3. Valid account dates
             * 4. A matching device assignment
             * 5. Enabled device assignment
             * 6. Valid device assignment dates
             * 7. An existing patient record
             */

            $sql = "
                SELECT
                    wu.id,
                    wu.username,
                    wu.password_hash,
                    wu.device_id,
                    pd.patient_id,
                    p.full_name

                FROM web_users AS wu

                INNER JOIN patient_devices AS pd
                    ON pd.device_id = wu.device_id

                INNER JOIN patients AS p
                    ON p.patient_id = pd.patient_id

                WHERE
                    wu.username = ?
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
                    'Could not prepare login query.'
                );
            }

            $stmt->bind_param(
                'sssss',
                $username,
                $now,
                $now,
                $now,
                $now
            );

            $stmt->execute();

            $stmt->bind_result(
                $userId,
                $dbUsername,
                $passwordHash,
                $deviceId,
                $patientId,
                $patientName
            );

            $accountFound = $stmt->fetch();

            $stmt->close();

            // --------------------------------------
            // 7. Verify password
            // --------------------------------------

            if (
                $accountFound &&
                password_verify($password, $passwordHash)
            ) {

                // Prevent session fixation
                session_regenerate_id(true);

                // ----------------------------------
                // 8. Store patient session
                // ----------------------------------

                $_SESSION['patient_logged_in'] = true;

                $_SESSION['patient_user_id'] =
                    (int)$userId;

                $_SESSION['patient_username'] =
                    $dbUsername;

                $_SESSION['patient_device_id'] =
                    $deviceId;

                $_SESSION['patient_id'] =
                    $patientId;

                $_SESSION['patient_name'] =
                    $patientName;

                $_SESSION['patient_login_time'] =
                    date('Y-m-d H:i:s');

                // Remove login CSRF token
                unset($_SESSION['patient_login_csrf']);

                // ----------------------------------
                // 9. Open protected patient dashboard
                // ----------------------------------

                redirectPatient('index.php');

            } else {

                $error =
                    'Invalid username or password, or your account/device access is not currently active.';

            }

        } catch (Throwable $e) {

            error_log(
                'Patient login error: ' . $e->getMessage()
            );

            $error =
                'A system error occurred. Please try again later.';
        }
    }
}

?>
<!DOCTYPE html>
<html lang="en">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>Patient Login</title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            padding: 20px;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #f1f5f9;
            font-family: Arial, Helvetica, sans-serif;
        }

        .login-container {
            width: 100%;
            max-width: 420px;
            background: #ffffff;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 4px 18px rgba(0, 0, 0, 0.10);
        }

        h2 {
            margin-top: 0;
            margin-bottom: 8px;
            text-align: center;
            color: #1e293b;
        }

        .subtitle {
            text-align: center;
            color: #64748b;
            margin-bottom: 25px;
            font-size: 14px;
        }

        label {
            display: block;
            margin-bottom: 7px;
            font-weight: bold;
            color: #334155;
        }

        input {
            width: 100%;
            padding: 12px;
            margin-bottom: 18px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 16px;
        }

        input:focus {
            outline: none;
            border-color: #2563eb;
        }

        button {
            width: 100%;
            padding: 13px;
            border: none;
            border-radius: 6px;
            background: #2563eb;
            color: white;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
        }

        button:hover {
            background: #1d4ed8;
        }

        .error {
            padding: 12px;
            margin-bottom: 18px;
            border-radius: 6px;
            background: #fee2e2;
            color: #991b1b;
            font-size: 14px;
            line-height: 1.5;
        }

        .footer {
            margin-top: 22px;
            text-align: center;
            color: #64748b;
            font-size: 12px;
        }

    </style>

</head>

<body>

<div class="login-container">

    <h2>Patient Login</h2>

    <div class="subtitle">
        Patient Monitoring System
    </div>

    <?php if ($error !== ''): ?>

        <div class="error">
            <?php echo h($error); ?>
        </div>

    <?php endif; ?>

    <form method="POST" action="">

        <input
            type="hidden"
            name="csrf_token"
            value="<?php
                echo h($_SESSION['patient_login_csrf']);
            ?>"
        >

        <label for="username">
            Username
        </label>

        <input
            type="text"
            id="username"
            name="username"
            maxlength="50"
            value="<?php echo h($username); ?>"
            autocomplete="username"
            required
            autofocus
        >

        <label for="password">
            Password
        </label>

        <input
            type="password"
            id="password"
            name="password"
            autocomplete="current-password"
            required
        >

        <button type="submit">
            Login
        </button>

    </form>

    <div class="footer">
        Patient Monitoring System
    </div>

</div>

</body>
</html>
