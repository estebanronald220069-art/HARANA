<?php
// includes/security.php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db_connection.php';

class Security {

    /**
     * Generate CSRF token.
     * Pass true to force a new token (call after sensitive actions like
     * enabling/disabling 2FA or changing the password).
     */
    public static function generateCSRFToken(bool $forceNew = false) {
        if ($forceNew || empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * Validate CSRF token (timing-safe).
     */
    public static function validateCSRFToken($token) {
        return isset($_SESSION['csrf_token'])
            && is_string($token)
            && hash_equals($_SESSION['csrf_token'], $token);
    }

    /**
     * Legacy sanitize method - use validateInput instead for new code
     * @deprecated Use validateInput() for better validation
     */
    public static function sanitize($data) {
        if (is_null($data)) return '';
        if (is_array($data)) {
            return array_map([self::class, 'sanitize'], $data);
        }
        $data = str_replace(chr(0), '', $data);
        $data = trim($data);
        return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Sanitize output for HTML (use this for DISPLAYING data).
     */
    public static function htmlEscape($data) {
        if (is_null($data)) return '';
        return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Validate and sanitize input based on type.
     */
    public static function validateInput($data, $type = 'string', $options = []) {
        if (is_null($data) && !isset($options['required'])) {
            return null;
        }

        $data = trim($data);

        switch ($type) {
            case 'string':
                $data = str_replace(chr(0), '', $data);
                $data = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $data);
                $max = $options['max'] ?? 255;
                $data = mb_substr($data, 0, $max, 'UTF-8');
                return $data;

            case 'name':
                $data = str_replace(chr(0), '', $data);
                $data = preg_replace('/[^\p{L}\s\.\'\-\x20]/u', '', $data);
                $max = $options['max'] ?? 100;
                $data = mb_substr($data, 0, $max, 'UTF-8');
                return $data;

            case 'email':
                $data = filter_var($data, FILTER_SANITIZE_EMAIL);
                if (!filter_var($data, FILTER_VALIDATE_EMAIL)) {
                    return false;
                }
                return $data;

            case 'phone':
                $data = preg_replace('/[^0-9\+\-]/', '', $data);
                $max = $options['max'] ?? 20;
                $data = substr($data, 0, $max);
                return $data;

            case 'number':
                if (!is_numeric($data)) {
                    return false;
                }
                $min = $options['min'] ?? null;
                $max = $options['max'] ?? null;
                $data = floatval($data);
                if ($min !== null && $data < $min) return false;
                if ($max !== null && $data > $max) return false;
                if (isset($options['integer']) && $options['integer']) {
                    return intval($data);
                }
                return $data;

            case 'age':
                $data = intval($data);
                if ($data < 0 || $data > 150) {
                    return false;
                }
                return $data;

            case 'date':
                $data = trim($data);
                if (empty($data)) return null;
                $date = DateTime::createFromFormat('Y-m-d', $data);
                if (!$date || $date->format('Y-m-d') !== $data) {
                    return false;
                }
                return $data;

            case 'gender':
                $allowed = ['male', 'female', 'other', ''];
                $data = strtolower(trim($data));
                if (!in_array($data, $allowed)) {
                    return false;
                }
                return $data;

            case 'civil_status':
                $allowed = ['single', 'married', 'widowed', 'separated', ''];
                $data = strtolower(trim($data));
                if (!in_array($data, $allowed)) {
                    return false;
                }
                return $data;

            case 'boolean':
                return filter_var($data, FILTER_VALIDATE_BOOLEAN);

            case 'address':
                $data = str_replace(chr(0), '', $data);
                $data = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $data);
                $data = preg_replace('/[^\p{L}\p{N}\s\,\.\-\#\/]/u', '', $data);
                $max = $options['max'] ?? 500;
                $data = mb_substr($data, 0, $max, 'UTF-8');
                return $data;

            case 'textarea':
                $data = str_replace(chr(0), '', $data);
                $data = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $data);
                $max = $options['max'] ?? 1000;
                $data = mb_substr($data, 0, $max, 'UTF-8');
                return $data;

            default:
                return $data;
        }
    }

    /**
     * Validate entire form data array.
     */
    public static function validateForm($data, $rules) {
        $validated = [];
        $errors = [];

        foreach ($rules as $field => $rule) {
            $value = $data[$field] ?? null;
            $type = $rule['type'] ?? 'string';
            $options = $rule['options'] ?? [];
            $required = $rule['required'] ?? false;

            if ($required && (is_null($value) || $value === '')) {
                $errors[$field] = $rule['label'] . ' is required';
                continue;
            }

            if (!is_null($value) && $value !== '') {
                $validated_value = self::validateInput($value, $type, $options);
                if ($validated_value === false) {
                    $errors[$field] = $rule['label'] . ' is invalid';
                } else {
                    $validated[$field] = $validated_value;
                }
            } else {
                $validated[$field] = null;
            }
        }

        return [
            'data' => $validated,
            'errors' => $errors,
            'is_valid' => empty($errors)
        ];
    }

    /**
     * Get client IP address.
     */
    public static function getClientIP() {
        $ip_keys = [
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_FORWARDED',
            'HTTP_X_CLUSTER_CLIENT_IP',
            'HTTP_FORWARDED_FOR',
            'HTTP_FORWARDED',
            'REMOTE_ADDR'
        ];

        foreach ($ip_keys as $key) {
            if (isset($_SERVER[$key])) {
                $ips = explode(',', $_SERVER[$key]);
                $ip = trim($ips[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    /**
     * Log security event (legacy — writes to system_logs).
     */
    public static function logEvent($action, $description) {
        $db = Database::getInstance();
        $user_id = $_SESSION['user_id'] ?? null;
        $ip = self::getClientIP();
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';

        $sql = "INSERT INTO system_logs (user_id, action, description, ip_address, user_agent)
                VALUES (?, ?, ?, ?, ?)";
        $db->execute($sql, [$user_id, $action, $description, $ip, $user_agent], 'issss');
    }

    /* ============================================================
       TOTP (RFC 6238) — real, working 2FA
       ============================================================ */

    /**
     * Generate a Base32 TOTP secret (RFC 4648 alphabet A-Z2-7).
     * Authenticator apps expect this format.
     */
    public static function generateTOTPSecret(int $bytes = 20): string {
        return self::base32Encode(random_bytes($bytes));
    }

    /**
     * Verify a 6-digit TOTP code against a Base32 secret.
     * Implements RFC 6238 with a ±$window step tolerance (30 s each).
     * Also rejects re-used steps within the same session (replay protection).
     */
    public static function verifyTOTP(string $secret, string $code, int $window = 1): bool {
        $code = preg_replace('/\D/', '', $code);
        if (strlen($code) !== 6) return false;

        $key = self::base32Decode($secret);
        if ($key === false || $key === '') return false;

        $timeStep   = 30;
        $currentStep = intdiv(time(), $timeStep);
        $replayKey  = 'twofa_last_step_' . md5($secret);
        $lastStep   = (int)($_SESSION[$replayKey] ?? 0);

        for ($i = -$window; $i <= $window; $i++) {
            $step = $currentStep + $i;
            if ($step <= $lastStep) continue;

            if (hash_equals(self::hotp($key, $step), $code)) {
                $_SESSION[$replayKey] = $step;
                return true;
            }
        }
        return false;
    }

    /**
     * HOTP (RFC 4226) — building block of TOTP.
     */
    private static function hotp(string $key, int $counter): string {
        $binCounter = pack('N*', 0) . pack('N*', $counter);
        $hash       = hash_hmac('sha1', $binCounter, $key, true);

        $offset = ord($hash[19]) & 0x0F;
        $binary = ((ord($hash[$offset])     & 0x7f) << 24)
                | ((ord($hash[$offset + 1]) & 0xff) << 16)
                | ((ord($hash[$offset + 2]) & 0xff) << 8)
                |  (ord($hash[$offset + 3]) & 0xff);

        return str_pad((string)($binary % 1000000), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Build the otpauth:// URI for a QR code.
     */
    public static function totpProvisioningUri(string $secret, string $account, string $issuer): string {
        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=6&period=30',
            rawurlencode($issuer),
            rawurlencode($account),
            $secret,
            rawurlencode($issuer)
        );
    }

    /**
     * Base32 encode (RFC 4648, no padding).
     */
    private static function base32Encode(string $data): string {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $out  = '';
        $bits = 0;
        $val  = 0;
        for ($i = 0; $i < strlen($data); $i++) {
            $val   = ($val << 8) | ord($data[$i]);
            $bits += 8;
            while ($bits >= 5) {
                $bits -= 5;
                $out  .= $alphabet[($val >> $bits) & 0x1F];
            }
        }
        if ($bits > 0) {
            $out .= $alphabet[($val << (5 - $bits)) & 0x1F];
        }
        return $out;
    }

    /**
     * Base32 decode (accepts padded or unpadded, case-insensitive).
     */
    private static function base32Decode(string $b32): string|false {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $b32 = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $b32));
        if ($b32 === '') return false;

        $bits = 0;
        $val  = 0;
        $out  = '';
        for ($i = 0; $i < strlen($b32); $i++) {
            $pos = strpos($alphabet, $b32[$i]);
            if ($pos === false) return false;
            $val   = ($val << 5) | $pos;
            $bits += 5;
            if ($bits >= 8) {
                $bits -= 8;
                $out  .= chr(($val >> $bits) & 0xFF);
            }
        }
        return $out;
    }

    /* ============================================================
       Backup codes
       ============================================================ */

    /**
     * Generate N plaintext backup codes. Caller must hash before storing.
     */
    public static function generateBackupCodes(int $count = 8): array {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = strtoupper(bin2hex(random_bytes(5))); // 10 hex chars
        }
        return $codes;
    }
}