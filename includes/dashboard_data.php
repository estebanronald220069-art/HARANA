<?php
/**
 * includes/dashboard_data.php
 *
 * Single source of truth for the member dashboard.
 * Everything is computed live from `payments` — never from `member_balances`.
 *
 * Data Integrity Refactor:
 *   • All queries strictly use `member_code`.
 *   • Calculations match the dashboard override exactly, so the numbers
 *     the user sees are always the numbers the function computed.
 */

require_once __DIR__ . '/benefit_rules.php';
require_once __DIR__ . '/db_connection.php';

if (!function_exists('getMemberDashboard')) {

    /**
     * @param Database $db
     * @param string   $member_code
     * @return array
     */
    function getMemberDashboard(Database $db, string $member_code): array
    {
        // ---- 1. Member row ----
        $member = $db->getSingle(
            "SELECT member_code, first_name, last_name, date_joined, status,
                    monthly_contribution, chapter, group_name
             FROM members
             WHERE member_code = ?
             LIMIT 1",
            [$member_code],
            's'
        );

        if (!$member) {
            return ['member' => [], 'error' => 'member_not_found'];
        }

        $monthly = (float)($member['monthly_contribution'] ?? 100);
        if ($monthly <= 0) $monthly = 100;
        $joined  = $member['date_joined'] ?: date('Y-m-d');

        // ---- 2. Settings: which day is "due day"? ----
        $due_day = 15;
        try {
            $row = $db->getSingle(
                "SELECT setting_value FROM settings
                 WHERE setting_key = 'payment_due_day' LIMIT 1"
            );
            if ($row && (int)$row['setting_value'] > 0) {
                $due_day = min(28, (int)$row['setting_value']);
            }
        } catch (Throwable $e) {
            // keep default 15
        }

        // ---- 3. Confirmed payments (period-aware, member_code strict) ----
        $confirmed = $db->getAll(
            "SELECT payment_id, amount, payment_date, payment_method,
                    COALESCE(period_month, DATE_FORMAT(payment_date,'%Y-%m')) AS period,
                    receipt_number, notes, gcash_reference
             FROM payments
             WHERE member_code = ? AND payment_status = 'confirmed'
             ORDER BY payment_date ASC, payment_id ASC",
            [$member_code],
            's'
        );

        $paid_periods = [];
        $total_paid   = 0.0;
        foreach ($confirmed as $p) {
            $paid_periods[$p['period']] = true;
            $total_paid += (float)$p['amount'];
        }

        // ---- 4. Pending GCash uploads (member_code strict) ----
        $pending = [];
        try {
            $pending = $db->getAll(
                "SELECT payment_id, amount, payment_date, gcash_reference,
                        receipt_number, proof_of_payment
                 FROM payments
                 WHERE member_code = ? AND payment_status = 'pending'
                 ORDER BY payment_date DESC, payment_id DESC
                 LIMIT 5",
                [$member_code],
                's'
            );
        } catch (Throwable $e) {
            // member_code column may not exist yet on first run
            $pending = [];
        }

        // ---- 5. Build the month-by-month timeline ----
        // From the member's join month through the current month.
        $periods = [];
        $cursor  = new DateTime(date('Y-m-01', strtotime($joined)));
        $now     = new DateTime(date('Y-m-01'));
        $guard   = 0;
        while ($cursor <= $now && $guard < 600) {
            $periods[] = $cursor->format('Y-m');
            $cursor->modify('+1 month');
            $guard++;
        }
        if (empty($periods)) {
            $periods[] = date('Y-m');
        }

        // ---- 6. Classify each past/current month ----
        $current_ym      = date('Y-m');
        $months_paid     = 0;
        $months_expected = count($periods);
        $missed_periods  = [];

        foreach ($periods as $p) {
            if (isset($paid_periods[$p])) {
                $months_paid++;
            } elseif ($p < $current_ym) {
                // Past month, no payment = missed
                $missed_periods[] = $p;
            }
            // Current month unpaid = not yet missed, just not paid
        }

        $months_missing = count($missed_periods);

        // ---- 7. Advance: are there ANY future periods already paid? ----
        // Count consecutive paid months starting from NEXT month.
        $advance_months = 0;
        $advance_until  = null;
        $future         = clone $now;
        $future->modify('+1 month');
        for ($i = 0; $i < 24; $i++) { // look up to 24 months ahead
            $fp = $future->format('Y-m');
            if (isset($paid_periods[$fp])) {
                $advance_months++;
                $advance_until = $fp;
                $future->modify('+1 month');
            } else {
                break;
            }
        }

        // ---- 8. Next due = first month without a confirmed payment ----
        $next_due_period = null;
        $check           = new DateTime(date('Y-m-01'));
        // If current month paid, start looking from next month; otherwise start now
        if (isset($paid_periods[$current_ym])) {
            $check->modify('+1 month');
        }
        for ($i = 0; $i < 24; $i++) {
            $ym = $check->format('Y-m');
            if (!isset($paid_periods[$ym])) {
                $next_due_period = $ym;
                break;
            }
            $check->modify('+1 month');
        }

        // ---- 9. Next due date = due_day of that period ----
        $next_due_date = null;
        if ($next_due_period) {
            [$y, $m] = explode('-', $next_due_period);
            $next_due_date = sprintf('%04d-%02d-%02d', (int)$y, (int)$m, $due_day);
        }

        // ---- 10. Overdue? ----
        $today          = new DateTime(date('Y-m-d'));
        $is_overdue     = false;
        $days_until_due = null;
        if ($next_due_date) {
            $due = new DateTime($next_due_date);
            $days_until_due = (int)$today->diff($due)->format('%r%a');
            $is_overdue = ($days_until_due < 0);
        }

        // ---- 11. Balance: what should they have paid vs what they have ----
        // Expected = months_expected * monthly
        // Balance = expected - paid
        //   positive → owes
        //   negative → credit / advance
        $expected_total = $months_expected * $monthly;
        $balance        = $expected_total - $total_paid;

        // ---- 12. Benefit rules ----
        $benefit = [];
        try {
            $summary = BenefitRules::getMemberBenefitSummary($db, $member_code);
            $benefit = [
                'base_benefit'      => (float)($summary['base_benefit']      ?? 0),
                'good_payer_bonus'  => (float)($summary['good_payer_bonus']  ?? 0),
                'accident_bonus'    => (float)($summary['accident_bonus']    ?? 10000),
                'deduction'         => (float)($summary['deduction']         ?? 0),
                'deduction_reason'  => $summary['deduction_reason']          ?? '',
                'total_benefit'     => (float)($summary['total_benefit']     ?? 0),
                'accident_max'      => (float)($summary['accident_max_benefit'] ?? 0),
                'is_good_payer'     => (bool) ($summary['is_good_payer']     ?? false),
                'missed_payments'   => (int)  ($summary['missed_payments']   ?? 0),
                'years_as_member'   => (int)  ($summary['years_as_member']   ?? 0),
                'months_as_member'  => (int)  ($summary['months_as_member']  ?? 0),
                'probation'         => $summary['probation']                 ?? null,
                'is_donation'       => (bool) ($summary['is_donation']       ?? false),
            ];
        } catch (Throwable $e) {
            // Benefit rules failed — never break the dashboard for it.
            $benefit = [
                'error'        => 'benefit_unavailable',
                'total_benefit'=> 0,
                'years_as_member' => 0,
            ];
        }

        // ---- 13. Recent payments (last 5) ----
        $recent = array_slice(array_reverse($confirmed), 0, 5);

        // ---- 14. Return ----
        return [
            'member'            => $member,
            'monthly'           => $monthly,
            'months_paid'       => $months_paid,
            'months_expected'   => $months_expected,
            'months_missing'    => $months_missing,
            'total_paid'        => round($total_paid, 2),
            'expected_total'    => round($expected_total, 2),
            'balance'           => round($balance, 2),
            'advance_months'    => $advance_months,
            'advance_until'     => $advance_until,
            'next_due_period'   => $next_due_period,
            'next_due_date'     => $next_due_date,
            'is_overdue'        => $is_overdue,
            'days_until_due'    => $days_until_due,
            'is_good_payer'     => !empty($benefit['is_good_payer']),
            'missed_periods'    => $missed_periods,
            'missed_count'      => $months_missing,
            'pending_payments'  => $pending,
            'recent_payments'   => $recent,
            'benefit'           => $benefit,
            'due_day'           => $due_day,
            'server_time'       => date('c'),
        ];
    }
}
?>