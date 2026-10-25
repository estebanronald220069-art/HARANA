<?php
// includes/mailer.php
// Gmail SMTP wrapper using PHPMailer.
// Credentials come from the .env file in the project root.

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

/* ============================================================
   Load Composer autoloader (PHPMailer + phpdotenv)
   ============================================================ */
$autoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
} else {
    error_log('Mailer: Composer autoloader not found. Run: composer require phpmailer/phpmailer vlucas/phpdotenv');
}

/* ============================================================
   Load .env (only once per request)
   ============================================================ */
if (class_exists(\Dotenv\Dotenv::class)) {
    static $env_loaded = false;
    if (!$env_loaded) {
        try {
            $dotenv = \Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
            $dotenv->safeLoad(); // safe = does nothing if .env missing
            $env_loaded = true;
        } catch (Throwable $e) {
            error_log('Mailer: .env load failed: ' . $e->getMessage());
        }
    }
}

class Mailer
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

    /**
     * Send an email via Gmail SMTP.
     *
     * @param string $toEmail   Recipient address
     * @param string $toName    Recipient name (optional)
     * @param string $subject   Subject line
     * @param string $htmlBody  HTML body
     * @param string $altBody   Plain-text fallback (optional)
     * @return bool             true on success, false on failure
     */
    public static function send(string $toEmail, string $toName, string $subject, string $htmlBody, string $altBody = ''): bool
    {
        if (!class_exists(PHPMailer::class)) {
            error_log('Mailer: PHPMailer class not loaded. Run: composer require phpmailer/phpmailer');
            return false;
        }

        $host      = self::env('MAIL_HOST', 'smtp.gmail.com');
        $port      = (int) self::env('MAIL_PORT', 587);
        $username  = self::env('MAIL_USERNAME', '');
        $password  = self::env('MAIL_PASSWORD', '');
        $fromEmail = self::env('MAIL_FROM_ADDRESS', $username);
        $fromName  = self::env('MAIL_FROM_NAME', 'Harana Financial System');
        $debug     = filter_var(self::env('MAIL_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN);

        // Sanity checks
        if ($username === '' || $password === '') {
            error_log('Mailer: MAIL_USERNAME / MAIL_PASSWORD not set in .env');
            return false;
        }

        // Auto-pick encryption based on port
        $encryption = ($port === 465)
            ? PHPMailer::ENCRYPTION_SMTPS    // SSL
            : PHPMailer::ENCRYPTION_STARTTLS; // TLS

        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host       = $host;
            $mail->SMTPAuth   = true;
            $mail->Username   = $username;
            $mail->Password   = $password;
            $mail->SMTPSecure = $encryption;
            $mail->Port       = $port;
            $mail->CharSet    = 'UTF-8';

            if ($debug) {
                $mail->SMTPDebug   = SMTP::DEBUG_SERVER;
                $mail->Debugoutput = function ($str, $level) {
                    error_log("SMTP[$level]: $str");
                };
            }

            $mail->setFrom($fromEmail, $fromName);
            if ($toName !== '') {
                $mail->addAddress($toEmail, $toName);
            } else {
                $mail->addAddress($toEmail);
            }
            $mail->addReplyTo($fromEmail, $fromName);

            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body    = $htmlBody;
            $mail->AltBody = $altBody !== '' ? $altBody : strip_tags($htmlBody);

            $mail->send();
            return true;

        } catch (Exception $e) {
            error_log('Mailer send failed: ' . $mail->ErrorInfo . ' | ' . $e->getMessage());
            return false;
        }
    }

    /* ============================================================
       Convenience wrapper — Email verification
       ============================================================ */
    public static function sendVerification(string $toEmail, string $firstName, string $token, string $baseUrl = ''): bool
    {
        if ($baseUrl === '') {
            $baseUrl = rtrim(self::env('APP_URL', ''), '/');
        }
        if ($baseUrl === '') {
            // Fallback to current request
            $scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $dir     = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/\\');
            $baseUrl = $scheme . '://' . $host . $dir;
        }

        $link    = rtrim($baseUrl, '/') . '/verify.php?token=' . urlencode($token);
        $subject = 'Verify your email — Harana Financial System';
        $safe    = htmlspecialchars($firstName !== '' ? $firstName : 'there', ENT_QUOTES);

        $html = '
<!DOCTYPE html>
<html>
<body style="font-family: Arial, sans-serif; background:#f4f7fc; padding:24px; margin:0;">
  <div style="max-width:560px; margin:0 auto; background:#ffffff; border-radius:12px; overflow:hidden; box-shadow:0 2px 8px rgba(0,0,0,.06);">
    <div style="background:linear-gradient(135deg,#375a7f,#2c4a6b); padding:24px; text-align:center; color:#ffffff;">
      <h2 style="margin:0; font-size:20px;">Harana Financial System</h2>
      <p style="margin:6px 0 0; opacity:.9; font-size:13px;">Email Verification</p>
    </div>
    <div style="padding:28px;">
      <p style="font-size:15px;">Hi ' . $safe . ',</p>
      <p style="font-size:15px;">Thank you for registering. Please confirm your email address by clicking the button below:</p>
      <p style="text-align:center; margin:32px 0;">
        <a href="' . $link . '" style="background:#375a7f; color:#fff; padding:12px 32px; border-radius:8px; text-decoration:none; font-weight:600; display:inline-block;">Verify My Email</a>
      </p>
      <p style="font-size:13px; color:#6c757d;">Or copy and paste this link into your browser:</p>
      <p style="font-size:12px; color:#6c757d; word-break:break-all;"><a href="' . $link . '" style="color:#375a7f;">' . $link . '</a></p>
      <hr style="border:none; border-top:1px solid #e9ecef; margin:24px 0;">
      <p style="font-size:12px; color:#8896a6;">This link expires in 48 hours. If you did not register, please ignore this email — no account will be created.</p>
    </div>
    <div style="background:#f8f9fc; padding:14px; text-align:center; color:#8896a6; font-size:11px;">
      © ' . date('Y') . ' Harana Financial System. All rights reserved.
    </div>
  </div>
</body>
</html>';

        $alt = "Hi $safe,\n\nThank you for registering. Please verify your email by visiting:\n$link\n\nThis link expires in 48 hours.";

        return self::send($toEmail, $firstName, $subject, $html, $alt);
    }

    /* ============================================================
       Convenience wrapper — Approval notification
       ============================================================ */
    public static function sendApproval(string $toEmail, string $firstName, string $username, string $loginUrl = ''): bool
    {
        if ($loginUrl === '') {
            $base = rtrim(self::env('APP_URL', ''), '/');
            if ($base === '') {
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $dir    = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/\\');
                $base   = $scheme . '://' . $host . $dir;
            }
            $loginUrl = $base . '/index.php';
        }

        $subject = 'Your membership has been approved — Harana';
        $safe    = htmlspecialchars($firstName !== '' ? $firstName : 'there', ENT_QUOTES);
        $user    = htmlspecialchars($username, ENT_QUOTES);

        $html = '
<!DOCTYPE html>
<html>
<body style="font-family: Arial, sans-serif; background:#f4f7fc; padding:24px; margin:0;">
  <div style="max-width:560px; margin:0 auto; background:#ffffff; border-radius:12px; overflow:hidden; box-shadow:0 2px 8px rgba(0,0,0,.06);">
    <div style="background:linear-gradient(135deg,#28a745,#1e7e34); padding:24px; text-align:center; color:#ffffff;">
      <h2 style="margin:0; font-size:20px;">Welcome to Harana!</h2>
    </div>
    <div style="padding:28px;">
      <p style="font-size:15px;">Hi ' . $safe . ',</p>
      <p style="font-size:15px;">Your membership application has been <strong>approved</strong>. You can now log in to your account.</p>
      <p style="font-size:15px;">Your username: <strong>' . $user . '</strong></p>
      <p style="text-align:center; margin:32px 0;">
        <a href="' . $loginUrl . '" style="background:#375a7f; color:#fff; padding:12px 32px; border-radius:8px; text-decoration:none; font-weight:600; display:inline-block;">Log In Now</a>
      </p>
      <p style="font-size:12px; color:#8896a6;">If you did not apply for membership, please contact the admin immediately.</p>
    </div>
    <div style="background:#f8f9fc; padding:14px; text-align:center; color:#8896a6; font-size:11px;">
      © ' . date('Y') . ' Harana Financial System. All rights reserved.
    </div>
  </div>
</body>
</html>';

        return self::send($toEmail, $firstName, $subject, $html);
    }

    /* ============================================================
       Convenience wrapper — Rejection notification
       ------------------------------------------------------------
       Sends a formatted rejection email to the applicant.

       @param string $toEmail        Recipient address
       @param string $toName         Recipient name (full name)
       @param string $reasonLabel    Short label, e.g. "Medical certificate missing"
       @param string $reasonMessage  Full message shown to the applicant
       @param string $customReason   Optional custom text (only used for "Others")
       @param string $statusUrl      Optional URL where applicant can check status
       @return bool
       ============================================================ */
    public static function sendRejection(
        string $toEmail,
        string $toName,
        string $reasonLabel,
        string $reasonMessage,
        string $customReason = '',
        string $statusUrl = ''
    ): bool {
        // ---- Default status URL ----------------------------------
        if ($statusUrl === '') {
            $base = rtrim(self::env('APP_URL', ''), '/');
            if ($base === '') {
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $dir    = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/\\');
                $base   = $scheme . '://' . $host . $dir;
            }
            $statusUrl = $base . '/application_status.php';
        }

        $subject     = 'Your membership application status — NHGL';
        $safeName    = htmlspecialchars($toName !== '' ? $toName : 'Applicant', ENT_QUOTES);
        $safeLabel   = htmlspecialchars($reasonLabel, ENT_QUOTES);
        $safeMessage = nl2br(htmlspecialchars($reasonMessage, ENT_QUOTES));
        $safeUrl     = htmlspecialchars($statusUrl, ENT_QUOTES);

        $customBlock = '';
        if (trim($customReason) !== '') {
            $safeCustom  = nl2br(htmlspecialchars($customReason, ENT_QUOTES));
            $customBlock = '
      <div style="margin-top:16px; padding:12px 16px; background:#fff8e1; border-left:4px solid #ffc107; border-radius:6px;">
        <strong style="color:#856404; font-size:13px;">Additional note from admin:</strong>
        <p style="margin:6px 0 0; font-size:14px; color:#4a4a4a;">' . $safeCustom . '</p>
      </div>';
        }

        $html = '
<!DOCTYPE html>
<html>
<body style="font-family: Arial, sans-serif; background:#f4f7fc; padding:24px; margin:0;">
  <div style="max-width:560px; margin:0 auto; background:#ffffff; border-radius:12px; overflow:hidden; box-shadow:0 2px 8px rgba(0,0,0,.06);">
    <div style="background:linear-gradient(135deg,#dc3545,#a71d2a); padding:24px; text-align:center; color:#ffffff;">
      <h2 style="margin:0; font-size:20px;">Membership Application Update</h2>
      <p style="margin:6px 0 0; opacity:.9; font-size:13px;">Nagkaisang Haranista sa Gintong Luzon, Inc.</p>
    </div>
    <div style="padding:28px;">
      <p style="font-size:15px;">Dear ' . $safeName . ',</p>

      <p style="font-size:15px;">
        Thank you for your interest in joining <strong>Nagkaisang Haranista sa Gintong Luzon, Inc. (NHGL, Inc.)</strong>.
      </p>

      <p style="font-size:15px;">
        After reviewing your application, we regret to inform you that we are <strong>unable to approve it at this time</strong>.
      </p>

      <div style="margin:20px 0; padding:16px; background:#fdecee; border-left:4px solid #dc3545; border-radius:6px;">
        <strong style="color:#842029; font-size:13px; text-transform:uppercase; letter-spacing:.4px;">Reason</strong>
        <p style="margin:6px 0 0; font-size:15px; font-weight:600; color:#842029;">' . $safeLabel . '</p>
        <p style="margin:8px 0 0; font-size:14px; color:#4a4a4a;">' . $safeMessage . '</p>
      </div>' . $customBlock . '

      <p style="font-size:14px; color:#4a4a4a;">
        You may review your application status anytime by visiting:
      </p>
      <p style="text-align:center; margin:24px 0;">
        <a href="' . $safeUrl . '" style="background:#1c1c1d; color:#fff; padding:12px 28px; border-radius:8px; text-decoration:none; font-weight:600; display:inline-block;">Check Application Status</a>
      </p>

      <p style="font-size:13px; color:#6c757d;">
        If you believe this decision was made in error, or if you would like to submit updated documents,
        please contact the admin office.
      </p>

      <hr style="border:none; border-top:1px solid #e9ecef; margin:24px 0;">
      <p style="font-size:12px; color:#8896a6; margin:0;">Sincerely,<br><strong>NHGL Admin Team</strong></p>
    </div>
    <div style="background:#f8f9fc; padding:14px; text-align:center; color:#8896a6; font-size:11px;">
      © ' . date('Y') . ' Nagkaisang Haranista sa Gintong Luzon, Inc. All rights reserved.
    </div>
  </div>
</body>
</html>';

        $alt = "Dear $safeName,\n\n"
             . "Thank you for your interest in joining NHGL, Inc.\n\n"
             . "After reviewing your application, we regret to inform you that we are unable to approve it at this time.\n\n"
             . "Reason: $reasonLabel\n"
             . strip_tags(str_replace('<br>', "\n", $safeMessage)) . "\n"
             . ($customReason !== '' ? "\nAdditional note: " . $customReason . "\n" : '')
             . "\nYou may check your application status at:\n$statusUrl\n\n"
             . "Sincerely,\nNHGL Admin Team";

        return self::send($toEmail, $toName, $subject, $html, $alt);
    }
}