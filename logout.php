<?php
// logout.php
require_once 'includes/config.php';

// Explicitly clear any 2FA state before wiping $_SESSION.
// If a user was mid-enrollment or mid-challenge when they logged out,
// this prevents that state from leaking into the next session on a
// shared device.
unset(
    $_SESSION['2fa_user_id'],
    $_SESSION['2fa_started_at'],
    $_SESSION['pending_2fa_secret'],
    $_SESSION['pending_backup_codes'],
    $_SESSION['show_backup_codes_once']
);

// Also drop any replay-protection step counters we wrote during 2FA
if (!empty($_SESSION) && is_array($_SESSION)) {
    foreach (array_keys($_SESSION) as $key) {
        if (strpos($key, 'twofa_last_step_') === 0) {
            unset($_SESSION[$key]);
        }
    }
}

// Clear all session variables
$_SESSION = array();

// Destroy the session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Finally, destroy the session
session_destroy();

// Redirect to login page using BASE_URL
header("Location: " . BASE_URL . "/index.php");
exit;