<?php
// includes/benefit_rules.php
// ============================================================
// Centralized Benefit Computation Engine
// Harana Financial System
//
// This is the SINGLE SOURCE OF TRUTH for:
//   - Base death benefit (by years of membership)
//   - Good Payer bonus
//   - Accidental death bonus
//   - Deductions for missed payments
//   - Probation periods
// ============================================================

class BenefitRules
{
    /* ============================================================
       DEATH BENEFIT TABLE — by years of membership
       (must match the table in rules & regulations)
    ============================================================ */
    private static $death_benefit_table = [
        0  => 0,
        1  => 80000,
        2  => 80000,
        3  => 90000,
        4  => 90000,
        5  => 100000,
        6  => 100000,
        7  => 110000,
        8  => 110000,
        9  => 115000,
        10 => 120000,
        11 => 125000,
        12 => 130000,
        13 => 135000,
        14 => 140000,
        15 => 145000,
        16 => 150000,
    ];

    /* ============================================================
       CONSTANTS
    ============================================================ */
    const ACCIDENT_BONUS     = 10000; // ₱10,000 extra if death by accident
    const GOOD_PAYER_BONUS   = 5000;  // ₱5,000 extra if 5+ yrs no misses
    const MAX_YEARS_FOR_TABLE = 16;   // Table caps at 16 years

    /* ============================================================
       BASE BENEFIT (by years)
    ============================================================ */
    public static function getBaseBenefit(int $years): float
    {
        if ($years >= self::MAX_YEARS_FOR_TABLE) {
            return 150000.0;
        }
        if ($years >= 1 && $years <= self::MAX_YEARS_FOR_TABLE) {
            return (float) self::$death_benefit_table[$years];
        }
        return 0.0;
    }

    /* ============================================================
       YEARS / MONTHS AS MEMBER
    ============================================================ */
    public static function getYearsAsMember(?string $date_joined): int
    {
        if (empty($date_joined) || $date_joined === '0000-00-00') {
            return 0;
        }
        try {
            $joined = new DateTime($date_joined);
            $now    = new DateTime();
            if ($joined > $now) return 0;
            return (int) $joined->diff($now)->y;
        } catch (Exception $e) {
            return 0;
        }
    }

    public static function getMonthsAsMember(?string $date_joined): int
    {
        if (empty($date_joined) || $date_joined === '0000-00-00') {
            return 0;
        }
        try {
            $joined = new DateTime($date_joined);
            $now    = new DateTime();
            if ($joined > $now) return 0;
            $diff = $joined->diff($now);
            return ($diff->y * 12) + $diff->m;
        } catch (Exception $e) {
            return 0;
        }
    }

    /* ============================================================
       MISSED PAYMENTS
       Computes which months are unpaid since date_joined.
    ============================================================ */
    public static function getMissedPayments($db, string $member_code, ?string $date_joined): array
    {
        $empty = [
            'missed'   => 0,
            'expected' => 0,
            'paid'     => 0,
            'list'     => [],
        ];

        if (empty($date_joined) || $date_joined === '0000-00-00') {
            return $empty;
        }

        try {
            $joined = new DateTime($date_joined);
        } catch (Exception $e) {
            return $empty;
        }

        $joined->modify('first day of this month');
        $now = new DateTime();
        $now->modify('first day of this month');

        // All confirmed payment months
        $rows = $db->getAll(
            "SELECT DISTINCT DATE_FORMAT(payment_date, '%Y-%m') AS ym
             FROM payments
             WHERE member_id = ? AND payment_status = 'confirmed'",
            [$member_code],
            's'
        );

        $paid_set = [];
        foreach ($rows as $r) {
            if (!empty($r['ym'])) {
                $paid_set[$r['ym']] = true;
            }
        }

        $missed     = [];
        $expected   = 0;
        $paid_count = 0;
        $cursor     = clone $joined;

        while ($cursor <= $now) {
            $ym = $cursor->format('Y-m');
            $expected++;

            if (isset($paid_set[$ym])) {
                $paid_count++;
            } else {
                $missed[] = [
                    'ym'    => $ym,
                    'label' => $cursor->format('F Y'),
                ];
            }

            $cursor->modify('+1 month');
        }

        return [
            'missed'   => count($missed),
            'expected' => $expected,
            'paid'     => $paid_count,
            'list'     => $missed,
        ];
    }

    /* ============================================================
       GOOD PAYER CHECK
       5+ years as member AND no more than 6 missed payments
       in the last 5 years.
    ============================================================ */
    public static function isGoodPayer($db, string $member_code): bool
    {
        $member = $db->getSingle(
            "SELECT date_joined FROM members WHERE member_code = ?",
            [$member_code],
            's'
        );

        if (!$member || empty($member['date_joined'])) {
            return false;
        }

        $years = self::getYearsAsMember($member['date_joined']);
        if ($years < 5) {
            return false;
        }

        // Look at the last 5 years (60 months)
        $five_years_ago = (new DateTime())->modify('-5 years')->format('Y-m-d');

        $result = $db->getSingle(
            "SELECT COUNT(DISTINCT DATE_FORMAT(payment_date, '%Y-%m')) AS months_paid
             FROM payments
             WHERE member_id = ?
               AND payment_status = 'confirmed'
               AND payment_date >= ?",
            [$member_code, $five_years_ago],
            'ss'
        );

        $months_paid = (int) ($result['months_paid'] ?? 0);

        // Allow up to 6 months grace → 54 of 60
        return $months_paid >= 54;
    }

    /* ============================================================
       PROBATION PERIOD
       Based on missed payments thresholds.
    ============================================================ */
    public static function getProbationPeriod(int $missed_payments): array
    {
        if ($missed_payments >= 150) {
            return [
                'months' => null,
                'status' => 'removal',
                'label'  => 'May be removed from list',
                'class'  => 'danger',
            ];
        }
        if ($missed_payments >= 101) {
            return [
                'months' => 2,
                'status' => 'probation',
                'label'  => '2 months probation',
                'class'  => 'warning',
            ];
        }
        if ($missed_payments >= 51) {
            return [
                'months' => 1,
                'status' => 'probation',
                'label'  => '1 month probation',
                'class'  => 'warning',
            ];
        }
        return [
            'months' => 0,
            'status' => 'good',
            'label'  => 'No probation',
            'class'  => 'success',
        ];
    }

    /* ============================================================
       DEATH BENEFIT (full computation)
       ============================================================ */
    public static function computeDeathBenefit(
        int $years_as_member,
        int $missed_payments = 0,
        bool $is_accidental = false,
        bool $is_good_payer = false
    ): array {
        $base             = self::getBaseBenefit($years_as_member);
        $accident_bonus   = $is_accidental ? self::ACCIDENT_BONUS : 0;
        $good_payer_bonus = $is_good_payer ? self::GOOD_PAYER_BONUS : 0;

        // ---- 71+ missed: donation case
        if ($missed_payments >= 71) {
            return [
                'base'              => $base,
                'accident_bonus'    => $accident_bonus,
                'good_payer_bonus'  => $good_payer_bonus,
                'subtotal'          => $base + $accident_bonus + $good_payer_bonus,
                'deduction'         => $base,
                'deduction_reason'  => 'Donation based on membership duration',
                'total'             => 0,
                'is_donation'       => true,
                'donation_amount'   => round($base * 0.5, 2),
                'years'             => $years_as_member,
                'missed'            => $missed_payments,
                'status'            => 'donation',
            ];
        }

        // ---- Deduction by bracket
        $deduction        = 0;
        $deduction_reason = '';

        if ($missed_payments >= 51) {
            $deduction        = $base * 0.5;
            $deduction_reason = 'Half of benefit (51-70 missed payments)';
        } elseif ($missed_payments >= 26) {
            $deduction        = 10000;
            $deduction_reason = '₱10,000 deduction (26-50 missed)';
        } elseif ($missed_payments >= 11) {
            $deduction        = 5000;
            $deduction_reason = '₱5,000 deduction (11-25 missed)';
        } elseif ($missed_payments >= 1) {
            $deduction        = 2500;
            $deduction_reason = '₱2,500 deduction (1-10 missed)';
        }

        $subtotal = $base + $accident_bonus + $good_payer_bonus;
        $total    = max(0, $subtotal - $deduction);

        return [
            'base'              => $base,
            'accident_bonus'    => $accident_bonus,
            'good_payer_bonus'  => $good_payer_bonus,
            'subtotal'          => $subtotal,
            'deduction'         => $deduction,
            'deduction_reason'  => $deduction_reason,
            'total'             => $total,
            'is_donation'       => false,
            'donation_amount'   => 0,
            'years'             => $years_as_member,
            'missed'            => $missed_payments,
            'status'            => $missed_payments > 0 ? 'adjusted' : 'clean',
        ];
    }

    /* ============================================================
       FULL MEMBER BENEFIT SUMMARY
       Single call that returns everything a dashboard needs.
    ============================================================ */
    public static function getMemberBenefitSummary($db, string $member_code): array
    {
        $member = $db->getSingle(
            "SELECT member_code, first_name, last_name, date_joined,
                    monthly_contribution, status
             FROM members
             WHERE member_code = ?",
            [$member_code],
            's'
        );

        if (!$member) {
            return [];
        }

        $years  = self::getYearsAsMember($member['date_joined']);
        $months = self::getMonthsAsMember($member['date_joined']);

        $missed       = self::getMissedPayments($db, $member_code, $member['date_joined']);
        $is_good_payer = self::isGoodPayer($db, $member_code);
        $probation    = self::getProbationPeriod($missed['missed']);

        // Base computation — no accident flag (that's claim-time only)
        $benefit = self::computeDeathBenefit(
            $years,
            $missed['missed'],
            false,
            $is_good_payer
        );

        $accident_max = $benefit['is_donation']
            ? $benefit['donation_amount']
            : $benefit['total'] + self::ACCIDENT_BONUS;

        return [
            // Member identity
            'member_code'        => $member['member_code'],
            'member_name'        => trim(
                ($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? '')
            ),
            'date_joined'        => $member['date_joined'],

            // Tenure
            'years_as_member'    => $years,
            'months_as_member'   => $months,

            // Payment health
            'missed_payments'    => $missed['missed'],
            'expected_payments'  => $missed['expected'],
            'paid_payments'      => $missed['paid'],
            'missed_list'        => $missed['list'],

            // Good payer
            'is_good_payer'      => $is_good_payer,
            'good_payer_bonus'   => $is_good_payer ? self::GOOD_PAYER_BONUS : 0,

            // Benefit breakdown
            'base_benefit'       => $benefit['base'],
            'deduction'          => $benefit['deduction'],
            'deduction_reason'   => $benefit['deduction_reason'],
            'total_benefit'      => $benefit['total'],
            'is_donation'        => $benefit['is_donation'],
            'donation_amount'    => $benefit['donation_amount'],

            // Accident
            'accident_bonus'     => self::ACCIDENT_BONUS,
            'accident_max_benefit' => $accident_max,

            // Probation
            'probation'          => $probation,

            // Overall status
            'status'             => $benefit['status'],
        ];
    }
}