<?php
// index.php
require_once 'includes/config.php';
require_once 'includes/db_connection.php';
require_once 'includes/security.php';
require_once 'includes/auth.php';

// ===== UPDATED REDIRECT FOR ALREADY LOGGED IN USERS =====

if ($auth->isLoggedIn() || isset($_SESSION['user_id'])) {
    $role = $_SESSION['role'] ?? null;
    $account_status = $_SESSION['account_status'] ?? null;

    // Refresh both from DB — session may be stale (e.g. admin just approved)
    if (!empty($_SESSION['user_id'])) {
        $db = Database::getInstance();
        $fresh = $db->getSingle(
            "SELECT role, account_status FROM users WHERE user_id = ?",
            [$_SESSION['user_id']], 'i'
        );
        if ($fresh) {
            $role           = $fresh['role'];
            $account_status = $fresh['account_status'];
            $_SESSION['role']           = $role;
            $_SESSION['account_status'] = $account_status;
        }
    }
    $role           = $role ?? 'viewer';
    $account_status = $account_status ?? 'joined';

    if ($role === 'admin') {
        header('Location: ' . BASE_URL . '/admin/dashboard.php');
        exit();
    }
    if ($account_status === 'approved') {
        header('Location: ' . BASE_URL . '/user/dashboard.php');
    } else {
        header('Location: ' . BASE_URL . '/user/welcome.php');
    }
    exit();
}
// ===== END OF UPDATED CODE =====

$error = '';
$step = isset($_GET['step']) ? $_GET['step'] : 'login';
$user_id = $_SESSION['2fa_user_id'] ?? null;

// ===== 2FA CHALLENGE GUARD + EXPIRY =====
// Challenge expires after 10 minutes of inactivity on the 2FA screen.
const TWOFA_CHALLENGE_TTL = 600;

if ($step === '2fa') {
    if (!$user_id) {
        header('Location: index.php');
        exit();
    }
    $started = (int)($_SESSION['2fa_started_at'] ?? 0);
    if ($started && (time() - $started) > TWOFA_CHALLENGE_TTL) {
        unset($_SESSION['2fa_user_id'], $_SESSION['2fa_started_at']);
        header('Location: index.php?expired=1');
        exit();
    }
}

// Handle login form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token';
    } else {
        if ($step === 'login') {
            $username = Security::sanitize($_POST['username'] ?? '');
            $password = $_POST['password'] ?? '';

            $result = $auth->login($username, $password);

            if ($result['success']) {
                // Rotate CSRF after successful auth
                Security::generateCSRFToken(true);
                header('Location: ' . $result['redirect']);
                exit();
            } elseif (isset($result['require_2fa']) && $result['require_2fa']) {
                header('Location: index.php?step=2fa');
                exit();
            } else {
                $error = $result['error'];
            }
        } elseif ($step === '2fa' && $user_id) {
            // Accept TOTP (6 digits) OR a backup code (10 alnum chars)
            $totp_code = trim($_POST['totp_code'] ?? '');

            $result = $auth->verify2FA($user_id, $totp_code);

            if ($result['success']) {
                Security::generateCSRFToken(true);
                $redirect_url = ($_SESSION['role'] === 'admin') ? 'admin/dashboard.php' : 'user/dashboard.php';
                header('Location: ' . $redirect_url);
                exit();
            } else {
                $error = $result['error'];
                // Session invalidated mid-flight — bounce to login
                if (empty($_SESSION['2fa_user_id'])) {
                    header('Location: index.php');
                    exit();
                }
            }
        }
    }
}

$csrf_token = Security::generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Nagkaisang Haranista sa Gintong Luzon Phils, Inc.</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* ========================================
                   IMPORTS & RESET
                ======================================== */
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap');

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        /* ========================================
                   ROOT VARIABLES (Theming & Responsive)
                ======================================== */
        :root {
            --primary-dark: #375a7f;
            --primary-medium: #4a7a9c;
            --primary-light: #6a9abf;
            --white: #ffffff;
            --light-bg: #f8f9fa;
            --text-dark: #212529;
            --text-muted: #6c757d;
            --shadow-sm: 0 4px 6px rgba(0, 0, 0, 0.07);
            --shadow-md: 0 10px 40px rgba(0, 0, 0, 0.15);
            --shadow-lg: 0 20px 60px rgba(0, 0, 0, 0.25);
            --radius-sm: clamp(0.5rem, 1.5vw, 0.75rem);
            --radius-md: clamp(0.75rem, 2vw, 1.25rem);
            --radius-lg: clamp(1rem, 2.5vw, 1.5rem);
            --radius-xl: clamp(1.25rem, 3vw, 2rem);
            --font-size-xs: clamp(0.7rem, 1.2vw, 0.8rem);
            --font-size-sm: clamp(0.8rem, 1.4vw, 0.9rem);
            --font-size-base: clamp(0.9rem, 1.6vw, 1rem);
            --font-size-md: clamp(1rem, 1.8vw, 1.1rem);
            --font-size-lg: clamp(1.2rem, 2.2vw, 1.5rem);
            --font-size-xl: clamp(1.5rem, 3vw, 2rem);
            --font-size-2xl: clamp(1.8rem, 3.5vw, 2.5rem);
            --spacing-xs: clamp(0.25rem, 0.5vw, 0.5rem);
            --spacing-sm: clamp(0.5rem, 1vw, 0.75rem);
            --spacing-md: clamp(0.75rem, 1.5vw, 1.25rem);
            --spacing-lg: clamp(1rem, 2vw, 1.5rem);
            --spacing-xl: clamp(1.25rem, 2.5vw, 2rem);
            --spacing-2xl: clamp(1.5rem, 3vw, 2.5rem);
        }

        /* ========================================
                   BODY & BACKGROUND
                ======================================== */
        body {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary-medium) 100%);
            min-height: 100vh;
            min-height: 100dvh;
            font-family: 'Poppins', 'Segoe UI', sans-serif;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
            position: relative;
            overflow-x: hidden;
            padding: var(--spacing-md);
        }

        /* Animated background bubbles */
        .bg-bubble {
            position: fixed;
            background: rgba(255, 255, 255, 0.05);
            border-radius: 50%;
            animation: float 20s infinite;
            z-index: 0;
            pointer-events: none;
        }

        .bg-bubble-1 {
            width: clamp(150px, 30vw, 300px);
            height: clamp(150px, 30vw, 300px);
            top: -10%;
            left: -10%;
            animation-delay: 0s;
        }

        .bg-bubble-2 {
            width: clamp(250px, 50vw, 500px);
            height: clamp(250px, 50vw, 500px);
            bottom: -15%;
            right: -15%;
            animation-delay: 5s;
        }

        .bg-bubble-3 {
            width: clamp(100px, 20vw, 200px);
            height: clamp(100px, 20vw, 200px);
            bottom: 10%;
            left: 5%;
            animation-delay: 10s;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0) rotate(0deg); }
            50% { transform: translateY(-30px) rotate(10deg); }
        }

        /* ========================================
                   CONTAINER
                ======================================== */
        .container {
            position: relative;
            z-index: 10;
            width: 100%;
            max-width: min(650px, 95vw);
            margin: 0 auto;
            padding: 0;
        }

        .row {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            min-height: 100dvh;
            margin: 0;
            padding: 0;
        }

        .col-md-8 {
            width: 100%;
            padding: 0;
        }

        /* ========================================
                   CARD
                ======================================== */
        .card {
            border: none;
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-lg);
            width: 100%;
            max-width: 100%;
            margin: 0;
            backdrop-filter: blur(10px);
            background: rgba(255, 255, 255, 0.98);
            transform: translateY(0);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            overflow: hidden;
        }

        .card:hover {
            transform: translateY(clamp(-2px, -0.3vw, -5px));
            box-shadow: var(--shadow-lg);
        }

        /* ========================================
                   CARD HEADER
                ======================================== */
        .card-header {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary-medium) 100%);
            border-radius: var(--radius-xl) var(--radius-xl) 0 0;
            color: var(--white);
            padding: var(--spacing-xl) var(--spacing-xl);
            text-align: center;
            position: relative;
            overflow: hidden;
        }

        .card-header::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.1) 0%, transparent 70%);
            animation: rotate 20s linear infinite;
        }

        @keyframes rotate {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        /* Logo */
        .logo-container {
            display: flex;
            justify-content: center;
            align-items: center;
            margin-bottom: var(--spacing-md);
            position: relative;
            z-index: 1;
            width: 100%;
        }

        .logo-container img {
            width: clamp(80px, 15vw, 120px);
            height: clamp(80px, 15vw, 120px);
            object-fit: contain;
            filter: drop-shadow(0 5px 15px rgba(0, 0, 0, 0.2));
        }

        .logo-placeholder {
            width: clamp(80px, 15vw, 120px);
            height: clamp(80px, 15vw, 120px);
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .logo-placeholder i {
            font-size: clamp(3rem, 8vw, 4rem);
            background: linear-gradient(135deg, var(--primary-dark), var(--primary-medium));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            filter: drop-shadow(0 5px 15px rgba(0, 0, 0, 0.2));
        }

        /* Organization name */
        .org-name {
            margin: 0;
            font-size: var(--font-size-xl);
            font-weight: 600;
            line-height: 1.3;
            position: relative;
            z-index: 1;
            letter-spacing: 0.02em;
        }

        .org-subname {
            font-size: var(--font-size-sm);
            opacity: 0.9;
            margin-top: var(--spacing-xs);
            position: relative;
            z-index: 1;
        }

        .card-header i {
            font-size: clamp(2.5rem, 6vw, 3rem);
            margin-bottom: var(--spacing-sm);
            animation: pulse 2s ease-in-out infinite;
            position: relative;
            z-index: 1;
        }

        @keyframes pulse {
            0%, 100% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.05); opacity: 0.9; }
        }

        /* ========================================
                   CARD BODY
                ======================================== */
        .card-body {
            padding: var(--spacing-2xl) var(--spacing-xl);
            background: transparent;
            border-radius: 0 0 var(--radius-xl) var(--radius-xl);
        }

        /* ========================================
                   FORM ELEMENTS
                ======================================== */
        .form-group {
            margin-bottom: var(--spacing-lg);
            position: relative;
        }

        .form-label {
            font-weight: 500;
            color: var(--text-dark);
            margin-bottom: var(--spacing-xs);
            font-size: var(--font-size-sm);
            transition: color 0.3s ease;
            display: block;
        }

        .form-control {
            padding: clamp(0.6rem, 1.5vw, 0.9rem) clamp(0.8rem, 1.5vw, 1rem);
            border: 2px solid #e0e0e0;
            border-radius: var(--radius-sm);
            transition: all 0.3s ease;
            font-size: var(--font-size-base);
            background: var(--white);
            width: 100%;
            height: auto;
            min-height: clamp(2.8rem, 5vw, 3.5rem);
        }

        .form-control:focus {
            border-color: var(--primary-dark);
            box-shadow: 0 0 0 4px rgba(55, 90, 127, 0.1);
            outline: none;
        }

        .form-control:hover {
            border-color: var(--primary-light);
        }

        /* Password field with toggle */
        .password-field {
            position: relative;
            width: 100%;
        }

        .password-field input {
            padding-right: clamp(2.5rem, 6vw, 3.5rem);
        }

        .password-toggle {
            position: absolute;
            right: clamp(0.8rem, 2vw, 1.2rem);
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: var(--primary-dark);
            z-index: 10;
            font-size: var(--font-size-md);
            background: transparent;
            padding: 0;
            line-height: 1;
            border: none;
        }

        .password-toggle:hover {
            color: var(--primary-medium);
        }

        /* ========================================
                   BUTTONS
                ======================================== */
        .btn-primary {
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary-medium) 100%);
            border: none;
            padding: clamp(0.7rem, 1.5vw, 1rem);
            width: 100%;
            font-weight: 600;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            font-size: var(--font-size-md);
            border-radius: var(--radius-sm);
            color: var(--white);
            cursor: pointer;
            min-height: clamp(3rem, 5vw, 3.8rem);
        }

        .btn-primary::before {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.2);
            transform: translate(-50%, -50%);
            transition: width 0.6s ease, height 0.6s ease;
        }

        .btn-primary:hover::before {
            width: 300px;
            height: 300px;
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(55, 90, 127, 0.4);
        }

        .btn-primary:active {
            transform: translateY(0);
        }

        .btn-primary:disabled {
            opacity: 0.7;
            cursor: not-allowed;
        }

        /* Loading spinner */
        .spinner {
            display: none;
            width: clamp(16px, 2vw, 20px);
            height: clamp(16px, 2vw, 20px);
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-radius: 50%;
            border-top-color: #fff;
            animation: spin 1s ease-in-out infinite;
            margin-right: 10px;
            vertical-align: middle;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        .btn-primary.loading .spinner {
            display: inline-block;
        }

        .btn-primary.loading .btn-text {
            opacity: 0.8;
        }

        /* ========================================
                   ALERTS
                ======================================== */
        .alert {
            border-radius: var(--radius-sm);
            margin-bottom: var(--spacing-lg);
            animation: slideIn 0.3s ease;
            padding: var(--spacing-md) var(--spacing-lg);
            font-size: var(--font-size-sm);
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes slideOut {
            from {
                opacity: 1;
                transform: translateY(0);
            }
            to {
                opacity: 0;
                transform: translateY(-10px);
            }
        }

        .alert .btn-close {
            padding: var(--spacing-xs) var(--spacing-sm);
            font-size: var(--font-size-sm);
        }

        /* ========================================
                   FOOTER LINKS
                ======================================== */
        .footer-links {
            text-align: center;
            margin-top: var(--spacing-xl);
        }

        .footer-links p {
            font-size: var(--font-size-sm);
            margin-bottom: 0;
        }

        .footer-links a {
            color: var(--primary-dark);
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
            position: relative;
        }

        .footer-links a::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            width: 0;
            height: 2px;
            background: linear-gradient(135deg, var(--primary-dark) 0%, var(--primary-medium) 100%);
            transition: width 0.3s ease;
        }

        .footer-links a:hover::after {
            width: 100%;
        }

        .footer-links a:hover {
            color: var(--primary-medium);
        }

        /* ========================================
                   REMEMBER ME & FORGOT PASSWORD
                ======================================== */
        .form-check {
            display: flex;
            align-items: center;
            gap: var(--spacing-xs);
        }

        .form-check-input {
            cursor: pointer;
            transition: all 0.3s ease;
            width: clamp(16px, 1.5vw, 18px);
            height: clamp(16px, 1.5vw, 18px);
            margin-top: 0;
            flex-shrink: 0;
        }

        .form-check-input:checked {
            background-color: var(--primary-dark);
            border-color: var(--primary-dark);
        }

        .form-check-label {
            cursor: pointer;
            user-select: none;
            font-size: var(--font-size-sm);
        }

        .d-flex.justify-content-between.align-items-center {
            display: flex;
            flex-wrap: wrap;
            gap: var(--spacing-sm);
        }

        .d-flex.justify-content-between.align-items-center a {
            font-size: var(--font-size-sm);
            white-space: nowrap;
        }

        /* ========================================
                   2FA SPECIFIC STYLES
                ======================================== */
        .totp-input {
            letter-spacing: clamp(4px, 1vw, 8px);
            font-size: clamp(1.5rem, 4vw, 1.8rem);
            font-weight: 600;
            text-align: center;
            padding: var(--spacing-md) !important;
            min-height: clamp(3.5rem, 6vw, 4.5rem);
        }

        .totp-input::placeholder {
            letter-spacing: normal;
            font-size: var(--font-size-base);
            font-weight: 400;
        }

        /* Backup-code input styling (added) */
        .backup-input {
            letter-spacing: 2px;
            font-family: monospace;
            font-size: clamp(1rem, 2.5vw, 1.15rem);
            text-align: center;
            text-transform: uppercase;
            padding: var(--spacing-md) !important;
            min-height: clamp(3rem, 5vw, 3.8rem);
        }

        .back-link {
            display: inline-block;
            margin-top: var(--spacing-md);
            color: var(--primary-dark);
            text-decoration: none;
            font-size: var(--font-size-sm);
            transition: all 0.3s ease;
        }

        .back-link i {
            margin-right: var(--spacing-xs);
            transition: transform 0.3s ease;
        }

        .back-link:hover i {
            transform: translateX(-5px);
        }

        .back-link:hover {
            color: var(--primary-medium);
        }

        /* ========================================
                   2FA Icon
                ======================================== */
        .fa-shield-alt {
            color: var(--primary-dark);
            font-size: clamp(2.5rem, 6vw, 4rem);
        }

        .fa-key {
            color: var(--primary-dark);
            font-size: clamp(2.5rem, 6vw, 4rem);
        }

        /* ========================================
                   RESPONSIVE BREAKPOINTS
                ======================================== */

        /* Tablets & Small Laptops (768px - 1024px) */
        @media (max-width: 1024px) {
            .card-body {
                padding: var(--spacing-lg) var(--spacing-lg);
            }

            .card-header {
                padding: var(--spacing-lg);
            }
        }

        /* Tablets (768px) */
        @media (max-width: 768px) {
            body {
                padding: var(--spacing-sm);
            }

            .container {
                max-width: 100%;
                padding: 0;
            }

            .card-header {
                padding: var(--spacing-lg);
                border-radius: var(--radius-lg) var(--radius-lg) 0 0;
            }

            .card-body {
                padding: var(--spacing-lg);
            }

            .org-name {
                font-size: var(--font-size-lg);
            }

            .org-subname {
                font-size: var(--font-size-xs);
            }

            .logo-container img,
            .logo-placeholder {
                width: clamp(70px, 12vw, 100px);
                height: clamp(70px, 12vw, 100px);
            }

            .logo-placeholder i {
                font-size: clamp(2.5rem, 6vw, 3.5rem);
            }

            .form-control {
                padding: clamp(0.5rem, 1.2vw, 0.8rem) clamp(0.7rem, 1.2vw, 0.9rem);
                min-height: clamp(2.5rem, 4vw, 3rem);
                font-size: var(--font-size-sm);
            }

            .btn-primary {
                padding: clamp(0.6rem, 1.2vw, 0.8rem);
                min-height: clamp(2.8rem, 4vw, 3.2rem);
                font-size: var(--font-size-sm);
            }

            .form-group {
                margin-bottom: var(--spacing-md);
            }

            .totp-input {
                font-size: clamp(1.3rem, 3.5vw, 1.5rem);
                letter-spacing: clamp(4px, 0.8vw, 6px);
                min-height: clamp(3rem, 5vw, 4rem);
            }

            .d-flex.justify-content-between.align-items-center {
                flex-direction: row;
                flex-wrap: wrap;
            }

            .footer-links {
                margin-top: var(--spacing-lg);
            }
        }

        /* Mobile (480px) */
        @media (max-width: 480px) {
            body {
                padding: var(--spacing-xs);
            }

            .container {
                max-width: 100%;
                padding: 0;
            }

            .card {
                border-radius: var(--radius-md);
                box-shadow: var(--shadow-md);
            }

            .card-header {
                padding: var(--spacing-md);
                border-radius: var(--radius-md) var(--radius-md) 0 0;
            }

            .card-body {
                padding: var(--spacing-md);
                border-radius: 0 0 var(--radius-md) var(--radius-md);
            }

            .org-name {
                font-size: var(--font-size-md);
            }

            .org-subname {
                font-size: var(--font-size-xs);
            }

            .logo-container {
                margin-bottom: var(--spacing-sm);
            }

            .logo-container img,
            .logo-placeholder {
                width: clamp(60px, 15vw, 80px);
                height: clamp(60px, 15vw, 80px);
            }

            .logo-placeholder i {
                font-size: clamp(2rem, 6vw, 2.8rem);
            }

            .form-group {
                margin-bottom: var(--spacing-md);
            }

            .form-label {
                font-size: var(--font-size-xs);
                margin-bottom: var(--spacing-xs);
            }

            .form-control {
                padding: clamp(0.4rem, 1vw, 0.6rem) clamp(0.6rem, 1vw, 0.8rem);
                min-height: clamp(2.2rem, 4.5vw, 2.8rem);
                font-size: var(--font-size-xs);
                border-width: 1.5px;
            }

            .form-control:focus {
                box-shadow: 0 0 0 3px rgba(55, 90, 127, 0.1);
            }

            .btn-primary {
                padding: clamp(0.5rem, 1vw, 0.7rem);
                min-height: clamp(2.5rem, 4.5vw, 3rem);
                font-size: var(--font-size-xs);
            }

            .btn-primary:hover {
                transform: none;
            }

            .password-toggle {
                font-size: var(--font-size-sm);
                right: clamp(0.6rem, 1.5vw, 0.8rem);
            }

            .password-field input {
                padding-right: clamp(2rem, 5vw, 2.5rem);
            }

            .form-check-input {
                width: clamp(14px, 1.5vw, 16px);
                height: clamp(14px, 1.5vw, 16px);
            }

            .form-check-label {
                font-size: var(--font-size-xs);
            }

            .d-flex.justify-content-between.align-items-center {
                flex-direction: column;
                align-items: flex-start;
                gap: var(--spacing-xs);
            }

            .d-flex.justify-content-between.align-items-center a {
                font-size: var(--font-size-xs);
                white-space: normal;
            }

            .alert {
                padding: var(--spacing-sm) var(--spacing-md);
                font-size: var(--font-size-xs);
                margin-bottom: var(--spacing-md);
                border-radius: var(--radius-sm);
            }

            .footer-links {
                margin-top: var(--spacing-md);
            }

            .footer-links p {
                font-size: var(--font-size-xs);
            }

            .footer-links a {
                font-size: var(--font-size-xs);
            }

            .totp-input {
                font-size: clamp(1.1rem, 3vw, 1.3rem);
                letter-spacing: clamp(3px, 0.6vw, 5px);
                padding: var(--spacing-sm) !important;
                min-height: clamp(2.8rem, 5vw, 3.5rem);
            }

            .totp-input::placeholder {
                font-size: var(--font-size-xs);
            }

            .backup-input {
                font-size: 1rem;
                min-height: clamp(2.6rem, 4.5vw, 3rem);
                letter-spacing: 1.5px;
            }

            .fa-shield-alt,
            .fa-key {
                font-size: clamp(2rem, 5vw, 2.5rem);
            }

            .back-link {
                font-size: var(--font-size-xs);
                margin-top: var(--spacing-sm);
            }

            .bg-bubble {
                display: none;
            }

            .card:hover {
                transform: none;
            }

            .btn-primary.loading .spinner {
                width: clamp(14px, 1.5vw, 16px);
                height: clamp(14px, 1.5vw, 16px);
            }
        }

        /* Extra Small (320px) */
        @media (max-width: 360px) {
            .card-header {
                padding: var(--spacing-sm);
            }

            .card-body {
                padding: var(--spacing-sm);
            }

            .logo-container img,
            .logo-placeholder {
                width: clamp(50px, 14vw, 60px);
                height: clamp(50px, 14vw, 60px);
            }

            .logo-placeholder i {
                font-size: clamp(1.5rem, 5vw, 2rem);
            }

            .org-name {
                font-size: var(--font-size-sm);
            }

            .org-subname {
                font-size: var(--font-size-xs);
            }

            .form-control {
                min-height: clamp(2rem, 4vw, 2.4rem);
                font-size: var(--font-size-xs);
            }

            .btn-primary {
                min-height: clamp(2.2rem, 4vw, 2.6rem);
                font-size: var(--font-size-xs);
            }

            .totp-input {
                font-size: 1rem;
                letter-spacing: 3px;
                min-height: 2.5rem;
            }

            .backup-input {
                font-size: .95rem;
                min-height: 2.5rem;
            }
        }

        /* Landscape phone mode */
        @media (max-height: 600px) and (orientation: landscape) {
            body {
                padding: var(--spacing-sm);
                align-items: flex-start;
                padding-top: var(--spacing-md);
            }

            .card {
                max-width: 90vw;
            }

            .card-header {
                padding: var(--spacing-sm) var(--spacing-md);
            }

            .card-body {
                padding: var(--spacing-md);
            }

            .logo-container img,
            .logo-placeholder {
                width: clamp(50px, 8vw, 70px);
                height: clamp(50px, 8vw, 70px);
            }

            .logo-container {
                margin-bottom: var(--spacing-xs);
            }

            .org-name {
                font-size: var(--font-size-sm);
            }

            .org-subname {
                font-size: var(--font-size-xs);
            }

            .form-group {
                margin-bottom: var(--spacing-sm);
            }

            .form-control {
                min-height: clamp(2rem, 3.5vw, 2.5rem);
                padding: clamp(0.3rem, 0.8vw, 0.5rem) clamp(0.5rem, 0.8vw, 0.7rem);
            }

            .btn-primary {
                min-height: clamp(2.2rem, 3.5vw, 2.8rem);
                padding: clamp(0.3rem, 0.8vw, 0.5rem);
            }

            .footer-links {
                margin-top: var(--spacing-sm);
            }

            .row {
                min-height: auto;
                padding: var(--spacing-sm) 0;
            }

            .bg-bubble {
                display: none;
            }
        }

        /* High DPI / Retina displays */
        @media (-webkit-min-device-pixel-ratio: 2), (min-resolution: 192dpi) {
            .card {
                backdrop-filter: blur(10px);
            }
        }

        /* Dark mode preference */
        @media (prefers-color-scheme: dark) {
            .card {
                background: rgba(30, 30, 35, 0.95);
            }

            .card-body {
                background: transparent;
            }

            .form-label {
                color: #e0e0e0;
            }

            .form-control {
                background: #2a2a2f;
                border-color: #444;
                color: #e0e0e0;
            }

            .form-control:focus {
                border-color: var(--primary-light);
                box-shadow: 0 0 0 4px rgba(74, 122, 156, 0.2);
            }

            .form-control::placeholder {
                color: #888;
            }

            .form-check-label {
                color: #ccc;
            }

            .footer-links a {
                color: var(--primary-light);
            }

            .footer-links p {
                color: #aaa;
            }

            .d-flex.justify-content-between.align-items-center a {
                color: var(--primary-light);
            }

            .back-link {
                color: var(--primary-light);
            }

            .back-link:hover {
                color: var(--primary-light);
            }

            .text-muted {
                color: #999 !important;
            }

            .alert {
                background: rgba(40, 40, 45, 0.95);
                color: #e0e0e0;
                border-color: #555;
            }

            .alert-danger {
                background: rgba(60, 20, 25, 0.95);
                border-color: #6a2a2a;
                color: #ffb3b3;
            }

            .alert .btn-close {
                filter: invert(1);
            }

            .card-header {
                background: linear-gradient(135deg, #2a4a6a 0%, #3a6a8a 100%);
            }
        }

        /* Reduced motion preference */
        @media (prefers-reduced-motion: reduce) {
            .bg-bubble {
                animation: none;
            }

            .card-header::before {
                animation: none;
            }

            .card-header i {
                animation: none;
            }

            .btn-primary::before {
                display: none;
            }

            .alert {
                animation: none;
            }

            .card {
                transition: none;
            }

            .card:hover {
                transform: none;
            }

            .btn-primary:hover {
                transform: none;
            }
        }
    </style>
</head>
<body>
    <!-- Animated background bubbles -->
    <div class="bg-bubble bg-bubble-1"></div>
    <div class="bg-bubble bg-bubble-2"></div>
    <div class="bg-bubble bg-bubble-3"></div>

    <div class="container">
        <div class="row">
            <div class="col-md-8">
                <!-- Login Section -->
                <div id="login-section">
                    <div class="card">
                        <div class="card-header text-center">
                            <div class="logo-container">
                                <img src="assets/images/harana-logo.png" alt="Harana Logo"
                                     onerror="this.style.display='none'; this.parentNode.innerHTML='<div class=\'logo-placeholder\'><i class=\'fas fa-hand-holding-heart\'></i></div>';">
                            </div>
                            <h3 class="org-name">Nagkaisang Haranista</h3>
                            <div class="org-subname">sa Gintong Luzon Phils, Inc.</div>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($_GET['expired'])): ?>
                                <div class="alert alert-warning alert-dismissible fade show" role="alert">
                                    <i class="fas fa-clock me-2"></i>Your 2FA challenge expired. Please log in again.
                                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                                </div>
                            <?php endif; ?>

                            <?php if ($error): ?>
                                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                    <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?>
                                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                                </div>
                            <?php endif; ?>

                            <?php if ($step === 'login'): ?>
                            <form method="POST" action="" id="loginForm">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

                                <div class="form-group">
                                    <label class="form-label"><i class="fas fa-user me-2"></i>Username</label>
                                    <input type="text" class="form-control" name="username" placeholder="Enter your username" required autofocus>
                                </div>

                                <div class="form-group">
                                    <label class="form-label"><i class="fas fa-lock me-2"></i>Password</label>
                                    <div class="password-field">
                                        <input type="password" class="form-control" name="password" id="password" placeholder="Enter your password" required>
                                        <button type="button" class="password-toggle" id="togglePassword" aria-label="Toggle password visibility">
                                            <i class="fas fa-eye"></i>
                                        </button>
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div class="form-check">
                                            <input type="checkbox" class="form-check-input" id="remember">
                                            <label class="form-check-label" for="remember">Remember me</label>
                                        </div>
                                        <a href="forgot_password.php" class="text-decoration-none">
                                            <i class="fas fa-question-circle me-1"></i>Forgot Password?
                                        </a>
                                    </div>
                                </div>

                                <div class="d-grid">
                                    <button type="submit" class="btn btn-primary" id="loginBtn">
                                        <span class="spinner"></span>
                                        <span class="btn-text"><i class="fas fa-sign-in-alt me-2"></i>Login</span>
                                    </button>
                                </div>
                            </form>

                            <div class="footer-links">
                                <p class="mb-0">Don't have an account? <a href="join.php">Create an account <i class="fas fa-arrow-right ms-1"></i></a></i></a></p>
                            </div>

                            <?php elseif ($step === '2fa'): ?>
                            <form method="POST" action="?step=2fa" id="2faForm">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

                                <div class="text-center mb-4">
                                    <div class="mb-3" id="2faIcon">
                                        <i class="fas fa-shield-alt"></i>
                                    </div>
                                    <h5 class="mt-2" id="2faTitle">Two-Factor Authentication</h5>
                                    <p class="text-muted" id="2faHint">Enter the 6-digit code from your authenticator app</p>
                                </div>

                                <div class="form-group mb-4">
                                    <input type="text" class="form-control text-center totp-input" name="totp_code"
                                           id="totp_code"
                                           placeholder="000000" maxlength="6" pattern="[0-9]{6}"
                                           inputmode="numeric" autocomplete="off" required autofocus>
                                </div>

                                <div class="d-grid">
                                    <button type="submit" class="btn btn-primary" id="verifyBtn">
                                        <span class="spinner"></span>
                                        <span class="btn-text" id="verifyBtnText"><i class="fas fa-check me-2"></i>Verify &amp; Login</span>
                                    </button>
                                </div>

                                <div class="d-flex justify-content-between align-items-center mt-3">
                                    <a href="index.php" class="back-link m-0">
                                        <i class="fas fa-arrow-left"></i> Back to login
                                    </a>
                                    <a href="#" class="back-link m-0" id="toggleBackupLink" onclick="toggleBackupMode(event)">
                                        <i class="fas fa-key me-1"></i><span id="toggleBackupText">Use a backup code</span>
                                    </a>
                                </div>
                            </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // ─── Password visibility toggle ───
        const togglePassword = document.getElementById('togglePassword');
        const password = document.getElementById('password');

        if (togglePassword && password) {
            togglePassword.addEventListener('click', function() {
                const type = password.getAttribute('type') === 'password' ? 'text' : 'password';
                password.setAttribute('type', type);

                const icon = this.querySelector('i');
                if (icon) {
                    icon.classList.toggle('fa-eye');
                    icon.classList.toggle('fa-eye-slash');
                }
            });
        }

        // ─── Form submission loading effect ───
        const loginForm = document.getElementById('loginForm');
        const loginBtn = document.getElementById('loginBtn');

        if (loginForm && loginBtn) {
            loginForm.addEventListener('submit', function(e) {
                loginBtn.classList.add('loading');
                loginBtn.disabled = true;
            });
        }

        // ─── 2FA form submission ───
        const twoFAForm = document.getElementById('2faForm');
        const verifyBtn = document.getElementById('verifyBtn');

        if (twoFAForm && verifyBtn) {
            twoFAForm.addEventListener('submit', function(e) {
                verifyBtn.classList.add('loading');
                verifyBtn.disabled = true;
            });
        }

        // ─── Backup-code mode toggle ───
        let backupMode = false;
        function toggleBackupMode(e) {
            e.preventDefault();
            backupMode = !backupMode;

            const inp     = document.getElementById('totp_code');
            const hint    = document.getElementById('2faHint');
            const title   = document.getElementById('2faTitle');
            const iconBox = document.getElementById('2faIcon');
            const linkTxt = document.getElementById('toggleBackupText');
            const btnTxt  = document.getElementById('verifyBtnText');

            if (backupMode) {
                inp.removeAttribute('pattern');
                inp.removeAttribute('maxlength');
                inp.removeAttribute('inputmode');
                inp.classList.remove('totp-input');
                inp.classList.add('backup-input');
                inp.placeholder = 'A1B2C3D4E5';
                inp.value = '';
                title.textContent = 'Backup Code';
                hint.textContent = 'Enter one of the 10-character backup codes you saved when enabling 2FA. Each code can be used only once.';
                iconBox.innerHTML = '<i class="fas fa-key"></i>';
                linkTxt.textContent = 'Use authenticator code';
                btnTxt.innerHTML = '<i class="fas fa-key me-2"></i>Verify Backup Code';
            } else {
                inp.setAttribute('pattern', '[0-9]{6}');
                inp.setAttribute('maxlength', '6');
                inp.setAttribute('inputmode', 'numeric');
                inp.classList.remove('backup-input');
                inp.classList.add('totp-input');
                inp.placeholder = '000000';
                inp.value = '';
                title.textContent = 'Two-Factor Authentication';
                hint.textContent = 'Enter the 6-digit code from your authenticator app';
                iconBox.innerHTML = '<i class="fas fa-shield-alt"></i>';
                linkTxt.textContent = 'Use a backup code';
                btnTxt.innerHTML = '<i class="fas fa-check me-2"></i>Verify &amp; Login';
            }
            inp.focus();
        }

        // ─── Auto-submit 2FA code when 6 digits entered (TOTP only) ───
        const totpInput = document.getElementById('totp_code');
        if (totpInput) {
            totpInput.addEventListener('input', function() {
                if (backupMode) {
                    // Backup code: uppercase, alphanumeric, no auto-submit
                    this.value = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
                    return;
                }
                // TOTP: digits only, auto-submit at 6
                this.value = this.value.replace(/[^0-9]/g, '');
                if (this.value.length === 6) {
                    document.getElementById('2faForm').submit();
                }
            });

            // Focus on input
            totpInput.focus();
        }

        // ─── Smooth alert dismissal ───
        document.querySelectorAll('.alert .btn-close').forEach(btn => {
            btn.addEventListener('click', function() {
                const alert = this.closest('.alert');
                if (alert) {
                    alert.style.animation = 'slideOut 0.3s ease forwards';
                    setTimeout(() => {
                        alert.remove();
                    }, 300);
                }
            });
        });

        // ─── Handle logo load error ───
        const logo = document.querySelector('.logo-container img');
        if (logo) {
            logo.addEventListener('error', function() {
                this.style.display = 'none';
                const container = this.parentNode;
                container.innerHTML = '<div class="logo-placeholder"><i class="fas fa-hand-holding-heart"></i></div>';
            });
        }

        // ─── Add slideOut animation if not exists ───
        if (!document.getElementById('slideOutStyle')) {
            const style = document.createElement('style');
            style.id = 'slideOutStyle';
            style.textContent = `
                @keyframes slideOut {
                    from {
                        opacity: 1;
                        transform: translateY(0);
                    }
                    to {
                        opacity: 0;
                        transform: translateY(-10px);
                    }
                }
            `;
            document.head.appendChild(style);
        }
    </script>
</body>
</html>