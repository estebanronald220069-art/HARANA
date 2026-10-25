<?php
// admin/includes/functions.php
// Refactored for strict data integrity. All payment queries strictly use `member_code`.

function getMemberPaymentStatus($member_code, $db) {
    $current_ym = date('Y-m');
    $paid = $db->getSingle("SELECT COUNT(*) as cnt FROM payments WHERE member_code = ? AND COALESCE(period_month, DATE_FORMAT(payment_date, '%Y-%m')) = ? AND payment_status = 'confirmed'", [$member_code, $current_ym], 'ss');
    $paid_count = $paid['cnt'] ?? 0;

    $advanced = $db->getSingle("SELECT COUNT(*) as cnt FROM payments WHERE member_code = ? AND payment_status = 'confirmed' AND COALESCE(period_month, DATE_FORMAT(payment_date, '%Y-%m')) > ?", [$member_code, $current_ym], 'ss');
    $advanced_count = $advanced['cnt'] ?? 0;

    $pending = $db->getSingle("SELECT COUNT(*) as cnt FROM payments WHERE member_code = ? AND payment_status = 'pending' AND COALESCE(period_month, DATE_FORMAT(payment_date, '%Y-%m')) = ?", [$member_code, $current_ym], 'ss');
    $pending_count = $pending['cnt'] ?? 0;

    if ($advanced_count > 0) return ['status' => 'advanced', 'label' => 'Advanced', 'class' => 'advanced', 'count' => $advanced_count, 'pending' => $pending_count];
    if ($paid_count > 0) return ['status' => 'paid', 'label' => 'Paid', 'class' => 'paid', 'count' => 0, 'pending' => $pending_count];
    return ['status' => 'unpaid', 'label' => 'Unpaid', 'class' => 'unpaid', 'count' => 0, 'pending' => $pending_count];
}

function getMemberById($db, $member_code) {
    return $db->getSingle("SELECT * FROM members WHERE member_code = ?", [$member_code], 's');
}

function getMemberPaymentHistory($db, $member_code, $limit = 12) {
    return $db->getAll("SELECT * FROM payments WHERE member_code = ? ORDER BY payment_date DESC LIMIT ?", [$member_code, $limit], 'si');
}

function getMemberDocuments($db, $member_code) {
    return $db->getAll("SELECT * FROM member_documents WHERE member_code = ? ORDER BY uploaded_at DESC", [$member_code], 's');
}

function generateMemberCSV($members, $fields = []) {
    $default_fields = ['member_code'=>'Member Code','first_name'=>'First Name','last_name'=>'Last Name','middle_name'=>'Middle Name','email'=>'Email','contact_number'=>'Contact Number','province'=>'Province','city'=>'City','barangay'=>'Barangay','permanent_province'=>'Permanent Province','permanent_city'=>'Permanent City','permanent_barangay'=>'Permanent Barangay','chapter'=>'Chapter','group_name'=>'Group','monthly_contribution'=>'Monthly Contribution','status'=>'Member Status','date_joined'=>'Date Joined'];
    if (empty($fields)) $fields = $default_fields;
    $output = fopen('php://temp', 'r+');
    fputcsv($output, array_values($fields));
    foreach ($members as $member) {
        $row = [];
        foreach (array_keys($fields) as $field) $row[] = $member[$field] ?? '';
        fputcsv($output, $row);
    }
    rewind($output);
    $csv = stream_get_contents($output);
    fclose($output);
    return $csv;
}

function logAdminActivity($db, $admin_id, $action, $details = '') {
    return $db->execute("INSERT INTO admin_activity_log (admin_id, action, details, created_at) VALUES (?, ?, ?, NOW())", [$admin_id, $action, $details], 'iss');
}

function getAdminActivityLog($db, $limit = 50) {
    return $db->getAll("SELECT al.*, u.full_name FROM admin_activity_log al JOIN users u ON al.admin_id = u.user_id ORDER BY al.created_at DESC LIMIT ?", [$limit], 'i');
}

function formatDate($date, $format = 'M d, Y') {
    if (empty($date) || $date === '0000-00-00') return 'N/A';
    return date($format, strtotime($date));
}

function getStatusBadge($status) {
    $classes = ['paid'=>'paid','unpaid'=>'unpaid','advanced'=>'advanced','active'=>'paid','inactive'=>'inactive','pending'=>'pending', 'warning'=>'warning'];
    $labels = ['paid'=>'✅ Paid','unpaid'=>'❌ Unpaid','advanced'=>'⭐ Advanced','active'=>'Active','inactive'=>'Inactive','pending'=>'⏳ Pending', 'warning'=>'⚠️ Notice'];
    $class = $classes[$status] ?? 'inactive';
    $label = $labels[$status] ?? ucfirst($status);
    return "<span class=\"status-badge {$class}\">{$label}</span>";
}

function getMemberFullName($member) {
    $parts = [];
    if (!empty($member['first_name'])) $parts[] = $member['first_name'];
    if (!empty($member['middle_name'])) $parts[] = $member['middle_name'];
    if (!empty($member['last_name'])) $parts[] = $member['last_name'];
    return implode(' ', $parts);
}

function safeGet($array, $key, $default = '') {
    return isset($array[$key]) ? $array[$key] : $default;
}

/**
 * UNIFIED Bulk monitoring-status calculator.
 *
 * Uses Chronological Allocation: bulk payments are automatically applied to
 * the oldest unpaid months.
 *
 * DATA INTEGRITY FIX:
 * - Strictly uses `member_code` (VARCHAR) for all matching.
 * - Only counts payments whose period_month matches `YYYY-MM` exactly.
 * - Explicitly checks if a payment was made for the *exact* viewed month.
 *   If yes, they are marked "Paid" for this specific month, even if they owe
 *   money from prior months.
 */
function getMonitoringStatusForMembers($db, $members, $year, $month) {
    if (empty($members)) return [];
    $codes = array_column($members, 'member_code');
    if (empty($codes)) return [];

    $ph    = implode(',', array_fill(0, count($codes), '?'));
    $types = str_repeat('s', count($codes));

    $view_ym   = sprintf('%04d-%02d', $year, $month);
    $today_ym  = date('Y-m');

    // 1. Fetch ALL confirmed payments up to this month
    $rows = $db->getAll("
        SELECT member_code, period_month, SUM(amount) AS amount
        FROM payments
        WHERE member_code IN ($ph)
          AND payment_status = 'confirmed'
          AND period_month REGEXP '^[0-9]{4}-[0-9]{2}$'
          AND period_month <= ?
        GROUP BY member_code, period_month
    ", array_merge($codes, [$view_ym]), $types . 's');

    // Organize payments: $paid_months['20264435']['2026-10'] = 100
    $paid_months = [];
    foreach ($rows as $r) {
        $paid_months[$r['member_code']][$r['period_month']] = (float)$r['amount'];
    }

    $out = [];
    foreach ($members as $m) {
        $code    = $m['member_code'];
        $join_ym = !empty($m['date_joined']) ? substr($m['date_joined'], 0, 7) : $view_ym;
        
        // If they haven't joined yet by the view month
        if ($join_ym > $view_ym) {
            $out[$code] = ['status' => 'unpaid', 'missed_prior' => 0, 'pending' => 0];
            continue;
        }

        $monthly = (float)($m['monthly_contribution'] ?? 100);
        if ($monthly <= 0) $monthly = 100;

        $member_payments = $paid_months[$code] ?? [];
        $missed_count = 0;
        $total_expected = 0;
        $total_paid = array_sum($member_payments);

        // Loop through every month from Date Joined to View Month
        $cursor = new DateTime($join_ym . '-01');
        $end    = new DateTime($view_ym . '-01');
        
        while ($cursor <= $end) {
            $check_ym = $cursor->format('Y-m');
            $total_expected += $monthly;
            
            // Did they pay for this specific month?
            $paid_this_month = $member_payments[$check_ym] ?? 0;
            
            // If they didn't pay the full amount, and it's NOT the current month (grace period)
            if ($paid_this_month < $monthly) {
                if (!($check_ym === $view_ym && $view_ym === $today_ym)) {
                    $missed_count++;
                }
            }
            
            $cursor->modify('+1 month');
        }

        // Determine Status based on EXPLICIT payments
        $paid_for_view_month = ($member_payments[$view_ym] ?? 0) >= $monthly;

        if ($missed_count > 0) {
            // They missed a prior month
            $status = 'overdue';
        } elseif ($paid_for_view_month) {
            // No prior missed months, and they paid for the current view month
            $status = 'paid';
        } else {
            // No prior missed months, but current month is unpaid (Grace Period)
            $status = 'unpaid';
        }

        // If they have advanced payments (paid beyond view month)
        $paid_beyond_view = 0;
        foreach ($member_payments as $pm => $amt) {
            if ($pm > $view_ym) $paid_beyond_view += $amt;
        }
        
        if ($status === 'paid' && $paid_beyond_view > 0) {
            $status = 'advanced';
        }

        $out[$code] = [
            'status'       => $status,
            'missed_prior' => $missed_count,
            'pending'      => 0,
            'balance'      => max(0, $total_expected - $total_paid)
        ];
    }
    return $out;
}

function _harana_next_month_ym($ym) {
    list($y, $m) = array_map('intval', explode('-', $ym));
    $m++;
    if ($m > 12) { $m = 1; $y++; }
    return sprintf('%04d-%02d', $y, $m);
}

/* ============================================================
   Reports / Historical Snapshot Helpers
   ============================================================ */

function haranaYmRange($date_start, $date_end) {
    return [date('Y-m', strtotime($date_start)), date('Y-m', strtotime($date_end))];
}

function getMemberStatusAsOf($db, $member_code, $as_of_date) {
    $as_of_dt = $as_of_date . ' 23:59:59';
    $dd = $db->getSingle("SELECT death_date FROM deceased_details WHERE member_code = ? LIMIT 1", [$member_code], 's');
    if ($dd && !empty($dd['death_date']) && $dd['death_date'] <= $as_of_date) return 'deceased';

    $ev = $db->getSingle("SELECT action FROM system_logs WHERE action IN ('MEMBER_INACTIVE','MEMBER_REACTIVATE') AND description LIKE CONCAT('%', ?, '%') AND created_at <= ? ORDER BY created_at DESC, log_id DESC LIMIT 1", [$member_code, $as_of_dt], 'ss');
    if ($ev && $ev['action'] === 'MEMBER_INACTIVE') return 'inactive';
    return 'active';
}

function getMemberStatusCountsAsOf($db, $as_of_date) {
    $as_of_dt = $as_of_date . ' 23:59:59';
    $total = (int)($db->getSingle("SELECT COUNT(*) AS cnt FROM members WHERE date_joined <= ?", [$as_of_date], 's')['cnt'] ?? 0);

    $deceased = (int)($db->getSingle("
        SELECT COUNT(*) AS cnt FROM members m
        LEFT JOIN deceased_details dd ON dd.member_code = m.member_code
        WHERE (dd.death_date IS NOT NULL AND dd.death_date <= ?)
           OR (m.status = 'deceased' AND dd.member_code IS NULL AND DATE(m.updated_at) <= ?)
    ", [$as_of_date, $as_of_date], 'ss')['cnt'] ?? 0);

    $active = (int)($db->getSingle("
        SELECT COUNT(*) AS cnt FROM members m
        WHERE m.date_joined <= ?
          AND NOT EXISTS (
            SELECT 1 FROM system_logs sl
            WHERE sl.action = 'MEMBER_INACTIVE' AND sl.description LIKE CONCAT('%', m.member_code, '%') AND sl.created_at <= ?
              AND NOT EXISTS (SELECT 1 FROM system_logs sl2 WHERE sl2.action = 'MEMBER_REACTIVATE' AND sl2.description LIKE CONCAT('%', m.member_code, '%') AND sl2.created_at > sl.created_at AND sl2.created_at <= ?)
          )
          AND NOT (
            EXISTS (SELECT 1 FROM deceased_details dd WHERE dd.member_code = m.member_code AND dd.death_date <= ?)
            OR (m.status = 'deceased' AND NOT EXISTS (SELECT 1 FROM deceased_details dd WHERE dd.member_code = m.member_code) AND DATE(m.updated_at) <= ?)
          )
    ", [$as_of_date, $as_of_dt, $as_of_dt, $as_of_date, $as_of_date], 'sssss')['cnt'] ?? 0);

    return ['active' => $active, 'deceased' => $deceased, 'inactive' => max(0, $total - $active - $deceased), 'total' => $total];
}