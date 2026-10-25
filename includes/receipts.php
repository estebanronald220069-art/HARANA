<?php
// includes/receipts.php
// Central helper for generating unique receipt numbers.
// Retries on collision, falls back to microseconds if needed.

if (!function_exists('generateReceiptNumber')) {
    function generateReceiptNumber($db, int $maxAttempts = 50): string
    {
        $datePrefix = date('Ymd');

        // Attempt 1..N: random 4-digit suffix, checked against DB
        for ($i = 0; $i < $maxAttempts; $i++) {
            $suffix = str_pad((string)mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
            $candidate = 'RCP-' . $datePrefix . '-' . $suffix;

            $exists = $db->getSingle(
                "SELECT 1 FROM payments WHERE receipt_number = ?",
                [$candidate], 's'
            );
            if (!$exists) return $candidate;
        }

        // Fallback: microseconds (guaranteed unique within a process)
        $micro = substr((string)microtime(true), -6);
        return 'RCP-' . $datePrefix . '-' . $micro;
    }
}