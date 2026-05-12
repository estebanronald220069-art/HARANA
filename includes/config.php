<?php
// includes/config.php
date_default_timezone_set('Asia/Manila'); // Add this line

// NEVER commit this file to GitHub!

// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', ''); // Change this in production
define('DB_NAME', 'harana_financial_system');

// Security settings
define('SECURE_MODE', true); // Set to true in production
define('SESSION_NAME', 'harana_session');
define('SESSION_LIFETIME', 7200); // 2 hours
define('BCRYPT_COST', 12);

// Application URLs
define('BASE_URL', 'http://localhost/harana_financial_system');
define('APP_NAME', 'Harana Financial System');

// Paths
define('ROOT_PATH', dirname(__DIR__) . '/');
define('UPLOAD_PATH', ROOT_PATH . 'assets/uploads/');
define('LOG_PATH', ROOT_PATH . 'logs/');

// Rate limiting
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_TIME', 900); // 15 minutes

// Secret key for encryption (CHANGE THIS!)
define('SECRET_KEY', 'your-32-character-secret-key-here!');

// Session configuration - only set if session not already started
if (session_status() === PHP_SESSION_NONE) {
    // Set session ini settings before starting session
    ini_set('session.use_strict_mode', 1);
    ini_set('session.use_cookies', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_secure', SECURE_MODE ? 1 : 0);
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.gc_maxlifetime', SESSION_LIFETIME);
    
    // Set session name
    session_name(SESSION_NAME);
    
    // Start session
    session_start();
}
?>