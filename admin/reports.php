<?php
// admin/reports.php — Comprehensive Reports & Analytics (2026)
require_once '../includes/config.php';
require_once '../includes/db_connection.php';
require_once '../includes/security.php';
require_once '../includes/auth.php';
require_once '../includes/settings.php';
require_once 'includes/functions.php'; 

$auth->requireLogin();
$current_user = $auth->getCurrentUser();
$db = Database::getInstance();

/* ============================================================
   PENDING BADGE
   ============================================================ */
$pending_count = 0;
if ($current_user['role'] === 'admin') {
    $pending_count = (int)($db->getSingle("SELECT COUNT(*) as cnt FROM pending_users WHERE status = 'pending'")['cnt'] ?? 0);
}

/* ============================================================
   FILTER OPTIONS
   ============================================================ */
$chapters_list = array_column(
    $db->getAll("SELECT chapter_name FROM chapters WHERE is_active = 1 ORDER BY sort_order ASC, chapter_name ASC"),
    'chapter_name'
);
$groups_list = array_column(
    $db->getAll("SELECT DISTINCT group_name FROM groups WHERE is_active = 1 ORDER BY group_name ASC"),
    'group_name'
);
$barangays_list = array_column(
    $db->getAll("SELECT DISTINCT barangay FROM members WHERE status = 'active' AND barangay IS NOT NULL AND barangay != '' ORDER BY barangay ASC"),
    'barangay'
);

/* ============================================================
   REPORT CONFIGURATION
   ============================================================ */
$report_view     = isset($_GET['view']) ? Security::sanitize($_GET['view']) : 'dashboard';
$allowed_views   = ['dashboard', 'financial', 'members', 'compliance', 'performance', 'aging', 'beneficiary', 'chapters'];
if (!in_array($report_view, $allowed_views, true)) $report_view = 'dashboard';

$period          = isset($_GET['period']) ? Security::sanitize($_GET['period']) : 'monthly';
$allowed_periods = ['monthly', 'quarterly', 'yearly', 'custom'];
if (!in_array($period, $allowed_periods, true)) $period = 'monthly';

$selected_year   = isset($_GET['year'])  ? (int)$_GET['year']  : (int)date('Y');
$selected_month  = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$selected_quarter = isset($_GET['quarter']) ? (int)$_GET['quarter'] : (int)ceil(date('m') / 3);

if ($selected_year < 2020 || $selected_year > (int)date('Y') + 1) $selected_year = (int)date('Y');
if ($selected_month < 1 || $selected_month > 12) $selected_month = (int)date('m');
if ($selected_quarter < 1 || $selected_quarter > 4) $selected_quarter = (int)ceil(date('m') / 3);

// Custom range
$date_from = isset($_GET['date_from']) ? Security::sanitize($_GET['date_from']) : date('Y-m-01');
$date_to   = isset($_GET['date_to'])   ? Security::sanitize($_GET['date_to'])   : date('Y-m-d');

$df = DateTime::createFromFormat('Y-m-d', $date_from);
$dt = DateTime::createFromFormat('Y-m-d', $date_to);
if (!$df) $date_from = date('Y-m-01');
if (!$dt) $date_to   = date('Y-m-d');
if ($date_from > $date_to) { $tmp = $date_from; $date_from = $date_to; $date_to = $tmp; }

// Filters
$filter_chapter  = isset($_GET['filter_chapter'])  ? Security::sanitize($_GET['filter_chapter'])  : '';
$filter_group    = isset($_GET['filter_group'])    ? Security::sanitize($_GET['filter_group'])    : '';
$filter_barangay = isset($_GET['filter_barangay']) ? Security::sanitize($_GET['filter_barangay']) : '';
$filter_status   = isset($_GET['filter_status'])   ? Security::sanitize($_GET['filter_status'])   : '';

// Pagination
$per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 25;
$allowed_per_page = [10, 25, 50, 100];
if (!in_array($per_page, $allowed_per_page, true)) $per_page = 25;
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $per_page;

/* ============================================================
   BUILD DATE RANGE FOR QUERIES
   ============================================================ */
$range_start = date('Y-m-d', strtotime($date_from));
$range_end   = date('Y-m-d', strtotime($date_to));

if ($period === 'monthly') {
    $range_start = date('Y-m-01', strtotime($selected_year . '-' . str_pad($selected_month, 2, '0', STR_PAD_LEFT) . '-01'));
    $range_end   = date('Y-m-t', strtotime($range_start));
} elseif ($period === 'quarterly') {
    $q_start_month = ($selected_quarter - 1) * 3 + 1;
    $range_start = date('Y-m-01', strtotime($selected_year . '-' . str_pad($q_start_month, 2, '0', STR_PAD_LEFT) . '-01'));
    $range_end   = date('Y-m-t', strtotime($range_start . ' +2 months'));
} elseif ($period === 'yearly') {
    $range_start = $selected_year . '-01-01';
    $range_end   = $selected_year . '-12-31';
}

// Inclusive YYYY-MM bounds
list($range_start_ym, $range_end_ym) = haranaYmRange($range_start, $range_end);

// Months spanned by the selected range (inclusive)
$months_in_range = max(1,
    ((int)date('Y', strtotime($range_end)) - (int)date('Y', strtotime($range_start))) * 12
    + ((int)date('n', strtotime($range_end)) - (int)date('n', strtotime($range_start)))
    + 1
);

/* ============================================================
   HELPER: Build WHERE clause fragments
   ============================================================ */
$member_filters = [];
$member_params  = [];
$member_types   = '';

if ($filter_chapter !== '') {
    $member_filters[] = "m.chapter = ?";
    $member_params[]  = $filter_chapter;
    $member_types    .= 's';
}
if ($filter_group !== '') {
    $member_filters[] = "m.group_name = ?";
    $member_params[]  = $filter_group;
    $member_types    .= 's';
}
if ($filter_barangay !== '') {
    $member_filters[] = "m.barangay = ?";
    $member_params[]  = $filter_barangay;
    $member_types    .= 's';
}
if ($filter_status !== '') {
    $member_filters[] = "m.status = ?";
    $member_params[]  = $filter_status;
    $member_types    .= 's';
}
$member_where = !empty($member_filters) ? ' AND ' . implode(' AND ', $member_filters) : '';

/* ============================================================
   POST: COMPLIANCE CSV IMPORT
   ============================================================ */
$import_result = null;
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import_compliance_csv') {
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error_message = 'Invalid security token.';
    } elseif (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $error_message = 'Please choose a valid CSV file to upload.';
    } else {
        $tmp  = $_FILES['csv_file']['tmp_name'];
        $name = $_FILES['csv_file']['name'];
        $ext  = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        if ($ext !== 'csv') {
            $error_message = 'Only .csv files are allowed.';
        } elseif ($_FILES['csv_file']['size'] > 5 * 1024 * 1024) {
            $error_message = 'CSV file is too large (max 5MB).';
        } else {
            $result = ['updated' => 0, 'skipped' => 0, 'errors' => []];
            if (($handle = fopen($tmp, 'r')) !== false) {
                $firstBytes = fread($handle, 3);
                if ($firstBytes !== "\xEF\xBB\xBF") rewind($handle);

                $header = fgetcsv($handle);
                if ($header === false || empty($header)) {
                    $error_message = 'CSV file appears to be empty.';
                } else {
                    $header = array_map(fn($h) => strtolower(trim(str_replace("\xEF\xBB\xBF", '', $h))), $header);
                    if (!in_array('member_code', $header, true)) {
                        $error_message = 'CSV must contain at least the "member_code" column.';
                    } else {
                        $idx = array_flip($header);
                        $allowed_cols = [
                            'chapter', 'group_name', 'barangay', 'status', 'date_joined',
                            'monthly_contribution', 'beneficiary_name', 'beneficiary_relation',
                            'beneficiary_age', 'beneficiary_contact', 'contact_number', 'email'
                        ];
                        $row_num = 1;
                        $db->getConnection()->begin_transaction();

                        try {
                            while (($row = fgetcsv($handle)) !== false) {
                                $row_num++;
                                if (count($row) === 1 && trim((string)$row[0]) === '') continue;

                                $member_code = trim((string)($row[$idx['member_code']] ?? ''));
                                if ($member_code === '') {
                                    $result['skipped']++;
                                    $result['errors'][] = "Row $row_num: missing member_code";
                                    continue;
                                }

                                $exists = $db->getSingle("SELECT member_code FROM members WHERE member_code = ?", [$member_code], 's');
                                if (!$exists) {
                                    $result['skipped']++;
                                    $result['errors'][] = "Row $row_num: member '$member_code' not found";
                                    continue;
                                }

                                $resolved_chapter_id = null;
                                $resolved_group_id   = null;

                                if (isset($idx['chapter'])) {
                                    $chapName = trim((string)($row[$idx['chapter']] ?? ''));
                                    if ($chapName !== '') {
                                        $chapRow = $db->getSingle("SELECT chapter_id FROM chapters WHERE chapter_name = ? LIMIT 1", [$chapName], 's');
                                        if (!$chapRow) {
                                            $result['skipped']++;
                                            $result['errors'][] = "Row $row_num: unknown chapter '$chapName'";
                                            continue;
                                        }
                                        $resolved_chapter_id = (int)$chapRow['chapter_id'];
                                    }
                                }
                                if (isset($idx['group_name'])) {
                                    $grpName = trim((string)($row[$idx['group_name']] ?? ''));
                                    if ($grpName !== '') {
                                        if ($resolved_chapter_id === null) {
                                            $result['skipped']++;
                                            $result['errors'][] = "Row $row_num: group_name given without chapter";
                                            continue;
                                        }
                                        $grpRow = $db->getSingle(
                                            "SELECT group_id FROM groups WHERE group_name = ? AND chapter_id = ? LIMIT 1",
                                            [$grpName, $resolved_chapter_id], 'si'
                                        );
                                        if (!$grpRow) {
                                            $result['skipped']++;
                                            $result['errors'][] = "Row $row_num: group '$grpName' not found in chapter";
                                            continue;
                                        }
                                        $resolved_group_id = (int)$grpRow['group_id'];
                                    }
                                }

                                $set = []; $vals = []; $types = '';
                                foreach ($allowed_cols as $col) {
                                    if (!isset($idx[$col])) continue;
                                    if ($col === 'chapter' || $col === 'group_name') continue;

                                    $val = trim((string)($row[$idx[$col]] ?? ''));

                                    if ($col === 'status') {
                                        $val = strtolower($val);
                                        if (!in_array($val, ['active','inactive','deceased'], true)) {
                                            $result['errors'][] = "Row $row_num: invalid status '$val'";
                                            continue;
                                        }
                                    } elseif (in_array($col, ['beneficiary_age','monthly_contribution'], true)) {
                                        if ($val === '') {
                                            $val = null;
                                        } elseif (!is_numeric($val)) {
                                            $result['errors'][] = "Row $row_num: invalid numeric value for $col ('$val')";
                                            continue;
                                        }
                                    } elseif ($col === 'date_joined' && $val !== '') {
                                        $d = DateTime::createFromFormat('Y-m-d', $val);
                                        if (!$d || $d->format('Y-m-d') !== $val) {
                                            $result['errors'][] = "Row $row_num: invalid date_joined '$val' (expected YYYY-MM-DD)";
                                            continue;
                                        }
                                    } else {
                                        if ($val === '') $val = null;
                                    }

                                    $set[]  = "`$col` = ?";
                                    $vals[] = $val;
                                    $types .= 's';
                                }

                                if ($resolved_chapter_id !== null) {
                                    $set[]  = "`chapter_id` = ?";
                                    $vals[] = $resolved_chapter_id;
                                    $types .= 'i';
                                }
                                if ($resolved_group_id !== null) {
                                    $set[]  = "`group_id` = ?";
                                    $vals[] = $resolved_group_id;
                                    $types .= 'i';
                                }

                                if (empty($set)) {
                                    $result['skipped']++;
                                    continue;
                                }

                                $vals[] = $member_code;
                                $types .= 's';
                                $sql = "UPDATE members SET " . implode(', ', $set) . " WHERE member_code = ?";
                                if ($db->execute($sql, $vals, $types) !== false) $result['updated']++;
                                else $result['skipped']++;
                            }

                            $db->getConnection()->commit();
                            Security::logEvent('COMPLIANCE_IMPORT', "Imported CSV: {$result['updated']} updated, {$result['skipped']} skipped");
                            $_SESSION['compliance_import_result'] = $result;
                            header('Location: reports.php?view=compliance&imported=1');
                            exit();
                        } catch (Throwable $e) {
                            $db->getConnection()->rollback();
                            $error_message = 'Import failed: ' . $e->getMessage();
                        }
                    }
                }
                fclose($handle);
            }
        }
    }
}

if (isset($_SESSION['compliance_import_result'])) {
    $import_result = $_SESSION['compliance_import_result'];
    unset($_SESSION['compliance_import_result']);
}

/* ============================================================
   SAMPLE CSV DOWNLOAD
   ============================================================ */
if (isset($_GET['download_sample_csv']) && $_GET['download_sample_csv'] === 'compliance') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="compliance_import_sample.csv"');
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out, ['member_code','chapter','group_name','barangay','status','date_joined','monthly_contribution','beneficiary_name','beneficiary_relation','beneficiary_age','beneficiary_contact','contact_number','email']);
    fputcsv($out, ['M0001','NATIVIDAD','Group A','Singalat','active','2023-01-15','100.00','Maria Santos','Spouse','45','09171234567','09181234567','juan@example.com']);
    fputcsv($out, ['M0002','GUIMBA','Group B','Poblacion','active','2023-06-01','100.00','Jose Cruz','Son','12','09181234567','09191234567','jose@example.com']);
    fclose($out);
    exit;
}

/* ============================================================
   DASHBOARD METRICS (period-basis + historical-as-of)
   ============================================================ */

$total_collections = (float)($db->getSingle("
    SELECT COALESCE(SUM(amount),0) as total FROM payments
    WHERE payment_status = 'confirmed'
      AND COALESCE(period_month, DATE_FORMAT(payment_date, '%Y-%m'))
          BETWEEN ? AND ?
", [$range_start_ym, $range_end_ym], 'ss')['total'] ?? 0);

$current_month_collections = $total_collections;

$status_counts          = getMemberStatusCountsAsOf($db, $range_end);
$total_active_members   = $status_counts['active'];
$total_inactive_members = $status_counts['inactive'];
$total_deceased_members = $status_counts['deceased'];

$expected_monthly = (float)($db->getSingle("
    SELECT COALESCE(SUM(m.monthly_contribution), 0) AS total
    FROM members m
    WHERE m.date_joined <= ?
      AND NOT EXISTS (
        SELECT 1 FROM system_logs sl
        WHERE sl.action = 'MEMBER_INACTIVE'
          AND sl.description LIKE CONCAT('%', m.member_code, '%')
          AND sl.created_at <= ?
          AND NOT EXISTS (
            SELECT 1 FROM system_logs sl2
            WHERE sl2.action = 'MEMBER_REACTIVATE'
              AND sl2.description LIKE CONCAT('%', m.member_code, '%')
              AND sl2.created_at > sl.created_at
              AND sl2.created_at <= ?
          )
      )
      AND NOT EXISTS (
        SELECT 1 FROM deceased_details dd
        WHERE dd.member_code = m.member_code AND dd.death_date <= ?
      )
", [$range_end, $range_end . ' 23:59:59', $range_end . ' 23:59:59', $range_end], 'ssss')['total'] ?? 0);
$expected_period = $expected_monthly * $months_in_range;
$collection_rate = $expected_period > 0
    ? round(($current_month_collections / $expected_period) * 100, 1)
    : 0;

$pending_payments_count = (int)($db->getSingle("
    SELECT COUNT(*) as cnt FROM payments
    WHERE payment_status = 'pending'
      AND COALESCE(period_month, DATE_FORMAT(payment_date, '%Y-%m'))
          BETWEEN ? AND ?
", [$range_start_ym, $range_end_ym], 'ss')['cnt'] ?? 0);
$pending_payments_amount = (float)($db->getSingle("
    SELECT COALESCE(SUM(amount),0) as total FROM payments
    WHERE payment_status = 'pending'
      AND COALESCE(period_month, DATE_FORMAT(payment_date, '%Y-%m'))
          BETWEEN ? AND ?
", [$range_start_ym, $range_end_ym], 'ss')['total'] ?? 0);


// [PATCH E] Overdue accounts — strictly historical (includes members who were active in the past)
$overdue_accounts = (int)($db->getSingle("
    SELECT COUNT(*) AS cnt FROM members m
    LEFT JOIN (
        SELECT member_code, SUM(amount) AS paid_amount
        FROM payments
        WHERE payment_status = 'confirmed'
          AND COALESCE(period_month, DATE_FORMAT(payment_date, '%Y-%m')) <= ?
        GROUP BY member_code
    ) paid ON paid.member_code = m.member_code
    WHERE m.date_joined <= ?
      AND (
          (TIMESTAMPDIFF(MONTH, m.date_joined, ?) + 1) * m.monthly_contribution
          - COALESCE(paid.paid_amount, 0)
      ) > 0
      AND NOT EXISTS (
          SELECT 1 FROM system_logs sl
          WHERE sl.action = 'MEMBER_INACTIVE'
            AND sl.description LIKE CONCAT('%', m.member_code, '%')
            AND sl.created_at <= ?
            AND NOT EXISTS (
              SELECT 1 FROM system_logs sl2
              WHERE sl2.action = 'MEMBER_REACTIVATE'
                AND sl2.description LIKE CONCAT('%', m.member_code, '%')
                AND sl2.created_at > sl.created_at
                AND sl2.created_at <= ?
            )
      )
      AND NOT (
          EXISTS (
              SELECT 1 FROM deceased_details dd
              WHERE dd.member_code = m.member_code AND dd.death_date <= ?
          )
          OR (
              m.status = 'deceased'
              AND NOT EXISTS (
                  SELECT 1 FROM deceased_details dd
                  WHERE dd.member_code = m.member_code
              )
              AND DATE(m.updated_at) <= ?
          )
      )
", [$range_end_ym, $range_end, $range_end, $range_end . ' 23:59:59', $range_end . ' 23:59:59', $range_end, $range_end], 'sssssss')['cnt'] ?? 0);

// [PATCH F] Aging Report — strictly historical
$aging_report = $db->getAll("
    SELECT
        m.member_code,
        CONCAT(m.first_name, ' ', m.last_name) as member_name,
        m.chapter, m.group_name,
        m.monthly_contribution,
        GREATEST(
            TIMESTAMPDIFF(MONTH, m.date_joined, ?)
            - COALESCE(paid.paid_months, 0),
            0
        ) as months_overdue,
        GREATEST(
            (TIMESTAMPDIFF(MONTH, m.date_joined, ?) + 1) * m.monthly_contribution
            - COALESCE(paid.paid_amount, 0),
            0
        ) as balance_due
    FROM members m
    LEFT JOIN (
        SELECT
            member_code,
            COUNT(DISTINCT COALESCE(period_month, DATE_FORMAT(payment_date, '%Y-%m'))) AS paid_months,
            SUM(amount) AS paid_amount
        FROM payments
        WHERE payment_status = 'confirmed'
          AND COALESCE(period_month, DATE_FORMAT(payment_date, '%Y-%m')) <= ?
        GROUP BY member_code
    ) paid ON paid.member_code = m.member_code
    WHERE m.date_joined <= ?
      AND GREATEST(
            TIMESTAMPDIFF(MONTH, m.date_joined, ?)
            - COALESCE(paid.paid_months, 0),
            0
          ) > 0
      AND NOT EXISTS (
          SELECT 1 FROM system_logs sl
          WHERE sl.action = 'MEMBER_INACTIVE'
            AND sl.description LIKE CONCAT('%', m.member_code, '%')
            AND sl.created_at <= ?
            AND NOT EXISTS (
              SELECT 1 FROM system_logs sl2
              WHERE sl2.action = 'MEMBER_REACTIVATE'
                AND sl2.description LIKE CONCAT('%', m.member_code, '%')
                AND sl2.created_at > sl.created_at
                AND sl2.created_at <= ?
            )
      )
      AND NOT (
          EXISTS (
              SELECT 1 FROM deceased_details dd
              WHERE dd.member_code = m.member_code AND dd.death_date <= ?
          )
          OR (
              m.status = 'deceased'
              AND NOT EXISTS (
                  SELECT 1 FROM deceased_details dd
                  WHERE dd.member_code = m.member_code
              )
              AND DATE(m.updated_at) <= ?
          )
      )
    ORDER BY months_overdue DESC, balance_due DESC
    LIMIT 20
", [$range_end, $range_end, $range_end_ym, $range_end, $range_end, $range_end . ' 23:59:59', $range_end . ' 23:59:59', $range_end, $range_end], 'sssssssss');

/* ============================================================
   CHART DATA: Monthly Collections 
   ============================================================ */
$monthly_data = [];
if ($period === 'monthly') {
    $base_timestamp = strtotime($selected_year . '-' . str_pad($selected_month, 2, '0', STR_PAD_LEFT) . '-01');
    for ($i = 11; $i >= 0; $i--) {
        $month_ts = strtotime("-$i months", $base_timestamp);
        $month_key = date('Y-m', $month_ts);
        $month_name = date('M Y', $month_ts);
        $total = (float)($db->getSingle("
            SELECT COALESCE(SUM(amount),0) as total FROM payments
            WHERE payment_status = 'confirmed'
              AND COALESCE(period_month, DATE_FORMAT(payment_date, '%Y-%m')) = ?
        ", [$month_key], 's')['total'] ?? 0);
        $monthly_data[] = ['month' => $month_name, 'month_key' => $month_key, 'total' => $total];
    }
} elseif ($period === 'yearly') {
    for ($m = 1; $m <= 12; $m++) {
        $month_key = $selected_year . '-' . str_pad($m, 2, '0', STR_PAD_LEFT);
        $month_name = date('M Y', mktime(0, 0, 0, $m, 1, $selected_year));
        $total = (float)($db->getSingle("
            SELECT COALESCE(SUM(amount),0) as total FROM payments
            WHERE payment_status = 'confirmed'
              AND COALESCE(period_month, DATE_FORMAT(payment_date, '%Y-%m')) = ?
        ", [$month_key], 's')['total'] ?? 0);
        $monthly_data[] = ['month' => $month_name, 'month_key' => $month_key, 'total' => $total];
    }
} else {
    for ($i = 11; $i >= 0; $i--) {
        $month_ts = strtotime("-$i months");
        $month_key = date('Y-m', $month_ts);
        $month_name = date('M Y', $month_ts);
        $total = (float)($db->getSingle("
            SELECT COALESCE(SUM(amount),0) as total FROM payments
            WHERE payment_status = 'confirmed'
              AND COALESCE(period_month, DATE_FORMAT(payment_date, '%Y-%m')) = ?
        ", [$month_key], 's')['total'] ?? 0);
        $monthly_data[] = ['month' => $month_name, 'month_key' => $month_key, 'total' => $total];
    }
}

/* ============================================================
   CHART DATA: Payment Methods
   ============================================================ */
$payment_methods = $db->getAll("
    SELECT payment_method, COUNT(*) as count, SUM(amount) as total
    FROM payments WHERE payment_status = 'confirmed'
      AND COALESCE(period_month, DATE_FORMAT(payment_date, '%Y-%m'))
          BETWEEN ? AND ?
    GROUP BY payment_method
    ORDER BY total DESC
", [$range_start_ym, $range_end_ym], 'ss');

/* ============================================================
   CHART DATA: Member Growth (historical active count)
   ============================================================ */
$member_growth = [];
for ($i = 11; $i >= 0; $i--) {
    $month_ts   = strtotime("-$i months");
    $month_name = date('M Y', $month_ts);
    $month_end  = date('Y-m-t', $month_ts);
    $cnts       = getMemberStatusCountsAsOf($db, $month_end);
    $member_growth[] = ['month' => $month_name, 'count' => $cnts['active']];
}

/* ============================================================
   CHART DATA: Chapter Performance 
   ============================================================ */
$chapter_rows = $db->getAll("
    SELECT
        m.chapter,
        COUNT(DISTINCT m.member_code) as member_count,
        COALESCE(SUM(m.monthly_contribution),0) as expected_monthly
    FROM members m
    WHERE m.status = 'active'
      AND m.chapter IS NOT NULL AND m.chapter != ''
      AND m.date_joined <= ?
    GROUP BY m.chapter
", [$range_end], 's');

$chapter_collected = $db->getAll("
    SELECT m.chapter, COALESCE(SUM(p.amount),0) as total_collected
    FROM payments p
    JOIN members m ON m.member_code = p.member_code
    WHERE p.payment_status = 'confirmed'
      AND COALESCE(p.period_month, DATE_FORMAT(p.payment_date, '%Y-%m'))
          BETWEEN ? AND ?
      AND m.status = 'active'
      AND m.chapter IS NOT NULL AND m.chapter != ''
    GROUP BY m.chapter
", [$range_start_ym, $range_end_ym], 'ss');

$collected_by_chapter = [];
foreach ($chapter_collected as $r) {
    $collected_by_chapter[$r['chapter']] = (float)$r['total_collected'];
}

$chapter_performance = [];
foreach ($chapter_rows as $r) {
    $expected = (float)$r['expected_monthly'] * $months_in_range;
    $got      = $collected_by_chapter[$r['chapter']] ?? 0.0;
    $rate     = $expected > 0 ? round(($got / $expected) * 100, 2) : 0.0;

    $chapter_performance[] = [
        'chapter'         => $r['chapter'],
        'member_count'    => (int)$r['member_count'],
        'total_collected' => $got,
        'collection_rate' => $rate,
    ];
}
usort($chapter_performance, fn($a, $b) => $b['total_collected'] <=> $a['total_collected']);

/* ============================================================
   DASHBOARD: Top Payers 
   ============================================================ */
$top_payers = $db->getAll("
    SELECT
        m.member_code,
        CONCAT(m.first_name, ' ', m.last_name) as member_name,
        m.chapter, m.group_name,
        COALESCE(SUM(p.amount),0) as total_paid,
        COUNT(p.payment_id) as payment_count
    FROM members m
    LEFT JOIN payments p ON m.member_code = p.member_code
        AND p.payment_status = 'confirmed'
        AND COALESCE(p.period_month, DATE_FORMAT(p.payment_date, '%Y-%m')) BETWEEN ? AND ?
    WHERE m.status = 'active'
    GROUP BY m.member_code, m.first_name, m.last_name, m.chapter, m.group_name
    HAVING total_paid > 0
    ORDER BY total_paid DESC
    LIMIT 10
", [$range_start_ym, $range_end_ym], 'ss');

/* ============================================================
   DASHBOARD: New Members (use date_joined)
   ============================================================ */
if ($period === 'monthly') {
    $new_members = $db->getAll("
        SELECT DATE_FORMAT(date_joined, '%Y-%m') as period, COUNT(*) as count
        FROM members WHERE YEAR(date_joined) = ?
        GROUP BY DATE_FORMAT(date_joined, '%Y-%m')
        ORDER BY period DESC LIMIT 12
    ", [$selected_year], 'i');
} elseif ($period === 'quarterly') {
    $new_members = $db->getAll("
        SELECT CONCAT(YEAR(date_joined), '-Q', QUARTER(date_joined)) as period, COUNT(*) as count
        FROM members WHERE YEAR(date_joined) = ?
        GROUP BY YEAR(date_joined), QUARTER(date_joined)
        ORDER BY period DESC
    ", [$selected_year], 'i');
} else {
    $new_members = $db->getAll("
        SELECT YEAR(date_joined) as period, COUNT(*) as count
        FROM members GROUP BY YEAR(date_joined) ORDER BY period DESC
    ");
}

/* ============================================================
   DASHBOARD: Inactive/Deceased Report (unchanged)
   ============================================================ */
$inactive_members = $db->getAll("
    SELECT
        member_code,
        CONCAT(first_name, ' ', last_name) as member_name,
        chapter, group_name, status,
        inactive_date, inactive_reason
    FROM members
    WHERE status IN ('inactive', 'deceased')
    ORDER BY inactive_date DESC
    LIMIT 20
");

/* ============================================================
   DASHBOARD: Beneficiary Report (unchanged)
   ============================================================ */
$beneficiary_report = $db->getAll("
    SELECT
        member_code,
        CONCAT(first_name, ' ', last_name) as member_name,
        chapter, group_name,
        beneficiary_name, beneficiary_relation, beneficiary_age, beneficiary_contact
    FROM members
    WHERE status = 'active' AND beneficiary_name IS NOT NULL AND beneficiary_name != ''
    ORDER BY member_name ASC
    LIMIT 100
");

/* ============================================================
   REPORT DATA SWITCHER
   ============================================================ */
$report_data = [];
$report_columns = [];
$report_title = '';
$total_records = 0;
$total_pages = 1;

switch ($report_view) {

    case 'financial':
        $report_title = 'Financial Summary Report';
        $conditions = [
            "p.payment_status = 'confirmed'",
            "COALESCE(p.period_month, DATE_FORMAT(p.payment_date, '%Y-%m')) BETWEEN ? AND ?"
        ];
        $params = [$range_start_ym, $range_end_ym];
        $types  = 'ss';
        if ($filter_chapter !== '') {
            $conditions[] = "m.chapter = ?"; $params[] = $filter_chapter; $types .= 's';
        }
        if ($filter_group !== '') {
            $conditions[] = "m.group_name = ?"; $params[] = $filter_group; $types .= 's';
        }
        $where = 'WHERE ' . implode(' AND ', $conditions);
        $total_records = (int)($db->getSingle("
            SELECT COUNT(DISTINCT CONCAT(COALESCE(p.period_month, DATE_FORMAT(p.payment_date,'%Y-%m')), '|', p.payment_method)) as total
            FROM payments p JOIN members m ON m.member_code = p.member_code
            $where
        ", $params, $types)['total'] ?? 0);

        $total_pages = $total_records > 0 ? (int)ceil($total_records / $per_page) : 1;
        if ($page > $total_pages) $page = $total_pages;
        $offset = ($page - 1) * $per_page;

        $sql = "SELECT
                    COALESCE(p.period_month, DATE_FORMAT(p.payment_date, '%Y-%m')) as month,
                    p.payment_method,
                    COUNT(*) as transaction_count,
                    SUM(p.amount) as total_amount,
                    AVG(p.amount) as avg_amount
                FROM payments p JOIN members m ON m.member_code = p.member_code
                $where
                GROUP BY COALESCE(p.period_month, DATE_FORMAT(p.payment_date, '%Y-%m')), p.payment_method
                ORDER BY month DESC, total_amount DESC
                LIMIT ? OFFSET ?";
        $qp = array_merge($params, [$per_page, $offset]);
        $report_data = $db->getAll($sql, $qp, $types . 'ii');
        $report_columns = ['Month', 'Method', 'Transactions', 'Total Amount', 'Avg Amount'];
        break;

    case 'members':
        $report_title = 'Member Demographics';
        $where = "WHERE m.status = 'active' AND m.date_joined <= ?" . $member_where;
        $case_params = array_merge([$range_end], $member_params);
        $case_types  = 's' . $member_types;
        $total_records = (int)($db->getSingle("SELECT COUNT(DISTINCT m.chapter) as total FROM members m $where", $case_params, $case_types)['total'] ?? 0);
        $total_pages = $total_records > 0 ? (int)ceil($total_records / $per_page) : 1;
        if ($page > $total_pages) $page = $total_pages;
        $offset = ($page - 1) * $per_page;

        $sql = "SELECT
                    m.chapter,
                    COUNT(*) as member_count,
                    SUM(CASE WHEN m.gender = 'Male' THEN 1 ELSE 0 END) as male_count,
                    SUM(CASE WHEN m.gender = 'Female' THEN 1 ELSE 0 END) as female_count,
                    ROUND(AVG(m.age), 1) as avg_age,
                    MIN(m.age) as min_age,
                    MAX(m.age) as max_age
                FROM members m
                $where
                GROUP BY m.chapter
                ORDER BY member_count DESC
                LIMIT ? OFFSET ?";
        $qp = array_merge($case_params, [$per_page, $offset]);
        $report_data = $db->getAll($sql, $qp, $case_types . 'ii');
        $report_columns = ['Chapter', 'Total', 'Male', 'Female', 'Avg Age', 'Min Age', 'Max Age'];
        break;

    case 'compliance':
        $report_title = 'Compliance Report';
        $where = "WHERE m.status = 'active' AND m.date_joined <= ?" . $member_where;
        $case_params = array_merge([$range_end], $member_params);
        $case_types  = 's' . $member_types;
        $total_records = (int)($db->getSingle("SELECT COUNT(*) as total FROM members m $where", $case_params, $case_types)['total'] ?? 0);
        $total_pages = $total_records > 0 ? (int)ceil($total_records / $per_page) : 1;
        if ($page > $total_pages) $page = $total_pages;
        $offset = ($page - 1) * $per_page;

        $sql = "SELECT
                    m.member_code,
                    CONCAT(m.first_name, ' ', m.last_name) as member_name,
                    m.chapter, m.group_name, m.barangay,
                    m.date_joined,
                    TIMESTAMPDIFF(MONTH, m.date_joined, ?) as membership_months,
                    m.monthly_contribution,
                    COALESCE(paid.total_paid, 0) as total_paid,
                    GREATEST(
                        (TIMESTAMPDIFF(MONTH, m.date_joined, ?) + 1) * m.monthly_contribution
                        - COALESCE(paid.total_paid, 0),
                        0
                    ) as balance,
                    m.beneficiary_name,
                    CASE
                        WHEN m.beneficiary_name IS NOT NULL AND m.beneficiary_name != '' THEN 'Has Beneficiary'
                        ELSE 'No Beneficiary'
                    END as beneficiary_status,
                    CASE
                        WHEN GREATEST(
                            (TIMESTAMPDIFF(MONTH, m.date_joined, ?) + 1) * m.monthly_contribution
                            - COALESCE(paid.total_paid, 0),
                            0
                        ) <= 0 THEN 'Compliant'
                        ELSE 'Non-Compliant'
                    END as compliance_status
                FROM members m
                LEFT JOIN (
                    SELECT member_code, SUM(amount) AS total_paid
                    FROM payments
                    WHERE payment_status = 'confirmed'
                      AND COALESCE(period_month, DATE_FORMAT(payment_date, '%Y-%m')) <= ?
                    GROUP BY member_code
                ) paid ON paid.member_code = m.member_code
                $where
                ORDER BY membership_months DESC, member_name ASC
                LIMIT ? OFFSET ?";
        $qp = array_merge([$range_end, $range_end, $range_end, $range_end_ym], $case_params, [$per_page, $offset]);
        $qtypes = 'ssss' . $case_types . 'ii';
        $report_data = $db->getAll($sql, $qp, $qtypes);
        $report_columns = ['Code', 'Member', 'Chapter', 'Group', 'Barangay', 'Joined', 'Months', 'Contribution', 'Total Paid', 'Balance', 'Beneficiary', 'Compliance'];
        break;

    case 'performance':
        $report_title = 'Chapter Performance';
        $report_data = $chapter_performance;
        $total_records = count($report_data);
        $total_pages = 1;
        $report_columns = ['Chapter', 'Members', 'Total Collected', 'Collection Rate (%)'];
        break;

    case 'aging':
        $report_title = 'Aging Report';
        $where = "WHERE m.status = 'active' AND m.date_joined <= ?" . $member_where;
        $case_params = array_merge([$range_end], $member_params);
        $case_types  = 's' . $member_types;

        $total_records = (int)($db->getSingle("
            SELECT COUNT(*) as total
            FROM members m
            LEFT JOIN (
                SELECT
                    member_code,
                    COUNT(DISTINCT COALESCE(period_month, DATE_FORMAT(payment_date, '%Y-%m'))) AS paid_months
                FROM payments
                WHERE payment_status = 'confirmed'
                  AND COALESCE(period_month, DATE_FORMAT(payment_date, '%Y-%m')) <= ?
                GROUP BY member_code
            ) paid ON paid.member_code = m.member_code
            $where
              AND GREATEST(
                    TIMESTAMPDIFF(MONTH, m.date_joined, ?)
                    - COALESCE(paid.paid_months, 0),
                    0
                  ) > 0
        ", array_merge([$range_end_ym], $case_params, [$range_end]), 's' . $case_types . 's')['total'] ?? 0);
        $total_pages = $total_records > 0 ? (int)ceil($total_records / $per_page) : 1;
        if ($page > $total_pages) $page = $total_pages;
        $offset = ($page - 1) * $per_page;

        $sql = "SELECT
                    m.member_code,
                    CONCAT(m.first_name, ' ', m.last_name) as member_name,
                    m.chapter, m.group_name,
                    m.monthly_contribution,
                    GREATEST(
                        TIMESTAMPDIFF(MONTH, m.date_joined, ?)
                        - COALESCE(paid.paid_months, 0),
                        0
                    ) as months_overdue,
                    GREATEST(
                        (TIMESTAMPDIFF(MONTH, m.date_joined, ?) + 1) * m.monthly_contribution
                        - COALESCE(paid.paid_amount, 0),
                        0
                    ) as balance_due,
                    paid.last_payment_date
                FROM members m
                LEFT JOIN (
                    SELECT
                        member_code,
                        COUNT(DISTINCT COALESCE(period_month, DATE_FORMAT(payment_date, '%Y-%m'))) AS paid_months,
                        SUM(amount) AS paid_amount,
                        MAX(payment_date) AS last_payment_date
                    FROM payments
                    WHERE payment_status = 'confirmed'
                      AND COALESCE(period_month, DATE_FORMAT(payment_date, '%Y-%m')) <= ?
                    GROUP BY member_code
                ) paid ON paid.member_code = m.member_code
                $where
                  AND GREATEST(
                        TIMESTAMPDIFF(MONTH, m.date_joined, ?)
                        - COALESCE(paid.paid_months, 0),
                        0
                      ) > 0
                ORDER BY months_overdue DESC, balance_due DESC
                LIMIT ? OFFSET ?";
        $qp = array_merge([$range_end, $range_end, $range_end_ym], $case_params, [$range_end, $per_page, $offset]);
        $qtypes = 'sss' . $case_types . 'sii';
        $report_data = $db->getAll($sql, $qp, $qtypes);
        $report_columns = ['Code', 'Member', 'Chapter', 'Group', 'Contribution', 'Months Overdue', 'Balance Due', 'Last Payment'];
        break;

    case 'beneficiary':
        $report_title = 'Beneficiary Report';
        $where = "WHERE m.status = 'active' AND m.beneficiary_name IS NOT NULL AND m.beneficiary_name != '' AND m.date_joined <= ?" . $member_where;
        $case_params = array_merge([$range_end], $member_params);
        $case_types  = 's' . $member_types;
        $total_records = (int)($db->getSingle("SELECT COUNT(*) as total FROM members m $where", $case_params, $case_types)['total'] ?? 0);
        $total_pages = $total_records > 0 ? (int)ceil($total_records / $per_page) : 1;
        if ($page > $total_pages) $page = $total_pages;
        $offset = ($page - 1) * $per_page;

        $sql = "SELECT
                    m.member_code,
                    CONCAT(m.first_name, ' ', m.last_name) as member_name,
                    m.chapter, m.group_name,
                    m.beneficiary_name,
                    m.beneficiary_relation,
                    m.beneficiary_age,
                    m.beneficiary_contact
                FROM members m
                $where
                ORDER BY member_name ASC
                LIMIT ? OFFSET ?";
        $qp = array_merge($case_params, [$per_page, $offset]);
        $report_data = $db->getAll($sql, $qp, $case_types . 'ii');
        $report_columns = ['Code', 'Member', 'Chapter', 'Group', 'Beneficiary', 'Relation', 'Age', 'Contact'];
        break;

    case 'chapters':
        $report_title = 'Chapter & Group Analytics';
        $report_data = $db->getAll("
            SELECT
                m.chapter,
                m.group_name,
                COUNT(DISTINCT m.member_code) as member_count,
                COALESCE(SUM(p.amount), 0) as total_collected,
                COUNT(DISTINCT p.payment_id) as payment_count
            FROM members m
            LEFT JOIN payments p ON m.member_code = p.member_code
                AND p.payment_status = 'confirmed'
                AND COALESCE(p.period_month, DATE_FORMAT(p.payment_date, '%Y-%m'))
                    BETWEEN ? AND ?
            WHERE m.status = 'active'
              AND m.date_joined <= ?
              AND m.chapter IS NOT NULL AND m.chapter != ''
            GROUP BY m.chapter, m.group_name
            ORDER BY m.chapter ASC, total_collected DESC
        ", [$range_start_ym, $range_end_ym, $range_end], 'sss');
        $total_records = count($report_data);
        $total_pages = 1;
        $report_columns = ['Chapter', 'Group', 'Members', 'Total Collected', 'Payments'];
        break;
}

/* ============================================================
   EXPORT HANDLERS (unchanged)
   ============================================================ */
if (isset($_GET['export']) && in_array($_GET['export'], ['csv', 'pdf'], true) && !empty($report_data)) {
    if ($_GET['export'] === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $report_view . '_report_' . date('Y-m-d') . '.csv"');
        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($out, $report_columns);
        foreach ($report_data as $row) { fputcsv($out, array_values($row)); }
        fclose($out);
        exit;
    }
    if ($_GET['export'] === 'pdf') {
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $report_view . '_report_' . date('Y-m-d') . '.txt"');
        echo "REPORT: " . $report_title . "\r\n";
        echo "Generated: " . date('Y-m-d H:i:s') . "\r\n";
        echo "Period: {$range_start} → {$range_end}\r\n";
        echo str_repeat('=', 60) . "\r\n\r\n";
        echo implode(' | ', $report_columns) . "\r\n";
        echo str_repeat('-', 60) . "\r\n";
        foreach ($report_data as $row) {
            echo implode(' | ', array_values($row)) . "\r\n";
        }
        exit;
    }
}

$csrf_token = Security::generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reports & Analytics — <?php echo APP_NAME; ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
(function(){ try { if (localStorage.getItem('sidebarCollapsed') === 'true') document.documentElement.classList.add('sidebar-was-collapsed'); } catch(e){} })();
</script>
<style>
:root{
    --brand-900:#1c1c1d;--brand-800:#2c2c2d;--brand-700:#375a7f;--brand-600:#4a7a9c;
    --brand-100:#e8f0f8;--brand-50:#f4f8fc;
    --yellow-accent:#fbbf24;--yellow-100:#fef3c7;
    --success-700:#14532d;--success-500:#16a34a;--success-100:#dcfce7;
    --danger-700:#7f1d1d;--danger-500:#dc2626;--danger-100:#fee2e2;
    --warning-700:#78350f;--warning-500:#d97706;--warning-100:#fef3c7;
    --ink-900:#1e2b3c;--ink-700:#334155;--ink-500:#64748b;--ink-400:#94a3b8;--ink-300:#cbd5e1;
    --line:#e2e8f0;--line-soft:#eef2f7;--paper:#f0f2f5;--surface:#fff;
    --radius-sm:10px;--radius:14px;--radius-lg:18px;
    --shadow-sm:0 2px 8px rgba(16,24,40,.07);--shadow-md:0 4px 14px rgba(16,24,40,.08);--shadow-lg:0 10px 26px rgba(16,24,40,.10);
}
*{box-sizing:border-box}
html{font-size:15px}
body{background:var(--paper);font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;margin:0;overflow:hidden;color:var(--ink-900);line-height:1.55}
#wrapper{display:flex;width:100%;height:100vh;overflow:hidden}

/* Sidebar */
#sidebar-wrapper{background:#1c1c1d;color:#fff;width:250px;height:100vh;overflow-y:auto;transition:width .3s;box-shadow:4px 0 10px rgba(0,0,0,.1);white-space:nowrap;flex-shrink:0;position:relative;z-index:1000}
html.sidebar-was-collapsed #sidebar-wrapper{width:70px}
html.sidebar-was-collapsed #sidebar-wrapper .sidebar-heading .brand-text,
html.sidebar-was-collapsed #sidebar-wrapper .sidebar-heading .sidebar-logo,
html.sidebar-was-collapsed #sidebar-wrapper .list-group-item span,
html.sidebar-was-collapsed #sidebar-wrapper .badge-count{display:none}
html.sidebar-was-collapsed #sidebar-wrapper .list-group-item{padding:15px 0;text-align:center;justify-content:center}
html.sidebar-was-collapsed #sidebar-wrapper .list-group-item i{margin-right:0;width:100%;font-size:1.2rem}
html.sidebar-was-collapsed #sidebar-wrapper .sidebar-heading{justify-content:center;padding:1rem 0;gap:0}
html.sidebar-transitions-on #sidebar-wrapper{transition:width .3s ease}
#sidebar-wrapper.collapsed{width:70px}
#sidebar-wrapper.collapsed .list-group-item span{display:none}
#sidebar-wrapper.collapsed .list-group-item{padding:15px 0;text-align:center;justify-content:center}
#sidebar-wrapper.collapsed .list-group-item i{margin-right:0;width:100%;font-size:1.2rem}
#sidebar-wrapper.collapsed .badge-count{display:none !important}
#sidebar-wrapper .sidebar-heading{padding:1rem 1rem .85rem;font-size:1.1rem;font-weight:700;border-bottom:1px solid rgba(255,255,255,.1);background:#1c1c1d;color:#fff;display:flex;align-items:center;gap:10px;line-height:1.15;flex-shrink:0}
#sidebar-wrapper .sidebar-heading .sidebar-logo{height:34px;width:34px;object-fit:contain;flex-shrink:0}
#sidebar-wrapper .sidebar-heading .brand-text{display:flex;flex-direction:column;text-align:left;flex:1;min-width:0}
#sidebar-wrapper .sidebar-heading .brand-acronym{font-size:1.15rem;letter-spacing:.5px;font-weight:800}
#sidebar-wrapper .sidebar-heading .brand-full{font-size:.62rem;font-weight:400;color:rgba(255,255,255,.7);letter-spacing:.2px;text-transform:uppercase;margin-top:1px;white-space:normal;line-height:1.15;max-width:175px}
#sidebar-wrapper.collapsed .sidebar-heading .brand-text,
#sidebar-wrapper.collapsed .sidebar-heading .sidebar-logo{display:none}
#sidebar-wrapper.collapsed .sidebar-heading{justify-content:center;padding:1rem 0;gap:0}
#sidebar-wrapper .list-group{padding-bottom:20px}
#sidebar-wrapper .list-group-item{background:transparent;border:none;color:rgba(255,255,255,.9);padding:.8rem 1.2rem;font-weight:500;font-size:.95rem;text-align:left;display:flex;align-items:center;gap:10px;transition:all .2s;text-decoration:none}
#sidebar-wrapper .list-group-item i{width:24px;text-align:center;font-size:1rem;flex-shrink:0}
#sidebar-wrapper .list-group-item:hover,#sidebar-wrapper .list-group-item.active{background:rgba(255,255,255,.15);color:#fff;border-left:4px solid #fff}
#sidebar-wrapper.collapsed .list-group-item:hover,#sidebar-wrapper.collapsed .list-group-item.active{border-left:none;border-bottom:2px solid #fff}
#sidebar-wrapper .list-group-item .badge-count{margin-left:auto;font-size:.65rem;padding:3px 7px;line-height:1;border-radius:10px;flex-shrink:0}
.menu-toggle{background:transparent;border:none;color:#fff;font-size:1.5rem;cursor:pointer;padding:0 10px;flex-shrink:0;transition:transform .2s}
.menu-toggle:hover{transform:scale(1.1)}

/* Main Layout */
#page-content-wrapper{flex:1;background:var(--paper);height:100vh;display:flex;flex-direction:column;min-width:0}
.navbar{background:#fff !important;box-shadow:0 2px 4px rgba(0,0,0,.05);padding:.5rem 1.2rem;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;flex-wrap:wrap;gap:10px;min-height:50px;position:relative;z-index:100}
.navbar-left{display:flex;align-items:center;gap:12px}
.navbar-brand{font-size:1.1rem;font-weight:600;color:#1c1c1d !important;display:flex;align-items:center;gap:8px;margin:0}
.navbar-right{display:flex;align-items:center;gap:15px;flex-wrap:wrap}
.header-logo{height:28px;width:auto;margin-right:8px;display:none}
#sidebar-wrapper.collapsed ~ #page-content-wrapper .header-logo,
html.sidebar-was-collapsed #sidebar-wrapper ~ #page-content-wrapper .header-logo{display:inline-block}

/* FIXED CONTROLS */
.fixed-controls {background: var(--paper);padding: 14px 20px 0 20px;flex-shrink: 0;z-index: 90;box-shadow: 0 4px 6px -4px rgba(0,0,0,0.05);border-bottom: 1px solid var(--line);}
.content-container {padding: 16px 20px 20px;flex: 1;overflow-y: auto;min-height: 0;}

/* KPI cards */
.kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:14px;margin-bottom:16px}
.kpi-card{background:#fff;border-radius:var(--radius);padding:16px 18px;box-shadow:var(--shadow-sm);border-left:4px solid var(--brand-900);display:flex;align-items:center;gap:14px;transition:all .2s}
.kpi-card:hover{transform:translateY(-2px);box-shadow:var(--shadow-md)}
.kpi-card.green{border-left-color:var(--success-500)}
.kpi-card.gold{border-left-color:var(--yellow-accent)}
.kpi-card.red{border-left-color:var(--danger-500)}
.kpi-card.blue{border-left-color:var(--brand-700)}
.kpi-icon{width:48px;height:48px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.3rem;background:var(--brand-100);color:var(--brand-700);flex-shrink:0}
.kpi-card.green .kpi-icon{background:var(--success-100);color:var(--success-700)}
.kpi-card.gold .kpi-icon{background:var(--yellow-100);color:var(--warning-700)}
.kpi-card.red .kpi-icon{background:var(--danger-100);color:var(--danger-700)}
.kpi-card.blue .kpi-icon{background:#e0f2fe;color:#075985}
.kpi-body{min-width:0;flex:1}
.kpi-label{font-size:.72rem;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-500);font-weight:700;margin-bottom:3px}
.kpi-value{font-size:1.5rem;font-weight:900;color:var(--ink-900);line-height:1.1;letter-spacing:-.02em}
.kpi-sub{font-size:.75rem;color:var(--ink-500);margin-top:3px;font-weight:500}

/* Tabs */
.report-tabs{display:flex;gap:4px;flex-wrap:wrap;background:#fff;padding:6px 10px;border-radius:var(--radius);box-shadow:var(--shadow-sm);margin-bottom:14px}
.report-tab{padding:8px 14px;border-radius:8px;font-size:.82rem;font-weight:600;color:var(--ink-500);text-decoration:none;display:inline-flex;align-items:center;gap:6px;transition:all .2s;white-space:nowrap}
.report-tab:hover{background:var(--brand-50);color:var(--brand-900)}
.report-tab.active{background:var(--brand-900);color:#fff}

/* Toolbar */
.toolbar-row{display:flex;flex-wrap:wrap;gap:8px;align-items:center;justify-content:space-between;background:#fff;padding:10px 14px;border-radius:var(--radius);box-shadow:var(--shadow-sm);margin-bottom:14px}
.toolbar-left,.toolbar-right{display:flex;flex-wrap:wrap;gap:6px;align-items:center}
.filter-select{padding:6px 10px;border:1.5px solid var(--line);border-radius:8px;font-size:.82rem;background:#f8f9fa;height:34px;transition:border-color .2s}
.filter-select:focus{border-color:var(--brand-900);outline:none;box-shadow:0 0 0 3px rgba(28,28,29,.08)}
.btn-action{padding:6px 14px;font-size:.78rem;font-weight:700;border-radius:8px;border:none;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all .2s;height:34px;text-decoration:none}
.btn-action:hover{transform:translateY(-1px)}
.btn-apply{background:var(--brand-900);color:#fff}
.btn-apply:hover{background:#2c2c2d;color:#fff}
.btn-clear{background:#6c757d;color:#fff}
.btn-clear:hover{background:#5a6268;color:#fff}
.btn-export{background:var(--success-500);color:#fff}
.btn-export:hover{background:#15803d;color:#fff}
.btn-print{background:#6c757d;color:#fff}
.btn-print:hover{background:#5a6268;color:#fff}
.btn-import{background:#0070ba;color:#fff}
.btn-import:hover{background:#005a94;color:#fff}

/* Period selector */
.period-pills{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-bottom:14px;background:#fff;padding:8px 14px;border-radius:var(--radius);box-shadow:var(--shadow-sm)}
.period-pill{padding:6px 14px;border-radius:20px;font-size:.78rem;font-weight:600;text-decoration:none;color:var(--ink-500);background:var(--paper);border:1px solid var(--line);transition:all .2s}
.period-pill:hover{background:var(--brand-50);color:var(--brand-900)}
.period-pill.active{background:var(--brand-900);color:#fff;border-color:var(--brand-900)}

/* Card wrapper */
.content-card{background:#fff;border-radius:var(--radius);box-shadow:var(--shadow-sm);margin-bottom:16px;overflow:hidden}
.content-card-header{padding:14px 18px;border-bottom:1px solid var(--line-soft);background:#fbfcfe;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px}
.content-card-title{font-size:.9rem;font-weight:800;color:var(--ink-900);display:flex;align-items:center;gap:8px;margin:0}
.content-card-title i{color:var(--brand-700)}
.content-card-body{padding:16px 18px}

/* Chart cards */
.chart-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(340px,1fr));gap:14px;margin-bottom:16px}
.chart-card{background:#fff;border-radius:var(--radius);box-shadow:var(--shadow-sm);padding:14px 16px;transition:all .2s;cursor:pointer;position:relative}
.chart-card:hover{box-shadow:var(--shadow-md);transform:translateY(-2px)}
.chart-card .chart-title{font-size:.85rem;font-weight:800;color:var(--ink-900);margin-bottom:10px;display:flex;align-items:center;justify-content:space-between;gap:8px;pointer-events:none}
.chart-card .chart-title .hint{font-size:.65rem;font-weight:500;color:var(--ink-400);background:var(--paper);padding:2px 8px;border-radius:20px;border:1px solid var(--line)}
.chart-container{position:relative;height:200px;pointer-events:none}
.chart-container-sm{height:170px}

/* Data table */
.data-table{width:100%;border-collapse:collapse;font-size:.82rem}
.data-table th{background:#f8f9fa;padding:10px 12px;text-align:left;font-size:.7rem;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-500);font-weight:800;border-bottom:2px solid var(--line);white-space:nowrap;position:sticky;top:0;z-index:5}
.data-table td{padding:9px 12px;border-bottom:1px solid var(--line-soft);color:var(--ink-700);vertical-align:middle}
.data-table tr:hover td{background:var(--brand-50)}
.data-table .num{text-align:right;font-variant-numeric:tabular-nums;font-weight:600}
.data-table .amount{color:var(--success-700);font-weight:800}
.data-table .amount.danger{color:var(--danger-700)}
.table-scroll{max-height:480px;overflow:auto;border-radius:0 0 var(--radius) var(--radius);border:1px solid var(--line);border-top:none}

/* Status chips */
.chip{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:20px;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.02em;white-space:nowrap}
.chip-ok{background:var(--success-100);color:var(--success-700)}
.chip-warn{background:var(--warning-100);color:var(--warning-700)}
.chip-danger{background:var(--danger-100);color:var(--danger-700)}
.chip-info{background:var(--brand-100);color:var(--brand-700)}
.chip-dim{background:var(--line-soft);color:var(--ink-500)}

/* Pagination */
.pagination-wrapper{display:flex;align-items:center;justify-content:space-between;padding:12px 18px;background:#f8f9fa;border-top:1px solid var(--line-soft);flex-wrap:wrap;gap:8px}
.pagination-info{font-size:.78rem;color:var(--ink-500)}
.pagination{display:flex;gap:3px;margin:0;list-style:none;padding:0}
.pagination .page-link{padding:5px 10px;font-size:.78rem;border-radius:6px;border:1px solid var(--line);color:var(--brand-900);background:#fff;text-decoration:none;transition:all .2s;min-width:32px;text-align:center}
.pagination .page-link:hover{background:var(--brand-900);color:#fff;border-color:var(--brand-900)}
.pagination .active .page-link{background:var(--brand-900);color:#fff;border-color:var(--brand-900)}
.pagination .disabled .page-link{color:var(--ink-300);background:var(--paper);cursor:not-allowed}

/* Empty state */
.empty-state{text-align:center;padding:40px 20px;color:var(--ink-500)}
.empty-state i{font-size:2.5rem;color:var(--ink-300);margin-bottom:10px}
.empty-state p{margin:0;font-size:.9rem}

/* Modal */
.modal-content{border-radius:14px;border:none;overflow:hidden}
.modal-header{background:#1c1c1d;color:#fff;border:none;padding:14px 20px}
.modal-header .btn-close{filter:brightness(0) invert(1)}
.modal-body{padding:20px;max-height:70vh;overflow-y:auto}
.modal-body .chart-container-modal{height:300px;position:relative}

/* Import zone */
.import-dropzone{border:2px dashed var(--line);border-radius:10px;padding:28px 20px;text-align:center;background:#fafbfc;transition:all .2s;cursor:pointer}
.import-dropzone:hover,.import-dropzone.dragover{border-color:#0070ba;background:#eef7ff}
.import-dropzone .big-icon{font-size:2.5rem;color:#0070ba;margin-bottom:10px}
.import-dropzone .dz-title{font-weight:700;color:var(--ink-900);margin-bottom:4px}
.import-dropzone .dz-sub{font-size:.82rem;color:var(--ink-500)}
.import-dropzone .file-name{margin-top:12px;font-size:.85rem;color:var(--success-700);font-weight:700}
.import-result{border-radius:10px;padding:14px 18px;margin-bottom:16px}
.import-result.success{background:var(--success-100);border-left:4px solid var(--success-500)}
.import-result.warning{background:var(--warning-100);border-left:4px solid var(--warning-500)}
.import-result .result-title{font-weight:800;color:var(--ink-900);margin-bottom:6px;font-size:.95rem}
.import-result .result-errors{max-height:150px;overflow-y:auto;margin-top:8px;font-size:.8rem}
.csv-format-table{font-size:.78rem}
.csv-format-table th{background:#f8f9fa}

/* Responsive */
@media (max-width:992px){
    #sidebar-wrapper{width:70px}
    #sidebar-wrapper .list-group-item span{display:none}
    #sidebar-wrapper .list-group-item i{margin-right:0;width:100%;font-size:1.2rem}
    #sidebar-wrapper .list-group-item{padding:15px 0;text-align:center;justify-content:center}
    #sidebar-wrapper .badge-count{display:none !important}
    #sidebar-wrapper .sidebar-heading .brand-text{display:none}
    #sidebar-wrapper .sidebar-heading .sidebar-logo{display:none}
    #sidebar-wrapper .sidebar-heading{justify-content:center;padding:1rem 0}
    .header-logo{display:inline-block !important}
    .fixed-controls{padding:10px 10px 0 10px}
    .content-container{padding:10px}
}
@media (max-width:768px){
    #sidebar-wrapper{position:fixed;left:0;top:0;transform:translateX(-100%);width:280px;height:100vh;z-index:1050;transition:transform .3s}
    #sidebar-wrapper.mobile-open{transform:translateX(0)}
    #sidebar-wrapper .list-group-item span{display:inline}
    #sidebar-wrapper .list-group-item i{margin-right:10px;width:24px;font-size:1rem}
    #sidebar-wrapper .list-group-item{padding:.8rem 1.2rem;text-align:left;justify-content:flex-start}
    #sidebar-wrapper .badge-count{display:inline-flex !important}
    #sidebar-wrapper .sidebar-heading .brand-text{display:flex}
    #sidebar-wrapper .sidebar-heading .sidebar-logo{display:block}
    #sidebar-wrapper .sidebar-heading{justify-content:space-between;padding:1rem}
    .sidebar-overlay{display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,.5);z-index:1040}
    .sidebar-overlay.active{display:block}
    .header-logo{display:inline-block !important}
    .fixed-controls{padding:8px 10px 0 10px}
    .content-container{padding:10px}
    .report-tabs{justify-content:center}
    .report-tab{font-size:.7rem;padding:5px 10px}
    .period-pill{font-size:.7rem;padding:4px 10px}
    .chart-container,.chart-container-sm{height:140px}
    .table-scroll{max-height:300px}
    .data-table{font-size:.72rem}
    .data-table th,.data-table td{padding:6px 8px}
    .kpi-grid{grid-template-columns:1fr 1fr}
}
@media (max-width:480px){
    .toolbar-left,.toolbar-right{flex-direction:column;align-items:stretch;width:100%}
    .btn-action,.filter-select{justify-content:center;width:100%}
    .kpi-grid{grid-template-columns:1fr}
}
::-webkit-scrollbar{width:6px;height:6px}
::-webkit-scrollbar-track{background:#f1f1f1;border-radius:3px}
::-webkit-scrollbar-thumb{background:#c1c7cd;border-radius:3px}
::-webkit-scrollbar-thumb:hover{background:#a0a7ad}

/* Print-friendly */
@media print {
    #sidebar-wrapper, #sidebarOverlay, .navbar,
    .fixed-controls, .pagination-wrapper, .btn-action,
    .modal { display: none !important; }
    #page-content-wrapper { height: auto; }
    .content-container { overflow: visible; padding: 0; }
    .content-card, .chart-card { box-shadow: none; border: 1px solid #ddd; page-break-inside: avoid; }
    body { overflow: visible; background: #fff; }
    .data-table th { background: #eee !important; }
    .data-table tr:hover td { background: transparent !important; }
}
</style>
</head>
<body>
<div class="sidebar-overlay" id="sidebarOverlay"></div>
<div id="wrapper">

    <div id="sidebar-wrapper">
        <div class="sidebar-heading">
            <img src="../assets/images/harana-logo.png" alt="NHGL" class="sidebar-logo" onerror="this.style.display='none';">
            <span class="brand-text">
                <span class="brand-acronym">NHGL</span>
                <span class="brand-full">Nagkaisang Haranista sa Gintong Luzon</span>
            </span>
            <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
        </div>
        <div class="list-group list-group-flush mt-2">
            <a href="dashboard.php" class="list-group-item"><i class="fas fa-tachometer-alt"></i><span>Dashboard</span></a>
            <a href="members.php" class="list-group-item"><i class="fas fa-users"></i><span>Members</span></a>
            <a href="manage_chapters.php" class="list-group-item"><i class="fas fa-sitemap"></i><span>Chapters & Groups</span></a>
            <a href="leader_assignments.php" class="list-group-item"><i class="fas fa-user-shield"></i><span>Leader Assignment</span></a>
            <a href="council.php" class="list-group-item"><i class="fas fa-user-tie"></i><span>Council</span></a>
            <a href="payments.php" class="list-group-item"><i class="fas fa-credit-card"></i><span>Payments</span></a>
            <a href="remittances.php" class="list-group-item"><i class="fas fa-hand-holding-usd"></i><span>Remittances</span></a>
            <a href="reports.php" class="list-group-item active"><i class="fas fa-chart-bar"></i><span>Reports</span></a>
            <a href="announcements.php" class="list-group-item"><i class="fas fa-bullhorn"></i><span>Send Announcement</span></a>
            <?php if ($current_user['role'] === 'admin'): ?>
            <a href="pending_users.php" class="list-group-item">
                <i class="fas fa-user-clock"></i><span>Pending</span>
                <?php if ($pending_count > 0): ?><span class="badge bg-danger badge-count"><?php echo $pending_count; ?></span><?php endif; ?>
            </a>
            <?php endif; ?>
            <a href="settings.php" class="list-group-item"><i class="fas fa-cog"></i><span>Settings</span></a>
            <a href="../logout.php" class="list-group-item" onclick="return confirm('Are you sure?');"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a>
        </div>
    </div>

    <div id="page-content-wrapper">
        <nav class="navbar navbar-light bg-light">
            <div class="navbar-left">
                <button class="btn btn-link d-md-none p-0 me-2" id="mobileMenuToggle" style="color:#1c1c1d;font-size:1.3rem;"><i class="fas fa-bars"></i></button>
                <img src="../assets/images/harana-logo.png" alt="Harana" class="header-logo" onerror="this.style.display='none';">
                <span class="navbar-brand"><i class="fas fa-chart-bar me-2"></i>Reports & Analytics</span>
            </div>
            <div class="navbar-right">
                <span class="text-muted small d-none d-sm-inline"><i class="fas fa-calendar-alt me-1"></i><?php echo date('F j, Y'); ?></span>
                <span class="small"><i class="fas fa-user-circle me-2"></i><?php echo htmlspecialchars($current_user['full_name'] ?? $current_user['username']); ?></span>
            </div>
        </nav>

        <div class="fixed-controls">
            <div class="report-tabs">
                <a href="?view=dashboard" class="report-tab <?php echo $report_view === 'dashboard' ? 'active' : ''; ?>"><i class="fas fa-chart-pie"></i> Dashboard</a>
                <a href="?view=financial" class="report-tab <?php echo $report_view === 'financial' ? 'active' : ''; ?>"><i class="fas fa-chart-line"></i> Financial</a>
                <a href="?view=members" class="report-tab <?php echo $report_view === 'members' ? 'active' : ''; ?>"><i class="fas fa-users"></i> Members</a>
                <a href="?view=compliance" class="report-tab <?php echo $report_view === 'compliance' ? 'active' : ''; ?>"><i class="fas fa-check-double"></i> Compliance</a>
                <a href="?view=performance" class="report-tab <?php echo $report_view === 'performance' ? 'active' : ''; ?>"><i class="fas fa-trophy"></i> Performance</a>
                <a href="?view=aging" class="report-tab <?php echo $report_view === 'aging' ? 'active' : ''; ?>"><i class="fas fa-clock"></i> Aging</a>
                <a href="?view=beneficiary" class="report-tab <?php echo $report_view === 'beneficiary' ? 'active' : ''; ?>"><i class="fas fa-heart"></i> Beneficiary</a>
                <a href="?view=chapters" class="report-tab <?php echo $report_view === 'chapters' ? 'active' : ''; ?>"><i class="fas fa-sitemap"></i> Chapters</a>
            </div>

            <div class="period-pills">
                <span style="font-size:.78rem;font-weight:700;color:var(--ink-500);margin-right:4px;">Period:</span>
                <a href="?view=<?php echo $report_view; ?>&period=monthly&year=<?php echo $selected_year; ?>&month=<?php echo $selected_month; ?>&filter_chapter=<?php echo urlencode($filter_chapter); ?>&filter_group=<?php echo urlencode($filter_group); ?>&filter_barangay=<?php echo urlencode($filter_barangay); ?>&filter_status=<?php echo urlencode($filter_status); ?>" class="period-pill <?php echo $period === 'monthly' ? 'active' : ''; ?>">Monthly</a>
                <a href="?view=<?php echo $report_view; ?>&period=quarterly&year=<?php echo $selected_year; ?>&quarter=<?php echo $selected_quarter; ?>&filter_chapter=<?php echo urlencode($filter_chapter); ?>&filter_group=<?php echo urlencode($filter_group); ?>&filter_barangay=<?php echo urlencode($filter_barangay); ?>&filter_status=<?php echo urlencode($filter_status); ?>" class="period-pill <?php echo $period === 'quarterly' ? 'active' : ''; ?>">Quarterly</a>
                <a href="?view=<?php echo $report_view; ?>&period=yearly&year=<?php echo $selected_year; ?>&filter_chapter=<?php echo urlencode($filter_chapter); ?>&filter_group=<?php echo urlencode($filter_group); ?>&filter_barangay=<?php echo urlencode($filter_barangay); ?>&filter_status=<?php echo urlencode($filter_status); ?>" class="period-pill <?php echo $period === 'yearly' ? 'active' : ''; ?>">Yearly</a>
                <a href="?view=<?php echo $report_view; ?>&period=custom&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>&filter_chapter=<?php echo urlencode($filter_chapter); ?>&filter_group=<?php echo urlencode($filter_group); ?>&filter_barangay=<?php echo urlencode($filter_barangay); ?>&filter_status=<?php echo urlencode($filter_status); ?>" class="period-pill <?php echo $period === 'custom' ? 'active' : ''; ?>">Custom</a>

                <?php if ($period === 'monthly'): ?>
                    <form method="GET" style="display:inline-flex;gap:4px;align-items:center;margin-left:8px;">
                        <input type="hidden" name="view" value="<?php echo $report_view; ?>">
                        <input type="hidden" name="period" value="monthly">
                        <input type="hidden" name="filter_chapter" value="<?php echo htmlspecialchars($filter_chapter); ?>">
                        <input type="hidden" name="filter_group" value="<?php echo htmlspecialchars($filter_group); ?>">
                        <input type="hidden" name="filter_barangay" value="<?php echo htmlspecialchars($filter_barangay); ?>">
                        <input type="hidden" name="filter_status" value="<?php echo htmlspecialchars($filter_status); ?>">
                        <select name="month" class="filter-select" onchange="this.form.submit()">
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?php echo $m; ?>" <?php echo $m == $selected_month ? 'selected' : ''; ?>><?php echo date('F', mktime(0,0,0,$m,1)); ?></option>
                            <?php endfor; ?>
                        </select>
                        <select name="year" class="filter-select" onchange="this.form.submit()">
                            <?php for ($y = (int)date('Y'); $y >= (int)date('Y') - 5; $y--): ?>
                                <option value="<?php echo $y; ?>" <?php echo $y == $selected_year ? 'selected' : ''; ?>><?php echo $y; ?></option>
                            <?php endfor; ?>
                        </select>
                    </form>
                <?php elseif ($period === 'quarterly'): ?>
                    <form method="GET" style="display:inline-flex;gap:4px;align-items:center;margin-left:8px;">
                        <input type="hidden" name="view" value="<?php echo $report_view; ?>">
                        <input type="hidden" name="period" value="quarterly">
                        <input type="hidden" name="filter_chapter" value="<?php echo htmlspecialchars($filter_chapter); ?>">
                        <input type="hidden" name="filter_group" value="<?php echo htmlspecialchars($filter_group); ?>">
                        <input type="hidden" name="filter_barangay" value="<?php echo htmlspecialchars($filter_barangay); ?>">
                        <input type="hidden" name="filter_status" value="<?php echo htmlspecialchars($filter_status); ?>">
                        <select name="quarter" class="filter-select" onchange="this.form.submit()">
                            <?php for ($q = 1; $q <= 4; $q++): ?>
                                <option value="<?php echo $q; ?>" <?php echo $q == $selected_quarter ? 'selected' : ''; ?>>Q<?php echo $q; ?></option>
                            <?php endfor; ?>
                        </select>
                        <select name="year" class="filter-select" onchange="this.form.submit()">
                            <?php for ($y = (int)date('Y'); $y >= (int)date('Y') - 5; $y--): ?>
                                <option value="<?php echo $y; ?>" <?php echo $y == $selected_year ? 'selected' : ''; ?>><?php echo $y; ?></option>
                            <?php endfor; ?>
                        </select>
                    </form>
                <?php elseif ($period === 'yearly'): ?>
                    <form method="GET" style="display:inline-flex;gap:4px;align-items:center;margin-left:8px;">
                        <input type="hidden" name="view" value="<?php echo $report_view; ?>">
                        <input type="hidden" name="period" value="yearly">
                        <input type="hidden" name="filter_chapter" value="<?php echo htmlspecialchars($filter_chapter); ?>">
                        <input type="hidden" name="filter_group" value="<?php echo htmlspecialchars($filter_group); ?>">
                        <input type="hidden" name="filter_barangay" value="<?php echo htmlspecialchars($filter_barangay); ?>">
                        <input type="hidden" name="filter_status" value="<?php echo htmlspecialchars($filter_status); ?>">
                        <select name="year" class="filter-select" onchange="this.form.submit()">
                            <?php for ($y = (int)date('Y'); $y >= (int)date('Y') - 5; $y--): ?>
                                <option value="<?php echo $y; ?>" <?php echo $y == $selected_year ? 'selected' : ''; ?>><?php echo $y; ?></option>
                            <?php endfor; ?>
                        </select>
                    </form>
                <?php else: ?>
                    <form method="GET" style="display:inline-flex;gap:4px;align-items:center;margin-left:8px;">
                        <input type="hidden" name="view" value="<?php echo $report_view; ?>">
                        <input type="hidden" name="period" value="custom">
                        <input type="hidden" name="filter_chapter" value="<?php echo htmlspecialchars($filter_chapter); ?>">
                        <input type="hidden" name="filter_group" value="<?php echo htmlspecialchars($filter_group); ?>">
                        <input type="hidden" name="filter_barangay" value="<?php echo htmlspecialchars($filter_barangay); ?>">
                        <input type="hidden" name="filter_status" value="<?php echo htmlspecialchars($filter_status); ?>">
                        <input type="date" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>" class="filter-select" style="width:135px;" onchange="this.form.submit()">
                        <input type="date" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>" class="filter-select" style="width:135px;" onchange="this.form.submit()">
                    </form>
                <?php endif; ?>
            </div>

            <div class="toolbar-row">
                <div class="toolbar-left">
                    <form method="GET" style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;width:100%;">
                        <input type="hidden" name="view" value="<?php echo $report_view; ?>">
                        <input type="hidden" name="period" value="<?php echo $period; ?>">
                        <input type="hidden" name="year" value="<?php echo $selected_year; ?>">
                        <input type="hidden" name="month" value="<?php echo $selected_month; ?>">
                        <input type="hidden" name="quarter" value="<?php echo $selected_quarter; ?>">
                        <input type="hidden" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                        <input type="hidden" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">

                        <select name="filter_chapter" class="filter-select">
                            <option value="">All Chapters</option>
                            <?php foreach ($chapters_list as $c): ?>
                                <option value="<?php echo htmlspecialchars($c); ?>" <?php echo $filter_chapter === $c ? 'selected' : ''; ?>><?php echo htmlspecialchars($c); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="filter_group" class="filter-select">
                            <option value="">All Groups</option>
                            <?php foreach ($groups_list as $g): ?>
                                <option value="<?php echo htmlspecialchars($g); ?>" <?php echo $filter_group === $g ? 'selected' : ''; ?>><?php echo htmlspecialchars($g); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="filter_barangay" class="filter-select">
                            <option value="">All Barangays</option>
                            <?php foreach ($barangays_list as $b): ?>
                                <option value="<?php echo htmlspecialchars($b); ?>" <?php echo $filter_barangay === $b ? 'selected' : ''; ?>><?php echo htmlspecialchars($b); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select name="filter_status" class="filter-select">
                            <option value="">All Status</option>
                            <option value="active" <?php echo $filter_status === 'active' ? 'selected' : ''; ?>>Active</option>
                            <option value="inactive" <?php echo $filter_status === 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                            <option value="deceased" <?php echo $filter_status === 'deceased' ? 'selected' : ''; ?>>Deceased</option>
                        </select>

                        <button type="submit" class="btn-action btn-apply" style="margin-left:auto;"><i class="fas fa-filter"></i> Apply Filters</button>
                        <a href="?view=<?php echo $report_view; ?>&period=<?php echo $period; ?>" class="btn-action btn-clear"><i class="fas fa-times"></i> Clear</a>
                    </form>
                </div>
                <div class="toolbar-right" style="margin-left:auto;">
                    <?php if ($report_view === 'compliance'): ?>
                    <button class="btn-action btn-import" data-bs-toggle="modal" data-bs-target="#importModal"><i class="fas fa-file-import"></i> Import CSV</button>
                    <?php endif; ?>
                    <button class="btn-action btn-export" onclick="exportData('csv')"><i class="fas fa-file-csv"></i> CSV</button>
                    <button class="btn-action btn-print" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
                </div>
            </div>
        </div>

        <div class="content-container">
            <?php if ($import_result): ?>
                <div class="import-result <?php echo (count($import_result['errors']) > 0 ? 'warning' : 'success'); ?>">
                    <div class="result-title"><i class="fas fa-<?php echo count($import_result['errors']) > 0 ? 'exclamation-triangle' : 'check-circle'; ?> me-2"></i>CSV Import Complete</div>
                    <div><span style="margin-right:16px;"><strong><?php echo (int)$import_result['updated']; ?></strong> updated</span><span><strong><?php echo (int)$import_result['skipped']; ?></strong> skipped</span></div>
                    <?php if (!empty($import_result['errors'])): ?>
                        <div class="result-errors">
                            <strong>Issues:</strong>
                            <ul style="margin:4px 0 0 20px;padding:0;">
                                <?php foreach (array_slice($import_result['errors'], 0, 20) as $err): ?>
                                    <li><?php echo htmlspecialchars($err); ?></li>
                                <?php endforeach; ?>
                                <?php if (count($import_result['errors']) > 20): ?><li class="text-muted">…and <?php echo count($import_result['errors']) - 20; ?> more</li><?php endif; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($error_message)): ?>
                <div class="alert alert-danger alert-dismissible fade show"><i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error_message); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
            <?php endif; ?>

            <?php if ($report_view === 'dashboard'): ?>
                <div class="kpi-grid">
                    <div class="kpi-card green">
                        <div class="kpi-icon"><i class="fas fa-peso-sign"></i></div>
                        <div class="kpi-body">
                            <div class="kpi-label">Total Collections</div>
                            <div class="kpi-value">₱<?php echo number_format($total_collections, 2); ?></div>
                            <div class="kpi-sub">For selected period</div>
                        </div>
                    </div>
                    <div class="kpi-card gold">
                        <div class="kpi-icon"><i class="fas fa-calendar-check"></i></div>
                        <div class="kpi-body">
                            <div class="kpi-label">Collection Rate</div>
                            <div class="kpi-value"><?php echo $collection_rate; ?>%</div>
                            <div class="kpi-sub"><?php echo $months_in_range; ?>-month expected basis</div>
                        </div>
                    </div>
                    <div class="kpi-card blue">
                        <div class="kpi-icon"><i class="fas fa-users"></i></div>
                        <div class="kpi-body">
                            <div class="kpi-label">Active Members</div>
                            <div class="kpi-value"><?php echo number_format($total_active_members); ?></div>
                            <div class="kpi-sub"><?php echo $total_inactive_members; ?> inactive, <?php echo $total_deceased_members; ?> deceased</div>
                        </div>
                    </div>
                    <div class="kpi-card red">
                        <div class="kpi-icon"><i class="fas fa-exclamation-triangle"></i></div>
                        <div class="kpi-body">
                            <div class="kpi-label">Overdue Accounts</div>
                            <div class="kpi-value"><?php echo number_format($overdue_accounts); ?></div>
                            <div class="kpi-sub"><?php echo $pending_payments_count; ?> pending payments</div>
                        </div>
                    </div>
                </div>

                <div class="chart-grid">
                    <div class="chart-card" onclick="openModal('collections','Monthly Collections', <?php echo htmlspecialchars(json_encode($monthly_data), ENT_QUOTES, 'UTF-8'); ?>, 'bar')">
                        <div class="chart-title"><span><i class="fas fa-chart-line me-2"></i>Monthly Collections</span><span class="hint">Click for details</span></div>
                        <div class="chart-container"><canvas id="chartCollections"></canvas></div>
                    </div>
                    <div class="chart-card" onclick="openModal('payment_methods','Payment Methods', <?php echo htmlspecialchars(json_encode($payment_methods), ENT_QUOTES, 'UTF-8'); ?>, 'pie')">
                        <div class="chart-title"><span><i class="fas fa-chart-pie me-2"></i>Payment Methods</span><span class="hint">Click for details</span></div>
                        <div class="chart-container"><canvas id="chartMethods"></canvas></div>
                    </div>
                    <div class="chart-card" onclick="openModal('member_growth','Member Growth', <?php echo htmlspecialchars(json_encode($member_growth), ENT_QUOTES, 'UTF-8'); ?>, 'line')">
                        <div class="chart-title"><span><i class="fas fa-user-plus me-2"></i>Member Growth</span><span class="hint">Click for details</span></div>
                        <div class="chart-container"><canvas id="chartGrowth"></canvas></div>
                    </div>
                    <div class="chart-card" onclick="openModal('chapter_perf','Chapter Performance', <?php echo htmlspecialchars(json_encode($chapter_performance), ENT_QUOTES, 'UTF-8'); ?>, 'bar')">
                        <div class="chart-title"><span><i class="fas fa-sitemap me-2"></i>Chapter Performance</span><span class="hint">Click for details</span></div>
                        <div class="chart-container"><canvas id="chartChapterPerf"></canvas></div>
                    </div>
                </div>

                <div class="chart-grid">
                    <div class="chart-card" style="cursor:default;">
                        <div class="chart-title"><span><i class="fas fa-trophy me-2"></i>Top Payers</span><span class="hint"><?php echo count($top_payers); ?> members</span></div>
                        <div class="table-scroll" style="max-height:260px;border:1px solid var(--line);border-radius:8px;">
                            <table class="data-table">
                                <thead><tr><th>Member</th><th>Chapter</th><th class="num">Total Paid</th><th class="num">#</th></tr></thead>
                                <tbody>
                                <?php if (empty($top_payers)): ?>
                                    <tr><td colspan="4"><div class="empty-state"><i class="fas fa-trophy"></i><p>No data yet</p></div></td></tr>
                                <?php else: foreach ($top_payers as $p): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($p['member_name']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($p['chapter'] ?? '—'); ?></td>
                                        <td class="num amount">₱<?php echo number_format((float)$p['total_paid'], 2); ?></td>
                                        <td class="num"><?php echo (int)$p['payment_count']; ?></td>
                                    </tr>
                                <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="chart-card" style="cursor:default;">
                        <div class="chart-title"><span><i class="fas fa-clock me-2"></i>Aging Report</span><span class="hint"><?php echo count($aging_report); ?> overdue</span></div>
                        <div class="table-scroll" style="max-height:260px;border:1px solid var(--line);border-radius:8px;">
                            <table class="data-table">
                                <thead><tr><th>Member</th><th>Chapter</th><th class="num">Months</th><th class="num">Balance</th></tr></thead>
                                <tbody>
                                <?php if (empty($aging_report)): ?>
                                    <tr><td colspan="4"><div class="empty-state"><i class="fas fa-check-circle"></i><p>No overdue accounts</p></div></td></tr>
                                <?php else: foreach ($aging_report as $a): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($a['member_name']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($a['chapter'] ?? '—'); ?></td>
                                        <td class="num"><span class="chip <?php echo (int)$a['months_overdue'] >= 3 ? 'chip-danger' : 'chip-warn'; ?>"><?php echo (int)$a['months_overdue']; ?> mo</span></td>
                                        <td class="num amount danger">₱<?php echo number_format((float)$a['balance_due'], 2); ?></td>
                                    </tr>
                                <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="chart-grid">
                    <div class="chart-card" style="cursor:default;">
                        <div class="chart-title"><span><i class="fas fa-calendar-plus me-2"></i>New Members (<?php echo ucfirst($period); ?>)</span><span class="hint"><?php echo count($new_members); ?> periods</span></div>
                        <div class="table-scroll" style="max-height:260px;border:1px solid var(--line);border-radius:8px;">
                            <table class="data-table">
                                <thead><tr><th>Period</th><th class="num">New Members</th></tr></thead>
                                <tbody>
                                <?php if (empty($new_members)): ?>
                                    <tr><td colspan="2"><div class="empty-state"><i class="fas fa-calendar"></i><p>No data</p></div></td></tr>
                                <?php else: foreach ($new_members as $n): ?>
                                    <tr><td><?php echo htmlspecialchars($n['period']); ?></td><td class="num"><?php echo (int)$n['count']; ?></td></tr>
                                <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="chart-card" style="cursor:default;">
                        <div class="chart-title"><span><i class="fas fa-user-slash me-2"></i>Inactive / Deceased</span><span class="hint"><?php echo count($inactive_members); ?> members</span></div>
                        <div class="table-scroll" style="max-height:260px;border:1px solid var(--line);border-radius:8px;">
                            <table class="data-table">
                                <thead><tr><th>Member</th><th>Chapter</th><th>Status</th><th>Reason</th></tr></thead>
                                <tbody>
                                <?php if (empty($inactive_members)): ?>
                                    <tr><td colspan="4"><div class="empty-state"><i class="fas fa-users"></i><p>No inactive members</p></div></td></tr>
                                <?php else: foreach ($inactive_members as $im): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($im['member_name']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($im['chapter'] ?? '—'); ?></td>
                                        <td><span class="chip <?php echo $im['status'] === 'deceased' ? 'chip-dim' : 'chip-warn'; ?>"><?php echo ucfirst($im['status']); ?></span></td>
                                        <td><small><?php echo htmlspecialchars($im['inactive_reason'] ?? '—'); ?></small></td>
                                    </tr>
                                <?php endforeach; endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div class="content-card">
                    <div class="content-card-header">
                        <h5 class="content-card-title"><i class="fas fa-heart"></i>Beneficiary Report (Snapshot)</h5>
                        <span class="text-muted small"><?php echo count($beneficiary_report); ?> records</span>
                    </div>
                    <div class="table-scroll" style="max-height:320px;">
                        <table class="data-table">
                            <thead><tr><th>Member</th><th>Chapter</th><th>Beneficiary</th><th>Relation</th><th>Age</th><th>Contact</th></tr></thead>
                            <tbody>
                            <?php if (empty($beneficiary_report)): ?>
                                <tr><td colspan="6"><div class="empty-state"><i class="fas fa-heart"></i><p>No beneficiaries recorded</p></div></td></tr>
                            <?php else: foreach ($beneficiary_report as $b): ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($b['member_name']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($b['chapter'] ?? '—'); ?></td>
                                    <td><?php echo htmlspecialchars($b['beneficiary_name']); ?></td>
                                    <td><?php echo htmlspecialchars($b['beneficiary_relation']); ?></td>
                                    <td class="num"><?php echo (int)($b['beneficiary_age'] ?: 0); ?></td>
                                    <td><small><?php echo htmlspecialchars($b['beneficiary_contact'] ?? '—'); ?></small></td>
                                </tr>
                            <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            <?php else: ?>
                <div class="content-card">
                    <div class="content-card-header">
                        <h5 class="content-card-title"><i class="fas fa-file-alt"></i><?php echo htmlspecialchars($report_title); ?></h5>
                        <span class="text-muted small"><i class="fas fa-database me-1"></i><?php echo number_format($total_records); ?> records</span>
                    </div>

                    <?php if (empty($report_data)): ?>
                        <div class="empty-state"><i class="fas fa-inbox"></i><p>No data available for the selected filters.</p></div>
                    <?php else: ?>
                        <div class="table-scroll" style="max-height:520px;border:none;border-top:1px solid var(--line);">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <?php foreach ($report_columns as $col): ?>
                                            <th<?php echo in_array(strtolower($col), ['total amount','amount','balance','balance due','total collected','total paid','avg amount','contribution','monthly_contribution','transactions','members','count']) ? ' class="num"' : ''; ?>><?php echo $col; ?></th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($report_data as $row): ?>
                                    <tr>
                                        <?php foreach (array_values($row) as $i => $value): ?>
                                            <?php
                                                $col_name = $report_columns[$i] ?? '';
                                                $is_num = preg_match('/amount|balance|contribution|total|rate|count|months|age/i', $col_name);
                                                $is_amount = preg_match('/amount|balance|total paid|total collected/i', $col_name);
                                            ?>
                                            <td<?php echo $is_num ? ' class="num' . ($is_amount ? ' amount' : '') . '"' : ''; ?>>
                                                <?php
                                                if ($is_amount) {
                                                    echo '₱' . number_format((float)$value, 2);
                                                } elseif (preg_match('/rate/i', $col_name)) {
                                                    echo number_format((float)$value, 1) . '%';
                                                } elseif (preg_match('/compliance|status/i', $col_name)) {
                                                    $cls = stripos((string)$value, 'compliant') !== false && stripos((string)$value, 'non') === false
                                                        ? 'chip-ok'
                                                        : (stripos((string)$value, 'has') !== false ? 'chip-info' : 'chip-warn');
                                                    echo '<span class="chip ' . $cls . '">' . htmlspecialchars((string)$value) . '</span>';
                                                } elseif (preg_match('/beneficiary status/i', $col_name)) {
                                                    echo '<span class="chip ' . (stripos((string)$value, 'has') !== false ? 'chip-ok' : 'chip-dim') . '">' . htmlspecialchars((string)$value) . '</span>';
                                                } else {
                                                    echo htmlspecialchars((string)$value);
                                                }
                                                ?>
                                            </td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if ($total_pages > 1): ?>
                        <div class="pagination-wrapper">
                            <div class="pagination-info">Page <?php echo $page; ?> of <?php echo $total_pages; ?> (<?php echo number_format($total_records); ?> records)</div>
                            <ul class="pagination">
                                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>"><a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>">&laquo;</a></li>
                                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>"><a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">&lsaquo;</a></li>
                                <?php
                                $start = max(1, $page - 2);
                                $end = min($total_pages, $page + 2);
                                for ($i = $start; $i <= $end; $i++):
                                ?>
                                    <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>"><a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"><?php echo $i; ?></a></li>
                                <?php endfor; ?>
                                <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>"><a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">&rsaquo;</a></li>
                                <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>"><a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>">&raquo;</a></li>
                            </ul>
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- Details Modal -->
<div class="modal fade" id="detailModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title" id="modalTitle">Details</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body" id="modalBody"><div class="text-center py-4"><div class="spinner-border text-dark"></div></div></div>
        </div>
    </div>
</div>

<!-- Import Modal -->
<div class="modal fade" id="importModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data" id="importForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="import_compliance_csv">
                <div class="modal-header"><h5 class="modal-title"><i class="fas fa-file-import me-2"></i>Import Compliance CSV</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <div class="alert alert-info mb-3"><i class="fas fa-info-circle me-2"></i><strong>How it works:</strong> Upload a CSV with a <code>member_code</code> column. Supported columns: chapter, group_name, barangay, status, date_joined, monthly_contribution, beneficiary_name, beneficiary_relation, beneficiary_age, beneficiary_contact, contact_number, email.</div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted text-uppercase">Supported Columns</label>
                        <div style="max-height:200px;overflow-y:auto;border:1px solid var(--line);border-radius:8px;">
                            <table class="csv-format-table data-table"><thead><tr><th>Column</th><th>Required?</th><th>Example</th></tr></thead><tbody>
                                <tr><td><code>member_code</code></td><td><span class="chip chip-danger">Required</span></td><td>M0001</td></tr>
                                <tr><td><code>chapter</code></td><td><span class="chip chip-dim">Optional</span></td><td>NATIVIDAD</td></tr>
                                <tr><td><code>group_name</code></td><td><span class="chip chip-dim">Optional</span></td><td>Group A</td></tr>
                                <tr><td><code>barangay</code></td><td><span class="chip chip-dim">Optional</span></td><td>Singalat</td></tr>
                                <tr><td><code>status</code></td><td><span class="chip chip-dim">Optional</span></td><td>active</td></tr>
                                <tr><td><code>date_joined</code></td><td><span class="chip chip-dim">Optional</span></td><td>2023-01-15</td></tr>
                                <tr><td><code>monthly_contribution</code></td><td><span class="chip chip-dim">Optional</span></td><td>100.00</td></tr>
                                <tr><td><code>beneficiary_name</code></td><td><span class="chip chip-dim">Optional</span></td><td>Maria Santos</td></tr>
                                <tr><td><code>beneficiary_relation</code></td><td><span class="chip chip-dim">Optional</span></td><td>Spouse</td></tr>
                                <tr><td><code>beneficiary_age</code></td><td><span class="chip chip-dim">Optional</span></td><td>45</td></tr>
                                <tr><td><code>beneficiary_contact</code></td><td><span class="chip chip-dim">Optional</span></td><td>09171234567</td></tr>
                                <tr><td><code>contact_number</code></td><td><span class="chip chip-dim">Optional</span></td><td>09181234567</td></tr>
                                <tr><td><code>email</code></td><td><span class="chip chip-dim">Optional</span></td><td>juan@example.com</td></tr>
                            </tbody></table>
                        </div>
                    </div>
                    <div class="import-dropzone" id="importDropzone">
                        <input type="file" name="csv_file" id="csvFileInput" accept=".csv" hidden required>
                        <i class="fas fa-cloud-upload-alt big-icon"></i>
                        <div class="dz-title">Click to choose a CSV file</div>
                        <div class="dz-sub">or drag &amp; drop · Max 5 MB</div>
                        <div class="file-name" id="importFileName" style="display:none;"></div>
                    </div>
                    <div class="text-end mt-2"><a href="?download_sample_csv=compliance" class="small text-decoration-none"><i class="fas fa-download me-1"></i>Download sample CSV template</a></div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button><button type="submit" class="btn btn-primary" id="importSubmitBtn"><i class="fas fa-upload me-1"></i>Import CSV</button></div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
/* Sidebar */
const sidebar = document.getElementById('sidebar-wrapper');
const menuToggle = document.getElementById('menuToggle');
const mobileToggle = document.getElementById('mobileMenuToggle');
const overlay = document.getElementById('sidebarOverlay');

if (localStorage.getItem('sidebarCollapsed') === 'true') sidebar.classList.add('collapsed');
requestAnimationFrame(() => requestAnimationFrame(() => {
    document.documentElement.classList.remove('sidebar-was-collapsed');
    document.documentElement.classList.add('sidebar-transitions-on');
}));

if (menuToggle) menuToggle.addEventListener('click', () => {
    sidebar.classList.toggle('collapsed');
    localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
});
if (mobileToggle) mobileToggle.addEventListener('click', () => {
    sidebar.classList.toggle('mobile-open');
    overlay.classList.toggle('active');
});
if (overlay) overlay.addEventListener('click', () => {
    sidebar.classList.remove('mobile-open');
    overlay.classList.remove('active');
});

/* Export */
function exportData(format) {
    const url = new URL(window.location.href);
    url.searchParams.set('export', format);
    window.location.href = url.toString();
}

/* Charts — Dashboard */
<?php if ($report_view === 'dashboard'): ?>
const monthlyData = <?php echo json_encode($monthly_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
const paymentMethods = <?php echo json_encode($payment_methods, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
const memberGrowth = <?php echo json_encode($member_growth, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
const chapterPerf = <?php echo json_encode($chapter_performance, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

new Chart(document.getElementById('chartCollections'), {
    type: 'bar',
    data: {
        labels: monthlyData.map(d => d.month),
        datasets: [{
            label: 'Collections (₱)',
            data: monthlyData.map(d => d.total),
            backgroundColor: 'rgba(28,28,29,0.75)',
            borderColor: '#1c1c1d',
            borderWidth: 1,
            borderRadius: 4
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true, ticks: { callback: v => '₱' + (v/1000).toFixed(0) + 'k' } } }
    }
});

new Chart(document.getElementById('chartMethods'), {
    type: 'doughnut',
    data: {
        labels: paymentMethods.map(m => (m.payment_method || 'Other').toUpperCase()),
        datasets: [{
            data: paymentMethods.map(m => parseFloat(m.total || 0)),
            backgroundColor: ['#28a745','#17a2b8','#ffc107','#dc3545','#6c757d'],
            borderWidth: 0
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 10 } } } }
    }
});

new Chart(document.getElementById('chartGrowth'), {
    type: 'line',
    data: {
        labels: memberGrowth.map(d => d.month),
        datasets: [{
            label: 'Members',
            data: memberGrowth.map(d => d.count),
            borderColor: '#28a745',
            backgroundColor: 'rgba(40,167,69,0.1)',
            fill: true,
            tension: 0.4,
            pointBackgroundColor: '#28a745',
            pointRadius: 3
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true } }
    }
});

new Chart(document.getElementById('chartChapterPerf'), {
    type: 'bar',
    data: {
        labels: chapterPerf.map(c => c.chapter || 'Unknown'),
        datasets: [{
            label: 'Collection Rate (%)',
            data: chapterPerf.map(c => parseFloat(c.collection_rate || 0)),
            backgroundColor: 'rgba(23,162,184,0.7)',
            borderColor: '#17a2b8',
            borderWidth: 1,
            borderRadius: 4
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        indexAxis: 'y',
        plugins: { legend: { display: false } },
        scales: { x: { beginAtZero: true, ticks: { callback: v => v + '%' } } }
    }
});
<?php endif; ?>

/* Details Modal */
function openModal(type, title, data, chartType) {
    const modal = new bootstrap.Modal(document.getElementById('detailModal'));
    document.getElementById('modalTitle').textContent = title;

    let html = '';
    if (chartType === 'pie' && type === 'payment_methods') {
        html = '<div class="chart-container-modal"><canvas id="modalChart"></canvas></div>';
    } else if (type === 'collections' || type === 'member_growth' || type === 'chapter_perf') {
        html = '<div class="chart-container-modal"><canvas id="modalChart"></canvas></div>';
    } else {
        if (!data || !data.length) {
            html = '<div class="empty-state"><i class="fas fa-inbox"></i><p>No records</p></div>';
        } else {
            html = '<div class="table-responsive" style="max-height:400px;overflow-y:auto;"><table class="data-table"><thead><tr>';
            Object.keys(data[0]).forEach(k => html += `<th>${k.replace(/_/g,' ').toUpperCase()}</th>`);
            html += '</tr></thead><tbody>';
            data.forEach(row => {
                html += '<tr>';
                Object.values(row).forEach(v => {
                    html += `<td>${(typeof v === 'number' && v > 1000) ? '₱' + Number(v).toLocaleString() : (v ?? '—')}</td>`;
                });
                html += '</tr>';
            });
            html += '</tbody></table></div>';
        }
    }

    document.getElementById('modalBody').innerHTML = html;
    modal.show();

    if (chartType === 'pie') {
        setTimeout(() => {
            const ctx = document.getElementById('modalChart');
            if (!ctx) return;
            new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: data.map(d => (d.payment_method || 'Other').toUpperCase()),
                    datasets: [{ data: data.map(d => parseFloat(d.total || 0)), backgroundColor: ['#28a745','#17a2b8','#ffc107','#dc3545','#6c757d'], borderWidth: 0 }]
                },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
            });
        }, 200);
    } else if (type === 'collections') {
        setTimeout(() => {
            const ctx = document.getElementById('modalChart');
            if (!ctx) return;
            new Chart(ctx, {
                type: 'bar',
                data: { labels: data.map(d => d.month), datasets: [{ label: 'Collections', data: data.map(d => d.total), backgroundColor: 'rgba(28,28,29,0.7)', borderColor: '#1c1c1d', borderWidth: 1, borderRadius: 4 }] },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
            });
        }, 200);
    } else if (type === 'member_growth') {
        setTimeout(() => {
            const ctx = document.getElementById('modalChart');
            if (!ctx) return;
            new Chart(ctx, {
                type: 'line',
                data: { labels: data.map(d => d.month), datasets: [{ label: 'Members', data: data.map(d => d.count), borderColor: '#28a745', backgroundColor: 'rgba(40,167,69,0.1)', fill: true, tension: 0.4, pointRadius: 3 }] },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
            });
        }, 200);
    } else if (type === 'chapter_perf') {
        setTimeout(() => {
            const ctx = document.getElementById('modalChart');
            if (!ctx) return;
            new Chart(ctx, {
                type: 'bar',
                data: { labels: data.map(d => d.chapter || 'Unknown'), datasets: [{ label: 'Collection Rate (%)', data: data.map(d => parseFloat(d.collection_rate || 0)), backgroundColor: 'rgba(23,162,184,0.7)', borderColor: '#17a2b8', borderWidth: 1, borderRadius: 4 }] },
                options: { responsive: true, maintainAspectRatio: false, indexAxis: 'y', plugins: { legend: { display: false } }, scales: { x: { beginAtZero: true, ticks: { callback: v => v + '%' } } } }
            });
        }, 200);
    }
}

/* Import dropzone */
(function() {
    const dropzone = document.getElementById('importDropzone');
    const input    = document.getElementById('csvFileInput');
    const label    = document.getElementById('importFileName');
    const form     = document.getElementById('importForm');
    const submitBtn = document.getElementById('importSubmitBtn');

    if (!dropzone || !input) return;

    dropzone.addEventListener('click', () => input.click());
    input.addEventListener('change', function() {
        if (this.files && this.files[0]) {
            label.textContent = '📄 ' + this.files[0].name;
            label.style.display = 'block';
        }
    });

    ['dragenter','dragover'].forEach(evt => dropzone.addEventListener(evt, e => { e.preventDefault(); dropzone.classList.add('dragover'); }));
    ['dragleave','drop'].forEach(evt => dropzone.addEventListener(evt, e => { e.preventDefault(); dropzone.classList.remove('dragover'); }));
    dropzone.addEventListener('drop', e => {
        if (e.dataTransfer.files && e.dataTransfer.files[0]) {
            input.files = e.dataTransfer.files;
            label.textContent = '📄 ' + e.dataTransfer.files[0].name;
            label.style.display = 'block';
        }
    });

    form.addEventListener('submit', function() {
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Importing...';
    });
})();

console.log('✅ Reports page loaded');
</script>
</body>
</html>