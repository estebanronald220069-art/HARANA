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

// ============ FIXED: MEMBERS LIST WITH PAYMENT STATUS ============
$members_with_payment_status = [];

// Get all active members with their latest payment status
$members_with_payment_status = $db->getAll("
    SELECT 
        m.member_code,
        m.first_name,
        m.last_name,
        m.email,
        m.contact_number as phone,
        m.status as member_status,
        m.created_at,
        m.monthly_contribution,
        (
            SELECT payment_status 
            FROM payments 
            WHERE member_id = m.member_code 
            AND MONTH(payment_date) = MONTH(CURRENT_DATE())
            AND YEAR(payment_date) = YEAR(CURRENT_DATE())
            AND payment_status = 'confirmed'
            ORDER BY payment_date DESC 
            LIMIT 1
        ) as current_payment_status,
        (
            SELECT COUNT(*) 
            FROM payments 
            WHERE member_id = m.member_code 
            AND payment_status = 'confirmed'
            AND payment_date > CURRENT_DATE()
        ) as advanced_count,
        (
            SELECT COUNT(*) 
            FROM payments 
            WHERE member_id = m.member_code 
            AND payment_status = 'pending'
            AND MONTH(payment_date) = MONTH(CURRENT_DATE())
            AND YEAR(payment_date) = YEAR(CURRENT_DATE())
        ) as pending_count
    FROM members m
    WHERE m.status = 'active'
    ORDER BY m.last_name ASC
") ?? [];

// Categorize members
$members_with_status = [
    'paid' => [],
    'unpaid' => [],
    'advanced' => []
];

foreach ($members_with_payment_status as $member) {
    // Check if member has advanced payments (future months)
    if ($member['advanced_count'] > 0) {
        $members_with_status['advanced'][] = $member;
    } 
    // Check if member has paid current month
    elseif ($member['current_payment_status'] === 'confirmed') {
        $members_with_status['paid'][] = $member;
    } 
    // Otherwise, member is unpaid
    else {
        $members_with_status['unpaid'][] = $member;
    }
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
        
        /* Sidebar */
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
        }
        
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
            display: flex;
            flex-direction: column;
        }
        
        /* Navbar */
        .navbar {
            background: #fff !important;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
            padding: 0.7rem 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
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
        
        /* ===== CONTENT ===== */
        .content-container {
            padding: 20px;
            flex: 1;
            overflow-y: auto;
        }
        
        /* ===== MEMBERS LIST PANEL ===== */
        .members-filter-tabs {
            display: flex;
            gap: 0;
            margin-bottom: 20px;
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        
        .members-filter-tabs .filter-tab {
            padding: 12px 24px;
            border: none;
            background: none;
            font-weight: 500;
            font-size: 0.85rem;
            cursor: pointer;
            transition: all 0.2s;
            color: #6c757d;
            flex: 1;
            text-align: center;
            border-bottom: 3px solid transparent;
        }
        
        .members-filter-tabs .filter-tab:hover {
            background: #f8f9fa;
        }
        
        .members-filter-tabs .filter-tab.active {
            color: #375a7f;
            border-bottom-color: #375a7f;
            background: #f8f9fa;
        }
        
        .members-filter-tabs .filter-tab .count-badge {
            background: #e9ecef;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 0.75rem;
            margin-left: 6px;
        }
        
        .members-filter-tabs .filter-tab.active .count-badge {
            background: #375a7f;
            color: white;
        }
        
        .members-filter-tabs .filter-tab .count-badge.paid-badge { 
            background: #d4edda; 
            color: #155724; 
        }
        
        .members-filter-tabs .filter-tab .count-badge.unpaid-badge { 
            background: #f8d7da; 
            color: #721c24; 
        }
        
        .members-filter-tabs .filter-tab .count-badge.advanced-badge { 
            background: #d1ecf1; 
            color: #0c5460; 
        }
        
        .member-list-container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            overflow: hidden;
        }
        
        .member-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .member-table th {
            background: #f8f9fa;
            padding: 12px 16px;
            text-align: left;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6c757d;
            font-weight: 600;
            border-bottom: 2px solid #e9ecef;
        }
        
        .member-table td {
            padding: 12px 16px;
            border-bottom: 1px solid #e9ecef;
            font-size: 0.85rem;
            color: #2c3e50;
        }
        
        .member-table tr:hover td {
            background: #f8f9fa;
        }
        
        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.7rem;
            font-weight: 600;
            display: inline-block;
        }
        
        .status-badge.paid {
            background: #d4edda;
            color: #155724;
        }
        
        .status-badge.unpaid {
            background: #f8d7da;
            color: #721c24;
        }
        
        .status-badge.advanced {
            background: #d1ecf1;
            color: #0c5460;
        }
        
        .status-badge.inactive {
            background: #e2e3e5;
            color: #383d41;
        }
        
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: #6c757d;
        }
        
        .empty-state i {
            font-size: 3rem;
            color: #dee2e6;
            margin-bottom: 15px;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .members-filter-tabs {
                flex-direction: column;
            }
            
            .member-table {
                font-size: 0.8rem;
            }
            
            .member-table th,
            .member-table td {
                padding: 8px 10px;
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
            <!-- Navbar -->
            <nav class="navbar navbar-light bg-light">
                <div class="navbar-left">
                    <img src="../assets/images/harana-logo.png" alt="Harana" class="header-logo" id="headerLogo" onerror="this.style.display='none';">
                    <span class="navbar-brand"><i class="fas fa-users me-2"></i>Members</span>
                </div>
                <div class="navbar-right">
                    <span class="text-muted small">
                        <i class="fas fa-calendar-alt me-1"></i><?php echo date('F j, Y'); ?>
                    </span>
                    <span class="small"><i class="fas fa-user-circle me-2"></i><?php echo htmlspecialchars($current_user['full_name']); ?></span>
                </div>
            </nav>

            <!-- CONTENT -->
            <div class="content-container">
                
                <!-- Filter Tabs -->
                <div class="members-filter-tabs">
                    <button class="filter-tab active" data-filter="all">
                        All Members
                        <span class="count-badge"><?php echo count($members_with_payment_status); ?></span>
                    </button>
                    <button class="filter-tab" data-filter="paid">
                        <i class="fas fa-check-circle" style="color: #28a745;"></i> Paid
                        <span class="count-badge paid-badge"><?php echo count($members_with_status['paid']); ?></span>
                    </button>
                    <button class="filter-tab" data-filter="unpaid">
                        <i class="fas fa-exclamation-circle" style="color: #dc3545;"></i> Unpaid
                        <span class="count-badge unpaid-badge"><?php echo count($members_with_status['unpaid']); ?></span>
                    </button>
                    <button class="filter-tab" data-filter="advanced">
                        <i class="fas fa-star" style="color: #17a2b8;"></i> Advanced
                        <span class="count-badge advanced-badge"><?php echo count($members_with_status['advanced']); ?></span>
                    </button>
                </div>

                <!-- Member List -->
                <div class="member-list-container">
                    <div class="table-responsive">
                        <table class="member-table">
                            <thead>
                                <tr>
                                    <th>Member Code</th>
                                    <th>Member</th>
                                    <th>Email</th>
                                    <th>Phone</th>
                                    <th>Status</th>
                                    <th>Payment Status</th>
                                </tr>
                            </thead>
                            <tbody id="memberTableBody">
                                <?php if (count($members_with_payment_status) > 0): ?>
                                    <?php foreach ($members_with_payment_status as $member): ?>
                                        <?php 
                                            $status_class = 'unpaid';
                                            $status_text = 'Unpaid';
                                            if ($member['advanced_count'] > 0) {
                                                $status_class = 'advanced';
                                                $status_text = 'Advanced';
                                            } elseif ($member['current_payment_status'] === 'confirmed') {
                                                $status_class = 'paid';
                                                $status_text = 'Paid';
                                            }
                                        ?>
                                        <tr class="member-row" data-status="<?php echo $status_class; ?>">
                                            <td>
                                                <span class="badge bg-secondary"><?php echo htmlspecialchars($member['member_code']); ?></span>
                                            </td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?></strong>
                                            </td>
                                            <td><?php echo htmlspecialchars($member['email']); ?></td>
                                            <td><?php echo htmlspecialchars($member['phone']); ?></td>
                                            <td>
                                                <span class="status-badge <?php echo $member['member_status'] === 'active' ? 'paid' : 'inactive'; ?>">
                                                    <?php echo ucfirst($member['member_status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="status-badge <?php echo $status_class; ?>">
                                                    <?php echo $status_text; ?>
                                                    <?php if ($status_class === 'advanced'): ?>
                                                        <small>(<?php echo $member['advanced_count']; ?> months)</small>
                                                    <?php endif; ?>
                                                    <?php if ($member['pending_count'] > 0 && $status_class === 'unpaid'): ?>
                                                        <small>(<?php echo $member['pending_count']; ?> pending)</small>
                                                    <?php endif; ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr>
                                        <td colspan="6">
                                            <div class="empty-state">
                                                <i class="fas fa-users"></i>
                                                <p>No active members found</p>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
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

        // ===== MEMBERS FILTER FUNCTIONALITY =====
        const filterTabs = document.querySelectorAll('.filter-tab');
        const memberRows = document.querySelectorAll('.member-row');

        filterTabs.forEach(filterTab => {
            filterTab.addEventListener('click', function() {
                // Update active filter tab
                filterTabs.forEach(ft => ft.classList.remove('active'));
                this.classList.add('active');
                
                const filter = this.dataset.filter;
                
                // Show/hide member rows based on filter
                memberRows.forEach(row => {
                    if (filter === 'all') {
                        row.style.display = '';
                    } else {
                        const rowStatus = row.dataset.status;
                        if (rowStatus === filter) {
                            row.style.display = '';
                        } else {
                            row.style.display = 'none';
                        }
                    }
                });
            });
        });
    </script>
</body>
</html>