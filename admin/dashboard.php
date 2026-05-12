<?php
// admin/dashboard.php
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

// ============ MEMBERS SUMMARY ============
$members_summary = [];

// Total members
$members_summary['total'] = $db->getSingle("SELECT COUNT(*) as total FROM members WHERE status = 'active'")['total'] ?? 0;
$members_summary['inactive'] = $db->getSingle("SELECT COUNT(*) as total FROM members WHERE status = 'inactive'")['total'] ?? 0;
$members_summary['deceased'] = $db->getSingle("SELECT COUNT(*) as total FROM members WHERE status = 'deceased'")['total'] ?? 0;

// New members this month
$members_summary['new_this_month'] = $db->getSingle("
    SELECT COUNT(*) as cnt 
    FROM members 
    WHERE MONTH(created_at) = MONTH(CURDATE()) 
    AND YEAR(created_at) = YEAR(CURDATE())
")['cnt'] ?? 0;

// ============ PAYMENTS OVERVIEW ============
$monthly_target = 90000; // 90k target

// Current month's collections
$current_month_collected = $db->getSingle("
    SELECT COALESCE(SUM(amount), 0) as total 
    FROM payments 
    WHERE payment_status = 'confirmed' 
    AND MONTH(payment_date) = MONTH(CURRENT_DATE())
    AND YEAR(payment_date) = YEAR(CURRENT_DATE())
")['total'] ?? 0;

// Current month's pending payments (pending status, not yet confirmed)
$pending_payments = $db->getSingle("
    SELECT COUNT(*) as cnt 
    FROM payments 
    WHERE payment_status = 'pending'
    AND MONTH(payment_date) = MONTH(CURRENT_DATE())
    AND YEAR(payment_date) = YEAR(CURRENT_DATE())
")['cnt'] ?? 0;

// Current month's paid payments (confirmed)
$paid_payments = $db->getSingle("
    SELECT COUNT(*) as cnt 
    FROM payments 
    WHERE payment_status = 'confirmed'
    AND MONTH(payment_date) = MONTH(CURRENT_DATE())
    AND YEAR(payment_date) = YEAR(CURRENT_DATE())
")['cnt'] ?? 0;

// Advanced payments - members who paid ahead (payment_date > current month)
$advanced_payments = $db->getSingle("
    SELECT COUNT(*) as cnt 
    FROM payments 
    WHERE payment_status = 'confirmed'
    AND YEAR(payment_date) = YEAR(CURRENT_DATE())
    AND MONTH(payment_date) > MONTH(CURRENT_DATE())
")['cnt'] ?? 0;

// Calculate progress percentage toward target
$progress_percentage = $monthly_target > 0 ? min(100, ($current_month_collected / $monthly_target) * 100) : 0;

// ============ MONTHLY REPORTS (LAST 3 MONTHS) ============
$monthly_reports = [];

for ($i = 0; $i < 3; $i++) {
    $month = date('n', strtotime("-$i months"));
    $year = date('Y', strtotime("-$i months"));
    $month_name = date('M', strtotime("-$i months"));
    
    // Total collected for that month
    $collected = $db->getSingle("
        SELECT COALESCE(SUM(amount), 0) as total 
        FROM payments 
        WHERE payment_status = 'confirmed' 
        AND MONTH(payment_date) = ? 
        AND YEAR(payment_date) = ?
    ", [$month, $year], 'ii')['total'] ?? 0;
    
    // Pending payments for that month
    $pending = $db->getSingle("
        SELECT COUNT(*) as cnt 
        FROM payments 
        WHERE payment_status = 'pending'
        AND MONTH(payment_date) = ? 
        AND YEAR(payment_date) = ?
    ", [$month, $year], 'ii')['cnt'] ?? 0;
    
    // Paid payments for that month
    $paid = $db->getSingle("
        SELECT COUNT(*) as cnt 
        FROM payments 
        WHERE payment_status = 'confirmed'
        AND MONTH(payment_date) = ? 
        AND YEAR(payment_date) = ?
    ", [$month, $year], 'ii')['cnt'] ?? 0;
    
    // Advanced payments for that month (payments made for future months)
    $advanced = $db->getSingle("
        SELECT COUNT(*) as cnt 
        FROM payments 
        WHERE payment_status = 'confirmed'
        AND MONTH(payment_date) > ? 
        AND YEAR(payment_date) >= ?
    ", [$month, $year], 'ii')['cnt'] ?? 0;
    
    $monthly_reports[] = [
        'month' => $month_name,
        'year' => $year,
        'collected' => $collected,
        'pending' => $pending,
        'paid' => $paid,
        'advanced' => $advanced
    ];
}

// ============ PENDING APPROVALS SUMMARY ============
$pending_summary = [];

if ($current_user['role'] === 'admin') {
    $pending_summary['total'] = $pending_count;
    
    $pending_summary['users'] = $db->getAll("
        SELECT id, username, first_name, last_name, email, requested_at 
        FROM pending_users 
        WHERE status = 'pending' 
        ORDER BY requested_at DESC
    ");
}

$csrf_token = Security::generateCSRFToken();
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
    <style>
        * {
            box-sizing: border-box;
        }
        body {
            background: #f0f2f5;
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
            transition: all 0.3s ease;
        }
        
        /* Sidebar - keeping your original colors */
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
        
        /* Sidebar - Collapsed state */
        #sidebar-wrapper.collapsed {
            width: 70px;
        }
        
        #sidebar-wrapper.collapsed .sidebar-heading span {
            display: none;
        }
        
        #sidebar-wrapper.collapsed .list-group-item span {
            display: none;
        }
        
        #sidebar-wrapper.collapsed .list-group-item i {
            margin-right: 0;
            width: 100%;
            text-align: center;
            font-size: 1.2rem;
        }
        
        #sidebar-wrapper.collapsed .list-group-item {
            padding: 15px 0;
            text-align: center;
        }
        
        #sidebar-wrapper.collapsed .badge {
            display: none;
        }
        
        #sidebar-wrapper.collapsed .sidebar-heading img {
            display: none;
        }
        
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
        
        #sidebar-wrapper.collapsed .sidebar-heading {
            justify-content: center;
            padding: 1.2rem 0;
        }
        
        #sidebar-wrapper .sidebar-heading img {
            height: 30px;
            width: auto;
            margin-right: 10px;
            vertical-align: middle;
        }
        
        .menu-toggle {
            background: transparent;
            border: none;
            font-size: 1.5rem;
            color: white;
            cursor: pointer;
            padding: 0 10px;
        }
        .menu-toggle:hover {
            color: #fff;
            transform: scale(1.1);
        }
        
        .header-logo {
            height: 30px;
            width: auto;
            margin-right: 10px;
            vertical-align: middle;
            display: none;
        }
        
        #sidebar-wrapper.collapsed ~ #page-content-wrapper .header-logo {
            display: inline-block;
        }
        
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
        
        #sidebar-wrapper.collapsed .list-group-item {
            padding: 15px 0;
            text-align: center;
        }
        
        #sidebar-wrapper .list-group-item:hover,
        #sidebar-wrapper .list-group-item.active {
            background: rgba(255,255,255,0.15);
            color: #fff;
            border-left: 4px solid #fff;
        }
        
        #sidebar-wrapper.collapsed .list-group-item:hover,
        #sidebar-wrapper.collapsed .list-group-item.active {
            border-left: none;
            border-bottom: 2px solid #fff;
        }
        
        #sidebar-wrapper .list-group-item i {
            width: 24px;
            text-align: center;
            margin-right: 10px;
            font-size: 1rem;
        }
        
        #sidebar-wrapper.collapsed .list-group-item i {
            margin-right: 0;
            width: 100%;
            font-size: 1.2rem;
        }
        
        #page-content-wrapper {
            flex: 1;
            background: #f0f2f5;
            height: 100vh;
            overflow-y: auto;
            padding: 0;
        }
        
        .navbar {
            background: #fff !important;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
            padding: 0.7rem 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .navbar-left {
            display: flex;
            align-items: center;
        }
        
        .navbar-brand {
            font-size: 1.2rem;
            font-weight: 500;
            color: #375a7f !important;
            display: flex;
            align-items: center;
        }
        
        .navbar-brand i {
            color: #375a7f;
        }
        
        .navbar-right {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        /* Dashboard Grid */
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            padding: 20px;
            height: calc(100vh - 70px);
        }
        
        /* Module Cards */
        .module-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            display: flex;
            flex-direction: column;
            overflow: hidden;
            transition: all 0.2s ease;
        }
        
        .module-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        
        .module-header {
            padding: 1rem 1.2rem;
            display: flex;
            align-items: center;
            gap: 10px;
            border-bottom: 1px solid #e9ecef;
        }
        
        .module-header i {
            font-size: 1.2rem;
            color: #375a7f;
        }
        
        .module-header h5 {
            margin: 0;
            font-weight: 600;
            font-size: 1rem;
            color: #2c3e50;
        }
        
        .module-body {
            padding: 1.2rem;
            flex: 1;
        }
        
        /* Progress Bar Styles */
        .progress-container {
            margin: 15px 0;
        }
        
        .progress-label {
            display: flex;
            justify-content: space-between;
            font-size: 0.85rem;
            margin-bottom: 8px;
            color: #4a5568;
        }
        
        .progress {
            height: 10px;
            border-radius: 10px;
            background: #e9ecef;
            overflow: hidden;
        }
        
        .progress-bar-custom {
            background: linear-gradient(90deg, #28a745, #20c997);
            height: 100%;
            border-radius: 10px;
            transition: width 0.5s ease;
        }
        
        /* Stats Row */
        .stats-row {
            display: flex;
            justify-content: space-between;
            gap: 15px;
            margin-top: 20px;
            padding-top: 15px;
            border-top: 1px solid #e9ecef;
        }
        
        .stat-box {
            flex: 1;
            text-align: center;
            padding: 10px;
            background: #f8f9fa;
            border-radius: 12px;
        }
        
        .stat-box .stat-number {
            font-size: 1.3rem;
            font-weight: 700;
            color: #2c3e50;
        }
        
        .stat-box .stat-label {
            font-size: 0.7rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-top: 4px;
        }
        
        /* Monthly Reports */
        .reports-list {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        
        .report-item {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 12px;
            transition: all 0.2s;
        }
        
        .report-item:hover {
            background: #f0f2f5;
        }
        
        .report-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
            padding-bottom: 8px;
            border-bottom: 1px solid #e9ecef;
        }
        
        .report-month {
            font-weight: 700;
            color: #375a7f;
            font-size: 0.9rem;
        }
        
        .report-amount {
            font-weight: 700;
            color: #28a745;
            font-size: 1rem;
        }
        
        .report-stats {
            display: flex;
            justify-content: space-between;
            gap: 10px;
        }
        
        .report-stat {
            flex: 1;
            text-align: center;
            padding: 8px;
            background: white;
            border-radius: 8px;
        }
        
        .report-stat .stat-number {
            font-size: 1rem;
            font-weight: 700;
            color: #2c3e50;
        }
        
        .report-stat .stat-label {
            font-size: 0.6rem;
            color: #6c757d;
            text-transform: uppercase;
        }
        
        .report-stat.pending .stat-number { color: #f39c12; }
        .report-stat.paid .stat-number { color: #28a745; }
        .report-stat.advanced .stat-number { color: #3498db; }
        
        /* Members Overview Stats */
        .member-stats {
            display: flex;
            justify-content: space-between;
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .member-stat {
            flex: 1;
            text-align: center;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 12px;
        }
        
        .member-stat .stat-number {
            font-size: 1.5rem;
            font-weight: 700;
            color: #2c3e50;
        }
        
        .member-stat .stat-label {
            font-size: 0.7rem;
            color: #6c757d;
        }
        
        /* Quick Actions */
        .quick-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-top: 15px;
        }
        
        .quick-action-btn {
            flex: 1;
            padding: 8px 12px;
            background: #f8f9fa;
            border: 1px solid #e9ecef;
            border-radius: 10px;
            text-align: center;
            text-decoration: none;
            color: #2c3e50;
            font-size: 0.8rem;
            transition: all 0.2s;
        }
        
        .quick-action-btn:hover {
            background: #375a7f;
            color: white;
            border-color: #375a7f;
        }
        
        .quick-action-btn i {
            margin-right: 6px;
        }
        
        /* Pending Items */
        .pending-item {
            display: flex;
            align-items: center;
            padding: 10px 12px;
            background: #fff9f0;
            border-left: 3px solid #f39c12;
            border-radius: 10px;
            margin-bottom: 10px;
        }
        
        .pending-info {
            flex: 1;
        }
        
        .pending-name {
            font-weight: 600;
            color: #8a6d3b;
            font-size: 0.85rem;
        }
        
        .pending-meta {
            font-size: 0.7rem;
            color: #8a6d3b;
        }
        
        .badge-count {
            position: absolute !important;
            top: 50% !important;
            right: 10px !important;
            transform: translateY(-50%) !important;
            font-size: 0.7rem !important;
            padding: 3px 6px !important;
            border-radius: 10px !important;
        }
        
        .pending-badge {
            background: rgba(220,53,69,0.2);
            color: #b71c1c;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.7rem;
            margin-left: 8px;
        }
        
        @media (max-width: 992px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 768px) {
            .stats-row {
                flex-direction: column;
            }
            
            .member-stats {
                flex-direction: column;
            }
            
            .report-stats {
                flex-direction: column;
            }
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
                <button class="menu-toggle" id="menuToggle">
                    <i class="fas fa-bars"></i>
                </button>
            </div>
            <div class="list-group list-group-flush mt-2">
                <a href="dashboard.php" class="list-group-item active"><i class="fas fa-tachometer-alt"></i><span>Dashboard</span></a>
                <a href="members.php" class="list-group-item"><i class="fas fa-users"></i><span>Members</span></a>
                <a href="council.php" class="list-group-item"><i class="fas fa-user-tie"></i><span>Council</span></a>
                <a href="payments.php" class="list-group-item"><i class="fas fa-credit-card"></i><span>Payments</span></a>
                <a href="reports.php" class="list-group-item"><i class="fas fa-chart-bar"></i><span>Reports</span></a>
                <a href="announcements.php" class="list-group-item"><i class="fas fa-bullhorn"></i><span>Send Announcement</span></a>
                <?php if ($current_user['role'] === 'admin'): ?>
                <a href="pending_users.php" class="list-group-item position-relative">
                    <i class="fas fa-user-clock"></i><span>Pending</span>
                    <?php if ($pending_count > 0): ?>
                        <span class="badge bg-danger position-absolute top-0 start-100 translate-middle badge-count"><?php echo $pending_count; ?></span>
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
                    <span class="navbar-brand"><i class="fas fa-tachometer-alt me-2"></i>Dashboard</span>
                </div>
                <div class="navbar-right">
                    <span class="text-muted small">
                        <i class="fas fa-calendar-alt me-1"></i><?php echo date('F j, Y'); ?>
                    </span>
                    <span class="small"><i class="fas fa-user-circle me-2"></i><?php echo htmlspecialchars($current_user['full_name']); ?></span>
                </div>
            </nav>

            <div class="dashboard-grid">
                <!-- MEMBERS MODULE - Top Left -->
                <div class="module-card">
                    <div class="module-header">
                        <i class="fas fa-users"></i>
                        <h5>Members Overview</h5>
                    </div>
                    <div class="module-body">
                        <div class="member-stats">
                            <div class="member-stat">
                                <div class="stat-number"><?php echo $members_summary['total']; ?></div>
                                <div class="stat-label">Active</div>
                                <div class="small text-success">+<?php echo $members_summary['new_this_month']; ?> new</div>
                            </div>
                            <div class="member-stat">
                                <div class="stat-number"><?php echo $members_summary['inactive']; ?></div>
                                <div class="stat-label">Inactive</div>
                            </div>
                            <div class="member-stat">
                                <div class="stat-number"><?php echo $members_summary['deceased']; ?></div>
                                <div class="stat-label">Deceased</div>
                            </div>
                        </div>
                        
                        <div class="quick-actions">
                            <a href="members.php" class="quick-action-btn"><i class="fas fa-list"></i>All Members</a>
                            <a href="members.php?action=add" class="quick-action-btn"><i class="fas fa-plus"></i>Add Member</a>
                        </div>
                    </div>
                </div>

                <!-- PAYMENTS OVERVIEW MODULE - Top Right -->
                <div class="module-card">
                    <div class="module-header">
                        <i class="fas fa-credit-card"></i>
                        <h5>Payments Overview</h5>
                    </div>
                    <div class="module-body">
                        <div class="progress-container">
                            <div class="progress-label">
                                <span>This Month</span>
                                <span>₱<?php echo number_format($current_month_collected); ?> / ₱<?php echo number_format($monthly_target); ?></span>
                            </div>
                            <div class="progress">
                                <div class="progress-bar-custom" style="width: <?php echo $progress_percentage; ?>%"></div>
                            </div>
                        </div>
                        
                        <div class="stats-row">
                            <div class="stat-box">
                                <div class="stat-number"><?php echo $pending_payments; ?></div>
                                <div class="stat-label">Pending</div>
                            </div>
                            <div class="stat-box">
                                <div class="stat-number"><?php echo $paid_payments; ?></div>
                                <div class="stat-label">Paid</div>
                            </div>
                            <div class="stat-box">
                                <div class="stat-number"><?php echo $advanced_payments; ?></div>
                                <div class="stat-label">Advanced</div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- REPORTS MODULE - Bottom Left -->
                <div class="module-card">
                    <div class="module-header">
                        <i class="fas fa-chart-bar"></i>
                        <h5>Monthly Reports</h5>
                    </div>
                    <div class="module-body">
                        <div class="reports-list">
                            <?php foreach ($monthly_reports as $report): ?>
                            <div class="report-item">
                                <div class="report-header">
                                    <span class="report-month"><?php echo $report['month']; ?> / / <?php echo $report['year']; ?></span>
                                    <span class="report-amount">₱<?php echo number_format($report['collected']); ?></span>
                                </div>
                                <div class="report-stats">
                                    <div class="report-stat pending">
                                        <div class="stat-number"><?php echo $report['pending']; ?></div>
                                        <div class="stat-label">Pending</div>
                                    </div>
                                    <div class="report-stat paid">
                                        <div class="stat-number"><?php echo $report['paid']; ?></div>
                                        <div class="stat-label">Paid</div>
                                    </div>
                                    <div class="report-stat advanced">
                                        <div class="stat-number"><?php echo $report['advanced']; ?></div>
                                        <div class="stat-label">Advanced</div>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <div class="quick-actions mt-3">
                            <a href="reports.php" class="quick-action-btn"><i class="fas fa-chart-bar"></i>View All Reports</a>
                        </div>
                    </div>
                </div>

                <!-- PENDING APPROVALS MODULE - Bottom Right -->
                <?php if ($current_user['role'] === 'admin'): ?>
                <div class="module-card">
                    <div class="module-header">
                        <i class="fas fa-user-clock"></i>
                        <h5>Pending Approvals</h5>
                        <?php if ($pending_summary['total'] > 0): ?>
                            <span class="pending-badge"><?php echo $pending_summary['total']; ?> pending</span>
                        <?php endif; ?>
                    </div>
                    <div class="module-body">
                        <?php if (count($pending_summary['users']) > 0): ?>
                            <?php foreach (array_slice($pending_summary['users'], 0, 3) as $pending): ?>
                            <div class="pending-item">
                                <div class="pending-info">
                                    <div class="pending-name"><?php echo htmlspecialchars($pending['first_name'] . ' ' . $pending['last_name']); ?></div>
                                    <div class="pending-meta">
                                        <i class="fas fa-envelope"></i> <?php echo htmlspecialchars($pending['email']); ?>
                                        <span class="ms-2"><i class="fas fa-clock"></i> <?php echo date('M d', strtotime($pending['requested_at'])); ?></span>
                                    </div>
                                </div>
                                <a href="pending_users.php" class="btn btn-sm" style="background: #fee9e7; color: #b71c1c; padding: 4px 10px; border-radius: 20px; font-size: 0.7rem; text-decoration: none;">Review</a>
                            </div>
                            <?php endforeach; ?>
                            
                            <?php if ($pending_summary['total'] > 3): ?>
                            <div class="text-center mt-2">
                                <small class="text-muted">+ <?php echo ($pending_summary['total'] - 3); ?> more pending approvals</small>
                            </div>
                            <?php endif; ?>
                            
                            <div class="quick-actions mt-2">
                                <a href="pending_users.php" class="quick-action-btn" style="background: #fee9e7; color: #b71c1c;">Review All (<?php echo $pending_summary['total']; ?>)</a>
                            </div>
                        <?php else: ?>
                            <div class="text-center py-4">
                                <i class="fas fa-check-circle" style="color: #2ecc71; font-size: 2rem;"></i>
                                <p class="mt-2 small text-muted">All clear! No pending approvals</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Sidebar Toggle Functionality
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar-wrapper');
        const headerLogo = document.getElementById('headerLogo');
        
        const sidebarCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
        
        if (sidebarCollapsed) {
            sidebar.classList.add('collapsed');
        }
        
        if (menuToggle) {
            menuToggle.addEventListener('click', function() {
                sidebar.classList.toggle('collapsed');
                localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
            });
        }

        // Logo fallback
        const sidebarLogo = document.querySelector('.sidebar-heading img');
        if (sidebarLogo) {
            sidebarLogo.onerror = function() {
                this.style.display = 'none';
                this.nextSibling.textContent = 'Harana';
            };
        }
        
        if (headerLogo) {
            headerLogo.onerror = function() {
                this.style.display = 'none';
            };
        }
    </script>
</body>
</html>