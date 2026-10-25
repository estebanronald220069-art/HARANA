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

    /* ============================================================
       LOGIN — with real 2FA challenge + backup-code fallback
       ============================================================ */
    public function login($username, $password, $totp_code = null) {
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

        // ===== ACCOUNT LOCK CHECK =====
        if ($user['locked_until'] && $user['locked_until'] > date('Y-m-d H:i:s')) {
            $locked_until = strtotime($user['locked_until']);
            $minutes_left = ceil(($locked_until - time()) / 60);
            error_log("Locked login attempt for user: $username");
            return [
                'success' => false,
                'error' => "Account is temporarily locked. Please try again in $minutes_left minute(s)."
            ];
        }

        // ===== ACTIVE CHECK =====
        if (!$user['is_active']) {
            return [
                'success' => false,
                'error' => 'Your account is inactive. Please contact admin.'
            ];
        }

        // ===== PASSWORD =====
        if (!password_verify($password, $user['password'])) {
            error_log("Login failed: Wrong password for user - $username");

            $this->db->execute(
                "UPDATE users SET login_attempts = login_attempts + 1, last_login_attempt = NOW() WHERE user_id = ?",
                [$user['user_id']],
                'i'
            );

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

            $remaining = MAX_LOGIN_ATTEMPTS - $attempts;
            return [
                'success' => false,
                'error' => "Invalid username or password. You have $remaining attempt(s) remaining."
            ];
        }

        // ===== 2FA CHALLENGE =====
        if (!empty($user['two_factor_enabled'])) {
            // No code supplied → ask the caller to render the 2FA form
            if (!$totp_code) {
                $_SESSION['2fa_user_id']    = (int)$user['user_id'];
                $_SESSION['2fa_started_at'] = time();
                error_log("2FA required for user: " . $user['username']);
                return [
                    'success'     => false,
                    'require_2fa' => true,
                    'error'       => '2FA code required'
                ];
            }

            // A code was supplied — try TOTP first, then backup code
            $digits = preg_replace('/\D/', '', $totp_code);
            $ok = false;

            if (strlen($digits) === 6 && is_string($user['two_factor_secret']) && $user['two_factor_secret'] !== '') {
                $ok = Security::verifyTOTP($user['two_factor_secret'], $digits);
            }
            if (!$ok && $this->verifyBackupCode((int)$user['user_id'], $totp_code)) {
                $ok = true;
            }

            if (!$ok) {
                $this->db->execute(
                    "UPDATE users SET login_attempts = login_attempts + 1 WHERE user_id = ?",
                    [$user['user_id']],
                    'i'
                );
                Security::logEvent('2FA_FAILED', "Failed 2FA for user: {$user['username']}");
                error_log("2FA verification failed for user: " . $user['username']);
                return [
                    'success'     => false,
                    'require_2fa' => true,
                    'error'       => 'Invalid 2FA code.'
                ];
            }

            error_log("2FA verification successful for user: " . $user['username']);
            unset($_SESSION['2fa_user_id'], $_SESSION['2fa_started_at']);
        }

        // ===== SUCCESS =====
       if ($user['role'] === 'admin') {
    $redirect_url = 'admin/dashboard.php';
} elseif (($user['account_status'] ?? 'joined') === 'approved') {
    $redirect_url = 'user/dashboard.php';
} else {
    $redirect_url = 'user/welcome.php';
}
        $session_result = $this->createSession($user);

        if ($session_result['success']) {
            Security::logEvent('LOGIN_SUCCESS', "User {$user['username']} logged in");

            $this->db->execute(
                "UPDATE users SET login_attempts = 0, locked_until = NULL, last_login = NOW(), last_ip_address = ? WHERE user_id = ?",
                [Security::getClientIP(), $user['user_id']],
                'si'
            );

            error_log("Login successful! Redirecting to: " . $redirect_url);

            return [
                'success'  => true,
                'redirect' => $redirect_url,
                'user'     => $session_result['user']
            ];
        }

        return [
            'success' => false,
            'error' => 'Failed to create session'
        ];
    }

    /* ============================================================
       VERIFY 2FA — used by index.php?step=2fa
       ============================================================ */
    public function verify2FA($user_id, $totp_code) {
        // Guard: only allow if a challenge is actually in progress for this user
        if (empty($_SESSION['2fa_user_id']) || (int)$_SESSION['2fa_user_id'] !== (int)$user_id) {
            return [
                'success' => false,
                'error' => 'No active 2FA challenge. Please log in again.'
            ];
        }

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

        if (empty($user['two_factor_enabled'])) {
            return [
                'success' => false,
                'error' => '2FA is not enabled for this account.'
            ];
        }

        $digits = preg_replace('/\D/', '', $totp_code);
        $ok = false;

        if (strlen($digits) === 6 && !empty($user['two_factor_secret'])) {
            $ok = Security::verifyTOTP($user['two_factor_secret'], $digits);
        }
        if (!$ok && $this->verifyBackupCode((int)$user['user_id'], $totp_code)) {
            $ok = true;
        }

        if (!$ok) {
            Security::logEvent('2FA_FAILED', "Failed 2FA verification for user ID: $user_id");
            return [
                'success' => false,
                'error' => 'Invalid 2FA code'
            ];
        }

        unset($_SESSION['2fa_user_id'], $_SESSION['2fa_started_at']);
        return $this->createSession($user);
    }

    /* ============================================================
       SESSION
       ============================================================ */
    private function createSession($user) {
        $session_token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', time() + SESSION_LIFETIME);

        $this->db->execute(
            "INSERT INTO user_sessions (user_id, session_token, ip_address, user_agent, expires_at)
             VALUES (?, ?, ?, ?, ?)",
            [$user['user_id'], $session_token, Security::getClientIP(), $_SERVER['HTTP_USER_AGENT'] ?? '', $expires],
            'issss'
        );

        $_SESSION['user_id']       = $user['user_id'];
        $_SESSION['username']      = $user['username'];
        $_SESSION['full_name']     = $user['full_name'];
        $_SESSION['email']         = $user['email'];
        $_SESSION['role']           = $user['role'];
$_SESSION['account_status'] = $user['account_status'] ?? 'joined';
$_SESSION['session_token']  = $session_token;
        $_SESSION['login_time']    = time();

        unset($_SESSION['2fa_user_id'], $_SESSION['2fa_started_at']);

        return [
            'success' => true,
            'user' => [
                'user_id'   => $user['user_id'],
                'username'  => $user['username'],
                'full_name' => $user['full_name'],
                'email'     => $user['email'],
                'role'      => $user['role']
            ]
        ];
    }

    public function isLoggedIn() {
        if (!isset($_SESSION['user_id']) || !isset($_SESSION['session_token'])) {
            return false;
        }

        $session = $this->db->getSingle(
            "SELECT * FROM user_sessions WHERE session_token = ?",
            [$_SESSION['session_token']],
            's'
        );

        if ($session) {
            $now = date('Y-m-d H:i:s');
            if ($session['expires_at'] > $now) {
                return true;
            } else {
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

    private function checkRateLimit($action, $max_attempts, $lockout_time) {
        $ip = Security::getClientIP();
        $key = "rate_limit_{$action}_{$ip}";

        if (!isset($_SESSION[$key])) {
            $_SESSION[$key] = ['attempts' => 1, 'first_attempt' => time()];
            return true;
        }

        $data = $_SESSION[$key];

        if (time() - $data['first_attempt'] > $lockout_time) {
            $_SESSION[$key] = ['attempts' => 1, 'first_attempt' => time()];
            return true;
        }

        if ($data['attempts'] >= $max_attempts) {
            return false;
        }

        $_SESSION[$key]['attempts']++;
        return true;
    }

    public function requireLogin() {
        if (!$this->isLoggedIn()) {
            header('Location: ' . BASE_URL . '/index.php');
            exit();
        }
    }

    public function requireRole($role) {
        $this->requireLogin();
        if ($_SESSION['role'] !== $role) {
            header('Location: ' . BASE_URL . '/index.php?error=access_denied');
            exit();
        }
    }

    public function getCurrentUser() {
        if (!$this->isLoggedIn()) {
            return null;
        }

       return [
    'user_id'        => $_SESSION['user_id'],
    'username'       => $_SESSION['username'],
    'full_name'      => $_SESSION['full_name'],
    'email'          => $_SESSION['email'] ?? null,
    'role'           => $_SESSION['role'],
    'account_status' => $_SESSION['account_status'] ?? 'joined',
];
    }

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

        // Explicitly clear any 2FA state
        unset(
            $_SESSION['2fa_user_id'],
            $_SESSION['2fa_started_at'],
            $_SESSION['pending_2fa_secret'],
            $_SESSION['pending_backup_codes'],
            $_SESSION['show_backup_codes_once']
        );

        $_SESSION = [];
        session_destroy();

        header('Location: ' . BASE_URL . '/index.php');
        exit();
    }

    /* ============================================================
       2FA — STAGE 1: generate new secret + backup codes (NOT enabled)
       ============================================================ */
    public function setup2FA($user_id) {
        $user_id = (int)$user_id;

        // Clear any previous pending enrollment + codes
        $this->db->execute(
            "DELETE FROM two_factor_backup_codes WHERE user_id = ?",
            [$user_id], 'i'
        );

        $secret = Security::generateTOTPSecret();
        $codes  = Security::generateBackupCodes(8);

        foreach ($codes as $code) {
            $hash = password_hash($code, PASSWORD_DEFAULT);
            $this->db->execute(
                "INSERT INTO two_factor_backup_codes (user_id, backup_code) VALUES (?, ?)",
                [$user_id, $hash],
                'is'
            );
        }

        // Store the secret but DO NOT enable until confirmed
        $this->db->execute(
            "UPDATE users SET two_factor_secret = ?, two_factor_enabled = 0 WHERE user_id = ?",
            [$secret, $user_id],
            'si'
        );

        Security::logEvent('2FA_SETUP_STARTED', "2FA enrollment started for user ID: $user_id");

        return [
            'secret'       => $secret,
            'backup_codes' => $codes
        ];
    }

    /* ============================================================
       2FA — STAGE 2: verify user's code, then enable
       ============================================================ */
    public function confirm2FA($user_id, $code) {
        $user_id = (int)$user_id;
        $row = $this->db->getSingle(
            "SELECT two_factor_secret, two_factor_enabled FROM users WHERE user_id = ?",
            [$user_id], 'i'
        );

        if (!$row || empty($row['two_factor_secret'])) {
            return ['success' => false, 'error' => 'No pending 2FA enrollment found.'];
        }
        if (!empty($row['two_factor_enabled'])) {
            return ['success' => false, 'error' => '2FA is already enabled.'];
        }

        $digits = preg_replace('/\D/', '', $code);
        if (strlen($digits) !== 6) {
            return ['success' => false, 'error' => 'Enter the 6-digit code from your authenticator app.'];
        }

        if (!Security::verifyTOTP($row['two_factor_secret'], $digits)) {
            Security::logEvent('2FA_CONFIRM_FAILED', "Invalid code during 2FA enrollment for user ID: $user_id");
            return ['success' => false, 'error' => 'Invalid code. Please try again.'];
        }

        $this->db->execute(
            "UPDATE users SET two_factor_enabled = 1 WHERE user_id = ?",
            [$user_id], 'i'
        );

        Security::logEvent('2FA_ENABLED', "2FA enabled for user ID: $user_id");
        return ['success' => true];
    }

    /* ============================================================
       2FA — Cancel an in-progress enrollment
       ============================================================ */
    public function cancel2FA($user_id) {
        $user_id = (int)$user_id;
        $this->db->execute(
            "UPDATE users SET two_factor_secret = NULL, two_factor_enabled = 0
             WHERE user_id = ? AND two_factor_enabled = 0",
            [$user_id], 'i'
        );
        $this->db->execute(
            "DELETE FROM two_factor_backup_codes WHERE user_id = ?",
            [$user_id], 'i'
        );
        Security::logEvent('2FA_SETUP_CANCELLED', "2FA enrollment cancelled for user ID: $user_id");
    }

    /* ============================================================
       2FA — Disable (caller MUST have verified password)
       ============================================================ */
    public function disable2FA($user_id) {
        $user_id = (int)$user_id;
        $this->db->execute(
            "UPDATE users SET two_factor_secret = NULL, two_factor_enabled = 0 WHERE user_id = ?",
            [$user_id], 'i'
        );
        $this->db->execute(
            "DELETE FROM two_factor_backup_codes WHERE user_id = ?",
            [$user_id], 'i'
        );
        Security::logEvent('2FA_DISABLED', "2FA disabled for user ID: $user_id");
    }

    /* ============================================================
       Backup code verification (login)
       ============================================================ */
    public function verifyBackupCode($user_id, $code) {
        $user_id = (int)$user_id;
        $code = strtoupper(trim((string)$code));
        if ($code === '') return false;

        $rows = $this->db->getAll(
            "SELECT code_id, backup_code FROM two_factor_backup_codes
             WHERE user_id = ? AND is_used = 0",
            [$user_id], 'i'
        );

        foreach ($rows as $row) {
            if (password_verify($code, $row['backup_code'])) {
                $this->db->execute(
                    "UPDATE two_factor_backup_codes SET is_used = 1 WHERE code_id = ?",
                    [(int)$row['code_id']], 'i'
                );
                Security::logEvent('2FA_BACKUP_USED', "Backup code consumed for user ID: $user_id");
                return true;
            }
        }
        return false;
    }

    /* ============================================================
       Regenerate backup codes (user must be authenticated + 2FA on)
       Returns plaintext codes for one-time display.
       ============================================================ */
    public function regenerateBackupCodes($user_id) {
        $user_id = (int)$user_id;

        $this->db->execute(
            "DELETE FROM two_factor_backup_codes WHERE user_id = ?",
            [$user_id], 'i'
        );

        $codes = Security::generateBackupCodes(8);
        foreach ($codes as $code) {
            $hash = password_hash($code, PASSWORD_DEFAULT);
            $this->db->execute(
                "INSERT INTO two_factor_backup_codes (user_id, backup_code) VALUES (?, ?)",
                [$user_id, $hash],
                'is'
            );
        }

                       Security::logEvent('2FA_CODES_REGENERATED', "Backup codes regenerated for user ID: $user_id");
        return $codes;
    }

    /**
     * Require account_status === 'approved'. Redirects joined/pending users
     * to welcome.php. Admins are always allowed.
     */
    public function requireApprovedMember(): void
    {
        $this->requireLogin();
        if (($_SESSION['role'] ?? '') === 'admin') return; // admins bypass
        if (($_SESSION['account_status'] ?? 'joined') !== 'approved') {
            header('Location: ' . BASE_URL . '/user/welcome.php');
            exit();
        }
    }
}

// Global auth instance
$auth = Auth::getInstance();
?>