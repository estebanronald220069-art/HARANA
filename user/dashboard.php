<?php
// user/dashboard.php - COMPACT VERSION with REAL NOTIFICATIONS
require_once '../includes/config.php';
require_once '../includes/db_connection.php';
require_once '../includes/security.php';
require_once '../includes/auth.php';
require_once '../includes/user_functions.php';

$auth->requireLogin();
$current_user = $auth->getCurrentUser();

$db = Database::getInstance();

// Get member details using member_code from database
$member = null;
$member_code = null;

// Try to find member by user_id (most reliable)
if (!empty($current_user['user_id'])) {
    $member = $db->getSingle(
        "SELECT * FROM members WHERE user_id = ?",
        [$current_user['user_id']],
        'i'
    );
}

// If not found, try by username
if (!$member && !empty($current_user['username'])) {
    $member = $db->getSingle(
        "SELECT * FROM members WHERE username = ?",
        [$current_user['username']],
        's'
    );
}

// If still not found, get the first active member with a user_id (for demo)
if (!$member) {
    $member = $db->getSingle(
        "SELECT * FROM members WHERE status = 'active' AND user_id IS NOT NULL ORDER BY member_code DESC LIMIT 1"
    );
}

// If no member exists at all, create sample data for display
if (!$member) {
    $member = [
        'member_code' => '2024' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT),
        'first_name' => 'Juan',
        'last_name' => 'Dela Cruz',
        'middle_name' => 'Santos',
        'chapter' => 'GUIMBA',
        'group_name' => 'Group A',
        'barangay' => 'Singalat',
        'city' => 'Palayan City',
        'province' => 'Nueva Ecija',
        'contact_number' => '09123456789',
        'email' => 'juan.delacruz@example.com',
        'birth_date' => '1990-01-15',
        'gender' => 'Male',
        'civil_status' => 'Married',
        'date_joined' => date('Y-m-d', strtotime('-2 years')),
        'monthly_contribution' => 100.00,
        'beneficiary_name' => 'Maria Dela Cruz',
        'beneficiary_relation' => 'Spouse',
        'status' => 'active'
    ];
}

$member_code = $member['member_code'];
$user_id = $current_user['user_id'];

// Get financial data using member_code
$balance = getUserBalance($db, $member_code);
$months_as_member = calculateMonthsAsMember($member);
$monthly_contribution = floatval($member['monthly_contribution'] ?? 100);
$expected_total = $months_as_member * $monthly_contribution;
$total_paid = floatval($balance['total_paid'] ?? 0);
$current_balance = $expected_total - $total_paid;

// Get recent payments (LAST 5) using member_code
$recent_payments = getUserRecentPayments($db, $member_code, 5);

// Get all payments count for total contributions
$total_payments_count = getUserPaymentCount($db, $member_code);
$total_contributions = $total_paid;

// Calculate Death Benefit based on years of membership
function calculateDeathBenefit($years) {
    $benefit_table = [
        0 => 0,
        1 => 80000, 2 => 80000,
        3 => 90000, 4 => 90000,
        5 => 100000, 6 => 100000,
        7 => 110000, 8 => 110000,
        9 => 115000,
        10 => 120000, 11 => 125000, 12 => 130000,
        13 => 135000, 14 => 140000, 15 => 145000,
        16 => 150000
    ];
    
    $years_int = (int)$years;
    if ($years_int >= 16) return 150000;
    if ($years_int >= 1 && $years_int <= 16) return $benefit_table[$years_int];
    return 0;
}

$years_as_member = floor($months_as_member / 12);
$base_benefit = calculateDeathBenefit($years_as_member);

// Check if member is a good payer (no missed payments for 5+ years)
$is_good_payer = false;
if ($years_as_member >= 5) {
    // Get payment history for last 5 years
    $five_years_ago = date('Y-m-d', strtotime('-5 years'));
    $payments_last_5_years = $db->getSingle(
        "SELECT COUNT(*) as cnt FROM payments 
         WHERE member_id = ? 
         AND payment_status = 'confirmed' 
         AND payment_date >= ?",
        [$member_code, $five_years_ago],
        'ss'
    );
    // Good payer if at least 5*12 = 60 payments in last 5 years (simplified)
    $expected_payments = 60;
    $actual_payments = intval($payments_last_5_years['cnt'] ?? 0);
    $is_good_payer = ($actual_payments >= $expected_payments - 6); // Allow 6 months grace
}

$good_payer_bonus = $is_good_payer ? 5000 : 0;
$total_benefit = $base_benefit + $good_payer_bonus;

// Get next due date
$last_payment = $db->getSingle(
    "SELECT MAX(payment_date) as last_date FROM payments 
     WHERE member_id = ? AND payment_status = 'confirmed'",
    [$member_code], 's'
);
$last_payment_date = $last_payment['last_date'] ?? $member['date_joined'];
$next_due_date = $last_payment_date ? date('Y-m-d', strtotime($last_payment_date . ' +1 month')) : date('Y-m-d', strtotime('first day of next month'));

// ========== GET REAL NOTIFICATIONS FROM DATABASE ==========
// Get unread notification count for badge
$unread_count = $db->getSingle(
    "SELECT COUNT(*) as total FROM notifications WHERE user_id = ? AND is_read = 0",
    [$user_id], 'i'
)['total'] ?? 0;

// Get latest notifications for dashboard (limit to 3 most recent)
$notifications = $db->getAll(
    "SELECT * FROM notifications 
     WHERE user_id = ? 
     ORDER BY created_at DESC 
     LIMIT 3",
    [$user_id], 'i'
);

// If no notifications in database, use sample announcements
if (empty($notifications)) {
    // Try to get from announcements table as fallback
    $announcements_data = $db->getAll(
        "SELECT title, content, announcement_type as type, created_at 
         FROM announcements 
         WHERE status = 'active' 
         ORDER BY display_order ASC, created_at DESC 
         LIMIT 3"
    );
    
    // Convert announcements to notification format
    foreach ($announcements_data as $ann) {
        $notifications[] = [
            'notification_id' => 0,
            'title' => $ann['title'],
            'message' => $ann['content'],
            'type' => $ann['type'] ?? 'announcement',
            'is_read' => 0,
            'link' => null,
            'created_at' => $ann['created_at']
        ];
    }
}

// If still empty, use fallback announcements
if (empty($notifications)) {
    $notifications = [
        [
            'title' => 'Welcome to Harana!',
            'message' => 'Thank you for being a member. Check your dashboard regularly for updates.',
            'type' => 'system',
            'created_at' => date('Y-m-d H:i:s')
        ],
        [
            'title' => 'Monthly Contribution',
            'message' => 'Remember to pay your monthly contribution on time to maintain your benefits.',
            'type' => 'reminder',
            'created_at' => date('Y-m-d H:i:s', strtotime('-2 days'))
        ]
    ];
}

$is_admin = ($current_user['role'] === 'admin');
$csrf_token = Security::generateCSRFToken();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>Dashboard - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: #f4f7fc;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            height: 100vh;
            overflow: hidden;
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
        #sidebar-wrapper.collapsed .list-group-item i {
            margin-right: 0;
            width: 100%;
            text-align: center;
            font-size: 1.2rem;
        }
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

        #page-content-wrapper {
            flex: 1;
            background: #f4f7fc;
            height: 100vh;
            overflow-y: auto;
            padding: 0;
        }

        /* Navbar - Compact */
        .navbar {
            background: #fff !important;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
            padding: 0.5rem 1.2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .navbar-brand { font-size: 1rem; font-weight: 500; color: #375a7f !important; }
        .navbar-right { display: flex; align-items: center; gap: 15px; }

        /* Dashboard Grid - 2 Columns, Compact */
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            padding: 15px;
            height: calc(100vh - 52px);
            overflow-y: auto;
        }

        /* Cards - Compact */
        .module-card {
            background: white;
            border-radius: 10px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            overflow: hidden;
            display: flex;
            flex-direction: column;
            height: fit-content;
        }

        .module-header {
            padding: 0.8rem 1rem;
            background: #f8f9fa;
            border-bottom: 1px solid #e9ecef;
        }

        .module-header h5 {
            margin: 0;
            font-weight: 600;
            font-size: 0.85rem;
            color: #2c3e50;
        }

        .module-header i { margin-right: 6px; color: #375a7f; }

        .module-body {
            padding: 1rem;
        }

        /* Stats Row */
        .stats-row {
            display: flex;
            gap: 12px;
            margin-bottom: 12px;
        }

        .stat-box {
            flex: 1;
            background: #f8f9fa;
            border-radius: 8px;
            padding: 8px;
            text-align: center;
        }

        .stat-number {
            font-size: 1.1rem;
            font-weight: 700;
            color: #2c3e50;
        }

        .stat-label {
            font-size: 0.6rem;
            color: #6c757d;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        /* Balance Card */
        .balance-card {
            background: linear-gradient(135deg, #375a7f, #2c4a6b);
            border-radius: 8px;
            padding: 12px;
            color: white;
            margin-bottom: 12px;
        }

        .balance-amount {
            font-size: 1.4rem;
            font-weight: 700;
        }

        .balance-status {
            display: inline-block;
            padding: 3px 10px;
            background: rgba(255,255,255,0.2);
            border-radius: 20px;
            font-size: 0.65rem;
            margin-top: 6px;
        }

        /* Progress Bar */
        .payment-progress {
            height: 6px;
            background: #e9ecef;
            border-radius: 3px;
            overflow: hidden;
            margin: 10px 0;
        }

        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #28a745, #20c997);
            transition: width 0.3s ease;
        }

        /* Benefit Cards */
        .benefit-card {
            background: linear-gradient(135deg, #28a745, #20c997);
            border-radius: 8px;
            padding: 12px;
            color: white;
            text-align: center;
        }

        .benefit-amount {
            font-size: 1.3rem;
            font-weight: 700;
        }

        .benefit-label {
            font-size: 0.7rem;
            opacity: 0.9;
        }

        .benefit-small {
            font-size: 0.65rem;
            margin-top: 5px;
            opacity: 0.8;
        }

        /* Timeline Items (Recent Transactions) */
        .timeline-item {
            padding: 8px 0;
            border-bottom: 1px solid #e9ecef;
        }

        .timeline-item:last-child { border-bottom: none; }

        .timeline-date {
            font-size: 0.7rem;
            color: #6c757d;
        }

        .timeline-amount {
            font-weight: 700;
            color: #28a745;
            font-size: 0.85rem;
        }

        /* Notification Items */
        .notification-item {
            padding: 8px 0;
            border-bottom: 1px solid #e9ecef;
        }

        .notification-item.unread {
            background: rgba(55,90,127,0.03);
            border-left: 2px solid #375a7f;
            padding-left: 8px;
        }

        .notification-title {
            font-weight: 600;
            font-size: 0.8rem;
            color: #2c3e50;
        }

        .notification-message {
            font-size: 0.7rem;
            color: #6c757d;
            margin-top: 3px;
            line-height: 1.4;
        }

        .notification-time {
            font-size: 0.6rem;
            color: #adb5bd;
            margin-top: 3px;
        }

        .notification-badge {
            display: inline-block;
            padding: 2px 6px;
            background: rgba(220,53,69,0.1);
            color: #dc3545;
            border-radius: 10px;
            font-size: 0.6rem;
            margin-left: 6px;
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 20px;
            color: #6c757d;
        }

        .empty-icon {
            font-size: 2rem;
            color: #dee2e6;
            margin-bottom: 8px;
        }

        /* Quick Stats Row */
        .quick-stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 12px;
        }

        /* Unread Badge on Sidebar */
        .badge-count {
            position: absolute !important;
            top: 50% !important;
            right: 10px !important;
            transform: translateY(-50%) !important;
            font-size: 0.65rem !important;
            padding: 3px 6px !important;
        }

        /* Responsive */
        @media (max-width: 992px) {
            .dashboard-grid {
                grid-template-columns: 1fr;
                height: auto;
                overflow-y: visible;
            }
            body { overflow: auto; }
            #wrapper { height: auto; overflow: visible; }
            #page-content-wrapper { overflow-y: visible; height: auto; }
        }

        @media (max-width: 768px) {
            .stats-row { flex-direction: column; }
            .quick-stats { grid-template-columns: 1fr; }
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
                <a href="dashboard.php" class="list-group-item active"><i class="fas fa-tachometer-alt"></i><span>Dashboard</span></a>
                <a href="profile.php" class="list-group-item"><i class="fas fa-user"></i><span>My Profile</span></a>
                <a href="payments.php" class="list-group-item"><i class="fas fa-credit-card"></i><span>Payment History</span></a>
                <a href="beneficiary.php" class="list-group-item"><i class="fas fa-heart"></i><span>Beneficiary</span></a>
                <a href="notifications.php" class="list-group-item position-relative">
                    <i class="fas fa-bell"></i><span>Notifications</span>
                    <?php if ($unread_count > 0): ?>
                        <span class="badge bg-danger badge-count"><?php echo $unread_count; ?></span>
                    <?php endif; ?>
                </a>
                <a href="organization.php" class="list-group-item"><i class="fas fa-building"></i><span>Organization</span></a>
                <a href="support.php" class="list-group-item"><i class="fas fa-life-ring"></i><span>Support</span></a>
                <?php if ($is_admin): ?>
                <a href="../admin/dashboard.php" class="list-group-item"><i class="fas fa-crown"></i><span>Admin Panel</span></a>
                <?php endif; ?>
                <a href="../logout.php" class="list-group-item" onclick="return confirm('Are you sure?');"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a>
            </div>
        </div>

        <!-- Main Content -->
        <div id="page-content-wrapper">
            <?php 
            $page_title = 'Dashboard';
            include '../includes/header.php'; 
            ?>

            <div class="dashboard-grid">
                <!-- Left Column -->
                <div>
                    <!-- Financial Summary Card -->
                    <div class="module-card">
                        <div class="module-header">
                            <h5><i class="fas fa-chart-line"></i> Financial Summary</h5>
                        </div>
                        <div class="module-body">
                            <div class="balance-card">
                                <div class="balance-amount">₱<?php echo number_format(abs($current_balance), 2); ?></div>
                                <div class="balance-status">
                                    <?php if ($current_balance > 0): ?>
                                        <i class="fas fa-exclamation-triangle me-1"></i> Balance Due
                                    <?php elseif ($current_balance < 0): ?>
                                        <i class="fas fa-check-circle me-1"></i> In Credit
                                    <?php else: ?>
                                        <i class="fas fa-check-circle me-1"></i> Fully Paid
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="payment-progress">
                                <div class="progress-fill" style="width: <?php echo $expected_total > 0 ? min(100, ($total_paid / $expected_total) * 100) : 0; ?>%"></div>
                            </div>

                            <div class="stats-row">
                                <div class="stat-box">
                                    <div class="stat-number">₱<?php echo number_format($total_paid, 2); ?></div>
                                    <div class="stat-label">Total Paid</div>
                                </div>
                                <div class="stat-box">
                                    <div class="stat-number">₱<?php echo number_format($expected_total, 2); ?></div>
                                    <div class="stat-label">Expected Total</div>
                                </div>
                                <div class="stat-box">
                                    <div class="stat-number"><?php echo $months_as_member; ?></div>
                                    <div class="stat-label">Months Active</div>
                                </div>
                            </div>

                            <div class="alert alert-warning py-2 px-3 mb-0" style="font-size: 0.7rem;">
                                <i class="fas fa-calendar-exclamation me-2"></i>
                                <strong>Next Due Date:</strong> <?php echo date('F j, Y', strtotime($next_due_date)); ?>
                            </div>
                        </div>
                    </div>

                    <!-- Total Contributions & Benefits Card -->
                    <div class="module-card">
                        <div class="module-header">
                            <h5><i class="fas fa-chart-simple"></i> Contributions & Benefits</h5>
                        </div>
                        <div class="module-body">
                            <div class="quick-stats">
                                <div class="stat-box" style="background: linear-gradient(135deg, #28a745, #20c997); color: white;">
                                    <div class="stat-number" style="color: white;">₱<?php echo number_format($total_contributions, 2); ?></div>
                                    <div class="stat-label" style="color: rgba(255,255,255,0.9);">Total Contributions</div>
                                </div>
                                <div class="stat-box" style="background: linear-gradient(135deg, #ffc107, #e0a800);">
                                    <div class="stat-number" style="color: #2c3e50;">₱<?php echo number_format($total_benefit, 2); ?></div>
                                    <div class="stat-label" style="color: #2c3e50;">Total Death Benefit</div>
                                </div>
                            </div>
                            <div class="stats-row" style="margin-top: 8px;">
                                <div class="stat-box">
                                    <div class="stat-number">₱<?php echo number_format($base_benefit, 2); ?></div>
                                    <div class="stat-label">Base Benefit (<?php echo $years_as_member; ?> yrs)</div>
                                </div>
                                <div class="stat-box">
                                    <div class="stat-number">₱<?php echo number_format($good_payer_bonus, 2); ?></div>
                                    <div class="stat-label">Good Payer Bonus</div>
                                    <?php if ($is_good_payer): ?>
                                        <small class="text-success"><i class="fas fa-check-circle"></i> Eligible</small>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="small text-muted mt-2 text-center">
                                <i class="fas fa-info-circle me-1"></i> Benefit based on <?php echo $years_as_member; ?> year(s) of membership
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right Column -->
                <div>
                    <!-- Recent Transactions Card -->
                    <div class="module-card">
                        <div class="module-header">
                            <h5><i class="fas fa-history"></i> Recent Transactions</h5>
                        </div>
                        <div class="module-body">
                            <?php if (empty($recent_payments)): ?>
                                <div class="empty-state">
                                    <div class="empty-icon"><i class="fas fa-receipt"></i></div>
                                    <p class="small mb-0">No payment history yet.</p>
                                    <small class="text-muted">Make your first payment today!</small>
                                </div>
                            <?php else: ?>
                                <?php foreach ($recent_payments as $payment): ?>
                                <div class="timeline-item">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <div class="timeline-date">
                                                <i class="far fa-calendar-alt me-1"></i><?php echo date('M d, Y', strtotime($payment['payment_date'])); ?>
                                            </div>
                                            <div class="small text-muted">Ref: <?php echo $payment['receipt_number'] ?? 'N/A'; ?></div>
                                        </div>
                                        <div class="text-end">
                                            <div class="timeline-amount">₱<?php echo number_format($payment['amount'], 2); ?></div>
                                            <span class="badge bg-<?php echo $payment['payment_status'] == 'confirmed' ? 'success' : 'warning'; ?>" style="font-size: 0.6rem;">
                                                <?php echo ucfirst($payment['payment_status']); ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                                
                                <?php if ($total_payments_count > 5): ?>
                                <div class="text-center mt-2">
                                    <a href="payments.php" class="small">View all <?php echo $total_payments_count; ?> transactions <i class="fas fa-arrow-right ms-1"></i></a>
                                </div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Notifications / Announcements Card -->
                    <div class="module-card">
                        <div class="module-header">
                            <h5><i class="fas fa-bell"></i> Recent Notifications</h5>
                            <?php if ($unread_count > 0): ?>
                                <span class="badge bg-danger" style="font-size: 0.6rem;"><?php echo $unread_count; ?> new</span>
                            <?php endif; ?>
                        </div>
                        <div class="module-body">
                            <?php if (empty($notifications)): ?>
                                <div class="empty-state">
                                    <div class="empty-icon"><i class="fas fa-bell-slash"></i></div>
                                    <p class="small mb-0">No notifications yet.</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($notifications as $notification): ?>
                                <div class="notification-item <?php echo (isset($notification['is_read']) && !$notification['is_read']) ? 'unread' : ''; ?>">
                                    <div class="notification-title">
                                        <?php echo htmlspecialchars($notification['title']); ?>
                                        <?php if (isset($notification['is_read']) && !$notification['is_read']): ?>
                                            <span class="notification-badge">New</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="notification-message">
                                        <?php echo htmlspecialchars(substr($notification['message'], 0, 100)); ?>
                                        <?php if (strlen($notification['message']) > 100): ?>...<?php endif; ?>
                                    </div>
                                    <div class="notification-time">
                                        <i class="far fa-clock me-1"></i>
                                        <?php 
                                        $time = strtotime($notification['created_at']);
                                        $diff = time() - $time;
                                        if ($diff < 60) {
                                            echo 'Just now';
                                        } elseif ($diff < 3600) {
                                            echo floor($diff / 60) . ' minutes ago';
                                        } elseif ($diff < 86400) {
                                            echo floor($diff / 3600) . ' hours ago';
                                        } else {
                                            echo date('M d, Y', $time);
                                        }
                                        ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                                
                                <div class="text-center mt-2">
                                    <a href="notifications.php" class="small">
                                        View all notifications 
                                        <?php if ($unread_count > 0): ?>
                                            (<?php echo $unread_count; ?> unread)
                                        <?php endif; ?>
                                        <i class="fas fa-arrow-right ms-1"></i>
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
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
        
        if (sidebarCollapsed) {
            sidebar.classList.add('collapsed');
        }
        
        if (menuToggle) {
            menuToggle.addEventListener('click', function() {
                sidebar.classList.toggle('collapsed');
                localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
            });
        }

        // Handle logo fallback
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