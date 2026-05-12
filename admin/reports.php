<?php
// admin/reports.php
require_once '../includes/config.php';
require_once '../includes/db_connection.php';
require_once '../includes/security.php';
require_once '../includes/auth.php';

$auth->requireLogin();
$current_user = $auth->getCurrentUser();

$db = Database::getInstance();

// Get pending users count (for sidebar badge)
$pending_count = 0;
if ($current_user['role'] === 'admin') {
    $pending_count = $db->getSingle("SELECT COUNT(*) as cnt FROM pending_users WHERE status = 'pending'")['cnt'] ?? 0;
}

$message = '';
$error = '';

// Time period options
$period_options = ['monthly', 'quarterly', 'yearly', 'custom'];
$selected_period = isset($_GET['period']) ? $_GET['period'] : 'monthly';
$selected_year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');
$selected_month = isset($_GET['month']) ? (int)$_GET['month'] : date('m');
$selected_quarter = isset($_GET['quarter']) ? (int)$_GET['quarter'] : ceil(date('m') / 3);

// Custom date range
$date_from = isset($_GET['date_from']) ? $_GET['date_from'] : date('Y-m-01');
$date_to = isset($_GET['date_to']) ? $_GET['date_to'] : date('Y-m-d');
$report_view = isset($_GET['report_view']) ? $_GET['report_view'] : 'dashboard';
$export_format = isset($_GET['export']) ? $_GET['export'] : '';
$drill_down = isset($_GET['drill']) ? $_GET['drill'] : '';
$drill_value = isset($_GET['value']) ? $_GET['value'] : '';

// ============ DASHBOARD SUMMARY METRICS ============
// Total Collections All Time
$total_collections = $db->getSingle("SELECT COALESCE(SUM(amount), 0) as total FROM payments WHERE payment_status = 'confirmed'")['total'] ?? 0;

// This Month Collections
$current_month_collections = $db->getSingle("
    SELECT COALESCE(SUM(amount), 0) as total 
    FROM payments 
    WHERE payment_status = 'confirmed' 
    AND MONTH(payment_date) = MONTH(CURDATE()) 
    AND YEAR(payment_date) = YEAR(CURDATE())
")['total'] ?? 0;

// Total Active Members
$total_active_members = $db->getSingle("SELECT COUNT(*) as total FROM members WHERE status = 'active'")['total'] ?? 0;

// Pending Payments
$pending_payments = $db->getSingle("SELECT COUNT(*) as cnt FROM payments WHERE payment_status = 'pending'")['cnt'] ?? 0;

// Collection Rate (paid vs expected)
$expected_monthly = $total_active_members * 100; // Assuming ₱100 monthly
$collection_rate = $expected_monthly > 0 ? round(($current_month_collections / $expected_monthly) * 100, 1) : 0;

// Overdue Accounts (members with balance > 0)
$overdue_accounts = $db->getSingle("
    SELECT COUNT(DISTINCT m.member_code) as cnt
    FROM members m
    LEFT JOIN member_balances b ON m.member_code = b.member_code
    WHERE m.status = 'active' AND (b.current_balance > 0 OR b.current_balance IS NULL)
")['cnt'] ?? 0;

// ============ CHART DATA ============
// Monthly Collections (Last 12 months)
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

// Payment Methods Distribution
$payment_methods = $db->getAll("
    SELECT payment_method, COUNT(*) as count, SUM(amount) as total
    FROM payments 
    WHERE payment_status = 'confirmed'
    GROUP BY payment_method
");

// Member Growth (Last 12 months)
$member_growth = [];
for ($i = 11; $i >= 0; $i--) {
    $month_start = date('Y-m-01', strtotime("-$i months"));
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
        COALESCE(SUM(p.amount), 0) / (COUNT(DISTINCT m.member_code) * 100) * 100 as collection_rate
    FROM members m
    LEFT JOIN payments p ON m.member_code = p.member_id AND p.payment_status = 'confirmed'
    WHERE m.status = 'active' AND m.chapter IS NOT NULL AND m.chapter != ''
    GROUP BY m.chapter
    ORDER BY total_collected DESC
    LIMIT 10
");

// Top Payers (by total contributions)
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

// Aging Report (Members with unpaid contributions)
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

// New Members by Period
if ($selected_period == 'monthly') {
    $new_members = $db->getAll("
        SELECT 
            DATE_FORMAT(created_at, '%Y-%m') as period,
            COUNT(*) as count
        FROM members
        WHERE YEAR(created_at) = ?
        GROUP BY DATE_FORMAT(created_at, '%Y-%m')
        ORDER BY period DESC
        LIMIT 12
    ", [$selected_year], 'i');
} elseif ($selected_period == 'quarterly') {
    $new_members = $db->getAll("
        SELECT 
            CONCAT(YEAR(created_at), '-Q', QUARTER(created_at)) as period,
            COUNT(*) as count
        FROM members
        WHERE YEAR(created_at) = ?
        GROUP BY YEAR(created_at), QUARTER(created_at)
        ORDER BY period DESC
    ", [$selected_year], 'i');
} else {
    $new_members = $db->getAll("
        SELECT 
            YEAR(created_at) as period,
            COUNT(*) as count
        FROM members
        GROUP BY YEAR(created_at)
        ORDER BY period DESC
    ");
}

// Inactive/Deceased Report
$inactive_members = $db->getAll("
    SELECT 
        member_code,
        CONCAT(first_name, ' ', last_name) as member_name,
        chapter,
        status,
        inactive_date,
        inactive_reason
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
        beneficiary_relation,
        beneficiary_age,
        beneficiary_contact
    FROM members
    WHERE status = 'active' 
    AND beneficiary_name IS NOT NULL 
    AND beneficiary_name != ''
    ORDER BY member_name
");

// ============ DRILL-DOWN HANDLING ============
if ($drill_down == 'chapter' && !empty($drill_value)) {
    $drill_members = $db->getAll("
        SELECT member_code, first_name, last_name, contact_number, email
        FROM members
        WHERE chapter = ? AND status = 'active'
        ORDER BY last_name, first_name
    ", [$drill_value], 's');
}

if ($drill_down == 'month' && !empty($drill_value)) {
    $drill_payments = $db->getAll("
        SELECT p.*, CONCAT(m.first_name, ' ', m.last_name) as member_name
        FROM payments p
        JOIN members m ON p.member_id = m.member_code
        WHERE DATE_FORMAT(p.payment_date, '%Y-%m') = ? AND p.payment_status = 'confirmed'
        ORDER BY p.payment_date DESC
    ", [$drill_value], 's');
}

// ============ REPORT DATA BASED ON VIEW ============
$report_data = [];
$report_columns = [];
$report_title = '';

switch ($report_view) {
    case 'financial':
        $report_title = 'Financial Summary Report';
        $report_data = $db->getAll("
            SELECT 
                DATE_FORMAT(payment_date, '%Y-%m') as month,
                COUNT(*) as transaction_count,
                SUM(amount) as total_amount,
                payment_method
            FROM payments
            WHERE payment_status = 'confirmed'
            AND payment_date BETWEEN ? AND ?
            GROUP BY DATE_FORMAT(payment_date, '%Y-%m'), payment_method
            ORDER BY payment_date DESC
        ", [$date_from, $date_to], 'ss');
        $report_columns = ['Month', 'Payment Method', 'Transactions', 'Total Amount'];
        break;
        
    case 'member':
        $report_title = 'Member Demographics Report';
        $report_data = $db->getAll("
            SELECT 
                chapter,
                COUNT(*) as member_count,
                SUM(CASE WHEN gender = 'Male' THEN 1 ELSE 0 END) as male_count,
                SUM(CASE WHEN gender = 'Female' THEN 1 ELSE 0 END) as female_count,
                AVG(age) as avg_age
            FROM members
            WHERE status = 'active'
            GROUP BY chapter
            ORDER BY member_count DESC
        ");
        $report_columns = ['Chapter', 'Total Members', 'Male', 'Female', 'Average Age'];
        break;
        
    case 'compliance':
        $report_title = 'Compliance Report';
        $report_data = $db->getAll("
            SELECT 
                m.member_code,
                CONCAT(m.first_name, ' ', m.last_name) as member_name,
                m.chapter,
                m.date_joined,
                TIMESTAMPDIFF(MONTH, m.date_joined, CURDATE()) as membership_months,
                m.beneficiary_name,
                CASE 
                    WHEN m.beneficiary_name IS NOT NULL THEN 'Has Beneficiary'
                    ELSE 'No Beneficiary'
                END as beneficiary_status,
                CASE 
                    WHEN COALESCE(b.current_balance, 0) <= 0 THEN 'Compliant'
                    ELSE 'Non-Compliant'
                END as payment_status
            FROM members m
            LEFT JOIN member_balances b ON m.member_code = b.member_code
            WHERE m.status = 'active'
            ORDER BY membership_months DESC
        ");
        $report_columns = ['Code', 'Member', 'Chapter', 'Joined', 'Months', 'Beneficiary', 'Status'];
        break;
        
    case 'performance':
        $report_title = 'Performance Report';
        $report_data = $chapter_performance;
        $report_columns = ['Chapter', 'Members', 'Total Collected', 'Collection Rate (%)'];
        break;
}

// Handle Export
if ($export_format && !empty($report_data)) {
    if ($export_format === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $report_view . '_report_' . date('Y-m-d') . '.csv"');
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($output, $report_columns);
        foreach ($report_data as $row) {
            fputcsv($output, array_values($row));
        }
        fclose($output);
        exit;
    } elseif ($export_format === 'pdf') {
        // Simple PDF export - you can enhance this later
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="report_' . date('Y-m-d') . '.pdf"');
        // Basic PDF output (can be enhanced with dompdf or similar)
        echo "PDF Export - " . $report_title . "\n";
        echo "Generated on: " . date('Y-m-d H:i:s') . "\n\n";
        foreach ($report_data as $row) {
            echo implode(" | ", array_values($row)) . "\n";
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
    <title>Reports - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        :root {
            --primary-color: #375a7f;
            --secondary-color: #2c4a6b;
            --success-color: #28a745;
            --warning-color: #ffc107;
            --danger-color: #dc3545;
            --info-color: #17a2b8;
        }
        
        * {
            box-sizing: border-box;
        }
        
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
        
        /* Sidebar Styles */
        #sidebar-wrapper {
            background: #375a7f;
            color: #fff;
            width: 250px;
            height: 100vh;
            overflow-y: auto;
            transition: width 0.3s ease;
            box-shadow: 4px 0 10px rgba(0,0,0,0.1);
            white-space: nowrap;
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
            text-align: left;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        #sidebar-wrapper.collapsed .sidebar-heading { justify-content: center; padding: 1.2rem 0; }
        #sidebar-wrapper .sidebar-heading img { height: 30px; width: auto; margin-right: 10px; }
        .menu-toggle { background: transparent; border: none; font-size: 1.5rem; color: white; cursor: pointer; padding: 0 10px; }
        .header-logo { height: 30px; width: auto; margin-right: 10px; display: none; }
        #sidebar-wrapper.collapsed ~ #page-content-wrapper .header-logo { display: inline-block; }
        
        #sidebar-wrapper .list-group-item {
            background: transparent;
            border: none;
            color: rgba(255,255,255,0.9);
            padding: 0.8rem 1.2rem;
            font-weight: 500;
            transition: all 0.2s;
            font-size: 0.95rem;
            text-align: left;
        }
        
        #sidebar-wrapper .list-group-item:hover,
        #sidebar-wrapper .list-group-item.active {
            background: rgba(255,255,255,0.15);
            color: #fff;
            border-left: 4px solid #fff;
        }
        
        #sidebar-wrapper .list-group-item i { width: 24px; margin-right: 10px; }
        #sidebar-wrapper.collapsed .list-group-item i { margin-right: 0; width: 100%; font-size: 1.2rem; }
        
        #page-content-wrapper {
            flex: 1;
            background: #f4f7fc;
            height: 100vh;
            overflow-y: auto;
            padding: 0;
        }
        
        .navbar {
            background: #fff !important;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            padding: 0.7rem 1.2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 100;
        }
        
        .navbar-brand { font-size: 1.2rem; font-weight: 500; color: #375a7f !important; }
        .navbar-brand i { color: #375a7f; }
        .navbar-right { display: flex; align-items: center; gap: 20px; }
        
        /* Dashboard Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }
        
        .stat-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            transition: all 0.3s;
            border-left: 4px solid #375a7f;
        }
        
        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 8px 20px rgba(0,0,0,0.12); }
        .stat-number { font-size: 1.8rem; font-weight: 700; color: #2c3e50; }
        .stat-label { font-size: 0.75rem; color: #6c757d; text-transform: uppercase; letter-spacing: 0.5px; }
        .stat-trend { font-size: 0.7rem; margin-top: 5px; }
        
        /* Chart Cards */
        .chart-card {
            background: white;
            border-radius: 16px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        
        .chart-title {
            font-weight: 600;
            margin-bottom: 15px;
            color: #2c3e50;
            font-size: 1rem;
            border-left: 3px solid #375a7f;
            padding-left: 10px;
        }
        
        /* Report Tabs */
        .report-tabs {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 20px;
            background: white;
            padding: 10px;
            border-radius: 12px;
        }
        
        .report-tab {
            padding: 8px 20px;
            border-radius: 8px;
            text-decoration: none;
            color: #6c757d;
            font-weight: 500;
            transition: all 0.3s;
        }
        
        .report-tab:hover { background: #f8f9fa; color: #375a7f; }
        .report-tab.active { background: #375a7f; color: white; }
        
        /* Period Selector */
        .period-selector {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 20px;
            align-items: center;
        }
        
        .period-btn {
            padding: 6px 15px;
            border-radius: 20px;
            text-decoration: none;
            color: #6c757d;
            background: white;
            border: 1px solid #dee2e6;
            transition: all 0.3s;
        }
        
        .period-btn.active { background: #375a7f; color: white; border-color: #375a7f; }
        
        /* Tables */
        .table-responsive-custom { max-height: 500px; overflow-y: auto; border-radius: 12px; }
        .table-custom { width: 100%; background: white; border-radius: 12px; overflow: hidden; }
        .table-custom th { background: #f8f9fa; padding: 12px; font-size: 0.75rem; text-transform: uppercase; color: #6c757d; }
        .table-custom td { padding: 10px 12px; font-size: 0.85rem; border-bottom: 1px solid #e9ecef; }
        .table-custom tr:hover { background: #f8f9fa; cursor: pointer; }
        
        /* Drill Modal */
        .drill-modal .modal-content { border-radius: 16px; }
        .drill-modal .modal-header { background: linear-gradient(135deg, #375a7f, #2c4a6b); color: white; }
        
        .badge-count {
            position: absolute !important;
            top: 50% !important;
            right: 10px !important;
            transform: translateY(-50%) !important;
            font-size: 0.7rem !important;
            padding: 3px 6px !important;
        }
        
        @media (max-width: 768px) {
            .stats-grid { grid-template-columns: repeat(2, 1fr); }
            .report-tabs { justify-content: center; }
            .period-selector { justify-content: center; }
        }
    </style>
</head>
<body>
    <div id="wrapper">
        <!-- Sidebar -->
        <div id="sidebar-wrapper">
            <div class="sidebar-heading">
                <img src="../assets/images/harana-logo.png" alt="Harana" onerror="this.src=''; this.onerror=null; this.innerHTML='Harana';">
                <span>Harana</span>
                <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
            </div>
            <div class="list-group list-group-flush mt-2">
                <a href="dashboard.php" class="list-group-item"><i class="fas fa-tachometer-alt"></i><span>Dashboard</span></a>
                <a href="members.php" class="list-group-item"><i class="fas fa-users"></i><span>Members</span></a>
                <a href="council.php" class="list-group-item"><i class="fas fa-user-tie"></i><span>Council</span></a>
                <a href="payments.php" class="list-group-item"><i class="fas fa-credit-card"></i><span>Payments</span></a>
                <a href="reports.php" class="list-group-item active"><i class="fas fa-chart-bar"></i><span>Reports</span></a>
                <?php if ($current_user['role'] === 'admin'): ?>
                <a href="pending_users.php" class="list-group-item position-relative">
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
            <nav class="navbar navbar-light bg-light">
                <div class="navbar-left">
                    <img src="../assets/images/harana-logo.png" alt="Harana" class="header-logo" id="headerLogo" onerror="this.style.display='none';">
                    <span class="navbar-brand"><i class="fas fa-chart-bar me-2"></i>Reports & Analytics</span>
                </div>
                <div class="navbar-right">
                    <span class="text-muted small"><i class="fas fa-calendar-alt me-1"></i><?php echo date('F j, Y'); ?></span>
                    <span class="small"><i class="fas fa-user-circle me-2"></i><?php echo htmlspecialchars($current_user['full_name'] ?? $current_user['username']); ?></span>
                </div>
            </nav>

            <div class="container-fluid p-4">
                <!-- Dashboard Summary Cards -->
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-number">₱<?php echo number_format($total_collections / 1000, 1); ?>k</div>
                        <div class="stat-label">Total Collections</div>
                        <div class="stat-trend text-success"><i class="fas fa-chart-line"></i> All time</div>
                    </div>
                    <div class="stat-card" style="border-left-color: #28a745;">
                        <div class="stat-number">₱<?php echo number_format($current_month_collections / 1000, 1); ?>k</div>
                        <div class="stat-label">This Month</div>
                        <div class="stat-trend text-success"><i class="fas fa-calendar"></i> <?php echo date('F'); ?></div>
                    </div>
                    <div class="stat-card" style="border-left-color: #17a2b8;">
                        <div class="stat-number"><?php echo $total_active_members; ?></div>
                        <div class="stat-label">Active Members</div>
                        <div class="stat-trend text-info"><i class="fas fa-users"></i> Total members</div>
                    </div>
                    <div class="stat-card" style="border-left-color: #ffc107;">
                        <div class="stat-number"><?php echo $pending_payments; ?></div>
                        <div class="stat-label">Pending Payments</div>
                        <div class="stat-trend text-warning"><i class="fas fa-clock"></i> Awaiting confirmation</div>
                    </div>
                    <div class="stat-card" style="border-left-color: #28a745;">
                        <div class="stat-number"><?php echo $collection_rate; ?>%</div>
                        <div class="stat-label">Collection Rate</div>
                        <div class="stat-trend text-success"><i class="fas fa-percent"></i> This month</div>
                    </div>
                    <div class="stat-card" style="border-left-color: #dc3545;">
                        <div class="stat-number"><?php echo $overdue_accounts; ?></div>
                        <div class="stat-label">Overdue Accounts</div>
                        <div class="stat-trend text-danger"><i class="fas fa-exclamation-triangle"></i> Need attention</div>
                    </div>
                </div>

                <!-- Period Selector -->
                <div class="period-selector">
                    <a href="?period=monthly&report_view=<?php echo $report_view; ?>" class="period-btn <?php echo $selected_period == 'monthly' ? 'active' : ''; ?>">Monthly</a>
                    <a href="?period=quarterly&report_view=<?php echo $report_view; ?>" class="period-btn <?php echo $selected_period == 'quarterly' ? 'active' : ''; ?>">Quarterly</a>
                    <a href="?period=yearly&report_view=<?php echo $report_view; ?>" class="period-btn <?php echo $selected_period == 'yearly' ? 'active' : ''; ?>">Yearly</a>
                    <a href="?period=custom&report_view=<?php echo $report_view; ?>" class="period-btn <?php echo $selected_period == 'custom' ? 'active' : ''; ?>">Custom</a>
                    <?php if ($selected_period == 'custom'): ?>
                    <form method="GET" class="d-inline-flex gap-2">
                        <input type="hidden" name="period" value="custom">
                        <input type="hidden" name="report_view" value="<?php echo $report_view; ?>">
                        <input type="date" name="date_from" value="<?php echo $date_from; ?>" class="form-control form-control-sm" style="width: 130px;">
                        <input type="date" name="date_to" value="<?php echo $date_to; ?>" class="form-control form-control-sm" style="width: 130px;">
                        <button type="submit" class="btn btn-sm btn-primary">Apply</button>
                    </form>
                    <?php endif; ?>
                </div>

                <!-- Report Tabs -->
                <div class="report-tabs">
                    <a href="?report_view=dashboard&period=<?php echo $selected_period; ?>" class="report-tab <?php echo $report_view == 'dashboard' ? 'active' : ''; ?>"><i class="fas fa-chart-pie"></i> Dashboard</a>
                    <a href="?report_view=financial&period=<?php echo $selected_period; ?>" class="report-tab <?php echo $report_view == 'financial' ? 'active' : ''; ?>"><i class="fas fa-chart-line"></i> Financial Reports</a>
                    <a href="?report_view=member&period=<?php echo $selected_period; ?>" class="report-tab <?php echo $report_view == 'member' ? 'active' : ''; ?>"><i class="fas fa-users"></i> Member Reports</a>
                    <a href="?report_view=compliance&period=<?php echo $selected_period; ?>" class="report-tab <?php echo $report_view == 'compliance' ? 'active' : ''; ?>"><i class="fas fa-check-double"></i> Compliance</a>
                    <a href="?report_view=performance&period=<?php echo $selected_period; ?>" class="report-tab <?php echo $report_view == 'performance' ? 'active' : ''; ?>"><i class="fas fa-trophy"></i> Performance</a>
                </div>

                <?php if ($report_view == 'dashboard'): ?>
                <!-- Dashboard with Charts -->
                <div class="row">
                    <div class="col-lg-6">
                        <div class="chart-card">
                            <div class="chart-title"><i class="fas fa-chart-line me-2"></i> Monthly Collections Trend</div>
                            <canvas id="collectionsChart" height="200"></canvas>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="chart-card">
                            <div class="chart-title"><i class="fas fa-chart-pie me-2"></i> Payment Methods</div>
                            <canvas id="paymentMethodsChart" height="200"></canvas>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="chart-card">
                            <div class="chart-title"><i class="fas fa-chart-line me-2"></i> Member Growth</div>
                            <canvas id="memberGrowthChart" height="200"></canvas>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="chart-card">
                            <div class="chart-title"><i class="fas fa-trophy me-2"></i> Top Payers</div>
                            <div class="table-responsive-custom" style="max-height: 250px;">
                                <table class="table-custom">
                                    <thead>
                                        <tr><th>Member</th><th>Chapter</th><th>Total Paid</th><th>Payments</th></tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($top_payers as $payer): ?>
                                        <tr><td><?php echo htmlspecialchars($payer['member_name']); ?></td><td><?php echo htmlspecialchars($payer['chapter']); ?></td><td class="text-success">₱<?php echo number_format($payer['total_paid'], 2); ?></td><td><?php echo $payer['payment_count']; ?></td></tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="chart-card">
                            <div class="chart-title"><i class="fas fa-chart-bar me-2"></i> Chapter Performance</div>
                            <canvas id="chapterPerformanceChart" height="200"></canvas>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="chart-card">
                            <div class="chart-title"><i class="fas fa-clock me-2"></i> Aging Report (Overdue)</div>
                            <div class="table-responsive-custom" style="max-height: 250px;">
                                <table class="table-custom">
                                    <thead><tr><th>Member</th><th>Chapter</th><th>Months Overdue</th><th>Balance Due</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($aging_report as $aging): ?>
                                        <tr onclick="viewMemberDetails('<?php echo $aging['member_code']; ?>')" style="cursor: pointer;">
                                            <td><?php echo htmlspecialchars($aging['member_name']); ?></td>
                                            <td><?php echo htmlspecialchars($aging['chapter']); ?></td>
                                            <td class="text-danger"><?php echo $aging['months_overdue']; ?> months</td>
                                            <td class="text-danger">₱<?php echo number_format($aging['balance_due'], 2); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Additional Reports Section -->
                <div class="row mt-3">
                    <div class="col-md-6">
                        <div class="chart-card">
                            <div class="chart-title"><i class="fas fa-calendar-plus me-2"></i> New Members (<?php echo ucfirst($selected_period); ?>)</div>
                            <div class="table-responsive-custom">
                                <table class="table-custom">
                                    <thead><tr><th>Period</th><th>New Members</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($new_members as $nm): ?>
                                        <tr><td><?php echo $nm['period']; ?></td><td><?php echo $nm['count']; ?></td></tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="chart-card">
                            <div class="chart-title"><i class="fas fa-user-slash me-2"></i> Inactive/Deceased Members</div>
                            <div class="table-responsive-custom" style="max-height: 300px;">
                                <table class="table-custom">
                                    <thead><tr><th>Member</th><th>Chapter</th><th>Status</th><th>Date</th></tr></thead>
                                    <tbody>
                                        <?php foreach ($inactive_members as $im): ?>
                                        <tr><td><?php echo htmlspecialchars($im['member_name']); ?></td><td><?php echo htmlspecialchars($im['chapter']); ?></td><td><span class="badge bg-secondary"><?php echo ucfirst($im['status']); ?></span></td><td><?php echo date('M d, Y', strtotime($im['inactive_date'])); ?></td></tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Beneficiary Report -->
                <div class="chart-card">
                    <div class="chart-title"><i class="fas fa-heart me-2"></i> Beneficiary Report</div>
                    <div class="table-responsive-custom">
                        <table class="table-custom">
                            <thead><tr><th>Member</th><th>Chapter</th><th>Beneficiary</th><th>Relationship</th><th>Contact</th></tr></thead>
                            <tbody>
                                <?php foreach ($beneficiary_report as $ben): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($ben['member_name']); ?></td>
                                    <td><?php echo htmlspecialchars($ben['chapter']); ?></td>
                                    <td><?php echo htmlspecialchars($ben['beneficiary_name']); ?></td>
                                    <td><?php echo htmlspecialchars($ben['beneficiary_relation']); ?></td>
                                    <td><?php echo htmlspecialchars($ben['beneficiary_contact']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <?php else: ?>
                <!-- Detailed Report View -->
                <div class="chart-card">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div class="chart-title"><?php echo $report_title; ?></div>
                        <div>
                            <a href="?report_view=<?php echo $report_view; ?>&period=<?php echo $selected_period; ?>&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>&export=csv" class="btn btn-sm btn-success me-2"><i class="fas fa-file-csv"></i> CSV</a>
                            <a href="?report_view=<?php echo $report_view; ?>&period=<?php echo $selected_period; ?>&date_from=<?php echo $date_from; ?>&date_to=<?php echo $date_to; ?>&export=pdf" class="btn btn-sm btn-danger"><i class="fas fa-file-pdf"></i> PDF</a>
                        </div>
                    </div>
                    <div class="table-responsive-custom">
                        <table class="table-custom">
                            <thead>
                                <tr>
                                    <?php foreach ($report_columns as $col): ?>
                                    <th><?php echo $col; ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($report_data as $row): ?>
                                <tr>
                                    <?php foreach (array_values($row) as $value): ?>
                                    <td><?php echo is_numeric($value) && strpos($value, '.') !== false ? '₱' . number_format($value, 2) : htmlspecialchars($value); ?></td>
                                    <?php endforeach; ?>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Sidebar Toggle
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar-wrapper');
        const headerLogo = document.getElementById('headerLogo');
        const sidebarCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
        if (sidebarCollapsed) sidebar.classList.add('collapsed');
        if (menuToggle) {
            menuToggle.addEventListener('click', function() {
                sidebar.classList.toggle('collapsed');
                localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
            });
        }

        // Logo fallback
        const sidebarLogo = document.querySelector('.sidebar-heading img');
        if (sidebarLogo) sidebarLogo.onerror = function() { this.style.display = 'none'; this.nextSibling.textContent = 'Harana'; };
        if (headerLogo) headerLogo.onerror = function() { this.style.display = 'none'; };

        // Chart Data
        const monthlyData = <?php echo json_encode($monthly_data); ?>;
        const paymentMethods = <?php echo json_encode($payment_methods); ?>;
        const memberGrowth = <?php echo json_encode($member_growth); ?>;
        const chapterPerformance = <?php echo json_encode($chapter_performance); ?>;

        // Collections Chart
        new Chart(document.getElementById('collectionsChart'), {
            type: 'bar',
            data: {
                labels: monthlyData.map(d => d.month),
                datasets: [{
                    label: 'Collections (₱)',
                    data: monthlyData.map(d => d.total),
                    backgroundColor: 'rgba(55,90,127,0.6)',
                    borderColor: '#375a7f',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                onClick: (e, active) => {
                    if (active.length) {
                        const month = monthlyData[active[0].index].month;
                        window.location.href = `?drill=month&value=${month}`;
                    }
                },
                scales: { y: { beginAtZero: true, ticks: { callback: v => '₱' + (v/1000).toFixed(0) + 'k' } } }
            }
        });

        // Payment Methods Chart
        new Chart(document.getElementById('paymentMethodsChart'), {
            type: 'pie',
            data: {
                labels: paymentMethods.map(m => m.payment_method ? m.payment_method.toUpperCase() : 'Other'),
                datasets: [{
                    data: paymentMethods.map(m => m.total),
                    backgroundColor: ['#28a745', '#17a2b8', '#ffc107', '#dc3545']
                }]
            },
            options: { responsive: true, maintainAspectRatio: true }
        });

        // Member Growth Chart
        new Chart(document.getElementById('memberGrowthChart'), {
            type: 'line',
            data: {
                labels: memberGrowth.map(d => d.month),
                datasets: [{
                    label: 'Total Members',
                    data: memberGrowth.map(d => d.count),
                    borderColor: '#28a745',
                    backgroundColor: 'rgba(40,167,69,0.1)',
                    fill: true,
                    tension: 0.4
                }]
            },
            options: { responsive: true, maintainAspectRatio: true }
        });

        // Chapter Performance Chart
        new Chart(document.getElementById('chapterPerformanceChart'), {
            type: 'bar',
            data: {
                labels: chapterPerformance.map(c => c.chapter),
                datasets: [{
                    label: 'Collection Rate (%)',
                    data: chapterPerformance.map(c => c.collection_rate),
                    backgroundColor: 'rgba(23,162,184,0.6)',
                    borderColor: '#17a2b8',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                onClick: (e, active) => {
                    if (active.length) {
                        const chapter = chapterPerformance[active[0].index].chapter;
                        window.location.href = `?drill=chapter&value=${encodeURIComponent(chapter)}`;
                    }
                },
                scales: { y: { beginAtZero: true, max: 100, ticks: { callback: v => v + '%' } } }
            }
        });

        function viewMemberDetails(memberCode) {
            window.location.href = `members.php?view_code=${memberCode}`;
        }
    </script>
</body>
</html>