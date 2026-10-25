<?php
// admin/dashboard.php
require_once '../includes/config.php';  
require_once '../includes/db_connection.php';
require_once '../includes/security.php';
require_once '../includes/auth.php';
require_once 'includes/functions.php';

$auth->requireLogin();
$current_user = $auth->getCurrentUser();

$db = Database::getInstance();

// Get pending users count (for sidebar badge)
$pending_count = 0;
if ($current_user['role'] === 'admin') {
    $pending_count = $db->getSingle("SELECT COUNT(*) as cnt FROM pending_users WHERE status = 'pending'")['cnt'] ?? 0;
}

// ===== SEARCH & FILTER =====
$search = isset($_GET['search']) ? Security::sanitize($_GET['search']) : '';
$filter = isset($_GET['filter']) ? Security::sanitize($_GET['filter']) : 'all';
$per_page = isset($_GET['per_page']) ? intval($_GET['per_page']) : 25;
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$offset = ($page - 1) * $per_page;

// Allowed per_page values
$allowed_per_page = [10, 25, 50, 100];
if (!in_array($per_page, $allowed_per_page)) {
    $per_page = 25;
}

// Build WHERE clause
$where_conditions = ["m.status = 'active'"];
$params = [];
$types = '';

if (!empty($search)) {
    $where_conditions[] = "(m.first_name LIKE ? OR m.last_name LIKE ? OR m.member_code LIKE ? OR m.email LIKE ? OR m.contact_number LIKE ? OR m.chapter LIKE ?)";
    $search_param = "%$search%";
    $params = array_merge($params, [$search_param, $search_param, $search_param, $search_param, $search_param, $search_param]);
    $types .= 'ssssss';
}

$where_clause = implode(' AND ', $where_conditions);

// Get total count
$count_sql = "SELECT COUNT(*) as total FROM members m WHERE $where_clause";
$total_result = $db->getSingle($count_sql, $params, $types);
$total_members = $total_result['total'] ?? 0;
$total_pages = ceil($total_members / $per_page);

// ===== GET MEMBERS WITH PAYMENT STATUS =====
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
        m.date_joined,
        m.chapter,
        m.province,
        m.city,
        m.barangay
    FROM members m
    WHERE $where_clause
    ORDER BY m.last_name ASC
    LIMIT ? OFFSET ?
", array_merge($params, [$per_page, $offset]), $types . 'ii');

// Categorize members with safe payment status
$members_with_status = [
    'paid' => [],
    'unpaid' => [],
    'advanced' => []
];

foreach ($members_with_payment_status as $member) {
    // Get payment info with fallback
    try {
        $payment_info = getMemberPaymentStatus($member['member_code'], $db);
    } catch (Exception $e) {
        $payment_info = ['status' => 'unpaid', 'label' => 'Unpaid', 'class' => 'unpaid', 'count' => 0, 'pending' => 0];
    }
    
    // Ensure we have an array
    if (!is_array($payment_info)) {
        $payment_info = ['status' => 'unpaid', 'label' => 'Unpaid', 'class' => 'unpaid', 'count' => 0, 'pending' => 0];
    }
    
    $member['payment_status'] = $payment_info;
    
    // Add to appropriate category based on FILTER
    $status = $payment_info['status'] ?? 'unpaid';
    $members_with_status[$status][] = $member;
}

// If filter is applied, filter the results
$filtered_members = [];
if ($filter !== 'all') {
    $filtered_members = $members_with_status[$filter] ?? [];
} else {
    $filtered_members = $members_with_payment_status;
}

// Get counts for each status (for the badges)
$paid_count = count($members_with_status['paid']);
$unpaid_count = count($members_with_status['unpaid']);
$advanced_count = count($members_with_status['advanced']);

$csrf_token = Security::generateCSRFToken();

// Helper function for pagination URL
function buildPaginationUrl($page, $filter, $search, $per_page) {
    $url = '?' . http_build_query([
        'page' => $page,
        'filter' => $filter,
        'search' => $search,
        'per_page' => $per_page
    ]);
    return $url;
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
    <style>
        /* ===== RESET & BASE ===== */
        * { box-sizing: border-box; }
        body {
            background: #f0f2f5;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            overflow: hidden;
            margin: 0;
            padding: 0;
            height: 100vh;
        }
        
        /* ===== WRAPPER ===== */
        #wrapper {
            display: flex;
            width: 100%;
            height: 100vh;
            overflow: hidden;
            transition: all 0.3s ease;
        }
        
        /* ===== SIDEBAR ===== */
        #sidebar-wrapper {
            background: #375a7f;
            color: #fff;
            width: 250px;
            height: 100vh;
            overflow-y: auto;
            transition: width 0.3s ease, transform 0.3s ease;
            box-shadow: 4px 0 10px rgba(0,0,0,0.1);
            white-space: nowrap;
            flex-shrink: 0;
            position: relative;
            z-index: 1000;
        }
        
        #sidebar-wrapper.collapsed {
            width: 70px;
        }
        
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
            flex-shrink: 0;
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
        
        #sidebar-wrapper .list-group {
            padding-bottom: 20px;
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
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        #sidebar-wrapper .list-group-item i {
            width: 24px;
            text-align: center;
            font-size: 1rem;
            flex-shrink: 0;
        }
        
        #sidebar-wrapper .list-group-item:hover,
        #sidebar-wrapper .list-group-item.active {
            background: rgba(255,255,255,0.15);
            color: #fff;
            border-left: 4px solid #fff;
        }
        
        #sidebar-wrapper .list-group-item .badge-count {
            margin-left: auto;
            font-size: 0.7rem;
        }
        
        .menu-toggle {
            background: transparent;
            border: none;
            font-size: 1.5rem;
            color: white;
            cursor: pointer;
            padding: 0 10px;
        }
        .menu-toggle:hover { color: #fff; transform: scale(1.1); }
        
        /* ===== PAGE CONTENT ===== */
        #page-content-wrapper {
            flex: 1;
            background: #f0f2f5;
            height: 100vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        
        /* ===== NAVBAR ===== */
        .navbar {
            background: #fff !important;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
            padding: 0.5rem 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
            flex-wrap: wrap;
            gap: 10px;
            min-height: 50px;
        }
        
        .navbar-left {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .navbar-brand {
            font-size: 1.1rem;
            font-weight: 600;
            color: #375a7f !important;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .navbar-right {
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }
        
        .header-logo {
            height: 28px;
            width: auto;
            margin-right: 8px;
            vertical-align: middle;
            display: none;
        }
        
        #sidebar-wrapper.collapsed ~ #page-content-wrapper .header-logo {
            display: inline-block;
        }
        
        /* ===== CONTENT ===== */
        .content-container {
            padding: 12px 20px 20px 20px;
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            min-height: 0;
        }
        
        /* ===== TOOLBAR ===== */
        .toolbar {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-bottom: 12px;
            align-items: center;
            background: white;
            padding: 10px 16px;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            flex-shrink: 0;
        }
        
        .toolbar .search-box {
            flex: 1;
            min-width: 180px;
            max-width: 380px;
            position: relative;
        }
        
        .toolbar .search-box input {
            width: 100%;
            padding: 6px 12px 6px 35px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            font-size: 0.85rem;
            transition: all 0.3s;
            background: #f8f9fa;
            height: 36px;
        }
        
        .toolbar .search-box input:focus {
            border-color: #375a7f;
            outline: none;
            background: white;
            box-shadow: 0 0 0 3px rgba(55, 90, 127, 0.1);
        }
        
        .toolbar .search-box i {
            position: absolute;
            left: 12px;
            top: 50%;
            transform: translateY(-50%);
            color: #6c757d;
            font-size: 0.85rem;
        }
        
        .toolbar .toolbar-actions {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            margin-left: auto;
        }
        
        .toolbar .toolbar-actions .btn {
            font-size: 0.8rem;
            padding: 6px 14px;
            border-radius: 8px;
            height: 36px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .toolbar .per-page-selector {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 0.8rem;
            color: #6c757d;
            height: 36px;
        }
        
        .toolbar .per-page-selector select {
            padding: 4px 8px;
            border: 2px solid #e9ecef;
            border-radius: 6px;
            background: #f8f9fa;
            font-size: 0.8rem;
            height: 32px;
        }
        
        /* ===== FILTER TABS ===== */
        .members-filter-tabs {
            display: flex;
            gap: 0;
            margin-bottom: 12px;
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            flex-wrap: wrap;
            flex-shrink: 0;
        }
        
        .members-filter-tabs .filter-tab {
            padding: 8px 20px;
            border: none;
            background: none;
            font-weight: 500;
            font-size: 0.8rem;
            cursor: pointer;
            transition: all 0.2s;
            color: #6c757d;
            flex: 1;
            text-align: center;
            border-bottom: 3px solid transparent;
            min-width: 70px;
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
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.7rem;
            margin-left: 4px;
        }
        
        .members-filter-tabs .filter-tab.active .count-badge {
            background: #375a7f;
            color: white;
        }
        
        .members-filter-tabs .filter-tab .count-badge.paid-badge { background: #d4edda; color: #155724; }
        .members-filter-tabs .filter-tab .count-badge.unpaid-badge { background: #f8d7da; color: #721c24; }
        .members-filter-tabs .filter-tab .count-badge.advanced-badge { background: #d1ecf1; color: #0c5460; }
        
        /* ===== MEMBER TABLE CONTAINER ===== */
        .member-list-container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            display: flex;
            flex-direction: column;
            flex: 1;
            min-height: 0;
            overflow: hidden;
        }
        
        /* ===== TABLE WRAPPER - ONLY THIS SCROLLS ===== */
        .table-scroll-wrapper {
            flex: 1;
            overflow-y: auto;
            overflow-x: auto;
            padding: 0;
            min-height: 0;
        }
        
        .table-scroll-wrapper::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }
        
        .table-scroll-wrapper::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }
        
        .table-scroll-wrapper::-webkit-scrollbar-thumb {
            background: #c1c7cd;
            border-radius: 4px;
        }
        
        .table-scroll-wrapper::-webkit-scrollbar-thumb:hover {
            background: #a0a7ad;
        }
        
        .table-responsive {
            overflow: visible;
            min-height: 100%;
        }
        
        .member-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 600px;
        }
        
        .member-table th {
            background: #f8f9fa;
            padding: 10px 14px;
            text-align: left;
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6c757d;
            font-weight: 600;
            border-bottom: 2px solid #e9ecef;
            white-space: nowrap;
            position: sticky;
            top: 0;
            z-index: 10;
        }
        
        .member-table td {
            padding: 10px 14px;
            border-bottom: 1px solid #e9ecef;
            font-size: 0.82rem;
            color: #2c3e50;
            vertical-align: middle;
        }
        
        .member-table tr:hover td {
            background: #f8f9fa;
        }
        
        /* ===== STATUS BADGES ===== */
        .status-badge {
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.68rem;
            font-weight: 600;
            display: inline-block;
            white-space: nowrap;
        }
        
        .status-badge.paid { background: #d4edda; color: #155724; }
        .status-badge.unpaid { background: #f8d7da; color: #721c24; }
        .status-badge.advanced { background: #d1ecf1; color: #0c5460; }
        .status-badge.inactive { background: #e2e3e5; color: #383d41; }
        .status-badge.active { background: #d4edda; color: #155724; }
        .status-badge.pending { background: #fff3cd; color: #856404; }
        
        .status-badge small {
            font-size: 0.55rem;
            opacity: 0.8;
        }
        
        /* ===== PAGINATION ===== */
        .pagination-wrapper {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 16px;
            background: #f8f9fa;
            border-top: 1px solid #e9ecef;
            flex-wrap: wrap;
            gap: 8px;
            flex-shrink: 0;
        }
        
        .pagination-wrapper .info {
            font-size: 0.8rem;
            color: #6c757d;
        }
        
        .pagination-wrapper .pagination {
            margin: 0;
            gap: 2px;
        }
        
        .pagination-wrapper .pagination .page-link {
            padding: 4px 10px;
            font-size: 0.8rem;
            border-radius: 4px;
            border: 1px solid #dee2e6;
            color: #375a7f;
            background: white;
            transition: all 0.2s;
        }
        
        .pagination-wrapper .pagination .page-link:hover {
            background: #375a7f;
            color: white;
            border-color: #375a7f;
        }
        
        .pagination-wrapper .pagination .page-item.active .page-link {
            background: #375a7f;
            color: white;
            border-color: #375a7f;
        }
        
        .pagination-wrapper .pagination .page-item.disabled .page-link {
            color: #6c757d;
            cursor: not-allowed;
            background: #f8f9fa;
        }
        
        .pagination-wrapper .pagination .page-item.disabled .page-link:hover {
            background: #f8f9fa;
            color: #6c757d;
        }
        
        /* ===== EMPTY STATE ===== */
        .empty-state {
            text-align: center;
            padding: 30px 20px;
            color: #6c757d;
        }
        
        .empty-state i {
            font-size: 2.5rem;
            color: #dee2e6;
            margin-bottom: 10px;
        }
        
        /* ========================================
                   RESPONSIVE
                ======================================== */
        
        /* Tablet */
        @media (max-width: 992px) {
            #sidebar-wrapper {
                width: 70px;
            }
            
            #sidebar-wrapper .sidebar-heading span { display: none; }
            #sidebar-wrapper .list-group-item span { display: none; }
            #sidebar-wrapper .list-group-item i {
                margin-right: 0;
                width: 100%;
                text-align: center;
                font-size: 1.2rem;
            }
            #sidebar-wrapper .list-group-item { padding: 15px 0; text-align: center; }
            #sidebar-wrapper .badge { display: none; }
            #sidebar-wrapper .sidebar-heading img { display: none; }
            #sidebar-wrapper .sidebar-heading { justify-content: center; padding: 1.2rem 0; }
            
            .header-logo { display: inline-block !important; }
            
            .toolbar {
                flex-direction: column;
                align-items: stretch;
            }
            
            .toolbar .search-box {
                max-width: 100%;
            }
            
            .toolbar .toolbar-actions {
                justify-content: flex-start;
                margin-left: 0;
            }
        }
        
        /* Mobile */
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
            
            #sidebar-wrapper.mobile-open {
                transform: translateX(0);
            }
            
            #sidebar-wrapper .sidebar-heading span { display: inline; }
            #sidebar-wrapper .list-group-item span { display: inline; }
            #sidebar-wrapper .list-group-item i {
                margin-right: 10px;
                width: 24px;
                text-align: center;
                font-size: 1rem;
            }
            #sidebar-wrapper .list-group-item { padding: 0.8rem 1.2rem; text-align: left; }
            #sidebar-wrapper .badge { display: inline; }
            #sidebar-wrapper .sidebar-heading img { display: inline; }
            #sidebar-wrapper .sidebar-heading { justify-content: space-between; padding: 1.2rem 1rem; }
            
            /* Mobile overlay */
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
            
            .sidebar-overlay.active {
                display: block;
            }
            
            .header-logo { display: inline-block !important; }
            
            .navbar {
                padding: 0.4rem 1rem;
                min-height: 44px;
            }
            
            .navbar-brand {
                font-size: 0.95rem;
            }
            
            .navbar-right {
                gap: 8px;
            }
            
            .navbar-right .small {
                font-size: 0.7rem;
            }
            
            .content-container {
                padding: 8px 12px 12px 12px;
            }
            
            .toolbar {
                padding: 8px 12px;
                gap: 8px;
                margin-bottom: 8px;
                border-radius: 8px;
            }
            
            .toolbar .search-box input {
                height: 32px;
                font-size: 0.8rem;
                padding: 4px 10px 4px 30px;
            }
            
            .toolbar .search-box i {
                font-size: 0.75rem;
                left: 10px;
            }
            
            .toolbar .toolbar-actions .btn {
                font-size: 0.7rem;
                padding: 4px 10px;
                height: 32px;
            }
            
            .members-filter-tabs {
                margin-bottom: 8px;
                border-radius: 8px;
            }
            
            .members-filter-tabs .filter-tab {
                padding: 6px 12px;
                font-size: 0.7rem;
                min-width: 50px;
            }
            
            .member-table th,
            .member-table td {
                padding: 6px 10px;
                font-size: 0.7rem;
            }
            
            .pagination-wrapper {
                padding: 8px 12px;
                flex-direction: column;
                align-items: center;
                text-align: center;
            }
            
            .pagination-wrapper .pagination .page-link {
                padding: 3px 8px;
                font-size: 0.7rem;
            }
            
            .status-badge {
                font-size: 0.6rem;
                padding: 2px 6px;
            }
            
            .member-list-container {
                border-radius: 8px;
            }
        }
        
        /* Small mobile */
        @media (max-width: 480px) {
            .navbar-brand {
                font-size: 0.85rem;
            }
            
            .navbar-right .small {
                display: none;
            }
            
            .member-table {
                min-width: 450px;
            }
            
            .members-filter-tabs .filter-tab {
                padding: 4px 8px;
                font-size: 0.6rem;
                min-width: 40px;
            }
            
            .toolbar .per-page-selector {
                font-size: 0.7rem;
                height: 32px;
            }
            
            .toolbar .per-page-selector select {
                padding: 2px 6px;
                font-size: 0.7rem;
                height: 28px;
            }
            
            .content-container {
                padding: 6px 8px 10px 8px;
            }
        }
        
        /* Landscape phone */
        @media (max-height: 500px) and (orientation: landscape) {
            #sidebar-wrapper {
                transform: translateX(-100%);
            }
            
            #sidebar-wrapper.mobile-open {
                transform: translateX(0);
            }
            
            .content-container {
                padding: 6px 12px 10px 12px;
            }
            
            .navbar {
                padding: 0.2rem 0.8rem;
                min-height: 36px;
            }
            
            .members-filter-tabs .filter-tab {
                padding: 3px 8px;
                font-size: 0.65rem;
            }
            
            .toolbar {
                padding: 6px 10px;
                margin-bottom: 6px;
                gap: 6px;
            }
            
            .toolbar .search-box input {
                height: 28px;
                font-size: 0.75rem;
            }
            
            .toolbar .toolbar-actions .btn {
                height: 28px;
                font-size: 0.7rem;
                padding: 3px 8px;
            }
            
            .member-table th,
            .member-table td {
                padding: 4px 8px;
                font-size: 0.65rem;
            }
            
            .pagination-wrapper {
                padding: 4px 10px;
            }
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
                    <button class="btn btn-link d-md-none p-0 me-2" id="mobileMenuToggle" style="color: #375a7f; font-size: 1.3rem;">
                        <i class="fas fa-bars"></i>
                    </button>
                    <img src="../assets/images/harana-logo.png" alt="Harana" class="header-logo" id="headerLogo" onerror="this.style.display='none';">
                    <span class="navbar-brand"><i class="fas fa-tachometer-alt me-2"></i>Dashboard</span>
                </div> remove the calendar here and the user icon and name should be removed the export csv button should be moved where the user icon rwemove
                <div class="navbar-right">
                    <span class="text-muted small d-none d-sm-inline">
                        <i class="fas fa-calendar-alt me-1"></i><?php echo date('F j, Y'); ?>
                    </span>
                    <span class="small"><i class="fas fa-user-circle me-2"></i><?php echo htmlspecialchars($current_user['full_name']); ?></span>
                </div>
            </nav>

            <!-- CONTENT -->
            <div class="content-container">
                
                <!-- Toolbar -->
                <div class="toolbar">
                    <div class="search-box">
                        <i class="fas fa-search"></i> should have seach button when clicked it triggres search and when enter is pressed it also triggers search
                        <input type="text" id="searchInput" placeholder="Search members..." value="<?php echo htmlspecialchars($search); ?>">
                    </div> here is the filter button that when clicked it shows a dropdown with the filter options and when an option is selected it triggers the filter
                    <div class="toolbar-actions">
                        <a href="member_export.php" class="btn btn-success">
                            <i class="fas fa-file-excel"></i> Export CSV
                        </a>
                    </div>
                    <div class="per-page-selector">
                        <span>Show:</span>
                        <select id="perPageSelect">
                            <?php foreach ($allowed_per_page as $value): ?>
                                <option value="<?php echo $value; ?>" <?php echo $per_page == $value ? 'selected' : ''; ?>>
                                    <?php echo $value; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Filter Tabs --> 
                <div class="members-filter-tabs">this should be at the end and delete the margin on top and buttom 
                    <button class="filter-tab <?php echo $filter === 'all' ? 'active' : ''; ?>" data-filter="all">
                        All Members
                        <span class="count-badge"><?php echo $total_members; ?></span>
                    </button>
                    <button class="filter-tab <?php echo $filter === 'paid' ? 'active' : ''; ?>" data-filter="paid">
                        <i class="fas fa-check-circle" style="color: #28a745;"></i> Paid
                        <span class="count-badge paid-badge"><?php echo $paid_count; ?></span>
                    </button>
                    <button class="filter-tab <?php echo $filter === 'unpaid' ? 'active' : ''; ?>" data-filter="unpaid">
                        <i class="fas fa-exclamation-circle" style="color: #dc3545;"></i> Unpaid
                        <span class="count-badge unpaid-badge"><?php echo $unpaid_count; ?></span>
                    </button>
                    <button class="filter-tab <?php echo $filter === 'advanced' ? 'active' : ''; ?>" data-filter="advanced">
                        <i class="fas fa-star" style="color: #17a2b8;"></i> Advanced
                        <span class="count-badge advanced-badge"><?php echo $advanced_count; ?></span>
                    </button>
                </div>

                <!-- Member List -->
                <div class="member-list-container">
                    <!-- ONLY THIS DIV SCROLLS -->
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
                                the listed members should be clickable and when clicked it opens a modal with the member details and the modal should have a close button and when the close button is clicked it closes the modal 
                                <tbody id="memberTableBody">
                                    <?php if (count($filtered_members) > 0): ?>
                                        <?php foreach ($filtered_members as $member): ?>
                                            <?php 
                                                // SAFE PAYMENT STATUS WITH FALLBACK
                                                $payment = isset($member['payment_status']) && is_array($member['payment_status']) 
                                                    ? $member['payment_status'] 
                                                    : ['status' => 'unpaid', 'label' => 'Unpaid', 'class' => 'unpaid', 'count' => 0, 'pending' => 0];
                                                
                                                $status_class = $payment['status'] ?? 'unpaid';
                                                $status_text = $payment['label'] ?? 'Unpaid';
                                                $pending_text = isset($payment['pending']) && $payment['pending'] > 0 ? " ({$payment['pending']} pending)" : '';
                                                $advanced_text = isset($payment['count']) && $payment['count'] > 0 && $status_class === 'advanced' ? " ({$payment['count']} months)" : '';
                                            ?>
                                            <tr class="member-row" data-status="<?php echo $status_class; ?>">
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
                                                    <span class="status-badge <?php echo $status_class; ?>">
                                                        <?php echo $status_text; ?>
                                                        <?php echo $advanced_text; ?>
                                                        <?php echo $pending_text; ?>
                                                    </span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="7">
                                                <div class="empty-state">
                                                    <i class="fas fa-users"></i>
                                                    <p style="margin-bottom: 0;"><?php echo !empty($search) ? 'No members found matching "' . htmlspecialchars($search) . '"' : 'No active members found'; ?></p>
                                                    <?php if (!empty($search)): ?>
                                                        <button class="btn btn-outline-secondary btn-sm mt-2" onclick="clearSearch()">
                                                            <i class="fas fa-times me-1"></i> Clear Search
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <!-- Pagination -->
                    <?php if ($total_members > 0): ?>
                    <div class="pagination-wrapper">
                        <div class="info">
                            Showing <?php echo ($offset + 1); ?> - <?php echo min($offset + $per_page, $total_members); ?> of <?php echo $total_members; ?> members
                        </div>
                        <nav>
                            <ul class="pagination">
                                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo buildPaginationUrl(1, $filter, $search, $per_page); ?>">&laquo;</a>
                                </li>
                                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo buildPaginationUrl($page - 1, $filter, $search, $per_page); ?>">&lsaquo;</a>
                                </li>
                                <?php
                                $start_page = max(1, $page - 2);
                                $end_page = min($total_pages, $page + 2);
                                if ($start_page > 1) {
                                    echo '<li class="page-item"><a class="page-link" href="' . buildPaginationUrl(1, $filter, $search, $per_page) . '">1</a></li>';
                                    if ($start_page > 2) {
                                        echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                    }
                                }
                                for ($i = $start_page; $i <= $end_page; $i++) {
                                    echo '<li class="page-item ' . ($i == $page ? 'active' : '') . '">
                                        <a class="page-link" href="' . buildPaginationUrl($i, $filter, $search, $per_page) . '">' . $i . '</a>
                                    </li>';
                                }
                                if ($end_page < $total_pages) {
                                    if ($end_page < $total_pages - 1) {
                                        echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                    }
                                    echo '<li class="page-item"><a class="page-link" href="' . buildPaginationUrl($total_pages, $filter, $search, $per_page) . '">' . $total_pages . '</a></li>';
                                }
                                ?>
                                <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo buildPaginationUrl($page + 1, $filter, $search, $per_page); ?>">&rsaquo;</a>
                                </li>
                                <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="<?php echo buildPaginationUrl($total_pages, $filter, $search, $per_page); ?>">&raquo;</a>
                                </li>
                            </ul>
                        </nav>
                    </div>
                    <?php endif; ?>
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
        
        // Desktop toggle
        if (menuToggle) {
            menuToggle.addEventListener('click', function() {
                sidebar.classList.toggle('collapsed');
                localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
            });
        }
        
        // Mobile toggle
        if (mobileToggle) {
            mobileToggle.addEventListener('click', function() {
                sidebar.classList.toggle('mobile-open');
                overlay.classList.toggle('active');
            });
        }
        
        // Overlay click closes sidebar
        if (overlay) {
            overlay.addEventListener('click', function() {
                sidebar.classList.remove('mobile-open');
                overlay.classList.remove('active');
            });
        }
        
        // Restore sidebar state
        if (localStorage.getItem('sidebarCollapsed') === 'true' && window.innerWidth > 768) {
            sidebar.classList.add('collapsed');
        }

        // ========================================
        // SEARCH (Debounced)
        // ========================================
        let searchTimeout;
        const searchInput = document.getElementById('searchInput');
        
        if (searchInput) {
            searchInput.addEventListener('input', function() {
                clearTimeout(searchTimeout);
                searchTimeout = setTimeout(function() {
                    const search = encodeURIComponent(searchInput.value);
                    const url = new URL(window.location.href);
                    if (search) {
                        url.searchParams.set('search', search);
                    } else {
                        url.searchParams.delete('search');
                    }
                    url.searchParams.set('page', 1);
                    window.location.href = url.toString();
                }, 400);
            });
        }

        function clearSearch() {
            document.getElementById('searchInput').value = '';
            const url = new URL(window.location.href);
            url.searchParams.delete('search');
            url.searchParams.set('page', 1);
            window.location.href = url.toString();
        }

        // ========================================
        // PER PAGE SELECTOR
        // ========================================
        document.getElementById('perPageSelect')?.addEventListener('change', function() {
            const url = new URL(window.location.href);
            url.searchParams.set('per_page', this.value);
            url.searchParams.set('page', 1);
            window.location.href = url.toString();
        });

        // ========================================
        // FILTER TABS
        // ========================================
        const filterTabs = document.querySelectorAll('.filter-tab');
        const currentFilter = new URLSearchParams(window.location.search).get('filter') || 'all';

        filterTabs.forEach(tab => {
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

        // ========================================
        // LOGO FALLBACK
        // ========================================
        document.querySelectorAll('.sidebar-heading img, .header-logo').forEach(img => {
            img.onerror = function() {
                this.style.display = 'none';
            };
        });

        // ========================================
        // KEYBOARD SHORTCUTS
        // ========================================
        document.addEventListener('keydown', function(e) {
            // Ctrl+F or / to focus search
            if ((e.ctrlKey && e.key === 'f') || (e.key === '/' && !e.ctrlKey && !e.metaKey)) {
                if (!['INPUT', 'TEXTAREA'].includes(e.target.tagName)) {
                    e.preventDefault();
                    document.getElementById('searchInput')?.focus();
                }
            }
            // Escape to clear search
            if (e.key === 'Escape' && document.activeElement === searchInput) {
                if (searchInput.value) {
                    clearSearch();
                } else {
                    searchInput.blur();
                }
            }
        });

        console.log('✅ Dashboard loaded successfully');
        console.log('📊 Paid: <?php echo $paid_count; ?>');
        console.log('📊 Unpaid: <?php echo $unpaid_count; ?>');
        console.log('📊 Advanced: <?php echo $advanced_count; ?>');
        console.log('📊 Total: <?php echo $total_members; ?>');
    </script>
</body>