<?php
// Userpage.php - Member Dashboard
require_once 'includes/config.php';
require_once 'includes/db_connection.php';
require_once 'includes/security.php';
require_once 'includes/auth.php';

// Require login for this page
$auth->requireLogin();
$current_user = $auth->getCurrentUser();

$db = Database::getInstance();

// Get member details based on logged-in user
// Since we're in test mode with bypass, let's try to find the member by username or email
$member = null;

// Try to find member by username
if (!empty($current_user['username'])) {
    $member = $db->getSingle(
        "SELECT * FROM members WHERE username = ? OR email = ?",
        [$current_user['username'], $current_user['email'] ?? ''],
        'ss'
    );
}

// If not found, try to find by email
if (!$member && !empty($current_user['email'])) {
    $member = $db->getSingle(
        "SELECT * FROM members WHERE email = ?",
        [$current_user['email']],
        's'
    );
}

// If still not found, use the first active member for demo
if (!$member) {
    $member = $db->getSingle(
        "SELECT * FROM members WHERE status = 'active' ORDER BY member_id DESC LIMIT 1"
    );
}

// If no member exists at all, create a dummy member for display
if (!$member) {
    $member = [
        'member_id' => 1,
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

$member_id = $member['member_id'];

// Get member balance
$balance = $db->getSingle(
    "SELECT * FROM member_balances WHERE member_id = ?",
    [$member_id],
    'i'
);

// Calculate expected total based on months since joining
$months_as_member = 0;
if (!empty($member['date_joined']) && $member['date_joined'] != '0000-00-00') {
    $join_date = new DateTime($member['date_joined']);
    $today = new DateTime();
    $months_as_member = $join_date->diff($today)->m + ($join_date->diff($today)->y * 12);
}
$expected_total = $months_as_member * ($member['monthly_contribution'] ?? 100);

$total_paid = $balance['total_paid'] ?? 0;
$current_balance = ($expected_total - $total_paid);

// Get payment history (last 5 payments)
$payments = $db->getAll(
    "SELECT * FROM payments 
     WHERE member_id = ? AND payment_status = 'confirmed' 
     ORDER BY payment_date DESC 
     LIMIT 5",
    [$member_id],
    'i'
);

// Get all payments for full history
$all_payments = $db->getAll(
    "SELECT * FROM payments 
     WHERE member_id = ? 
     ORDER BY payment_date DESC",
    [$member_id],
    'i'
);

// Get upcoming due date (next month)
$next_due_date = date('Y-m-d', strtotime('first day of next month'));

// Get council members for display
$council_members = $db->getAll(
    "SELECT full_name, position, contact_number, email FROM council WHERE status = 'active' ORDER BY position LIMIT 5"
);

// Get chapter officials (if applicable)
$chapter_officials = $db->getAll(
    "SELECT full_name, position FROM council 
     WHERE status = 'active' AND (position LIKE '%Coordinator%' OR position LIKE '%Leader%' OR position LIKE '%Officer%')
     ORDER BY position LIMIT 5"
);

// Get announcements (you may need to create an announcements table)
// For now, we'll create sample announcements
$announcements = [
    [
        'title' => 'Monthly General Assembly',
        'content' => 'Join us for our monthly general assembly on March 15, 2026 at 2:00 PM at the main office.',
        'date' => '2026-03-01',
        'type' => 'meeting'
    ],
    [
        'title' => 'Payment Deadline Reminder',
        'content' => 'March contributions are due by March 10, 2026. Please settle your payments on time.',
        'date' => '2026-03-05',
        'type' => 'payment'
    ],
    [
        'title' => 'Financial Literacy Seminar',
        'content' => 'Free financial literacy seminar for members and their families on March 20, 2026.',
        'date' => '2026-03-10',
        'type' => 'event'
    ],
    [
        'title' => 'Office Closure',
        'content' => 'The office will be closed on March 28-30 for a staff retreat. Regular operations resume on March 31.',
        'date' => '2026-03-25',
        'type' => 'announcement'
    ]
];

// Get upcoming events (next 30 days)
$upcoming_events = array_filter($announcements, function($a) {
    return strtotime($a['date']) > time() && strtotime($a['date']) < strtotime('+30 days');
});

// Get birthdays this month (for members - in a real system, you'd query all members)
$birthdays_this_month = [];
if (!empty($member['birth_date']) && $member['birth_date'] != '0000-00-00') {
    $birth_month = date('m', strtotime($member['birth_date']));
    if ($birth_month == date('m')) {
        $birthdays_this_month[] = [
            'name' => $member['first_name'] . ' ' . $member['last_name'],
            'day' => date('j', strtotime($member['birth_date']))
        ];
    }
}

// Check if user is admin for additional features
$is_admin = ($current_user['role'] === 'admin');

$csrf_token = Security::generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Member Dashboard - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #667eea;
            --secondary-color: #764ba2;
            --success-color: #28a745;
            --warning-color: #ffc107;
            --danger-color: #dc3545;
            --dark-color: #2c3e50;
            --light-color: #f8f9fa;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #e9ecef 100%);
            min-height: 100vh;
        }
        
        /* Top Navigation */
        .top-navbar {
            background: white;
            box-shadow: 0 2px 20px rgba(0,0,0,0.1);
            padding: 10px 20px;
            position: sticky;
            top: 0;
            z-index: 1000;
        }
        
        .brand {
            font-size: 1.5rem;
            font-weight: 700;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            text-decoration: none;
        }
        
        .brand i {
            margin-right: 10px;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .user-dropdown {
            display: flex;
            align-items: center;
            cursor: pointer;
            padding: 5px 15px;
            border-radius: 50px;
            background: var(--light-color);
            transition: all 0.3s ease;
        }
        
        .user-dropdown:hover {
            background: #e9ecef;
        }
        
        .user-avatar {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            margin-right: 10px;
        }
        
        .user-info {
            line-height: 1.2;
        }
        
        .user-name {
            font-weight: 600;
            color: var(--dark-color);
        }
        
        .user-role {
            font-size: 0.8rem;
            color: #6c757d;
        }
        
        /* Sidebar */
        .sidebar {
            background: white;
            box-shadow: 2px 0 20px rgba(0,0,0,0.1);
            height: calc(100vh - 70px);
            position: sticky;
            top: 70px;
            overflow-y: auto;
            transition: all 0.3s ease;
        }
        
        .sidebar-menu {
            list-style: none;
            padding: 0;
            margin: 0;
        }
        
        .sidebar-item {
            margin: 5px 0;
        }
        
        .sidebar-link {
            display: flex;
            align-items: center;
            padding: 12px 20px;
            color: var(--dark-color);
            text-decoration: none;
            transition: all 0.3s ease;
            border-left: 3px solid transparent;
        }
        
        .sidebar-link:hover,
        .sidebar-link.active {
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.1), rgba(118, 75, 162, 0.1));
            border-left-color: var(--primary-color);
            color: var(--primary-color);
        }
        
        .sidebar-link i {
            width: 25px;
            margin-right: 10px;
            font-size: 1.2rem;
        }
        
        .sidebar-link .badge {
            margin-left: auto;
        }
        
        /* Main Content */
        .main-content {
            padding: 20px;
            min-height: calc(100vh - 70px);
            background: linear-gradient(135deg, #f5f7fa 0%, #e9ecef 100%);
        }
        
        /* Welcome Banner */
        .welcome-banner {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 30px;
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.3);
            position: relative;
            overflow: hidden;
        }
        
        .welcome-banner::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, transparent 70%);
            animation: rotate 20s linear infinite;
        }
        
        @keyframes rotate {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        
        .welcome-banner h2 {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 10px;
            position: relative;
            z-index: 2;
        }
        
        .welcome-banner p {
            font-size: 1.1rem;
            opacity: 0.9;
            margin-bottom: 0;
            position: relative;
            z-index: 2;
        }
        
        .welcome-banner .date {
            position: absolute;
            top: 30px;
            right: 30px;
            background: rgba(255,255,255,0.2);
            padding: 10px 20px;
            border-radius: 50px;
            font-size: 0.9rem;
        }
        
        /* Cards */
        .dashboard-card {
            background: white;
            border-radius: 15px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            transition: all 0.3s ease;
            overflow: hidden;
        }
        
        .dashboard-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.2);
        }
        
        .card-header {
            padding: 20px;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .card-header h5 {
            margin: 0;
            font-weight: 600;
        }
        
        .card-header i {
            font-size: 1.5rem;
        }
        
        .card-body {
            padding: 20px;
        }
        
        /* Profile Card */
        .profile-card {
            text-align: center;
            padding: 20px;
        }
        
        .profile-avatar {
            width: 120px;
            height: 120px;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 3rem;
            font-weight: 600;
            margin: 0 auto 20px;
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.3);
        }
        
        .profile-name {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--dark-color);
            margin-bottom: 5px;
        }
        
        .profile-code {
            background: var(--light-color);
            padding: 5px 15px;
            border-radius: 50px;
            display: inline-block;
            margin-bottom: 15px;
            font-size: 0.9rem;
            color: var(--primary-color);
            font-weight: 600;
        }
        
        .profile-info {
            text-align: left;
            margin-top: 20px;
        }
        
        .info-item {
            display: flex;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #e9ecef;
        }
        
        .info-item:last-child {
            border-bottom: none;
        }
        
        .info-icon {
            width: 35px;
            height: 35px;
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.1), rgba(118, 75, 162, 0.1));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            color: var(--primary-color);
        }
        
        .info-label {
            font-size: 0.9rem;
            color: #6c757d;
            margin-bottom: 2px;
        }
        
        .info-value {
            font-weight: 600;
            color: var(--dark-color);
        }
        
        /* Financial Summary */
        .balance-card {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        
        .balance-label {
            font-size: 0.9rem;
            opacity: 0.9;
            margin-bottom: 5px;
        }
        
        .balance-amount {
            font-size: 2rem;
            font-weight: 700;
        }
        
        .balance-status {
            display: inline-block;
            padding: 5px 15px;
            border-radius: 50px;
            background: rgba(255,255,255,0.2);
            font-size: 0.9rem;
            margin-top: 10px;
        }
        
        .stat-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-top: 20px;
        }
        
        .stat-item {
            background: var(--light-color);
            padding: 15px;
            border-radius: 10px;
            text-align: center;
        }
        
        .stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-color);
        }
        
        .stat-label {
            font-size: 0.85rem;
            color: #6c757d;
        }
        
        /* Payment History */
        .payment-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 15px 0;
            border-bottom: 1px solid #e9ecef;
        }
        
        .payment-item:last-child {
            border-bottom: none;
        }
        
        .payment-date {
            font-weight: 600;
            color: var(--dark-color);
        }
        
        .payment-amount {
            font-weight: 700;
            color: var(--success-color);
        }
        
        .payment-receipt {
            color: var(--primary-color);
            text-decoration: none;
            font-size: 0.9rem;
        }
        
        .payment-receipt:hover {
            text-decoration: underline;
        }
        
        .payment-status {
            font-size: 0.8rem;
            padding: 3px 10px;
            border-radius: 50px;
        }
        
        .status-confirmed {
            background: #d4edda;
            color: #155724;
        }
        
        .status-pending {
            background: #fff3cd;
            color: #856404;
        }
        
        /* Quick Actions */
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
        }
        
        .quick-action {
            text-align: center;
            padding: 15px;
            background: var(--light-color);
            border-radius: 10px;
            text-decoration: none;
            color: var(--dark-color);
            transition: all 0.3s ease;
        }
        
        .quick-action:hover {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            transform: translateY(-3px);
        }
        
        .quick-action i {
            font-size: 1.5rem;
            margin-bottom: 5px;
            display: block;
        }
        
        .quick-action span {
            font-size: 0.9rem;
            font-weight: 500;
        }
        
        /* Notifications */
        .notification-item {
            display: flex;
            align-items: flex-start;
            padding: 15px 0;
            border-bottom: 1px solid #e9ecef;
        }
        
        .notification-item:last-child {
            border-bottom: none;
        }
        
        .notification-icon {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, rgba(102, 126, 234, 0.1), rgba(118, 75, 162, 0.1));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            color: var(--primary-color);
        }
        
        .notification-content {
            flex: 1;
        }
        
        .notification-title {
            font-weight: 600;
            color: var(--dark-color);
            margin-bottom: 5px;
        }
        
        .notification-text {
            font-size: 0.9rem;
            color: #6c757d;
            margin-bottom: 5px;
        }
        
        .notification-date {
            font-size: 0.8rem;
            color: #adb5bd;
        }
        
        .notification-badge {
            background: var(--danger-color);
            color: white;
            padding: 2px 8px;
            border-radius: 50px;
            font-size: 0.7rem;
            margin-left: 10px;
        }
        
        /* Beneficiary Card */
        .beneficiary-card {
            background: linear-gradient(135deg, #28a745, #20c997);
            color: white;
            padding: 20px;
            border-radius: 10px;
            margin-bottom: 20px;
        }
        
        .beneficiary-name {
            font-size: 1.3rem;
            font-weight: 700;
            margin-bottom: 5px;
        }
        
        .beneficiary-detail {
            font-size: 0.95rem;
            opacity: 0.9;
            margin-bottom: 3px;
        }
        
        .beneficiary-detail i {
            width: 20px;
            margin-right: 5px;
        }
        
        .update-beneficiary {
            background: rgba(255,255,255,0.2);
            color: white;
            border: none;
            padding: 8px 15px;
            border-radius: 50px;
            font-size: 0.9rem;
            margin-top: 15px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .update-beneficiary:hover {
            background: white;
            color: var(--success-color);
        }
        
        /* Council List */
        .council-item {
            display: flex;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #e9ecef;
        }
        
        .council-item:last-child {
            border-bottom: none;
        }
        
        .council-avatar {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            margin-right: 15px;
        }
        
        .council-info {
            flex: 1;
        }
        
        .council-name {
            font-weight: 600;
            color: var(--dark-color);
        }
        
        .council-position {
            font-size: 0.85rem;
            color: #6c757d;
        }
        
        .council-contact {
            font-size: 0.85rem;
            color: var(--primary-color);
            text-decoration: none;
        }
        
        /* Events Calendar */
        .event-item {
            display: flex;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #e9ecef;
        }
        
        .event-date {
            text-align: center;
            min-width: 50px;
            margin-right: 15px;
        }
        
        .event-day {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-color);
            line-height: 1;
        }
        
        .event-month {
            font-size: 0.8rem;
            color: #6c757d;
            text-transform: uppercase;
        }
        
        .event-details {
            flex: 1;
        }
        
        .event-title {
            font-weight: 600;
            color: var(--dark-color);
            margin-bottom: 3px;
        }
        
        .event-time {
            font-size: 0.85rem;
            color: #6c757d;
        }
        
        .event-type {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 50px;
            font-size: 0.7rem;
            margin-left: 10px;
        }
        
        .type-meeting {
            background: #cce5ff;
            color: #004085;
        }
        
        .type-event {
            background: #d4edda;
            color: #155724;
        }
        
        .type-payment {
            background: #fff3cd;
            color: #856404;
        }
        
        /* Support Section */
        .support-item {
            padding: 15px;
            background: var(--light-color);
            border-radius: 10px;
            margin-bottom: 10px;
        }
        
        .support-title {
            font-weight: 600;
            color: var(--dark-color);
            margin-bottom: 5px;
        }
        
        .support-text {
            font-size: 0.9rem;
            color: #6c757d;
        }
        
        .support-link {
            color: var(--primary-color);
            text-decoration: none;
            font-size: 0.9rem;
            font-weight: 500;
        }
        
        /* Modals */
        .modal-content {
            border: none;
            border-radius: 15px;
        }
        
        .modal-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            border-radius: 15px 15px 0 0;
            padding: 20px;
        }
        
        .modal-header .btn-close {
            filter: brightness(0) invert(1);
        }
        
        .modal-body {
            padding: 30px;
        }
        
        .modal-footer {
            padding: 20px;
            border-top: 1px solid #e9ecef;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .sidebar {
                position: fixed;
                left: -100%;
                top: 70px;
                width: 250px;
                height: calc(100vh - 70px);
                z-index: 999;
                transition: left 0.3s ease;
            }
            
            .sidebar.show {
                left: 0;
            }
            
            .welcome-banner h2 {
                font-size: 1.5rem;
            }
            
            .welcome-banner .date {
                position: static;
                display: inline-block;
                margin-top: 10px;
            }
            
            .quick-actions {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <!-- Top Navigation -->
    <nav class="top-navbar d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center">
            <button class="btn btn-link d-md-none me-3" id="sidebarToggle">
                <i class="fas fa-bars" style="color: var(--primary-color); font-size: 1.5rem;"></i>
            </button>
            <a href="Userpage.php" class="brand">
                <i class="fas fa-hand-holding-heart"></i> Harana
            </a>
        </div>
        
        <div class="dropdown">
            <div class="user-dropdown" data-bs-toggle="dropdown">
                <div class="user-avatar">
                    <?php 
                    $initials = '';
                    if (!empty($member['first_name']) && !empty($member['last_name'])) {
                        $initials = strtoupper(substr($member['first_name'], 0, 1) . substr($member['last_name'], 0, 1));
                    } else {
                        $initials = 'U';
                    }
                    echo $initials;
                    ?>
                </div>
                <div class="user-info d-none d-md-block">
                    <div class="user-name"><?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?></div>
                    <div class="user-role"><?php echo ucfirst($current_user['role'] ?? 'Member'); ?></div>
                </div>
                <i class="fas fa-chevron-down ms-2"></i>
            </div>
            <ul class="dropdown-menu dropdown-menu-end">
                <li><a class="dropdown-item" href="#profile" data-bs-toggle="modal" data-bs-target="#profileModal"><i class="fas fa-user me-2"></i>My Profile</a></li>
                <li><a class="dropdown-item" href="settings.php"><i class="fas fa-cog me-2"></i>Settings</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item" href="logout.php"><i class="fas fa-sign-out-alt me-2"></i>Logout</a></li>
            </ul>
        </div>
    </nav>

    <div class="d-flex">
        <!-- Sidebar -->
        <div class="sidebar" id="sidebar">
            <ul class="sidebar-menu">
                <li class="sidebar-item">
                    <a href="#dashboard" class="sidebar-link active" onclick="showSection('dashboard')">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                <li class="sidebar-item">
                    <a href="#profile" class="sidebar-link" onclick="showSection('profile')">
                        <i class="fas fa-user"></i>
                        <span>My Profile</span>
                    </a>
                </li>
                <li class="sidebar-item">
                    <a href="#payments" class="sidebar-link" onclick="showSection('payments')">
                        <i class="fas fa-credit-card"></i>
                        <span>Payment History</span>
                        <?php if (count($all_payments) > 0): ?>
                            <span class="badge bg-primary"><?php echo count($all_payments); ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="sidebar-item">
                    <a href="#beneficiary" class="sidebar-link" onclick="showSection('beneficiary')">
                        <i class="fas fa-heart"></i>
                        <span>Beneficiary</span>
                    </a>
                </li>
                <li class="sidebar-item">
                    <a href="#notifications" class="sidebar-link" onclick="showSection('notifications')">
                        <i class="fas fa-bell"></i>
                        <span>Notifications</span>
                        <?php if (count($upcoming_events) > 0): ?>
                            <span class="badge bg-danger"><?php echo count($upcoming_events); ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                <li class="sidebar-item">
                    <a href="#organization" class="sidebar-link" onclick="showSection('organization')">
                        <i class="fas fa-building"></i>
                        <span>Organization</span>
                    </a>
                </li>
                <li class="sidebar-item">
                    <a href="#support" class="sidebar-link" onclick="showSection('support')">
                        <i class="fas fa-life-ring"></i>
                        <span>Support</span>
                    </a>
                </li>
                <?php if ($is_admin): ?>
                <li class="sidebar-item mt-3">
                    <a href="admin/dashboard.php" class="sidebar-link" style="border-left-color: var(--success-color);">
                        <i class="fas fa-crown" style="color: var(--success-color);"></i>
                        <span>Admin Panel</span>
                    </a>
                </li>
                <?php endif; ?>
            </ul>
        </div>

        <!-- Main Content -->
        <div class="main-content flex-grow-1" id="mainContent">
            <!-- Dashboard Section -->
            <div id="dashboard-section">
                <!-- Welcome Banner -->
                <div class="welcome-banner" data-aos="fade-up">
                    <div class="date">
                        <i class="fas fa-calendar-alt me-2"></i><?php echo date('F j, Y'); ?>
                    </div>
                    <h2>Welcome back, <?php echo htmlspecialchars($member['first_name']); ?>! 👋</h2>
                    <p>Here's what's happening with your membership today.</p>
                </div>

                <!-- Profile Summary Row -->
                <div class="row">
                    <div class="col-lg-4" data-aos="fade-up" data-aos-delay="100">
                        <div class="dashboard-card">
                            <div class="card-header">
                                <h5><i class="fas fa-user-circle me-2"></i>My Profile</h5>
                                <i class="fas fa-chevron-right"></i>
                            </div>
                            <div class="profile-card">
                                <div class="profile-avatar">
                                    <?php echo $initials; ?>
                                </div>
                                <div class="profile-name">
                                    <?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?>
                                </div>
                                <div class="profile-code">
                                    <i class="fas fa-id-card me-1"></i><?php echo htmlspecialchars($member['member_code']); ?>
                                </div>
                                
                                <div class="profile-info">
                                    <div class="info-item">
                                        <div class="info-icon">
                                            <i class="fas fa-users"></i>
                                        </div>
                                        <div>
                                            <div class="info-label">Chapter/Group</div>
                                            <div class="info-value">
                                                <?php 
                                                echo htmlspecialchars($member['chapter'] ?? 'Not assigned');
                                                if (!empty($member['group_name'])) {
                                                    echo ' - ' . htmlspecialchars($member['group_name']);
                                                }
                                                ?>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="info-item">
                                        <div class="info-icon">
                                            <i class="fas fa-calendar-alt"></i>
                                        </div>
                                        <div>
                                            <div class="info-label">Member Since</div>
                                            <div class="info-value">
                                                <?php 
                                                if (!empty($member['date_joined']) && $member['date_joined'] != '0000-00-00') {
                                                    echo date('F j, Y', strtotime($member['date_joined']));
                                                } else {
                                                    echo 'N/A';
                                                }
                                                ?>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div class="info-item">
                                        <div class="info-icon">
                                            <i class="fas fa-check-circle"></i>
                                        </div>
                                        <div>
                                            <div class="info-label">Membership Status</div>
                                            <div class="info-value">
                                                <span class="badge bg-<?php echo ($member['status'] ?? 'active') == 'active' ? 'success' : 'warning'; ?>">
                                                    <?php echo ucfirst($member['status'] ?? 'active'); ?>
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <button class="btn btn-primary-custom w-100 mt-3" onclick="showSection('profile')">
                                    <i class="fas fa-edit me-2"></i>View Full Profile
                                </button>
                            </div>
                        </div>
                        
                        <!-- Quick Actions -->
                        <div class="dashboard-card" data-aos="fade-up" data-aos-delay="200">
                            <div class="card-header" style="background: linear-gradient(135deg, #28a745, #20c997);">
                                <h5><i class="fas fa-bolt me-2"></i>Quick Actions</h5>
                                <i class="fas fa-rocket"></i>
                            </div>
                            <div class="card-body">
                                <div class="quick-actions">
                                    <a href="#" class="quick-action" data-bs-toggle="modal" data-bs-target="#paymentHistoryModal">
                                        <i class="fas fa-history"></i>
                                        <span>View Payments</span>
                                    </a>
                                    <a href="#" class="quick-action" data-bs-toggle="modal" data-bs-target="#receiptsModal">
                                        <i class="fas fa-download"></i>
                                        <span>Download Receipts</span>
                                    </a>
                                    <a href="#" class="quick-action" onclick="window.print()">
                                        <i class="fas fa-print"></i>
                                        <span>Print Certificate</span>
                                    </a>
                                    <a href="#" class="quick-action" data-bs-toggle="modal" data-bs-target="#updateProfileModal">
                                        <i class="fas fa-user-edit"></i>
                                        <span>Update Profile</span>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-lg-8" data-aos="fade-up" data-aos-delay="300">
                        <!-- Financial Summary -->
                        <div class="dashboard-card">
                            <div class="card-header" style="background: linear-gradient(135deg, #ff6b6b, #ee5a24);">
                                <h5><i class="fas fa-chart-line me-2"></i>Financial Summary</h5>
                                <i class="fas fa-wallet"></i>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="balance-card">
                                            <div class="balance-label">Current Balance</div>
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
                                    </div>
                                    <div class="col-md-6">
                                        <div class="stat-grid">
                                            <div class="stat-item">
                                                <div class="stat-value">₱<?php echo number_format($member['monthly_contribution'] ?? 100, 2); ?></div>
                                                <div class="stat-label">Monthly Contribution</div>
                                            </div>
                                            <div class="stat-item">
                                                <div class="stat-value">₱<?php echo number_format($total_paid, 2); ?></div>
                                                <div class="stat-label">Total Paid</div>
                                            </div>
                                            <div class="stat-item">
                                                <div class="stat-value">₱<?php echo number_format($expected_total, 2); ?></div>
                                                <div class="stat-label">Expected Total</div>
                                            </div>
                                            <div class="stat-item">
                                                <div class="stat-value"><?php echo $months_as_member; ?></div>
                                                <div class="stat-label">Months as Member</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                
                                <!-- Recent Payments -->
                                <h6 class="mt-4 mb-3 fw-bold"><i class="fas fa-clock me-2 text-primary"></i>Recent Payments</h6>
                                
                                <?php if (empty($payments)): ?>
                                    <p class="text-muted text-center py-3">No payment history yet.</p>
                                <?php else: ?>
                                    <?php foreach ($payments as $payment): ?>
                                    <div class="payment-item">
                                        <div>
                                            <div class="payment-date"><?php echo date('M d, Y', strtotime($payment['payment_date'])); ?></div>
                                            <small class="text-muted"><?php echo $payment['receipt_number'] ?? 'No receipt'; ?></small>
                                        </div>
                                        <div class="text-end">
                                            <div class="payment-amount">₱<?php echo number_format($payment['amount'], 2); ?></div>
                                            <span class="payment-status status-<?php echo $payment['payment_status']; ?>">
                                                <?php echo ucfirst($payment['payment_status']); ?>
                                            </span>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                
                                <div class="text-center mt-3">
                                    <a href="#" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#paymentHistoryModal">
                                        <i class="fas fa-list me-2"></i>View All Payments
                                    </a>
                                </div>
                                
                                <!-- Next Due Date -->
                                <div class="alert alert-warning mt-4 mb-0">
                                    <i class="fas fa-calendar-exclamation me-2"></i>
                                    <strong>Next Due Date:</strong> <?php echo date('F j, Y', strtotime($next_due_date)); ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Notifications & Announcements -->
                        <div class="dashboard-card mt-4" data-aos="fade-up" data-aos-delay="400">
                            <div class="card-header" style="background: linear-gradient(135deg, #17a2b8, #138496);">
                                <h5><i class="fas fa-bell me-2"></i>Notifications & Announcements</h5>
                                <i class="fas fa-bullhorn"></i>
                            </div>
                            <div class="card-body">
                                <?php if (count($birthdays_this_month) > 0): ?>
                                    <div class="alert alert-success">
                                        <i class="fas fa-birthday-cake me-2"></i>
                                        <strong>Happy Birthday!</strong> 
                                        <?php foreach ($birthdays_this_month as $bday): ?>
                                            <?php echo htmlspecialchars($bday['name']); ?> on <?php echo $bday['day']; ?>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                                
                                <?php foreach (array_slice($announcements, 0, 3) as $announcement): ?>
                                <div class="notification-item">
                                    <div class="notification-icon">
                                        <i class="fas fa-<?php 
                                            echo $announcement['type'] == 'meeting' ? 'users' : 
                                                ($announcement['type'] == 'payment' ? 'credit-card' : 
                                                ($announcement['type'] == 'event' ? 'calendar-alt' : 'bullhorn')); 
                                        ?>"></i>
                                    </div>
                                    <div class="notification-content">
                                        <div class="notification-title">
                                            <?php echo htmlspecialchars($announcement['title']); ?>
                                            <?php if (strtotime($announcement['date']) > time()): ?>
                                                <span class="notification-badge">NEW</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="notification-text">
                                            <?php echo htmlspecialchars($announcement['content']); ?>
                                        </div>
                                        <div class="notification-date">
                                            <i class="fas fa-clock me-1"></i><?php echo date('M d, Y', strtotime($announcement['date'])); ?>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                                
                                <div class="text-center mt-3">
                                    <a href="#" class="btn btn-outline-info" onclick="showSection('notifications')">
                                        <i class="fas fa-bell me-2"></i>View All Notifications
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Profile Section (Hidden by default) -->
            <div id="profile-section" style="display: none;">
                <div class="dashboard-card">
                    <div class="card-header">
                        <h5><i class="fas fa-user-circle me-2"></i>Complete Profile</h5>
                        <button class="btn btn-sm btn-light" onclick="showSection('dashboard')">
                            <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
                        </button>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-4 text-center mb-4">
                                <div class="profile-avatar" style="width: 150px; height: 150px; font-size: 4rem;">
                                    <?php echo $initials; ?>
                                </div>
                                <h4 class="mt-3"><?php echo htmlspecialchars($member['first_name'] . ' ' . $member['last_name']); ?></h4>
                                <p class="text-muted">Member Code: <?php echo htmlspecialchars($member['member_code']); ?></p>
                                <span class="badge bg-success"><?php echo ucfirst($member['status'] ?? 'active'); ?></span>
                            </div>
                            <div class="col-md-8">
                                <div class="row">
                                    <div class="col-md-6 mb-3">
                                        <label class="fw-bold">First Name</label>
                                        <p><?php echo htmlspecialchars($member['first_name'] ?? 'N/A'); ?></p>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="fw-bold">Last Name</label>
                                        <p><?php echo htmlspecialchars($member['last_name'] ?? 'N/A'); ?></p>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="fw-bold">Middle Name</label>
                                        <p><?php echo htmlspecialchars($member['middle_name'] ?? 'N/A'); ?></p>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="fw-bold">Gender</label>
                                        <p><?php echo htmlspecialchars($member['gender'] ?? 'N/A'); ?></p>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="fw-bold">Birth Date</label>
                                        <p>
                                            <?php 
                                            if (!empty($member['birth_date']) && $member['birth_date'] != '0000-00-00') {
                                                echo date('F j, Y', strtotime($member['birth_date']));
                                            } else {
                                                echo 'N/A';
                                            }
                                            ?>
                                        </p>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="fw-bold">Age</label>
                                        <p><?php echo $member['age'] ?? 'N/A'; ?></p>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="fw-bold">Civil Status</label>
                                        <p><?php echo htmlspecialchars($member['civil_status'] ?? 'N/A'); ?></p>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="fw-bold">Religion</label>
                                        <p><?php echo htmlspecialchars($member['religion'] ?? 'N/A'); ?></p>
                                    </div>
                                    <div class="col-12 mb-3">
                                        <label class="fw-bold">Address</label>
                                        <p>
                                            <?php 
                                            $address_parts = [];
                                            if (!empty($member['barangay'])) $address_parts[] = 'Brgy. ' . $member['barangay'];
                                            if (!empty($member['city'])) $address_parts[] = $member['city'];
                                            if (!empty($member['province'])) $address_parts[] = $member['province'];
                                            echo !empty($address_parts) ? implode(', ', $address_parts) : 'N/A';
                                            ?>
                                        </p>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="fw-bold">Contact Number</label>
                                        <p><?php echo htmlspecialchars($member['contact_number'] ?? 'N/A'); ?></p>
                                    </div>
                                    <div class="col-md-6 mb-3">
                                        <label class="fw-bold">Email</label>
                                        <p><?php echo htmlspecialchars($member['email'] ?? 'N/A'); ?></p>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <hr>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <h5 class="mb-3">Family Information</h5>
                                <?php if (!empty($member['father_fname']) || !empty($member['father_lname'])): ?>
                                <p><strong>Father:</strong> <?php echo htmlspecialchars(trim(($member['father_fname'] ?? '') . ' ' . ($member['father_mname'] ?? '') . ' ' . ($member['father_lname'] ?? ''))); ?></p>
                                <?php endif; ?>
                                
                                <?php if (!empty($member['mother_fname']) || !empty($member['mother_lname'])): ?>
                                <p><strong>Mother:</strong> <?php echo htmlspecialchars(trim(($member['mother_fname'] ?? '') . ' ' . ($member['mother_mname'] ?? '') . ' ' . ($member['mother_lname'] ?? ''))); ?></p>
                                <?php endif; ?>
                                
                                <?php if (!empty($member['spouse_fname']) || !empty($member['spouse_lname'])): ?>
                                <p><strong>Spouse:</strong> <?php echo htmlspecialchars(trim(($member['spouse_fname'] ?? '') . ' ' . ($member['spouse_mname'] ?? '') . ' ' . ($member['spouse_lname'] ?? ''))); ?></p>
                                <?php endif; ?>
                            </div>
                            
                            <div class="col-md-6">
                                <h5 class="mb-3">Chapter Information</h5>
                                <p><strong>Chapter:</strong> <?php echo htmlspecialchars($member['chapter'] ?? 'N/A'); ?></p>
                                <p><strong>Group:</strong> <?php echo htmlspecialchars($member['group_name'] ?? 'N/A'); ?></p>
                                <p><strong>Leader:</strong> <?php echo htmlspecialchars($member['leader'] ?? 'N/A'); ?></p>
                                <p><strong>Coordinator:</strong> <?php echo htmlspecialchars($member['coordinator'] ?? 'N/A'); ?></p>
                            </div>
                        </div>
                        
                        <div class="text-center mt-4">
                            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#updateProfileModal">
                                <i class="fas fa-edit me-2"></i>Update Profile
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Payments Section (Hidden by default) -->
            <div id="payments-section" style="display: none;">
                <div class="dashboard-card">
                    <div class="card-header" style="background: linear-gradient(135deg, #28a745, #20c997);">
                        <h5><i class="fas fa-credit-card me-2"></i>Payment History</h5>
                        <button class="btn btn-sm btn-light" onclick="showSection('dashboard')">
                            <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
                        </button>
                    </div>
                    <div class="card-body">
                        <?php if (empty($all_payments)): ?>
                            <p class="text-muted text-center py-5">No payment history found.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Date</th>
                                            <th>Receipt #</th>
                                            <th>Amount</th>
                                            <th>Method</th>
                                            <th>Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($all_payments as $payment): ?>
                                        <tr>
                                            <td><?php echo date('M d, Y', strtotime($payment['payment_date'])); ?></td>
                                            <td><?php echo htmlspecialchars($payment['receipt_number'] ?? 'N/A'); ?></td>
                                            <td class="fw-bold text-success">₱<?php echo number_format($payment['amount'], 2); ?></td>
                                            <td><?php echo ucfirst($payment['payment_method'] ?? 'cash'); ?></td>
                                            <td>
                                                <span class="badge bg-<?php 
                                                    echo $payment['payment_status'] == 'confirmed' ? 'success' : 
                                                        ($payment['payment_status'] == 'pending' ? 'warning' : 'danger'); 
                                                ?>">
                                                    <?php echo ucfirst($payment['payment_status']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <a href="#" class="btn btn-sm btn-outline-primary" onclick="downloadReceipt('<?php echo $payment['receipt_number']; ?>')">
                                                    <i class="fas fa-download"></i>
                                                </a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                        
                        <div class="text-center mt-4">
                            <button class="btn btn-success" onclick="downloadAllReceipts()">
                                <i class="fas fa-download me-2"></i>Download All Receipts
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Beneficiary Section (Hidden by default) -->
            <div id="beneficiary-section" style="display: none;">
                <div class="dashboard-card">
                    <div class="card-header" style="background: linear-gradient(135deg, #dc3545, #c82333);">
                        <h5><i class="fas fa-heart me-2"></i>Beneficiary Information</h5>
                        <button class="btn btn-sm btn-light" onclick="showSection('dashboard')">
                            <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
                        </button>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6 offset-md-3">
                                <div class="beneficiary-card text-center">
                                    <i class="fas fa-heart fa-4x mb-3"></i>
                                    <div class="beneficiary-name">
                                        <?php echo htmlspecialchars($member['beneficiary_name'] ?? 'No beneficiary set'); ?>
                                    </div>
                                    <?php if (!empty($member['beneficiary_relation'])): ?>
                                    <div class="beneficiary-detail">
                                        <i class="fas fa-users"></i> <?php echo htmlspecialchars($member['beneficiary_relation']); ?>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (!empty($member['beneficiary_age'])): ?>
                                    <div class="beneficiary-detail">
                                        <i class="fas fa-birthday-cake"></i> Age: <?php echo htmlspecialchars($member['beneficiary_age']); ?>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (!empty($member['beneficiary_address'])): ?>
                                    <div class="beneficiary-detail">
                                        <i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($member['beneficiary_address']); ?>
                                    </div>
                                    <?php endif; ?>
                                    <?php if (!empty($member['beneficiary_contact'])): ?>
                                    <div class="beneficiary-detail">
                                        <i class="fas fa-phone-alt"></i> <?php echo htmlspecialchars($member['beneficiary_contact']); ?>
                                    </div>
                                    <?php endif; ?>
                                    
                                    <button class="update-beneficiary" data-bs-toggle="modal" data-bs-target="#updateBeneficiaryModal">
                                        <i class="fas fa-edit me-2"></i>Update Beneficiary
                                    </button>
                                </div>
                                
                                <div class="alert alert-info mt-4">
                                    <i class="fas fa-info-circle me-2"></i>
                                    <strong>Note:</strong> Updating your beneficiary requires admin approval. Changes will be reviewed within 3-5 business days.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Notifications Section (Hidden by default) -->
            <div id="notifications-section" style="display: none;">
                <div class="dashboard-card">
                    <div class="card-header" style="background: linear-gradient(135deg, #17a2b8, #138496);">
                        <h5><i class="fas fa-bell me-2"></i>All Notifications & Announcements</h5>
                        <button class="btn btn-sm btn-light" onclick="showSection('dashboard')">
                            <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
                        </button>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-8">
                                <h5 class="mb-3">Announcements</h5>
                                <?php foreach ($announcements as $announcement): ?>
                                <div class="notification-item">
                                    <div class="notification-icon">
                                        <i class="fas fa-<?php 
                                            echo $announcement['type'] == 'meeting' ? 'users' : 
                                                ($announcement['type'] == 'payment' ? 'credit-card' : 
                                                ($announcement['type'] == 'event' ? 'calendar-alt' : 'bullhorn')); 
                                        ?>"></i>
                                    </div>
                                    <div class="notification-content">
                                        <div class="notification-title">
                                            <?php echo htmlspecialchars($announcement['title']); ?>
                                            <?php if (strtotime($announcement['date']) > time()): ?>
                                                <span class="notification-badge">NEW</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="notification-text">
                                            <?php echo htmlspecialchars($announcement['content']); ?>
                                        </div>
                                        <div class="notification-date">
                                            <i class="fas fa-clock me-1"></i><?php echo date('F j, Y', strtotime($announcement['date'])); ?>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            
                            <div class="col-md-4">
                                <div class="dashboard-card">
                                    <div class="card-header" style="background: linear-gradient(135deg, #ff6b6b, #ee5a24);">
                                        <h5><i class="fas fa-calendar-alt me-2"></i>Upcoming Events</h5>
                                    </div>
                                    <div class="card-body">
                                        <?php 
                                        $upcoming = array_filter($announcements, function($a) {
                                            return strtotime($a['date']) > time();
                                        });
                                        ?>
                                        
                                        <?php if (empty($upcoming)): ?>
                                            <p class="text-muted text-center">No upcoming events</p>
                                        <?php else: ?>
                                            <?php foreach ($upcoming as $event): ?>
                                            <div class="event-item">
                                                <div class="event-date">
                                                    <div class="event-day"><?php echo date('d', strtotime($event['date'])); ?></div>
                                                    <div class="event-month"><?php echo date('M', strtotime($event['date'])); ?></div>
                                                </div>
                                                <div class="event-details">
                                                    <div class="event-title"><?php echo htmlspecialchars($event['title']); ?></div>
                                                    <div class="event-time">
                                                        <i class="fas fa-clock me-1"></i>All day
                                                    </div>
                                                </div>
                                                <span class="event-type type-<?php echo $event['type']; ?>">
                                                    <?php echo ucfirst($event['type']); ?>
                                                </span>
                                            </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <div class="dashboard-card mt-3">
                                    <div class="card-header" style="background: linear-gradient(135deg, #28a745, #20c997);">
                                        <h5><i class="fas fa-birthday-cake me-2"></i>Birthdays This Month</h5>
                                    </div>
                                    <div class="card-body">
                                        <?php if (empty($birthdays_this_month)): ?>
                                            <p class="text-muted text-center">No birthdays this month</p>
                                        <?php else: ?>
                                            <?php foreach ($birthdays_this_month as $bday): ?>
                                            <div class="d-flex align-items-center mb-2">
                                                <div class="council-avatar" style="background: linear-gradient(135deg, #ff6b6b, #ee5a24);">
                                                    <i class="fas fa-birthday-cake"></i>
                                                </div>
                                                <div>
                                                    <div class="fw-bold"><?php echo htmlspecialchars($bday['name']); ?></div>
                                                    <small class="text-muted">Celebrating on <?php echo $bday['day']; ?></small>
                                                </div>
                                            </div>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Organization Section (Hidden by default) -->
            <div id="organization-section" style="display: none;">
                <div class="dashboard-card">
                    <div class="card-header" style="background: linear-gradient(135deg, #6f42c1, #6610f2);">
                        <h5><i class="fas fa-building me-2"></i>Organization Information</h5>
                        <button class="btn btn-sm btn-light" onclick="showSection('dashboard')">
                            <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
                        </button>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h4 class="mb-3">About NHGL, Inc.</h4>
                                <p><strong>NAGKAISANG HIRANISTA SA GINTONG LUZON, PHILS. INC. (NHGL, INC.)</strong></p>
                                <p><small>(Formerly Nagkaisang Hiranista Sa Gintong Luzon, Inc.)</small></p>
                                <p><small>Sec. REG No. CN 700172104</small></p>
                                
                                <p class="mt-3">We are a community-based financial assistance organization dedicated to providing our members with financial security, support, and opportunities for growth.</p>
                                
                                <h5 class="mt-4">Our Mission</h5>
                                <p>To provide accessible financial assistance and support to our members, fostering a community of mutual help and financial wellness.</p>
                                
                                <h5 class="mt-3">Our Vision</h5>
                                <p>A community where every member has access to financial security and opportunities for growth.</p>
                            </div>
                            
                            <div class="col-md-6">
                                <h5 class="mb-3">Council Members</h5>
                                <?php if (empty($council_members)): ?>
                                    <p class="text-muted">No council members listed.</p>
                                <?php else: ?>
                                    <?php foreach ($council_members as $council): ?>
                                    <div class="council-item">
                                        <div class="council-avatar">
                                            <?php 
                                            $council_initials = '';
                                            $name_parts = explode(' ', $council['full_name'] ?? '');
                                            foreach ($name_parts as $part) {
                                                if (!empty($part)) {
                                                    $council_initials .= strtoupper(substr($part, 0, 1));
                                                }
                                            }
                                            echo $council_initials ?: 'CM';
                                            ?>
                                        </div>
                                        <div class="council-info">
                                            <div class="council-name"><?php echo htmlspecialchars($council['full_name'] ?? 'Council Member'); ?></div>
                                            <div class="council-position"><?php echo htmlspecialchars($council['position'] ?? ''); ?></div>
                                            <?php if (!empty($council['contact_number'])): ?>
                                            <a href="tel:<?php echo $council['contact_number']; ?>" class="council-contact">
                                                <i class="fas fa-phone-alt"></i> <?php echo htmlspecialchars($council['contact_number']); ?>
                                            </a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                                
                                <h5 class="mt-4 mb-3">Chapter Officials</h5>
                                <?php if (empty($chapter_officials)): ?>
                                    <p class="text-muted">No chapter officials listed.</p>
                                <?php else: ?>
                                    <?php foreach ($chapter_officials as $official): ?>
                                    <div class="council-item">
                                        <div class="council-avatar">
                                            <?php 
                                            $official_initials = '';
                                            $name_parts = explode(' ', $official['full_name'] ?? '');
                                            foreach ($name_parts as $part) {
                                                if (!empty($part)) {
                                                    $official_initials .= strtoupper(substr($part, 0, 1));
                                                }
                                            }
                                            echo $official_initials ?: 'CO';
                                            ?>
                                        </div>
                                        <div class="council-info">
                                            <div class="council-name"><?php echo htmlspecialchars($official['full_name'] ?? 'Official'); ?></div>
                                            <div class="council-position"><?php echo htmlspecialchars($official['position'] ?? ''); ?></div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <hr>
                        
                        <div class="row mt-3">
                            <div class="col-md-4">
                                <h5><i class="fas fa-map-marker-alt me-2 text-primary"></i>Address</h5>
                                <p>MF 2024<br>Brgy. Singalat, Palayan City<br>Province of Nueva Ecija</p>
                            </div>
                            <div class="col-md-4">
                                <h5><i class="fas fa-phone-alt me-2 text-primary"></i>Contact</h5>
                                <p>Tel. No. (044) 940-6708<br>Email: info@harana.com</p>
                            </div>
                            <div class="col-md-4">
                                <h5><i class="fas fa-clock me-2 text-primary"></i>Office Hours</h5>
                                <p>Monday - Friday: 8:00 AM - 5:00 PM<br>Saturday: 8:00 AM - 12:00 PM</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Support Section (Hidden by default) -->
            <div id="support-section" style="display: none;">
                <div class="dashboard-card">
                    <div class="card-header" style="background: linear-gradient(135deg, #28a745, #20c997);">
                        <h5><i class="fas fa-life-ring me-2"></i>Support & Assistance</h5>
                        <button class="btn btn-sm btn-light" onclick="showSection('dashboard')">
                            <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
                        </button>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="support-item">
                                    <div class="support-title">
                                        <i class="fas fa-hand-holding-usd me-2 text-success"></i>
                                        Financial Assistance (Damayan)
                                    </div>
                                    <div class="support-text">
                                        Members can avail of financial assistance for emergencies, hospitalization, and other valid needs.
                                    </div>
                                    <a href="#" class="support-link" data-bs-toggle="modal" data-bs-target="#assistanceModal">
                                        Learn more <i class="fas fa-arrow-right ms-1"></i>
                                    </a>
                                </div>
                                
                                <div class="support-item">
                                    <div class="support-title">
                                        <i class="fas fa-heartbeat me-2 text-danger"></i>
                                        Death & Burial Benefits
                                    </div>
                                    <div class="support-text">
                                        Financial assistance for burial expenses and support for bereaved families.
                                    </div>
                                    <a href="#" class="support-link" data-bs-toggle="modal" data-bs-target="#burialModal">
                                        Learn more <i class="fas fa-arrow-right ms-1"></i>
                                    </a>
                                </div>
                                
                                <div class="support-item">
                                    <div class="support-title">
                                        <i class="fas fa-graduation-cap me-2 text-primary"></i>
                                        Educational Support
                                    </div>
                                    <div class="support-text">
                                        Scholarship and educational assistance programs for members' children.
                                    </div>
                                    <a href="#" class="support-link" data-bs-toggle="modal" data-bs-target="#educationModal">
                                        Learn more <i class="fas fa-arrow-right ms-1"></i>
                                    </a>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="support-item">
                                    <div class="support-title">
                                        <i class="fas fa-file-alt me-2 text-warning"></i>
                                        Requirements for Claims
                                    </div>
                                    <ul class="support-text mt-2">
                                        <li>Valid ID of claimant</li>
                                        <li>Member's certificate of membership</li>
                                        <li>Supporting documents (medical certificate, death certificate, etc.)</li>
                                        <li>Claim form (available at the office)</li>
                                    </ul>
                                </div>
                                
                                <div class="support-item">
                                    <div class="support-title">
                                        <i class="fas fa-clock me-2 text-info"></i>
                                        Processing Time
                                    </div>
                                    <div class="support-text">
                                        <p>Regular claims: 3-5 business days<br>
                                        Emergency claims: 24-48 hours</p>
                                    </div>
                                </div>
                                
                                <div class="support-item">
                                    <div class="support-title">
                                        <i class="fas fa-question-circle me-2 text-secondary"></i>
                                        Need Help?
                                    </div>
                                    <div class="support-text">
                                        Contact our support team:<br>
                                        <i class="fas fa-phone-alt me-1"></i> (044) 940-6708<br>
                                        <i class="fas fa-envelope me-1"></i> support@harana.com
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="text-center mt-4">
                            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#assistanceRequestModal">
                                <i class="fas fa-paper-plane me-2"></i>Request Assistance
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Payment History Modal -->
    <div class="modal fade" id="paymentHistoryModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-history me-2"></i>Payment History</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?php if (empty($all_payments)): ?>
                        <p class="text-muted text-center">No payment history found.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Receipt #</th>
                                        <th>Amount</th>
                                        <th>Method</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($all_payments as $payment): ?>
                                    <tr>
                                        <td><?php echo date('M d, Y', strtotime($payment['payment_date'])); ?></td>
                                        <td><?php echo htmlspecialchars($payment['receipt_number'] ?? 'N/A'); ?></td>
                                        <td class="fw-bold text-success">₱<?php echo number_format($payment['amount'], 2); ?></td>
                                        <td><?php echo ucfirst($payment['payment_method'] ?? 'cash'); ?></td>
                                        <td>
                                            <span class="badge bg-<?php 
                                                echo $payment['payment_status'] == 'confirmed' ? 'success' : 
                                                    ($payment['payment_status'] == 'pending' ? 'warning' : 'danger'); 
                                            ?>">
                                                <?php echo ucfirst($payment['payment_status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <a href="#" class="btn btn-sm btn-outline-primary" onclick="downloadReceipt('<?php echo $payment['receipt_number']; ?>')">
                                                <i class="fas fa-download"></i>
                                            </a>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-success" onclick="downloadAllReceipts()">
                        <i class="fas fa-download me-2"></i>Download All
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Receipts Modal -->
    <div class="modal fade" id="receiptsModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-file-invoice me-2"></i>Download Receipts</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Select payment to download receipt:</p>
                    <?php if (empty($all_payments)): ?>
                        <p class="text-muted">No receipts available.</p>
                    <?php else: ?>
                        <div class="list-group">
                            <?php foreach ($all_payments as $payment): ?>
                            <a href="#" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center" onclick="downloadReceipt('<?php echo $payment['receipt_number']; ?>')">
                                <div>
                                    <i class="fas fa-file-pdf me-2 text-danger"></i>
                                    <?php echo date('M d, Y', strtotime($payment['payment_date'])); ?> - ₱<?php echo number_format($payment['amount'], 2); ?>
                                </div>
                                <i class="fas fa-download"></i>
                            </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Update Profile Modal -->
    <div class="modal fade" id="updateProfileModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-user-edit me-2"></i>Update Profile</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="update_profile.php">
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Contact Number</label>
                                <input type="text" class="form-control" name="contact_number" value="<?php echo htmlspecialchars($member['contact_number'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Alternate Number</label>
                                <input type="text" class="form-control" name="alternate_number" value="<?php echo htmlspecialchars($member['alternate_number'] ?? ''); ?>">
                            </div>
                            <div class="col-12 mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" class="form-control" name="email" value="<?php echo htmlspecialchars($member['email'] ?? ''); ?>">
                            </div>
                            <div class="col-12 mb-3">
                                <label class="form-label">Present Address</label>
                                <input type="text" class="form-control" name="present_address" value="<?php echo htmlspecialchars($member['present_address'] ?? ''); ?>">
                            </div>
                            <div class="col-12 mb-3">
                                <label class="form-label">Permanent Address</label>
                                <input type="text" class="form-control" name="permanent_address" value="<?php echo htmlspecialchars($member['permanent_address'] ?? ''); ?>">
                            </div>
                        </div>
                        
                        <div class="alert alert-warning">
                            <i class="fas fa-info-circle me-2"></i>
                            Other profile changes require admin approval. Please contact the office for changes to your name, birth date, or civil status.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Update Beneficiary Modal -->
    <div class="modal fade" id="updateBeneficiaryModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-heart me-2"></i>Update Beneficiary</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="update_beneficiary.php">
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        
                        <div class="mb-3">
                            <label class="form-label">Beneficiary Full Name</label>
                            <input type="text" class="form-control" name="beneficiary_name" value="<?php echo htmlspecialchars($member['beneficiary_name'] ?? ''); ?>" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Relationship</label>
                            <input type="text" class="form-control" name="beneficiary_relation" value="<?php echo htmlspecialchars($member['beneficiary_relation'] ?? ''); ?>" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Age</label>
                            <input type="number" class="form-control" name="beneficiary_age" value="<?php echo htmlspecialchars($member['beneficiary_age'] ?? ''); ?>">
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Complete Address</label>
                            <input type="text" class="form-control" name="beneficiary_address" value="<?php echo htmlspecialchars($member['beneficiary_address'] ?? ''); ?>" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Contact Number</label>
                            <input type="text" class="form-control" name="beneficiary_contact" value="<?php echo htmlspecialchars($member['beneficiary_contact'] ?? ''); ?>">
                        </div>
                        
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            Beneficiary updates require admin approval and will be reviewed within 3-5 business days.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Submit for Approval</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Assistance Request Modal -->
    <div class="modal fade" id="assistanceRequestModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-hand-holding-usd me-2"></i>Request Assistance</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="request_assistance.php">
                    <div class="modal-body">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        
                        <div class="mb-3">
                            <label class="form-label">Type of Assistance</label>
                            <select class="form-select" name="assistance_type" required>
                                <option value="">Select type</option>
                                <option value="emergency">Emergency Financial Assistance</option>
                                <option value="medical">Medical/Hospitalization</option>
                                <option value="educational">Educational Support</option>
                                <option value="livelihood">Livelihood Assistance</option>
                                <option value="burial">Burial Assistance</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Amount Requested (₱)</label>
                            <input type="number" class="form-control" name="amount" step="0.01" min="0" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Purpose/Reason</label>
                            <textarea class="form-control" name="purpose" rows="3" required></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Supporting Documents</label>
                            <input type="file" class="form-control" name="documents" multiple>
                            <small class="text-muted">Upload any supporting documents (medical certificate, estimate, etc.)</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Submit Request</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://unpkg.com/aos@2.3.1/dist/aos.js"></script>
    <script>
        // Initialize AOS
        AOS.init({
            duration: 1000,
            once: true,
            offset: 50
        });
        
        // Sidebar toggle for mobile
        document.getElementById('sidebarToggle')?.addEventListener('click', function() {
            document.getElementById('sidebar').classList.toggle('show');
        });
        
        // Close sidebar when clicking outside on mobile
        document.addEventListener('click', function(event) {
            const sidebar = document.getElementById('sidebar');
            const toggle = document.getElementById('sidebarToggle');
            
            if (window.innerWidth < 768 && sidebar.classList.contains('show') && 
                !sidebar.contains(event.target) && !toggle.contains(event.target)) {
                sidebar.classList.remove('show');
            }
        });
        
        // Section navigation
        function showSection(section) {
            // Hide all sections
            document.getElementById('dashboard-section').style.display = 'none';
            document.getElementById('profile-section').style.display = 'none';
            document.getElementById('payments-section').style.display = 'none';
            document.getElementById('beneficiary-section').style.display = 'none';
            document.getElementById('notifications-section').style.display = 'none';
            document.getElementById('organization-section').style.display = 'none';
            document.getElementById('support-section').style.display = 'none';
            
            // Show selected section
            document.getElementById(section + '-section').style.display = 'block';
            
            // Update active class in sidebar
            document.querySelectorAll('.sidebar-link').forEach(link => {
                link.classList.remove('active');
            });
            
            // Find and activate the clicked link
            document.querySelector(`.sidebar-link[onclick="showSection('${section}')"]`)?.classList.add('active');
            
            // Close sidebar on mobile after navigation
            if (window.innerWidth < 768) {
                document.getElementById('sidebar').classList.remove('show');
            }
            
            // Scroll to top
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }
        
        // Check for section parameter in URL
        const urlParams = new URLSearchParams(window.location.search);
        const section = urlParams.get('section');
        if (section && ['profile', 'payments', 'beneficiary', 'notifications', 'organization', 'support'].includes(section)) {
            showSection(section);
        }
        
        // Download receipt function (mock)
        function downloadReceipt(receiptNumber) {
            alert('Downloading receipt: ' + receiptNumber + '\n(In a real system, this would download the PDF receipt)');
        }
        
        // Download all receipts (mock)
        function downloadAllReceipts() {
            alert('Downloading all receipts as ZIP file\n(In a real system, this would download a ZIP file with all receipts)');
        }
        
        // Handle form submissions
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                alert('Form submitted successfully!\n(In a real system, this would save your changes)');
                
                // Close modal if open
                const modal = this.closest('.modal');
                if (modal) {
                    bootstrap.Modal.getInstance(modal).hide();
                }
            });
        });
    </script>
</body>
</html>