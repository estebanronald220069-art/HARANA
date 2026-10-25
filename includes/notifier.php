<?php
// includes/notifier.php
// ============================================================
// Unified notification dispatcher.
//
// Currently used for:  rejection notices to pending applicants.
//
// Order of operations:
//   1. Insert row into `applicant_notifications`  (in-app)
//   2. Mailer::sendRejection()                     (email)
//   3. Sms::sendRejection()                        (SMS)
//
// Any channel failing does NOT abort the others.
// A full per-channel status array is returned so the admin UI
// can display exactly what succeeded and what didn't.
// ============================================================

require_once __DIR__ . '/db_connection.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/sms.php';

class Notifier
{
    /**
     * Send a rejection notice to an applicant via all channels.
     *
     * @param array $pending       Row from `pending_users` (must contain: first_name, last_name, email, contact_number, username, member_code)
     * @param array $rejection     Output of RejectionReasons::validate() (must contain: reason_code, reason_label, reason_message, custom_reason)
     * @param array $meta          Optional:
     *                             - status_url  (string)  URL for the status check page
     *                             - channels    (array)   e.g. ['notification','email','sms'] (default: all)
     *
     * @return array [
     *     'overall'       => 'sent'|'partial'|'failed',
     *     'notification'  => ['success'=>bool, 'id'=>int|null, 'error'=>string|null, 'status'=>'sent'|'failed'|'skipped'],
     *     'email'         => ['success'=>bool, 'message_id'=>string|null, 'error'=>string|null, 'status'=>'sent'|'failed'|'skipped'],
     *     'sms'           => ['success'=>bool, 'message_id'=>string|null, 'error'=>string|null, 'status'=>'sent'|'failed'|'skipped'],
     *     'delivery_log'  => string,   // human-readable summary for storage
     * ]
     */
    public static function sendRejection(array $pending, array $rejection, array $meta = []): array
    {
        $statusUrl = $meta['status_url'] ?? self::buildStatusUrl();
        $channels  = $meta['channels']   ?? ['notification', 'email', 'sms'];

        // ---- Extract applicant info ------------------------------
        $firstName   = trim((string)($pending['first_name'] ?? ''));
        $lastName    = trim((string)($pending['last_name']  ?? ''));
        $fullName    = trim($firstName . ' ' . $lastName);
        if ($fullName === '') $fullName = 'Applicant';

        $email       = trim((string)($pending['email']          ?? ''));
        $contact     = trim((string)($pending['contact_number'] ?? ''));
        $username    = trim((string)($pending['username']       ?? ''));
        $memberCode  = trim((string)($pending['member_code']    ?? ''));

        // ---- Extract rejection info ------------------------------
        $reasonCode    = (string)($rejection['reason_code']    ?? '');
        $reasonLabel   = (string)($rejection['reason_label']   ?? '');
        $reasonMessage = (string)($rejection['reason_message'] ?? '');
        $customReason  = (string)($rejection['custom_reason']  ?? '');

        $result = [
            'overall'      => 'failed',
            'notification' => ['success' => false, 'id' => null, 'error' => null, 'status' => 'skipped'],
            'email'        => ['success' => false, 'message_id' => null, 'error' => null, 'status' => 'skipped'],
            'sms'          => ['success' => false, 'message_id' => null, 'error' => null, 'status' => 'skipped'],
            'delivery_log' => '',
        ];

        $log = [];

        // ============================================================
        // 1. IN-APP NOTIFICATION (applicant_notifications table)
        // ============================================================
        if (in_array('notification', $channels, true)) {
            try {
                $db = Database::getInstance();

                $title   = 'Membership Application — Action Required';
                $message = "Your application was not approved. Reason: {$reasonLabel}. "
                         . "Please check your email for full details and next steps.";

                $sql = "INSERT INTO applicant_notifications
                          (email, username, type, title, message, related_id, is_read, created_at)
                        VALUES (?, ?, 'rejection', ?, ?, NULL, 0, NOW())";

                $ok = $db->execute($sql, [$email, $username, $title, $message], 'ssss');

                if ($ok) {
                    $newId = $db->getConnection()->insert_id;
                    $result['notification'] = [
                        'success' => true,
                        'id'      => (int)$newId,
                        'error'   => null,
                        'status'  => 'sent',
                    ];
                    $log[] = "In-app notification queued (id={$newId})";
                } else {
                    $result['notification'] = [
                        'success' => false,
                        'id'      => null,
                        'error'   => 'DB insert failed',
                        'status'  => 'failed',
                    ];
                    $log[] = "In-app notification FAILED (DB insert)";
                }
            } catch (Throwable $e) {
                $result['notification'] = [
                    'success' => false,
                    'id'      => null,
                    'error'   => $e->getMessage(),
                    'status'  => 'failed',
                ];
                $log[] = "In-app notification EXCEPTION: " . $e->getMessage();
            }
        } else {
            $log[] = "In-app notification skipped (channel disabled)";
        }

        // ============================================================
        // 2. EMAIL (Mailer::sendRejection)
        // ============================================================
        if (in_array('email', $channels, true)) {
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $result['email'] = [
                    'success'    => false,
                    'message_id' => null,
                    'error'      => 'Invalid or missing email address',
                    'status'     => 'failed',
                ];
                $log[] = "Email SKIPPED: applicant email is empty or invalid";
            } else {
                try {
                    $ok = Mailer::sendRejection(
                        $email,
                        $fullName,
                        $reasonLabel,
                        $reasonMessage,
                        $customReason,
                        $statusUrl
                    );

                    if ($ok) {
                        $result['email'] = [
                            'success'    => true,
                            'message_id' => null,
                            'error'      => null,
                            'status'     => 'sent',
                        ];
                        $log[] = "Email sent to {$email}";
                    } else {
                        $result['email'] = [
                            'success'    => false,
                            'message_id' => null,
                            'error'      => 'Mailer::sendRejection returned false',
                            'status'     => 'failed',
                        ];
                        $log[] = "Email FAILED to {$email} — see error_log";
                    }
                } catch (Throwable $e) {
                    $result['email'] = [
                        'success'    => false,
                        'message_id' => null,
                        'error'      => $e->getMessage(),
                        'status'     => 'failed',
                    ];
                    $log[] = "Email EXCEPTION: " . $e->getMessage();
                }
            }
        } else {
            $log[] = "Email skipped (channel disabled)";
        }

        // ============================================================
        // 3. SMS (Sms::sendRejection)
        // ============================================================
        if (in_array('sms', $channels, true)) {
            if ($contact === '') {
                $result['sms'] = [
                    'success'    => false,
                    'message_id' => null,
                    'error'      => 'Missing contact number',
                    'status'     => 'failed',
                ];
                $log[] = "SMS SKIPPED: applicant contact number is empty";
            } else {
                try {
                    $smsRes = Sms::sendRejection(
                        $contact,
                        $firstName !== '' ? $firstName : 'Applicant',
                        $reasonLabel,
                        $statusUrl
                    );

                    if (!empty($smsRes['success'])) {
                        $result['sms'] = [
                            'success'    => true,
                            'message_id' => $smsRes['message_id'] ?? null,
                            'error'      => null,
                            'status'     => 'sent',
                        ];
                        $log[] = "SMS sent to {$contact} (provider=" . ($smsRes['provider'] ?? '?') . ")";
                    } else {
                        $result['sms'] = [
                            'success'    => false,
                            'message_id' => $smsRes['message_id'] ?? null,
                            'error'      => $smsRes['error'] ?? 'Unknown SMS error',
                            'status'     => 'failed',
                        ];
                        $log[] = "SMS FAILED to {$contact}: " . ($smsRes['error'] ?? 'unknown');
                    }
                } catch (Throwable $e) {
                    $result['sms'] = [
                        'success'    => false,
                        'message_id' => null,
                        'error'      => $e->getMessage(),
                        'status'     => 'failed',
                    ];
                    $log[] = "SMS EXCEPTION: " . $e->getMessage();
                }
            }
        } else {
            $log[] = "SMS skipped (channel disabled)";
        }

        // ============================================================
        // 4. OVERALL STATUS
        // ============================================================
        $statuses = [
            $result['notification']['status'],
            $result['email']['status'],
            $result['sms']['status'],
        ];
        // Count only channels that were actually attempted
        $attempted = array_filter($statuses, fn($s) => $s !== 'skipped');
        $sent      = array_filter($attempted, fn($s) => $s === 'sent');

        if (empty($attempted)) {
            $result['overall'] = 'failed';
        } elseif (count($sent) === count($attempted)) {
            $result['overall'] = 'sent';
        } elseif (count($sent) > 0) {
            $result['overall'] = 'partial';
        } else {
            $result['overall'] = 'failed';
        }

        $result['delivery_log'] = implode(' | ', $log);

        return $result;
    }

    /**
     * Build the public status-check URL using APP_URL if set.
     */
    public static function buildStatusUrl(): string
    {
        $envFile = __DIR__ . '/../.env';
        $appUrl  = '';

        // Prefer $_ENV if already loaded
        if (!empty($_ENV['APP_URL'])) {
            $appUrl = $_ENV['APP_URL'];
        } elseif (!empty($_SERVER['APP_URL'])) {
            $appUrl = $_SERVER['APP_URL'];
        } else {
            $v = getenv('APP_URL');
            if ($v !== false && $v !== '') $appUrl = $v;
        }

        if ($appUrl === '') {
            // Fallback: auto-detect from current request
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $dir    = rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/\\');
            $appUrl = $scheme . '://' . $host . $dir;
        }

        return rtrim($appUrl, '/') . '/application_status.php';
    }
}