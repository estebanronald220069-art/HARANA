<?php
// includes/sms.php
// ============================================================
// Pluggable SMS sender.
//
// TODAY:        Stub — logs the payload to logs/sms_stub.log
//               and to error_log. No SMS is actually sent.
//
// TOMORROW:     Set SMS_PROVIDER=<provider> in .env and fill in
//               credentials. The switch inside sendViaProvider()
//               dispatches to the right implementation.
//
// Supported providers (implemented as stubs you can finish later):
//   - 'semaphore'  (Philippines - https://semaphore.co)
//   - 'movider'    (Philippines - https://movider.co)
//   - 'twilio'     (International - https://twilio.com)
//
// Usage:
//   $res = Sms::send('09171234567', 'Your application was not approved.');
//   // => ['success' => true, 'provider' => 'stub', 'message_id' => '...', 'error' => null]
// ============================================================

/* ============================================================
   Load Composer autoloader if present (some providers use SDKs)
   ============================================================ */
$autoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}

/* ============================================================
   Load .env (only once per request) — same pattern as mailer.php
   ============================================================ */
if (class_exists(\Dotenv\Dotenv::class)) {
    static $env_loaded_sms = false;
    if (!$env_loaded_sms) {
        try {
            $dotenv = \Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
            $dotenv->safeLoad();
            $env_loaded_sms = true;
        } catch (Throwable $e) {
            error_log('Sms: .env load failed: ' . $e->getMessage());
        }
    }
}

class Sms
{
    /* ============================================================
       Read config from environment variables.
       ============================================================ */
    private static function env(string $key, $default = '')
    {
        if (array_key_exists($key, $_ENV)    && $_ENV[$key]    !== '') return $_ENV[$key];
        if (array_key_exists($key, $_SERVER) && $_SERVER[$key] !== '') return $_SERVER[$key];
        $v = getenv($key);
        return ($v !== false && $v !== '') ? $v : $default;
    }

    /* ============================================================
       Normalize a PH mobile number to international format.
       Accepts:   09171234567  |  +639171234567  |  639171234567
       Returns:   +639171234567  (or '' if invalid)
       ============================================================ */
    public static function normalizeNumber(string $number): string
    {
        $number = preg_replace('/[^0-9+]/', '', trim($number));
        if ($number === '') return '';

        // Convert local formats to +63...
        if (str_starts_with($number, '0')) {
            $number = '+63' . substr($number, 1);
        } elseif (str_starts_with($number, '63')) {
            $number = '+' . $number;
        } elseif (!str_starts_with($number, '+')) {
            $number = '+' . $number;
        }

        // Basic sanity check: + then 8-15 digits
        if (!preg_match('/^\+\d{8,15}$/', $number)) {
            return '';
        }
        return $number;
    }

    /**
     * Send an SMS message.
     *
     * @param string $toNumber  Recipient mobile number (any format)
     * @param string $message   Message body (max 160 chars recommended)
     * @return array [
     *     'success'    => bool,
     *     'provider'   => string,
     *     'message_id' => string,
     *     'error'      => string|null,
     * ]
     */
    public static function send(string $toNumber, string $message): array
    {
        // ---- Validate + normalize recipient ----------------------
        $normalized = self::normalizeNumber($toNumber);
        if ($normalized === '') {
            return [
                'success'    => false,
                'provider'   => self::provider(),
                'message_id' => '',
                'error'      => 'Invalid mobile number: ' . $toNumber,
            ];
        }

        // ---- Validate + trim message -----------------------------
        $message = trim($message);
        if ($message === '') {
            return [
                'success'    => false,
                'provider'   => self::provider(),
                'message_id' => '',
                'error'      => 'Message body is empty.',
            ];
        }
        // SMS best practice: keep under 160 chars for single part
        if (mb_strlen($message) > 480) {
            $message = mb_substr($message, 0, 477) . '...';
        }

        // ---- Provider dispatch -----------------------------------
        $provider = self::provider();

        switch ($provider) {
            case 'semaphore':
                return self::sendViaSemaphore($normalized, $message);
            case 'movider':
                return self::sendViaMovider($normalized, $message);
            case 'twilio':
                return self::sendViaTwilio($normalized, $message);
            case 'stub':
            default:
                return self::sendViaStub($normalized, $message);
        }
    }

    /* ============================================================
       Provider: STUB (default — safe, no-op)
       ============================================================ */
    private static function sendViaStub(string $to, string $message): array
    {
        $message_id = 'stub_' . date('YmdHis') . '_' . bin2hex(random_bytes(4));

        // Write to logs/sms_stub.log
        $dir = __DIR__ . '/../logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $file = $dir . '/sms_stub.log';

        $line  = str_repeat('=', 60) . "\n";
        $line .= '[' . date('Y-m-d H:i:s') . "] message_id={$message_id}\n";
        $line .= 'To:      ' . $to . "\n";
        $line .= 'Message: ' . $message . "\n";
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);

        // Also log a one-liner to PHP error_log
        error_log("[SMS-STUB] → {$to} | {$message}");

        return [
            'success'    => true,
            'provider'   => 'stub',
            'message_id' => $message_id,
            'error'      => null,
        ];
    }

    /* ============================================================
       Provider: Semaphore (Philippines)
       ------------------------------------------------------------
       .env keys:
           SMS_SEMAPHORE_API_KEY=xxxxx
           SMS_SEMAPHORE_SENDER=NHGL   (optional)
       ============================================================ */
    private static function sendViaSemaphore(string $to, string $message): array
    {
        $apiKey = self::env('SMS_SEMAPHORE_API_KEY', '');
        $sender = self::env('SMS_SEMAPHORE_SENDER', '');

        if ($apiKey === '') {
            error_log('Sms/Semaphore: API key missing. Falling back to stub.');
            return self::sendViaStub($to, $message);
        }

        // TODO: implement real HTTP call using cURL to https://semaphore.co/api/v4/messages
        // Placeholder — logs intent and returns stub success for now.
        error_log("[SMS-SEMAPHORE] Would send to {$to}: {$message}");
        return self::sendViaStub($to, $message);
    }

    /* ============================================================
       Provider: Movider (Philippines)
       ------------------------------------------------------------
       .env keys:
           SMS_MOVIDER_API_KEY=xxxxx
           SMS_MOVIDER_API_SECRET=xxxxx
           SMS_MOVIDER_SENDER=NHGL
       ============================================================ */
    private static function sendViaMovider(string $to, string $message): array
    {
        $apiKey    = self::env('SMS_MOVIDER_API_KEY', '');
        $apiSecret = self::env('SMS_MOVIDER_API_SECRET', '');

        if ($apiKey === '' || $apiSecret === '') {
            error_log('Sms/Movider: credentials missing. Falling back to stub.');
            return self::sendViaStub($to, $message);
        }

        // TODO: implement real HTTP call to https://api.movider.co/v1/sms
        error_log("[SMS-MOVIDER] Would send to {$to}: {$message}");
        return self::sendViaStub($to, $message);
    }

    /* ============================================================
       Provider: Twilio (International)
       ------------------------------------------------------------
       .env keys:
           SMS_TWILIO_SID=xxxxx
           SMS_TWILIO_TOKEN=xxxxx
           SMS_TWILIO_FROM=+1xxxxxxxxxx
       ============================================================ */
    private static function sendViaTwilio(string $to, string $message): array
    {
        $sid   = self::env('SMS_TWILIO_SID', '');
        $token = self::env('SMS_TWILIO_TOKEN', '');
        $from  = self::env('SMS_TWILIO_FROM', '');

        if ($sid === '' || $token === '' || $from === '') {
            error_log('Sms/Twilio: credentials missing. Falling back to stub.');
            return self::sendViaStub($to, $message);
        }

        // TODO: implement real HTTP call to https://api.twilio.com/2010-04-01/Accounts/{sid}/Messages.json
        error_log("[SMS-TWILIO] Would send to {$to}: {$message}");
        return self::sendViaStub($to, $message);
    }

    /* ============================================================
       Convenience wrapper — Rejection notification
       ------------------------------------------------------------
       Sends a short SMS letting the applicant know their
       application was not approved and pointing them to email.
       ============================================================ */
    public static function sendRejection(
        string $toNumber,
        string $firstName,
        string $reasonLabel,
        string $statusUrl = ''
    ): array {
        $name = trim($firstName) !== '' ? $firstName : 'Applicant';
        $text = "NHGL: Dear {$name}, your membership application was NOT approved. "
              . "Reason: {$reasonLabel}. ";

        if ($statusUrl !== '') {
            $text .= "Details: {$statusUrl}";
        } else {
            $text .= "Please check your email for details.";
        }

        return self::send($toNumber, $text);
    }

    /* ============================================================
       Diagnostics: which provider is active?
       ============================================================ */
    public static function provider(): string
    {
        $p = strtolower(trim(self::env('SMS_PROVIDER', 'stub')));
        return $p !== '' ? $p : 'stub';
    }

    /**
     * Quick check whether SMS sending is wired up.
     * Returns true if a non-stub provider is configured with credentials.
     */
    public static function isLive(): bool
    {
        $p = self::provider();
        if ($p === 'stub') return false;

        return match ($p) {
            'semaphore' => self::env('SMS_SEMAPHORE_API_KEY', '') !== '',
            'movider'   => self::env('SMS_MOVIDER_API_KEY', '') !== ''
                        && self::env('SMS_MOVIDER_API_SECRET', '') !== '',
            'twilio'    => self::env('SMS_TWILIO_SID', '') !== ''
                        && self::env('SMS_TWILIO_TOKEN', '') !== '',
            default     => false,
        };
    }
}