<?php
// includes/rejection_reasons.php
// ============================================================
// Central list of predefined rejection reasons + helpers.
// Used by:
//   - admin/pending_users.php (rejection modal)
//   - admin/rejections_list.php (filter dropdown)
//   - includes/notifier.php (validation before sending)
//   - application_status.php (public status check)
// ============================================================

class RejectionReasons
{
    /**
     * Master list of predefined reasons.
     *
     * Structure:
     *   'code'    => unique key stored in DB
     *   'label'   => short label shown to admin
     *   'group'   => grouping in the modal UI
     *   'message' => message shown/sent to the applicant
     *
     * The special code 'other' lets the admin type a custom
     * message; its 'message' here is only a fallback.
     */
    private static array $reasons = [
        // ---------- Documents ----------
        'medical_missing' => [
            'label'   => 'Medical certificate missing',
            'group'   => 'Documents',
            'message' => 'Your medical certificate was not attached or is missing. '
                       . 'Please upload a valid medical certificate and resubmit your application.',
        ],
        'medical_invalid' => [
            'label'   => 'Medical certificate invalid/expired',
            'group'   => 'Documents',
            'message' => 'Your medical certificate appears to be invalid or expired. '
                       . 'Please submit a current medical certificate from a licensed physician.',
        ],
        'birth_cert_missing' => [
            'label'   => 'Birth certificate missing',
            'group'   => 'Documents',
            'message' => 'Your birth certificate was not attached. '
                       . 'Please upload a PSA/NSO-issued birth certificate and resubmit.',
        ],
        'birth_cert_unreadable' => [
            'label'   => 'Birth certificate unreadable',
            'group'   => 'Documents',
            'message' => 'Your birth certificate image is unclear or unreadable. '
                       . 'Please upload a clear, well-lit scan or photo.',
        ],
        'id_photo_missing' => [
            'label'   => 'ID picture missing',
            'group'   => 'Documents',
            'message' => 'Your 2x2 ID picture was not attached. '
                       . 'Please upload a clear photo and resubmit your application.',
        ],
        'id_photo_unclear' => [
            'label'   => 'ID picture unclear',
            'group'   => 'Documents',
            'message' => 'Your ID picture is blurry or unclear. '
                       . 'Please upload a clearer photo with a plain background.',
        ],

        // ---------- Account / Eligibility ----------
        'duplicate_account' => [
            'label'   => 'Duplicate account',
            'group'   => 'Account / Eligibility',
            'message' => 'An account already exists with the same name, email, or member code. '
                       . 'Please contact the admin office if you believe this is an error.',
        ],
        'incomplete_info' => [
            'label'   => 'Incomplete information',
            'group'   => 'Account / Eligibility',
            'message' => 'Your application is missing required information. '
                       . 'Please review the form and resubmit with complete details.',
        ],
        'invalid_contact' => [
            'label'   => 'Invalid contact details',
            'group'   => 'Account / Eligibility',
            'message' => 'The contact number or email you provided is invalid or unreachable. '
                       . 'Please provide a working contact and resubmit.',
        ],
        'underage' => [
            'label'   => 'Applicant under minimum age',
            'group'   => 'Account / Eligibility',
            'message' => 'You do not meet the minimum age requirement for membership. '
                       . 'Please contact the admin office for details.',
        ],
        'not_eligible' => [
            'label'   => 'Not eligible for membership',
            'group'   => 'Account / Eligibility',
            'message' => 'Based on our current membership criteria, your application cannot be approved at this time. '
                       . 'If you have questions, please contact the admin office.',
        ],

        // ---------- Other ----------
        'other' => [
            'label'   => 'Others (custom reason)',
            'group'   => 'Other',
            'message' => 'Your application was not approved. Please see the reason provided by the admin below.',
        ],
    ];

    /**
     * Return the full reason map (code => [label, group, message]).
     */
    public static function all(): array
    {
        return self::$reasons;
    }

    /**
     * Return a single reason row, or null if not found.
     */
    public static function get(string $code): ?array
    {
        return self::$reasons[$code] ?? null;
    }

    /**
     * Return true if the code is a valid predefined reason.
     */
    public static function isValid(string $code): bool
    {
        return isset(self::$reasons[$code]);
    }

    /**
     * Return the short label for a code (falls back to the raw code).
     */
    public static function label(string $code): string
    {
        return self::$reasons[$code]['label'] ?? $code;
    }

    /**
     * Return the group name for a code.
     */
    public static function group(string $code): string
    {
        return self::$reasons[$code]['group'] ?? 'Other';
    }

    /**
     * Build the final message that will be shown to the applicant.
     *
     * For 'other', the custom text takes precedence and the
     * predefined message is discarded.
     */
    public static function buildMessage(string $code, string $customReason = ''): string
    {
        $row = self::get($code);

        if ($code === 'other') {
            $customReason = trim($customReason);
            if ($customReason !== '') {
                return $customReason;
            }
            return $row['message']
                ?? 'Your application was not approved. Please contact the admin office for details.';
        }

        return $row['message']
            ?? 'Your application was not approved. Please contact the admin office for details.';
    }

    /**
     * Group the reasons for the admin modal UI.
     *
     * Returns: [ 'Documents' => [code => row, ...], ... ]
     */
    public static function grouped(): array
    {
        $grouped = [];
        foreach (self::$reasons as $code => $row) {
            $grouped[$row['group']][$code] = $row;
        }
        return $grouped;
    }

    /**
     * Sanitize + validate a submitted reason.
     */
    public static function validate(string $code, string $customReason): array
    {
        $code = trim($code);
        $customReason = trim($customReason);

        if ($code === '' || !self::isValid($code)) {
            return [
                'ok'    => false,
                'error' => 'Please choose a valid rejection reason.',
            ];
        }

        if ($code === 'other') {
            if ($customReason === '') {
                return [
                    'ok'    => false,
                    'error' => 'Please type a custom reason when "Others" is selected.',
                ];
            }
            if (mb_strlen($customReason) > 500) {
                return [
                    'ok'    => false,
                    'error' => 'Custom reason must be 500 characters or fewer.',
                ];
            }
        }

        return [
            'ok'             => true,
            'reason_code'    => $code,
            'reason_label'   => self::label($code),
            'custom_reason'  => $code === 'other' ? $customReason : '',
            'reason_message' => self::buildMessage($code, $customReason),
        ];
    }
}