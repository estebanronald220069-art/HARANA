<?php
// admin/reports/index.php
require_once '../../includes/config.php';
require_once '../../includes/db_connection.php';
require_once '../../includes/security.php';
require_once '../../includes/auth.php';

$auth->requireLogin();
$current_user = $auth->getCurrentUser();
$db = Database::getInstance();

// Get pending users count
$pending_count = 0;
if ($current_user['role'] === 'admin') {
    $pending_count = $db->getSingle("SELECT COUNT(*) as cnt FROM pending_users WHERE status = 'pending'")['cnt'] ?? 0;
}

// Get parameters
$report_view = isset($_GET['report_view']) ? $_GET['report_view'] : 'dashboard';
$selected_period = isset($_GET['period']) ? $_GET['period'] : 'monthly';
$filter_chapter = isset($_GET['filter_chapter']) ? $_GET['filter_chapter'] : '';
$filter_status = isset($_GET['filter_status']) ? $_GET['filter_status'] : '';
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01');
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');

$csrf_token = Security::generateCSRFToken();

// ============ GET CHAPTERS ============
$chapters = $db->getAll("SELECT DISTINCT chapter FROM members WHERE status = 'active' AND chapter IS NOT NULL AND chapter != '' ORDER BY chapter ASC");
$chapter_list = array_column($chapters, 'chapter');

// ============ GET STATS ============
$total_collections = $db->getSingle("SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE payment_status = 'confirmed'")['total'] ?? 0;
$current_month_collections = $db->getSingle("
    SELECT COALESCE(SUM(amount), 0) as total 
    FROM payments 
    WHERE payment_status = 'confirmed' 
    AND MONTH(payment_date) = MONTH(CURDATE()) 
    AND YEAR(payment_date) = YEAR(CURDATE())
")['total'] ?? 0;
$total_active_members = $db->getSingle("SELECT COUNT(*) as total FROM members WHERE status = 'active'")['total'] ?? 0;
$pending_payments = $db->getSingle("SELECT COUNT(*) as cnt FROM payments WHERE payment_status = 'pending'")['cnt'] ?? 0;
$expected_monthly = $total_active_members * 100;
$collection_rate = $expected_monthly > 0 ? round(($current_month_collections / $expected_monthly) * 100, 1) : 0;
$overdue_accounts = $db->getSingle("
    SELECT COUNT(DISTINCT m.member_code) as cnt
    FROM members m
    LEFT JOIN member_balances b ON m.member_code = b.member_code
    WHERE m.status = 'active' AND (b.current_balance > 0 OR b.current_balance IS NULL)
")['cnt'] ?? 0;

// ============ GET CHART DATA ============
// Monthly Collections
$monthly_data = [];
for ($i = 11; $i >= 0; $i--) {
    $month = date('Y-m', strtotime("-$i months"));
    $month_name = date('M Y', strtotime("-$i months"));
    $total = $db->getSingle("
        SELECT COALESCE(SUM(amount), 0) as total 
        FROM payments 
        WHERE payment_status = 'confirmed' 
        AND DATE_FORMAT(payment_date, '%Y-%m') = ?
    ", [$month], 's')['total'] ?? 0;
    $monthly_data[] = ['month' => $month_name, 'total' => $total];
}

// Payment Methods
$payment_methods = $db->getAll("
    SELECT payment_method, COUNT(*) as count, SUM(amount) as total
    FROM payments 
    WHERE payment_status = 'confirmed'
    GROUP BY payment_method
");

// Member Growth
$member_growth = [];
for ($i = 11; $i >= 0; $i--) {
    $month_end = date('Y-m-t', strtotime("-$i months"));
    $month_name = date('M Y', strtotime("-$i months"));
    $count = $db->getSingle("
        SELECT COUNT(*) as cnt 
        FROM members 
        WHERE created_at <= ?
    ", [$month_end], 's')['cnt'] ?? 0;
    $member_growth[] = ['month' => $month_name, 'count' => $count];
}

// Chapter Performance
$chapter_performance = $db->getAll("
    SELECT 
        m.chapter,
        COUNT(DISTINCT m.member_code) as member_count,
        COALESCE(SUM(p.amount), 0) as total_collected,
        COALESCE(SUM(p.amount), 0) / GREATEST(COUNT(DISTINCT m.member_code) * 100, 1) * 100 as collection_rate
    FROM members m
    LEFT JOIN payments p ON m.member_code = p.member_id AND p.payment_status = 'confirmed'
    WHERE m.status = 'active' AND m.chapter IS NOT NULL AND m.chapter != ''
    GROUP BY m.chapter
    ORDER BY total_collected DESC
    LIMIT 10
");

// Top Payers
$top_payers = $db->getAll("
    SELECT 
        m.member_code,
        CONCAT(m.first_name, ' ', m.last_name) as member_name,
        m.chapter,
        COALESCE(SUM(p.amount), 0) as total_paid,
        COUNT(p.payment_id) as payment_count
    FROM members m
    LEFT JOIN payments p ON m.member_code = p.member_id AND p.payment_status = 'confirmed'
    WHERE m.status = 'active'
    GROUP BY m.member_code
    HAVING total_paid > 0
    ORDER BY total_paid DESC
    LIMIT 10
");

// Aging Report
$aging_report = $db->getAll("
    SELECT 
        m.member_code,
        CONCAT(m.first_name, ' ', m.last_name) as member_name,
        m.chapter,
        m.monthly_contribution,
        TIMESTAMPDIFF(MONTH, COALESCE(b.last_payment_date, m.date_joined), CURDATE()) as months_overdue,
        COALESCE(b.current_balance, 0) as balance_due
    FROM members m
    LEFT JOIN member_balances b ON m.member_code = b.member_code
    WHERE m.status = 'active' 
    AND (b.current_balance > 0 OR b.current_balance IS NULL)
    ORDER BY months_overdue DESC
    LIMIT 20
");

// New Members
$new_members = $db->getAll("
    SELECT 
        DATE_FORMAT(created_at, '%Y-%m') as period,
        COUNT(*) as count
    FROM members
    WHERE YEAR(created_at) = YEAR(CURDATE())
    GROUP BY DATE_FORMAT(created_at, '%Y-%m')
    ORDER BY period DESC
    LIMIT 12
");

// Inactive Members
$inactive_members = $db->getAll("
    SELECT 
        member_code,
        CONCAT(first_name, ' ', last_name) as member_name,
        chapter,
        status,
        inactive_date
    FROM members
    WHERE status IN ('inactive', 'deceased')
    ORDER BY inactive_date DESC
    LIMIT 20
");

// Beneficiary Report
$beneficiary_report = $db->getAll("
    SELECT 
        member_code,
        CONCAT(first_name, ' ', last_name) as member_name,
        chapter,
        beneficiary_name,
        beneficiary_relation
    FROM members
    WHERE status = 'active' 
    AND beneficiary_name IS NOT NULL 
    AND beneficiary_name != ''
    ORDER BY member_name
");
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* ===== BASE ===== */
        * { box-sizing: border-box; }
        body {
            background: #f4f7fc;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            overflow: hidden;
            margin: 0;
            padding: 0;
        }
        
        #wrapper {
            display: flex;
            width: 100%;
            height: 100vh;
            overflow: hidden;
        }
        
        /* ===== SIDEBAR ===== */
        #sidebar-wrapper {
            background: #375a7f;
            color: #fff;
            width: 250px;
            height: 100vh;
            overflow-y: auto;
            transition: width 0.3s ease;
            box-shadow: 4px 0 10px rgba(0,0,0,0.1);
            white-space: nowrap;
            flex-shrink: 0;
            z-index: 1000;
        }
        
        #sidebar-wrapper.collapsed { width: 70px; }
        #sidebar-wrapper.collapsed .sidebar-heading span { display: none; }
        #sidebar-wrapper.collapsed .list-group-item span { display: none; }
        #sidebar-wrapper.collapsed .list-group-item i { margin-right: 0; width: 100%; text-align: center; font-size: 1.2rem; }
        #sidebar-wrapper.collapsed .list-group-item { padding: 15px 0; text-align: center; }
        #sidebar-wrapper.collapsed .badge { display: none; }
        #sidebar-wrapper.collapsed .sidebar-heading img { display: none; }
        
        #sidebar-wrapper .sidebar-heading {
            padding: 1.2rem 1rem;
            font-size: 1.4rem;
            font-weight: 600;
            border-bottom: 1px solid rgba(255,255,255,0.1);
            background: #375a7f;
            color: white;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        #sidebar-wrapper .sidebar-heading img { height: 30px; width: auto; margin-right: 10px; }
        #sidebar-wrapper .list-group-item {
            background: transparent;
            border: none;
            color: rgba(255,255,255,0.9);
            padding: 0.8rem 1.2rem;
            font-weight: 500;
            transition: all 0.2s;
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        #sidebar-wrapper .list-group-item i { width: 24px; text-align: center; }
        #sidebar-wrapper .list-group-item:hover,
        #sidebar-wrapper .list-group-item.active {
            background: rgba(255,255,255,0.15);
            color: #fff;
            border-left: 4px solid #fff;
        }
        #sidebar-wrapper .list-group-item .badge-count { margin-left: auto; font-size: 0.7rem; }
        
        .menu-toggle {
            background: transparent;
            border: none;
            font-size: 1.5rem;
            color: white;
            cursor: pointer;
            padding: 0 10px;
        }
        
        /* ===== PAGE CONTENT ===== */
        #page-content-wrapper {
            flex: 1;
            background: #f4f7fc;
            height: 100vh;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
        }
        
        /* ===== NAVBAR ===== */
        .navbar {
            background: #fff !important;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            padding: 0.5rem 1.2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
            flex-wrap: wrap;
            gap: 10px;
            min-height: 50px;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .navbar-left { display: flex; align-items: center; gap: 12px; }
        .navbar-brand { font-size: 1.1rem; font-weight: 600; color: #375a7f !important; display: flex; align-items: center; gap: 8px; }
        .navbar-right { display: flex; align-items: center; gap: 15px; flex-wrap: wrap; }
        .header-logo { height: 28px; width: auto; margin-right: 8px; display: none; }
        #sidebar-wrapper.collapsed ~ #page-content-wrapper .header-logo { display: inline-block; }
        
        /* ===== STATS CARDS - 10% BIGGER ===== */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(165px, 1fr));
            gap: 8px;
            margin-bottom: 10px;
            position: sticky;
            top: 0;
            z-index: 99;
            background: #f4f7fc;
            padding: 6px 0;
        }
        
        .stat-card {
            background: white;
            border-radius: 9px;
            padding: 8px 12px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.06);
            border-left: 3px solid #375a7f;
            cursor: default;
            overflow: hidden;
        }
        .stat-card .stat-number { 
            font-size: 1.05rem; 
            font-weight: 600; 
            color: #2c3e50; 
            line-height: 1.2; 
        }
        .stat-card .stat-label { 
            font-size: 0.55rem; 
            color: #6c757d; 
            text-transform: uppercase; 
            letter-spacing: 0.2px; 
            margin-top: 0; 
            white-space: nowrap; 
            overflow: hidden; 
            text-overflow: ellipsis; 
        }
        .stat-card .stat-trend { 
            font-size: 0.44rem; 
            margin-top: 0; 
            white-space: nowrap; 
            overflow: hidden; 
            text-overflow: ellipsis; 
        }
        .stat-card .stat-icon { 
            font-size: 0.9rem; 
            opacity: 0.15; 
            float: right; 
        }
        .stat-card:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,0,0,0.10); }
        
        /* ===== TOOLBAR ===== */
        .toolbar-row {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 10px;
            background: white;
            padding: 8px 14px;
            border-radius: 10px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.06);
        }
        
        .filter-select {
            padding: 3px 8px;
            border: 1.5px solid #e9ecef;
            border-radius: 6px;
            font-size: 0.75rem;
            background: #f8f9fa;
            height: 28px;
        }
        .filter-select:focus { border-color: #375a7f; outline: none; }
        
        .btn-sm-custom {
            padding: 3px 12px;
            font-size: 0.72rem;
            border-radius: 6px;
            border: none;
            height: 28px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            cursor: pointer;
            transition: all 0.2s;
        }
        .btn-sm-custom:hover { transform: translateY(-1px); }
        .btn-export-csv { background: #28a745; color: white; }
        .btn-export-csv:hover { background: #1e7e34; color: white; }
        .btn-export-pdf { background: #dc3545; color: white; }
        .btn-export-pdf:hover { background: #b02a37; color: white; }
        .btn-print { background: #6c757d; color: white; }
        .btn-print:hover { background: #5a6268; color: white; }
        .btn-filter-apply { background: #375a7f; color: white; }
        .btn-filter-apply:hover { background: #2c4a6b; color: white; }
        .btn-filter-clear { background: #6c757d; color: white; }
        .btn-filter-clear:hover { background: #5a6268; color: white; }
        
        /* ===== PERIOD SELECTOR ===== */
        .period-selector {
            display: flex;
            gap: 4px;
            flex-wrap: wrap;
            align-items: center;
            margin-bottom: 10px;
        }
        
        .period-btn {
            padding: 3px 12px;
            border-radius: 14px;
            text-decoration: none;
            color: #6c757d;
            background: white;
            border: 1px solid #dee2e6;
            font-size: 0.72rem;
        }
        .period-btn:hover { background: #f8f9fa; color: #375a7f; }
        .period-btn.active { background: #375a7f; color: white; border-color: #375a7f; }
        
        /* ===== REPORT TABS - FIXED/STICKY ===== */
        .report-tabs {
            display: flex;
            gap: 4px;
            flex-wrap: wrap;
            margin-bottom: 12px;
            background: white;
            padding: 4px 8px;
            border-radius: 10px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.06);
            position: sticky;
            top: 0;
            z-index: 98;
            background: #f4f7fc;
            padding: 6px 0;
        }
        
        .report-tab {
            padding: 5px 14px;
            border-radius: 6px;
            text-decoration: none;
            color: #6c757d;
            font-weight: 500;
            font-size: 0.78rem;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: white;
            border: 1px solid #e9ecef;
            transition: all 0.2s;
        }
        .report-tab:hover { background: #f8f9fa; color: #375a7f; }
        .report-tab.active { background: #375a7f; color: white; border-color: #375a7f; }
        
        /* ===== CHART CARDS ===== */
        .chart-card {
            background: white;
            border-radius: 10px;
            padding: 12px 14px;
            margin-bottom: 14px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.06);
            transition: all 0.3s ease;
            cursor: pointer;
        }
        .chart-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,0.10); transform: translateY(-2px); }
        
        .chart-card .chart-title {
            font-weight: 600;
            margin-bottom: 8px;
            color: #2c3e50;
            font-size: 0.85rem;
            border-left: 3px solid #375a7f;
            padding-left: 10px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            pointer-events: none;
        }
        .chart-card .chart-title .badge-count {
            font-size: 0.6rem;
            font-weight: 400;
            background: #e9ecef;
            color: #6c757d;
            padding: 1px 8px;
            border-radius: 10px;
        }
        .chart-card .chart-container { position: relative; height: 160px; pointer-events: none; }
        .chart-card .click-hint {
            position: absolute;
            top: 8px;
            right: 8px;
            font-size: 0.5rem;
            color: #adb5bd;
            background: rgba(255,255,255,0.8);
            padding: 1px 8px;
            border-radius: 10px;
            border: 1px solid #e9ecef;
            opacity: 0;
            transition: opacity 0.3s;
            pointer-events: none;
        }
        .chart-card:hover .click-hint { opacity: 1; }
        
        /* ===== TABLES ===== */
        .table-responsive-custom {
            max-height: 220px;
            overflow-y: auto;
            border-radius: 8px;
            border: 1px solid #e9ecef;
        }
        .table-custom {
            width: 100%;
            background: white;
            border-collapse: collapse;
            font-size: 0.78rem;
        }
        .table-custom th {
            background: #f8f9fa;
            padding: 6px 10px;
            font-size: 0.6rem;
            text-transform: uppercase;
            color: #6c757d;
            font-weight: 600;
            border-bottom: 2px solid #dee2e6;
            position: sticky;
            top: 0;
            z-index: 5;
            white-space: nowrap;
        }
        .table-custom td {
            padding: 5px 10px;
            border-bottom: 1px solid #e9ecef;
            font-size: 0.78rem;
            color: #2c3e50;
            vertical-align: middle;
        }
        .table-custom tr:hover td { background: #f8f9fa; }
        .table-custom .text-success { color: #28a745 !important; font-weight: 600; }
        .table-custom .text-danger { color: #dc3545 !important; font-weight: 600; }
        
        /* ===== MODAL ===== */
        .modal-content { border-radius: 14px; border: none; }
        .modal-header {
            background: linear-gradient(135deg, #375a7f, #2c4a6b);
            color: white;
            border-radius: 14px 14px 0 0;
            padding: 14px 20px;
        }
        .modal-header .btn-close { filter: brightness(0) invert(1); }
        .modal-body { padding: 20px; max-height: 70vh; overflow-y: auto; }
        .modal-body .chart-container-modal { height: 280px; position: relative; }
        
        /* ===== RESPONSIVE ===== */
        @media (max-width: 992px) {
            #sidebar-wrapper { width: 70px; }
            #sidebar-wrapper .sidebar-heading span { display: none; }
            #sidebar-wrapper .list-group-item span { display: none; }
            #sidebar-wrapper .list-group-item i { margin-right: 0; width: 100%; font-size: 1.2rem; }
            #sidebar-wrapper .list-group-item { padding: 15px 0; text-align: center; }
            #sidebar-wrapper .badge { display: none; }
            #sidebar-wrapper .sidebar-heading img { display: none; }
            #sidebar-wrapper .sidebar-heading { justify-content: center; padding: 1.2rem 0; }
            .header-logo { display: inline-block !important; }
            .toolbar-row { flex-direction: column; align-items: stretch; }
            .toolbar-left { justify-content: center; }
            .toolbar-right { justify-content: center; }
        }
        
        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: repeat(3, 1fr); gap: 4px; }
            .stat-card { padding: 4px 8px; }
            .stat-card .stat-number { font-size: 0.8rem; }
            .stat-card .stat-label { font-size: 0.4rem; }
            .report-tabs { justify-content: center; }
            .report-tab { font-size: 0.65rem; padding: 3px 8px; }
            .period-selector { justify-content: center; }
            .period-btn { font-size: 0.65rem; padding: 2px 8px; }
            .chart-card .chart-container { height: 120px; }
            .modal-body .chart-container-modal { height: 200px; }
        }
        
        @media (max-width: 480px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); gap: 4px; }
            .stat-card { padding: 4px 6px; }
            .stat-card .stat-number { font-size: 0.7rem; }
            .stat-card .stat-label { font-size: 0.35rem; }
            .toolbar-left { flex-direction: column; align-items: stretch; }
            .toolbar-right { flex-direction: column; align-items: stretch; }
            .btn-sm-custom { justify-content: center; }
            .filter-select { width: 100%; }
        }
        
        .sidebar-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1040;
        }
        .sidebar-overlay.active { display: block; }
        
        @media (max-width: 768px) {
            #sidebar-wrapper {
                position: fixed;
                left: 0;
                top: 0;
                transform: translateX(-100%);
                width: 280px;
                height: 100vh;
                z-index: 1050;
                transition: transform 0.3s ease;
            }
            #sidebar-wrapper.mobile-open { transform: translateX(0); }
            .sidebar-overlay.active { display: block; }
            .header-logo { display: inline-block !important; }
        }
        
        ::-webkit-scrollbar { width: 6px; height: 6px; }
        ::-webkit-scrollbar-track { background: #f1f1f1; border-radius: 3px; }
        ::-webkit-scrollbar-thumb { background: #c1c7cd; border-radius: 3px; }
        ::-webkit-scrollbar-thumb:hover { background: #a0a7ad; }
        
        .btn-mobile-menu {
            display: none;
            background: none;
            border: none;
            color: #375a7f;
            font-size: 1.3rem;
            padding: 0;
        }
        @media (max-width: 768px) { .btn-mobile-menu { display: block; } }
    </style>
</head>
<body>
    <!-- Mobile overlay -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <div id="wrapper">
        <!-- Sidebar -->
        <div id="sidebar-wrapper">
            <div class="sidebar-heading">
                <img src="../../assets/images/harana-logo.png" alt="Harana" onerror="this.src=''; this.onerror=null; this.innerHTML='Harana';">
                <span>Harana</span>
                <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
            </div>
            <div class="list-group list-group-flush mt-2">
                <a href="../dashboard.php" class="list-group-item"><i class="fas fa-tachometer-alt"></i><span>Dashboard</span></a>
                <a href="../members.php" class="list-group-item"><i class="fas fa-users"></i><span>Members</span></a>
                <a href="../council.php" class="list-group-item"><i class="fas fa-user-tie"></i><span>Council</span></a>
                <a href="../payments.php" class="list-group-item"><i class="fas fa-credit-card"></i><span>Payments</span></a>
                <a href="index.php" class="list-group-item active"><i class="fas fa-chart-bar"></i><span>Reports</span></a>
                <?php if ($current_user['role'] === 'admin'): ?>
                <a href="../pending_users.php" class="list-group-item position-relative">
                    <i class="fas fa-user-clock"></i><span>Pending</span>
                    <?php if ($pending_count > 0): ?>
                        <span class="badge bg-danger badge-count"><?php echo $pending_count; ?></span>
                    <?php endif; ?>
                </a>
                <?php endif; ?>
                <a href="../settings.php" class="list-group-item"><i class="fas fa-cog"></i><span>Settings</span></a>
                <a href="../../logout.php" class="list-group-item" onclick="return confirm('Are you sure?');"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a>
            </div>
        </div>

        <!-- Main Content -->
        <div id="page-content-wrapper">
            <!-- Navbar -->
            <nav class="navbar navbar-light bg-light">
                <div class="navbar-left">
                    <button class="btn-mobile-menu" id="mobileMenuToggle">
                        <i class="fas fa-bars"></i>
                    </button>
                    <img src="../../assets/images/harana-logo.png" alt="Harana" class="header-logo" id="headerLogo" onerror="this.style.display='none';">
                    <span class="navbar-brand"><i class="fas fa-chart-bar me-2"></i>Reports & Analytics</span>
                </div>
                <div class="navbar-right">
                    <span class="text-muted small d-none d-sm-inline">
                        <i class="fas fa-calendar-alt me-1"></i><?php echo date('F j, Y'); ?>
                    </span>
                    <span class="small"><i class="fas fa-user-circle me-2"></i><?php echo htmlspecialchars($current_user['full_name'] ?? $current_user['username']); ?></span>
                </div>
            </nav>

            <!-- ===== PERMANENT COMPACT STAT CARDS ===== -->
            <div class="stats-grid" id="statsGrid">
                <div class="stat-card" style="border-left-color: #375a7f;">
                    <div class="stat-icon"><i class="fas fa-coins"></i></div>
                    <div class="stat-number">₱<?php echo number_format($total_collections / 1000, 1); ?>k</div>
                    <div class="stat-label">Total Collections</div>
                    <div class="stat-trend text-success"><i class="fas fa-chart-line"></i> All time</div>
                </div>
                <div class="stat-card" style="border-left-color: #28a745;">
                    <div class="stat-icon"><i class="fas fa-calendar-day"></i></div>
                    <div class="stat-number">₱<?php echo number_format($current_month_collections / 1000, 1); ?>k</div>
                    <div class="stat-label">This Month</div>
                    <div class="stat-trend text-success"><i class="fas fa-calendar"></i> <?php echo date('F'); ?></div>
                </div>
                <div class="stat-card" style="border-left-color: #17a2b8;">
                    <div class="stat-icon"><i class="fas fa-users"></i></div>
                    <div class="stat-number"><?php echo $total_active_members; ?></div>
                    <div class="stat-label">Active Members</div>
                    <div class="stat-trend text-info"><i class="fas fa-users"></i> Total</div>
                </div>
                <div class="stat-card" style="border-left-color: #ffc107;">
                    <div class="stat-icon"><i class="fas fa-clock"></i></div>
                    <div class="stat-number"><?php echo $pending_payments; ?></div>
                    <div class="stat-label">Pending Payments</div>
                    <div class="stat-trend text-warning"><i class="fas fa-clock"></i> Awaiting</div>
                </div>
                <div class="stat-card" style="border-left-color: #28a745;">
                    <div class="stat-icon"><i class="fas fa-percent"></i></div>
                    <div class="stat-number"><?php echo $collection_rate; ?>%</div>
                    <div class="stat-label">Collection Rate</div>
                    <div class="stat-trend text-success"><i class="fas fa-percent"></i> This month</div>
                </div>
                <div class="stat-card" style="border-left-color: #dc3545;">
                    <div class="stat-icon"><i class="fas fa-exclamation-triangle"></i></div>
                    <div class="stat-number"><?php echo $overdue_accounts; ?></div>
                    <div class="stat-label">Overdue Accounts</div>
                    <div class="stat-trend text-danger"><i class="fas fa-exclamation-triangle"></i> Needs attention</div>
                </div>
            </div>

            <!-- ===== TOOLBAR ===== -->
            <div class="toolbar-row" id="toolbarRow">
                <div class="toolbar-left">
                    <select class="filter-select" id="filterChapter">
                        <option value="">All Chapters</option>
                        <?php foreach ($chapter_list as $chapter): ?>
                            <option value="<?php echo htmlspecialchars($chapter); ?>" <?php echo $filter_chapter == $chapter ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($chapter); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <select class="filter-select" id="filterStatus">
                        <option value="">All Status</option>
                        <option value="active" <?php echo $filter_status == 'active' ? 'selected' : ''; ?>>Active</option>
                        <option value="inactive" <?php echo $filter_status == 'inactive' ? 'selected' : ''; ?>>Inactive</option>
                        <option value="deceased" <?php echo $filter_status == 'deceased' ? 'selected' : ''; ?>>Deceased</option>
                    </select>
                    <button class="btn-sm-custom btn-filter-apply" onclick="applyFilters()">
                        <i class="fas fa-filter"></i> Apply
                    </button>
                    <button class="btn-sm-custom btn-filter-clear" onclick="clearFilters()">
                        <i class="fas fa-times"></i> Clear
                    </button>
                </div>
                <div class="toolbar-right">
                    <button class="btn-sm-custom btn-export-csv" onclick="exportData('csv')">
                        <i class="fas fa-file-csv"></i> CSV
                    </button>
                    <button class="btn-sm-custom btn-export-pdf" onclick="exportData('pdf')">
                        <i class="fas fa-file-pdf"></i> PDF
                    </button>
                    <button class="btn-sm-custom btn-print" onclick="window.print()">
                        <i class="fas fa-print"></i> Print
                    </button>
                </div>
            </div>

            <!-- ===== PERIOD SELECTOR ===== -->
            <div class="period-selector" id="periodSelector">
                <a href="?period=monthly&report_view=<?php echo $report_view; ?>&filter_chapter=<?php echo $filter_chapter; ?>&filter_status=<?php echo $filter_status; ?>" class="period-btn <?php echo $selected_period == 'monthly' ? 'active' : ''; ?>">Monthly</a>
                <a href="?period=quarterly&report_view=<?php echo $report_view; ?>&filter_chapter=<?php echo $filter_chapter; ?>&filter_status=<?php echo $filter_status; ?>" class="period-btn <?php echo $selected_period == 'quarterly' ? 'active' : ''; ?>">Quarterly</a>
                <a href="?period=yearly&report_view=<?php echo $report_view; ?>&filter_chapter=<?php echo $filter_chapter; ?>&filter_status=<?php echo $filter_status; ?>" class="period-btn <?php echo $selected_period == 'yearly' ? 'active' : ''; ?>">Yearly</a>
                <a href="?period=custom&report_view=<?php echo $report_view; ?>&filter_chapter=<?php echo $filter_chapter; ?>&filter_status=<?php echo $filter_status; ?>" class="period-btn <?php echo $selected_period == 'custom' ? 'active' : ''; ?>">Custom</a>
                <?php if ($selected_period == 'custom'): ?>
                <form method="GET" class="d-inline-flex gap-2 align-items-center">
                    <input type="hidden" name="period" value="custom">
                    <input type="hidden" name="report_view" value="<?php echo $report_view; ?>">
                    <input type="hidden" name="filter_chapter" value="<?php echo $filter_chapter; ?>">
                    <input type="hidden" name="filter_status" value="<?php echo $filter_status; ?>">
                    <input type="date" name="date_from" value="<?php echo $date_from; ?>" class="filter-select" style="width: 110px;">
                    <input type="date" name="date_to" value="<?php echo $date_to; ?>" class="filter-select" style="width: 110px;">
                    <button type="submit" class="btn-sm-custom btn-filter-apply">Apply</button>
                </form>
                <?php endif; ?>
            </div>

            <!-- ===== REPORT TABS - FIXED/STICKY ===== -->
            <div class="report-tabs" id="reportTabs">
                <a href="?report_view=dashboard&period=<?php echo $selected_period; ?>&filter_chapter=<?php echo $filter_chapter; ?>&filter_status=<?php echo $filter_status; ?>" class="report-tab <?php echo $report_view == 'dashboard' ? 'active' : ''; ?>">
                    <i class="fas fa-chart-pie"></i> Dashboard
                </a>
                <a href="?report_view=financial&period=<?php echo $selected_period; ?>&filter_chapter=<?php echo $filter_chapter; ?>&filter_status=<?php echo $filter_status; ?>" class="report-tab <?php echo $report_view == 'financial' ? 'active' : ''; ?>">
                    <i class="fas fa-chart-line"></i> Financial
                </a>
                <a href="?report_view=member&period=<?php echo $selected_period; ?>&filter_chapter=<?php echo $filter_chapter; ?>&filter_status=<?php echo $filter_status; ?>" class="report-tab <?php echo $report_view == 'member' ? 'active' : ''; ?>">
                    <i class="fas fa-users"></i> Member
                </a>
                <a href="?report_view=compliance&period=<?php echo $selected_period; ?>&filter_chapter=<?php echo $filter_chapter; ?>&filter_status=<?php echo $filter_status; ?>" class="report-tab <?php echo $report_view == 'compliance' ? 'active' : ''; ?>">
                    <i class="fas fa-check-double"></i> Compliance
                </a>
                <a href="?report_view=performance&period=<?php echo $selected_period; ?>&filter_chapter=<?php echo $filter_chapter; ?>&filter_status=<?php echo $filter_status; ?>" class="report-tab <?php echo $report_view == 'performance' ? 'active' : ''; ?>">
                    <i class="fas fa-trophy"></i> Performance
                </a>
            </div>

            <!-- ===== CONTENT AREA ===== -->
            <div class="content-container" id="contentArea" style="padding-top: 0;">
                <?php if ($report_view == 'dashboard'): ?>
                <!-- ===== DASHBOARD VIEW ===== -->
                <div class="row g-3">
                    <div class="col-lg-6">
                        <div class="chart-card" onclick="openModal('collections', 'Monthly Collections', <?php echo json_encode($monthly_data); ?>, 'bar')">
                            <div class="chart-title">
                                <span><i class="fas fa-chart-line me-2"></i> Monthly Collections</span>
                                <span class="badge-count">Click to view details</span>
                            </div>
                            <div class="chart-container">
                                <canvas id="collectionsChart"></canvas>
                            </div>
                            <div class="click-hint"><i class="fas fa-hand-pointer"></i> Click for details</div>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="chart-card" onclick="openModal('payment_methods', 'Payment Methods', <?php echo json_encode($payment_methods); ?>, 'pie')">
                            <div class="chart-title">
                                <span><i class="fas fa-chart-pie me-2"></i> Payment Methods</span>
                                <span class="badge-count">Click to view details</span>
                            </div>
                            <div class="chart-container">
                                <canvas id="paymentMethodsChart"></canvas>
                            </div>
                            <div class="click-hint"><i class="fas fa-hand-pointer"></i> Click for details</div>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="chart-card" onclick="openModal('member_growth', 'Member Growth', <?php echo json_encode($member_growth); ?>, 'line')">
                            <div class="chart-title">
                                <span><i class="fas fa-chart-line me-2"></i> Member Growth</span>
                                <span class="badge-count">Click to view details</span>
                            </div>
                            <div class="chart-container">
                                <canvas id="memberGrowthChart"></canvas>
                            </div>
                            <div class="click-hint"><i class="fas fa-hand-pointer"></i> Click for details</div>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="chart-card" onclick="openModal('top_payers', 'Top Payers', <?php echo json_encode($top_payers); ?>, 'table')">
                            <div class="chart-title">
                                <span><i class="fas fa-trophy me-2"></i> Top Payers</span>
                                <span class="badge-count">Click to view details</span>
                            </div>
                            <div class="table-responsive-custom" style="max-height: 170px; pointer-events: none;">
                                <table class="table-custom">
                                    <thead><tr><th>Member</th><th>Chapter</th><th>Total Paid</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($top_payers as $payer): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($payer['member_name']); ?></td>
                                            <td><?php echo htmlspecialchars($payer['chapter']); ?></td>
                                            <td class="text-success">₱<?php echo number_format($payer['total_paid'], 2); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="click-hint"><i class="fas fa-hand-pointer"></i> Click for details</div>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="chart-card" onclick="openModal('chapter_performance', 'Chapter Performance', <?php echo json_encode($chapter_performance); ?>, 'bar')">
                            <div class="chart-title">
                                <span><i class="fas fa-chart-bar me-2"></i> Chapter Performance</span>
                                <span class="badge-count">Click to view details</span>
                            </div>
                            <div class="chart-container">
                                <canvas id="chapterPerformanceChart"></canvas>
                            </div>
                            <div class="click-hint"><i class="fas fa-hand-pointer"></i> Click for details</div>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="chart-card" onclick="openModal('aging_report', 'Aging Report', <?php echo json_encode($aging_report); ?>, 'table')">
                            <div class="chart-title">
                                <span><i class="fas fa-clock me-2"></i> Aging Report</span>
                                <span class="badge-count">Click to view details</span>
                            </div>
                            <div class="table-responsive-custom" style="max-height: 170px; pointer-events: none;">
                                <table class="table-custom">
                                    <thead><tr><th>Member</th><th>Chapter</th><th>Months</th><th>Balance</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($aging_report as $aging): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($aging['member_name']); ?></td>
                                            <td><?php echo htmlspecialchars($aging['chapter']); ?></td>
                                            <td class="text-danger"><?php echo $aging['months_overdue']; ?> mo</td>
                                            <td class="text-danger">₱<?php echo number_format($aging['balance_due'], 2); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="click-hint"><i class="fas fa-hand-pointer"></i> Click for details</div>
                        </div>
                    </div>
                </div>

                <!-- Additional Reports -->
                <div class="row g-3 mt-1">
                    <div class="col-md-6">
                        <div class="chart-card" onclick="openModal('new_members', 'New Members (<?php echo ucfirst($selected_period); ?>)', <?php echo json_encode($new_members); ?>, 'table')">
                            <div class="chart-title">
                                <span><i class="fas fa-calendar-plus me-2"></i> New Members (<?php echo ucfirst($selected_period); ?>)</span>
                                <span class="badge-count">Click to view details</span>
                            </div>
                            <div class="table-responsive-custom" style="max-height: 170px; pointer-events: none;">
                                <table class="table-custom">
                                    <thead><tr><th>Period</th><th>New Members</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($new_members as $nm): ?>
                                        <tr><td><?php echo $nm['period']; ?></td><td><?php echo $nm['count']; ?></td></tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="click-hint"><i class="fas fa-hand-pointer"></i> Click for details</div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="chart-card" onclick="openModal('inactive_members', 'Inactive/Deceased Members', <?php echo json_encode($inactive_members); ?>, 'table')">
                            <div class="chart-title">
                                <span><i class="fas fa-user-slash me-2"></i> Inactive/Deceased</span>
                                <span class="badge-count">Click to view details</span>
                            </div>
                            <div class="table-responsive-custom" style="max-height: 170px; pointer-events: none;">
                                <table class="table-custom">
                                    <thead><tr><th>Member</th><th>Chapter</th><th>Status</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($inactive_members as $im): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($im['member_name']); ?></td>
                                            <td><?php echo htmlspecialchars($im['chapter']); ?></td>
                                            <td><span class="badge bg-secondary"><?php echo ucfirst($im['status']); ?></span></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="click-hint"><i class="fas fa-hand-pointer"></i> Click for details</div>
                        </div>
                    </div>
                </div>

                <!-- Beneficiary Report (not clickable) -->
                <div class="chart-card" style="cursor: default;">
                    <div class="chart-title"><i class="fas fa-heart me-2"></i> Beneficiary Report</div>
                    <div class="table-responsive-custom" style="max-height: 180px;">
                        <table class="table-custom">
                            <thead><tr><th>Member</th><th>Chapter</th><th>Beneficiary</th><th>Relation</th></tr></thead>
                            <tbody>
                                <?php foreach ($beneficiary_report as $ben): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($ben['member_name']); ?></td>
                                    <td><?php echo htmlspecialchars($ben['chapter']); ?></td>
                                    <td><?php echo htmlspecialchars($ben['beneficiary_name']); ?></td>
                                    <td><?php echo htmlspecialchars($ben['beneficiary_relation']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <?php else: ?>
                <!-- ===== DETAILED REPORT VIEW ===== -->
                <div class="chart-card" style="cursor: default;">
                    <div class="chart-title">
                        <span><i class="fas fa-file-alt me-2"></i> Detailed Report</span>
                        <span class="badge-count">Coming soon</span>
                    </div>
                    <div class="empty-state text-center py-5">
                        <i class="fas fa-chart-bar fa-3x text-muted"></i>
                        <p class="mt-3 text-muted">Detailed report view is under construction.</p>
                        <p class="text-muted small">Please use the Dashboard view for now.</p>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Modal -->
    <div class="modal fade" id="detailModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">Details</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="modalBody">
                    <div class="text-center py-4">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // ========================================
        // SIDEBAR TOGGLE
        // ========================================
        const sidebar = document.getElementById('sidebar-wrapper');
        const menuToggle = document.getElementById('menuToggle');
        const mobileToggle = document.getElementById('mobileMenuToggle');
        const overlay = document.getElementById('sidebarOverlay');
        
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
        
        if (localStorage.getItem('sidebarCollapsed') === 'true' && window.innerWidth > 768) {
            sidebar.classList.add('collapsed');
        }

        // ========================================
        // MODAL - WORKING
        // ========================================
        let modalInstance = null;

        function openModal(type, title, data, chartType) {
            console.log('Opening modal:', type, title);
            
            const modalElement = document.getElementById('detailModal');
            if (!modalElement) {
                console.error('Modal element not found!');
                return;
            }
            
            document.getElementById('modalTitle').textContent = title;
            
            let html = '';
            
            if (data && data.length > 0) {
                html = '<div class="table-responsive">';
                html += '<table class="table table-striped table-hover">';
                
                // Headers
                html += '<thead><tr>';
                Object.keys(data[0]).forEach(key => {
                    const label = key.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
                    html += `<th>${label}</th>`;
                });
                html += '</tr></thead><tbody>';
                
                // Data rows
                data.forEach(item => {
                    html += '<tr>';
                    Object.values(item).forEach(value => {
                        if (typeof value === 'number' && value > 1000) {
                            html += `<td>₱${Number(value).toLocaleString('en-US', {minimumFractionDigits: 2})}</td>`;
                        } else {
                            html += `<td>${value || '-'}</td>`;
                        }
                    });
                    html += '</tr>';
                });
                
                html += '</tbody></table></div>';
                html += `<div class="text-muted small mt-2">Showing ${data.length} records</div>`;
            } else {
                html = '<div class="text-center text-muted py-4">No data available</div>';
            }
            
            document.getElementById('modalBody').innerHTML = html;
            
            if (!modalInstance) {
                modalInstance = new bootstrap.Modal(modalElement);
            }
            modalInstance.show();
        }

        // ========================================
        // FILTERS
        // ========================================
        function applyFilters() {
            const chapter = document.getElementById('filterChapter').value;
            const status = document.getElementById('filterStatus').value;
            const url = new URL(window.location.href);
            if (chapter) url.searchParams.set('filter_chapter', chapter);
            else url.searchParams.delete('filter_chapter');
            if (status) url.searchParams.set('filter_status', status);
            else url.searchParams.delete('filter_status');
            url.searchParams.set('page', 1);
            window.location.href = url.toString();
        }

        function clearFilters() {
            const url = new URL(window.location.href);
            url.searchParams.delete('filter_chapter');
            url.searchParams.delete('filter_status');
            url.searchParams.set('page', 1);
            window.location.href = url.toString();
        }

        // ========================================
        // EXPORT
        // ========================================
        function exportData(format) {
            const url = new URL(window.location.href);
            url.searchParams.set('export', format);
            window.location.href = url.toString();
        }

        // ========================================
        // CHARTS
        // ========================================
        document.addEventListener('DOMContentLoaded', function() {
            console.log('📊 Initializing charts...');
            
            // Collections Chart
            const collectionsCanvas = document.getElementById('collectionsChart');
            if (collectionsCanvas) {
                const monthlyData = <?php echo json_encode($monthly_data); ?>;
                new Chart(collectionsCanvas, {
                    type: 'bar',
                    data: {
                        labels: monthlyData.map(d => d.month),
                        datasets: [{
                            label: 'Collections (₱)',
                            data: monthlyData.map(d => d.total),
                            backgroundColor: 'rgba(55,90,127,0.65)',
                            borderColor: '#375a7f',
                            borderWidth: 1,
                            borderRadius: 4
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { display: false } },
                        scales: {
                            y: { beginAtZero: true, ticks: { callback: v => '₱' + (v/1000).toFixed(0) + 'k' } }
                        }
                    }
                });
                console.log('✅ Collections chart rendered');
            }

            // Payment Methods Chart
            const paymentCanvas = document.getElementById('paymentMethodsChart');
            if (paymentCanvas) {
                const paymentMethods = <?php echo json_encode($payment_methods); ?>;
                new Chart(paymentCanvas, {
                    type: 'doughnut',
                    data: {
                        labels: paymentMethods.map(m => m.payment_method ? m.payment_method.toUpperCase() : 'Other'),
                        datasets: [{
                            data: paymentMethods.map(m => m.total || 0),
                            backgroundColor: ['#28a745', '#17a2b8', '#ffc107', '#dc3545', '#6c757d'],
                            borderWidth: 0
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { position: 'bottom', labels: { boxWidth: 10, font: { size: 9 } } }
                        }
                    }
                });
                console.log('✅ Payment methods chart rendered');
            }

            // Member Growth Chart
            const growthCanvas = document.getElementById('memberGrowthChart');
            if (growthCanvas) {
                const memberGrowth = <?php echo json_encode($member_growth); ?>;
                new Chart(growthCanvas, {
                    type: 'line',
                    data: {
                        labels: memberGrowth.map(d => d.month),
                        datasets: [{
                            label: 'Total Members',
                            data: memberGrowth.map(d => d.count),
                            borderColor: '#28a745',
                            backgroundColor: 'rgba(40,167,69,0.1)',
                            fill: true,
                            tension: 0.4,
                            pointBackgroundColor: '#28a745',
                            pointRadius: 2
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { display: false } },
                        scales: { y: { beginAtZero: true } }
                    }
                });
                console.log('✅ Member growth chart rendered');
            }

            // Chapter Performance Chart
            const chapterCanvas = document.getElementById('chapterPerformanceChart');
            if (chapterCanvas) {
                const chapterPerformance = <?php echo json_encode($chapter_performance); ?>;
                new Chart(chapterCanvas, {
                    type: 'bar',
                    data: {
                        labels: chapterPerformance.map(c => c.chapter),
                        datasets: [{
                            label: 'Collection Rate (%)',
                            data: chapterPerformance.map(c => Math.min(c.collection_rate || 0, 100)),
                            backgroundColor: 'rgba(23,162,184,0.65)',
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
                        scales: {
                            x: { beginAtZero: true, max: 100, ticks: { callback: v => v + '%' } }
                        }
                    }
                });
                console.log('✅ Chapter performance chart rendered');
            }
        });

        console.log('✅ Reports page loaded');
        console.log('📊 Active members: <?php echo $total_active_members; ?>');
        console.log('💰 Total collections: ₱<?php echo number_format($total_collections, 2); ?>');
    </script>
</body>
</html>