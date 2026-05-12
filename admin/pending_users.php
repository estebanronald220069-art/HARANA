<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// admin/pending_users.php
require_once '../includes/config.php';
require_once '../includes/db_connection.php';
require_once '../includes/security.php';
require_once '../includes/auth.php';
require_once '../includes/user_functions.php';

$auth->requireLogin();
$auth->requireRole('admin');
$current_user = $auth->getCurrentUser();

$db = Database::getInstance();

// Get counts
$user_pending_count = $db->getSingle("SELECT COUNT(*) as cnt FROM pending_users WHERE status = 'pending'")['cnt'] ?? 0;
$photo_pending_count = $db->getSingle("SELECT COUNT(*) as cnt FROM member_photo_requests WHERE status = 'pending'")['cnt'] ?? 0;
$total_pending = $user_pending_count + $photo_pending_count;

$message = '';
$error = '';

// Pagination
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
$search = isset($_GET['search']) ? Security::sanitize($_GET['search']) : '';
$filter_name = isset($_GET['filter_name']) ? Security::sanitize($_GET['filter_name']) : '';
$filter_chapter = isset($_GET['filter_chapter']) ? Security::sanitize($_GET['filter_chapter']) : '';

// Validate limit
$allowed_limits = [10, 20, 30, 50, 100];
if (!in_array($limit, $allowed_limits)) {
    $limit = 20;
}

// Build WHERE conditions
$where_conditions = ["status = 'pending'"];
$params = [];
$types = '';

// Search condition
if (!empty($search)) {
    $search_terms = explode(' ', $search);
    $search_conditions = [];
    foreach ($search_terms as $term) {
        if (!empty(trim($term))) {
            $term = trim($term);
            $search_conditions[] = "(first_name LIKE ? OR last_name LIKE ? OR CONCAT(first_name, ' ', last_name) LIKE ? OR username LIKE ? OR email LIKE ? OR member_code LIKE ?)";
            $like_term = "%$term%";
            for ($i = 0; $i < 6; $i++) {
                $params[] = $like_term;
                $types .= 's';
            }
        }
    }
    if (!empty($search_conditions)) {
        $where_conditions[] = '(' . implode(' OR ', $search_conditions) . ')';
    }
}

// Name filter
if (!empty($filter_name)) {
    $where_conditions[] = "(first_name LIKE ? OR last_name LIKE ? OR CONCAT(first_name, ' ', last_name) LIKE ?)";
    $like_name = "%$filter_name%";
    $params[] = $like_name;
    $params[] = $like_name;
    $params[] = $like_name;
    $types .= 'sss';
}

// Chapter filter
if (!empty($filter_chapter)) {
    $where_conditions[] = "chapter = ?";
    $params[] = $filter_chapter;
    $types .= 's';
}

$where_clause = "WHERE " . implode(" AND ", $where_conditions);

// Get total records
$count_sql = "SELECT COUNT(*) as total FROM pending_users $where_clause";
$count_result = $db->getSingle($count_sql, $params, $types);
$total_records = $count_result['total'] ?? 0;
$total_pages = ceil($total_records / $limit);
$offset = ($page - 1) * $limit;

if ($page < 1) $page = 1;
if ($page > $total_pages && $total_pages > 0) $page = $total_pages;

// Fetch pending users
$sql = "SELECT * FROM pending_users $where_clause ORDER BY requested_at DESC LIMIT ? OFFSET ?";
$query_params = array_merge($params, [$limit, $offset]);
$query_types = $types . 'ii';
$pending_users = $db->getAll($sql, $query_params, $query_types);

// Get unique chapters for filter
$chapters = $db->getAll("SELECT DISTINCT chapter FROM pending_users WHERE chapter IS NOT NULL AND chapter != '' AND status = 'pending' ORDER BY chapter ASC");
$chapter_list = [];
foreach ($chapters as $chap) {
    $chapter_list[] = $chap['chapter'];
}

// Get unique barangays for filter
$barangays = $db->getAll("SELECT DISTINCT barangay FROM pending_users WHERE barangay IS NOT NULL AND barangay != '' AND status = 'pending' ORDER BY barangay ASC");
$barangay_list = [];
foreach ($barangays as $brgy) {
    $barangay_list[] = $brgy['barangay'];
}

// Get unique groups for filter
$groups = $db->getAll("SELECT DISTINCT group_name FROM pending_users WHERE group_name IS NOT NULL AND group_name != '' AND status = 'pending' ORDER BY group_name ASC");
$group_list = [];
foreach ($groups as $grp) {
    $group_list[] = $grp['group_name'];
}

$csrf_token = Security::generateCSRFToken();

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token.';
    } else {
        $action = $_POST['action'] ?? '';
        $pending_id = (int)($_POST['pending_id'] ?? 0);

        if ($action === 'approve') {
            $pending = $db->getSingle("SELECT * FROM pending_users WHERE id = ?", [$pending_id], 'i');
            
            if (!$pending) {
                $error = 'Request not found.';
            } else {
                $db->getConnection()->begin_transaction();
                
                try {
                    // Check if username already exists
                    $existing_user = $db->getSingle(
                        "SELECT user_id FROM users WHERE username = ?", 
                        [$pending['username']], 
                        's'
                    );
                    
                    if ($existing_user) {
                        throw new Exception('Username already exists: ' . $pending['username']);
                    }
                    
                    $existing_email = $db->getSingle(
                        "SELECT user_id FROM users WHERE email = ?", 
                        [$pending['email']], 
                        's'
                    );
                    
                    if ($existing_email) {
                        throw new Exception('Email already exists: ' . $pending['email']);
                    }
                    
                    // 1. INSERT INTO USERS TABLE
                    $full_name = trim($pending['first_name'] . ' ' . ($pending['middle_name'] ?? '') . ' ' . $pending['last_name']);
                    $full_name = preg_replace('/\s+/', ' ', $full_name);
                    
                    $insert_user = $db->execute(
                        "INSERT INTO users (username, password, full_name, email, role, is_active, created_at) 
                         VALUES (?, ?, ?, ?, ?, 1, NOW())",
                        [
                            $pending['username'],
                            $pending['password'],
                            $full_name,
                            $pending['email'],
                            $pending['role_requested'] ?? 'viewer'
                        ],
                        'sssss'
                    );
                    
                    if (!$insert_user) {
                        throw new Exception('Failed to create user account: ' . $db->getConnection()->error);
                    }
                    
                    $user_id = $db->getConnection()->insert_id;
                    
                    // Handle member_code uniqueness
                    $member_code = $pending['member_code'];
                    $existing_member = $db->getSingle(
                        "SELECT member_code FROM members WHERE member_code = ?", 
                        [$member_code], 
                        's'
                    );
                    
                    if ($existing_member) {
                        $year = date('Y');
                        $random = str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
                        $member_code = $year . $random;
                    }
                    
                    // Build address from components
                    $address_parts = [];
                    if (!empty($pending['street'])) $address_parts[] = $pending['street'];
                    if (!empty($pending['barangay'])) $address_parts[] = 'Brgy. ' . $pending['barangay'];
                    if (!empty($pending['city'])) $address_parts[] = $pending['city'];
                    if (!empty($pending['province'])) $address_parts[] = $pending['province'];
                    $address = implode(', ', $address_parts);
                    
                    // Generate combined names
                    $father_name = trim(($pending['father_fname'] ?? '') . ' ' . ($pending['father_mname'] ?? '') . ' ' . ($pending['father_lname'] ?? ''));
                    $mother_name = trim(($pending['mother_fname'] ?? '') . ' ' . ($pending['mother_mname'] ?? '') . ' ' . ($pending['mother_lname'] ?? ''));
                    $spouse_name = trim(($pending['spouse_fname'] ?? '') . ' ' . ($pending['spouse_mname'] ?? '') . ' ' . ($pending['spouse_lname'] ?? ''));
                    
                    $child1_name = trim(($pending['child1_fname'] ?? '') . ' ' . ($pending['child1_mname'] ?? '') . ' ' . ($pending['child1_lname'] ?? ''));
                    $child2_name = trim(($pending['child2_fname'] ?? '') . ' ' . ($pending['child2_mname'] ?? '') . ' ' . ($pending['child2_lname'] ?? ''));
                    $child3_name = trim(($pending['child3_fname'] ?? '') . ' ' . ($pending['child3_mname'] ?? '') . ' ' . ($pending['child3_lname'] ?? ''));
                    $child4_name = trim(($pending['child4_fname'] ?? '') . ' ' . ($pending['child4_mname'] ?? '') . ' ' . ($pending['child4_lname'] ?? ''));
                    
                    // Handle registration photo
                    $registration_photo = null;
                    if (!empty($pending['registration_photo'])) {
                        // Move photo from temp to members folder
                        $old_path = '../' . $pending['registration_photo'];
                        $new_filename = 'member_' . $member_code . '_' . time() . '.jpg';
                        $new_path = '../uploads/members/' . $new_filename;
                        
                        if (file_exists($old_path)) {
                            rename($old_path, $new_path);
                            $registration_photo = 'uploads/members/' . $new_filename;
                        }
                    }
                    
                    // 2. INSERT INTO MEMBERS TABLE
                    $insert_member_sql = "INSERT INTO members (
                        member_code, first_name, last_name, middle_name,
                        address, present_address, permanent_address,
                        barangay, city, province,
                        contact_number, alternate_number, email,
                        birth_date, place_of_birth, age, gender, civil_status, religion,
                        father_fname, father_mname, father_lname,
                        mother_fname, mother_mname, mother_lname,
                        spouse_fname, spouse_mname, spouse_lname,
                        father_name, mother_name, spouse_name, spouse_age,
                        child1_fname, child1_mname, child1_lname, child1_name, child1_age,
                        child2_fname, child2_mname, child2_lname, child2_name, child2_age,
                        child3_fname, child3_mname, child3_lname, child3_name, child3_age,
                        child4_fname, child4_mname, child4_lname, child4_name, child4_age,
                        street,
                        ref1_name, ref1_contact, ref2_name, ref2_contact,
                        date_joined, status,
                        monthly_contribution,
                        chapter, group_name, leader, coordinator, chairman,
                        screening_officer, screening_date, approved_by,
                        date_registered,
                        beneficiary_name, beneficiary_address, beneficiary_relation, beneficiary_age, beneficiary_contact,
                        medical_certificate, birth_certificate,
                        profile_photo, username, user_id, created_by,
                        created_at
                    ) VALUES (
                        ?, ?, ?, ?,
                        ?, ?, ?,
                        ?, ?, ?,
                        ?, ?, ?,
                        ?, ?, ?, ?, ?, ?,
                        ?, ?, ?,
                        ?, ?, ?,
                        ?, ?, ?,
                        ?, ?, ?, ?,
                        ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?,
                        ?, ?, ?, ?, ?,
                        ?,
                        ?, ?, ?, ?,
                        ?, 'active',
                        ?,
                        ?, ?, ?, ?, ?,
                        ?, ?, ?,
                        ?,
                        ?, ?, ?, ?, ?,
                        ?, ?,
                        ?, ?, ?, ?,
                        NOW()
                    )";
                    
                    $member_values = [
                        // Basic info (4)
                        $member_code,
                        $pending['first_name'],
                        $pending['last_name'],
                        $pending['middle_name'] ?? '',
                        // Address (7)
                        $address,
                        $pending['present_address'] ?? $address,
                        $pending['permanent_address'] ?? $address,
                        $pending['barangay'] ?? '',
                        $pending['city'] ?? 'San Jose',
                        $pending['province'] ?? 'Occidental Mindoro',
                        // Contact (3)
                        $pending['contact_number'] ?? '',
                        $pending['alternate_number'] ?? '',
                        $pending['email'],
                        // Personal (9)
                        $pending['birth_date'] ?? null,
                        $pending['place_of_birth'] ?? '',
                        $pending['age'] ?? 0,
                        $pending['gender'] ?? '',
                        $pending['civil_status'] ?? '',
                        $pending['religion'] ?? '',
                        // Father (3)
                        $pending['father_fname'] ?? '',
                        $pending['father_mname'] ?? '',
                        $pending['father_lname'] ?? '',
                        // Mother (3)
                        $pending['mother_fname'] ?? '',
                        $pending['mother_mname'] ?? '',
                        $pending['mother_lname'] ?? '',
                        // Spouse (3 + 1 for spouse_age)
                        $pending['spouse_fname'] ?? '',
                        $pending['spouse_mname'] ?? '',
                        $pending['spouse_lname'] ?? '',
                        // Combined names and spouse_age (4)
                        $father_name,
                        $mother_name,
                        $spouse_name,
                        $pending['spouse_age'] ?? null,
                        // Child 1 (5)
                        $pending['child1_fname'] ?? '',
                        $pending['child1_mname'] ?? '',
                        $pending['child1_lname'] ?? '',
                        $child1_name,
                        $pending['child1_age'] ?? null,
                        // Child 2 (5)
                        $pending['child2_fname'] ?? '',
                        $pending['child2_mname'] ?? '',
                        $pending['child2_lname'] ?? '',
                        $child2_name,
                        $pending['child2_age'] ?? null,
                        // Child 3 (5)
                        $pending['child3_fname'] ?? '',
                        $pending['child3_mname'] ?? '',
                        $pending['child3_lname'] ?? '',
                        $child3_name,
                        $pending['child3_age'] ?? null,
                        // Child 4 (5)
                        $pending['child4_fname'] ?? '',
                        $pending['child4_mname'] ?? '',
                        $pending['child4_lname'] ?? '',
                        $child4_name,
                        $pending['child4_age'] ?? null,
                        // Street
                        $pending['street'] ?? '',
                        // References (4)
                        $pending['ref1_name'] ?? '',
                        $pending['ref1_contact'] ?? '',
                        $pending['ref2_name'] ?? '',
                        $pending['ref2_contact'] ?? '',
                        // Date joined
                        $pending['date_joined'] ?? date('Y-m-d'),
                        // monthly_contribution
                        $pending['monthly_contribution'] ?? 100.00,
                        // Chapter info (5)
                        $pending['chapter'] ?? '',
                        $pending['group_name'] ?? '',
                        $pending['leader'] ?? '',
                        $pending['coordinator'] ?? '',
                        $pending['chairman'] ?? '',
                        // Screening (3)
                        $pending['screening_officer'] ?? '',
                        $pending['screening_date'] ?? null,
                        $pending['approved_by'] ?? '',
                        // date_registered
                        $pending['date_registered'] ?? date('Y-m-d'),
                        // Beneficiary (5)
                        $pending['beneficiary_name'] ?? '',
                        $pending['beneficiary_address'] ?? '',
                        $pending['beneficiary_relation'] ?? '',
                        $pending['beneficiary_age'] ?? null,
                        $pending['beneficiary_contact'] ?? '',
                        // Documents (2)
                        $pending['medical_certificate'] ?? 0,
                        $pending['birth_certificate'] ?? 0,
                        // Profile photo, account info (5)
                        $registration_photo,
                        $pending['username'],
                        $user_id,
                        $user_id
                    ];
                    
                    $insert_member = $db->execute($insert_member_sql, $member_values, str_repeat('s', count($member_values)));
                    
                    if (!$insert_member) {
                        throw new Exception('Failed to create member record: ' . $db->getConnection()->error);
                    }
                    
                    // Initialize member_balances
                    $check_balances = $db->getSingle("SHOW TABLES LIKE 'member_balances'");
                    if ($check_balances) {
                        $db->execute(
                            "INSERT INTO member_balances (member_code, total_paid, total_due, current_balance) 
                             VALUES (?, 0.00, ?, ?)",
                            [
                                $member_code,
                                $pending['monthly_contribution'] ?? 100.00,
                                $pending['monthly_contribution'] ?? 100.00
                            ],
                            'sdd'
                        );
                    }
                    
                    // Delete from pending
                    $db->execute("DELETE FROM pending_users WHERE id = ?", [$pending_id], 'i');
                    
                    $db->getConnection()->commit();
                    
                    $message = "User {$pending['username']} approved and added to members list. They can now login!";
                    Security::logEvent('USER_APPROVED', "Approved user: {$pending['username']}");
                    
                    // CREATE NOTIFICATION FOR THE NEW USER
                    createNotification(
                        $db,
                        $user_id,
                        "Account Approved",
                        "Your membership application has been approved! You can now log in to your account.",
                        'account',
                        '../index.php'
                    );
                    
                } catch (Exception $e) {
                    $db->getConnection()->rollback();
                    $error = 'Failed to approve user: ' . $e->getMessage();
                    error_log('Approval error: ' . $e->getMessage());
                    error_log('Stack trace: ' . $e->getTraceAsString());
                }
            }
        } elseif ($action === 'reject') {
            $pending = $db->getSingle("SELECT * FROM pending_users WHERE id = ?", [$pending_id], 'i');
            if ($pending && !empty($pending['registration_photo'])) {
                $photo_path = '../' . $pending['registration_photo'];
                if (file_exists($photo_path)) {
                    unlink($photo_path);
                }
            }
            $db->execute("UPDATE pending_users SET status = 'rejected' WHERE id = ?", [$pending_id], 'i');
            $message = "Request rejected.";
            Security::logEvent('USER_REJECTED', "Rejected user ID: $pending_id");
            
        } elseif ($action === 'delete') {
            $pending = $db->getSingle("SELECT * FROM pending_users WHERE id = ?", [$pending_id], 'i');
            if ($pending && !empty($pending['registration_photo'])) {
                $photo_path = '../' . $pending['registration_photo'];
                if (file_exists($photo_path)) {
                    unlink($photo_path);
                }
            }
            $db->execute("DELETE FROM pending_users WHERE id = ?", [$pending_id], 'i');
            $message = "Request deleted.";
        }
    }
}

// Generate pagination links helper
function buildPaginationLinks($current_page, $total_pages, $limit, $search, $filter_name, $filter_chapter) {
    $query_params = [];
    if ($limit != 20) $query_params['limit'] = $limit;
    if (!empty($search)) $query_params['search'] = $search;
    if (!empty($filter_name)) $query_params['filter_name'] = $filter_name;
    if (!empty($filter_chapter)) $query_params['filter_chapter'] = $filter_chapter;
    
    $base_url = 'pending_users.php?' . http_build_query($query_params);
    
    $links = [];
    
    if ($current_page > 1) {
        $links['prev'] = $base_url . '&page=' . ($current_page - 1);
    }
    
    $start_page = max(1, $current_page - 2);
    $end_page = min($total_pages, $current_page + 2);
    
    if ($start_page > 1) {
        $links['first'] = $base_url . '&page=1';
        if ($start_page > 2) $links['ellipsis_start'] = '...';
    }
    
    for ($i = $start_page; $i <= $end_page; $i++) {
        $links['page_' . $i] = [
            'page' => $i,
            'url' => $base_url . '&page=' . $i,
            'active' => ($i == $current_page)
        ];
    }
    
    if ($end_page < $total_pages) {
        if ($end_page < $total_pages - 1) $links['ellipsis_end'] = '...';
        $links['last'] = $base_url . '&page=' . $total_pages;
    }
    
    if ($current_page < $total_pages) {
        $links['next'] = $base_url . '&page=' . ($current_page + 1);
    }
    
    return $links;
}

$pagination_links = buildPaginationLinks($page, $total_pages, $limit, $search, $filter_name, $filter_chapter);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pending Approvals - Harana Financial System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
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
        .navbar-right { display: flex; align-items: center; gap: 20px; }
        
        .card {
            border: none;
            border-radius: 10px;
            box-shadow: 0 0 20px rgba(0,0,0,.1);
            margin-bottom: 20px;
        }
        
        .card-header {
            background: linear-gradient(135deg, #375a7f, #2c4a6b);
            color: white;
            border-radius: 10px 10px 0 0 !important;
            padding: 15px 20px;
        }
        
        .card-header h5 {
            margin: 0;
            font-size: 1rem;
        }
        
        /* Action Toolbar */
        .action-toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .search-group {
            display: flex;
            gap: 10px;
            align-items: center;
            flex: 1;
            max-width: 500px;
        }
        
        .search-input {
            flex: 1;
            height: 38px;
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 8px 15px;
            font-size: 14px;
        }
        
        .btn-search {
            height: 38px;
            padding: 0 18px;
            background: #375a7f;
            color: white;
            border: none;
            border-radius: 8px;
        }
        
        /* Filter Bar - Matching members.php */
        .filter-bar {
            background: white;
            border-radius: 10px;
            padding: 15px 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.06);
        }
        
        .filter-row {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: flex-end;
        }
        
        .filter-item {
            flex: 1;
            min-width: 120px;
        }
        
        .filter-item label {
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            color: #6c757d;
            margin-bottom: 4px;
            display: block;
            letter-spacing: 0.5px;
        }
        
        .filter-item .form-control,
        .filter-item .form-select {
            height: 36px;
            font-size: 13px;
            border-radius: 6px;
            border: 1px solid #dee2e6;
            padding: 5px 10px;
        }
        
        .filter-actions {
            display: flex;
            gap: 8px;
        }
        
        .filter-actions .btn {
            height: 36px;
            padding: 0 16px;
            font-size: 13px;
            border-radius: 6px;
        }
        
        .filter-actions .btn-primary {
            background: #375a7f;
            border: none;
        }
        
        .filter-actions .btn-primary:hover {
            background: #2c4a6b;
        }
        
        .filter-actions .btn-secondary {
            background: #6c757d;
            border: none;
        }
        
        /* Table Styles */
        .table-container {
            background: white;
            border-radius: 12px;
            overflow: hidden;
        }
        
        .table {
            margin-bottom: 0;
            width: 100%;
            font-size: 13px;
        }
        
        .table thead th {
            background: #f8f9fa;
            padding: 12px 12px;
            font-weight: 600;
            font-size: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #495057;
            border-bottom: 2px solid #dee2e6;
            white-space: nowrap;
        }
        
        .table tbody td {
            padding: 10px 12px;
            vertical-align: middle;
            border-bottom: 1px solid #e9ecef;
        }
        
        .table tbody tr:hover {
            background-color: rgba(55,90,127,0.04);
        }
        
        /* 3 Dot Dropdown - No "v" */
        .action-dropdown .dropdown-toggle::after {
            display: none;
        }
        
        .action-dropdown .dropdown-toggle {
            background: transparent;
            border: 1px solid #dee2e6;
            padding: 4px 10px;
            border-radius: 6px;
            color: #6c757d;
            transition: all 0.2s;
        }
        
        .action-dropdown .dropdown-toggle:hover {
            background: #f8f9fa;
            border-color: #adb5bd;
        }
        
        .dropdown-menu {
            font-size: 13px;
            min-width: 160px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
            border: none;
            border-radius: 8px;
            padding: 5px 0;
        }
        
        .dropdown-item {
            padding: 8px 16px;
            transition: all 0.2s;
        }
        
        .dropdown-item:hover {
            background-color: #f8f9fa;
            transform: translateX(2px);
        }
        
        .dropdown-item i {
            width: 20px;
            margin-right: 8px;
        }
        
        /* Badge */
        .badge-count {
            position: absolute !important;
            top: 50% !important;
            right: 10px !important;
            transform: translateY(-50%) !important;
            font-size: 0.7rem !important;
            padding: 3px 6px !important;
        }
        
        .dropdown-nav {
            margin-bottom: 20px;
        }
        
        .dropdown-nav .btn {
            padding: 10px 20px;
        }
        
        /* Pagination */
        .pagination-wrapper {
            background: white;
            padding: 15px 20px;
            border-top: 1px solid #e9ecef;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .pagination {
            margin: 0;
            gap: 4px;
        }
        
        .page-link {
            color: #375a7f;
            padding: 6px 12px;
            font-size: 13px;
            border-radius: 6px;
            border: 1px solid #dee2e6;
        }
        
        .page-link:hover {
            background-color: #e9ecef;
        }
        
        .page-item.active .page-link {
            background-color: #375a7f;
            border-color: #375a7f;
            color: white;
        }
        
        /* Card Body Height Increased */
        .card-body.p-0 {
            max-height: calc(100vh - 350px);
            overflow-y: auto;
        }
        
        /* Modal Styles */
        .logo-section {
            display: flex;
            align-items: center;
            gap: 20px;
            padding: 20px;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            border-radius: 10px;
            margin-bottom: 20px;
        }
        
        .logo-section img {
            width: 80px;
            height: 80px;
            object-fit: contain;
        }
        
        .logo-placeholder {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
        }
        
        .logo-section .text-content {
            flex: 1;
        }
        
        .logo-section h2 {
            color: #2c3e50;
            margin-bottom: 5px;
            font-size: 18px;
        }
        
        .logo-section h5 {
            color: #34495e;
            margin-bottom: 3px;
            font-size: 13px;
        }
        
        .logo-section p {
            margin-bottom: 2px;
            color: #7f8c8d;
            font-size: 10px;
        }
        
        .form-title {
            text-align: center;
            margin: 20px 0;
            padding: 10px;
            background: #2c3e50;
            color: white;
            border-radius: 5px;
            font-size: 16px;
        }
        
        .documents-section {
            background: #e8f4f8;
            padding: 10px 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        
        .checkbox-group {
            display: flex;
            gap: 20px;
            margin-top: 5px;
        }
        
        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .section-title {
            background: #3498db;
            color: white;
            padding: 6px 12px;
            border-radius: 6px;
            margin: 15px 0 10px 0;
            font-size: 13px;
            font-weight: 600;
        }
        
        .info-table {
            width: 100%;
            margin-bottom: 15px;
        }
        
        .info-table td {
            padding: 8px;
            border: 1px solid #dee2e6;
            vertical-align: top;
        }
        
        .info-table .label {
            font-weight: 600;
            background-color: #f8f9fa;
            width: 180px;
        }
        
        .pending-photo {
            width: 150px;
            height: 150px;
            object-fit: cover;
            border-radius: 12px;
            border: 3px solid #dee2e6;
            margin-bottom: 10px;
        }
        
        .photo-section {
            text-align: center;
            margin-bottom: 20px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 10px;
        }
        
        @media (max-width: 768px) {
            .action-toolbar {
                flex-direction: column;
                align-items: stretch;
            }
            
            .search-group {
                max-width: 100%;
            }
            
            .filter-row {
                flex-direction: column;
            }
            
            .pagination-wrapper {
                flex-direction: column;
                text-align: center;
            }
            
            .card-body.p-0 {
                max-height: calc(100vh - 500px);
            }
        }
        /* Increase height of the card body */
.card-body.p-0 {
    max-height: calc(100vh - 280px); /* Adjust this value to control height */
    overflow-y: auto;
    min-height: 500px; /* Minimum height - adjust as needed */
}

/* For larger screens */
@media (min-width: 1400px) {
    .card-body.p-0 {
        max-height: calc(100vh - 250px);
        min-height: 600px;
    }
}

/* For medium screens */
@media (max-width: 992px) {
    .card-body.p-0 {
        max-height: calc(100vh - 320px);
        min-height: 400px;
    }
}

/* For mobile screens */
@media (max-width: 768px) {
    .card-body.p-0 {
        max-height: calc(100vh - 380px);
        min-height: 300px;
    }
}
    </style>
</head>
<body>
    <div class="d-flex" id="wrapper">
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
                <a href="dashboard.php" class="list-group-item"><i class="fas fa-tachometer-alt"></i><span>Dashboard</span></a>
                <a href="members.php" class="list-group-item"><i class="fas fa-users"></i><span>Members</span></a>
                <a href="council.php" class="list-group-item"><i class="fas fa-user-tie"></i><span>Council</span></a>
                <a href="payments.php" class="list-group-item"><i class="fas fa-credit-card"></i><span>Payments</span></a>
                <a href="reports.php" class="list-group-item"><i class="fas fa-chart-bar"></i><span>Reports</span></a>
                <?php if ($current_user['role'] === 'admin'): ?>
                <a href="pending_users.php" class="list-group-item active position-relative">
                    <i class="fas fa-user-clock"></i><span>Pending</span>
                    <?php if ($total_pending > 0): ?>
                        <span class="badge bg-danger position-absolute top-0 start-100 translate-middle badge-count"><?php echo $total_pending; ?></span>
                    <?php endif; ?>
                </a>
                <?php endif; ?>
                <a href="announcements.php" class="list-group-item"><i class="fas fa-bullhorn"></i><span>Announcements</span></a>
                <a href="settings.php" class="list-group-item"><i class="fas fa-cog"></i><span>Settings</span></a>
                <a href="../logout.php" class="list-group-item" onclick="return confirm('Are you sure?');"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a>
            </div>
        </div>

        <!-- Main Content -->
        <div class="flex-grow-1">
            <nav class="navbar navbar-light bg-light px-4 py-3">
                <span class="navbar-brand">Pending Approvals</span>
                <div class="ms-auto">
                    <span><i class="fas fa-user-circle me-2"></i><?php echo htmlspecialchars($current_user['full_name'] ?? $current_user['username']); ?></span>
                </div>
            </nav>

            <div class="container-fluid p-4">
                <!-- Navigation Dropdown -->
                <div class="dropdown-nav">
                    <div class="btn-group">
                        <a href="pending_users.php" class="btn btn-primary active">
                            <i class="fas fa-user-plus me-1"></i> User Registrations
                            <?php if ($user_pending_count > 0): ?>
                                <span class="badge bg-light text-dark ms-1"><?php echo $user_pending_count; ?></span>
                            <?php endif; ?>
                        </a>
                        <a href="pending_photos.php" class="btn btn-outline-primary">
                            <i class="fas fa-camera me-1"></i> Photo Requests
                            <?php if ($photo_pending_count > 0): ?>
                                <span class="badge bg-danger ms-1"><?php echo $photo_pending_count; ?></span>
                            <?php endif; ?>
                        </a>
                    </div>
                </div>

                <!-- Toast Notification Container -->
                <div class="toast-container position-fixed top-0 end-0 p-3">
                    <div id="successToast" class="toast align-items-center text-white bg-success border-0" role="alert">
                        <div class="d-flex">
                            <div class="toast-body">
                                <i class="fas fa-check-circle me-2"></i>
                                <span id="successMessage"></span>
                            </div>
                            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                        </div>
                    </div>
                    <div id="errorToast" class="toast align-items-center text-white bg-danger border-0" role="alert">
                        <div class="d-flex">
                            <div class="toast-body">
                                <i class="fas fa-exclamation-circle me-2"></i>
                                <span id="errorMessage"></span>
                            </div>
                            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                        </div>
                    </div>
                </div>

                <?php if ($message): ?>
                    <div class="alert alert-success alert-dismissible fade show"><?php echo $message; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
                <?php endif; ?>
                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show"><?php echo $error; ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
                <?php endif; ?>

                <!-- Action Toolbar -->
                <div class="action-toolbar">
                    <div class="search-group">
                        <form method="GET" class="d-flex w-100 gap-2">
                            <input type="text" name="search" class="search-input" 
                                   placeholder="Search by name, username, email or member code..." 
                                   value="<?php echo htmlspecialchars($search); ?>">
                            <button type="submit" class="btn-search">
                                <i class="fas fa-search"></i> Search
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Filter Bar - Matching members.php style -->
                <div class="filter-bar">
                    <form method="GET" id="filterForm" class="filter-row">
                        <div class="filter-item">
                            <label>Name Filter</label>
                            <input type="text" name="filter_name" class="form-control" 
                                   placeholder="Filter by name" value="<?php echo htmlspecialchars($filter_name); ?>">
                        </div>
                        
                        <div class="filter-item">
                            <label>Barangay</label>
                            <select name="filter_barangay" class="form-select">
                                <option value="">All Barangays</option>
                                <?php foreach ($barangay_list as $brgy): ?>
                                    <option value="<?php echo htmlspecialchars($brgy); ?>" <?php echo isset($_GET['filter_barangay']) && $_GET['filter_barangay'] == $brgy ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($brgy); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="filter-item">
                            <label>Chapter</label>
                            <select name="filter_chapter" class="form-select">
                                <option value="">All Chapters</option>
                                <?php foreach ($chapter_list as $chap): ?>
                                    <option value="<?php echo htmlspecialchars($chap); ?>" <?php echo $filter_chapter == $chap ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($chap); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="filter-item">
                            <label>Group</label>
                            <select name="filter_group" class="form-select">
                                <option value="">All Groups</option>
                                <?php foreach ($group_list as $grp): ?>
                                    <option value="<?php echo htmlspecialchars($grp); ?>" <?php echo isset($_GET['filter_group']) && $_GET['filter_group'] == $grp ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($grp); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="filter-item">
                            <label>Per Page</label>
                            <select name="limit" class="form-select" onchange="this.form.submit()">
                                <?php foreach ($allowed_limits as $opt): ?>
                                    <option value="<?php echo $opt; ?>" <?php echo $limit == $opt ? 'selected' : ''; ?>><?php echo $opt; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="filter-actions">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-filter me-1"></i> Apply</button>
                            <a href="pending_users.php" class="btn btn-secondary"><i class="fas fa-undo me-1"></i> Reset</a>
                        </div>
                    </form>
                </div>

                <!-- Main Card -->
                <div class="card">
                    <div class="card-header">
                        <h5><i class="fas fa-user-plus me-2"></i>User Registration Requests (<?php echo number_format($total_records); ?> pending)</h5>
                    </div>
                    <div class="card-body p-0">
                        <?php if (empty($pending_users)): ?>
                            <div class="text-center py-5">
                                <i class="fas fa-check-circle fa-4x text-success mb-3"></i>
                                <p class="text-muted">No pending registration requests.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead class="table-light">
                                        
                                            <th>Member Code</th>
                                            <th>Photo</th>
                                            <th>Name</th>
                                            <th>Username</th>
                                            <th>Chapter</th>
                                            <th>Email</th>
                                            <th>Requested</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($pending_users as $u): ?>
                                        <tr>
                                            <td><span class="badge bg-secondary"><?php echo htmlspecialchars($u['member_code'] ?? 'N/A'); ?></span></td>
                                            <td>
                                                <?php if (!empty($u['registration_photo'])): ?>
                                                    <img src="../<?php echo htmlspecialchars($u['registration_photo']); ?>" 
                                                         style="width: 40px; height: 40px; object-fit: cover; border-radius: 8px; cursor: pointer;" 
                                                         onclick="viewDetails(<?php echo $u['id']; ?>)"
                                                         title="Click to view full details">
                                                <?php else: ?>
                                                    <div style="width: 40px; height: 40px; background: #f0f0f0; border-radius: 8px; display: flex; align-items: center; justify-content: center;">
                                                        <i class="fas fa-user-circle text-secondary" style="font-size: 24px;"></i>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <strong><?php echo htmlspecialchars(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')); ?></strong>
                                                <?php if (!empty($u['middle_name'])): ?>
                                                    <br><small class="text-muted"><?php echo htmlspecialchars($u['middle_name']); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($u['username']); ?></td>
                                            <td><?php echo !empty($u['chapter']) ? '<span class="badge bg-info">' . htmlspecialchars($u['chapter']) . '</span>' : '<span class="text-muted">—</span>'; ?></td>
                                            <td><?php echo htmlspecialchars($u['email']); ?></td>
                                            <td><small><?php echo date('M d, Y H:i', strtotime($u['requested_at'])); ?></small></td>
                                            <td class="action-buttons">
                                                <div class="dropdown action-dropdown">
                                                    <button class="btn btn-sm btn-outline-secondary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                                        <i class="fas fa-ellipsis-h"></i>
                                                    </button>
                                                    <ul class="dropdown-menu dropdown-menu-end">
                                                        <li><a class="dropdown-item" href="#" onclick="viewDetails(<?php echo $u['id']; ?>); return false;"><i class="fas fa-eye text-info me-2"></i>View Details</a></li>
                                                        <li><hr class="dropdown-divider"></li>
                                                        <li>
                                                            <form method="POST" style="display: inline-block; width: 100%;">
                                                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                                <input type="hidden" name="pending_id" value="<?php echo $u['id']; ?>">
                                                                <button type="submit" name="action" value="approve" class="dropdown-item text-success" onclick="return confirm('Approve this user? This will create a member record.')">
                                                                    <i class="fas fa-check-circle me-2"></i>Approve
                                                                </button>
                                                            </form>
                                                        </li>
                                                        <li>
                                                            <form method="POST" style="display: inline-block; width: 100%;">
                                                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                                <input type="hidden" name="pending_id" value="<?php echo $u['id']; ?>">
                                                                <button type="submit" name="action" value="reject" class="dropdown-item text-warning" onclick="return confirm('Reject this request?')">
                                                                    <i class="fas fa-times-circle me-2"></i>Reject
                                                                </button>
                                                            </form>
                                                        </li>
                                                        <li>
                                                            <form method="POST" style="display: inline-block; width: 100%;">
                                                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                                <input type="hidden" name="pending_id" value="<?php echo $u['id']; ?>">
                                                                <button type="submit" name="action" value="delete" class="dropdown-item text-danger" onclick="return confirm('Delete this request permanently?')">
                                                                    <i class="fas fa-trash-alt me-2"></i>Delete
                                                                </button>
                                                            </form>
                                                        </li>
                                                    </ul>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            
                            <!-- Pagination -->
                            <?php if ($total_pages > 1): ?>
                            <div class="pagination-wrapper">
                                <div class="text-muted small">
                                    <i class="fas fa-database me-1"></i> 
                                    Showing <?php echo (($page - 1) * $limit) + 1; ?> to <?php echo min($page * $limit, $total_records); ?> of <?php echo number_format($total_records); ?> records
                                </div>
                                <nav>
                                    <ul class="pagination pagination-sm mb-0">
                                        <?php if ($page > 1): ?>
                                        <li class="page-item">
                                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>">
                                                <i class="fas fa-angle-double-left"></i>
                                            </a>
                                        </li>
                                        <li class="page-item">
                                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">
                                                <i class="fas fa-angle-left"></i>
                                            </a>
                                        </li>
                                        <?php endif; ?>
                                        
                                        <?php 
                                        $start = max(1, $page - 2);
                                        $end = min($total_pages, $page + 2);
                                        for ($i = $start; $i <= $end; $i++): 
                                        ?>
                                        <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"><?php echo $i; ?></a>
                                        </li>
                                        <?php endfor; ?>
                                        
                                        <?php if ($page < $total_pages): ?>
                                        <li class="page-item">
                                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">
                                                <i class="fas fa-angle-right"></i>
                                            </a>
                                        </li>
                                        <li class="page-item">
                                            <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>">
                                                <i class="fas fa-angle-double-right"></i>
                                            </a>
                                        </li>
                                        <?php endif; ?>
                                    </ul>
                                </nav>
                            </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- View Details Modal -->
    <div class="modal fade" id="viewDetailsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header bg-info text-white py-2">
                    <h5 class="modal-title"><i class="fas fa-file-alt me-2"></i>Application for Membership - Pending Approval</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-4" id="detailsContent">
                    <div class="text-center p-5">
                        <div class="spinner-border text-info" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        function viewDetails(id) {
            const modal = new bootstrap.Modal(document.getElementById('viewDetailsModal'));
            
            document.getElementById('detailsContent').innerHTML = `
                <div class="text-center p-5">
                    <div class="spinner-border text-info" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
            `;
            
            modal.show();
            
            fetch(`get_pending_details.php?id=${id}`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Network response was not ok');
                    }
                    return response.json();
                })
                .then(data => {
                    // Format dates
                    const birthDate = data.birth_date && data.birth_date !== '0000-00-00' ? new Date(data.birth_date).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) : 'N/A';
                    const screeningDate = data.screening_date && data.screening_date !== '0000-00-00' ? new Date(data.screening_date).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) : 'N/A';
                    const dateRegistered = data.date_registered && data.date_registered !== '0000-00-00' ? new Date(data.date_registered).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) : 'N/A';
                    const dateJoined = data.date_joined && data.date_joined !== '0000-00-00' ? new Date(data.date_joined).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) : 'N/A';
                    const requestedAt = data.requested_at ? new Date(data.requested_at).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric', hour: '2-digit', minute: '2-digit' }) : 'N/A';
                    
                    const fatherName = [data.father_fname, data.father_mname, data.father_lname].filter(Boolean).join(' ');
                    const motherName = [data.mother_fname, data.mother_mname, data.mother_lname].filter(Boolean).join(' ');
                    const spouseName = [data.spouse_fname, data.spouse_mname, data.spouse_lname].filter(Boolean).join(' ');
                    
                    const child1Name = [data.child1_fname, data.child1_mname, data.child1_lname].filter(Boolean).join(' ');
                    const child2Name = [data.child2_fname, data.child2_mname, data.child2_lname].filter(Boolean).join(' ');
                    const child3Name = [data.child3_fname, data.child3_mname, data.child3_lname].filter(Boolean).join(' ');
                    const child4Name = [data.child4_fname, data.child4_mname, data.child4_lname].filter(Boolean).join(' ');
                    
                    const fullAddress = [data.street, data.barangay ? 'Brgy. ' + data.barangay : '', data.city, data.province].filter(Boolean).join(', ');
                    
                    const photoUrl = data.registration_photo ? '../' + data.registration_photo : null;
                    
                    let html = `
                        <div class="application-form">
                            <!-- Logo and Header Section -->
                            <div class="logo-section">
                                <img src="../assets/images/harana-logo.png" alt="Harana Logo" onerror="this.style.display='none'; this.parentNode.querySelector('.logo-placeholder').style.display='flex';">
                                <div class="logo-placeholder" style="display: none;">
                                    <i class="fas fa-hand-holding-heart fa-3x"></i>
                                </div>
                                <div class="text-content">
                                    <h2>NAGKAISANG HIRANISTA</h2>
                                    <h5>SA GINTONG LUZON, PHILS. INC. (NHGL, INC.)</h5>
                                    <p class="small">(Formerly Nagkaisang Hiranista Sa Gintong Luzon, Inc.)</p>
                                    <p class="small">(Sec. REG No. CN 700172104)</p>
                                    <p class="small">MF 2024<br>Bryg. Singalat, Palayan City<br>Province of Nueva Ecija<br>Tel. No. (044)940-6708</p>
                                </div>
                            </div>

                            <h4 class="form-title">APPLICATION FOR MEMBERSHIP</h4>
                            
                            <!-- Pending Status Badge -->
                            <div class="text-center mb-3">
                                <span class="badge bg-warning text-dark px-3 py-2"><i class="fas fa-clock me-2"></i>PENDING APPROVAL</span>
                                <br><small class="text-muted">Requested on: ${requestedAt}</small>
                            </div>

                            <div class="documents-section">
                                <h4>Documents Attached:</h4>
                                <div class="checkbox-group">
                                    <div class="checkbox-item">
                                        <input type="checkbox" ${data.medical_certificate == 1 ? 'checked' : ''} disabled>
                                        <label>Medical Certificate</label>
                                    </div>
                                    <div class="checkbox-item">
                                        <input type="checkbox" ${data.birth_certificate == 1 ? 'checked' : ''} disabled>
                                        <label>Birth Certificate</label>
                                    </div>
                                </div>
                            </div>`;
                    
                    // Add photo if exists
                    if (photoUrl) {
                        html += `
                            <div class="photo-section text-center">
                                <img src="${photoUrl}" alt="Applicant Photo" class="pending-photo" onerror="this.style.display='none'">
                                <div><small class="text-muted">Submitted ID Picture (2x2)</small></div>
                            </div>`;
                    }

                    html += `
                            <div class="section-title">I. PERSONAL INFORMATION</div>
                            
                            <table class="info-table">
                                 <tr>
                                    <td class="label">Member Code:</td>
                                    <td>${data.member_code || 'Auto-generated'}</td>
                                    <td class="label">Date Joined:</td>
                                    <td>${dateJoined}</td>
                                    <td class="label">Monthly Contribution:</td>
                                    <td>₱${parseFloat(data.monthly_contribution || 0).toFixed(2)}</td>
                                </tr>
                                <tr>
                                    <td class="label">Last Name:</td>
                                    <td>${data.last_name || ''}</td>
                                    <td class="label">First Name:</td>
                                    <td>${data.first_name || ''}</td>
                                    <td class="label">Middle Name:</td>
                                    <td>${data.middle_name || ''}</td>
                                </tr>
                                <tr>
                                    <td class="label">Date of Birth:</td>
                                    <td>${birthDate}</td>
                                    <td class="label">Place of Birth:</td>
                                    <td>${data.place_of_birth || ''}</td>
                                    <td class="label">Age:</td>
                                    <td>${data.age || ''}</td>
                                </tr>
                                <tr>
                                    <td class="label">Gender:</td>
                                    <td>${data.gender || ''}</td>
                                    <td class="label">Civil Status:</td>
                                    <td>${data.civil_status || ''}</td>
                                    <td class="label">Religion:</td>
                                    <td>${data.religion || ''}</td>
                                </tr>
                            </table>

                            <div class="section-title">II. ADDRESS INFORMATION</div>
                            <table class="info-table">
                                <tr>
                                    <td class="label">Present Address:</td>
                                    <td colspan="5">${data.present_address || fullAddress}</td>
                                </tr>
                                <tr>
                                    <td class="label">Permanent Address:</td>
                                    <td colspan="5">${data.permanent_address || fullAddress}</td>
                                </tr>
                            </table>

                            <div class="section-title">III. CONTACT INFORMATION</div>
                            <table class="info-table">
                                <tr>
                                    <td class="label">Contact Number:</td>
                                    <td>${data.contact_number || ''}</td>
                                    <td class="label">Alternate Number:</td>
                                    <td colspan="3">${data.alternate_number || ''}</td>
                                </tr>
                                <tr>
                                    <td class="label">Email Address:</td>
                                    <td colspan="5">${data.email || ''}</td>
                                </tr>
                            </table>

                            <div class="section-title">IV. FAMILY BACKGROUND</div>
                            <table class="info-table">
                                <tr>
                                    <td class="label">Father's Name:</td>
                                    <td colspan="2">${fatherName}</td>
                                    <td class="label">Mother's Name:</td>
                                    <td colspan="2">${motherName}</td>
                                </tr>
                                <tr>
                                    <td class="label">Spouse's Name:</td>
                                    <td colspan="3">${spouseName}</td>
                                    <td class="label">Age:</td>
                                    <td>${data.spouse_age || ''}</td>
                                </tr>
                            </table>

                            <div class="section-title">V. CHILDREN</div>
                            <table class="info-table">
                                <tr><td class="label">1.</td><td colspan="3">${child1Name}</td><td class="label">Age:</td><td>${data.child1_age || ''}</td></tr>
                                <tr><td class="label">2.</td><td colspan="3">${child2Name}</td><td class="label">Age:</td><td>${data.child2_age || ''}</td></tr>
                                <tr><td class="label">3.</td><td colspan="3">${child3Name}</td><td class="label">Age:</td><td>${data.child3_age || ''}</td></tr>
                                <tr><td class="label">4.</td><td colspan="3">${child4Name}</td><td class="label">Age:</td><td>${data.child4_age || ''}</td></tr>
                            </table>

                            <div class="section-title">VI. CHARACTER REFERENCES</div>
                            <table class="info-table">
                                <tr><td class="label">1.</td><td>${data.ref1_name || ''}</td><td class="label">Contact:</td><td colspan="3">${data.ref1_contact || ''}</td></tr>
                                <tr><td class="label">2.</td><td>${data.ref2_name || ''}</td><td class="label">Contact:</td><td colspan="3">${data.ref2_contact || ''}</td></tr>
                            </table>

                            <div class="section-title">VII. BENEFICIARY INFORMATION</div>
                            <table class="info-table">
                                <tr><td class="label">Name:</td><td colspan="5">${data.beneficiary_name || ''}</td></tr>
                                <tr><td class="label">Address:</td><td colspan="5">${data.beneficiary_address || ''}</td></tr>
                                <tr><td class="label">Relationship:</td><td>${data.beneficiary_relation || ''}</td><td class="label">Age:</td><td>${data.beneficiary_age || ''}</td><td class="label">Contact:</td><td>${data.beneficiary_contact || ''}</td></tr>
                            </table>

                            <div class="section-title">VIII. CHAPTER INFORMATION</div>
                            <table class="info-table">
                                <tr><td class="label">Chapter:</td><td>${data.chapter || ''}</td><td class="label">Group Name:</td><td colspan="3">${data.group_name || ''}</td></tr>
                                <tr><td class="label">Leader:</td><td>${data.leader || ''}</td><td class="label">Coordinator:</td><td colspan="3">${data.coordinator || ''}</td></tr>
                                <tr><td class="label">Chairman:</td><td>${data.chairman || ''}</td><td class="label">Screening Officer:</td><td colspan="3">${data.screening_officer || ''}</td></tr>
                                <tr><td class="label">Screening Date:</td><td>${screeningDate}</td><td class="label">Approved By:</td><td colspan="3">${data.approved_by || ''}</td></tr>
                                <tr><td class="label">Date Registered:</td><td>${dateRegistered}</td><td class="label">Status:</td><td colspan="3"><span class="badge bg-warning">Pending</span></td></tr>
                            </table>

                            <div class="section-title">IX. ACCOUNT INFORMATION</div>
                            <table class="info-table">
                                <tr><td class="label">Username:</td><td colspan="5">${data.username || 'Not set'}</td></tr>
                            </table>
                        </div>
                    `;
                    
                    document.getElementById('detailsContent').innerHTML = html;
                })
                .catch(error => {
                    document.getElementById('detailsContent').innerHTML = `
                        <div class="alert alert-danger m-3">
                            <i class="fas fa-exclamation-circle me-2"></i>
                            Error loading details: ${error.message}
                        </div>
                    `;
                });
        }

        document.addEventListener('DOMContentLoaded', function() {
            <?php if ($message): ?>
            var toast = new bootstrap.Toast(document.getElementById('successToast'));
            document.getElementById('successMessage').textContent = '<?php echo addslashes($message); ?>';
            toast.show();
            <?php endif; ?>
            
            <?php if ($error): ?>
            var toast = new bootstrap.Toast(document.getElementById('errorToast'));
            document.getElementById('errorMessage').textContent = '<?php echo addslashes($error); ?>';
            toast.show();
            <?php endif; ?>
        });
        
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