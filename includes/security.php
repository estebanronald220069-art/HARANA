<?php
// includes/security.php
class Security {
    
    /**
     * Generate CSRF token
     */
    public static function generateCSRFToken() {
        if (!isset($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
    
    /**
     * Validate CSRF token
     */
    public static function validateCSRFToken($token) {
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
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
        // Remove null bytes
        $data = str_replace(chr(0), '', $data);
        // Trim whitespace
        $data = trim($data);
        // Convert special characters to HTML entities (for safe output)
        return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    }
    
    /**
     * Sanitize output for HTML (use this for DISPLAYING data)
     */
    public static function htmlEscape($data) {
        if (is_null($data)) return '';
        return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    }
    
    /**
     * Validate and sanitize input based on type
     */
    public static function validateInput($data, $type = 'string', $options = []) {
        if (is_null($data) && !isset($options['required'])) {
            return null;
        }
        
        $data = trim($data);
        
        switch ($type) {
            case 'string':
                // Remove any null bytes
                $data = str_replace(chr(0), '', $data);
                // Remove any control characters except tabs and newlines
                $data = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $data);
                // Limit length
                $max = $options['max'] ?? 255;
                $data = mb_substr($data, 0, $max, 'UTF-8');
                return $data;
                
            case 'name':
                // Names: letters, spaces, dots, apostrophes, hyphens only
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
                // Remove everything except digits and +, -
                $data = preg_replace('/[^0-9\+\-]/', '', $data);
                // Limit length
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
                // Check if it's a valid date format
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
                // Remove null bytes and control characters
                $data = str_replace(chr(0), '', $data);
                $data = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $data);
                // Allow letters, numbers, commas, periods, spaces, hyphens, #, /
                $data = preg_replace('/[^\p{L}\p{N}\s\,\.\-\#\/]/u', '', $data);
                $max = $options['max'] ?? 500;
                $data = mb_substr($data, 0, $max, 'UTF-8');
                return $data;
                
            case 'textarea':
                // Remove null bytes
                $data = str_replace(chr(0), '', $data);
                // Remove control characters except newlines and tabs
                $data = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $data);
                $max = $options['max'] ?? 1000;
                $data = mb_substr($data, 0, $max, 'UTF-8');
                return $data;
                
            default:
                return $data;
        }
    }
    
    /**
     * Validate entire form data array
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
     * Get client IP address
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
     * Log security event
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
    
    /**
     * Generate TOTP secret for 2FA
     */
    public static function generateTOTPSecret() {
        return bin2hex(random_bytes(20));
    }
    
    /**
     * Verify TOTP code
     */
    public static function verifyTOTP($secret, $code) {
        // Simple TOTP verification - accepts any 6-digit code for demo
        // In production, you should use a proper TOTP library like 'sonata-project/google-authenticator'
        return strlen($code) === 6 && ctype_digit($code);
    }
    
    /**
     * Generate backup codes for 2FA
     */
    public static function generateBackupCodes($count = 8) {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = bin2hex(random_bytes(5)); // 10-character hex code
        }
        return $codes;
    }
}
?>