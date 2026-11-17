<?php
/*
 * ESP32 PATIENT MONITORING SYSTEM
 * ADMINISTRATOR LOGIN
 *
 * Database: patient_monitor
 * Table: web_users
 *
 * Administrator accounts:
 * role = admin
 * enabled = 1
 *
 * Patient accounts are NOT permitted here.
 */

date_default_timezone_set('Asia/Kolkata');

/* Separate administrator session from patient session */
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => false, // Local XAMPP HTTP
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    session_start();
}

require_once __DIR__ . '/config.php';

/* Already logged in */
if (!empty($_SESSION['owner_logged_in'])) {
    header('Location: owner.php');
    exit;
}

$message = '';

/* CSRF protection */
if (empty($_SESSION['admin_login_csrf'])) {
    $_SESSION['admin_login_csrf'] = bin2hex(random_bytes(32));
}

/* Process administrator login */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $csrf = $_POST['csrf_token'] ?? '';

    if (!hash_equals($_SESSION['admin_login_csrf'], $csrf)) {

        $message = 'Security verification failed. Reload the page.';

    } else {

        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($username === '' || $password === '') {

            $message = 'Please enter username and password.';

        } else {

            $stmt = $conn->prepare(
                "SELECT id, username, password_hash, role, enabled
                 FROM web_users
                 WHERE username = ?
                 LIMIT 1"
            );

            if (!$stmt) {

                $message = 'Database error. Please contact the administrator.';

            } else {

                $stmt->bind_param('s', $username);
                $stmt->execute();

                $result = $stmt->get_result();
                $user = $result->fetch_assoc();

                $stmt->close();

                /*
                 * Only enabled administrator accounts
                 * are allowed to enter owner.php.
                 */
                if (
                    $user &&
                    $user['role'] === 'admin' &&
                    (int)$user['enabled'] === 1 &&
                    password_verify($password, $user['password_hash'])
                ) {

                    session_regenerate_id(true);

                    $_SESSION['owner_logged_in'] = true;
                    $_SESSION['owner_id'] = (int)$user['id'];
                    $_SESSION['owner_username'] = $user['username'];
                    $_SESSION['owner_name'] = $user['username'];

                    unset($_SESSION['admin_login_csrf']);

                    header('Location: owner.php');
                    exit;

                } else {

                    $message = 'Incorrect username or password, or administrator access is disabled.';

                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>

<meta charset="UTF-8">

<meta name="viewport"
      content="width=device-width, initial-scale=1.0">

<title>Administrator Login - Patient Monitoring</title>

<style>

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    font-family: Arial, sans-serif;
    background: #eef2f7;
    min-height: 100vh;
    display: flex;
    justify-content: center;
    align-items: center;
    padding: 20px;
}

.login-box {
    width: 100%;
    max-width: 430px;
    background: white;
    padding: 30px;
    border-radius: 12px;
    box-shadow: 0 4px 18px rgba(0,0,0,0.12);
}

h1 {
    text-align: center;
    color: #123b64;
    font-size: 25px;
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
}

input {
    width: 100%;
    padding: 12px;
    margin-bottom: 18px;
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    font-size: 15px;
}

button {
    width: 100%;
    padding: 13px;
    background: #2563eb;
    border: none;
    border-radius: 6px;
    color: white;
    font-size: 16px;
    font-weight: bold;
    cursor: pointer;
}

button:hover {
    background: #1d4ed8;
}

.message {
    padding: 12px;
    margin-bottom: 18px;
    background: #fee2e2;
    color: #991b1b;
    border-radius: 6px;
    font-size: 14px;
}

.footer {
    text-align: center;
    color: #64748b;
    margin-top: 22px;
    font-size: 12px;
}

</style>
</head>

<body>

<div class="login-box">

    <h1>Patient Monitoring System</h1>

    <div class="subtitle">
        Owner / Administrator Login
    </div>

    <?php if ($message !== ''): ?>

        <div class="message">
            <?php
            echo htmlspecialchars(
                $message,
                ENT_QUOTES,
                'UTF-8'
            );
            ?>
        </div>

    <?php endif; ?>

    <form method="POST" autocomplete="off">

        <input type="hidden"
               name="csrf_token"
               value="<?php
               echo htmlspecialchars(
                   $_SESSION['admin_login_csrf'],
                   ENT_QUOTES,
                   'UTF-8'
               );
               ?>">

        <label>Administrator Username</label>

        <input type="text"
               name="username"
               maxlength="50"
               required
               autocomplete="username">

        <label>Password</label>

        <input type="password"
               name="password"
               required
               autocomplete="current-password">

        <button type="submit">
            Administrator Login
        </button>

    </form>

    <div class="footer">
        ESP32 Patient Monitoring System
        <br><br>
        Authorized Administrator Access Only
    </div>

</div>

</body>
</html>
