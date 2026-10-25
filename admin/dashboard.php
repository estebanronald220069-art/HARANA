<?php
// admin/dashboard.php
require_once '../includes/logger.php';
Log::info('system', 'PAGE_VIEW', 'Dashboard opened');
require_once '../includes/config.php';  
require_once '../includes/db_connection.php';
require_once '../includes/security.php';
require_once '../includes/auth.php';
require_once 'includes/functions.php';

$auth->requireLogin();
$current_user = $auth->getCurrentUser();

$db = Database::getInstance();

// ------------------------------------------------------------
// View switcher: 'monitoring' (default) | 'analytics'
// ------------------------------------------------------------
$view = isset($_GET['view']) ? Security::sanitize($_GET['view']) : 'monitoring';
if (!in_array($view, ['monitoring', 'analytics'])) {
    $view = 'monitoring';
}

// ------------------------------------------------------------
// Monitoring Period (YYYY-MM) — default current month, future blocked
// ------------------------------------------------------------
$today_ym = date('Y-m');
$period = isset($_GET['period']) ? Security::sanitize($_GET['period']) : $today_ym;
if (!preg_match('/^\d{4}-\d{2}$/', $period)) $period = $today_ym;
if ($period > $today_ym) $period = $today_ym;
if ($period < '2020-01') $period = '2020-01';
list($view_year, $view_month) = array_map('intval', explode('-', $period));
$view_end_date = date('Y-m-t', strtotime($period . '-01'));

// Get pending users count (for sidebar badge)
$pending_count = 0;
if ($current_user['role'] === 'admin') {
    $pending_count = $db->getSingle("SELECT COUNT(*) as cnt FROM pending_users WHERE status = 'pending'")['cnt'] ?? 0;
}

// Sidebar badge: groups count
$group_count = $db->getSingle("SELECT COUNT(*) AS cnt FROM groups")['cnt'] ?? 0;

// ============================================================
// SEARCH & FILTER (used by monitoring view only)
// ============================================================
$search = isset($_GET['search']) ? Security::sanitize($_GET['search']) : '';
$filter = isset($_GET['filter']) ? Security::sanitize($_GET['filter']) : 'all';
if (!in_array($filter, ['all', 'paid', 'unpaid', 'advanced', 'overdue'])) $filter = 'all';

$per_page = isset($_GET['per_page']) ? intval($_GET['per_page']) : 25;
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;

$allowed_per_page = [10, 25, 50, 100];
if (!in_array($per_page, $allowed_per_page)) {
    $per_page = 25;
}

// ---- Additional filters (mirrors members.php) ----
$filter_name     = isset($_GET['filter_name'])     ? Security::sanitize($_GET['filter_name'])     : '';
$filter_barangay = isset($_GET['filter_barangay']) ? Security::sanitize($_GET['filter_barangay']) : '';
$filter_chapter  = isset($_GET['filter_chapter'])  ? Security::sanitize($_GET['filter_chapter'])  : '';
$filter_group    = isset($_GET['filter_group'])    ? Security::sanitize($_GET['filter_group'])    : '';

// [FIX] Historical Active Filter: Restricts the dashboard to members who were active ON the viewed period, not today.
$where_conditions = [
    "m.date_joined <= ?",
    "NOT EXISTS (
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
    )",
    "NOT (
        EXISTS (
            SELECT 1 FROM deceased_details dd
            WHERE dd.member_code = m.member_code
              AND dd.death_date <= ?
        )
        OR (
            m.status = 'deceased'
            AND NOT EXISTS (
                SELECT 1 FROM deceased_details dd
                WHERE dd.member_code = m.member_code
            )
            AND DATE(m.updated_at) <= ?
        )
    )"
];
$params = [
    $view_end_date,
    $view_end_date . ' 23:59:59',
    $view_end_date . ' 23:59:59',
    $view_end_date,
    $view_end_date
];
$types  = 'sssss';

if (!empty($search)) {
    $where_conditions[] = "(m.first_name LIKE ? OR m.last_name LIKE ? OR m.member_code LIKE ? OR m.email LIKE ? OR m.contact_number LIKE ? OR m.chapter LIKE ?)";
    $search_param = "%$search%";
    $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param, $search_param, $search_param]);
    $types .= 'ssssss';
}

if (!empty($filter_name)) {
    $where_conditions[] = "(m.first_name LIKE ? OR m.last_name LIKE ? OR CONCAT(m.first_name, ' ', m.last_name) LIKE ?)";
    $like_name = "%$filter_name%";
    $params[] = $like_name;
    $params[] = $like_name;
    $params[] = $like_name;
    $types .= 'sss';
}

if (!empty($filter_barangay)) {
    $where_conditions[] = "m.barangay = ?";
    $params[] = $filter_barangay;
    $types .= 's';
}

if (!empty($filter_chapter)) {
    $where_conditions[] = "m.chapter = ?";
    $params[] = $filter_chapter;
    $types .= 's';
}

if (!empty($filter_group)) {
    $where_conditions[] = "m.group_name = ?";
    $params[] = $filter_group;
    $types .= 's';
}

$where_clause = implode(' AND ', $where_conditions);

// ---- Fetch ALL matching members (no LIMIT) so we can bucket, then paginate ----
$all_members = $db->getAll("
    SELECT 
        m.member_code,
        m.first_name,
        m.last_name,
        m.email,
        m.contact_number as phone,
        m.status as member_status,
        m.created_at,
        m.monthly_contribution,
        m.date_joined,
        m.chapter,
        m.province,
        m.city,
        m.barangay
    FROM members m
    WHERE $where_clause
    ORDER BY m.last_name ASC, m.first_name ASC
", $params, $types);

// ---- Bulk compute statuses for the selected period ----
$status_map = getMonitoringStatusForMembers($db, $all_members, $view_year, $view_month);


// [FIX] Removed the hacky "Advanced Override" loop. 
// The status_map is now calculated mathematically inside getMonitoringStatusForMembers() using Net Balance.

$buckets = ['paid' => [], 'unpaid' => [], 'advanced' => [], 'overdue' => []];
foreach ($all_members as $m) {
    $st = $status_map[$m['member_code']]['status'] ?? 'unpaid';
    $m['_status'] = $st;
    if (!isset($buckets[$st])) $buckets[$st] = [];
    $buckets[$st][] = $m;
}
$counts = [
    'all'      => count($all_members),
    'paid'     => count($buckets['paid']),
    'unpaid'   => count($buckets['unpaid']),
    'advanced' => count($buckets['advanced']),
    'overdue'  => count($buckets['overdue']),
];

// ---- Apply tab filter + paginate ----
$filtered_members = $filter === 'all' ? $all_members : ($buckets[$filter] ?? []);
$total_members = count($filtered_members);
$total_pages   = max(1, (int)ceil($total_members / $per_page));
if ($page > $total_pages) $page = $total_pages;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $per_page;
$page_members = array_slice($filtered_members, $offset, $per_page);

// Legacy vars (kept for the template)
$paid_count     = $counts['paid'];
$unpaid_count   = $counts['unpaid'];
$advanced_count = $counts['advanced'];
$overdue_count  = $counts['overdue'];

// ---- Filter option lists (only compute when monitoring view is active) ----
$filter_options_barangay = [];
$filter_options_chapter  = [];
$filter_options_group    = [];

if ($view === 'monitoring') {
    $rows = $db->getAll("
        SELECT DISTINCT barangay FROM members
        WHERE status = 'active' AND barangay IS NOT NULL AND barangay != ''
        ORDER BY barangay ASC
    ");
    $filter_options_barangay = array_column($rows, 'barangay');

    $rows = $db->getAll("
        SELECT DISTINCT chapter FROM members
        WHERE status = 'active' AND chapter IS NOT NULL AND chapter != ''
        ORDER BY chapter ASC
    ");
    $filter_options_chapter = array_column($rows, 'chapter');

    $rows = $db->getAll("
        SELECT DISTINCT group_name FROM members
        WHERE status = 'active' AND group_name IS NOT NULL AND group_name != ''
        ORDER BY group_name ASC
    ");
    $filter_options_group = array_column($rows, 'group_name');
}

// ============================================================
// ANALYTICS HELPERS  (claim & contribution computation)
// ============================================================
if (!function_exists('computeContributionYears')) {
    function computeContributionYears($date_joined) {
        if (empty($date_joined)) return 0;
        try {
            $joined = new DateTime($date_joined);
            $now    = new DateTime();
            if ($joined > $now) return 0;
            return (int)$joined->diff($now)->y;
        } catch (Exception $e) {
            return 0;
        }
    }
}

if (!function_exists('getClaimAmount')) {
    function getClaimAmount($years) {
        if ($years < 1)   return 0;
        if ($years <= 2)  return 80000;
        if ($years <= 4)  return 90000;
        if ($years <= 6)  return 100000;
        if ($years <= 8)  return 110000;
        if ($years == 9)  return 115000;
        if ($years == 10) return 120000;
        if ($years == 11) return 125000;
        if ($years == 12) return 130000;
        if ($years == 13) return 135000;
        if ($years == 14) return 140000;
        if ($years == 15) return 145000;
        return 150000;
    }
}

if (!function_exists('getMemberMissedPayments')) {
    function getMemberMissedPayments($db, $member) {
        if (empty($member['date_joined'])) return [[], 0, 0];

        try {
            $joined = new DateTime($member['date_joined']);
        } catch (Exception $e) {
            return [[], 0, 0];
        }

                $joined->modify('first day of this month');
        $now = new DateTime();
        $now->modify('first day of this month');

        // [FIX] Only count rows whose period_month is a valid YYYY-MM.
        // No COALESCE fallback — corrupted rows are excluded entirely.
        $paid_rows = $db->getAll("
            SELECT DISTINCT period_month AS ym
            FROM payments
                       WHERE member_code = ? AND payment_status = 'confirmed'
              AND period_month REGEXP '^[0-9]{4}-[0-9]{2}$'
        ", [$member['member_code']], 's');

        $paid_set = [];
        foreach ($paid_rows as $p) { $paid_set[$p['ym']] = true; }

        $missed     = [];
        $cursor     = clone $joined;
        $expected   = 0;
        $paid_count = 0;

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

        return [$missed, $paid_count, $expected];
    }
}

// ============================================================
// ANALYTICS DATA  (CHAPTER → GROUP → MEMBER drill-down)
// ============================================================
$chapters_list         = [];
$selected_chapter      = null;
$groups_list           = [];
$selected_group        = null;
$group_members         = [];
$selected_member       = null;
$member_missed         = [];
$member_paid_count     = 0;
$member_expected_count = 0;

if ($view === 'analytics') {
    $chapter_param = isset($_GET['chapter']) ? Security::sanitize($_GET['chapter']) : '';
    $group_param   = isset($_GET['group'])   ? Security::sanitize($_GET['group'])   : '';
    $member_param  = isset($_GET['member'])  ? Security::sanitize($_GET['member'])  : '';

    // ---- LEVEL 1: Chapters ----
    $chapters_list = $db->getAll("
        SELECT m.chapter AS name,
               COUNT(DISTINCT CASE WHEN m.group_name IS NOT NULL AND m.group_name != ''
                                   THEN m.group_name END) AS group_count,
               COUNT(*) AS member_count
        FROM members m
        WHERE m.status = 'active'
          AND m.chapter IS NOT NULL
          AND m.chapter != ''
        GROUP BY m.chapter
        ORDER BY m.chapter ASC
    ");

    if (!empty($chapter_param)) {
        $selected_chapter = $db->getSingle("
            SELECT m.chapter AS name, COUNT(*) AS member_count
            FROM members m
            WHERE m.status = 'active' AND m.chapter = ?
            GROUP BY m.chapter
        ", [$chapter_param], 's');

        if ($selected_chapter) {
            // ---- LEVEL 2: Groups inside this chapter ----
            $groups_list = $db->getAll("
                SELECT m.group_name AS name, COUNT(*) AS member_count
                FROM members m
                WHERE m.status = 'active'
                  AND m.chapter = ?
                  AND m.group_name IS NOT NULL
                  AND m.group_name != ''
                GROUP BY m.group_name
                ORDER BY m.group_name ASC
            ", [$chapter_param], 's');

            if (!empty($group_param)) {
                $selected_group = $db->getSingle("
                    SELECT m.group_name AS name, COUNT(*) AS member_count
                    FROM members m
                    WHERE m.status = 'active'
                      AND m.chapter = ?
                      AND m.group_name = ?
                    GROUP BY m.group_name
                ", [$chapter_param, $group_param], 'ss');

                if ($selected_group) {
                    // ---- LEVEL 3: Members inside this group ----
                    $group_members = $db->getAll("
                        SELECT m.member_code, m.first_name, m.last_name, m.email,
                               m.contact_number, m.date_joined, m.monthly_contribution,
                               m.status, m.chapter, m.barangay, m.group_name
                        FROM members m
                        WHERE m.chapter = ? AND m.group_name = ? AND m.status = 'active'
                        ORDER BY m.last_name ASC, m.first_name ASC
                    ", [$chapter_param, $group_param], 'ss');

                    foreach ($group_members as &$gm) {
                        $years = computeContributionYears($gm['date_joined']);
                        $gm['years'] = $years;
                        $gm['claim'] = getClaimAmount($years);
                    }
                    unset($gm);

                    // ---- LEVEL 4: Selected member detail ----
                    if (!empty($member_param)) {
                        foreach ($group_members as $gm) {
                            if ($gm['member_code'] === $member_param) {
                                $selected_member = $gm;
                                break;
                            }
                        }

                        if ($selected_member) {
                            list($member_missed, $member_paid_count, $member_expected_count)
                                = getMemberMissedPayments($db, $selected_member);
                        }
                    }
                }
            }
        }
    }
}

// ---- Helpers ----
if (!function_exists('timeAgo')) {
    function timeAgo($datetime) {
        $ts   = strtotime($datetime);
        $diff = time() - $ts;
        if ($diff < 60)       return 'just now';
        if ($diff < 3600)     return floor($diff / 60) . ' min ago';
        if ($diff < 86400)    return floor($diff / 3600) . ' hr ago';
        if ($diff < 604800)   return floor($diff / 86400) . ' day' . (floor($diff / 86400) > 1 ? 's' : '') . ' ago';
        return date('M j, Y', $ts);
    }
}

if (!function_exists('activityIcon')) {
    function activityIcon($action) {
        $map = [
            'LOGIN' => 'sign-in-alt', 'LOGOUT' => 'sign-out-alt', 'MEMBER_ADD' => 'user-plus',
            'MEMBER_UPDATE' => 'user-edit', 'MEMBER_DELETE' => 'user-minus', 'MEMBER_RECOVER' => 'user-check',
            'MEMBER_INACTIVE' => 'user-slash', 'MEMBER_REACTIVATE' => 'user-check', 'MEMBER_REVERT' => 'undo-alt',
            'PAYMENT' => 'credit-card', 'USER_APPROVED' => 'check-circle', 'USER_REJECTED' => 'times-circle',
            'PHOTO' => 'camera', 'COUNCIL' => 'user-tie', 'ANNOUNCEMENT' => 'bullhorn',
            'CHAPTER' => 'sitemap', 'GROUP' => 'layer-group', 'REGISTRATION' => 'file-signature',
        ];
        foreach ($map as $needle => $icon) {
            if (stripos($action, $needle) !== false) return $icon;
        }
        return 'circle';
    }
}

$csrf_token = Security::generateCSRFToken();

function buildPaginationUrl($page, $filter, $search, $per_page, $view, $period = '', $extra = []) {
    $base = [
        'view'     => $view,
        'period'   => $period,
        'page'     => $page,
        'filter'   => $filter,
        'search'   => $search,
        'per_page' => $per_page
    ];
    $merged = array_merge($base, array_filter($extra, function($v) {
        return $v !== '' && $v !== null;
    }));
    return '?' . http_build_query($merged);
}

function buildViewSwitchUrl($new_view) {
    if ($new_view === 'analytics') {
        return '?view=analytics';
    }
    $params = $_GET;
    $params['view'] = $new_view;
    return '?' . http_build_query($params);
}

function buildAnalyticsChapterUrl($chapter) {
    return '?' . http_build_query(['view' => 'analytics', 'chapter' => $chapter]);
}
function buildAnalyticsGroupUrl($chapter, $group) {
    return '?' . http_build_query(['view' => 'analytics', 'chapter' => $chapter, 'group' => $group]);
}
function buildAnalyticsMemberUrl($chapter, $group, $member_code) {
    return '?' . http_build_query(['view' => 'analytics', 'chapter' => $chapter, 'group' => $group, 'member' => $member_code]);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

    <script>
        (function() {
            try {
                if (localStorage.getItem('sidebarCollapsed') === 'true') {
                    document.documentElement.classList.add('sidebar-was-collapsed');
                }
            } catch (e) {}
        })();
    </script>

    <style>
        * { box-sizing: border-box; }
        body { background: #f0f2f5; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; overflow: hidden; margin: 0; padding: 0; height: 100vh; }
        #wrapper { display: flex; width: 100%; height: 100vh; overflow: hidden; }
        
        /* ===== SIDEBAR ===== */
        #sidebar-wrapper { background: #1c1c1d; color: #fff; width: 250px; height: 100vh; overflow-y: auto; transition: width 0.3s ease; box-shadow: 4px 0 10px rgba(0,0,0,0.1); white-space: nowrap; flex-shrink: 0; position: relative; z-index: 1000; }
        html.sidebar-was-collapsed #sidebar-wrapper { width: 70px; }
        html.sidebar-was-collapsed #sidebar-wrapper .sidebar-heading .brand-text, html.sidebar-was-collapsed #sidebar-wrapper .sidebar-heading .sidebar-logo, html.sidebar-was-collapsed #sidebar-wrapper .list-group-item span, html.sidebar-was-collapsed #sidebar-wrapper .badge-count { display: none; }
        html.sidebar-was-collapsed #sidebar-wrapper .list-group-item { text-align: center; padding: 15px 0; justify-content: center; }
        html.sidebar-was-collapsed #sidebar-wrapper .list-group-item i { margin-right: 0; width: 100%; text-align: center; font-size: 1.2rem; }
        html.sidebar-was-collapsed #sidebar-wrapper .sidebar-heading { justify-content: center; padding: 1rem 0; gap: 0; }
        html.sidebar-transitions-on #sidebar-wrapper { transition: width 0.3s ease; }
        
        #sidebar-wrapper.collapsed { width: 70px; }
        #sidebar-wrapper.collapsed .sidebar-heading .brand-text { display: none; }
        #sidebar-wrapper.collapsed .sidebar-heading .sidebar-logo { display: none; }
        #sidebar-wrapper.collapsed .list-group-item span { display: none; }
        #sidebar-wrapper.collapsed .list-group-item { padding: 15px 0; text-align: center; justify-content: center; }
        #sidebar-wrapper.collapsed .list-group-item i { margin-right: 0; width: 100%; text-align: center; font-size: 1.2rem; }
        #sidebar-wrapper.collapsed .badge-count { display: none !important; }
        #sidebar-wrapper.collapsed .sidebar-heading { justify-content: center; padding: 1rem 0; gap: 0; }
        
        #sidebar-wrapper .sidebar-heading { padding: 1rem 1rem .85rem; font-size: 1.1rem; font-weight: 700; border-bottom: 1px solid rgba(255,255,255,0.1); background: #1c1c1d; color: white; display: flex; align-items: center; gap: 10px; line-height: 1.15; flex-shrink: 0; }
        #sidebar-wrapper .sidebar-heading .sidebar-logo { height: 34px; width: 34px; object-fit: contain; flex-shrink: 0; }
        #sidebar-wrapper .sidebar-heading .brand-text { display: flex; flex-direction: column; text-align: left; flex: 1; min-width: 0; }
        #sidebar-wrapper .sidebar-heading .brand-text .brand-acronym { font-size: 1.15rem; letter-spacing: .5px; font-weight: 800; }
        #sidebar-wrapper .sidebar-heading .brand-text .brand-full { font-size: .62rem; font-weight: 400; color: rgba(255,255,255,.7); letter-spacing: .2px; text-transform: uppercase; margin-top: 1px; white-space: normal; line-height: 1.15; max-width: 175px; }
        
        #sidebar-wrapper .list-group { padding-bottom: 20px; }
        #sidebar-wrapper .list-group-item { background: transparent; border: none; color: rgba(255,255,255,0.9); padding: 0.8rem 1.2rem; font-weight: 500; transition: background 0.2s, color 0.2s; font-size: 0.95rem; text-align: left; display: flex; align-items: center; gap: 10px; }
        #sidebar-wrapper .list-group-item i { width: 24px; text-align: center; font-size: 1rem; flex-shrink: 0; }
        #sidebar-wrapper .list-group-item:hover, #sidebar-wrapper .list-group-item.active { background: rgba(255,255,255,0.15); color: #fff; border-left: 4px solid #fff; }
        #sidebar-wrapper.collapsed .list-group-item:hover, #sidebar-wrapper.collapsed .list-group-item.active, html.sidebar-was-collapsed #sidebar-wrapper .list-group-item:hover, html.sidebar-was-collapsed #sidebar-wrapper .list-group-item.active { border-left: none; border-bottom: 2px solid #fff; }
        #sidebar-wrapper .list-group-item .badge-count { margin-left: auto; font-size: 0.65rem; padding: 3px 7px; line-height: 1; border-radius: 10px; flex-shrink: 0; }
        
        .menu-toggle { background: transparent; border: none; font-size: 1.5rem; color: white; cursor: pointer; padding: 0 10px; flex-shrink: 0; transition: transform 0.2s; }
        .menu-toggle:hover { color: #fff; transform: scale(1.1); }
        
        /* ===== PAGE CONTENT ===== */
        #page-content-wrapper { flex: 1; background: #f0f2f5; height: 100vh; display: flex; flex-direction: column; overflow: hidden; min-width: 0; }
        .navbar { background: #fff !important; box-shadow: 0 2px 4px rgba(0,0,0,0.02); padding: 0.5rem 1.5rem; display: flex; align-items: center; justify-content: space-between; flex-shrink: 0; flex-wrap: wrap; gap: 10px; min-height: 50px; }
        .navbar-left { display: flex; align-items: center; gap: 15px; }
        .navbar-brand { font-size: 1.1rem; font-weight: 600; color: #1c1c1d !important; display: flex; align-items: center; gap: 10px; }
        .navbar-right { display: flex; align-items: center; gap: 20px; flex-wrap: wrap; }
        .header-logo { height: 28px; width: auto; margin-right: 8px; vertical-align: middle; display: none; }
        #sidebar-wrapper.collapsed ~ #page-content-wrapper .header-logo, html.sidebar-was-collapsed #sidebar-wrapper ~ #page-content-wrapper .header-logo { display: inline-block; }
        
        /* ===== CONTENT ===== */
        .content-container { padding: 12px 20px 20px 20px; flex: 1; display: flex; flex-direction: column; overflow-y: auto; min-height: 0; }
        .content-container.content-analytics { overflow: hidden; padding-bottom: 0; }
        
        .view-switcher-row { display: flex; justify-content: flex-end; margin-bottom: 12px; flex-shrink: 0; }
        .view-dropdown-btn { display: inline-flex; align-items: center; gap: 8px; background: #1c1c1d; color: white; border: none; padding: 8px 16px; border-radius: 8px; font-size: 0.85rem; font-weight: 600; box-shadow: 0 2px 6px rgba(28,28,29,0.25); transition: all 0.15s; }
        .view-dropdown-btn:hover, .view-dropdown-btn:focus { background: #2c2c2d; color: white; box-shadow: 0 4px 12px rgba(28,28,29,0.35); }
        .view-dropdown-btn::after { margin-left: 4px; border-top-color: #fff; }
        .view-dropdown-btn i.view-icon { font-size: 0.95rem; }
        
        .view-dropdown-menu { min-width: 260px; padding: 6px; border: none; border-radius: 10px; box-shadow: 0 6px 20px rgba(0,0,0,0.12); font-size: 0.85rem; }
        .view-dropdown-menu .view-option { display: flex; align-items: flex-start; gap: 10px; padding: 8px 10px; border-radius: 6px; color: #2c3e50; text-decoration: none; transition: background 0.12s; }
        .view-dropdown-menu .view-option:hover { background: #f2f6fb; color: #2c3e50; }
        .view-dropdown-menu .view-option.active { background: #1c1c1d; color: white; }
        .view-dropdown-menu .view-option.active .view-option-sub { color: rgba(255,255,255,0.8); }
        .view-dropdown-menu .view-option i { font-size: 1rem; margin-top: 2px; width: 18px; text-align: center; flex-shrink: 0; }
        .view-dropdown-menu .view-option-body { min-width: 0; flex: 1; }
        .view-dropdown-menu .view-option-title { font-weight: 600; font-size: 0.82rem; line-height: 1.2; }
        .view-dropdown-menu .view-option-sub { font-size: 0.7rem; color: #6c757d; margin-top: 2px; line-height: 1.3; }
        .view-dropdown-menu .view-option .check-icon { margin-left: auto; color: #fff; font-size: 0.8rem; margin-top: 4px; }
        
        /* ===== ACTION TOOLBAR ===== */
        .action-toolbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; flex-wrap: wrap; gap: 12px; flex-shrink: 0; }
        .action-toolbar .search-group { display: flex; gap: 10px; align-items: center; flex: 1; max-width: 520px; }
        .action-toolbar .search-group .search-input { flex: 1; height: 38px; border: 1px solid #dee2e6; border-radius: 8px; padding: 8px 15px; font-size: 0.85rem; transition: all 0.3s; background: white; }
        .action-toolbar .search-group .search-input:focus { border-color: #1c1c1d; outline: none; box-shadow: 0 0 0 3px rgba(28,28,29,0.10); }
        .action-toolbar .search-group .btn-search { height: 38px; padding: 0 20px; background: #1c1c1d; color: white; border: none; border-radius: 8px; font-size: 0.85rem; font-weight: 500; display: inline-flex; align-items: center; gap: 6px; transition: all 0.2s; }
        .action-toolbar .search-group .btn-search:hover { background: #2c2c2d; transform: translateY(-1px); }
        .action-toolbar .action-buttons-group { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
        .action-toolbar .action-buttons-group .btn { height: 38px; padding: 0 16px; font-size: 0.85rem; border-radius: 8px; display: inline-flex; align-items: center; gap: 6px; }

        /* ===== MONITORING PERIOD PICKER ===== */
        .period-picker { position: relative; display: inline-block; }
        .period-btn { display: inline-flex; align-items: center; gap: 8px; height: 38px; padding: 0 16px; background: #fff; border: 1px solid #1c1c1d; color: #1c1c1d; border-radius: 8px; font-size: 0.85rem; font-weight: 600; cursor: pointer; user-select: none; transition: all .15s; }
        .period-btn:hover { background: #f2f6fb; }
        .period-input-hidden { position: absolute; inset: 0; width: 100%; height: 100%; opacity: 0; cursor: pointer; border: none; background: transparent; }

        /* ===== FILTER BAR ===== */
        .filter-bar { background: white; border-radius: 10px; padding: 14px 18px; margin-bottom: 12px; box-shadow: 0 2px 6px rgba(0,0,0,0.05); flex-shrink: 0; }
        .filter-row { display: flex; flex-wrap: wrap; gap: 10px; align-items: flex-end; }
        .filter-item { flex: 1; min-width: 130px; display: flex; flex-direction: column; }
        .filter-item label { font-size: 0.65rem; font-weight: 600; text-transform: uppercase; color: #6c757d; margin-bottom: 4px; letter-spacing: 0.5px; }
        .filter-item .form-control, .filter-item .form-select { height: 36px; font-size: 0.82rem; border-radius: 6px; border: 1px solid #dee2e6; padding: 5px 10px; background: white; color: #2c3e50; }
        .filter-item .form-control:focus, .filter-item .form-select:focus { border-color: #1c1c1d; outline: none; box-shadow: 0 0 0 3px rgba(28,28,29,0.10); }
        .filter-actions { display: flex; gap: 8px; align-items: flex-end; padding-bottom: 1px; }
        .filter-actions .btn { height: 36px; padding: 0 16px; font-size: 0.82rem; border-radius: 6px; display: inline-flex; align-items: center; gap: 6px; }
        .filter-actions .btn-primary { background: #1c1c1d; border: none; }
        .filter-actions .btn-primary:hover { background: #2c2c2d; }
        .filter-actions .btn-secondary { background: #6c757d; border: none; }
        .filter-actions .btn-secondary:hover { background: #5a6268; }

        /* ===== FILTER TABS ===== */
        .members-filter-tabs { display: flex; gap: 0; margin-bottom: 12px; background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.05); flex-wrap: wrap; flex-shrink: 0; }
        .members-filter-tabs .filter-tab { padding: 8px 20px; border: none; background: none; font-weight: 500; font-size: 0.8rem; cursor: pointer; transition: all 0.2s; color: #6c757d; flex: 1; text-align: center; border-bottom: 3px solid transparent; min-width: 70px; }
        .members-filter-tabs .filter-tab:hover { background: #f8f9fa; }
        .members-filter-tabs .filter-tab.active { color: #1c1c1d; border-bottom-color: #1c1c1d; background: #f8f9fa; }
        .members-filter-tabs .filter-tab .count-badge { background: #e9ecef; padding: 2px 8px; border-radius: 12px; font-size: 0.7rem; margin-left: 4px; }
        .members-filter-tabs .filter-tab.active .count-badge { background: #1c1c1d; color: white; }
        .members-filter-tabs .filter-tab .count-badge.paid-badge { background: #d4edda; color: #82a88b; }
        .members-filter-tabs .filter-tab .count-badge.unpaid-badge { background: #f8d7da; color: #b89296; }
        .members-filter-tabs .filter-tab .count-badge.advanced-badge { background: #e0e7ff; color: #4338ca; }
        .members-filter-tabs .filter-tab .count-badge.overdue-badge { background: #fff3cd; color: #b6a778; }
        
        /* ===== MEMBER TABLE ===== */
        .member-list-container { background: white; border-radius: 10px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); display: flex; flex-direction: column; flex: 1; min-height: 0; overflow: hidden; }
        .table-scroll-wrapper { flex: 1; overflow-y: auto; overflow-x: auto; padding: 0; min-height: 0; }
        .table-responsive { overflow: visible; min-height: 100%; }
        .member-table { width: 100%; border-collapse: collapse; min-width: 600px; }
        .member-table th { background: #f8f9fa; padding: 10px 14px; text-align: left; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.5px; color: #6c757d; font-weight: 600; border-bottom: 2px solid #e9ecef; white-space: nowrap; position: sticky; top: 0; z-index: 10; }
        .member-table td { padding: 10px 14px; border-bottom: 1px solid #e9ecef; font-size: 0.82rem; color: #2c3e50; vertical-align: middle; }
        .member-table tr:hover td { background: #f8f9fa; }
        
        .status-badge { padding: 3px 10px; border-radius: 20px; font-size: 0.68rem; font-weight: 600; display: inline-block; white-space: nowrap; }
        .status-badge.paid { background: #d4edda; color: #82a88b; }
        .status-badge.unpaid { background: #f8d7da; color: #b89296; }
        .status-badge.advanced { background: #e0e7ff; color: #4338ca; }
        .status-badge.overdue { background: #fff3cd; color: #b6a778; }
        .status-badge.inactive { background: #e2e3e5; color: #383d41; }
        .status-badge.active { background: #d4edda; color: #155724; }
        .status-badge.pending { background: #fff3cd; color: #856404; }
        
        /* ===== PAGINATION ===== */
        .pagination-wrapper { display: flex; justify-content: space-between; align-items: center; padding: 10px 16px; background: #f8f9fa; border-top: 1px solid #e9ecef; flex-wrap: wrap; gap: 8px; flex-shrink: 0; }
        .pagination-wrapper .info { font-size: 0.8rem; color: #6c757d; }
        .pagination-wrapper .pagination { margin: 0; gap: 2px; }
        .pagination-wrapper .pagination .page-link { padding: 4px 10px; font-size: 0.8rem; border-radius: 4px; border: 1px solid #dee2e6; color: #1c1c1d; background: white; transition: all 0.2s; }
        .pagination-wrapper .pagination .page-link:hover { background: #1c1c1d; color: white; border-color: #1c1c1d; }
        .pagination-wrapper .pagination .page-item.active .page-link { background: #1c1c1d; color: white; border-color: #1c1c1d; }
        .pagination-wrapper .pagination .page-item.disabled .page-link { color: #6c757d; cursor: not-allowed; background: #f8f9fa; }
        
        .empty-state { text-align: center; padding: 30px 20px; color: #6c757d; }
        .empty-state i { font-size: 2.5rem; color: #dee2e6; margin-bottom: 10px; }

        /* ============================================
           ANALYTICS SHELL
        ============================================ */
        .analytics-shell { display: flex; flex-direction: column; flex: 1; min-height: 0; overflow: hidden; }
        .analytics-topbar { flex-shrink: 0; padding-bottom: 12px; }
        .analytics-list-scroll { flex: 1; overflow-y: auto; overflow-x: hidden; min-height: 0; padding-right: 6px; padding-bottom: 20px; }
        .analytics-list-scroll::-webkit-scrollbar { width: 6px; }
        .analytics-list-scroll::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 4px; }
        .analytics-list-scroll::-webkit-scrollbar-thumb { background: #c1c7cd; border-radius: 4px; }
        .analytics-list-scroll::-webkit-scrollbar-thumb:hover { background: #a0a7ad; }

        .analytics-header { display: flex; align-items: center; justify-content: space-between; background: white; border-radius: 10px; padding: 12px 18px; margin-bottom: 10px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); flex-wrap: wrap; gap: 12px; }
        .analytics-header h5 { margin: 0; font-size: 1rem; font-weight: 700; color: #1a2b3c; display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
        .analytics-header h5 i { color: #1c1c1d; }
        .analytics-header-tools { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-left: auto; }
        .analytics-header .meta { font-size: 0.78rem; color: #6c757d; display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .analytics-header .meta .pill { background: #f1f4f8; color: #1c1c1d; padding: 3px 10px; border-radius: 12px; font-weight: 600; }

        .analytics-filter { display: flex; align-items: center; gap: 8px; background: #f8fafc; border: 1px solid #e0e6ed; border-radius: 8px; padding: 6px 12px; min-width: 220px; max-width: 320px; flex: 1; transition: all .15s; }
        .analytics-filter:focus-within { border-color: #1c1c1d; box-shadow: 0 0 0 3px rgba(28,28,29,0.10); background: white; }
        .analytics-filter i { color: #8a94a0; font-size: 0.85rem; }
        .analytics-filter input { border: none; background: transparent; outline: none; flex: 1; font-size: 0.82rem; color: #2c3e50; min-width: 0; }
        .analytics-filter input::placeholder { color: #a6aeb8; }

        .crumb { display: flex; align-items: center; flex-wrap: wrap; gap: 6px; font-size: 0.78rem; color: #6c757d; margin-bottom: 10px; padding: 0 4px; }
        .crumb a { color: #1c1c1d; text-decoration: none; font-weight: 600; }
        .crumb a:hover { text-decoration: underline; }
        .crumb .sep { color: #b5bcc4; }
        .crumb .current { color: #1a2b3c; font-weight: 700; }

        .back-btn { display: inline-flex; align-items: center; gap: 6px; padding: 6px 14px; border-radius: 6px; background: #f1f4f8; color: #1c1c1d; text-decoration: none; font-size: 0.8rem; font-weight: 600; transition: all 0.15s; border: none; white-space: nowrap; }
        .back-btn:hover { background: #1c1c1d; color: white; text-decoration: none; }

        .groups-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 14px; margin-bottom: 14px; }
        .group-card { background: white; border-radius: 10px; padding: 16px 18px; box-shadow: 0 2px 6px rgba(0,0,0,0.05); border-left: 4px solid #1c1c1d; text-decoration: none; color: inherit; transition: all 0.15s; display: flex; align-items: center; gap: 14px; }
        .group-card:hover { transform: translateY(-2px); box-shadow: 0 6px 16px rgba(0,0,0,0.10); color: inherit; text-decoration: none; }
        .group-card.chapter-card { border-left-color: #1c1c1d; }
        .group-card.chapter-card .group-icon { background: #e7f1ff; color: #1c1c1d; }
        .group-icon { width: 46px; height: 46px; border-radius: 10px; background: #f1f4f8; color: #1c1c1d; display: flex; align-items: center; justify-content: center; font-size: 1.15rem; flex-shrink: 0; }
        .group-name { font-weight: 700; font-size: 0.95rem; color: #1a2b3c; word-break: break-word; }
        .group-meta { font-size: 0.72rem; color: #6c757d; margin-top: 3px; display: flex; gap: 10px; flex-wrap: wrap; }
        .group-card .chev { margin-left: auto; color: #b5bcc4; font-size: 0.85rem; flex-shrink: 0; }

        .analytics-no-results { display: none; text-align: center; padding: 40px 20px; color: #6c757d; background: white; border-radius: 10px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .analytics-no-results i { font-size: 2.2rem; color: #dee2e6; margin-bottom: 10px; display: block; }

        .member-detail-wrap { display: grid; grid-template-columns: 1.15fr 1fr; gap: 14px; margin-bottom: 14px; }
        .detail-card { background: white; border-radius: 10px; padding: 18px 20px; box-shadow: 0 2px 6px rgba(0,0,0,0.05); }
        .detail-card h6 { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.6px; color: #6c757d; font-weight: 700; margin-bottom: 14px; display: flex; align-items: center; gap: 8px; }
        .detail-card h6 i { color: #1c1c1d; }

        .info-list { display: flex; flex-direction: column; gap: 10px; }
        .info-row { display: flex; justify-content: space-between; align-items: flex-start; gap: 12px; font-size: 0.83rem; border-bottom: 1px dashed #eef1f5; padding-bottom: 8px; }
        .info-row:last-child { border-bottom: none; padding-bottom: 0; }
        .info-row .lbl { color: #6c757d; font-weight: 500; flex-shrink: 0; }
        .info-row .val { color: #1a2b3c; font-weight: 600; text-align: right; word-break: break-word; }

        .stat-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin-bottom: 14px; }
        .stat-block { background: #f8fafc; border-radius: 8px; padding: 14px 16px; text-align: center; }
        .stat-block .big { font-size: 1.75rem; font-weight: 800; line-height: 1.1; color: #1c1c1d; }
        .stat-block.claim .big { color: #28a745; }
        .stat-block.missed .big { color: #dc3545; }
        .stat-block .lbl { font-size: 0.7rem; text-transform: uppercase; letter-spacing: 0.5px; color: #6c757d; font-weight: 600; margin-top: 4px; }

        .claim-highlight { background: linear-gradient(135deg, #e6f4ea 0%, #d4edda 100%); border-radius: 10px; padding: 16px 18px; text-align: center; border: 1px solid #c3e6cb; }
        .claim-highlight .amount { font-size: 2rem; font-weight: 800; color: #1e7e34; line-height: 1; }
        .claim-highlight .lbl { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.5px; color: #1e7e34; font-weight: 700; margin-top: 6px; opacity: 0.85; }
        .claim-highlight .sub { font-size: 0.72rem; color: #1e7e34; margin-top: 6px; opacity: 0.8; }

        .missed-list { max-height: 340px; overflow-y: auto; padding-right: 4px; }
        .missed-list::-webkit-scrollbar { width: 5px; }
        .missed-list::-webkit-scrollbar-thumb { background: #d0d6dd; border-radius: 3px; }
        .missed-item { display: flex; align-items: center; justify-content: space-between; padding: 9px 14px; border-radius: 6px; background: #fdecea; color: #b02a37; font-size: 0.82rem; font-weight: 500; margin-bottom: 6px; }
        .missed-item .ym { font-weight: 700; letter-spacing: 0.5px; }
        .missed-empty { text-align: center; padding: 22px; color: #1e7e34; font-size: 0.85rem; font-weight: 600; }
        .missed-empty i { font-size: 1.8rem; display: block; margin-bottom: 8px; }

        .progress-thin { height: 6px; background: #e9ecef; border-radius: 3px; overflow: hidden; margin-top: 8px; }
        .progress-thin .bar { height: 100%; background: #28a745; transition: width 0.4s ease; }

        /* ========================================
           RESPONSIVE
        ======================================== */
        @media (max-width: 992px) {
            #sidebar-wrapper { width: 70px; }
            #sidebar-wrapper .sidebar-heading .brand-text { display: none; }
            #sidebar-wrapper .sidebar-heading .sidebar-logo { display: none; }
            #sidebar-wrapper .list-group-item span { display: none; }
            #sidebar-wrapper .list-group-item i { margin-right: 0; width: 100%; text-align: center; font-size: 1.2rem; }
            #sidebar-wrapper .list-group-item { padding: 15px 0; text-align: center; justify-content: center; }
            #sidebar-wrapper .badge-count { display: none !important; }
            #sidebar-wrapper .sidebar-heading { justify-content: center; padding: 1rem 0; }
            .header-logo { display: inline-block !important; }
            
            .member-detail-wrap { grid-template-columns: 1fr; }
            
            .action-toolbar { flex-direction: column; align-items: stretch; }
            .action-toolbar .search-group { max-width: 100%; }
            .action-toolbar .action-buttons-group { justify-content: flex-start; }
            .filter-row { flex-direction: column; align-items: stretch; }
            .filter-item { width: 100%; }
            .filter-actions { justify-content: stretch; }
            .filter-actions .btn { flex: 1; justify-content: center; }
        }
        
        @media (max-width: 768px) {
            #sidebar-wrapper { position: fixed; left: 0; top: 0; transform: translateX(-100%); width: 280px; height: 100vh; z-index: 1050; transition: transform 0.3s ease; }
            #sidebar-wrapper.mobile-open { transform: translateX(0); }
            #sidebar-wrapper .sidebar-heading .brand-text { display: flex; }
            #sidebar-wrapper .sidebar-heading .sidebar-logo { display: block; }
            #sidebar-wrapper .list-group-item span { display: inline; }
            #sidebar-wrapper .list-group-item i { margin-right: 10px; width: 24px; text-align: center; font-size: 1rem; }
            #sidebar-wrapper .list-group-item { padding: 0.8rem 1.2rem; text-align: left; justify-content: flex-start; }
            #sidebar-wrapper .badge-count { display: inline-flex !important; }
            #sidebar-wrapper .sidebar-heading { justify-content: space-between; padding: 1rem 1rem; }
            
            .sidebar-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1040; }
            .sidebar-overlay.active { display: block; }
            
            .header-logo { display: inline-block !important; }
            .navbar { padding: 0.4rem 1rem; min-height: 44px; }
            .navbar-brand { font-size: 0.95rem; }
            .navbar-right { gap: 8px; }
            .navbar-right .small { font-size: 0.7rem; }
            
            .content-container { padding: 8px 12px 12px 12px; }
            .content-container.content-analytics { padding-bottom: 0; }
            
            .action-toolbar .search-group .search-input, .action-toolbar .search-group .btn-search, .action-toolbar .action-buttons-group .btn, .period-btn { height: 34px; font-size: 0.8rem; }
            .filter-bar { padding: 10px 12px; }
            
            .members-filter-tabs { margin-bottom: 8px; border-radius: 8px; }
            .members-filter-tabs .filter-tab { padding: 6px 12px; font-size: 0.7rem; min-width: 50px; }
            
            .member-table th, .member-table td { padding: 6px 10px; font-size: 0.7rem; }
            .pagination-wrapper { padding: 8px 12px; flex-direction: column; align-items: center; text-align: center; }
            .pagination-wrapper .pagination .page-link { padding: 3px 8px; font-size: 0.7rem; }
            .status-badge { font-size: 0.6rem; padding: 2px 6px; }
            .member-list-container { border-radius: 8px; }
            
            .view-switcher-row { justify-content: stretch; }
            .view-dropdown-btn { width: 100%; justify-content: space-between; }

            .stat-grid { grid-template-columns: 1fr; }
            .groups-grid { grid-template-columns: 1fr; }
            .claim-highlight .amount { font-size: 1.6rem; }
            .analytics-header { padding: 10px 12px; }
            .analytics-header h5 { font-size: 0.9rem; }
            .analytics-header-tools { width: 100%; margin-left: 0; }
            .analytics-filter { max-width: 100%; }
            .analytics-header .meta { font-size: 0.7rem; }
        }
        
        @media (max-width: 480px) {
            .navbar-brand { font-size: 0.85rem; }
            .navbar-right .small { display: none; }
            .member-table { min-width: 450px; }
            .members-filter-tabs .filter-tab { padding: 4px 8px; font-size: 0.6rem; min-width: 40px; }
            .content-container { padding: 6px 8px 10px 8px; }
        }
    </style>
</head>
<body>
    <!-- Mobile overlay -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <div id="wrapper">
        <!-- Sidebar -->
        <div id="sidebar-wrapper">
            <div class="sidebar-heading">
                <img src="../assets/images/harana-logo.png" alt="NHGL" class="sidebar-logo" onerror="this.style.display='none';">
                <span class="brand-text">
                    <span class="brand-acronym">NHGL</span>
                    <span class="brand-full">Nagkaisang Haranista sa Gintong Luzon</span>
                </span>
                <button class="menu-toggle" id="menuToggle">
                    <i class="fas fa-bars"></i>
                </button>
            </div>
            <div class="list-group list-group-flush mt-2">
                <?php $current_page = basename($_SERVER['PHP_SELF']); ?>
                <a href="dashboard.php" class="list-group-item active"><i class="fas fa-tachometer-alt"></i><span>Dashboard</span></a>
                <a href="members.php" class="list-group-item"><i class="fas fa-users"></i><span>Members</span></a>
                <a href="manage_chapters.php" class="list-group-item"><i class="fas fa-sitemap"></i><span>Chapters &amp; Groups</span></a>
                 <a href="leader_assignments.php" class="list-group-item <?php echo $current_page == 'leader_assignments.php' ? 'active' : ''; ?>"><i class="fas fa-user-shield"></i><span>Leader Assignment</span></a>
                <a href="council.php" class="list-group-item"><i class="fas fa-user-tie"></i><span>Council</span></a>
                <a href="payments.php" class="list-group-item"><i class="fas fa-credit-card"></i><span>Payments</span></a>
                <a href="remittances.php" class="list-group-item"><i class="fas fa-hand-holding-usd"></i><span>Remittances</span></a>
                <a href="reports.php" class="list-group-item"><i class="fas fa-chart-bar"></i><span>Reports</span></a>
                <a href="announcements.php" class="list-group-item"><i class="fas fa-bullhorn"></i><span>Send Announcement</span></a>
                <?php if ($current_user['role'] === 'admin'): ?>
                
                <a href="pending_users.php" class="list-group-item">
                    <i class="fas fa-user-clock"></i><span>Pending</span>
                    <?php if ($pending_count > 0): ?>
                        <span class="badge bg-danger badge-count"><?php echo $pending_count; ?></span>
                    <?php endif; ?>
                </a>
                <?php endif; ?>
                <a href="settings.php" class="list-group-item"><i class="fas fa-cog"></i><span>Settings</span></a>
                <a href="../logout.php" class="list-group-item" onclick="return confirm('Are you sure?');"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a>
            </div>
        </div>

        <!-- Main Content -->
        <div id="page-content-wrapper">
            <!-- Navbar -->
            <nav class="navbar navbar-light bg-light">
                <div class="navbar-left">
                    <button class="btn btn-link d-md-none p-0 me-2" id="mobileMenuToggle" style="color: #1c1c1d; font-size: 1.3rem;">
                        <i class="fas fa-bars"></i>
                    </button>
                    <img src="../assets/images/harana-logo.png" alt="Harana" class="header-logo" id="headerLogo" onerror="this.style.display='none';">
                    <span class="navbar-brand"><i class="fas fa-tachometer-alt me-2"></i>Dashboard</span>
                </div>
                <div class="navbar-right">
                    <span class="text-muted small d-none d-sm-inline">
                        <i class="fas fa-calendar-alt me-1"></i><?php echo date('F j, Y'); ?>
                    </span>
                    <span class="small"><i class="fas fa-user-circle me-2"></i><?php echo htmlspecialchars($current_user['full_name']); ?></span>
                </div>
            </nav>

            <!-- CONTENT -->
            <div class="content-container <?php echo $view === 'analytics' ? 'content-analytics' : ''; ?>">

                <!-- VIEW SWITCHER (top-right) -->
                <div class="view-switcher-row">
                    <div class="dropdown">
                        <button class="view-dropdown-btn dropdown-toggle" type="button" id="viewSwitcherBtn" data-bs-toggle="dropdown" aria-expanded="false">
                            <?php if ($view === 'analytics'): ?>
                                <i class="fas fa-sitemap view-icon"></i>
                                <span>Chapters &amp; Claims Overview</span>
                            <?php else: ?>
                                <i class="fas fa-money-check-alt view-icon"></i>
                                <span>Payment Monitoring</span>
                            <?php endif; ?>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end view-dropdown-menu" aria-labelledby="viewSwitcherBtn">
                            <li>
                                <a class="view-option <?php echo $view === 'monitoring' ? 'active' : ''; ?>"
                                   href="<?php echo htmlspecialchars(buildViewSwitchUrl('monitoring')); ?>">
                                    <i class="fas fa-money-check-alt"></i>
                                    <div class="view-option-body">
                                        <div class="view-option-title">Payment Monitoring</div>
                                        <div class="view-option-sub">Track member contributions by month</div>
                                    </div>
                                    <?php if ($view === 'monitoring'): ?>
                                        <i class="fas fa-check check-icon"></i>
                                    <?php endif; ?>
                                </a>
                            </li>
                            <li>
                                <a class="view-option <?php echo $view === 'analytics' ? 'active' : ''; ?>"
                                   href="<?php echo htmlspecialchars(buildViewSwitchUrl('analytics')); ?>">
                                    <i class="fas fa-sitemap"></i>
                                    <div class="view-option-body">
                                        <div class="view-option-title">Chapters &amp; Claims Overview</div>
                                        <div class="view-option-sub">Chapter → Group → Member with claim &amp; missed payment computation</div>
                                    </div>
                                    <?php if ($view === 'analytics'): ?>
                                        <i class="fas fa-check check-icon"></i>
                                    <?php endif; ?>
                                </a>
                            </li>
                        </ul>
                    </div>
                </div>

                <?php if ($view === 'analytics'): ?>
                <!-- ============================================================ -->
                <!-- ANALYTICS VIEW                                                -->
                <!-- ============================================================ -->
                <div class="analytics-shell">

                    <div class="analytics-topbar">

                        <!-- BREADCRUMB -->
                        <div class="crumb">
                            <a href="?view=analytics"><i class="fas fa-sitemap"></i> Chapters</a>
                            <?php if ($selected_chapter): ?>
                                <span class="sep">/</span>
                                <?php if ($selected_group): ?>
                                    <a href="<?php echo htmlspecialchars(buildAnalyticsChapterUrl($selected_chapter['name'])); ?>">
                                        <?php echo htmlspecialchars($selected_chapter['name']); ?>
                                    </a>
                                <?php else: ?>
                                    <span class="current"><?php echo htmlspecialchars($selected_chapter['name']); ?></span>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php if ($selected_group): ?>
                                <span class="sep">/</span>
                                <?php if ($selected_member): ?>
                                    <a href="<?php echo htmlspecialchars(buildAnalyticsGroupUrl($selected_chapter['name'], $selected_group['name'])); ?>">
                                        <?php echo htmlspecialchars($selected_group['name']); ?>
                                    </a>
                                <?php else: ?>
                                    <span class="current"><?php echo htmlspecialchars($selected_group['name']); ?></span>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php if ($selected_member): ?>
                                <span class="sep">/</span>
                                <span class="current">
                                    <?php echo htmlspecialchars($selected_member['first_name'] . ' ' . $selected_member['last_name']); ?>
                                </span>
                            <?php endif; ?>
                        </div>

                        <?php if ($selected_member): ?>
                            <div class="analytics-header">
                                <h5>
                                    <i class="fas fa-user-circle"></i>
                                    <?php echo htmlspecialchars($selected_member['first_name'] . ' ' . $selected_member['last_name']); ?>
                                </h5>
                                <div class="analytics-header-tools">
                                    <a href="<?php echo htmlspecialchars(buildAnalyticsGroupUrl($selected_chapter['name'], $selected_group['name'])); ?>" class="back-btn">
                                        <i class="fas fa-arrow-left"></i> Back to <?php echo htmlspecialchars($selected_group['name']); ?>
                                    </a>
                                </div>
                            </div>

                        <?php elseif ($selected_group): ?>
                            <div class="analytics-header">
                                <h5>
                                    <i class="fas fa-layer-group"></i>
                                    <?php echo htmlspecialchars($selected_group['name']); ?> — Members
                                </h5>
                                <div class="analytics-header-tools">
                                    <div class="analytics-filter">
                                        <i class="fas fa-search"></i>
                                        <input type="text"
                                               id="analyticsFilter"
                                               placeholder="Search member (name, code, barangay)..."
                                               autocomplete="off">
                                    </div>
                                    <div class="meta">
                                        <span class="pill"><i class="fas fa-users"></i> <?php echo count($group_members); ?> member(s)</span>
                                    </div>
                                    <a href="<?php echo htmlspecialchars(buildAnalyticsChapterUrl($selected_chapter['name'])); ?>" class="back-btn">
                                        <i class="fas fa-arrow-left"></i> Back
                                    </a>
                                </div>
                            </div>

                        <?php elseif ($selected_chapter): ?>
                            <div class="analytics-header">
                                <h5>
                                    <i class="fas fa-sitemap"></i>
                                    <?php echo htmlspecialchars($selected_chapter['name']); ?> — Groups
                                </h5>
                                <div class="analytics-header-tools">
                                    <div class="analytics-filter">
                                        <i class="fas fa-search"></i>
                                        <input type="text"
                                               id="analyticsFilter"
                                               placeholder="Search group..."
                                               autocomplete="off">
                                    </div>
                                    <div class="meta">
                                        <span class="pill">
                                            <i class="fas fa-layer-group"></i>
                                            <?php echo count($groups_list); ?> group(s)
                                        </span>
                                        <span class="pill">
                                            <i class="fas fa-users"></i>
                                            <?php echo (int)$selected_chapter['member_count']; ?> member(s)
                                        </span>
                                    </div>
                                    <a href="?view=analytics" class="back-btn">
                                        <i class="fas fa-arrow-left"></i> All Chapters
                                    </a>
                                </div>
                            </div>

                        <?php else: ?>
                            <div class="analytics-header">
                                <h5><i class="fas fa-sitemap"></i> Chapters Overview</h5>
                                <div class="analytics-header-tools">
                                    <div class="analytics-filter">
                                        <i class="fas fa-search"></i>
                                        <input type="text"
                                               id="analyticsFilter"
                                               placeholder="Search chapter..."
                                               autocomplete="off">
                                    </div>
                                    <div class="meta">
                                        <span class="pill">
                                            <i class="fas fa-sitemap"></i>
                                            <?php echo count($chapters_list); ?> chapter(s)
                                        </span>
                                        <span class="pill">
                                            <i class="fas fa-users"></i>
                                            <?php echo array_sum(array_column($chapters_list, 'member_count')); ?> member(s)
                                        </span>
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                    </div>

                    <div class="analytics-list-scroll">

                        <?php if ($selected_member): ?>
                            <?php
                                $claim        = $selected_member['claim'];
                                $years        = $selected_member['years'];
                                $missed_count = count($member_missed);
                                $progress_pct = $member_expected_count > 0
                                    ? round(($member_paid_count / $member_expected_count) * 100)
                                    : 0;
                            ?>

                            <div class="member-detail-wrap">
                                <div class="detail-card">
                                    <h6><i class="fas fa-id-card"></i> Member Information</h6>
                                    <div class="info-list">
                                        <div class="info-row">
                                            <span class="lbl">Member Code</span>
                                            <span class="val"><?php echo htmlspecialchars($selected_member['member_code']); ?></span>
                                        </div>
                                        <div class="info-row">
                                            <span class="lbl">Full Name</span>
                                            <span class="val"><?php echo htmlspecialchars($selected_member['first_name'] . ' ' . $selected_member['last_name']); ?></span>
                                        </div>
                                        <div class="info-row">
                                            <span class="lbl">Email</span>
                                            <span class="val"><?php echo htmlspecialchars($selected_member['email'] ?: '—'); ?></span>
                                        </div>
                                        <div class="info-row">
                                            <span class="lbl">Contact</span>
                                            <span class="val"><?php echo htmlspecialchars($selected_member['contact_number'] ?: '—'); ?></span>
                                        </div>
                                        <div class="info-row">
                                            <span class="lbl">Chapter</span>
                                            <span class="val"><?php echo htmlspecialchars($selected_member['chapter'] ?: '—'); ?></span>
                                        </div>
                                        <div class="info-row">
                                            <span class="lbl">Group</span>
                                            <span class="val"><?php echo htmlspecialchars($selected_member['group_name'] ?: '—'); ?></span>
                                        </div>
                                        <div class="info-row">
                                            <span class="lbl">Barangay</span>
                                            <span class="val"><?php echo htmlspecialchars($selected_member['barangay'] ?: '—'); ?></span>
                                        </div>
                                        <div class="info-row">
                                            <span class="lbl">Date Joined</span>
                                            <span class="val"><?php echo $selected_member['date_joined'] ? date('F j, Y', strtotime($selected_member['date_joined'])) : '—'; ?></span>
                                        </div>
                                        <div class="info-row">
                                            <span class="lbl">Monthly Contribution</span>
                                            <span class="val">₱<?php echo number_format((float)($selected_member['monthly_contribution'] ?? 0), 2); ?></span>
                                        </div>
                                    </div>
                                </div>

                                <div class="detail-card">
                                    <h6><i class="fas fa-hand-holding-usd"></i> Contribution &amp; Claim</h6>

                                    <div class="stat-grid">
                                        <div class="stat-block">
                                            <div class="big"><?php echo $years; ?></div>
                                            <div class="lbl">Years of Contribution</div>
                                        </div>
                                        <div class="stat-block">
                                            <div class="big"><?php echo $member_paid_count; ?>/<?php echo $member_expected_count; ?></div>
                                            <div class="lbl">Months Paid</div>
                                        </div>
                                    </div>

                                    <div class="progress-thin">
                                        <div class="bar" style="width: <?php echo $progress_pct; ?>%;"></div>
                                    </div>
                                    <div style="font-size: 0.7rem; color: #6c757d; margin-top: 6px; text-align: right;">
                                        <?php echo $progress_pct; ?>% of expected months paid
                                    </div>

                                    <div style="margin-top: 16px;">
                                        <div class="claim-highlight">
                                            <div class="amount">₱<?php echo number_format($claim, 2); ?></div>
                                            <div class="lbl">Claimable Amount</div>
                                            <?php if ($years >= 1): ?>
                                                <div class="sub">Based on <?php echo $years; ?> year<?php echo $years == 1 ? '' : 's'; ?> of contribution</div>
                                            <?php else: ?>
                                                <div class="sub">Not yet eligible (needs at least 1 year)</div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="detail-card">
                                <h6>
                                    <i class="fas fa-exclamation-triangle" style="color:#dc3545;"></i>
                                    Missed Payments (<?php echo $missed_count; ?>)
                                </h6>

                                <?php if ($missed_count === 0): ?>
                                    <div class="missed-empty">
                                        <i class="fas fa-check-circle"></i>
                                        All contributions are complete. No missed payments.
                                    </div>
                                <?php else: ?>
                                    <div class="missed-list">
                                        <?php foreach ($member_missed as $m): ?>
                                            <div class="missed-item">
                                                <span><?php echo htmlspecialchars($m['label']); ?></span>
                                                <span class="ym"><?php echo htmlspecialchars($m['ym']); ?></span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                        <?php elseif ($selected_group): ?>
                            <div class="member-list-container" style="border-radius:10px;">
                                <div class="table-scroll-wrapper" style="max-height:none;">
                                    <div class="table-responsive">
                                        <table class="member-table">
                                            <thead>
                                                <tr>
                                                    <th>Member Code</th>
                                                    <th>Name</th>
                                                    <th>Barangay</th>
                                                    <th>Joined</th>
                                                    <th>Years</th>
                                                    <th>Claim Amount</th>
                                                    <th></th>
                                                </tr>
                                            </thead>
                                            <tbody id="analyticsListBody">
                                                <?php if (!empty($group_members)): ?>
                                                    <?php foreach ($group_members as $gm): ?>
                                                        <tr class="filterable-item"
                                                            data-name="<?php echo htmlspecialchars(strtolower($gm['member_code'] . ' ' . $gm['first_name'] . ' ' . $gm['last_name'] . ' ' . ($gm['barangay'] ?: ''))); ?>"
                                                            style="cursor:pointer;"
                                                            onclick="window.location.href='<?php echo htmlspecialchars(buildAnalyticsMemberUrl($selected_chapter['name'], $selected_group['name'], $gm['member_code'])); ?>'">
                                                            <td>
                                                                <span class="badge bg-secondary" style="font-size: 0.7rem;">
                                                                    <?php echo htmlspecialchars($gm['member_code']); ?>
                                                                </span>
                                                            </td>
                                                            <td>
                                                                <strong><?php echo htmlspecialchars($gm['first_name'] . ' ' . $gm['last_name']); ?></strong>
                                                            </td>
                                                            <td><?php echo htmlspecialchars($gm['barangay'] ?: 'N/A'); ?></td>
                                                            <td>
                                                                <?php echo $gm['date_joined'] ? date('M j, Y', strtotime($gm['date_joined'])) : '—'; ?>
                                                            </td>
                                                            <td>
                                                                <span class="badge" style="background:#e8f0fe; color:#1a56db; font-size:0.72rem;">
                                                                    <?php echo $gm['years']; ?> yr<?php echo $gm['years'] == 1 ? '' : 's'; ?>
                                                                </span>
                                                            </td>
                                                            <td>
                                                                <?php if ($gm['claim'] > 0): ?>
                                                                    <strong style="color:#1e7e34;">₱<?php echo number_format($gm['claim'], 2); ?></strong>
                                                                <?php else: ?>
                                                                    <span class="text-muted" style="font-size:0.75rem;">Not eligible</span>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td class="text-end">
                                                                <i class="fas fa-chevron-right text-muted" style="font-size:0.8rem;"></i>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <tr>
                                                        <td colspan="7">
                                                            <div class="empty-state">
                                                                <i class="fas fa-users"></i>
                                                                <p style="margin-bottom: 0;">No active members in this group.</p>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endif; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>

                            <div class="analytics-no-results" id="analyticsNoResults">
                                <i class="fas fa-search"></i>
                                <p style="margin:0;">No members match your search.</p>
                            </div>

                        <?php elseif ($selected_chapter): ?>
                            <?php if (empty($groups_list)): ?>
                                <div class="detail-card">
                                    <div class="empty-state">
                                        <i class="fas fa-layer-group"></i>
                                        <p style="margin-bottom: 0;">No groups in this chapter yet.</p>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="groups-grid" id="analyticsListGrid">
                                    <?php foreach ($groups_list as $g): ?>
                                        <a class="group-card filterable-item"
                                           data-name="<?php echo htmlspecialchars(strtolower($g['name'])); ?>"
                                           href="<?php echo htmlspecialchars(buildAnalyticsGroupUrl($selected_chapter['name'], $g['name'])); ?>">
                                            <div class="group-icon">
                                                <i class="fas fa-layer-group"></i>
                                            </div>
                                            <div style="min-width: 0; flex: 1;">
                                                <div class="group-name"><?php echo htmlspecialchars($g['name']); ?></div>
                                                <div class="group-meta">
                                                    <span><i class="fas fa-users"></i> <?php echo (int)$g['member_count']; ?> member(s)</span>
                                                </div>
                                            </div>
                                            <i class="fas fa-chevron-right chev"></i>
                                        </a>
                                    <?php endforeach; ?>
                                </div>

                                <div class="analytics-no-results" id="analyticsNoResults">
                                    <i class="fas fa-search"></i>
                                    <p style="margin:0;">No groups match your search.</p>
                                </div>
                            <?php endif; ?>

                        <?php else: ?>
                            <?php if (empty($chapters_list)): ?>
                                <div class="detail-card">
                                    <div class="empty-state">
                                        <i class="fas fa-sitemap"></i>
                                        <p style="margin-bottom: 0;">No chapters found. Assign members to a chapter first.</p>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="groups-grid" id="analyticsListGrid">
                                    <?php foreach ($chapters_list as $c): ?>
                                        <a class="group-card chapter-card filterable-item"
                                           data-name="<?php echo htmlspecialchars(strtolower($c['name'])); ?>"
                                           href="<?php echo htmlspecialchars(buildAnalyticsChapterUrl($c['name'])); ?>">
                                            <div class="group-icon">
                                                <i class="fas fa-sitemap"></i>
                                            </div>
                                            <div style="min-width: 0; flex: 1;">
                                                <div class="group-name"><?php echo htmlspecialchars($c['name']); ?></div>
                                                <div class="group-meta">
                                                    <span><i class="fas fa-layer-group"></i> <?php echo (int)$c['group_count']; ?> group(s)</span>
                                                    <span><i class="fas fa-users"></i> <?php echo (int)$c['member_count']; ?> member(s)</span>
                                                </div>
                                            </div>
                                            <i class="fas fa-chevron-right chev"></i>
                                        </a>
                                    <?php endforeach; ?>
                                </div>

                                <div class="analytics-no-results" id="analyticsNoResults">
                                    <i class="fas fa-search"></i>
                                    <p style="margin:0;">No chapters match your search.</p>
                                </div>
                            <?php endif; ?>

                        <?php endif; ?>

                    </div>

                </div>

                <?php else: ?>
                <!-- ============================================================ -->
                <!-- MONITORING VIEW                                               -->
                <!-- ============================================================ -->

                <div class="action-toolbar">
                    <form method="GET" class="search-group" id="searchForm">
                        <input type="hidden" name="view" value="monitoring">
                        <input type="hidden" name="period" value="<?php echo htmlspecialchars($period); ?>">
                        <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>">
                        <input type="hidden" name="filter_name" value="<?php echo htmlspecialchars($filter_name); ?>">
                        <input type="hidden" name="filter_barangay" value="<?php echo htmlspecialchars($filter_barangay); ?>">
                        <input type="hidden" name="filter_chapter" value="<?php echo htmlspecialchars($filter_chapter); ?>">
                        <input type="hidden" name="filter_group" value="<?php echo htmlspecialchars($filter_group); ?>">
                        <input type="hidden" name="per_page" value="<?php echo (int)$per_page; ?>">
                        <input type="text"
                               name="search"
                               id="searchInput"
                               class="search-input"
                               placeholder="Search by name, code, email, contact, chapter..."
                               value="<?php echo htmlspecialchars($search); ?>">
                        <button type="submit" class="btn-search">
                            <i class="fas fa-search"></i> Search
                        </button>
                    </form>

                    <div class="action-buttons-group">
                        <a href="member_export.php?period=<?php echo urlencode($period); ?>&filter=<?php echo urlencode($filter); ?>"
                           class="btn btn-success">
                            <i class="fas fa-file-excel"></i> Export CSV
                        </a>

                        <div class="period-picker">
                            <div class="period-btn" id="periodBtn">
                                <i class="fas fa-calendar-alt"></i>
                                <span id="periodLabel"><?php echo date('M Y', strtotime($period . '-01')); ?></span>
                                <i class="fas fa-chevron-down" style="font-size:.7rem;"></i>
                            </div>
                            <input type="month"
                                   id="periodInput"
                                   class="period-input-hidden"
                                   value="<?php echo htmlspecialchars($period); ?>"
                                   max="<?php echo date('Y-m'); ?>"
                                   aria-label="Monitoring period">
                        </div>
                    </div>
                </div>

                <div class="filter-bar">
                    <form method="GET" class="filter-row" id="filterForm">
                        <input type="hidden" name="view" value="monitoring">
                        <input type="hidden" name="period" value="<?php echo htmlspecialchars($period); ?>">
                        <input type="hidden" name="search" value="<?php echo htmlspecialchars($search); ?>">
                        <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>">

                        <div class="filter-item">
                            <label>Name Filter</label>
                            <input type="text"
                                   name="filter_name"
                                   class="form-control"
                                   placeholder="Filter by name"
                                   value="<?php echo htmlspecialchars($filter_name); ?>">
                        </div>

                        <div class="filter-item">
                            <label>Barangay</label>
                            <select name="filter_barangay" class="form-select">
                                <option value="">All Barangays</option>
                                <?php foreach ($filter_options_barangay as $brgy): ?>
                                    <option value="<?php echo htmlspecialchars($brgy); ?>"
                                        <?php echo $filter_barangay === $brgy ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($brgy); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="filter-item">
                            <label>Chapter</label>
                            <select name="filter_chapter" class="form-select">
                                <option value="">All Chapters</option>
                                <?php foreach ($filter_options_chapter as $chap): ?>
                                    <option value="<?php echo htmlspecialchars($chap); ?>"
                                        <?php echo $filter_chapter === $chap ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($chap); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="filter-item">
                            <label>Group</label>
                            <select name="filter_group" class="form-select">
                                <option value="">All Groups</option>
                                <?php foreach ($filter_options_group as $grp): ?>
                                    <option value="<?php echo htmlspecialchars($grp); ?>"
                                        <?php echo $filter_group === $grp ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($grp); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="filter-item">
                            <label>Per Page</label>
                            <select name="per_page" class="form-select" onchange="this.form.submit()">
                                <?php foreach ($allowed_per_page as $value): ?>
                                    <option value="<?php echo $value; ?>" <?php echo $per_page == $value ? 'selected' : ''; ?>>
                                        <?php echo $value; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="filter-actions">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-filter"></i> Apply
                            </button>
                            <a href="dashboard.php?view=monitoring" class="btn btn-secondary">
                                <i class="fas fa-undo"></i> Reset
                            </a>
                        </div>
                    </form>
                </div>

                <div class="members-filter-tabs">
                    <button class="filter-tab <?php echo $filter === 'all' ? 'active' : ''; ?>" data-filter="all">
                        All Members
                        <span class="count-badge"><?php echo $counts['all']; ?></span>
                    </button>
                    <button class="filter-tab <?php echo $filter === 'paid' ? 'active' : ''; ?>" data-filter="paid">
                        Paid
                        <span class="count-badge paid-badge"><?php echo $counts['paid']; ?></span>
                    </button>
                    <button class="filter-tab <?php echo $filter === 'unpaid' ? 'active' : ''; ?>" data-filter="unpaid">
                        Unpaid
                        <span class="count-badge unpaid-badge"><?php echo $counts['unpaid']; ?></span>
                    </button>
                    <button class="filter-tab <?php echo $filter === 'advanced' ? 'active' : ''; ?>" data-filter="advanced">
                        Advanced
                        <span class="count-badge advanced-badge"><?php echo $counts['advanced']; ?></span>
                    </button>
                    <button class="filter-tab <?php echo $filter === 'overdue' ? 'active' : ''; ?>" data-filter="overdue">
                        Overdue
                        <span class="count-badge overdue-badge"><?php echo $counts['overdue']; ?></span>
                    </button>
                </div>

                <div class="member-list-container">
                    <div class="table-scroll-wrapper">
                        <div class="table-responsive">
                            <table class="member-table" id="memberTable">
                                <thead>
                                    <tr>
                                        <th>Member Code</th>
                                        <th>Member</th>
                                        <th>Email</th>
                                        <th>Phone</th>
                                        <th>Chapter</th>
                                        <th>Status</th>
                                        <th>Payment Status</th>
                                    </tr>
                                </thead>
                                <tbody id="memberTableBody">
                                    <?php if (count($page_members) > 0): ?>
                                        <?php foreach ($page_members as $member): ?>
                                            <?php
                                                $st = $status_map[$member['member_code']] ?? [
                                                    'status' => 'unpaid', 'missed_prior' => 0, 'pending' => 0
                                                ];
                                                $scls = $st['status'];
                                                if ($scls === 'paid')         $stext = 'Paid';
                                                elseif ($scls === 'unpaid')   $stext = 'Unpaid';
                                                elseif ($scls === 'advanced') $stext = 'Advanced';
                                                else                          $stext = 'Overdue';

                                                if ($st['status'] === 'overdue' && $st['missed_prior'] > 0) {
                                                    $stext .= ' (' . $st['missed_prior'] . ' missed)';
                                                }
                                                if ($st['pending'] > 0) {
                                                    $stext .= ' (' . $st['pending'] . ' pending)';
                                                }
                                            ?>
                                            <tr class="member-row" data-status="<?php echo $scls; ?>">
                                                <td>
                                                    <span class="badge bg-secondary" style="font-size: 0.7rem;"><?php echo htmlspecialchars($member['member_code']); ?></span>
                                                </td>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?></strong>
                                                </td>
                                                <td><?php echo htmlspecialchars($member['email']); ?></td>
                                                <td><?php echo htmlspecialchars($member['phone']); ?></td>
                                                <td><?php echo htmlspecialchars($member['chapter'] ?? 'N/A'); ?></td>
                                                <td>
                                                    <span class="status-badge <?php echo $member['member_status'] === 'active' ? 'active' : 'inactive'; ?>">
                                                        <?php echo ucfirst($member['member_status']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <span class="status-badge <?php echo $scls; ?>">
                                                        <?php echo $stext; ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="7">
                                                <div class="empty-state">
                                                    <i class="fas fa-users"></i>
                                                    <p style="margin-bottom: 0;">
                                                        No members found for <strong><?php echo date('F Y', strtotime($period . '-01')); ?></strong>
                                                        <?php if (!empty($search) || !empty($filter_name) || !empty($filter_barangay) || !empty($filter_chapter) || !empty($filter_group)): ?>
                                                            matching your search or filters.
                                                        <?php endif; ?>
                                                    </p>
                                                    <?php if (!empty($search) || !empty($filter_name) || !empty($filter_barangay) || !empty($filter_chapter) || !empty($filter_group)): ?>
                                                        <a href="dashboard.php?view=monitoring" class="btn btn-outline-secondary btn-sm mt-2">
                                                            <i class="fas fa-times me-1"></i> Clear All Filters
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <?php if ($total_members > 0): ?>
                    <div class="pagination-wrapper">
                        <div class="info">
                            Showing <?php echo ($offset + 1); ?> - <?php echo min($offset + $per_page, $total_members); ?> of <?php echo $total_members; ?> members
                            for <?php echo date('F Y', strtotime($period . '-01')); ?>
                        </div>
                        <nav>
                            <ul class="pagination">
                                <?php
                                $extra_filters = [
                                    'filter_name'     => $filter_name,
                                    'filter_barangay' => $filter_barangay,
                                    'filter_chapter'  => $filter_chapter,
                                    'filter_group'    => $filter_group,
                                ];
                                ?>
                                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo buildPaginationUrl(1, $filter, $search, $per_page, $view, $period, $extra_filters); ?>">&laquo;</a>
                                </li>
                                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo buildPaginationUrl($page - 1, $filter, $search, $per_page, $view, $period, $extra_filters); ?>">&lsaquo;</a>
                                </li>
                                <?php
                                $start_page = max(1, $page - 2);
                                $end_page = min($total_pages, $page + 2);
                                if ($start_page > 1) {
                                    echo '<li class="page-item"><a class="page-link" href="' . buildPaginationUrl(1, $filter, $search, $per_page, $view, $period, $extra_filters) . '">1</a></li>';
                                    if ($start_page > 2) {
                                        echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                    }
                                }
                                for ($i = $start_page; $i <= $end_page; $i++) {
                                    echo '<li class="page-item ' . ($i == $page ? 'active' : '') . '">
                                        <a class="page-link" href="' . buildPaginationUrl($i, $filter, $search, $per_page, $view, $period, $extra_filters) . '">' . $i . '</a>
                                    </li>';
                                }
                                if ($end_page < $total_pages) {
                                    if ($end_page < $total_pages - 1) {
                                        echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                    }
                                    echo '<li class="page-item"><a class="page-link" href="' . buildPaginationUrl($total_pages, $filter, $search, $per_page, $view, $period, $extra_filters) . '">' . $total_pages . '</a></li>';
                                }
                                ?>
                                <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo buildPaginationUrl($page + 1, $filter, $search, $per_page, $view, $period, $extra_filters); ?>">&rsaquo;</a>
                                </li>
                                <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo buildPaginationUrl($total_pages, $filter, $search, $per_page, $view, $period, $extra_filters); ?>">&raquo;</a>
                                </li>
                            </ul>
                        </nav>
                    </div>
                    <?php endif; ?>
                </div>

                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // ========================================
        // SIDEBAR TOGGLE + PERSISTENCE
        // ========================================
        const sidebar = document.getElementById('sidebar-wrapper');
        const menuToggle = document.getElementById('menuToggle');
        const mobileToggle = document.getElementById('mobileMenuToggle');
        const overlay = document.getElementById('sidebarOverlay');

        if (localStorage.getItem('sidebarCollapsed') === 'true') {
            sidebar.classList.add('collapsed');
        }

        requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                document.documentElement.classList.remove('sidebar-was-collapsed');
                document.documentElement.classList.add('sidebar-transitions-on');
            });
        });

        if (menuToggle) {
            menuToggle.addEventListener('click', function() {
                sidebar.classList.toggle('collapsed');
                localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
            });
        }
        
        if (mobileToggle) {
            mobileToggle.addEventListener('click', function() {
                sidebar.classList.toggle('mobile-open');
                overlay.classList.toggle('active');
            });
        }
        
        if (overlay) {
            overlay.addEventListener('click', function() {
                sidebar.classList.remove('mobile-open');
                overlay.classList.remove('active');
            });
        }

        <?php if ($view === 'analytics'): ?>
        // ========================================
        // ANALYTICS IN-PAGE FILTER
        // ========================================
        (function() {
            const input = document.getElementById('analyticsFilter');
            if (!input) return;

            const noResults = document.getElementById('analyticsNoResults');

            function applyFilter() {
                const q = input.value.toLowerCase().trim();
                const items = document.querySelectorAll('.filterable-item');
                let visible = 0;

                items.forEach(function(el) {
                    const name = (el.dataset.name || '').toLowerCase();
                    if (!q || name.indexOf(q) !== -1) {
                        el.style.display = '';
                        visible++;
                    } else {
                        el.style.display = 'none';
                    }
                });

                if (noResults) {
                    noResults.style.display = (visible === 0 && items.length > 0) ? 'block' : 'none';
                }
            }

            input.addEventListener('input', applyFilter);
            input.addEventListener('keyup', applyFilter);

            document.addEventListener('keydown', function(e) {
                if (e.key === '/' && !['INPUT', 'TEXTAREA'].includes(e.target.tagName)) {
                    e.preventDefault();
                    input.focus();
                }
                if (e.key === 'Escape' && document.activeElement === input) {
                    input.value = '';
                    applyFilter();
                    input.blur();
                }
            });
        })();
        <?php endif; ?>

        <?php if ($view === 'monitoring'): ?>
       // ========================================
// MONITORING PERIOD PICKER
// ========================================
(function() {
    const input = document.getElementById('periodInput');
    const btn   = document.getElementById('periodBtn');
    if (!input) return;

    function openPicker() {
        if (typeof input.showPicker === 'function') {
            try { input.showPicker(); return; } catch (e) { /* already open */ }
        }
        input.focus();
        input.click();
    }

    btn?.addEventListener('click', function(e) {
        e.preventDefault();
        openPicker();
    });

    input.addEventListener('click', function() {
        if (typeof this.showPicker === 'function') {
            try { this.showPicker(); } catch (e) { /* already open */ }
        }
    });

    input.addEventListener('change', function() {
        const url = new URL(window.location.href);
        url.searchParams.set('period', this.value);
        url.searchParams.set('page', 1);
        window.location.href = url.toString();
    });
})();
        // ========================================
        // FILTER TABS
        // ========================================
        document.querySelectorAll('.filter-tab').forEach(tab => {
            tab.addEventListener('click', function() {
                const filter = this.dataset.filter;
                const url = new URL(window.location.href);
                if (filter && filter !== 'all') {
                    url.searchParams.set('filter', filter);
                } else {
                    url.searchParams.delete('filter');
                }
                url.searchParams.set('page', 1);
                window.location.href = url.toString();
            });
        });

        (function() {
            const input = document.getElementById('searchInput');
            if (input && input.value.trim() !== '') {
                const len = input.value.length;
                input.focus();
                try { input.setSelectionRange(len, len); } catch(e) {}
            }
        })();

        document.addEventListener('keydown', function(e) {
            const input = document.getElementById('searchInput');
            if (!input) return;
            if ((e.ctrlKey && e.key === 'f') || (e.key === '/' && !e.ctrlKey && !e.metaKey)) {
                if (!['INPUT', 'TEXTAREA'].includes(e.target.tagName)) {
                    e.preventDefault();
                    input.focus();
                }
            }
            if (e.key === 'Escape' && document.activeElement === input) {
                input.blur();
            }
        });
        <?php endif; ?>

        // ========================================
        // LOGO FALLBACK
        // ========================================
        document.querySelectorAll('.sidebar-heading img, .header-logo').forEach(img => {
            img.onerror = function() {
                this.style.display = 'none';
            };
        });

        console.log('✅ Dashboard loaded — view: <?php echo $view; ?>');
    </script>
</body> 
</html>