<?php
// includes/auth.php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db_connection.php';
require_once __DIR__ . '/security.php';

class Auth {
    private static $instance = null;
    private $db;
    
    private function __construct() {
        $this->db = Database::getInstance();
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    // Login function with proper user authentication
    public function login($username, $password, $totp_code = null) {
        // First check if user exists in users table
        $user = $this->db->getSingle(
            "SELECT * FROM users WHERE username = ? OR email = ?",
            [$username, $username],
            'ss'
        );
        
        if (!$user) {
            error_log("Login failed: User not found - $username");
            return [
                'success' => false,
                'error' => 'Invalid username or password'
            ];
        }
        
        // ===== CHECK IF ACCOUNT IS LOCKED =====
        if ($user['locked_until'] && $user['locked_until'] > date('Y-m-d H:i:s')) {
            $locked_until = strtotime($user['locked_until']);
            $minutes_left = ceil(($locked_until - time()) / 60);
            
            error_log("Locked login attempt for user: $username");
            
            return [
                'success' => false,
                'error' => "Account is temporarily locked. Please try again in $minutes_left minute(s)."
            ];
        }
        // ===== END LOCK CHECK =====
        
        // Check if user is active
        if (!$user['is_active']) {
            return [
                'success' => false,
                'error' => 'Your account is inactive. Please contact admin.'
            ];
        }
        
        // Verify password
        if (!password_verify($password, $user['password'])) {
            error_log("Login failed: Wrong password for user - $username");
            
            // Update login attempts (rate limiting)
            $this->db->execute(
                "UPDATE users SET login_attempts = login_attempts + 1, last_login_attempt = NOW() WHERE user_id = ?",
                [$user['user_id']],
                'i'
            );
            
            // Check if account should be locked
            $attempts = $user['login_attempts'] + 1;
            if ($attempts >= MAX_LOGIN_ATTEMPTS) {
                $lock_until = date('Y-m-d H:i:s', time() + LOGIN_LOCKOUT_TIME);
                $this->db->execute(
                    "UPDATE users SET locked_until = ? WHERE user_id = ?",
                    [$lock_until, $user['user_id']],
                    'si'
                );
                
                error_log("Account locked for user: $username until $lock_until");
                
                return [
                    'success' => false,
                    'error' => 'Too many failed attempts. Account locked for 15 minutes.'
                ];
            }
            
            // Calculate remaining attempts
            $remaining = MAX_LOGIN_ATTEMPTS - $attempts;
            
            return [
                'success' => false,
                'error' => "Invalid username or password. You have $remaining attempt(s) remaining."
            ];
        }

        // ===== DEBUG LINES =====
        error_log("=== LOGIN DEBUG ===");
        error_log("User ID: " . $user['user_id']);
        error_log("Username: " . $user['username']);
        error_log("Role: " . $user['role']);
        $debug_redirect = ($user['role'] === 'admin') ? 'admin/dashboard.php' : 'user/dashboard.php';
        error_log("Redirect URL: " . $debug_redirect);
        error_log("2FA Enabled: " . ($user['two_factor_enabled'] ? 'Yes' : 'No'));
        // ===== END DEBUG LINES =====

        // Check if 2FA is enabled
        if ($user['two_factor_enabled'] && !$totp_code) {
            $_SESSION['2fa_user_id'] = $user['user_id'];
            error_log("2FA required for user: " . $user['username']);
            return [
                'success' => false,
                'require_2fa' => true,
                'error' => '2FA code required'
            ];
        }

        // Verify 2FA code if provided
        if ($user['two_factor_enabled'] && $totp_code) {
            if (!Security::verifyTOTP($user['two_factor_secret'], $totp_code)) {
                error_log("2FA verification failed for user: " . $user['username']);
                return [
                    'success' => false,
                    'error' => 'Invalid 2FA code'
                ];
            }
            error_log("2FA verification successful for user: " . $user['username']);
        }

        // Check if user is admin or regular user
        $redirect_url = ($user['role'] === 'admin') ? 'admin/dashboard.php' : 'user/dashboard.php';

        // Create session
        $session_result = $this->createSession($user);

        if ($session_result['success']) {
            Security::logEvent('LOGIN_SUCCESS', "User {$user['username']} logged in");
            
            // Reset login attempts
            $this->db->execute(
                "UPDATE users SET login_attempts = 0, locked_until = NULL, last_login = NOW(), last_ip_address = ? WHERE user_id = ?",
                [Security::getClientIP(), $user['user_id']],
                'si'
            );
            
            error_log("Login successful! Redirecting to: " . $redirect_url);
            
            return [
                'success' => true,
                'redirect' => $redirect_url,
                'user' => $session_result['user']
            ];
        }

        return [
            'success' => false,
            'error' => 'Failed to create session'
        ];
    }
    
    // Verify 2FA after initial login
    public function verify2FA($user_id, $totp_code) {
        $user = $this->db->getSingle(
            "SELECT * FROM users WHERE user_id = ?",
            [$user_id], 'i'
        );
        
        if (!$user) {
            return [
                'success' => false,
                'error' => 'User not found'
            ];
        }
        
        if (!Security::verifyTOTP($user['two_factor_secret'], $totp_code)) {
            return [
                'success' => false,
                'error' => 'Invalid 2FA code'
            ];
        }
        
        return $this->createSession($user);
    }
  // Create user session
private function createSession($user) {
    // Generate session token
    $session_token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', time() + SESSION_LIFETIME);
    
    // Store session in database
    $insertResult = $this->db->execute(
        "INSERT INTO user_sessions (user_id, session_token, ip_address, user_agent, expires_at) 
         VALUES (?, ?, ?, ?, ?)",
        [$user['user_id'], $session_token, Security::getClientIP(), $_SERVER['HTTP_USER_AGENT'] ?? '', $expires],
        'issss'
    );
    
    // DEBUG: Log what role is being set
    error_log("=== CREATE SESSION DEBUG ===");
    error_log("User ID: " . $user['user_id']);
    error_log("Username: " . $user['username']);
    error_log("Role from database: " . $user['role']);
    error_log("=============================");
    
    // Set session variables
    $_SESSION['user_id'] = $user['user_id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['full_name'] = $user['full_name'];
    $_SESSION['email'] = $user['email'];
    $_SESSION['role'] = $user['role'];  // This is the critical line
    $_SESSION['session_token'] = $session_token;
    $_SESSION['login_time'] = time();
    
    // DEBUG: Confirm what was set
    error_log("Role stored in SESSION: " . $_SESSION['role']);
    error_log("=============================");
    
    // Clear 2FA session if set
    unset($_SESSION['2fa_user_id']);
    
    return [
        'success' => true,
        'user' => [
            'user_id' => $user['user_id'],
            'username' => $user['username'],
            'full_name' => $user['full_name'],
            'email' => $user['email'],
            'role' => $user['role']
        ]
    ];
}
    
    // Check if user is logged in
    public function isLoggedIn() {
        if (!isset($_SESSION['user_id']) || !isset($_SESSION['session_token'])) {
            return false;
        }
        
        // Verify session in database
        $session = $this->db->getSingle(
            "SELECT * FROM user_sessions WHERE session_token = ?",
            [$_SESSION['session_token']],
            's'
        );
        
        if ($session) {
            // Check if expired
            $now = date('Y-m-d H:i:s');
            if ($session['expires_at'] > $now) {
                return true;
            } else {
                // Delete expired session
                $this->db->execute(
                    "DELETE FROM user_sessions WHERE session_token = ?",
                    [$_SESSION['session_token']],
                    's'
                );
                return false;
            }
        }
        
        return false;
    }
    
    // Rate limit check method
    private function checkRateLimit($action, $max_attempts, $lockout_time) {
        $ip = Security::getClientIP();
        $key = "rate_limit_{$action}_{$ip}";
        
        if (!isset($_SESSION[$key])) {
            $_SESSION[$key] = [
                'attempts' => 1,
                'first_attempt' => time()
            ];
            return true;
        }
        
        $data = $_SESSION[$key];
        
        if (time() - $data['first_attempt'] > $lockout_time) {
            $_SESSION[$key] = [
                'attempts' => 1,
                'first_attempt' => time()
            ];
            return true;
        }
        
        if ($data['attempts'] >= $max_attempts) {
            return false;
        }
        
        $_SESSION[$key]['attempts']++;
        return true;
    }
    
    // Require login
    public function requireLogin() {
        if (!$this->isLoggedIn()) {
            header('Location: ' . BASE_URL . '/index.php');
            exit();
        }
    }
    
    // Check role
    public function requireRole($role) {
        $this->requireLogin();
        
        if ($_SESSION['role'] !== $role) {
            header('Location: ' . BASE_URL . '/index.php?error=access_denied');
            exit();
        }
    }
    
    // Get current user
   public function getCurrentUser() {
    if (!$this->isLoggedIn()) {
        return null;
    }
    
    return [
        'user_id' => $_SESSION['user_id'],
        'username' => $_SESSION['username'],
        'full_name' => $_SESSION['full_name'],
        'email' => $_SESSION['email'] ?? null,  // ← ADD THIS LINE
        'role' => $_SESSION['role']
    ];
}
    
    // Get full user details from database
    public function getUserDetails() {
        if (!$this->isLoggedIn()) {
            return null;
        }
        
        return $this->db->getSingle(
            "SELECT * FROM users WHERE user_id = ?",
            [$_SESSION['user_id']],
            'i'
        );
    }
    
    // Logout
    public function logout() {
        if (isset($_SESSION['session_token'])) {
            $this->db->execute(
                "DELETE FROM user_sessions WHERE session_token = ?",
                [$_SESSION['session_token']],
                's'
            );
            error_log("logout: Session deleted");
        }
        
        Security::logEvent('LOGOUT', "User logged out");
        
        $_SESSION = [];
        session_destroy();
        
        header('Location: ' . BASE_URL . '/index.php');
        exit();
    }
    
    // Setup 2FA for user
    public function setup2FA($user_id) {
        $secret = Security::generateTOTPSecret();
        $backup_codes = Security::generateBackupCodes();
        
        foreach ($backup_codes as $code) {
            $hashed_code = password_hash($code, PASSWORD_DEFAULT);
            $this->db->execute(
                "INSERT INTO two_factor_backup_codes (user_id, backup_code) VALUES (?, ?)",
                [$user_id, $hashed_code],
                'is'
            );
        }
        
        $this->db->execute(
            "UPDATE users SET two_factor_secret = ?, two_factor_enabled = 1 WHERE user_id = ?",
            [$secret, $user_id],
            'si'
        );
        
        Security::logEvent('2FA_SETUP', "2FA enabled for user ID: $user_id");
        
        return [
            'secret' => $secret,
            'backup_codes' => $backup_codes
        ];
    }
}

// Global auth instance
$auth = Auth::getInstance();
?>