<?php
// includes/rejection_reasons_photos.php
// ============================================================
// Predefined rejection reasons for MEMBER PHOTO requests.
// Follows the same structure as rejection_reasons.php.
// Used by:
//   - admin/pending_photos.php (rejection modal)
// ============================================================

class PhotoRejectionReasons
{
    private static array $reasons = [
        // ---------- Photo quality ----------
        'photo_blurry' => [
            'label'   => 'Photo is blurry or unclear',
            'group'   => 'Photo Quality',
            'message' => 'The photo you submitted is blurry or unclear. '
                       . 'Please upload a well-lit, sharp photo of your face.',
        ],
        'photo_low_resolution' => [
            'label'   => 'Photo resolution too low',
            'group'   => 'Photo Quality',
            'message' => 'The photo resolution is too low. '
                       . 'Please upload a higher-quality image (at least 600×600 pixels).',
        ],
        'photo_bad_lighting' => [
            'label'   => 'Poor lighting',
            'group'   => 'Photo Quality',
            'message' => 'The photo was taken in poor lighting. '
                       . 'Please take a new photo in a well-lit area.',
        ],
        'photo_dark_or_overexposed' => [
            'label'   => 'Photo is too dark or overexposed',
            'group'   => 'Photo Quality',
            'message' => 'The photo is either too dark or overexposed. '
                       . 'Please take a balanced photo with natural or even lighting.',
        ],

        // ---------- Photo content ----------
        'photo_not_face' => [
            'label'   => 'Face not clearly visible',
            'group'   => 'Photo Content',
            'message' => 'Your face is not clearly visible in the photo. '
                       . 'Please upload a clear front-facing photo.',
        ],
        'photo_wrong_subject' => [
            'label'   => 'Photo is not a proper portrait',
            'group'   => 'Photo Content',
            'message' => 'The image you submitted is not a proper portrait/ID photo. '
                       . 'Please upload a clear photo of yourself only.',
        ],
        'photo_has_filter' => [
            'label'   => 'Photo has filters or is edited',
            'group'   => 'Photo Content',
            'message' => 'The photo appears to have filters or has been edited. '
                       . 'Please upload a natural, unedited photo.',
        ],
        'photo_wrong_orientation' => [
            'label'   => 'Photo is rotated or sideways',
            'group'   => 'Photo Content',
            'message' => 'The photo is rotated or sideways. '
                       . 'Please upload the photo in the correct upright orientation.',
        ],
        'photo_background_bad' => [
            'label'   => 'Background is not acceptable',
            'group'   => 'Photo Content',
            'message' => 'The background of your photo is not acceptable. '
                       . 'Please use a plain, neutral background (white or light-colored).',
        ],

        // ---------- Compliance ----------
        'photo_not_recent' => [
            'label'   => 'Photo does not look recent',
            'group'   => 'Compliance',
            'message' => 'The photo does not appear to be recent. '
                       . 'Please upload a recent photo (taken within the last 6 months).',
        ],
        'photo_inappropriate' => [
            'label'   => 'Inappropriate image',
            'group'   => 'Compliance',
            'message' => 'The image you submitted is not appropriate for a member profile. '
                       . 'Please upload a proper ID-style photo.',
        ],
        'photo_duplicate' => [
            'label'   => 'Same photo already on file',
            'group'   => 'Compliance',
            'message' => 'This photo is the same as or too similar to your current profile picture. '
                       . 'Please upload a new photo if you wish to update it.',
        ],

        // ---------- Other ----------
        'other' => [
            'label'   => 'Others (custom reason)',
            'group'   => 'Other',
            'message' => 'Your photo change request was not approved. '
                       . 'Please see the reason provided by the admin below.',
        ],
    ];

    public static function all(): array { return self::$reasons; }
    public static function get(string $code): ?array { return self::$reasons[$code] ?? null; }
    public static function isValid(string $code): bool { return isset(self::$reasons[$code]); }
    public static function label(string $code): string { return self::$reasons[$code]['label'] ?? $code; }
    public static function group(string $code): string { return self::$reasons[$code]['group'] ?? 'Other'; }

    public static function buildMessage(string $code, string $customReason = ''): string
    {
        $row = self::get($code);
        if ($code === 'other') {
            $customReason = trim($customReason);
            if ($customReason !== '') return $customReason;
            return $row['message'] ?? 'Your photo change request was not approved.';
        }
        return $row['message'] ?? 'Your photo change request was not approved.';
    }

    public static function grouped(): array
    {
        $grouped = [];
        foreach (self::$reasons as $code => $row) {
            $grouped[$row['group']][$code] = $row;
        }
        return $grouped;
    }

    public static function validate(string $code, string $customReason): array
    {
        $code = trim($code);
        $customReason = trim($customReason);

        if ($code === '' || !self::isValid($code)) {
            return ['ok' => false, 'error' => 'Please choose a valid rejection reason.'];
        }
        if ($code === 'other') {
            if ($customReason === '') {
                return ['ok' => false, 'error' => 'Please type a custom reason when "Others" is selected.'];
            }
            if (mb_strlen($customReason) > 500) {
                return ['ok' => false, 'error' => 'Custom reason must be 500 characters or fewer.'];
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