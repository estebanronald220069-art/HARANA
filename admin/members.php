<?php
// admin/members.php
// Enable error reporting for debugging (remove after fixing)
error_reporting(E_ALL);
ini_set('display_errors', 1);
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

// Get council members for dropdowns
$council_members = $db->getAll("SELECT council_id, first_name, last_name, middle_name, position FROM council WHERE status = 'active' ORDER BY last_name ASC");

// Get unique chapters from members table (only active members)
$chapters_result = $db->getAll("SELECT DISTINCT chapter FROM members WHERE status = 'active' AND chapter IS NOT NULL AND chapter != '' ORDER BY chapter ASC");
$chapters = [];
foreach ($chapters_result as $chap) {
    $chapters[] = $chap['chapter'];
}

// Get unique barangays for filter
$barangays_result = $db->getAll("SELECT DISTINCT barangay FROM members WHERE status = 'active' AND barangay IS NOT NULL AND barangay != '' ORDER BY barangay ASC");
$barangays = [];
foreach ($barangays_result as $brgy) {
    $barangays[] = $brgy['barangay'];
}

// Get unique groups for filter
$groups_result = $db->getAll("SELECT DISTINCT group_name FROM members WHERE status = 'active' AND group_name IS NOT NULL AND group_name != '' ORDER BY group_name ASC");
$groups = [];
foreach ($groups_result as $grp) {
    $groups[] = $grp['group_name'];
}

// City data for JavaScript
$cities_data = [
    'Occidental Mindoro' => ['San Jose', 'Mamburao', 'Sablayan', 'Calintaan', 'Rizal', 'Abra de Ilog', 'Paluan', 'Santa Cruz'],
    'Nueva Ecija' => ['Palayan City', 'Cabanatuan', 'Gapan', 'San Jose', 'Science City of Muñoz', 'Guimba', 'Talavera', 'San Leonardo', 'Santa Rosa', 'General Tinio'],
    'Pampanga' => ['San Fernando', 'Angeles City', 'Mabalacat', 'Mexico', 'Arayat', 'Candaba', 'Lubao', 'Porac', 'Floridablanca', 'Guagua'],
    'Bulacan' => ['Malolos', 'Meycauayan', 'San Jose del Monte', 'Baliuag', 'Marilao', 'Bocaue', 'Santa Maria', 'Pulilan', 'Plaridel', 'Norzagaray'],
    'Tarlac' => ['Tarlac City', 'Concepcion', 'Capas', 'Paniqui', 'Gerona', 'Camiling', 'Moncada', 'Victoria', 'San Jose', 'La Paz']
];

$message = '';
$error = '';

// View mode: 'members' or history type
$view_mode = isset($_GET['view']) ? Security::sanitize($_GET['view']) : 'members';
$allowed_views = ['members', 'edit_history', 'inactive_history', 'deceased_history', 'delete_history'];
if (!in_array($view_mode, $allowed_views)) {
    $view_mode = 'members';
}

// Pagination and filter parameters
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 30;
$search = isset($_GET['search']) ? Security::sanitize($_GET['search']) : '';
$filter_name = isset($_GET['filter_name']) ? Security::sanitize($_GET['filter_name']) : '';
$filter_barangay = isset($_GET['filter_barangay']) ? Security::sanitize($_GET['filter_barangay']) : '';
$filter_chapter = isset($_GET['filter_chapter']) ? Security::sanitize($_GET['filter_chapter']) : '';
$filter_group = isset($_GET['filter_group']) ? Security::sanitize($_GET['filter_group']) : '';
$sort_by = isset($_GET['sort_by']) ? Security::sanitize($_GET['sort_by']) : 'created_at';
$sort_order = isset($_GET['sort_order']) ? Security::sanitize($_GET['sort_order']) : 'DESC';

// History filters
$history_date_from = isset($_GET['history_date_from']) ? $_GET['history_date_from'] : '';
$history_date_to = isset($_GET['history_date_to']) ? $_GET['history_date_to'] : '';
$history_member = isset($_GET['history_member']) ? Security::sanitize($_GET['history_member']) : '';
$history_admin = isset($_GET['history_admin']) ? Security::sanitize($_GET['history_admin']) : '';

// Validate limit
$allowed_limits = [10, 25, 30, 50, 100, 200];
if (!in_array($limit, $allowed_limits)) {
    $limit = 30;
}

// Validate sort columns
$allowed_sort = ['member_code', 'first_name', 'last_name', 'chapter', 'group_name', 'barangay', 'contact_number', 'status', 'created_at'];
if (!in_array($sort_by, $allowed_sort)) {
    $sort_by = 'created_at';
}

$sort_order = strtoupper($sort_order) === 'ASC' ? 'ASC' : 'DESC';

// Variables for displaying data
$members = [];
$total_records = 0;
$total_pages = 0;
$history_records = [];
$history_total = 0;

// Determine which data to fetch based on view mode
if ($view_mode === 'members') {
    // Build WHERE conditions for active members only
    $where_conditions = ["status = 'active'"];
    $params = [];
    $types = '';

    // Search condition
    if (!empty($search)) {
        $search_terms = explode(' ', $search);
        $search_conditions = [];
        foreach ($search_terms as $term) {
            if (!empty(trim($term))) {
                $term = trim($term);
                $search_conditions[] = "(CONCAT(first_name, ' ', last_name) LIKE ? OR 
                                          CONCAT(first_name, ' ', middle_name, ' ', last_name) LIKE ? OR 
                                          first_name LIKE ? OR 
                                          last_name LIKE ? OR 
                                          member_code LIKE ? OR 
                                          barangay LIKE ? OR 
                                          chapter LIKE ? OR 
                                          contact_number LIKE ?)";
                $like_term = "%$term%";
                for ($i = 0; $i < 8; $i++) {
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

    // Barangay filter
    if (!empty($filter_barangay)) {
        $where_conditions[] = "barangay = ?";
        $params[] = $filter_barangay;
        $types .= 's';
    }

    // Chapter filter
    if (!empty($filter_chapter)) {
        $where_conditions[] = "chapter = ?";
        $params[] = $filter_chapter;
        $types .= 's';
    }

    // Group filter
    if (!empty($filter_group)) {
        $where_conditions[] = "group_name = ?";
        $params[] = $filter_group;
        $types .= 's';
    }

    $where_clause = "WHERE " . implode(" AND ", $where_conditions);

    // Get total records count
    $count_sql = "SELECT COUNT(*) as total FROM members $where_clause";
    $count_result = $db->getSingle($count_sql, $params, $types);
    $total_records = $count_result['total'] ?? 0;

    // Calculate pagination
    $total_pages = ceil($total_records / $limit);
    $offset = ($page - 1) * $limit;

    if ($page < 1) $page = 1;
    if ($page > $total_pages && $total_pages > 0) $page = $total_pages;

    // Fetch members
    $sql = "SELECT * FROM members $where_clause ORDER BY $sort_by $sort_order LIMIT ? OFFSET ?";
    $params[] = $limit;
    $params[] = $offset;
    $types .= 'ii';

    $members = $db->getAll($sql, $params, $types);
    
} elseif ($view_mode === 'edit_history') {
    // Fetch edit history from audit log
    $history_conditions = ["action_type IN ('add', 'edit')"];
    $history_params = [];
    $history_types = '';
    
    if (!empty($history_date_from)) {
        $history_conditions[] = "DATE(created_at) >= ?";
        $history_params[] = $history_date_from;
        $history_types .= 's';
    }
    if (!empty($history_date_to)) {
        $history_conditions[] = "DATE(created_at) <= ?";
        $history_params[] = $history_date_to;
        $history_types .= 's';
    }
    if (!empty($history_member)) {
        $history_conditions[] = "(member_code LIKE ? OR CONCAT(first_name, ' ', last_name) LIKE ?)";
        $like_member = "%$history_member%";
        $history_params[] = $like_member;
        $history_params[] = $like_member;
        $history_types .= 'ss';
    }
    if (!empty($history_admin)) {
        $history_conditions[] = "admin_name LIKE ?";
        $history_params[] = "%$history_admin%";
        $history_types .= 's';
    }
    
    $history_where = !empty($history_conditions) ? "WHERE " . implode(" AND ", $history_conditions) : "";
    
    $history_count_sql = "SELECT COUNT(*) as total FROM member_audit_log $history_where";
    $history_count_result = $db->getSingle($history_count_sql, $history_params, $history_types);
    $history_total = $history_count_result['total'] ?? 0;
    
    $history_total_pages = ceil($history_total / $limit);
    $history_offset = ($page - 1) * $limit;
    
    $history_sql = "SELECT * FROM member_audit_log $history_where ORDER BY created_at DESC LIMIT ? OFFSET ?";
    $history_params[] = $limit;
    $history_params[] = $history_offset;
    $history_types .= 'ii';
    
    $history_records = $db->getAll($history_sql, $history_params, $history_types);
    $total_pages = $history_total_pages;
    $total_records = $history_total;
    
} elseif ($view_mode === 'inactive_history') {
    // Fetch inactive members from members table
    $history_conditions = ["status = 'inactive'"];
    $history_params = [];
    $history_types = '';
    
    if (!empty($history_date_from)) {
        $history_conditions[] = "DATE(inactive_date) >= ?";
        $history_params[] = $history_date_from;
        $history_types .= 's';
    }
    if (!empty($history_date_to)) {
        $history_conditions[] = "DATE(inactive_date) <= ?";
        $history_params[] = $history_date_to;
        $history_types .= 's';
    }
    if (!empty($history_member)) {
        $history_conditions[] = "(member_code LIKE ? OR CONCAT(first_name, ' ', last_name) LIKE ?)";
        $like_member = "%$history_member%";
        $history_params[] = $like_member;
        $history_params[] = $like_member;
        $history_types .= 'ss';
    }
    
    $history_where = !empty($history_conditions) ? "WHERE " . implode(" AND ", $history_conditions) : "";
    
    $history_count_sql = "SELECT COUNT(*) as total FROM members $history_where";
    $history_count_result = $db->getSingle($history_count_sql, $history_params, $history_types);
    $history_total = $history_count_result['total'] ?? 0;
    
    $history_total_pages = ceil($history_total / $limit);
    $history_offset = ($page - 1) * $limit;
    
    $history_sql = "SELECT * FROM members $history_where ORDER BY inactive_date DESC LIMIT ? OFFSET ?";
    $history_params[] = $limit;
    $history_params[] = $history_offset;
    $history_types .= 'ii';
    
    $history_records = $db->getAll($history_sql, $history_params, $history_types);
    $total_pages = $history_total_pages;
    $total_records = $history_total;
    
} elseif ($view_mode === 'deceased_history') {
    // Fetch deceased members with death details
    $history_conditions = ["m.status = 'deceased'"];
    $history_params = [];
    $history_types = '';
    
    if (!empty($history_date_from)) {
        $history_conditions[] = "DATE(d.death_date) >= ?";
        $history_params[] = $history_date_from;
        $history_types .= 's';
    }
    if (!empty($history_date_to)) {
        $history_conditions[] = "DATE(d.death_date) <= ?";
        $history_params[] = $history_date_to;
        $history_types .= 's';
    }
    if (!empty($history_member)) {
        $history_conditions[] = "(m.member_code LIKE ? OR CONCAT(m.first_name, ' ', m.last_name) LIKE ?)";
        $like_member = "%$history_member%";
        $history_params[] = $like_member;
        $history_params[] = $like_member;
        $history_types .= 'ss';
    }
    
    $history_where = !empty($history_conditions) ? "WHERE " . implode(" AND ", $history_conditions) : "";
    
    $history_count_sql = "SELECT COUNT(*) as total FROM members m 
                          LEFT JOIN deceased_details d ON m.member_code = d.member_code 
                          $history_where";
    $history_count_result = $db->getSingle($history_count_sql, $history_params, $history_types);
    $history_total = $history_count_result['total'] ?? 0;
    
    $history_total_pages = ceil($history_total / $limit);
    $history_offset = ($page - 1) * $limit;
    
    $history_sql = "SELECT m.*, d.*, d.death_date as death_date_record 
                    FROM members m 
                    LEFT JOIN deceased_details d ON m.member_code = d.member_code 
                    $history_where 
                    ORDER BY d.death_date DESC LIMIT ? OFFSET ?";
    $history_params[] = $limit;
    $history_params[] = $history_offset;
    $history_types .= 'ii';
    
    $history_records = $db->getAll($history_sql, $history_params, $history_types);
    $total_pages = $history_total_pages;
    $total_records = $history_total;
    
} elseif ($view_mode === 'delete_history') {
    // Fetch soft-deleted members
    $history_conditions = ["1=1"];
    $history_params = [];
    $history_types = '';
    
    if (!empty($history_date_from)) {
        $history_conditions[] = "DATE(deleted_at) >= ?";
        $history_params[] = $history_date_from;
        $history_types .= 's';
    }
    if (!empty($history_date_to)) {
        $history_conditions[] = "DATE(deleted_at) <= ?";
        $history_params[] = $history_date_to;
        $history_types .= 's';
    }
    if (!empty($history_member)) {
        $history_conditions[] = "(member_code LIKE ? OR CONCAT(first_name, ' ', last_name) LIKE ?)";
        $like_member = "%$history_member%";
        $history_params[] = $like_member;
        $history_params[] = $like_member;
        $history_types .= 'ss';
    }
    
    $history_where = !empty($history_conditions) ? "WHERE " . implode(" AND ", $history_conditions) : "";
    
    $history_count_sql = "SELECT COUNT(*) as total FROM deleted_members $history_where";
    $history_count_result = $db->getSingle($history_count_sql, $history_params, $history_types);
    $history_total = $history_count_result['total'] ?? 0;
    
    $history_total_pages = ceil($history_total / $limit);
    $history_offset = ($page - 1) * $limit;
    
    $history_sql = "SELECT * FROM deleted_members $history_where ORDER BY deleted_at DESC LIMIT ? OFFSET ?";
    $history_params[] = $limit;
    $history_params[] = $history_offset;
    $history_types .= 'ii';
    
    $history_records = $db->getAll($history_sql, $history_params, $history_types);
    $total_pages = $history_total_pages;
    $total_records = $history_total;
}

// Handle POST requests (CRUD operations)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please refresh the page.';
    } else {
        $action = $_POST['action'] ?? '';
if ($action === 'add') {
    // Get all form data
    $member_code = Security::sanitize($_POST['member_code'] ?? '');
    $first_name = Security::sanitize($_POST['first_name'] ?? '');
    $last_name = Security::sanitize($_POST['last_name'] ?? '');
    $middle_name = Security::sanitize($_POST['middle_name'] ?? '');
    
    // Address components
    $province = Security::sanitize($_POST['province'] ?? 'Occidental Mindoro');
    $city = Security::sanitize($_POST['city'] ?? 'San Jose');
    $barangay = Security::sanitize($_POST['barangay'] ?? '');
    $street = Security::sanitize($_POST['street'] ?? '');
    
    // Combine address
    $address_parts = [];
    if (!empty($street)) $address_parts[] = $street;
    if (!empty($barangay)) $address_parts[] = 'Brgy. ' . $barangay;
    if (!empty($city)) $address_parts[] = $city;
    if (!empty($province)) $address_parts[] = $province;
    
    $address = implode(', ', $address_parts);
    $present_address = Security::sanitize($_POST['present_address'] ?? $address);
    $permanent_address = Security::sanitize($_POST['permanent_address'] ?? $address);
    
    $contact_number = Security::sanitize($_POST['contact_number'] ?? '');
    $alternate_number = Security::sanitize($_POST['alternate_number'] ?? '');
    $email = Security::sanitize($_POST['email'] ?? '');
    
    // Personal Information
    $birth_date = !empty($_POST['birth_date']) ? $_POST['birth_date'] : null;
    $place_of_birth = Security::sanitize($_POST['place_of_birth'] ?? '');
    $age = !empty($_POST['age']) ? intval($_POST['age']) : null;
    $gender = Security::sanitize($_POST['gender'] ?? '');
    $civil_status = Security::sanitize($_POST['civil_status'] ?? '');
    $religion = Security::sanitize($_POST['religion'] ?? '');
    
    // Family Information
    $father_fname = Security::sanitize($_POST['father_fname'] ?? '');
    $father_mname = Security::sanitize($_POST['father_mname'] ?? '');
    $father_lname = Security::sanitize($_POST['father_lname'] ?? '');
    
    $mother_fname = Security::sanitize($_POST['mother_fname'] ?? '');
    $mother_mname = Security::sanitize($_POST['mother_mname'] ?? '');
    $mother_lname = Security::sanitize($_POST['mother_lname'] ?? '');
    
    $spouse_fname = Security::sanitize($_POST['spouse_fname'] ?? '');
    $spouse_mname = Security::sanitize($_POST['spouse_mname'] ?? '');
    $spouse_lname = Security::sanitize($_POST['spouse_lname'] ?? '');
    $spouse_age = !empty($_POST['spouse_age']) ? intval($_POST['spouse_age']) : null;
    
    // Children
    $child1_fname = Security::sanitize($_POST['child1_fname'] ?? '');
    $child1_mname = Security::sanitize($_POST['child1_mname'] ?? '');
    $child1_lname = Security::sanitize($_POST['child1_lname'] ?? '');
    $child1_age = !empty($_POST['child1_age']) ? intval($_POST['child1_age']) : null;
    
    $child2_fname = Security::sanitize($_POST['child2_fname'] ?? '');
    $child2_mname = Security::sanitize($_POST['child2_mname'] ?? '');
    $child2_lname = Security::sanitize($_POST['child2_lname'] ?? '');
    $child2_age = !empty($_POST['child2_age']) ? intval($_POST['child2_age']) : null;
    
    $child3_fname = Security::sanitize($_POST['child3_fname'] ?? '');
    $child3_mname = Security::sanitize($_POST['child3_mname'] ?? '');
    $child3_lname = Security::sanitize($_POST['child3_lname'] ?? '');
    $child3_age = !empty($_POST['child3_age']) ? intval($_POST['child3_age']) : null;
    
    $child4_fname = Security::sanitize($_POST['child4_fname'] ?? '');
    $child4_mname = Security::sanitize($_POST['child4_mname'] ?? '');
    $child4_lname = Security::sanitize($_POST['child4_lname'] ?? '');
    $child4_age = !empty($_POST['child4_age']) ? intval($_POST['child4_age']) : null;
    
    // Combined names
    $father_name = trim($father_fname . ' ' . $father_mname . ' ' . $father_lname);
    $mother_name = trim($mother_fname . ' ' . $mother_mname . ' ' . $mother_lname);
    $spouse_name = trim($spouse_fname . ' ' . $spouse_mname . ' ' . $spouse_lname);
    
    $child1_name = trim($child1_fname . ' ' . $child1_mname . ' ' . $child1_lname);
    $child2_name = trim($child2_fname . ' ' . $child2_mname . ' ' . $child2_lname);
    $child3_name = trim($child3_fname . ' ' . $child3_mname . ' ' . $child3_lname);
    $child4_name = trim($child4_fname . ' ' . $child4_mname . ' ' . $child4_lname);
    
    // References
    $ref1_name = Security::sanitize($_POST['ref1_name'] ?? '');
    $ref1_contact = Security::sanitize($_POST['ref1_contact'] ?? '');
    $ref2_name = Security::sanitize($_POST['ref2_name'] ?? '');
    $ref2_contact = Security::sanitize($_POST['ref2_contact'] ?? '');
    
    // Chapter fields
    $chapter = Security::sanitize($_POST['chapter'] ?? '');
    $group_name = Security::sanitize($_POST['group_name'] ?? '');
    $leader = Security::sanitize($_POST['leader'] ?? '');
    $coordinator = Security::sanitize($_POST['coordinator'] ?? '');
    $chairman = Security::sanitize($_POST['chairman'] ?? '');
    $screening_officer = Security::sanitize($_POST['screening_officer'] ?? '');
    $screening_date = !empty($_POST['screening_date']) ? $_POST['screening_date'] : null;
    $approved_by = Security::sanitize($_POST['approved_by'] ?? '');
    $date_joined = !empty($_POST['date_joined']) ? $_POST['date_joined'] : date('Y-m-d');
    $date_registered = !empty($_POST['date_registered']) ? $_POST['date_registered'] : date('Y-m-d');
    $monthly_contribution = isset($_POST['monthly_contribution']) && $_POST['monthly_contribution'] !== '' ? floatval($_POST['monthly_contribution']) : 100.00;
    
    // Beneficiary
    $beneficiary_name = Security::sanitize($_POST['beneficiary_name'] ?? '');
    $beneficiary_address = Security::sanitize($_POST['beneficiary_address'] ?? '');
    $beneficiary_relation = Security::sanitize($_POST['beneficiary_relation'] ?? '');
    $beneficiary_age = !empty($_POST['beneficiary_age']) ? intval($_POST['beneficiary_age']) : null;
    $beneficiary_contact = Security::sanitize($_POST['beneficiary_contact'] ?? '');
    
    // Documents
    $medical_certificate = isset($_POST['medical_certificate']) ? 1 : 0;
    $birth_certificate = isset($_POST['birth_certificate']) ? 1 : 0;
    
    // Account Information
    $username = Security::sanitize($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $hashed_password = !empty($password) ? password_hash($password, PASSWORD_DEFAULT) : null;

    // Validate required fields
    $required_fields = ['first_name', 'last_name', 'contact_number', 'member_code'];
    $missing_fields = [];
    foreach ($required_fields as $field) {
        if (empty($$field)) {
            $missing_fields[] = str_replace('_', ' ', ucfirst($field));
        }
    }
    
    // Validate username/password if provided
    if (!empty($username) || !empty($password)) {
        if (empty($username)) {
            $error = 'Username is required when password is provided.';
        } elseif (empty($password)) {
            $error = 'Password is required when username is provided.';
        } elseif (strlen($password) < 6) {
            $error = 'Password must be at least 6 characters.';
        } elseif ($password !== $confirm_password) {
            $error = 'Passwords do not match.';
        }
    }
    
    if (!empty($missing_fields)) {
        $error = 'Please fill all required fields: ' . implode(', ', $missing_fields);
    } elseif (empty($error)) {
        // Check if member_code already exists
        $existing = $db->getSingle("SELECT member_code FROM members WHERE member_code = ?", [$member_code], 's');
        if ($existing) {
            $error = 'Member code already exists.';
        } 
        // Check if username already exists in members table
        elseif (!empty($username)) {
            $existing_user = $db->getSingle("SELECT username FROM members WHERE username = ?", [$username], 's');
            if ($existing_user) {
                $error = 'Username already exists.';
            }
        }
        
        if (empty($error)) {
            // Begin transaction
            $db->getConnection()->begin_transaction();
            
            try {
                // Insert into members table
                $insert_sql = "INSERT INTO members (
                    member_code, first_name, last_name, middle_name, address, present_address, permanent_address,
                    barangay, city, province, street, contact_number, alternate_number, email, 
                    birth_date, place_of_birth, age, gender, civil_status, religion,
                    father_fname, father_mname, father_lname,
                    mother_fname, mother_mname, mother_lname,
                    spouse_fname, spouse_mname, spouse_lname, spouse_age,
                    child1_fname, child1_mname, child1_lname, child1_age,
                    child2_fname, child2_mname, child2_lname, child2_age,
                    child3_fname, child3_mname, child3_lname, child3_age,
                    child4_fname, child4_mname, child4_lname, child4_age,
                    father_name, mother_name, spouse_name,
                    child1_name, child2_name, child3_name, child4_name,
                    ref1_name, ref1_contact, ref2_name, ref2_contact,
                    chapter, group_name, leader, coordinator, chairman, screening_officer, screening_date, approved_by, 
                    date_joined, date_registered, monthly_contribution, 
                    beneficiary_name, beneficiary_address, beneficiary_relation, beneficiary_age, beneficiary_contact,
                    medical_certificate, birth_certificate, username, password, created_by
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
                )";

                $insert_params = [
                    $member_code, $first_name, $last_name, $middle_name, $address, $present_address, $permanent_address,
                    $barangay, $city, $province, $street, $contact_number, $alternate_number, $email, 
                    $birth_date, $place_of_birth, $age, $gender, $civil_status, $religion,
                    $father_fname, $father_mname, $father_lname,
                    $mother_fname, $mother_mname, $mother_lname,
                    $spouse_fname, $spouse_mname, $spouse_lname, $spouse_age,
                    $child1_fname, $child1_mname, $child1_lname, $child1_age,
                    $child2_fname, $child2_mname, $child2_lname, $child2_age,
                    $child3_fname, $child3_mname, $child3_lname, $child3_age,
                    $child4_fname, $child4_mname, $child4_lname, $child4_age,
                    $father_name, $mother_name, $spouse_name,
                    $child1_name, $child2_name, $child3_name, $child4_name,
                    $ref1_name, $ref1_contact, $ref2_name, $ref2_contact,
                    $chapter, $group_name, $leader, $coordinator, $chairman, $screening_officer, $screening_date, $approved_by, 
                    $date_joined, $date_registered, $monthly_contribution, 
                    $beneficiary_name, $beneficiary_address, $beneficiary_relation, $beneficiary_age, $beneficiary_contact,
                    $medical_certificate, $birth_certificate, $username, $hashed_password, $current_user['user_id']
                ];

                // Verify parameter count matches
                $expected_count = 78;
                $actual_count = count($insert_params);
                if ($actual_count !== $expected_count) {
                    throw new Exception("Parameter count mismatch: Expected $expected_count, got $actual_count");
                }

                // Build type string dynamically
                $insert_types = '';
                foreach ($insert_params as $param) {
                    if (is_int($param)) {
                        $insert_types .= 'i';
                    } elseif (is_float($param)) {
                        $insert_types .= 'd';
                    } else {
                        $insert_types .= 's';
                    }
                }

                $result = $db->execute($insert_sql, $insert_params, $insert_types);
                
               if (!$result) {
    $db_error = $db->getConnection()->error;
    throw new Exception('Failed to recover member: ' . $db_error);
}
                
                // Create member_balances record using member_code
                $balance_result = $db->execute(
                    "INSERT INTO member_balances (member_code, total_paid, total_due, current_balance, last_payment_date, next_due_date, updated_at) 
                     VALUES (?, 0.00, ?, ?, NULL, NULL, NOW())",
                    [$member_code, $monthly_contribution, $monthly_contribution],
                    'sdd'
                );
                
                if (!$balance_result) {
                    throw new Exception('Failed to create member balance record: ' . $db->getConnection()->error);
                }
                
                // Add audit log
                $db->execute(
                    "INSERT INTO member_audit_log (member_code, action_type, changes, admin_user_id, admin_name, ip_address, created_at) 
                     VALUES (?, 'add', ?, ?, ?, ?, NOW())",
                    [$member_code, json_encode($_POST), $current_user['user_id'], $current_user['username'], $_SERVER['REMOTE_ADDR']],
                    'ssiss'
                );
                
                $db->getConnection()->commit();
                $message = 'Member added successfully.';
                Security::logEvent('MEMBER_ADD', "Added member: $member_code");
                header('Location: members.php?success=added' . "&limit=$limit&page=$page");
                exit();
                
            } catch (Exception $e) {
                $db->getConnection()->rollback();
                $error = 'Database error: ' . $e->getMessage();
                error_log('Member add error: ' . $e->getMessage());
                error_log('Stack trace: ' . $e->getTraceAsString());
            }
        }
    }
} elseif ($action === 'edit') {
    // Get all form data for edit
    $member_code = Security::sanitize($_POST['member_code'] ?? '');
    
    $first_name = Security::sanitize($_POST['first_name'] ?? '');
    $last_name = Security::sanitize($_POST['last_name'] ?? '');
    $middle_name = Security::sanitize($_POST['middle_name'] ?? '');
    
    $province = Security::sanitize($_POST['province'] ?? 'Occidental Mindoro');
    $city = Security::sanitize($_POST['city'] ?? 'San Jose');
    $barangay = Security::sanitize($_POST['barangay'] ?? '');
    $street = Security::sanitize($_POST['street'] ?? '');
    
    $address_parts = [];
    if (!empty($street)) $address_parts[] = $street;
    if (!empty($barangay)) $address_parts[] = 'Brgy. ' . $barangay;
    if (!empty($city)) $address_parts[] = $city;
    if (!empty($province)) $address_parts[] = $province;
    
    $address = implode(', ', $address_parts);
    $present_address = Security::sanitize($_POST['present_address'] ?? $address);
    $permanent_address = Security::sanitize($_POST['permanent_address'] ?? $address);
    
    $contact_number = Security::sanitize($_POST['contact_number'] ?? '');
    $alternate_number = Security::sanitize($_POST['alternate_number'] ?? '');
    $email = Security::sanitize($_POST['email'] ?? '');
    
    $birth_date = !empty($_POST['birth_date']) ? $_POST['birth_date'] : null;
    $place_of_birth = Security::sanitize($_POST['place_of_birth'] ?? '');
    $age = !empty($_POST['age']) ? intval($_POST['age']) : null;
    $gender = Security::sanitize($_POST['gender'] ?? '');
    $civil_status = Security::sanitize($_POST['civil_status'] ?? '');
    $religion = Security::sanitize($_POST['religion'] ?? '');
    
    $father_fname = Security::sanitize($_POST['father_fname'] ?? '');
    $father_mname = Security::sanitize($_POST['father_mname'] ?? '');
    $father_lname = Security::sanitize($_POST['father_lname'] ?? '');
    
    $mother_fname = Security::sanitize($_POST['mother_fname'] ?? '');
    $mother_mname = Security::sanitize($_POST['mother_mname'] ?? '');
    $mother_lname = Security::sanitize($_POST['mother_lname'] ?? '');
    
    $spouse_fname = Security::sanitize($_POST['spouse_fname'] ?? '');
    $spouse_mname = Security::sanitize($_POST['spouse_mname'] ?? '');
    $spouse_lname = Security::sanitize($_POST['spouse_lname'] ?? '');
    $spouse_age = !empty($_POST['spouse_age']) ? intval($_POST['spouse_age']) : null;
    
    $child1_fname = Security::sanitize($_POST['child1_fname'] ?? '');
    $child1_mname = Security::sanitize($_POST['child1_mname'] ?? '');
    $child1_lname = Security::sanitize($_POST['child1_lname'] ?? '');
    $child1_age = !empty($_POST['child1_age']) ? intval($_POST['child1_age']) : null;
    
    $child2_fname = Security::sanitize($_POST['child2_fname'] ?? '');
    $child2_mname = Security::sanitize($_POST['child2_mname'] ?? '');
    $child2_lname = Security::sanitize($_POST['child2_lname'] ?? '');
    $child2_age = !empty($_POST['child2_age']) ? intval($_POST['child2_age']) : null;
    
    $child3_fname = Security::sanitize($_POST['child3_fname'] ?? '');
    $child3_mname = Security::sanitize($_POST['child3_mname'] ?? '');
    $child3_lname = Security::sanitize($_POST['child3_lname'] ?? '');
    $child3_age = !empty($_POST['child3_age']) ? intval($_POST['child3_age']) : null;
    
    $child4_fname = Security::sanitize($_POST['child4_fname'] ?? '');
    $child4_mname = Security::sanitize($_POST['child4_mname'] ?? '');
    $child4_lname = Security::sanitize($_POST['child4_lname'] ?? '');
    $child4_age = !empty($_POST['child4_age']) ? intval($_POST['child4_age']) : null;
    
    $father_name = trim($father_fname . ' ' . $father_mname . ' ' . $father_lname);
    $mother_name = trim($mother_fname . ' ' . $mother_mname . ' ' . $mother_lname);
    $spouse_name = trim($spouse_fname . ' ' . $spouse_mname . ' ' . $spouse_lname);
    
    $child1_name = trim($child1_fname . ' ' . $child1_mname . ' ' . $child1_lname);
    $child2_name = trim($child2_fname . ' ' . $child2_mname . ' ' . $child2_lname);
    $child3_name = trim($child3_fname . ' ' . $child3_mname . ' ' . $child3_lname);
    $child4_name = trim($child4_fname . ' ' . $child4_mname . ' ' . $child4_lname);
    
    $ref1_name = Security::sanitize($_POST['ref1_name'] ?? '');
    $ref1_contact = Security::sanitize($_POST['ref1_contact'] ?? '');
    $ref2_name = Security::sanitize($_POST['ref2_name'] ?? '');
    $ref2_contact = Security::sanitize($_POST['ref2_contact'] ?? '');
    
    $chapter = Security::sanitize($_POST['chapter'] ?? '');
    $group_name = Security::sanitize($_POST['group_name'] ?? '');
    $leader = Security::sanitize($_POST['leader'] ?? '');
    $coordinator = Security::sanitize($_POST['coordinator'] ?? '');
    $chairman = Security::sanitize($_POST['chairman'] ?? '');
    $screening_officer = Security::sanitize($_POST['screening_officer'] ?? '');
    $screening_date = !empty($_POST['screening_date']) ? $_POST['screening_date'] : null;
    $approved_by = Security::sanitize($_POST['approved_by'] ?? '');
    $date_joined = !empty($_POST['date_joined']) ? $_POST['date_joined'] : date('Y-m-d');
    $date_registered = !empty($_POST['date_registered']) ? $_POST['date_registered'] : date('Y-m-d');
    $monthly_contribution = isset($_POST['monthly_contribution']) && $_POST['monthly_contribution'] !== '' ? floatval($_POST['monthly_contribution']) : 100.00;
    $status = $_POST['status'] ?? 'active';
    
    $beneficiary_name = Security::sanitize($_POST['beneficiary_name'] ?? '');
    $beneficiary_address = Security::sanitize($_POST['beneficiary_address'] ?? '');
    $beneficiary_relation = Security::sanitize($_POST['beneficiary_relation'] ?? '');
    $beneficiary_age = !empty($_POST['beneficiary_age']) ? intval($_POST['beneficiary_age']) : null;
    $beneficiary_contact = Security::sanitize($_POST['beneficiary_contact'] ?? '');
    
    $medical_certificate = isset($_POST['medical_certificate']) ? 1 : 0;
    $birth_certificate = isset($_POST['birth_certificate']) ? 1 : 0;

    // Get old data for audit log
    $old_data = $db->getSingle("SELECT * FROM members WHERE member_code = ?", [$member_code], 's');

    // Validate required fields
    if (empty($first_name) || empty($last_name) || empty($contact_number)) {
        $error = 'Please fill all required fields (Last Name, First Name, Contact Number).';
    } else {
        $update_sql = "UPDATE members SET 
            first_name = ?, last_name = ?, middle_name = ?, address = ?, present_address = ?, permanent_address = ?,
            barangay = ?, city = ?, province = ?, street = ?, contact_number = ?, alternate_number = ?, email = ?, 
            birth_date = ?, place_of_birth = ?, age = ?, gender = ?, civil_status = ?, religion = ?,
            father_fname = ?, father_mname = ?, father_lname = ?,
            mother_fname = ?, mother_mname = ?, mother_lname = ?,
            spouse_fname = ?, spouse_mname = ?, spouse_lname = ?, spouse_age = ?,
            child1_fname = ?, child1_mname = ?, child1_lname = ?, child1_age = ?,
            child2_fname = ?, child2_mname = ?, child2_lname = ?, child2_age = ?,
            child3_fname = ?, child3_mname = ?, child3_lname = ?, child3_age = ?,
            child4_fname = ?, child4_mname = ?, child4_lname = ?, child4_age = ?,
            father_name = ?, mother_name = ?, spouse_name = ?,
            child1_name = ?, child2_name = ?, child3_name = ?, child4_name = ?,
            ref1_name = ?, ref1_contact = ?, ref2_name = ?, ref2_contact = ?,
            chapter = ?, group_name = ?, leader = ?, coordinator = ?, chairman = ?, screening_officer = ?, screening_date = ?, approved_by = ?, 
            date_joined = ?, date_registered = ?, status = ?, monthly_contribution = ?, 
            beneficiary_name = ?, beneficiary_address = ?, beneficiary_relation = ?, beneficiary_age = ?, beneficiary_contact = ?,
            medical_certificate = ?, birth_certificate = ?, updated_by = ?, updated_at = NOW()
            WHERE member_code = ?";

        $update_params = [
            $first_name, $last_name, $middle_name, $address, $present_address, $permanent_address,
            $barangay, $city, $province, $street, $contact_number, $alternate_number, $email, 
            $birth_date, $place_of_birth, $age, $gender, $civil_status, $religion,
            $father_fname, $father_mname, $father_lname,
            $mother_fname, $mother_mname, $mother_lname,
            $spouse_fname, $spouse_mname, $spouse_lname, $spouse_age,
            $child1_fname, $child1_mname, $child1_lname, $child1_age,
            $child2_fname, $child2_mname, $child2_lname, $child2_age,
            $child3_fname, $child3_mname, $child3_lname, $child3_age,
            $child4_fname, $child4_mname, $child4_lname, $child4_age,
            $father_name, $mother_name, $spouse_name,
            $child1_name, $child2_name, $child3_name, $child4_name,
            $ref1_name, $ref1_contact, $ref2_name, $ref2_contact,
            $chapter, $group_name, $leader, $coordinator, $chairman, $screening_officer, $screening_date, $approved_by, 
            $date_joined, $date_registered, $status, $monthly_contribution, 
            $beneficiary_name, $beneficiary_address, $beneficiary_relation, $beneficiary_age, $beneficiary_contact,
            $medical_certificate, $birth_certificate, $current_user['user_id'], $member_code
        ];
        
        $update_types = '';
        foreach ($update_params as $param) {
            if (is_int($param)) {
                $update_types .= 'i';
            } elseif (is_float($param)) {
                $update_types .= 'd';
            } else {
                $update_types .= 's';
            }
        }

        try {
            $result = $db->execute($update_sql, $update_params, $update_types);
            
            if ($result !== false) {
                // Add audit log for edit
                $changes = [];
                if ($old_data) {
                    foreach ($_POST as $key => $value) {
                        if (isset($old_data[$key]) && $old_data[$key] != $value && !in_array($key, ['csrf_token', 'action', 'member_code'])) {
                            $changes[$key] = ['old' => $old_data[$key], 'new' => $value];
                        }
                    }
                }
                
                $db->execute(
                    "INSERT INTO member_audit_log (member_code, action_type, changes, admin_user_id, admin_name, ip_address, created_at) 
                     VALUES (?, 'edit', ?, ?, ?, ?, NOW())",
                    [$member_code, json_encode($changes), $current_user['user_id'], $current_user['username'], $_SERVER['REMOTE_ADDR']],
                    'ssiss'
                );
                
                $message = 'Member updated successfully.';
                Security::logEvent('MEMBER_UPDATE', "Updated member: $member_code");
                header('Location: members.php?success=updated' . "&limit=$limit&page=$page&view=$view_mode");
                exit();
            } else {
                $error = 'Failed to update member.';
            }
        } catch (Exception $e) {
            $error = 'Database error: ' . $e->getMessage();
            error_log('Member update error: ' . $e->getMessage());
        }
    }
} elseif ($action === 'delete_multiple') {
            // Handle multiple delete
            $member_codes = $_POST['member_codes'] ?? [];
            if (empty($member_codes)) {
                $error = 'No members selected for deletion.';
            } else {
                $db->getConnection()->begin_transaction();
                $deleted_count = 0;
                try {
                    foreach ($member_codes as $member_code) {
                        $member = $db->getSingle("SELECT * FROM members WHERE member_code = ? AND status = 'active'", [$member_code], 's');
                        if ($member) {
                            // Store in deleted_members table
                            $member_data = json_encode($member);
                            $recovery_deadline = date('Y-m-d H:i:s', strtotime('+50 days'));
                            
                            $insert_deleted = $db->execute(
                                "INSERT INTO deleted_members (member_data, member_code, first_name, last_name, email, contact_number, deleted_by, deleted_by_name, deleted_at, recovery_deadline) 
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)",
                                [
                                    $member_data,
                                    $member['member_code'],
                                    $member['first_name'],
                                    $member['last_name'],
                                    $member['email'],
                                    $member['contact_number'],
                                    $current_user['user_id'],
                                    $current_user['username'],
                                    $recovery_deadline
                                ],
                                'ssssssiss'
                            );
                            
                            if (!$insert_deleted) {
                                throw new Exception('Failed to move member to deleted table');
                            }
                            
                            // Delete from member_balances
                            $db->execute("DELETE FROM member_balances WHERE member_code = ?", [$member_code], 's');
                            
                            // Delete from payments
                            $member_id = $db->getSingle("SELECT member_id FROM members WHERE member_code = ?", [$member_code], 's');
                            if ($member_id) {
                                $db->execute("DELETE FROM payments WHERE member_id = ?", [$member_id['member_id']], 'i');
                            }
                            
                            // Delete the member
                            $result = $db->execute("DELETE FROM members WHERE member_code = ?", [$member_code], 's');
                            
                            if ($result) {
                                $deleted_count++;
                                // Log the deletion
                                Security::logEvent('MEMBER_DELETE', "Deleted member: $member_code");
                            }
                        }
                    }
                    $db->getConnection()->commit();
                    $message = "$deleted_count member(s) moved to deleted history. They can be recovered within 50 days.";
                    header('Location: members.php?success=deleted_multiple' . "&limit=$limit&page=$page");
                    exit();
                } catch (Exception $e) {
                    $db->getConnection()->rollback();
                    $error = 'Database error: ' . $e->getMessage();
                    error_log('Multiple delete error: ' . $e->getMessage());
                }
            }
            } elseif ($action === 'delete') {
    // Handle single delete
    $member_code = Security::sanitize($_POST['member_code'] ?? '');
    
    if (empty($member_code)) {
        $error = 'No member selected for deletion.';
    } else {
        $db->getConnection()->begin_transaction();
        try {
            // Get member data first (only active members can be deleted)
            $member = $db->getSingle("SELECT * FROM members WHERE member_code = ? AND status = 'active'", [$member_code], 's');
            
            if (!$member) {
                throw new Exception('Member not found or not active.');
            }
            
            // Store in deleted_members table
            $member_data = json_encode($member);
            $recovery_deadline = date('Y-m-d H:i:s', strtotime('+50 days'));
            
            $insert_deleted = $db->execute(
                "INSERT INTO deleted_members (member_data, member_code, first_name, last_name, email, contact_number, deleted_by, deleted_by_name, deleted_at, recovery_deadline) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)",
                [
                    $member_data,
                    $member['member_code'],
                    $member['first_name'],
                    $member['last_name'],
                    $member['email'] ?? '',
                    $member['contact_number'] ?? '',
                    $current_user['user_id'],
                    $current_user['username'],
                    $recovery_deadline
                ],
                'ssssssiss'
            );
            
            if (!$insert_deleted) {
                throw new Exception('Failed to move member to deleted table');
            }
            
            // Delete from member_balances
            $db->execute("DELETE FROM member_balances WHERE member_code = ?", [$member_code], 's');
            
            // Get member_id for payments deletion
            $member_result = $db->getSingle("SELECT member_id FROM members WHERE member_code = ?", [$member_code], 's');
            if ($member_result && isset($member_result['member_id'])) {
                $db->execute("DELETE FROM payments WHERE member_id = ?", [$member_result['member_id']], 'i');
            }
            
            // Delete the member
            $result = $db->execute("DELETE FROM members WHERE member_code = ?", [$member_code], 's');
            
            if (!$result) {
                throw new Exception('Failed to delete member');
            }
            
            // Add audit log
            $db->execute(
                "INSERT INTO member_audit_log (member_code, action_type, changes, admin_user_id, admin_name, ip_address, created_at) 
                 VALUES (?, 'delete', ?, ?, ?, ?, NOW())",
                [$member_code, json_encode(['deleted_by' => $current_user['username'], 'deleted_at' => date('Y-m-d H:i:s')]), $current_user['user_id'], $current_user['username'], $_SERVER['REMOTE_ADDR']],
                'ssiss'
            );
            
            $db->getConnection()->commit();
            $message = 'Member moved to deleted history. They can be recovered within 50 days.';
            Security::logEvent('MEMBER_DELETE', "Deleted member: $member_code");
            header("Location: members.php?success=deleted&limit=$limit&page=$page&view=$view_mode");
            exit();
            
        } catch (Exception $e) {
            $db->getConnection()->rollback();
            $error = 'Database error: ' . $e->getMessage();
            error_log('Single delete error: ' . $e->getMessage());
        }
    }
     } elseif ($action === 'recover_deleted') {
    $delete_id = (int)$_POST['delete_id'];
    $deleted_record = $db->getSingle("SELECT * FROM deleted_members WHERE delete_id = ? AND is_permanently_deleted = 0", [$delete_id], 'i');

    if (!$deleted_record) {
        $error = 'Deleted record not found or already permanently deleted.';
    } else {
        $recovery_deadline = strtotime($deleted_record['recovery_deadline']);
        if (time() > $recovery_deadline) {
            $error = 'Cannot recover: 50-day recovery period has passed.';
        } else {
            $db->getConnection()->begin_transaction();
            try {
                $member_data = json_decode($deleted_record['member_data'], true);
                if (!$member_data) {
                    throw new Exception('Invalid member data in deleted record');
                }

                // Get columns from members table (excluding auto-generated timestamps)
                $columns_result = $db->getAll("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = 'members' AND TABLE_SCHEMA = DATABASE()");
                $table_columns = array_column($columns_result, 'COLUMN_NAME');
                
                $insert_columns = [];
                $insert_values = [];
                foreach ($table_columns as $col) {
                    // Skip auto timestamps (we'll set manually)
                    if (in_array($col, ['created_at', 'updated_at'])) {
                        continue;
                    }
                    if (array_key_exists($col, $member_data)) {
                        $insert_columns[] = $col;
                        $insert_values[] = $member_data[$col];
                    } else {
                        // Provide defaults for required fields missing from stored data
                        if ($col === 'status') {
                            $insert_columns[] = $col;
                            $insert_values[] = 'active';
                        } elseif ($col === 'date_joined') {
                            $insert_columns[] = $col;
                            $insert_values[] = date('Y-m-d');
                        } elseif ($col === 'monthly_contribution') {
                            $insert_columns[] = $col;
                            $insert_values[] = 100.00;
                        } elseif ($col === 'created_by') {
                            $insert_columns[] = $col;
                            $insert_values[] = $current_user['user_id'];
                        }
                        // Other missing columns will use DB defaults (do not include in insert)
                    }
                }
                // Add timestamps
                $insert_columns[] = 'created_at';
                $insert_values[] = date('Y-m-d H:i:s');
                $insert_columns[] = 'updated_at';
                $insert_values[] = date('Y-m-d H:i:s');

                // Build REPLACE query (deletes existing row with same member_code then inserts)
                $placeholders = implode(',', array_fill(0, count($insert_values), '?'));
                $replace_sql = "REPLACE INTO members (" . implode(',', $insert_columns) . ") VALUES ($placeholders)";
                
                $result = $db->execute($replace_sql, $insert_values, str_repeat('s', count($insert_values)));
                if (!$result) {
                    throw new Exception('Failed to recover member: ' . $db->getConnection()->error);
                }

                // Recreate member_balances (also use REPLACE to avoid duplicate)
                $db->execute(
                    "REPLACE INTO member_balances (member_code, total_paid, total_due, current_balance, updated_at) 
                     VALUES (?, 0.00, ?, ?, NOW())",
                    [$member_data['member_code'], $member_data['monthly_contribution'] ?? 100.00, $member_data['monthly_contribution'] ?? 100.00],
                    'sdd'
                );

                // Remove the record from deleted_members (so it doesn't appear in history)
                $db->execute("DELETE FROM deleted_members WHERE delete_id = ?", [$delete_id], 'i');

                $db->getConnection()->commit();
                $message = 'Member recovered successfully!';
                Security::logEvent('MEMBER_RECOVER', "Recovered member: " . $member_data['member_code']);
                header('Location: members.php?success=recovered&view=delete_history');
                exit();
            } catch (Exception $e) {
                $db->getConnection()->rollback();
                $error = 'Database error: ' . $e->getMessage();
                error_log('Member recovery error: ' . $e->getMessage());
            }
        }
    }
} elseif ($action === 'reactivate_inactive') {
            $member_code = Security::sanitize($_POST['member_code'] ?? '');
            
            $db->getConnection()->begin_transaction();
            try {
                $update_sql = "UPDATE members SET status = 'active', inactive_reason = NULL, inactive_notes = NULL, inactive_date = NULL, updated_by = ?, updated_at = NOW() WHERE member_code = ?";
                $result = $db->execute($update_sql, [$current_user['user_id'], $member_code], 'is');
                
                if (!$result) {
                    throw new Exception('Failed to reactivate member');
                }
                
                // Add audit log
                $db->execute(
                    "INSERT INTO member_audit_log (member_code, action_type, admin_user_id, admin_name, ip_address, created_at) 
                     VALUES (?, 'reactivate', ?, ?, ?, NOW())",
                    [$member_code, $current_user['user_id'], $current_user['username'], $_SERVER['REMOTE_ADDR']],
                    'siss'
                );
                
                $db->getConnection()->commit();
                $message = 'Member reactivated successfully!';
                Security::logEvent('MEMBER_REACTIVATE', "Reactivated member: $member_code");
                header('Location: members.php?success=reactivated&view=inactive_history');
                exit();
            } catch (Exception $e) {
                $db->getConnection()->rollback();
                $error = 'Database error: ' . $e->getMessage();
            }
        } elseif ($action === 'revert_edit') {
            $log_id = (int)$_POST['log_id'];
            $log = $db->getSingle("SELECT * FROM member_audit_log WHERE log_id = ?", [$log_id], 'i');
            
            if (!$log) {
                $error = 'History record not found.';
            } else {
                $db->getConnection()->begin_transaction();
                try {
                    // Get previous member data
                    $previous_log = $db->getSingle(
                        "SELECT * FROM member_audit_log WHERE member_code = ? AND log_id < ? AND action_type IN ('add', 'edit') ORDER BY log_id DESC LIMIT 1",
                        [$log['member_code'], $log_id],
                        'si'
                    );
                    
                    if ($previous_log && !empty($previous_log['changes'])) {
                        $previous_data = json_decode($previous_log['changes'], true);
                        if ($previous_data) {
                            // Update member with previous data
                            $update_sql = "UPDATE members SET first_name = ?, last_name = ?, middle_name = ?, contact_number = ?, email = ?, chapter = ?, group_name = ?, updated_by = ?, updated_at = NOW() WHERE member_code = ?";
                            $db->execute($update_sql, [
                                $previous_data['first_name'] ?? '',
                                $previous_data['last_name'] ?? '',
                                $previous_data['middle_name'] ?? '',
                                $previous_data['contact_number'] ?? '',
                                $previous_data['email'] ?? '',
                                $previous_data['chapter'] ?? '',
                                $previous_data['group_name'] ?? '',
                                $current_user['user_id'],
                                $log['member_code']
                            ], 'sssssssis');
                        }
                    }
                    
                    $db->getConnection()->commit();
                    $message = 'Member data reverted to previous version!';
                    header('Location: members.php?success=reverted&view=edit_history');
                    exit();
                } catch (Exception $e) {
                    $db->getConnection()->rollback();
                    $error = 'Database error: ' . $e->getMessage();
                }
            }
        } elseif ($action === 'undo_death') {
            $member_code = Security::sanitize($_POST['member_code'] ?? '');
            
            $db->getConnection()->begin_transaction();
            try {
                $update_sql = "UPDATE members SET status = 'active', death_date = NULL, cause_of_death = NULL, death_certificate = NULL, updated_by = ?, updated_at = NOW() WHERE member_code = ?";
                $result = $db->execute($update_sql, [$current_user['user_id'], $member_code], 'is');
                
                if ($result) {
                    $db->execute("DELETE FROM deceased_details WHERE member_code = ?", [$member_code], 's');
                    
                    $db->execute(
                        "INSERT INTO member_audit_log (member_code, action_type, admin_user_id, admin_name, ip_address, created_at) 
                         VALUES (?, 'undeath', ?, ?, ?, NOW())",
                        [$member_code, $current_user['user_id'], $current_user['username'], $_SERVER['REMOTE_ADDR']],
                        'siss'
                    );
                }
                
                $db->getConnection()->commit();
                $message = 'Death record undone. Member reactivated!';
                header('Location: members.php?success=undeath&view=deceased_history');
                exit();
            } catch (Exception $e) {
                $db->getConnection()->rollback();
                $error = 'Database error: ' . $e->getMessage();
            }
        }
    }
}

// Get complete member details for the view modal
if (isset($_GET['view_code'])) {
    $view_code = Security::sanitize($_GET['view_code']);
    $member_details = $db->getSingle("SELECT * FROM members WHERE member_code = ?", [$view_code], 's');
    if ($member_details) {
        if ($member_details['birth_date'] == '0000-00-00') {
            $member_details['birth_date'] = null;
        }
        header('Content-Type: application/json');
        echo json_encode($member_details);
        exit;
    }
}

$csrf_token = Security::generateCSRFToken();

// Generate pagination links helper
function buildPaginationLinks($current_page, $total_pages, $limit, $view_mode, $search, $filter_name, $filter_barangay, $filter_chapter, $filter_group, $sort_by, $sort_order, $history_filters = []) {
    $query_params = [];
    $query_params['view'] = $view_mode;
    if ($limit != 30) $query_params['limit'] = $limit;
    if (!empty($search)) $query_params['search'] = $search;
    if (!empty($filter_name)) $query_params['filter_name'] = $filter_name;
    if (!empty($filter_barangay)) $query_params['filter_barangay'] = $filter_barangay;
    if (!empty($filter_chapter)) $query_params['filter_chapter'] = $filter_chapter;
    if (!empty($filter_group)) $query_params['filter_group'] = $filter_group;
    if ($sort_by != 'created_at') $query_params['sort_by'] = $sort_by;
    if ($sort_order != 'DESC') $query_params['sort_order'] = $sort_order;
    
    // Add history filters
    if (!empty($history_filters['history_date_from'])) $query_params['history_date_from'] = $history_filters['history_date_from'];
    if (!empty($history_filters['history_date_to'])) $query_params['history_date_to'] = $history_filters['history_date_to'];
    if (!empty($history_filters['history_member'])) $query_params['history_member'] = $history_filters['history_member'];
    if (!empty($history_filters['history_admin'])) $query_params['history_admin'] = $history_filters['history_admin'];
    
    $base_url = 'members.php?' . http_build_query($query_params);
    
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

$history_filters = [
    'history_date_from' => $history_date_from,
    'history_date_to' => $history_date_to,
    'history_member' => $history_member,
    'history_admin' => $history_admin
];

$pagination_links = buildPaginationLinks($page, $total_pages, $limit, $view_mode, $search, $filter_name, $filter_barangay, $filter_chapter, $filter_group, $sort_by, $sort_order, $history_filters);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Members - Harana Financial System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="members/members.css">
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
                <a href="dashboard.php" class="list-group-item"><i class="fas fa-tachometer-alt"></i><span>Dashboard</span></a>
                <a href="members.php" class="list-group-item active"><i class="fas fa-users"></i><span>Members</span></a>
                <a href="council.php" class="list-group-item"><i class="fas fa-user-tie"></i><span>Council</span></a>
                <a href="payments.php" class="list-group-item"><i class="fas fa-credit-card"></i><span>Payments</span></a>
                <a href="reports.php" class="list-group-item"><i class="fas fa-chart-bar"></i><span>Reports</span></a>
                <?php if ($current_user['role'] === 'admin'): ?>
                <a href="pending_users.php" class="list-group-item position-relative">
                    <i class="fas fa-user-clock"></i><span>Pending</span>
                    <?php if ($pending_count > 0): ?>
                        <span class="badge bg-danger position-absolute top-0 start-100 translate-middle badge-count"><?php echo $pending_count; ?></span>
                    <?php endif; ?>
                </a>
                <?php endif; ?>
                <a href="settings.php" class="list-group-item"><i class="fas fa-cog"></i><span>Settings</span></a>
                <a href="change_password.php" class="list-group-item"><i class="fas fa-key"></i><span>Change Password</span></a>
                <a href="../logout.php" class="list-group-item" onclick="return confirm('Are you sure?');"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a>
            </div>
        </div>

        <!-- Main Content -->
       <div id="page-content-wrapper">
            <nav class="navbar navbar-light bg-light">
                <div class="navbar-left">
                    <img src="../assets/images/harana-logo.png" alt="Harana" class="header-logo" id="headerLogo" onerror="this.style.display='none';">
                    <span class="navbar-brand"><i class="fas fa-users me-2"></i>Members Management</span>
                </div>
                <div class="navbar-right">
                    <span class="text-muted small">
                        <i class="fas fa-calendar-alt me-1"></i><?php echo date('F j, Y'); ?>
                    </span>
                    <span class="small"><i class="fas fa-user-circle me-2"></i><?php echo htmlspecialchars($current_user['full_name'] ?? $current_user['username']); ?></span>
                </div>
            </nav>

            <div class="container-fluid p-3">
                <!-- Toast Notification Container -->
                <div class="toast-container position-fixed top-0 end-0 p-3" style="z-index: 999999 !important; top: 70px !important;">
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
                    <div id="warningToast" class="toast align-items-center text-white bg-warning border-0" role="alert">
                        <div class="d-flex">
                            <div class="toast-body">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                <span id="warningMessage"></span>
                            </div>
                            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                        </div>
                    </div>
                </div>

            <!-- Action Bar - Search on Left, Actions on Right -->
<div class="action-toolbar">
    <div class="search-group">
        <form method="GET" class="d-flex w-100 gap-2">
            <input type="hidden" name="view" value="<?php echo $view_mode; ?>">
            <input type="text" name="search" class="search-input" 
                   placeholder="Search by name, code, barangay, chapter..." 
                   value="<?php echo htmlspecialchars($search); ?>">
            <button type="submit" class="btn-search">
                <i class="fas fa-search"></i> Search
            </button>
        </form>
    </div>
    
    <div class="action-buttons-group">
        <!-- Actions History Dropdown -->
        <div class="dropdown">
            <button class="btn btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="fas fa-history me-1"></i>Actions History
            </button>
            <ul class="dropdown-menu">
                <li><a class="dropdown-item <?php echo $view_mode == 'members' ? 'active' : ''; ?>" href="members.php?view=members"><i class="fas fa-users me-2"></i>Active Members</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item <?php echo $view_mode == 'edit_history' ? 'active' : ''; ?>" href="members.php?view=edit_history"><i class="fas fa-edit me-2"></i>Edit History</a></li>
                <li><a class="dropdown-item <?php echo $view_mode == 'inactive_history' ? 'active' : ''; ?>" href="members.php?view=inactive_history"><i class="fas fa-pause-circle me-2"></i>Inactive History</a></li>
                <li><a class="dropdown-item <?php echo $view_mode == 'deceased_history' ? 'active' : ''; ?>" href="members.php?view=deceased_history"><i class="fas fa-cross me-2"></i>Deceased History</a></li>
                <li><a class="dropdown-item <?php echo $view_mode == 'delete_history' ? 'active' : ''; ?>" href="members.php?view=delete_history"><i class="fas fa-trash me-2"></i>Deleted History</a></li>
            </ul>
        </div>
        
        <?php if ($view_mode == 'members'): ?>
        <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addMemberModal">
            <i class="fas fa-plus me-1"></i>Add Member
        </button>
        
        <?php endif; ?>
        
        <?php if ($view_mode != 'members'): ?>
        <a href="members.php?view=members" class="btn btn-secondary">
            <i class="fas fa-arrow-left me-1"></i>Back
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- Filter Bar -->
<div class="filter-bar">
    <form method="GET" id="filterForm" class="filter-row">
        <input type="hidden" name="view" value="<?php echo $view_mode; ?>">
        
        <div class="filter-item">
            <label>Name Filter</label>
            <input type="text" name="filter_name" class="form-control" 
                   placeholder="Filter by name" value="<?php echo htmlspecialchars($filter_name); ?>">
        </div>
        
        <div class="filter-item">
            <label>Barangay</label>
            <select name="filter_barangay" class="form-select">
                <option value="">All Barangays</option>
                <?php foreach ($barangays as $brgy): ?>
                    <option value="<?php echo htmlspecialchars($brgy); ?>" <?php echo $filter_barangay == $brgy ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($brgy); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="filter-item">
            <label>Chapter</label>
            <select name="filter_chapter" class="form-select">
                <option value="">All Chapters</option>
                <?php foreach ($chapters as $chap): ?>
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
                <?php foreach ($groups as $grp): ?>
                    <option value="<?php echo htmlspecialchars($grp); ?>" <?php echo $filter_group == $grp ? 'selected' : ''; ?>>
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
            <a href="members.php?view=<?php echo $view_mode; ?>" class="btn btn-secondary"><i class="fas fa-undo me-1"></i> Reset</a>
        </div>
    </form>
</div>

                </div>
                
                <!-- History Filters (for history views) -->
                <?php if ($view_mode != 'members'): ?>
                <div class="card shadow-sm mb-3">
                    <div class="card-body py-2">
                        <form method="GET" class="row g-2 align-items-end">
                            <input type="hidden" name="view" value="<?php echo $view_mode; ?>">
                            <div class="col-auto">
                                <label class="form-label small">Date From</label>
                                <input type="date" name="history_date_from" class="form-control form-control-sm" value="<?php echo $history_date_from; ?>">
                            </div>
                            <div class="col-auto">
                                <label class="form-label small">Date To</label>
                                <input type="date" name="history_date_to" class="form-control form-control-sm" value="<?php echo $history_date_to; ?>">
                            </div>
                            <div class="col-auto">
                                <label class="form-label small">Member</label>
                                <input type="text" name="history_member" class="form-control form-control-sm" placeholder="Search member..." value="<?php echo htmlspecialchars($history_member); ?>">
                            </div>
                            <?php if ($view_mode == 'edit_history'): ?>
                            <div class="col-auto">
                                <label class="form-label small">Admin</label>
                                <input type="text" name="history_admin" class="form-control form-control-sm" placeholder="Admin name..." value="<?php echo htmlspecialchars($history_admin); ?>">
                            </div>
                            <?php endif; ?>
                            <div class="col-auto">
                                <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-filter"></i> Filter</button>
                                <a href="members.php?view=<?php echo $view_mode; ?>" class="btn btn-secondary btn-sm"><i class="fas fa-undo"></i> Clear</a>
                            </div>
                        </form>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Multiple Delete Form -->
                <?php if ($view_mode == 'members'): ?>
                <form method="POST" id="multipleDeleteForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="delete_multiple">
                <?php endif; ?>
                
                <!-- Main Content Container -->
                <div class="card shadow-sm">
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <?php if ($view_mode == 'members'): ?>
                                        <th width="40"><input type="checkbox" id="selectAllCheckbox"></th>
                                        <?php endif; ?>
                                        <th>Code</th>
                                        <th>Full Name</th>
                                        <th>Chapter</th>
                                        <th>Group</th>
                                        <th>Barangay</th>
                                        <th>Contact</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($view_mode == 'members'): ?>
                                        <?php if (empty($members)): ?>
                                        <tr>
                                            <td colspan="9" class="text-center py-4">
                                                <i class="fas fa-users fa-3x text-muted mb-3"></i>
                                                <p class="text-muted">No active members found.</p>
                                            </td>
                                        </tr>
                                        <?php else: ?>
                                            <?php foreach ($members as $member): ?>
                                            <tr class="clickable-row" data-member-code="<?php echo $member['member_code']; ?>">
                                                <td><input type="checkbox" name="member_codes[]" value="<?php echo $member['member_code']; ?>" class="member-checkbox"></td>
                                                <td><span class="badge bg-secondary"><?php echo htmlspecialchars($member['member_code'] ?? 'N/A'); ?></span></td>
                                                <td>
                                                    <strong><?php echo htmlspecialchars(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? '')); ?></strong>
                                                    <?php if (!empty($member['middle_name'])): ?>
                                                        <br><small class="text-muted"><?php echo htmlspecialchars($member['middle_name']); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo !empty($member['chapter']) ? '<span class="badge bg-info">' . htmlspecialchars($member['chapter']) . '</span>' : '<span class="text-muted">—</span>'; ?></td>
                                                <td><?php echo !empty($member['group_name']) ? htmlspecialchars($member['group_name']) : '<span class="text-muted">—</span>'; ?></td>
                                                <td><?php echo !empty($member['barangay']) ? htmlspecialchars($member['barangay']) : '<span class="text-muted">—</span>'; ?></td>
                                                <td><?php if (!empty($member['contact_number'])): ?><i class="fas fa-phone-alt me-1 text-success small"></i><?php echo htmlspecialchars($member['contact_number']); ?><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
                                                <td><span class="status-badge status-active">Active</span></td>
                                                <td class="action-buttons">
                                                    <div class="dropdown">
                                                       <button class="btn btn-sm btn-outline-secondary" type="button" data-bs-toggle="dropdown" aria-expanded="false" onclick="event.stopPropagation();">
    <i class="fas fa-ellipsis-v"></i>
</button>
                                                        <ul class="dropdown-menu dropdown-menu-end">
                                                            <li><a class="dropdown-item" href="#" onclick="event.stopPropagation(); viewMemberDetails('<?php echo $member['member_code']; ?>'); return false;"><i class="fas fa-eye text-info me-2"></i>View Details</a></li>
                                        <li><a class="dropdown-item edit-btn" href="javascript:void(0);" data-id="<?php echo $member['member_code']; ?>" data-code="<?php echo $member['member_code']; ?>" onclick="event.stopPropagation(); editMember(this); return false;"><i class="fas fa-edit text-primary me-2"></i>Edit Member</a></li>
                                                            <li><a class="dropdown-item inactive-btn" href="#" data-code="<?php echo $member['member_code']; ?>" data-name="<?php echo htmlspecialchars(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? '')); ?>" onclick="event.stopPropagation(); showInactiveModal(this); return false;" data-bs-toggle="modal" data-bs-target="#inactiveMemberModal"><i class="fas fa-pause-circle text-warning me-2"></i>Set Inactive</a></li>
                                                            <li><a class="dropdown-item deceased-btn" href="#" data-code="<?php echo $member['member_code']; ?>" data-name="<?php echo htmlspecialchars(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? '')); ?>" onclick="event.stopPropagation(); showDeceasedModal(this); return false;" data-bs-toggle="modal" data-bs-target="#deceasedMemberModal"><i class="fas fa-cross text-secondary me-2"></i>Mark Deceased</a></li>
                                                            <li><hr class="dropdown-divider"></li>
                                                            <li><a class="dropdown-item text-danger" href="javascript:void(0);" onclick="event.stopPropagation(); showDeleteModal('<?php echo $member['member_code']; ?>', '<?php echo htmlspecialchars(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? ''), ENT_QUOTES); ?>'); return false;"><i class="fas fa-trash text-danger me-2"></i>Delete Member</a></li>
                                                        </ul>
                                                    </div>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    <?php elseif ($view_mode == 'edit_history'): ?>
                                        <?php if (empty($history_records)): ?>
                                        <tr><td colspan="9" class="text-center py-4"><p class="text-muted">No edit history found.</p></td></tr>
                                        <?php else: ?>
                                            <?php foreach ($history_records as $record): ?>
                                            <tr>
                                                <td><span class="badge bg-secondary"><?php echo htmlspecialchars($record['member_code']); ?></span></td>
                                                <td><strong><?php echo htmlspecialchars($record['changes'] ?? 'Record ' . $record['action_type']); ?></strong><br><small class="text-muted"><?php echo date('M d, Y H:i:s', strtotime($record['created_at'])); ?></small></td>
                                                <td><?php echo htmlspecialchars($record['admin_name'] ?? 'System'); ?></td>
                                                <td><span class="badge bg-info"><?php echo $record['action_type']; ?></span></td>
                                                <td class="action-buttons">
                                                    <?php if ($record['action_type'] == 'edit'): ?>
                                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Revert to previous version?');">
                                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                        <input type="hidden" name="action" value="revert_edit">
                                                        <input type="hidden" name="log_id" value="<?php echo $record['log_id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-warning"><i class="fas fa-undo"></i> Revert</button>
                                                    </form>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    <?php elseif ($view_mode == 'inactive_history'): ?>
                                        <?php if (empty($history_records)): ?>
                                        <tr><td colspan="9" class="text-center py-4"><p class="text-muted">No inactive members found.</p></td></tr>
                                        <?php else: ?>
                                            <?php foreach ($history_records as $record): ?>
                                            <tr>
                                                <td><span class="badge bg-secondary"><?php echo htmlspecialchars($record['member_code']); ?></span></td>
                                                <td><strong><?php echo htmlspecialchars(($record['first_name'] ?? '') . ' ' . ($record['last_name'] ?? '')); ?></strong></td>
                                                <td><?php echo !empty($record['chapter']) ? htmlspecialchars($record['chapter']) : '—'; ?></td>
                                                <td><?php echo !empty($record['inactive_reason']) ? htmlspecialchars($record['inactive_reason']) : '—'; ?></td>
                                                <td><small><?php echo date('M d, Y', strtotime($record['inactive_date'])); ?></small></td>
                                                <td class="action-buttons">
                                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Reactivate this member?');">
                                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                        <input type="hidden" name="action" value="reactivate_inactive">
                                                        <input type="hidden" name="member_code" value="<?php echo $record['member_code']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-play"></i> Reactivate</button>
                                                    </form>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    <?php elseif ($view_mode == 'deceased_history'): ?>
                                        <?php if (empty($history_records)): ?>
                                        <tr><td colspan="9" class="text-center py-4"><p class="text-muted">No deceased members found.</p></td></tr>
                                        <?php else: ?>
                                            <?php foreach ($history_records as $record): ?>
                                            <tr>
                                                <td><span class="badge bg-secondary"><?php echo htmlspecialchars($record['member_code']); ?></span></td>
                                                <td><strong><?php echo htmlspecialchars(($record['first_name'] ?? '') . ' ' . ($record['last_name'] ?? '')); ?></strong></td>
                                                <td><?php echo !empty($record['cause_of_death']) ? htmlspecialchars($record['cause_of_death']) : '—'; ?></td>
                                                <td><?php echo !empty($record['death_date_record']) ? date('M d, Y', strtotime($record['death_date_record'])) : '—'; ?></td>
                                                <td class="action-buttons">
                                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Undo death record and reactivate member?');">
                                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                        <input type="hidden" name="action" value="undo_death">
                                                        <input type="hidden" name="member_code" value="<?php echo $record['member_code']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-warning"><i class="fas fa-undo"></i> Undo Death</button>
                                                    </form>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    <?php elseif ($view_mode == 'delete_history'): ?>
                                        <?php if (empty($history_records)): ?>
                                        <tr><td colspan="9" class="text-center py-4"><p class="text-muted">No deleted members found.</p></td></tr>
                                        <?php else: ?>
                                            <?php foreach ($history_records as $record): ?>
                                                <?php
                                                $recovery_deadline = strtotime($record['recovery_deadline']);
                                                $days_left = ceil(($recovery_deadline - time()) / 86400);
                                                $can_recover = $days_left > 0;
                                                ?>
                                            <tr>
                                                <td><span class="badge bg-secondary"><?php echo htmlspecialchars($record['member_code']); ?></span></td>
                                                <td><strong><?php echo htmlspecialchars(($record['first_name'] ?? '') . ' ' . ($record['last_name'] ?? '')); ?></strong></td>
                                                <td><?php echo htmlspecialchars($record['deleted_by_name'] ?? 'System'); ?></td>
                                                <td><small><?php echo date('M d, Y H:i', strtotime($record['deleted_at'])); ?></small></td>
                                                <td>
                                                    <?php if ($can_recover): ?>
                                                        <span class="badge bg-warning text-dark"><?php echo $days_left; ?> days left</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-danger">Expired</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="action-buttons">
                                                    <?php if ($can_recover): ?>
                                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Recover this member? They will be restored to active members list.');">
                                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                        <input type="hidden" name="action" value="recover_deleted">
                                                        <input type="hidden" name="delete_id" value="<?php echo $record['delete_id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-trash-restore"></i> Recover</button>
                                                    </form>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        
                   <?php if ($total_records > 0): ?>
<div class="p-3 border-top bg-white">
    <div class="row align-items-center">
        <div class="col-md-4">
            <div class="text-muted small">
                <i class="fas fa-database me-1"></i> 
                Showing <?php echo (($page - 1) * $limit) + 1; ?> to <?php echo min($page * $limit, $total_records); ?> of <?php echo number_format($total_records); ?> records
            </div>
        </div>
        <div class="col-md-8">
            <?php if ($total_pages > 1): ?>
            <nav aria-label="Page navigation" class="d-flex justify-content-end">
                <ul class="pagination pagination-sm mb-0 flex-wrap justify-content-center justify-content-md-end">
                    <!-- First Page -->
                    <?php if ($page > 1): ?>
                    <li class="page-item">
                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>" title="First Page">
                            <i class="fas fa-angle-double-left"></i>
                        </a>
                    </li>
                    <?php else: ?>
                    <li class="page-item disabled">
                        <span class="page-link"><i class="fas fa-angle-double-left"></i></span>
                    </li>
                    <?php endif; ?>
                    
                    <!-- Previous Page -->
                    <?php if ($page > 1): ?>
                    <li class="page-item">
                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" title="Previous Page">
                            <i class="fas fa-chevron-left"></i> Prev
                        </a>
                    </li>
                    <?php else: ?>
                    <li class="page-item disabled">
                        <span class="page-link"><i class="fas fa-chevron-left"></i> Prev</span>
                    </li>
                    <?php endif; ?>
                    
                    <!-- Page Numbers -->
                    <?php
                    $start_page = max(1, $page - 2);
                    $end_page = min($total_pages, $page + 2);
                    
                    if ($start_page > 1) {
                        echo '<li class="page-item"><a class="page-link" href="?' . http_build_query(array_merge($_GET, ['page' => 1])) . '">1</a></li>';
                        if ($start_page > 2) echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                    }
                    
                    for ($i = $start_page; $i <= $end_page; $i++) {
                        if ($i == $page) {
                            echo '<li class="page-item active"><span class="page-link">' . $i . '</span></li>';
                        } else {
                            echo '<li class="page-item"><a class="page-link" href="?' . http_build_query(array_merge($_GET, ['page' => $i])) . '">' . $i . '</a></li>';
                        }
                    }
                    
                    if ($end_page < $total_pages) {
                        if ($end_page < $total_pages - 1) echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                        echo '<li class="page-item"><a class="page-link" href="?' . http_build_query(array_merge($_GET, ['page' => $total_pages])) . '">' . $total_pages . '</a></li>';
                    }
                    ?>
                    
                    <!-- Next Page -->
                    <?php if ($page < $total_pages): ?>
                    <li class="page-item">
                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" title="Next Page">
                            Next <i class="fas fa-chevron-right"></i>
                        </a>
                    </li>
                    <?php else: ?>
                    <li class="page-item disabled">
                        <span class="page-link">Next <i class="fas fa-chevron-right"></i></span>
                    </li>
                    <?php endif; ?>
                    
                    <!-- Last Page -->
                    <?php if ($page < $total_pages): ?>
                    <li class="page-item">
                        <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>" title="Last Page">
                            <i class="fas fa-angle-double-right"></i>
                        </a>
                    </li>
                    <?php else: ?>
                    <li class="page-item disabled">
                        <span class="page-link"><i class="fas fa-angle-double-right"></i></span>
                    </li>
                    <?php endif; ?>
                </ul>
            </nav>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>
                
                <?php if ($view_mode == 'members'): ?>
                </form>
                
                <!-- Multiple Delete Button -->
                <div class="mt-3" id="deleteSelectedDiv" style="display: none;">
                    <button type="button" class="btn btn-danger" id="deleteSelectedBtn" onclick="confirmMultipleDelete()">
                        <i class="fas fa-trash me-1"></i> Delete Selected (<span id="selectedCount">0</span>)
                    </button>
                </div>
                <?php endif; ?>
                
            </div>
        </div>
    </div>

    <!-- Add Member Modal -->
    <div class="modal fade" id="addMemberModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <form method="POST" id="addMemberForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="add">
                    
                    <div class="modal-header bg-success text-white py-2">
                        <h5 class="modal-title"><i class="fas fa-user-plus me-2"></i>Add New Member</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    
                    <div class="modal-body p-4">
                        <!-- Logo and Header -->
                        <div class="logo-section">
                            <img src="../assets/images/harana-logo.png" alt="Harana Logo" onerror="this.style.display='none'; document.getElementById('add-logo-placeholder').style.display='flex';">
                            <div id="add-logo-placeholder" class="logo-placeholder" style="display: none;">
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

                        <!-- Documents Section -->
                        <div class="documents-section">
                            <h4>Documents Attached:</h4>
                            <div class="checkbox-group">
                                <div class="checkbox-item">
                                    <input type="checkbox" name="medical_certificate" id="add_medical_certificate" value="1">
                                    <label for="add_medical_certificate">Medical Certificate</label>
                                </div>
                                <div class="checkbox-item">
                                    <input type="checkbox" name="birth_certificate" id="add_birth_certificate" value="1">
                                    <label for="add_birth_certificate">Birth Certificate</label>
                                </div>
                            </div>
                        </div>

                        <!-- Personal Information -->
                        <div class="section-title">I. PERSONAL INFORMATION</div>
                        
                        <div class="row mb-3">
                            <div class="col-md-3">
                                <label class="form-label">Member Code *</label>
                                <input type="text" name="member_code" class="form-control" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Date Joined *</label>
                                <input type="date" name="date_joined" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Monthly Contribution *</label>
                                <input type="number" name="monthly_contribution" class="form-control" value="100.00" step="0.01" required>
                            </div>
                        </div>

                        <div class="row mb-3">
                            <div class="col-md-4">
                                <label class="form-label">Last Name *</label>
                                <input type="text" name="last_name" class="form-control" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">First Name *</label>
                                <input type="text" name="first_name" class="form-control" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Middle Name</label>
                                <input type="text" name="middle_name" class="form-control">
                            </div>
                        </div>

                        <div class="row mb-3">
                            <div class="col-md-3">
                                <label class="form-label">Date of Birth</label>
                                <input type="date" name="birth_date" id="add_birth_date" class="form-control" onchange="calculateAge('add')">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Place of Birth</label>
                                <input type="text" name="place_of_birth" class="form-control">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Age</label>
                                <input type="number" name="age" id="add_age" class="form-control" readonly>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Gender</label>
                                <select name="gender" class="form-select">
                                    <option value="">Select</option>
                                    <option value="Male">Male</option>
                                    <option value="Female">Female</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Civil Status</label>
                                <select name="civil_status" class="form-select">
                                    <option value="">Select</option>
                                    <option value="Single">Single</option>
                                    <option value="Married">Married</option>
                                    <option value="Widowed">Widowed</option>
                                    <option value="Separated">Separated</option>
                                </select>
                            </div>
                        </div>

                        <div class="row mb-3">
                            <div class="col-md-4">
                                <label class="form-label">Religion</label>
                                <input type="text" name="religion" class="form-control">
                            </div>
                        </div>

                        <!-- Address Information -->
                        <div class="section-title">II. ADDRESS INFORMATION</div>
                        
                        <div class="row mb-3">
                            <div class="col-md-3">
                                <label class="form-label">Province</label>
                                <select name="province" id="add_province" class="form-select" onchange="updateCities('add')">
                                    <option value="Occidental Mindoro">Occidental Mindoro</option>
                                    <option value="Nueva Ecija">Nueva Ecija</option>
                                    <option value="Pampanga">Pampanga</option>
                                    <option value="Bulacan">Bulacan</option>
                                    <option value="Tarlac">Tarlac</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">City/Municipality</label>
                                <select name="city" id="add_city" class="form-select">
                                    <option value="">Select City</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Barangay</label>
                                <input type="text" name="barangay" class="form-control" placeholder="Enter Barangay">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Street/Purok/Sitio</label>
                                <input type="text" name="street" class="form-control" placeholder="Street/Purok/Sitio">
                            </div>
                        </div>

                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Present Address (if different from above)</label>
                                <input type="text" name="present_address" class="form-control" placeholder="Complete present address">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Permanent Address</label>
                                <input type="text" name="permanent_address" class="form-control" placeholder="Complete permanent address">
                            </div>
                        </div>

                        <!-- Contact Information -->
                        <div class="section-title">III. CONTACT INFORMATION</div>
                        
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <label class="form-label">Contact Number *</label>
                                <input type="text" name="contact_number" class="form-control" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Alternate Number</label>
                                <input type="text" name="alternate_number" class="form-control">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Email Address</label>
                                <input type="email" name="email" class="form-control">
                            </div>
                        </div>

                        <!-- Family Information -->
                        <div class="section-title">IV. FAMILY BACKGROUND</div>
                        
                        <div class="row mb-3">
                            <div class="col-md-12">
                                <label class="form-label">Father's Full Name:</label>
                            </div>
                            <div class="col-md-4">
                                <input type="text" name="father_fname" class="form-control" placeholder="First Name">
                            </div>
                            <div class="col-md-4">
                                <input type="text" name="father_mname" class="form-control" placeholder="Middle Name">
                            </div>
                            <div class="col-md-4">
                                <input type="text" name="father_lname" class="form-control" placeholder="Last Name">
                            </div>
                        </div>

                        <div class="row mb-3">
                            <div class="col-md-12">
                                <label class="form-label">Mother's Full Name (Maiden Name):</label>
                            </div>
                            <div class="col-md-4">
                                <input type="text" name="mother_fname" class="form-control" placeholder="First Name">
                            </div>
                            <div class="col-md-4">
                                <input type="text" name="mother_mname" class="form-control" placeholder="Middle Name">
                            </div>
                            <div class="col-md-4">
                                <input type="text" name="mother_lname" class="form-control" placeholder="Last Name">
                            </div>
                        </div>

                        <div class="row mb-3">
                            <div class="col-md-12">
                                <label class="form-label">Spouse's Full Name (If Married):</label>
                            </div>
                            <div class="col-md-3">
                                <input type="text" name="spouse_fname" class="form-control" placeholder="First Name">
                            </div>
                            <div class="col-md-3">
                                <input type="text" name="spouse_mname" class="form-control" placeholder="Middle Name">
                            </div>
                            <div class="col-md-3">
                                <input type="text" name="spouse_lname" class="form-control" placeholder="Last Name">
                            </div>
                            <div class="col-md-3">
                                <input type="number" name="spouse_age" class="form-control" placeholder="Age">
                            </div>
                        </div>

                        <!-- Children -->
                        <div class="section-title">V. CHILDREN (List all children below 18 years old)</div>
                        
                        <div class="row mb-2">
                            <div class="col-md-1"><label class="form-label">1.</label></div>
                            <div class="col-md-3"><input type="text" name="child1_fname" class="form-control" placeholder="First Name"></div>
                            <div class="col-md-3"><input type="text" name="child1_mname" class="form-control" placeholder="Middle Name"></div>
                            <div class="col-md-3"><input type="text" name="child1_lname" class="form-control" placeholder="Last Name"></div>
                            <div class="col-md-2"><input type="number" name="child1_age" class="form-control" placeholder="Age"></div>
                        </div>

                        <div class="row mb-2">
                            <div class="col-md-1"><label class="form-label">2.</label></div>
                            <div class="col-md-3"><input type="text" name="child2_fname" class="form-control" placeholder="First Name"></div>
                            <div class="col-md-3"><input type="text" name="child2_mname" class="form-control" placeholder="Middle Name"></div>
                            <div class="col-md-3"><input type="text" name="child2_lname" class="form-control" placeholder="Last Name"></div>
                            <div class="col-md-2"><input type="number" name="child2_age" class="form-control" placeholder="Age"></div>
                        </div>

                        <div class="row mb-2">
                            <div class="col-md-1"><label class="form-label">3.</label></div>
                            <div class="col-md-3"><input type="text" name="child3_fname" class="form-control" placeholder="First Name"></div>
                            <div class="col-md-3"><input type="text" name="child3_mname" class="form-control" placeholder="Middle Name"></div>
                            <div class="col-md-3"><input type="text" name="child3_lname" class="form-control" placeholder="Last Name"></div>
                            <div class="col-md-2"><input type="number" name="child3_age" class="form-control" placeholder="Age"></div>
                        </div>

                        <div class="row mb-3">
                            <div class="col-md-1"><label class="form-label">4.</label></div>
                            <div class="col-md-3"><input type="text" name="child4_fname" class="form-control" placeholder="First Name"></div>
                            <div class="col-md-3"><input type="text" name="child4_mname" class="form-control" placeholder="Middle Name"></div>
                            <div class="col-md-3"><input type="text" name="child4_lname" class="form-control" placeholder="Last Name"></div>
                            <div class="col-md-2"><input type="number" name="child4_age" class="form-control" placeholder="Age"></div>
                        </div>

                        <!-- References -->
                        <div class="section-title">VI. CHARACTER REFERENCES</div>
                        
                        <div class="row mb-2">
                            <div class="col-md-5">
                                <label class="form-label">Name of Reference 1</label>
                                <input type="text" name="ref1_name" class="form-control" placeholder="Full Name">
                            </div>
                            <div class="col-md-7">
                                <label class="form-label">Contact Number</label>
                                <input type="text" name="ref1_contact" class="form-control" placeholder="Contact Number">
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-5">
                                <label class="form-label">Name of Reference 2</label>
                                <input type="text" name="ref2_name" class="form-control" placeholder="Full Name">
                            </div>
                            <div class="col-md-7">
                                <label class="form-label">Contact Number</label>
                                <input type="text" name="ref2_contact" class="form-control" placeholder="Contact Number">
                            </div>
                        </div>

                        <!-- Beneficiary -->
                        <div class="section-title">VII. BENEFICIARY INFORMATION</div>
                        
                        <div class="row mb-2">
                            <div class="col-md-4">
                                <label class="form-label">Full Name of Beneficiary</label>
                                <input type="text" name="beneficiary_name" class="form-control" placeholder="Full Name">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Complete Address</label>
                                <input type="text" name="beneficiary_address" class="form-control" placeholder="Address">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Relationship</label>
                                <input type="text" name="beneficiary_relation" class="form-control" placeholder="Relation">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Age</label>
                                <input type="number" name="beneficiary_age" class="form-control" placeholder="Age">
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <label class="form-label">Contact Number of Beneficiary</label>
                                <input type="text" name="beneficiary_contact" class="form-control" placeholder="Contact">
                            </div>
                        </div>

                        <!-- Chapter Information -->
                        <div class="section-title">VIII. CHAPTER INFORMATION</div>
                        
                        <div class="row mb-2">
                            <div class="col-md-3">
                                <label class="form-label">Chapter</label>
                                <select name="chapter" class="form-select">
                                    <option value="">Select Chapter</option>
                                    <?php foreach ($chapters as $chap): ?>
                                        <option value="<?php echo htmlspecialchars($chap); ?>"><?php echo htmlspecialchars($chap); ?></option>
                                    <?php endforeach; ?>
                                    <option value="GUIMBA">GUIMBA</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Group Name</label>
                                <input type="text" name="group_name" class="form-control" placeholder="Group Name">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Leader</label>
                                <input type="text" name="leader" class="form-control" placeholder="Leader" list="leaderList">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Coordinator</label>
                                <input type="text" name="coordinator" class="form-control" placeholder="Coordinator" list="coordinatorList">
                            </div>
                        </div>
                        
                        <div class="row mb-2">
                            <div class="col-md-3">
                                <label class="form-label">Chairman</label>
                                <input type="text" name="chairman" class="form-control" placeholder="Chairman" list="chairmanList">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Screening Officer</label>
                                <input type="text" name="screening_officer" class="form-control" placeholder="Screening Officer" list="screenerList">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Screening Date</label>
                                <input type="date" name="screening_date" class="form-control">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Approved By</label>
                                <input type="text" name="approved_by" class="form-control" placeholder="Approved By">
                            </div>
                        </div>
                        
                        <div class="row mb-2">
                            <div class="col-md-3">
                                <label class="form-label">Date Registered</label>
                                <input type="date" name="date_registered" class="form-control" value="<?php echo date('Y-m-d'); ?>">
                            </div>
                        </div>

                        <!-- Account Information -->
                        <div class="section-title">IX. ACCOUNT INFORMATION</div>
                        
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <label class="form-label">Username</label>
                                <input type="text" name="username" class="form-control" placeholder="Username for login">
                                <small class="text-muted">Leave empty if no login needed</small>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Password</label>
                                <input type="password" name="password" class="form-control" minlength="6">
                                <small class="text-muted">Min 6 chars if creating login</small>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Confirm Password</label>
                                <input type="password" name="confirm_password" class="form-control">
                            </div>
                        </div>

                        <!-- Fill Sample Data Button -->
                        <div class="row mt-3">
                            <div class="col-12 text-end">
                                <button type="button" class="btn-fill" onclick="fillSampleData()">
                                    <i class="fas fa-magic me-1"></i>Fill Sample Data
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <div class="modal-footer py-2">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">Save Member</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Edit Member Modal -->
    <div class="modal fade" id="editMemberModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <form method="POST" id="editMemberForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="edit">
                    <input type="hidden" name="member_code" id="edit_member_code">
                    <input type="hidden" name="member_id" id="edit_member_id">
                    <div class="modal-header bg-primary text-white py-2">
                        <h5 class="modal-title"><i class="fas fa-user-edit me-2"></i>Edit Member</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    
                    <div class="modal-body p-4">
                        <!-- Logo and Header -->
                        <div class="logo-section">
                            <img src="../assets/images/harana-logo.png" alt="Harana Logo" onerror="this.style.display='none'; document.getElementById('edit-logo-placeholder').style.display='flex';">
                            <div id="edit-logo-placeholder" class="logo-placeholder" style="display: none;">
                                <i class="fas fa-hand-holding-heart fa-3x"></i>
                            </div>
                            <div class="text-content">
                                <h2>NAGKAISANG HARANISTA</h2>
                                <h5>SA GINTONG LUZON, PHILS. INC. (NHGL, INC.)</h5>
                                <p class="small">(Formerly Nagkaisang Hiranista Sa Gintong Luzon, Inc.)</p>
                                <p class="small">(Sec. REG No. CN 700172104)</p>
                                <p class="small">MF 2024<br>Bryg. Singalat, Palayan City<br>Province of Nueva Ecija<br>Tel. No. (044)940-6708</p>
                            </div>
                        </div>

                        <h4 class="form-title">APPLICATION FOR MEMBERSHIP</h4>

                        <!-- Documents Section -->
                        <div class="documents-section">
                            <h4>Documents Attached:</h4>
                            <div class="checkbox-group">
                                <div class="checkbox-item">
                                    <input type="checkbox" name="medical_certificate" id="edit_medical_certificate" value="1">
                                    <label for="edit_medical_certificate">Medical Certificate</label>
                                </div>
                                <div class="checkbox-item">
                                    <input type="checkbox" name="birth_certificate" id="edit_birth_certificate" value="1">
                                    <label for="edit_birth_certificate">Birth Certificate</label>
                                </div>
                            </div>
                        </div>

                        <!-- Personal Information -->
                        <div class="section-title">I. PERSONAL INFORMATION</div>
                        
                        <div class="row mb-3">
                            <div class="col-md-3">
                                <label class="form-label">Member Code</label>
                                <input type="text" class="form-control" id="edit_member_code_display" disabled readonly>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Date Joined *</label>
                                <input type="date" name="date_joined" id="edit_date_joined" class="form-control" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Monthly Contribution *</label>
                                <input type="number" name="monthly_contribution" id="edit_monthly" class="form-control" step="0.01" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Status</label>
                                <select name="status" id="edit_status" class="form-select">
                                    <option value="active">Active</option>
                                    <option value="inactive">Inactive</option>
                                    <option value="deceased">Deceased</option>
                                </select>
                            </div>
                        </div>

                        <div class="row mb-3">
                            <div class="col-md-4">
                                <label class="form-label">Last Name *</label>
                                <input type="text" name="last_name" id="edit_last_name" class="form-control" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">First Name *</label>
                                <input type="text" name="first_name" id="edit_first_name" class="form-control" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Middle Name</label>
                                <input type="text" name="middle_name" id="edit_middle_name" class="form-control">
                            </div>
                        </div>

                        <div class="row mb-3">
                            <div class="col-md-3">
                                <label class="form-label">Date of Birth</label>
                                <input type="date" name="birth_date" id="edit_birth_date" class="form-control" onchange="calculateAge('edit')">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Place of Birth</label>
                                <input type="text" name="place_of_birth" id="edit_place_of_birth" class="form-control">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Age</label>
                                <input type="number" name="age" id="edit_age" class="form-control" readonly>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Gender</label>
                                <select name="gender" id="edit_gender" class="form-select">
                                    <option value="">Select</option>
                                    <option value="Male">Male</option>
                                    <option value="Female">Female</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Civil Status</label>
                                <select name="civil_status" id="edit_civil_status" class="form-select">
                                    <option value="">Select</option>
                                    <option value="Single">Single</option>
                                    <option value="Married">Married</option>
                                    <option value="Widowed">Widowed</option>
                                    <option value="Separated">Separated</option>
                                </select>
                            </div>
                        </div>

                        <div class="row mb-3">
                            <div class="col-md-4">
                                <label class="form-label">Religion</label>
                                <input type="text" name="religion" id="edit_religion" class="form-control">
                            </div>
                        </div>

                        <!-- Address Information -->
                        <div class="section-title">II. ADDRESS INFORMATION</div>
                        
                        <div class="row mb-3">
                            <div class="col-md-3">
                                <label class="form-label">Province</label>
                                <select name="province" id="edit_province" class="form-select" onchange="updateCities('edit')">
                                    <option value="Occidental Mindoro">Occidental Mindoro</option>
                                    <option value="Nueva Ecija">Nueva Ecija</option>
                                    <option value="Pampanga">Pampanga</option>
                                    <option value="Bulacan">Bulacan</option>
                                    <option value="Tarlac">Tarlac</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">City/Municipality</label>
                                <select name="city" id="edit_city" class="form-select">
                                    <option value="">Select City</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Barangay</label>
                                <input type="text" name="barangay" id="edit_barangay" class="form-control" placeholder="Enter Barangay">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Street/Purok/Sitio</label>
                                <input type="text" name="street" id="edit_street" class="form-control" placeholder="Street/Purok/Sitio">
                            </div>
                        </div>

                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Present Address (if different from above)</label>
                                <input type="text" name="present_address" id="edit_present_address" class="form-control" placeholder="Complete present address">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Permanent Address</label>
                                <input type="text" name="permanent_address" id="edit_permanent_address" class="form-control" placeholder="Complete permanent address">
                            </div>
                        </div>

                        <!-- Contact Information -->
                        <div class="section-title">III. CONTACT INFORMATION</div>
                        
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <label class="form-label">Contact Number *</label>
                                <input type="text" name="contact_number" id="edit_contact" class="form-control" required>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Alternate Number</label>
                                <input type="text" name="alternate_number" id="edit_alternate" class="form-control">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Email Address</label>
                                <input type="email" name="email" id="edit_email" class="form-control">
                            </div>
                        </div>

                        <!-- Family Information -->
                        <div class="section-title">IV. FAMILY BACKGROUND</div>
                        
                        <div class="row mb-3">
                            <div class="col-md-12">
                                <label class="form-label">Father's Full Name:</label>
                            </div>
                            <div class="col-md-4">
                                <input type="text" name="father_fname" id="edit_father_fname" class="form-control" placeholder="First Name">
                            </div>
                            <div class="col-md-4">
                                <input type="text" name="father_mname" id="edit_father_mname" class="form-control" placeholder="Middle Name">
                            </div>
                            <div class="col-md-4">
                                <input type="text" name="father_lname" id="edit_father_lname" class="form-control" placeholder="Last Name">
                            </div>
                        </div>

                        <div class="row mb-3">
                            <div class="col-md-12">
                                <label class="form-label">Mother's Full Name (Maiden Name):</label>
                            </div>
                            <div class="col-md-4">
                                <input type="text" name="mother_fname" id="edit_mother_fname" class="form-control" placeholder="First Name">
                            </div>
                            <div class="col-md-4">
                                <input type="text" name="mother_mname" id="edit_mother_mname" class="form-control" placeholder="Middle Name">
                            </div>
                            <div class="col-md-4">
                                <input type="text" name="mother_lname" id="edit_mother_lname" class="form-control" placeholder="Last Name">
                            </div>
                        </div>

                        <div class="row mb-3">
                            <div class="col-md-12">
                                <label class="form-label">Spouse's Full Name (If Married):</label>
                            </div>
                            <div class="col-md-3">
                                <input type="text" name="spouse_fname" id="edit_spouse_fname" class="form-control" placeholder="First Name">
                            </div>
                            <div class="col-md-3">
                                <input type="text" name="spouse_mname" id="edit_spouse_mname" class="form-control" placeholder="Middle Name">
                            </div>
                            <div class="col-md-3">
                                <input type="text" name="spouse_lname" id="edit_spouse_lname" class="form-control" placeholder="Last Name">
                            </div>
                            <div class="col-md-3">
                                <input type="number" name="spouse_age" id="edit_spouse_age" class="form-control" placeholder="Age">
                            </div>
                        </div>

                        <!-- Children -->
                        <div class="section-title">V. CHILDREN (List all children below 18 years old)</div>
                        
                        <div class="row mb-2">
                            <div class="col-md-1"><label class="form-label">1.</label></div>
                            <div class="col-md-3"><input type="text" name="child1_fname" id="edit_child1_fname" class="form-control" placeholder="First Name"></div>
                            <div class="col-md-3"><input type="text" name="child1_mname" id="edit_child1_mname" class="form-control" placeholder="Middle Name"></div>
                            <div class="col-md-3"><input type="text" name="child1_lname" id="edit_child1_lname" class="form-control" placeholder="Last Name"></div>
                            <div class="col-md-2"><input type="number" name="child1_age" id="edit_child1_age" class="form-control" placeholder="Age"></div>
                        </div>

                        <div class="row mb-2">
                            <div class="col-md-1"><label class="form-label">2.</label></div>
                            <div class="col-md-3"><input type="text" name="child2_fname" id="edit_child2_fname" class="form-control" placeholder="First Name"></div>
                            <div class="col-md-3"><input type="text" name="child2_mname" id="edit_child2_mname" class="form-control" placeholder="Middle Name"></div>
                            <div class="col-md-3"><input type="text" name="child2_lname" id="edit_child2_lname" class="form-control" placeholder="Last Name"></div>
                            <div class="col-md-2"><input type="number" name="child2_age" id="edit_child2_age" class="form-control" placeholder="Age"></div>
                        </div>

                        <div class="row mb-2">
                            <div class="col-md-1"><label class="form-label">3.</label></div>
                            <div class="col-md-3"><input type="text" name="child3_fname" id="edit_child3_fname" class="form-control" placeholder="First Name"></div>
                            <div class="col-md-3"><input type="text" name="child3_mname" id="edit_child3_mname" class="form-control" placeholder="Middle Name"></div>
                            <div class="col-md-3"><input type="text" name="child3_lname" id="edit_child3_lname" class="form-control" placeholder="Last Name"></div>
                            <div class="col-md-2"><input type="number" name="child3_age" id="edit_child3_age" class="form-control" placeholder="Age"></div>
                        </div>

                        <div class="row mb-3">
                            <div class="col-md-1"><label class="form-label">4.</label></div>
                            <div class="col-md-3"><input type="text" name="child4_fname" id="edit_child4_fname" class="form-control" placeholder="First Name"></div>
                            <div class="col-md-3"><input type="text" name="child4_mname" id="edit_child4_mname" class="form-control" placeholder="Middle Name"></div>
                            <div class="col-md-3"><input type="text" name="child4_lname" id="edit_child4_lname" class="form-control" placeholder="Last Name"></div>
                            <div class="col-md-2"><input type="number" name="child4_age" id="edit_child4_age" class="form-control" placeholder="Age"></div>
                        </div>

                        <!-- References -->
                        <div class="section-title">VI. CHARACTER REFERENCES</div>
                        
                        <div class="row mb-2">
                            <div class="col-md-5">
                                <label class="form-label">Name of Reference 1</label>
                                <input type="text" name="ref1_name" id="edit_ref1_name" class="form-control" placeholder="Full Name">
                            </div>
                            <div class="col-md-7">
                                <label class="form-label">Contact Number</label>
                                <input type="text" name="ref1_contact" id="edit_ref1_contact" class="form-control" placeholder="Contact Number">
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-5">
                                <label class="form-label">Name of Reference 2</label>
                                <input type="text" name="ref2_name" id="edit_ref2_name" class="form-control" placeholder="Full Name">
                            </div>
                            <div class="col-md-7">
                                <label class="form-label">Contact Number</label>
                                <input type="text" name="ref2_contact" id="edit_ref2_contact" class="form-control" placeholder="Contact Number">
                            </div>
                        </div>

                        <!-- Beneficiary -->
                        <div class="section-title">VII. BENEFICIARY INFORMATION</div>
                        
                        <div class="row mb-2">
                            <div class="col-md-4">
                                <label class="form-label">Full Name of Beneficiary</label>
                                <input type="text" name="beneficiary_name" id="edit_beneficiary_name" class="form-control" placeholder="Full Name">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Complete Address</label>
                                <input type="text" name="beneficiary_address" id="edit_beneficiary_address" class="form-control" placeholder="Address">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Relationship</label>
                                <input type="text" name="beneficiary_relation" id="edit_beneficiary_relation" class="form-control" placeholder="Relation">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Age</label>
                                <input type="number" name="beneficiary_age" id="edit_beneficiary_age" class="form-control" placeholder="Age">
                            </div>
                        </div>
                        
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <label class="form-label">Contact Number of Beneficiary</label>
                                <input type="text" name="beneficiary_contact" id="edit_beneficiary_contact" class="form-control" placeholder="Contact">
                            </div>
                        </div>

                        <!-- Chapter Information -->
                        <div class="section-title">VIII. CHAPTER INFORMATION</div>
                        
                        <div class="row mb-2">
                            <div class="col-md-3">
                                <label class="form-label">Chapter</label>
                                <select name="chapter" id="edit_chapter" class="form-select">
                                    <option value="">Select Chapter</option>
                                    <?php foreach ($chapters as $chap): ?>
                                        <option value="<?php echo htmlspecialchars($chap); ?>"><?php echo htmlspecialchars($chap); ?></option>
                                    <?php endforeach; ?>
                                    <option value="GUIMBA">GUIMBA</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Group Name</label>
                                <input type="text" name="group_name" id="edit_group_name" class="form-control" placeholder="Group Name">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Leader</label>
                                <input type="text" name="leader" id="edit_leader" class="form-control" placeholder="Leader" list="leaderListEdit">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Coordinator</label>
                                <input type="text" name="coordinator" id="edit_coordinator" class="form-control" placeholder="Coordinator" list="coordinatorListEdit">
                            </div>
                        </div>
                        
                        <div class="row mb-2">
                            <div class="col-md-3">
                                <label class="form-label">Chairman</label>
                                <input type="text" name="chairman" id="edit_chairman" class="form-control" placeholder="Chairman" list="chairmanListEdit">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Screening Officer</label>
                                <input type="text" name="screening_officer" id="edit_screening_officer" class="form-control" placeholder="Screening Officer" list="screenerListEdit">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Screening Date</label>
                                <input type="date" name="screening_date" id="edit_screening_date" class="form-control">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Approved By</label>
                                <input type="text" name="approved_by" id="edit_approved_by" class="form-control" placeholder="Approved By">
                            </div>
                        </div>
                        
                        <div class="row mb-2">
                            <div class="col-md-3">
                                <label class="form-label">Date Registered</label>
                                <input type="date" name="date_registered" id="edit_date_registered" class="form-control">
                            </div>
                        </div>

                        <!-- Account Information -->
                        <div class="section-title">IX. ACCOUNT INFORMATION</div>
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <label class="form-label">Username</label>
                                <input type="text" name="username" id="edit_username" class="form-control" readonly disabled>
                                <small class="text-muted">Username cannot be changed</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="modal-footer py-2">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary">Update Member</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

   <!-- View Member Details Modal -->
<div class="modal fade" id="viewMemberModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header bg-info text-white py-2">
                <h5 class="modal-title"><i class="fas fa-file-alt me-2"></i>Application for Membership</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4" id="memberDetailsContent">
                <div class="text-center p-5">
                    <div class="spinner-border text-info" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer py-2">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-info" onclick="window.print()"><i class="fas fa-print me-1"></i>Print</button>
            </div>
        </div>
    </div>
</div>

   <!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteMemberModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="singleDeleteForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="member_code" id="delete_member_code">
                <div class="modal-header bg-danger text-white py-2">
                    <h5 class="modal-title"><i class="fas fa-exclamation-triangle me-2"></i>Confirm Delete</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Are you sure you want to delete <strong id="delete_member_name"></strong>?</p>
                    <div class="alert alert-warning">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>What will happen:</strong>
                        <ul class="mb-0 mt-2">
                            <li>The member will be moved to <strong>Deleted History</strong></li>
                            <li>All payment records will be removed</li>
                            <li>The member can be <strong>recovered within 50 days</strong></li>
                            <li>After 50 days, the record will be permanently deleted</li>
                        </ul>
                    </div>
                    <p class="text-danger mb-0"><small>⚠️ This action cannot be undone immediately, but recovery is possible within 50 days.</small></p>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Yes, Delete Member</button>
                </div>
            </form>
        </div>
    </div>
</div>

    <!-- Inactive Confirmation Modal -->
    <div class="modal fade" id="inactiveMemberModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" id="inactiveMemberForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="inactive">
                    <input type="hidden" name="member_code" id="inactive_member_code">
                    <input type="hidden" name="current_status" id="inactive_current_status">
                    
                    <div class="modal-header bg-warning text-white py-2">
                        <h5 class="modal-title"><i class="fas fa-pause-circle me-2"></i>Set Member Inactive</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    
                    <div class="modal-body">
                        <p>Are you sure you want to set <strong id="inactive_member_name"></strong> as <span class="badge bg-warning text-dark">INACTIVE</span>?</p>
                        
                        <div class="mb-3">
                            <label for="inactive_reason" class="form-label">Reason for Inactivity <span class="text-danger">*</span></label>
                            <select name="inactive_reason" id="inactive_reason" class="form-select" required>
                                <option value="">Select a reason</option>
                                <option value="Voluntary withdrawal">Voluntary withdrawal</option>
                                <option value="Failure to pay contributions">Failure to pay contributions</option>
                                <option value="Moved to other location">Moved to other location</option>
                                <option value="Other">Other (specify below)</option>
                            </select>
                        </div>
                        
                        <div class="mb-3" id="other_reason_container" style="display: none;">
                            <label for="inactive_other_reason" class="form-label">Please specify</label>
                            <input type="text" name="inactive_other_reason" id="inactive_other_reason" class="form-control" placeholder="Enter reason">
                        </div>
                        
                        <div class="mb-3">
                            <label for="inactive_notes" class="form-label">Additional Notes</label>
                            <textarea name="inactive_notes" id="inactive_notes" class="form-control" rows="2" placeholder="Any additional information..."></textarea>
                        </div>
                        
                        <p class="text-warning mb-0 mt-3">
                            <small><i class="fas fa-info-circle me-1"></i> Setting a member as inactive will prevent them from making new payments and accessing their account.</small>
                        </p>
                    </div>
                    
                    <div class="modal-footer py-2">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-warning" id="inactive_submit_btn">Set Inactive</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Deceased Member Modal -->
    <div class="modal fade" id="deceasedMemberModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" id="deceasedMemberForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="deceased">
                    <input type="hidden" name="member_code" id="deceased_member_code">
                    
                    <div class="modal-header bg-secondary text-white py-2">
                        <h5 class="modal-title"><i class="fas fa-cross me-2"></i>Mark Member as Deceased</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    
                    <div class="modal-body">
                        <p>Please provide death details for <strong id="deceased_member_name"></strong>:</p>
                        
                        <div class="mb-3">
                            <label for="death_date" class="form-label">Date of Death <span class="text-danger">*</span></label>
                            <input type="date" name="death_date" id="death_date" class="form-control" required>
                        </div>
                        
                        <div class="mb-3">
                            <label for="cause_of_death" class="form-label">Cause of Death</label>
                            <input type="text" name="cause_of_death" id="cause_of_death" class="form-control" placeholder="e.g., Natural causes, Accident, Illness">
                        </div>
                        
                        <div class="mb-3">
                            <label for="death_certificate" class="form-label">Death Certificate Number</label>
                            <input type="text" name="death_certificate" id="death_certificate" class="form-control" placeholder="Certificate number">
                        </div>
                        
                        <div class="mb-3">
                            <label for="place_of_death" class="form-label">Place of Death</label>
                            <input type="text" name="place_of_death" id="place_of_death" class="form-control" placeholder="Hospital, address, etc.">
                        </div>
                        
                        <div class="mb-3">
                            <label for="burial_place" class="form-label">Burial Place</label>
                            <input type="text" name="burial_place" id="burial_place" class="form-control" placeholder="Cemetery name and location">
                        </div>
                        
                        <div class="mb-3">
                            <label for="burial_date" class="form-label">Burial Date</label>
                            <input type="date" name="burial_date" id="burial_date" class="form-control">
                        </div>
                        
                        <div class="mb-3">
                            <label for="death_notes" class="form-label">Additional Notes</label>
                            <textarea name="death_notes" id="death_notes" class="form-control" rows="2" placeholder="Any additional information..."></textarea>
                        </div>
                        
                        <p class="text-secondary mb-0 mt-3">
                            <small><i class="fas fa-info-circle me-1"></i> Marking a member as deceased will move them to deceased history and prevent login access.</small>
                        </p>
                    </div>
                    
                    <div class="modal-footer py-2">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-secondary">Mark as Deceased</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Reactivate Confirmation Modal -->
    <div class="modal fade" id="reactivateMemberModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" id="reactivateMemberForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action" value="reactivate">
                    <input type="hidden" name="member_code" id="reactivate_member_code">
                    
                    <div class="modal-header bg-success text-white py-2">
                        <h5 class="modal-title"><i class="fas fa-play-circle me-2"></i>Reactivate Member</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    
                    <div class="modal-body">
                        <p>Are you sure you want to reactivate <strong id="reactivate_member_name"></strong>?</p>
                        <p class="text-success mb-0"><small><i class="fas fa-info-circle me-1"></i> Reactivating will allow the member to make payments and access their account again.</small></p>
                    </div>
                    
                    <div class="modal-footer py-2">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-success">Reactivate</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Datalists for auto-complete -->
    <datalist id="leaderList">
        <?php foreach ($council_members as $council): ?>
            <option value="<?php echo htmlspecialchars($council['first_name'] . ' ' . $council['last_name']); ?>">
        <?php endforeach; ?>
    </datalist>
    
    <datalist id="coordinatorList">
        <?php foreach ($council_members as $council): ?>
            <option value="<?php echo htmlspecialchars($council['first_name'] . ' ' . $council['last_name']); ?>">
        <?php endforeach; ?>
    </datalist>
    
    <datalist id="chairmanList">
        <?php foreach ($council_members as $council): ?>
            <option value="<?php echo htmlspecialchars($council['first_name'] . ' ' . $council['last_name']); ?>">
        <?php endforeach; ?>
    </datalist>
    
    <datalist id="screenerList">
        <?php foreach ($council_members as $council): ?>
            <option value="<?php echo htmlspecialchars($council['first_name'] . ' ' . $council['last_name']); ?>">
        <?php endforeach; ?>
    </datalist>
    
    <datalist id="leaderListEdit">
        <?php foreach ($council_members as $council): ?>
            <option value="<?php echo htmlspecialchars($council['first_name'] . ' ' . $council['last_name']); ?>">
        <?php endforeach; ?>
    </datalist>
    
    <datalist id="coordinatorListEdit">
        <?php foreach ($council_members as $council): ?>
            <option value="<?php echo htmlspecialchars($council['first_name'] . ' ' . $council['last_name']); ?>">
        <?php endforeach; ?>
    </datalist>
    
    <datalist id="chairmanListEdit">
        <?php foreach ($council_members as $council): ?>
            <option value="<?php echo htmlspecialchars($council['first_name'] . ' ' . $council['last_name']); ?>">
        <?php endforeach; ?>
    </datalist>
    
    <datalist id="screenerListEdit">
        <?php foreach ($council_members as $council): ?>
            <option value="<?php echo htmlspecialchars($council['first_name'] . ' ' . $council['last_name']); ?>">
        <?php endforeach; ?>
    </datalist>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        // Pass PHP variables to JavaScript
        window.citiesData = <?php echo json_encode($cities_data); ?>;
        window.errorMessage = '<?php echo addslashes($error); ?>';
    </script>
    <script src="members/members.js"></script>
   
<script>
    // Debug: Check if jQuery is working
    console.log('jQuery version:', $.fn.jquery);
    console.log('Edit buttons found:', $('.edit-btn').length);
    
    // Force check on click
    $(document).on('click', '.edit-btn', function(e) {
        console.log('Button clicked, this is:', this);
        console.log('jQuery object of this:', $(this));
        console.log('data-id:', $(this).data('id'));
    });
    // Edit Member Function
function editMember(element) {
    event.stopPropagation();
    var memberCode = $(element).data('code') || $(element).data('id');
    console.log('Editing member with code:', memberCode);
    
    if (!memberCode) {
        console.error('No member code found');
        return;
    }
    
    // Fetch member data via AJAX
    $.ajax({
        url: 'get_member.php',
        type: 'POST',
        data: { code: memberCode },
        dataType: 'json',
        success: function(memberData) {
            console.log('Member data received:', memberData);
            
            if (memberData.error) {
                alert('Error loading member data: ' + memberData.error);
                return;
            }
            
            // Populate edit modal fields
            $('#edit_member_code').val(memberData.member_code || '');
            $('#edit_member_code_display').val(memberData.member_code || '');
            $('#edit_first_name').val(memberData.first_name || '');
            $('#edit_last_name').val(memberData.last_name || '');
            $('#edit_middle_name').val(memberData.middle_name || '');
            
            // Address
            $('#edit_province').val(memberData.province || 'Occidental Mindoro');
            updateCities('edit');
            setTimeout(function() {
                $('#edit_city').val(memberData.city || '');
            }, 100);
            $('#edit_barangay').val(memberData.barangay || '');
            $('#edit_street').val(memberData.street || '');
            $('#edit_present_address').val(memberData.present_address || '');
            $('#edit_permanent_address').val(memberData.permanent_address || '');
            
            // Contact
            $('#edit_contact').val(memberData.contact_number || '');
            $('#edit_alternate').val(memberData.alternate_number || '');
            $('#edit_email').val(memberData.email || '');
            
            // Personal
            $('#edit_birth_date').val(memberData.birth_date || '');
            $('#edit_place_of_birth').val(memberData.place_of_birth || '');
            $('#edit_age').val(memberData.age || '');
            $('#edit_gender').val(memberData.gender || '');
            $('#edit_civil_status').val(memberData.civil_status || '');
            $('#edit_religion').val(memberData.religion || '');
            
            // Family - Father
            $('#edit_father_fname').val(memberData.father_fname || '');
            $('#edit_father_mname').val(memberData.father_mname || '');
            $('#edit_father_lname').val(memberData.father_lname || '');
            
            // Family - Mother
            $('#edit_mother_fname').val(memberData.mother_fname || '');
            $('#edit_mother_mname').val(memberData.mother_mname || '');
            $('#edit_mother_lname').val(memberData.mother_lname || '');
            
            // Spouse
            $('#edit_spouse_fname').val(memberData.spouse_fname || '');
            $('#edit_spouse_mname').val(memberData.spouse_mname || '');
            $('#edit_spouse_lname').val(memberData.spouse_lname || '');
            $('#edit_spouse_age').val(memberData.spouse_age || '');
            
            // Children
            $('#edit_child1_fname').val(memberData.child1_fname || '');
            $('#edit_child1_mname').val(memberData.child1_mname || '');
            $('#edit_child1_lname').val(memberData.child1_lname || '');
            $('#edit_child1_age').val(memberData.child1_age || '');
            
            $('#edit_child2_fname').val(memberData.child2_fname || '');
            $('#edit_child2_mname').val(memberData.child2_mname || '');
            $('#edit_child2_lname').val(memberData.child2_lname || '');
            $('#edit_child2_age').val(memberData.child2_age || '');
            
            $('#edit_child3_fname').val(memberData.child3_fname || '');
            $('#edit_child3_mname').val(memberData.child3_mname || '');
            $('#edit_child3_lname').val(memberData.child3_lname || '');
            $('#edit_child3_age').val(memberData.child3_age || '');
            
            $('#edit_child4_fname').val(memberData.child4_fname || '');
            $('#edit_child4_mname').val(memberData.child4_mname || '');
            $('#edit_child4_lname').val(memberData.child4_lname || '');
            $('#edit_child4_age').val(memberData.child4_age || '');
            
            // References
            $('#edit_ref1_name').val(memberData.ref1_name || '');
            $('#edit_ref1_contact').val(memberData.ref1_contact || '');
            $('#edit_ref2_name').val(memberData.ref2_name || '');
            $('#edit_ref2_contact').val(memberData.ref2_contact || '');
            
            // Chapter info
            $('#edit_chapter').val(memberData.chapter || '');
            $('#edit_group_name').val(memberData.group_name || '');
            $('#edit_leader').val(memberData.leader || '');
            $('#edit_coordinator').val(memberData.coordinator || '');
            $('#edit_chairman').val(memberData.chairman || '');
            $('#edit_screening_officer').val(memberData.screening_officer || '');
            $('#edit_screening_date').val(memberData.screening_date || '');
            $('#edit_approved_by').val(memberData.approved_by || '');
            $('#edit_date_joined').val(memberData.date_joined || '');
            $('#edit_date_registered').val(memberData.date_registered || '');
            $('#edit_monthly').val(memberData.monthly_contribution || '100.00');
            $('#edit_status').val(memberData.status || 'active');
            
            // Beneficiary
            $('#edit_beneficiary_name').val(memberData.beneficiary_name || '');
            $('#edit_beneficiary_address').val(memberData.beneficiary_address || '');
            $('#edit_beneficiary_relation').val(memberData.beneficiary_relation || '');
            $('#edit_beneficiary_age').val(memberData.beneficiary_age || '');
            $('#edit_beneficiary_contact').val(memberData.beneficiary_contact || '');
            
            // Account Info
            $('#edit_username').val(memberData.username || '');
            
            // Documents
            $('#edit_medical_certificate').prop('checked', memberData.medical_certificate == 1);
            $('#edit_birth_certificate').prop('checked', memberData.birth_certificate == 1);
            
            // Show the modal
            $('#editMemberModal').modal('show');
        },
        error: function(xhr, status, error) {
            console.error('AJAX Error:', error);
            console.error('Response:', xhr.responseText);
            alert('Error loading member data. Please try again.');
        }
    });
}
</script>
</body>

</html>