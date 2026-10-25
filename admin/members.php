<?php
// admin/members.php
// Enable error reporting for debugging (remove after fixing)
error_reporting(E_ALL);
ini_set('display_errors', 1);
require_once '../includes/config.php';
require_once '../includes/db_connection.php';
require_once '../includes/security.php';
require_once '../includes/auth.php';
require_once '../includes/settings.php';

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
                                          contact_number LIKE ? OR 
                                          email LIKE ?)";
                $like_term = "%$term%";
                for ($i = 0; $i < 9; $i++) {
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
    $history_conditions = ["action_type IN ('edit', 'add', 'revert')"];
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
        $history_conditions[] = "member_code LIKE ?";
        $history_params[] = "%$history_member%";
        $history_types .= 's';
    }
    if (!empty($history_admin)) {
        $history_conditions[] = "admin_name LIKE ?";
        $history_params[] = "%$history_admin%";
        $history_types .= 's';
    }
    
    $history_where = !empty($history_conditions) ? "WHERE " . implode(" AND ", $history_conditions) : "";
    
    // Get total history records
    $history_count_sql = "SELECT COUNT(*) as total FROM member_audit_log $history_where";
    $history_count_result = $db->getSingle($history_count_sql, $history_params, $history_types);
    $history_total = $history_count_result['total'] ?? 0;
    
    $history_total_pages = ceil($history_total / $limit);
    $history_offset = ($page - 1) * $limit;
    
    // Fetch history records with changes
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
    
       $history_sql = "SELECT 
                        m.*,
                        d.death_date        AS death_date_record,
                        d.cause_of_death    AS cause_of_death,
                        d.death_certificate AS death_certificate,
                        d.place_of_death    AS place_of_death,
                        d.burial_place      AS burial_place,
                        d.burial_date       AS burial_date,
                        d.notes             AS death_notes,
                        d.recorded_by       AS recorded_by
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

// ============================================================
// Handle POST requests (CRUD operations)
// ============================================================
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
            $default_monthly = Settings::get('membership.default_monthly_contribution', 100.00);
$monthly_contribution = isset($_POST['monthly_contribution']) && $_POST['monthly_contribution'] !== ''
    ? floatval($_POST['monthly_contribution'])
    : $default_monthly;
            
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
                            throw new Exception('Failed to add member: ' . $db_error);
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
                        // Build detailed changes for audit log
                        $changes = [];
                        if ($old_data) {
                            // Define fields to compare (exclude internal fields)
                            $exclude_fields = ['created_at', 'updated_at', 'created_by', 'updated_by'];
                            
                            foreach ($old_data as $key => $old_value) {
                                if (in_array($key, $exclude_fields)) continue;
                                
                                // Get new value from POST
                                $new_value = $_POST[$key] ?? null;
                                
                                // Special handling for checkboxes
                                if (in_array($key, ['medical_certificate', 'birth_certificate'])) {
                                    $new_value = isset($_POST[$key]) ? 1 : 0;
                                }
                                
                                // Compare values (skip if both are empty/null)
                                if ($old_value != $new_value) {
                                    $changes[$key] = [
                                        'old' => $old_value ?? '',
                                        'new' => $new_value ?? ''
                                    ];
                                }
                            }
                        }
                        
                        // Only log if there are actual changes
                        if (!empty($changes)) {
                            $db->execute(
                                "INSERT INTO member_audit_log (member_code, action_type, changes, admin_user_id, admin_name, ip_address, created_at) 
                                 VALUES (?, 'edit', ?, ?, ?, ?, NOW())",
                                [$member_code, json_encode($changes), $current_user['user_id'], $current_user['username'], $_SERVER['REMOTE_ADDR']],
                                'ssiss'
                            );
                        }
                        
                        $message = 'Member updated successfully.';
                        Security::logEvent('MEMBER_UPDATE', "Updated member: $member_code");
                        header('Location: members.php?success=updated&limit=' . $limit . '&page=' . $page . '&view=' . $view_mode);
                        exit();
                    } else {
                        $error = 'Failed to update member.';
                    }
                } catch (Exception $e) {
                    $error = 'Database error: ' . $e->getMessage();
                    error_log('Member update error: ' . $e->getMessage());
                }
            }
                    } elseif ($action === 'inactive') {
            // ============================================================
            // SET MEMBER AS INACTIVE
            // - Nilalagay ang status = 'inactive'
            // - Nilalagay ang inactive_reason, inactive_notes, inactive_date
            // - Pagkatapos, lalabas na siya sa Inactive History
            // ============================================================
            $member_code           = Security::sanitize($_POST['member_code'] ?? '');
            $inactive_reason       = Security::sanitize($_POST['inactive_reason'] ?? '');
            $inactive_other_reason = Security::sanitize($_POST['inactive_other_reason'] ?? '');
            $inactive_notes        = Security::sanitize($_POST['inactive_notes'] ?? '');
            
            if (empty($member_code)) {
                $error = 'No member selected.';
            } elseif (empty($inactive_reason)) {
                $error = 'Please select a reason for inactivity.';
            } else {
                // Kung "Other" ang napili, gamitin ang custom reason text
                $final_reason = ($inactive_reason === 'Other' && !empty($inactive_other_reason))
                    ? $inactive_other_reason
                    : $inactive_reason;
                
                $db->getConnection()->begin_transaction();
                try {
                    // I-check kung existing at active pa
                    $existing = $db->getSingle(
                        "SELECT member_code FROM members WHERE member_code = ? AND status = 'active'",
                        [$member_code], 's'
                    );
                    
                    if (!$existing) {
                        throw new Exception('Member not found or not active.');
                    }
                    
                    // I-update ang member: set inactive + reason + notes + date
                    $update_sql = "UPDATE members SET 
                                    status = 'inactive',
                                    inactive_reason = ?,
                                    inactive_notes = ?,
                                    inactive_date = NOW(),
                                    updated_by = ?,
                                    updated_at = NOW()
                                   WHERE member_code = ? AND status = 'active'";
                    
                    $result = $db->execute(
                        $update_sql,
                        [$final_reason, $inactive_notes, $current_user['user_id'], $member_code],
                        'ssis'
                    );
                    
                    if (!$result) {
                        throw new Exception('Failed to set member as inactive: ' . $db->getConnection()->error);
                    }
                    
                    // Audit log entry
                    $db->execute(
                        "INSERT INTO member_audit_log 
                            (member_code, action_type, changes, admin_user_id, admin_name, ip_address, created_at) 
                         VALUES (?, 'inactive', ?, ?, ?, ?, NOW())",
                        [
                            $member_code,
                            json_encode([
                                'reason' => $final_reason,
                                'notes'  => $inactive_notes,
                                'date'   => date('Y-m-d H:i:s')
                            ]),
                            $current_user['user_id'],
                            $current_user['username'],
                            $_SERVER['REMOTE_ADDR']
                        ],
                        'ssiss'
                    );
                    
                    $db->getConnection()->commit();
                    Security::logEvent('MEMBER_INACTIVE', "Set member inactive: $member_code");
                    header('Location: members.php?success=inactive&limit=' . $limit . '&page=' . $page);
                    exit();
                    
                } catch (Exception $e) {
                    $db->getConnection()->rollback();
                    $error = 'Database error: ' . $e->getMessage();
                    error_log('Inactive error: ' . $e->getMessage());
                }
            }
            
               } elseif ($action === 'deceased') {
            // ============================================================
            // MARK MEMBER AS DECEASED (with full error checking)
            // ============================================================
            $member_code       = Security::sanitize($_POST['member_code'] ?? '');
            $death_date        = !empty($_POST['death_date']) ? $_POST['death_date'] : null;
            $cause_of_death    = Security::sanitize($_POST['cause_of_death'] ?? '');
            $death_certificate = Security::sanitize($_POST['death_certificate'] ?? '');
            $place_of_death    = Security::sanitize($_POST['place_of_death'] ?? '');
            $burial_place      = Security::sanitize($_POST['burial_place'] ?? '');
            $burial_date       = !empty($_POST['burial_date']) ? $_POST['burial_date'] : null;
            $death_notes       = Security::sanitize($_POST['death_notes'] ?? '');
            
            if (empty($member_code) || empty($death_date)) {
                $error = 'Member and date of death are required.';
            } else {
                $db->getConnection()->begin_transaction();
                try {
                    // ---- 1. Update members.status ----
                    $update_result = $db->execute(
                        "UPDATE members SET 
                            status = 'deceased',
                            updated_by = ?,
                            updated_at = NOW()
                         WHERE member_code = ?",
                        [$current_user['user_id'], $member_code],
                        'is'
                    );
                    
                    if ($update_result === false) {
                        throw new Exception('Failed to update member status: ' . $db->getConnection()->error);
                    }
                    
                    // Check kung may na-update na row (para siguradong existing ang member)
                    if ($db->getConnection()->affected_rows === 0) {
                        throw new Exception('Member not found or status already deceased.');
                    }
                    
                    // ---- 2. Insert/update deceased_details ----
                    $detail_result = $db->execute(
                        "INSERT INTO deceased_details 
                            (member_code, death_date, cause_of_death, death_certificate, 
                             place_of_death, burial_place, burial_date, notes, recorded_by, created_at) 
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                         ON DUPLICATE KEY UPDATE
                            death_date = VALUES(death_date),
                            cause_of_death = VALUES(cause_of_death),
                            death_certificate = VALUES(death_certificate),
                            place_of_death = VALUES(place_of_death),
                            burial_place = VALUES(burial_place),
                            burial_date = VALUES(burial_date),
                            notes = VALUES(notes),
                            recorded_by = VALUES(recorded_by)",
                        [
                            $member_code, $death_date, $cause_of_death, $death_certificate,
                            $place_of_death, $burial_place, $burial_date, $death_notes,
                            $current_user['user_id']
                        ],
                        'ssssssssi'
                    );
                    
                    if ($detail_result === false) {
                        throw new Exception('Failed to save death details: ' . $db->getConnection()->error);
                    }
                    
                    // ---- 3. Audit log ----
                    $db->execute(
                        "INSERT INTO member_audit_log 
                            (member_code, action_type, changes, admin_user_id, admin_name, ip_address, created_at) 
                         VALUES (?, 'deceased', ?, ?, ?, ?, NOW())",
                        [
                            $member_code,
                            json_encode([
                                'death_date'     => $death_date,
                                'cause_of_death' => $cause_of_death,
                                'place_of_death' => $place_of_death
                            ]),
                            $current_user['user_id'],
                            $current_user['username'],
                            $_SERVER['REMOTE_ADDR']
                        ],
                        'ssiss'
                    );
                    
                    $db->getConnection()->commit();
                    Security::logEvent('MEMBER_DECEASED', "Marked member as deceased: $member_code");
                    header('Location: members.php?success=deceased&limit=' . $limit . '&page=' . $page);
                    exit();
                    
                } catch (Exception $e) {
                    $db->getConnection()->rollback();
                    $error = 'Database error: ' . $e->getMessage();
                    error_log('Deceased error: ' . $e->getMessage());
                    error_log('Stack trace: ' . $e->getTraceAsString());
                }
            }
        }
              elseif ($action === 'revert') {
            // ============================================================
            // REVERT FUNCTIONALITY (selective, with Receipt)
            // - Kung may $_POST['selected_fields'], yun lang ang ire-revert
            // - Kung wala, lahat ng fields sa log ang ire-revert
            // - Inaalis lang sa original log ang mga na-revert na fields
            // - Nag-insert ng bagong 'revert' log bilang RESIBO
            // ============================================================
            $log_id = (int)$_POST['log_id'];
            $log = $db->getSingle("SELECT * FROM member_audit_log WHERE log_id = ?", [$log_id], 'i');

            $selected_fields = [];
            if (isset($_POST['selected_fields']) && is_array($_POST['selected_fields'])) {
                foreach ($_POST['selected_fields'] as $sf) {
                    $sf = Security::sanitize($sf);
                    if (!empty($sf)) $selected_fields[] = $sf;
                }
            }

            if (!$log) {
                $error = 'History record not found.';
            } elseif ($log['action_type'] !== 'edit') {
                $error = 'Only edit records can be reverted.';
            } else {
                $db->getConnection()->begin_transaction();
                try {
                    $changes = json_decode($log['changes'], true);

                    if (!is_array($changes) || empty($changes)) {
                        throw new Exception('No changes found in this log entry.');
                    }

                    $skip_fields = ['member_code', 'created_at', 'updated_at', 'created_by', 'updated_by'];

                    $fields_to_revert = [];
                    foreach ($changes as $field => $values) {
                        if (in_array($field, $skip_fields)) continue;
                        if (!is_array($values) || !array_key_exists('old', $values)) continue;
                        if (!empty($selected_fields) && !in_array($field, $selected_fields)) continue;
                        $fields_to_revert[$field] = $values;
                    }

                    if (empty($fields_to_revert)) {
                        throw new Exception('No valid fields selected to revert.');
                    }

                    $update_fields = [];
                    $update_values = [];
                    $reverted_changes = [];

                    foreach ($fields_to_revert as $field => $values) {
                        $update_fields[] = "`$field` = ?";
                        $update_values[] = $values['old'];
                        $reverted_changes[$field] = [
                            'from' => $values['new'] ?? '',
                            'to'   => $values['old'] ?? ''
                        ];
                    }

                    $update_values[] = $current_user['user_id'];
                    $update_values[] = $log['member_code'];

                    $revert_sql = "UPDATE members SET " . implode(", ", $update_fields)
                                . ", updated_by = ?, updated_at = NOW() WHERE member_code = ?";

                    $types = str_repeat('s', count($update_values) - 2) . 'is';

                    $result = $db->execute($revert_sql, $update_values, $types);

                    if ($result === false) {
                        throw new Exception('Failed to update member data: ' . $db->getConnection()->error);
                    }

                    // Alisin sa original log ang mga na-revert na fields
                    $remaining_changes = $changes;
                    foreach (array_keys($reverted_changes) as $revertedField) {
                        unset($remaining_changes[$revertedField]);
                    }

                    if (empty($remaining_changes)) {
                        $db->execute("DELETE FROM member_audit_log WHERE log_id = ?", [$log_id], 'i');
                    } else {
                        $db->execute(
                            "UPDATE member_audit_log SET changes = ? WHERE log_id = ?",
                            [json_encode($remaining_changes), $log_id],
                            'si'
                        );
                    }

                    $receipt = [
                        'reverted_log_id'  => $log_id,
                        'original_action'  => $log['action_type'],
                        'original_admin'   => $log['admin_name'] ?? 'System',
                        'original_date'    => $log['created_at'],
                        'reverted_fields'  => $reverted_changes,
                        'reverted_by'      => $current_user['username'],
                        'reverted_at'      => date('Y-m-d H:i:s'),
                        'partial_revert'   => !empty($selected_fields),
                        'remaining_fields' => array_keys($remaining_changes)
                    ];

                    $db->execute(
                        "INSERT INTO member_audit_log
                            (member_code, action_type, changes, admin_user_id, admin_name, ip_address, created_at)
                         VALUES (?, 'revert', ?, ?, ?, ?, NOW())",
                        [
                            $log['member_code'],
                            json_encode($receipt),
                            $current_user['user_id'],
                            $current_user['username'],
                            $_SERVER['REMOTE_ADDR']
                        ],
                        'ssiss'
                    );

                    $db->getConnection()->commit();
                    $message = 'Member data reverted successfully! A revert receipt was saved in Edit History.';
                    Security::logEvent('MEMBER_REVERT', "Reverted member: " . $log['member_code'] . " from log ID: " . $log_id);
                    header('Location: members.php?success=reverted&view=edit_history&limit=' . $limit . '&page=' . $page);
                    exit();

                } catch (Exception $e) {
                    $db->getConnection()->rollback();
                    $error = 'Database error: ' . $e->getMessage();
                    error_log('Revert error: ' . $e->getMessage());
                    error_log('Stack trace: ' . $e->getTraceAsString());
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
                            $db->execute("DELETE FROM payments WHERE member_id = ?", [$member_code], 's');
                            
                            // Delete the member
                            $result = $db->execute("DELETE FROM members WHERE member_code = ?", [$member_code], 's');
                            
                            if ($result) {
                                $deleted_count++;
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
                    
                    // Delete from payments
                    $db->execute("DELETE FROM payments WHERE member_id = ?", [$member_code], 's');
                    
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
              } elseif ($action === 'undo_death') {
            // ============================================================
            // UNDO DEATH — reactivate a deceased member
            // - Sets status back to 'active'
            // - Removes the deceased_details record
            // - Logs the action
            // NOTE: death_date / cause_of_death / death_certificate live in
            //       deceased_details (NOT on members), so we must NOT try to
            //       NULL them on the members table.
            // ============================================================
            $member_code = Security::sanitize($_POST['member_code'] ?? '');

            if (empty($member_code)) {
                $error = 'No member selected.';
            } else {
                $db->getConnection()->begin_transaction();
                try {
                    // Confirm the member really exists and is deceased
                    $existing = $db->getSingle(
                        "SELECT member_code FROM members WHERE member_code = ? AND status = 'deceased'",
                        [$member_code],
                        's'
                    );

                    if (!$existing) {
                        throw new Exception('Member not found or is not currently marked as deceased.');
                    }

                    // 1. Flip status back to active (only touches members table)
                    $update_sql = "UPDATE members 
                                   SET status = 'active',
                                       updated_by = ?,
                                       updated_at = NOW()
                                   WHERE member_code = ? AND status = 'deceased'";

                    $result = $db->execute($update_sql, [$current_user['user_id'], $member_code], 'is');

                    if ($result === false) {
                        throw new Exception('Failed to reactivate member: ' . $db->getConnection()->error);
                    }

                    // 2. Remove the deceased_details row
                    $db->execute("DELETE FROM deceased_details WHERE member_code = ?", [$member_code], 's');

                    // 3. Audit log
                    $db->execute(
                        "INSERT INTO member_audit_log 
                            (member_code, action_type, changes, admin_user_id, admin_name, ip_address, created_at) 
                         VALUES (?, 'undeath', ?, ?, ?, ?, NOW())",
                        [
                            $member_code,
                            json_encode([
                                'undone_at' => date('Y-m-d H:i:s'),
                                'undone_by' => $current_user['username']
                            ]),
                            $current_user['user_id'],
                            $current_user['username'],
                            $_SERVER['REMOTE_ADDR']
                        ],
                        'ssiss'
                    );

                    $db->getConnection()->commit();
                    Security::logEvent('MEMBER_UNDEATH', "Undid death record for member: $member_code");

                    header('Location: members.php?success=undeath&view=deceased_history');
                    exit();

                } catch (Exception $e) {
                    $db->getConnection()->rollback();
                    $error = 'Database error: ' . $e->getMessage();
                    error_log('Undo death error: ' . $e->getMessage());
                    error_log('Stack trace: ' . $e->getTraceAsString());
                }
            }
        }
    }    
}
// ============================================================
// AJAX endpoint for getting edit details
// ============================================================
if (isset($_GET['get_edit_details']) && isset($_GET['log_id'])) {
    $log_id = (int)$_GET['log_id'];
    $log = $db->getSingle("SELECT * FROM member_audit_log WHERE log_id = ?", [$log_id], 'i');
    
    if ($log) {
        $current_member = $db->getSingle("SELECT * FROM members WHERE member_code = ?", [$log['member_code']], 's');
        $changes = json_decode($log['changes'], true);
        
        $response = [
            'success' => true,
            'log' => $log,
            'current_member' => $current_member,
            'changes' => $changes
        ];
        
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'Log not found']);
        exit;
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
// Get inactive member details (for Inactive History view modal)
if (isset($_GET['view_inactive_code'])) {
    $view_code = Security::sanitize($_GET['view_inactive_code']);
    $member_details = $db->getSingle(
        "SELECT * FROM members WHERE member_code = ? AND status = 'inactive'",
        [$view_code],
        's'
    );

    if ($member_details) {
        if ($member_details['birth_date'] == '0000-00-00') {
            $member_details['birth_date'] = null;
        }
        if (($member_details['inactive_date'] ?? '') == '0000-00-00 00:00:00') {
            $member_details['inactive_date'] = null;
        }

        // Fetch the most recent audit log entry that set this member inactive
        $inactive_log = $db->getSingle(
            "SELECT * FROM member_audit_log 
             WHERE member_code = ? AND action_type = 'inactive' 
             ORDER BY created_at DESC LIMIT 1",
            [$view_code],
            's'
        );
        $member_details['inactive_log'] = $inactive_log;

        header('Content-Type: application/json');
        echo json_encode($member_details);
        exit;
    } else {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Inactive member not found']);
        exit;
    }
}
// Get deceased member details (for Deceased History view modal)
if (isset($_GET['view_deceased_code'])) {
    $view_code = Security::sanitize($_GET['view_deceased_code']);
    $member_details = $db->getSingle(
        "SELECT * FROM members WHERE member_code = ? AND status = 'deceased'",
        [$view_code],
        's'
    );

    if ($member_details) {
        if ($member_details['birth_date'] == '0000-00-00') {
            $member_details['birth_date'] = null;
        }

        // Fetch deceased_details record
        $death_details = $db->getSingle(
            "SELECT d.*, u.username AS recorded_by_name 
             FROM deceased_details d
             LEFT JOIN users u ON d.recorded_by = u.user_id
             WHERE d.member_code = ?",
            [$view_code],
            's'
        );

        // If the join failed (no users table / different column), fall back to just the record
        if ($death_details === false || $death_details === null) {
            $death_details = $db->getSingle(
                "SELECT * FROM deceased_details WHERE member_code = ?",
                [$view_code],
                's'
            );
        }

        $member_details['deceased_details'] = $death_details;

        // Get the audit log entry that marked them deceased
        $death_log = $db->getSingle(
            "SELECT * FROM member_audit_log 
             WHERE member_code = ? AND action_type = 'deceased' 
             ORDER BY created_at DESC LIMIT 1",
            [$view_code],
            's'
        );
        $member_details['death_log'] = $death_log;

        header('Content-Type: application/json');
        echo json_encode($member_details);
        exit;
    } else {
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Deceased member not found']);
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
    <link rel="stylesheet" href="members/members.css?v=<?php echo time(); ?>">
        <!-- Prevent sidebar collapse flash on load -->
    <script>
        (function() {
            try {
                if (localStorage.getItem('sidebarCollapsed') === 'true') {
                    document.documentElement.classList.add('sidebar-was-collapsed');
                }
            } catch (e) {}
        })();
    </script>
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
                <a href="dashboard.php" class="list-group-item <?php echo $current_page == 'dashboard.php' ? 'active' : ''; ?>"><i class="fas fa-tachometer-alt"></i><span>Dashboard</span></a>
                <a href="members.php" class="list-group-item <?php echo $current_page == 'members.php' ? 'active' : ''; ?>"><i class="fas fa-users"></i><span>Members</span></a>
                <a href="manage_chapters.php" class="list-group-item <?php echo $current_page == 'manage_chapters.php' ? 'active' : ''; ?>"><i class="fas fa-sitemap"></i><span>Chapters &amp; Groups</span></a>
                <a href="leader_assignments.php" class="list-group-item <?php echo $current_page == 'leader_assignments.php' ? 'active' : ''; ?>"><i class="fas fa-user-shield"></i><span>Leader Assignment</span></a>
                <a href="council.php" class="list-group-item <?php echo $current_page == 'council.php' ? 'active' : ''; ?>"><i class="fas fa-user-tie"></i><span>Council</span></a>
                <a href="payments.php" class="list-group-item <?php echo $current_page == 'payments.php' ? 'active' : ''; ?>"><i class="fas fa-credit-card"></i><span>Payments</span></a>
                <a href="remittances.php" class="list-group-item <?php echo $current_page == 'remittances.php' ? 'active' : ''; ?>"><i class="fas fa-hand-holding-usd"></i><span>Remittances</span></a>
                <a href="reports.php" class="list-group-item <?php echo $current_page == 'reports.php' ? 'active' : ''; ?>"><i class="fas fa-chart-bar"></i><span>Reports</span></a>
                <a href="announcements.php" class="list-group-item <?php echo $current_page == 'announcements.php' ? 'active' : ''; ?>"><i class="fas fa-bullhorn"></i><span>Send Announcement</span></a>
                <?php if ($current_user['role'] === 'admin'): ?>
                
                <a href="pending_users.php" class="list-group-item <?php echo $current_page == 'pending_users.php' ? 'active' : ''; ?>">
                    <i class="fas fa-user-clock"></i><span>Pending</span>
                    <?php if ($pending_count > 0): ?>
                        <span class="badge bg-danger badge-count"><?php echo $pending_count; ?></span>
                    <?php endif; ?>
                </a>
                <?php endif; ?>
                <a href="settings.php" class="list-group-item <?php echo $current_page == 'settings.php' ? 'active' : ''; ?>"><i class="fas fa-cog"></i><span>Settings</span></a>
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
                                        <th>Code</th>
                                        <th>Full Name</th>
                                        <th>Chapter</th>
                                        <th>Group</th>
                                        <th>Barangay</th>
                                        <th>Contact</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                        <?php elseif ($view_mode == 'edit_history'): ?>
                                        <th>Code</th>
                                        <th>Details</th>
                                        <th>Action</th>
                                        <th>Admin</th>
                                        <th>Actions</th>
                                        <?php else: ?>
                                        <th>Code</th>
                                        <th>Full Name</th>
                                        <th>Chapter</th>
                                        <th>Details</th>
                                        <th>Date</th>
                                        <th>Actions</th>
                                        <?php endif; ?>
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
                                                            <li><a class="dropdown-item edit-btn" href="javascript:void(0);" data-code="<?php echo $member['member_code']; ?>" onclick="event.stopPropagation(); editMember(this); return false;"><i class="fas fa-edit text-primary me-2"></i>Edit Member</a></li>
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
                                        <tr><td colspan="5" class="text-center py-4"><p class="text-muted">No edit history found.</p></td></tr>
                                        <?php else: ?>
                                            <?php foreach ($history_records as $record): 
                                                $changes = json_decode($record['changes'], true);
                                                $field_count = is_array($changes) ? count($changes) : 0;
                                                $action_badge = $record['action_type'] == 'edit' ? 'info' : ($record['action_type'] == 'revert' ? 'warning' : 'success');
                                            ?>
                                            <tr>
                                                <td><span class="badge bg-secondary"><?php echo htmlspecialchars($record['member_code']); ?></span></td>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($record['member_code']); ?></strong>
                                                    <br><small class="text-muted"><?php echo date('M d, Y H:i:s', strtotime($record['created_at'])); ?></small>
                                                    <?php if ($field_count > 0): ?>
                                                        <br><span class="badge bg-light text-dark"><?php echo $field_count; ?> field(s) changed</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="badge bg-<?php echo $action_badge; ?>">
                                                        <?php echo ucfirst($record['action_type']); ?>
                                                    </span>
                                                    <?php if ($record['action_type'] == 'edit' && $field_count > 0): ?>
                                                        <br><small class="text-muted"><?php echo implode(', ', array_slice(array_keys($changes), 0, 3)); ?><?php if($field_count > 3) echo '...'; ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo htmlspecialchars($record['admin_name'] ?? 'System'); ?></td>
                                                <td class="action-buttons">
                                                    <button class="btn btn-sm btn-info" onclick="viewEditDetails(<?php echo $record['log_id']; ?>, '<?php echo htmlspecialchars($record['member_code']); ?>')">
                                                        <i class="fas fa-eye me-1"></i> View Details
                                                    </button>
                                                    <?php if ($record['action_type'] == 'edit'): ?>
                                                    <button class="btn btn-sm btn-warning" onclick="confirmRevert(<?php echo $record['log_id']; ?>, '<?php echo htmlspecialchars($record['member_code']); ?>')">
                                                        <i class="fas fa-undo me-1"></i> Revert
                                                    </button>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    
                                    <?php elseif ($view_mode == 'inactive_history'): ?>
    <?php if (empty($history_records)): ?>
    <tr><td colspan="6" class="text-center py-4"><p class="text-muted">No inactive members found.</p></td></tr>
    <?php else: ?>
        <?php foreach ($history_records as $record): ?>
        <tr class="clickable-row" data-member-code="<?php echo htmlspecialchars($record['member_code']); ?>" data-inactive="1" style="cursor:pointer;">
            <td><span class="badge bg-secondary"><?php echo htmlspecialchars($record['member_code']); ?></span></td>
            <td>
                <strong><?php echo htmlspecialchars(($record['first_name'] ?? '') . ' ' . ($record['last_name'] ?? '')); ?></strong>
                <?php if (!empty($record['middle_name'])): ?>
                    <br><small class="text-muted"><?php echo htmlspecialchars($record['middle_name']); ?></small>
                <?php endif; ?>
            </td>
            <td><?php echo !empty($record['chapter']) ? '<span class="badge bg-info">' . htmlspecialchars($record['chapter']) . '</span>' : '—'; ?></td>
            <td>
                <?php if (!empty($record['inactive_reason'])): ?>
                    <strong class="text-danger"><?php echo htmlspecialchars($record['inactive_reason']); ?></strong>
                    <?php if (!empty($record['inactive_notes'])): ?>
                        <br><small class="text-muted"><?php echo htmlspecialchars($record['inactive_notes']); ?></small>
                    <?php endif; ?>
                <?php else: ?>
                    <span class="text-muted">—</span>
                <?php endif; ?>
            </td>
            <td>
                <?php if (!empty($record['inactive_date'])): ?>
                    <small><i class="fas fa-calendar-times text-danger me-1"></i><?php echo date('M d, Y', strtotime($record['inactive_date'])); ?></small>
                    <br><small class="text-muted"><?php echo date('h:i A', strtotime($record['inactive_date'])); ?></small>
                <?php else: ?>
                    <span class="text-muted">—</span>
                <?php endif; ?>
            </td>
            <td class="action-buttons">
                <button type="button" class="btn btn-sm btn-info" onclick="event.stopPropagation(); viewInactiveDetails('<?php echo htmlspecialchars($record['member_code']); ?>');">
                    <i class="fas fa-eye me-1"></i> View
                </button>
                <form method="POST" style="display:inline;" onsubmit="event.stopPropagation(); return confirm('Reactivate this member?');">
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
    <tr><td colspan="6" class="text-center py-4"><p class="text-muted">No deceased members found.</p></td></tr>
    <?php else: ?>
        <?php foreach ($history_records as $record): ?>
        <tr class="clickable-row" data-member-code="<?php echo htmlspecialchars($record['member_code']); ?>" data-deceased="1" style="cursor:pointer;">
            <td><span class="badge bg-secondary"><?php echo htmlspecialchars($record['member_code']); ?></span></td>
            <td>
                <strong><?php echo htmlspecialchars(($record['first_name'] ?? '') . ' ' . ($record['last_name'] ?? '')); ?></strong>
                <?php if (!empty($record['middle_name'])): ?>
                    <br><small class="text-muted"><?php echo htmlspecialchars($record['middle_name']); ?></small>
                <?php endif; ?>
            </td>
            <td><?php echo !empty($record['chapter']) ? '<span class="badge bg-info">' . htmlspecialchars($record['chapter']) . '</span>' : '—'; ?></td>
            <td>
                <?php if (!empty($record['cause_of_death'])): ?>
                    <strong class="text-secondary"><?php echo htmlspecialchars($record['cause_of_death']); ?></strong>
                <?php else: ?>
                    <span class="text-muted">—</span>
                <?php endif; ?>
                <?php if (!empty($record['place_of_death'])): ?>
                    <br><small class="text-muted"><i class="fas fa-map-marker-alt me-1"></i><?php echo htmlspecialchars($record['place_of_death']); ?></small>
                <?php endif; ?>
            </td>
            <td>
                <?php if (!empty($record['death_date_record'])): ?>
                    <small><i class="fas fa-cross text-secondary me-1"></i><?php echo date('M d, Y', strtotime($record['death_date_record'])); ?></small>
                <?php else: ?>
                    <span class="text-muted">—</span>
                <?php endif; ?>
            </td>
            <td class="action-buttons">
                <button type="button" class="btn btn-sm btn-info" onclick="event.stopPropagation(); viewDeceasedDetails('<?php echo htmlspecialchars($record['member_code']); ?>');">
                    <i class="fas fa-eye me-1"></i> View
                </button>
                <form method="POST" style="display:inline;" onsubmit="event.stopPropagation(); return confirm('Undo death record and reactivate member?');">
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
                                        <tr><td colspan="6" class="text-center py-4"><p class="text-muted">No deleted members found.</p></td></tr>
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
                                                <td><?php echo !empty($record['chapter']) ? htmlspecialchars($record['chapter']) : '—'; ?></td>
                                                <td><?php echo htmlspecialchars($record['deleted_by_name'] ?? 'System'); ?></td>
                                                <td><small><?php echo date('M d, Y H:i', strtotime($record['deleted_at'])); ?></small></td>
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
        </div>
    </div>

    <!-- ============================================================ -->
    <!-- EDIT DETAILS MODAL - Shows Old vs New values with checkboxes -->
    <!-- ============================================================ -->
    <div class="modal fade" id="editDetailsModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header bg-info text-white py-2">
                    <h5 class="modal-title">
                        <i class="fas fa-history me-2"></i>
                        <span id="editDetailsTitle">Edit Details</span>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                
                <div class="modal-body p-4" id="editDetailsContent">
                    <div class="text-center p-5">
                        <div class="spinner-border text-info" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <p class="mt-3">Loading edit details...</p>
                    </div>
                </div>
                
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-warning" id="revertFromDetailsBtn" style="display:none;">
                        <i class="fas fa-undo me-1"></i> Revert Selected Fields
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================================ -->
    <!-- REVERT CONFIRMATION MODAL -->
    <!-- ============================================================ -->
    <div class="modal fade" id="revertConfirmModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header bg-warning text-dark py-2">
                    <h5 class="modal-title">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        Confirm Revert
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body">
                    <div id="revertConfirmContent">
                        <p><strong>Are you sure you want to revert this edit?</strong></p>
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>Member:</strong> <span id="revertMemberName"></span>
                        </div>
                        <div id="revertBannerText" class="alert alert-warning py-2">
                            <i class="fas fa-info-circle me-1"></i> Loading...
                        </div>
                        <div id="revertChangesSummary" class="mb-3">
                            <p><strong>Changes that will be reverted:</strong></p>
                            <ul id="revertChangesList" class="list-unstyled"></ul>
                        </div>
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-circle me-2"></i>
                            <strong>This action cannot be undone.</strong>
                            <br>This will restore the selected field(s) to the state before this edit.
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <form method="POST" id="revertForm" style="display:inline;">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="action" value="revert">
                        <input type="hidden" name="log_id" id="revertLogId">
                        <div id="revertSelectedFieldsContainer"></div>
                        <button type="submit" class="btn btn-warning">
                            <i class="fas fa-undo me-1"></i> Yes, Revert
                        </button>
                    </form>
                </div>
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
                               <input type="text" name="member_code" class="form-control"
       placeholder="<?php echo htmlspecialchars(Settings::get('membership.member_code_prefix', 'M')); ?>0001"
       required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Date Joined *</label>
                                <input type="date" name="date_joined" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Monthly Contribution *</label>
                                <input type="number" name="monthly_contribution" class="form-control"
       value="<?php echo number_format(Settings::get('membership.default_monthly_contribution', 100.00), 2, '.', ''); ?>"
       step="0.01" required>
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
    <!-- ============================================================ -->
    <!-- DOCUMENT VIEWER MODAL — Birth Cert / Medical Cert viewer      -->
    <!-- ============================================================ -->
    <div class="modal fade" id="documentViewerModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white py-2">
                    <h5 class="modal-title">
                        <i class="fas fa-file-alt me-2"></i>
                        <span id="docViewerTitle">Document</span>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body p-0" id="docViewerBody"
                     style="background:#f4f7fc; min-height:500px; max-height:80vh; overflow:auto; text-align:center;">
                    <div class="text-center p-5">
                        <div class="spinner-border text-primary" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <p class="mt-3 text-muted">Loading document...</p>
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <a href="#" id="docViewerDownload" class="btn btn-outline-primary btn-sm" download>
                        <i class="fas fa-download me-1"></i>Download
                    </a>
                    <a href="#" id="docViewerOpenTab" class="btn btn-outline-secondary btn-sm" target="_blank" rel="noopener">
                        <i class="fas fa-external-link-alt me-1"></i>Open in New Tab
                    </a>
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>
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
    window.currencySymbol = <?php echo json_encode(Settings::get('general.currency_symbol', '₱')); ?>;
</script>
    <script src="members/members.js"></script>
   
    <script>
        // ============================================================
        // ALL JAVASCRIPT FUNCTIONS
        // ============================================================
        
        // Debug
        console.log('jQuery version:', $.fn.jquery);
        
        // Edit Member Function
        function editMember(element) {
            event.stopPropagation();
            var memberCode = $(element).data('code') || $(element).data('id');
            console.log('Editing member with code:', memberCode);
            
            if (!memberCode) {
                console.error('No member code found');
                return;
            }
            
            $.ajax({
                url: 'get_member.php',
                type: 'POST',
                data: { code: memberCode },
                dataType: 'json',
                success: function(memberData) {
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
                    
                    $('#edit_province').val(memberData.province || 'Occidental Mindoro');
                    updateCities('edit');
                    setTimeout(function() {
                        $('#edit_city').val(memberData.city || '');
                    }, 100);
                    $('#edit_barangay').val(memberData.barangay || '');
                    $('#edit_street').val(memberData.street || '');
                    $('#edit_present_address').val(memberData.present_address || '');
                    $('#edit_permanent_address').val(memberData.permanent_address || '');
                    
                    $('#edit_contact').val(memberData.contact_number || '');
                    $('#edit_alternate').val(memberData.alternate_number || '');
                    $('#edit_email').val(memberData.email || '');
                    
                    $('#edit_birth_date').val(memberData.birth_date || '');
                    $('#edit_place_of_birth').val(memberData.place_of_birth || '');
                    $('#edit_age').val(memberData.age || '');
                    $('#edit_gender').val(memberData.gender || '');
                    $('#edit_civil_status').val(memberData.civil_status || '');
                    $('#edit_religion').val(memberData.religion || '');
                    
                    $('#edit_father_fname').val(memberData.father_fname || '');
                    $('#edit_father_mname').val(memberData.father_mname || '');
                    $('#edit_father_lname').val(memberData.father_lname || '');
                    
                    $('#edit_mother_fname').val(memberData.mother_fname || '');
                    $('#edit_mother_mname').val(memberData.mother_mname || '');
                    $('#edit_mother_lname').val(memberData.mother_lname || '');
                    
                    $('#edit_spouse_fname').val(memberData.spouse_fname || '');
                    $('#edit_spouse_mname').val(memberData.spouse_mname || '');
                    $('#edit_spouse_lname').val(memberData.spouse_lname || '');
                    $('#edit_spouse_age').val(memberData.spouse_age || '');
                    
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
                    
                    $('#edit_ref1_name').val(memberData.ref1_name || '');
                    $('#edit_ref1_contact').val(memberData.ref1_contact || '');
                    $('#edit_ref2_name').val(memberData.ref2_name || '');
                    $('#edit_ref2_contact').val(memberData.ref2_contact || '');
                    
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
                    
                    $('#edit_beneficiary_name').val(memberData.beneficiary_name || '');
                    $('#edit_beneficiary_address').val(memberData.beneficiary_address || '');
                    $('#edit_beneficiary_relation').val(memberData.beneficiary_relation || '');
                    $('#edit_beneficiary_age').val(memberData.beneficiary_age || '');
                    $('#edit_beneficiary_contact').val(memberData.beneficiary_contact || '');
                    
                    $('#edit_username').val(memberData.username || '');
                    
                    $('#edit_medical_certificate').prop('checked', memberData.medical_certificate == 1);
                    $('#edit_birth_certificate').prop('checked', memberData.birth_certificate == 1);
                    
                    $('#editMemberModal').modal('show');
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', error);
                    alert('Error loading member data. Please try again.');
                }
            });
        }
        
        // ============================================================
        // EDIT HISTORY FUNCTIONS
        // ============================================================
        
        function viewEditDetails(logId, memberCode) {
            const modalEl = document.getElementById('editDetailsModal');
            if (!modalEl) return;

            const modal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
            const content = document.getElementById('editDetailsContent');
            const title = document.getElementById('editDetailsTitle');
            const revertBtn = document.getElementById('revertFromDetailsBtn');

            content.innerHTML = `
                <div class="text-center p-5">
                    <div class="spinner-border text-info" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-3">Loading edit details...</p>
                </div>
            `;
            revertBtn.style.display = 'none';
            title.textContent = 'Edit Details - ' + memberCode;

            modal.show();

            fetch('members.php?get_edit_details=1&log_id=' + logId)
                .then(response => response.json())
                .then(data => {
                    if (!data.success) {
                        content.innerHTML = `
                            <div class="alert alert-danger m-3">
                                <i class="fas fa-exclamation-circle me-2"></i>
                                Error loading edit details: ${data.error || 'Unknown error'}
                            </div>
                        `;
                        return;
                    }

                    let html = '';
                    const actionType = data.log.action_type || 'edit';
                    const adminName = data.log.admin_name || 'System';
                    const createdAt = new Date(data.log.created_at).toLocaleString();

                    html += `
                        <div class="edit-details-header mb-4">
                            <div class="row">
                                <div class="col-md-4">
                                    <strong>Member:</strong> ${memberCode}
                                    <br><span class="badge bg-secondary">${memberCode}</span>
                                </div>
                                <div class="col-md-4">
                                    <strong>Action:</strong>
                                    <span class="badge bg-${actionType == 'edit' ? 'info' : (actionType == 'revert' ? 'warning' : 'success')}">
                                        ${actionType.toUpperCase()}
                                    </span>
                                    <br><strong>By:</strong> ${adminName}
                                </div>
                                <div class="col-md-4 text-end">
                                    <strong>Date:</strong> ${createdAt}
                                </div>
                            </div>
                        </div>
                    `;

                    const changes = data.changes || {};

                    // ============================================================
                    // SPECIAL CASE: Revert Receipt
                    // ============================================================
                    if (actionType === 'revert' && changes.reverted_fields) {
                        const revertedFields = changes.reverted_fields;
                        const fieldKeys = Object.keys(revertedFields);

                        html += `
                            <div class="alert alert-warning mb-3">
                                <i class="fas fa-receipt me-2"></i>
                                <strong>REVERT RECEIPT</strong>
                                ${changes.partial_revert ? '<span class="badge bg-info text-dark ms-2">Partial</span>' : ''}
                                <hr class="my-2">
                                <div class="row small">
                                    <div class="col-md-6">
                                        <strong>Original Edit By:</strong> ${changes.original_admin || 'System'}<br>
                                        <strong>Original Date:</strong> ${new Date(changes.original_date).toLocaleString()}<br>
                                        <strong>Original Log ID:</strong> #${changes.reverted_log_id}
                                    </div>
                                    <div class="col-md-6">
                                        <strong>Reverted By:</strong> ${changes.reverted_by}<br>
                                        <strong>Reverted At:</strong> ${new Date(changes.reverted_at).toLocaleString()}
                                    </div>
                                </div>
                            </div>
                        `;

                        if (fieldKeys.length === 0) {
                            html += `<div class="alert alert-info">No fields were reverted.</div>`;
                        } else {
                            html += `
                                <div class="table-responsive">
                                    <table class="table table-bordered table-hover">
                                        <thead class="table-light">
                                            <tr>
                                                <th style="width: 25%;">Field</th>
                                                <th style="width: 35%;">Value Before Revert</th>
                                                <th style="width: 40%;">Value After Revert (Restored)</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                            `;

                            fieldKeys.forEach(key => {
                                const fromVal = revertedFields[key].from || '';
                                const toVal = revertedFields[key].to || '';
                                const displayKey = key.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());

                                html += `
                                    <tr class="table-warning">
                                        <td><strong>${displayKey}</strong></td>
                                        <td class="text-danger">${fromVal || '<span class="text-muted">(empty)</span>'}</td>
                                        <td class="text-success">${toVal || '<span class="text-muted">(empty)</span>'}</td>
                                    </tr>
                                `;
                            });

                            html += `</tbody></table></div>`;
                        }

                        content.innerHTML = html;
                        return;
                    }

                    // ============================================================
                    // Regular edit/add log display WITH SELECTION CHECKBOXES
                    // ============================================================
                    const changeKeys = Object.keys(changes);

                    if (changeKeys.length === 0) {
                        html += `<div class="alert alert-info">No field changes recorded for this edit.</div>`;
                    } else {
                        html += `
                            <div class="alert alert-info mb-2 py-2">
                                <i class="fas fa-info-circle me-1"></i>
                                <small>Check specific fields you want to revert, then click <strong>Revert Selected Fields</strong>. If nothing is checked, all changed fields will be reverted.</small>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-bordered table-hover" id="editDetailsTable">
                                    <thead class="table-light">
                                        <tr>
                                            <th style="width: 5%;" class="text-center">
                                                <input type="checkbox" id="selectAllChanges" title="Select all" checked>
                                            </th>
                                            <th style="width: 20%;">Field</th>
                                            <th style="width: 32%;">Old Value</th>
                                            <th style="width: 32%;">New Value</th>
                                            <th style="width: 11%;">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                        `;

                        changeKeys.forEach(key => {
                            const oldValue = changes[key].old || '';
                            const newValue = changes[key].new || '';
                            const isChanged = oldValue !== newValue;
                            const displayKey = key.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());

                            html += `
                                <tr class="${isChanged ? 'table-warning' : ''}">
                                    <td class="text-center">
                                        <input type="checkbox"
                                               class="change-checkbox"
                                               value="${key}"
                                               data-field="${key}"
                                               ${isChanged ? 'checked' : 'disabled'}>
                                    </td>
                                    <td><strong>${displayKey}</strong></td>
                                    <td class="${isChanged ? 'text-danger' : ''}">
                                        ${oldValue || '<span class="text-muted">(empty)</span>'}
                                    </td>
                                    <td class="${isChanged ? 'text-success' : ''}">
                                        ${newValue || '<span class="text-muted">(empty)</span>'}
                                    </td>
                                    <td class="text-center">
                                        ${isChanged ? '<span class="badge bg-warning text-dark">Changed</span>' : '<span class="badge bg-secondary">Same</span>'}
                                    </td>
                                </tr>
                            `;
                        });

                        html += `
                                    </tbody>
                                </table>
                            </div>
                        `;
                    }

                    if (actionType === 'edit' && changeKeys.length > 0) {
                        revertBtn.style.display = 'inline-block';
                        revertBtn.innerHTML = '<i class="fas fa-undo me-1"></i> Revert Selected Fields';
                        revertBtn.onclick = function() {
                            const checked = [];
                            document.querySelectorAll('#editDetailsContent .change-checkbox:checked').forEach(cb => {
                                checked.push(cb.value);
                            });
                            modal.hide();
                            setTimeout(() => {
                                confirmRevert(logId, memberCode, checked);
                            }, 300);
                        };
                    } else {
                        revertBtn.style.display = 'none';
                    }

                    content.innerHTML = html;

                    // Wire up select-all checkbox
                    const selectAll = document.getElementById('selectAllChanges');
                    if (selectAll) {
                        selectAll.addEventListener('change', function() {
                            document.querySelectorAll('#editDetailsContent .change-checkbox:not(:disabled)').forEach(cb => {
                                cb.checked = selectAll.checked;
                            });
                        });
                        document.querySelectorAll('#editDetailsContent .change-checkbox').forEach(cb => {
                            cb.addEventListener('change', function() {
                                const all = document.querySelectorAll('#editDetailsContent .change-checkbox:not(:disabled)');
                                const allChecked = Array.from(all).every(x => x.checked);
                                if (selectAll) selectAll.checked = allChecked;
                            });
                        });
                    }
                })
                .catch(error => {
                    content.innerHTML = `
                        <div class="alert alert-danger m-3">
                            <i class="fas fa-exclamation-circle me-2"></i>
                            Error loading edit details. Please try again.
                        </div>
                    `;
                });
        }
        
        function confirmRevert(logId, memberCode, selectedFields) {
            selectedFields = selectedFields || [];
            const isPartial = selectedFields.length > 0;

            const modalEl = document.getElementById('revertConfirmModal');
            if (!modalEl) return;

            const modal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
            const memberNameSpan = document.getElementById('revertMemberName');
            const changesList = document.getElementById('revertChangesList');
            const logIdInput = document.getElementById('revertLogId');
            const selectedContainer = document.getElementById('revertSelectedFieldsContainer');
            const banner = document.getElementById('revertBannerText');

            memberNameSpan.textContent = memberCode;
            logIdInput.value = logId;

            // Inject hidden inputs for selected fields
            if (selectedContainer) {
                selectedContainer.innerHTML = '';
                selectedFields.forEach(f => {
                    const inp = document.createElement('input');
                    inp.type = 'hidden';
                    inp.name = 'selected_fields[]';
                    inp.value = f;
                    selectedContainer.appendChild(inp);
                });
            }

            if (banner) {
                banner.innerHTML = '<i class="fas fa-info-circle me-1"></i> Loading...';
            }
            changesList.innerHTML = '<li class="text-muted">Loading changes...</li>';

            modal.show();

            fetch('members.php?get_edit_details=1&log_id=' + logId)
                .then(response => response.json())
                .then(data => {
                    if (!data.success) {
                        changesList.innerHTML = `<li class="text-danger">Error loading changes: ${data.error || 'Unknown error'}</li>`;
                        return;
                    }

                    const changes = data.changes || {};
                    const changeKeys = Object.keys(changes);

                    const fieldsToShow = isPartial
                        ? changeKeys.filter(k => selectedFields.includes(k))
                        : changeKeys;

                    if (banner) {
                        banner.innerHTML = isPartial
                            ? `<i class="fas fa-info-circle me-1"></i> <strong>Partial revert:</strong> Only the ${fieldsToShow.length} selected field(s) will be reverted. Other changes will remain.`
                            : `<i class="fas fa-info-circle me-1"></i> <strong>Full revert:</strong> All ${fieldsToShow.length} changed field(s) will be reverted.`;
                    }

                    if (fieldsToShow.length === 0) {
                        changesList.innerHTML = '<li class="text-muted">No changes to revert</li>';
                    } else {
                        changesList.innerHTML = '';
                        fieldsToShow.forEach(key => {
                            const displayKey = key.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
                            const oldValue = changes[key].old || '(empty)';
                            const newValue = changes[key].new || '(empty)';

                            const li = document.createElement('li');
                            li.className = 'revert-change-item';
                            li.innerHTML = `
                                <span class="badge bg-light text-dark me-2">${displayKey}</span>
                                <span class="text-danger text-decoration-line-through me-2">${oldValue}</span>
                                <i class="fas fa-arrow-right text-muted me-2"></i>
                                <span class="text-success">${newValue}</span>
                            `;
                            changesList.appendChild(li);
                        });
                    }
                })
                .catch(error => {
                    changesList.innerHTML = `<li class="text-danger">Error loading changes: ${error.message}</li>`;
                });
        }
        
        // Show delete modal
        window.showDeleteModal = function(memberCode, memberName) {
            if (!memberCode) {
                alert('Error: Invalid member code');
                return false;
            }
            
            try {
                document.getElementById('delete_member_code').value = memberCode;
                document.getElementById('delete_member_name').innerHTML = memberName;
                const deleteModal = new bootstrap.Modal(document.getElementById('deleteMemberModal'));
                deleteModal.show();
            } catch (error) {
                alert('Error showing delete confirmation. Please try again.');
            }
            return false;
        };
        
        function showInactiveModal(element) {
            const code = $(element).data('code');
            const name = $(element).data('name');
            
            $('#inactive_member_code').val(code);
            $('#inactive_member_name').text(name);
            
            $('#inactive_reason').val('');
            $('#inactive_other_reason').val('');
            $('#inactive_notes').val('');
            $('#other_reason_container').hide();
        }
        
        function showDeceasedModal(element) {
            const code = $(element).data('code');
            const name = $(element).data('name');
            
            $('#deceased_member_code').val(code);
            $('#deceased_member_name').text(name);
            
            $('#death_date').val('');
            $('#cause_of_death').val('');
            $('#death_certificate').val('');
            $('#place_of_death').val('');
            $('#burial_place').val('');
            $('#burial_date').val('');
            $('#death_notes').val('');
        }
        
        window.confirmMultipleDelete = function() {
            const selectedCount = $('.member-checkbox:checked').length;
            if (selectedCount === 0) {
                alert('Please select at least one member to delete.');
                return false;
            }
            return confirm('Are you sure you want to delete ' + selectedCount + ' selected member(s)?\n\nThey will be moved to deleted history and can be recovered within 50 days.');
        };
        
        function updateCities(prefix) {
            const province = document.getElementById(prefix + '_province');
            const citySelect = document.getElementById(prefix + '_city');
            
            if (!province || !citySelect) return;
            
            citySelect.innerHTML = '';
            const defaultOption = document.createElement('option');
            defaultOption.value = '';
            defaultOption.textContent = 'Select City';
            citySelect.appendChild(defaultOption);
            
            if (province.value && window.citiesData[province.value]) {
                window.citiesData[province.value].forEach(city => {
                    const option = document.createElement('option');
                    option.value = city;
                    option.textContent = city;
                    citySelect.appendChild(option);
                });
            }
        }
        
        function calculateAge(prefix) {
            const birthDate = document.getElementById(prefix + '_birth_date');
            const ageField = document.getElementById(prefix + '_age');
            
            if (birthDate && birthDate.value && ageField) {
                const today = new Date();
                const birth = new Date(birthDate.value);
                let age = today.getFullYear() - birth.getFullYear();
                const monthDiff = today.getMonth() - birth.getMonth();
                
                if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birth.getDate())) {
                    age--;
                }
                ageField.value = age;
            }
        }
        
        function fillSampleData() {
            const randomCode = Math.floor(1000 + Math.random() * 9000);
            
            document.querySelector('#addMemberModal [name="member_code"]').value = 'M' + randomCode;
            document.querySelector('#addMemberModal [name="first_name"]').value = 'Juan';
            document.querySelector('#addMemberModal [name="last_name"]').value = 'Dela Cruz';
            document.querySelector('#addMemberModal [name="middle_name"]').value = 'Santos';
            
            const provinceSelect = document.querySelector('#addMemberModal [name="province"]');
            if (provinceSelect) provinceSelect.value = 'Nueva Ecija';
            updateCities('add');
            
            setTimeout(() => {
                const citySelect = document.querySelector('#addMemberModal [name="city"]');
                if (citySelect) citySelect.value = 'Palayan City';
            }, 100);
            
            document.querySelector('#addMemberModal [name="barangay"]').value = 'Singalat';
            document.querySelector('#addMemberModal [name="street"]').value = 'Purok 3';
            document.querySelector('#addMemberModal [name="contact_number"]').value = '09123456789';
            document.querySelector('#addMemberModal [name="alternate_number"]').value = '09987654321';
            document.querySelector('#addMemberModal [name="email"]').value = 'juan.delacruz@example.com';
            document.querySelector('#addMemberModal [name="birth_date"]').value = '1990-01-15';
            calculateAge('add');
            document.querySelector('#addMemberModal [name="place_of_birth"]').value = 'Manila';
            document.querySelector('#addMemberModal [name="gender"]').value = 'Male';
            document.querySelector('#addMemberModal [name="civil_status"]').value = 'Married';
            document.querySelector('#addMemberModal [name="religion"]').value = 'Roman Catholic';
            
            document.querySelector('#addMemberModal [name="father_fname"]').value = 'Pedro';
            document.querySelector('#addMemberModal [name="father_mname"]').value = 'D';
            document.querySelector('#addMemberModal [name="father_lname"]').value = 'Dela Cruz';
            document.querySelector('#addMemberModal [name="mother_fname"]').value = 'Maria';
            document.querySelector('#addMemberModal [name="mother_mname"]').value = 'S';
            document.querySelector('#addMemberModal [name="mother_lname"]').value = 'Santos';
            document.querySelector('#addMemberModal [name="spouse_fname"]').value = 'Juana';
            document.querySelector('#addMemberModal [name="spouse_mname"]').value = 'R';
            document.querySelector('#addMemberModal [name="spouse_lname"]').value = 'Dela Cruz';
            document.querySelector('#addMemberModal [name="spouse_age"]').value = '30';
            
            document.querySelector('#addMemberModal [name="child1_fname"]').value = 'Jose';
            document.querySelector('#addMemberModal [name="child1_mname"]').value = 'J';
            document.querySelector('#addMemberModal [name="child1_lname"]').value = 'Dela Cruz';
            document.querySelector('#addMemberModal [name="child1_age"]').value = '5';
            document.querySelector('#addMemberModal [name="child2_fname"]').value = 'Maria';
            document.querySelector('#addMemberModal [name="child2_mname"]').value = 'J';
            document.querySelector('#addMemberModal [name="child2_lname"]').value = 'Dela Cruz';
            document.querySelector('#addMemberModal [name="child2_age"]').value = '3';
            
            document.querySelector('#addMemberModal [name="ref1_name"]').value = 'Jose Rizal';
            document.querySelector('#addMemberModal [name="ref1_contact"]').value = '09221112233';
            document.querySelector('#addMemberModal [name="ref2_name"]').value = 'Andres Bonifacio';
            document.querySelector('#addMemberModal [name="ref2_contact"]').value = '09332223344';
            
            document.querySelector('#addMemberModal [name="beneficiary_name"]').value = 'Juana Dela Cruz';
            document.querySelector('#addMemberModal [name="beneficiary_address"]').value = 'Palayan City';
            document.querySelector('#addMemberModal [name="beneficiary_relation"]').value = 'Spouse';
            document.querySelector('#addMemberModal [name="beneficiary_age"]').value = '30';
            document.querySelector('#addMemberModal [name="beneficiary_contact"]').value = '09123456789';
            
            document.querySelector('#addMemberModal [name="chapter"]').value = 'GUIMBA';
            document.querySelector('#addMemberModal [name="group_name"]').value = 'Group A';
            document.querySelector('#addMemberModal [name="leader"]').value = 'John Doe';
            document.querySelector('#addMemberModal [name="coordinator"]').value = 'Jane Smith';
            document.querySelector('#addMemberModal [name="chairman"]').value = 'Mike Johnson';
            document.querySelector('#addMemberModal [name="screening_officer"]').value = 'Sarah Wilson';
            document.querySelector('#addMemberModal [name="screening_date"]').value = new Date().toISOString().split('T')[0];
            document.querySelector('#addMemberModal [name="approved_by"]').value = 'Admin';
            
            document.querySelector('#addMemberModal [name="username"]').value = 'juan_' + randomCode;
            document.querySelector('#addMemberModal [name="password"]').value = 'password123';
            document.querySelector('#addMemberModal [name="confirm_password"]').value = 'password123';
            
            document.querySelector('#addMemberModal [name="medical_certificate"]').checked = true;
            document.querySelector('#addMemberModal [name="birth_certificate"]').checked = true;
            
            if (window.successToast) {
                document.getElementById('successMessage').textContent = 'Sample data filled!';
                window.successToast.show();
            }
        }
        
        function viewMemberDetails(memberCode) {
            const modalEl = document.getElementById('viewMemberModal');
            if (!modalEl) return;
            
            const modal = new bootstrap.Modal(modalEl);
            const contentEl = document.getElementById('memberDetailsContent');
            
            contentEl.innerHTML = `
                <div class="text-center p-5">
                    <div class="spinner-border text-info" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
            `;
            
            modal.show();
            
            fetch('members.php?view_code=' + memberCode)
                .then(response => response.json())
                .then(data => {
                    const birthDate = data.birth_date && data.birth_date !== '0000-00-00' ? new Date(data.birth_date).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) : 'N/A';
                    const screeningDate = data.screening_date && data.screening_date !== '0000-00-00' ? new Date(data.screening_date).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) : 'N/A';
                    const dateRegistered = data.date_registered && data.date_registered !== '0000-00-00' ? new Date(data.date_registered).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) : 'N/A';
                    const dateJoined = data.date_joined && data.date_joined !== '0000-00-00' ? new Date(data.date_joined).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }) : 'N/A';
                    
                    const fatherName = [data.father_fname, data.father_mname, data.father_lname].filter(Boolean).join(' ');
                    const motherName = [data.mother_fname, data.mother_mname, data.mother_lname].filter(Boolean).join(' ');
                    const spouseName = [data.spouse_fname, data.spouse_mname, data.spouse_lname].filter(Boolean).join(' ');
                    
                    const child1Name = [data.child1_fname, data.child1_mname, data.child1_lname].filter(Boolean).join(' ');
                    const child2Name = [data.child2_fname, data.child2_mname, data.child2_lname].filter(Boolean).join(' ');
                    const child3Name = [data.child3_fname, data.child3_mname, data.child3_lname].filter(Boolean).join(' ');
                    const child4Name = [data.child4_fname, data.child4_mname, data.child4_lname].filter(Boolean).join(' ');
                    
                    const fullAddress = [data.street, data.barangay ? 'Brgy. ' + data.barangay : '', data.city, data.province].filter(Boolean).join(', ');
                    
                    let content = `
                        <div class="application-form">
                            <div class="logo-section">
                                <img src="../assets/images/harana-logo.png" alt="Harana Logo" onerror="this.style.display='none';">
                                <div class="text-content">
                                    <h2>NAGKAISANG HIRANISTA</h2>
                                    <h5>SA GINTONG LUZON, PHILS. INC. (NHGL, INC.)</h5>
                                    <p class="small">(Formerly Nagkaisang Hiranista Sa Gintong Luzon, Inc.)</p>
                                    <p class="small">(Sec. REG No. CN 700172104)</p>
                                    <p class="small">MF 2024<br>Bryg. Singalat, Palayan City<br>Province of Nueva Ecija<br>Tel. No. (044)940-6708</p>
                                </div>
                            </div>

                            <h4 class="form-title">APPLICATION FOR MEMBERSHIP</h4>

                            <div class="documents-section">
                                <div class="documents-section-header">
                                    <h4 class="mb-0">Documents Attached:</h4>
                                    <div class="doc-actions">
                                        ${data.birth_certificate_file
                                            ? `<button type="button" class="btn btn-sm btn-outline-primary"
                                                       onclick="openDocumentViewer('${memberCode}', 'birth_certificate_file', 'Birth Certificate');">
                                                   <i class="fas fa-file-image me-1"></i>View Birth Certificate
                                               </button>`
                                            : ''}
                                        ${data.medical_certificate_file
                                            ? `<button type="button" class="btn btn-sm btn-outline-primary"
                                                       onclick="openDocumentViewer('${memberCode}', 'medical_certificate_file', 'Medical Certificate');">
                                                   <i class="fas fa-file-medical me-1"></i>View Medical Certificate
                                               </button>`
                                            : ''}
                                    </div>
                                </div>
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
                            </div>

                            <div class="section-title">I. PERSONAL INFORMATION</div>
                            
                            <table class="info-table">
                                <tr>
                                    <td class="label">Member Code:</td>
                                    <td>${data.member_code || ''}</td>
                                    <td class="label">Date Joined:</td>
                                    <td>${dateJoined}</td>
                                    <td class="label">Monthly Contribution:</td>
                                   <td>${window.currencySymbol || '₱'}${parseFloat(data.monthly_contribution || 0).toFixed(2)}</td>
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
                                    <td colspan="5">${data.present_address || data.address || fullAddress}</td>
                                </tr>
                                <tr>
                                    <td class="label">Permanent Address:</td>
                                    <td colspan="5">${data.permanent_address || data.address || fullAddress}</td>
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
                                <tr><td class="label">Date Registered:</td><td>${dateRegistered}</td><td class="label">Status:</td><td colspan="3"><span class="badge bg-${data.status === 'active' ? 'success' : (data.status === 'inactive' ? 'warning' : 'secondary')}">${data.status || 'active'}</span></td></tr>
                            </table>

                            <div class="section-title">IX. ACCOUNT INFORMATION</div>
                            <table class="info-table">
                                <tr><td class="label">Username:</td><td colspan="5">${data.username || 'Not set'}</td></tr>
                            </table>
                        </div>
                    `;
                    
                    contentEl.innerHTML = content;
                })
                .catch(error => {
                    contentEl.innerHTML = `
                        <div class="alert alert-danger m-3">
                            <i class="fas fa-exclamation-circle me-2"></i>
                            Error loading member details. Please try again.
                        </div>
                    `;
                });
        }
        // ============================================================
// INACTIVE MEMBER DETAILS MODAL — shows full info + inactivity details
// ============================================================
function viewInactiveDetails(memberCode) {
    const modalEl = document.getElementById('viewMemberModal');
    if (!modalEl) return;

    const modal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
    const contentEl = document.getElementById('memberDetailsContent');

    contentEl.innerHTML = `
        <div class="text-center p-5">
            <div class="spinner-border text-info" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <p class="mt-2 text-muted">Loading inactive member details...</p>
        </div>
    `;

    modal.show();

    fetch('members.php?view_inactive_code=' + encodeURIComponent(memberCode))
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                contentEl.innerHTML = `
                    <div class="alert alert-danger m-3">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        ${data.error}
                    </div>
                `;
                return;
            }

            const birthDate = data.birth_date && data.birth_date !== '0000-00-00'
                ? new Date(data.birth_date).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })
                : 'N/A';
            const screeningDate = data.screening_date && data.screening_date !== '0000-00-00'
                ? new Date(data.screening_date).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })
                : 'N/A';
            const dateRegistered = data.date_registered && data.date_registered !== '0000-00-00'
                ? new Date(data.date_registered).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })
                : 'N/A';
            const dateJoined = data.date_joined && data.date_joined !== '0000-00-00'
                ? new Date(data.date_joined).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })
                : 'N/A';

            let inactiveDateStr = 'N/A';
            let inactiveTimeStr = '';
            if (data.inactive_date) {
                const d = new Date(data.inactive_date.replace(' ', 'T'));
                inactiveDateStr = d.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' });
                inactiveTimeStr = d.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' });
            }

            const fatherName = [data.father_fname, data.father_mname, data.father_lname].filter(Boolean).join(' ');
            const motherName = [data.mother_fname, data.mother_mname, data.mother_lname].filter(Boolean).join(' ');
            const spouseName = [data.spouse_fname, data.spouse_mname, data.spouse_lname].filter(Boolean).join(' ');

            const child1Name = [data.child1_fname, data.child1_mname, data.child1_lname].filter(Boolean).join(' ');
            const child2Name = [data.child2_fname, data.child2_mname, data.child2_lname].filter(Boolean).join(' ');
            const child3Name = [data.child3_fname, data.child3_mname, data.child3_lname].filter(Boolean).join(' ');
            const child4Name = [data.child4_fname, data.child4_mname, data.child4_lname].filter(Boolean).join(' ');

            const fullAddress = [data.street, data.barangay ? 'Brgy. ' + data.barangay : '', data.city, data.province].filter(Boolean).join(', ');

            // Who set them inactive (from audit log)
            const setInactiveBy = (data.inactive_log && data.inactive_log.admin_name)
                ? data.inactive_log.admin_name
                : 'System';

            let content = `
                <div class="application-form">
                    <div class="logo-section">
                        <img src="../assets/images/harana-logo.png" alt="Harana Logo" onerror="this.style.display='none'; document.getElementById('view-logo-placeholder').style.display='flex';">
                        <div id="view-logo-placeholder" class="logo-placeholder" style="display: none;">
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

                    <!-- INACTIVITY BANNER -->
                    <div class="alert alert-warning mb-3">
                        <div class="d-flex align-items-start">
                            <i class="fas fa-pause-circle fa-2x me-3 mt-1"></i>
                            <div class="flex-grow-1">
                                <h5 class="mb-2"><strong>MEMBER IS INACTIVE</strong></h5>
                                <table class="table table-sm table-borderless mb-0" style="background:transparent;">
                                    <tr>
                                        <td class="ps-0" style="width:180px;"><strong>Reason:</strong></td>
                                        <td class="text-danger"><strong>${data.inactive_reason || '—'}</strong></td>
                                    </tr>
                                    ${data.inactive_notes ? `
                                    <tr>
                                        <td class="ps-0"><strong>Notes:</strong></td>
                                        <td>${data.inactive_notes}</td>
                                    </tr>` : ''}
                                    <tr>
                                        <td class="ps-0"><strong>Date Set Inactive:</strong></td>
                                        <td><i class="fas fa-calendar-times text-danger me-1"></i>${inactiveDateStr}${inactiveTimeStr ? ' at ' + inactiveTimeStr : ''}</td>
                                    </tr>
                                    <tr>
                                        <td class="ps-0"><strong>Set Inactive By:</strong></td>
                                        <td><i class="fas fa-user-shield text-secondary me-1"></i>${setInactiveBy}</td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div class="section-title">I. PERSONAL INFORMATION</div>
                    <table class="info-table">
                        <tr>
                            <td class="label">Member Code:</td>
                            <td>${data.member_code || ''}</td>
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
                            <td colspan="5">${data.present_address || data.address || fullAddress}</td>
                        </tr>
                        <tr>
                            <td class="label">Permanent Address:</td>
                            <td colspan="5">${data.permanent_address || data.address || fullAddress}</td>
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
                            <td colspan="2">${fatherName || '—'}</td>
                            <td class="label">Mother's Name:</td>
                            <td colspan="2">${motherName || '—'}</td>
                        </tr>
                        <tr>
                            <td class="label">Spouse's Name:</td>
                            <td colspan="3">${spouseName || '—'}</td>
                            <td class="label">Age:</td>
                            <td>${data.spouse_age || ''}</td>
                        </tr>
                    </table>

                    <div class="section-title">V. CHILDREN</div>
                    <table class="info-table">
                        <tr><td class="label">1.</td><td colspan="3">${child1Name || '—'}</td><td class="label">Age:</td><td>${data.child1_age || ''}</td></tr>
                        <tr><td class="label">2.</td><td colspan="3">${child2Name || '—'}</td><td class="label">Age:</td><td>${data.child2_age || ''}</td></tr>
                        <tr><td class="label">3.</td><td colspan="3">${child3Name || '—'}</td><td class="label">Age:</td><td>${data.child3_age || ''}</td></tr>
                        <tr><td class="label">4.</td><td colspan="3">${child4Name || '—'}</td><td class="label">Age:</td><td>${data.child4_age || ''}</td></tr>
                    </table>

                    <div class="section-title">VI. CHARACTER REFERENCES</div>
                    <table class="info-table">
                        <tr><td class="label">1.</td><td>${data.ref1_name || '—'}</td><td class="label">Contact:</td><td colspan="3">${data.ref1_contact || ''}</td></tr>
                        <tr><td class="label">2.</td><td>${data.ref2_name || '—'}</td><td class="label">Contact:</td><td colspan="3">${data.ref2_contact || ''}</td></tr>
                    </table>

                    <div class="section-title">VII. BENEFICIARY INFORMATION</div>
                    <table class="info-table">
                        <tr><td class="label">Name:</td><td colspan="5">${data.beneficiary_name || '—'}</td></tr>
                        <tr><td class="label">Address:</td><td colspan="5">${data.beneficiary_address || '—'}</td></tr>
                        <tr><td class="label">Relationship:</td><td>${data.beneficiary_relation || '—'}</td><td class="label">Age:</td><td>${data.beneficiary_age || ''}</td><td class="label">Contact:</td><td>${data.beneficiary_contact || ''}</td></tr>
                    </table>

                    <div class="section-title">VIII. CHAPTER INFORMATION</div>
                    <table class="info-table">
                        <tr><td class="label">Chapter:</td><td>${data.chapter || '—'}</td><td class="label">Group Name:</td><td colspan="3">${data.group_name || '—'}</td></tr>
                        <tr><td class="label">Leader:</td><td>${data.leader || '—'}</td><td class="label">Coordinator:</td><td colspan="3">${data.coordinator || '—'}</td></tr>
                        <tr><td class="label">Chairman:</td><td>${data.chairman || '—'}</td><td class="label">Screening Officer:</td><td colspan="3">${data.screening_officer || '—'}</td></tr>
                        <tr><td class="label">Screening Date:</td><td>${screeningDate}</td><td class="label">Approved By:</td><td colspan="3">${data.approved_by || '—'}</td></tr>
                        <tr><td class="label">Date Registered:</td><td>${dateRegistered}</td><td class="label">Status:</td><td colspan="3"><span class="badge bg-warning text-dark">INACTIVE</span></td></tr>
                    </table>

                    <div class="section-title">IX. ACCOUNT INFORMATION</div>
                    <table class="info-table">
                        <tr><td class="label">Username:</td><td colspan="5">${data.username || 'Not set'}</td></tr>
                    </table>
                </div>
            `;

            contentEl.innerHTML = content;
        })
        .catch(error => {
            contentEl.innerHTML = `
                <div class="alert alert-danger m-3">
                    <i class="fas fa-exclamation-circle me-2"></i>
                    Error loading inactive member details. Please try again.
                </div>
            `;
        });
}
// ============================================================
// DECEASED MEMBER DETAILS MODAL — shows full info + death/burial details
// ============================================================
function viewDeceasedDetails(memberCode) {
    const modalEl = document.getElementById('viewMemberModal');
    if (!modalEl) return;

    const modal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
    const contentEl = document.getElementById('memberDetailsContent');

    contentEl.innerHTML = `
        <div class="text-center p-5">
            <div class="spinner-border text-secondary" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <p class="mt-2 text-muted">Loading deceased member details...</p>
        </div>
    `;

    modal.show();

    fetch('members.php?view_deceased_code=' + encodeURIComponent(memberCode))
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                contentEl.innerHTML = `
                    <div class="alert alert-danger m-3">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        ${data.error}
                    </div>
                `;
                return;
            }

            // ---------- date helpers ----------
            const fmtDate = (v) => (v && v !== '0000-00-00' && v !== '0000-00-00 00:00:00')
                ? new Date(v.replace(' ', 'T')).toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' })
                : 'N/A';
            const fmtDateTime = (v) => {
                if (!v || v === '0000-00-00 00:00:00') return { date: 'N/A', time: '' };
                const d = new Date(v.replace(' ', 'T'));
                return {
                    date: d.toLocaleDateString('en-US', { year: 'numeric', month: 'long', day: 'numeric' }),
                    time: d.toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' })
                };
            };

            const birthDate      = fmtDate(data.birth_date);
            const screeningDate  = fmtDate(data.screening_date);
            const dateRegistered = fmtDate(data.date_registered);
            const dateJoined     = fmtDate(data.date_joined);

            // ---------- death details ----------
            const dd = data.deceased_details || {};
            const deathDateStr = fmtDate(dd.death_date);
            const burialDateStr = fmtDate(dd.burial_date);

            const recordedBy = (data.death_log && data.death_log.admin_name)
                ? data.death_log.admin_name
                : (dd.recorded_by_name || dd.recorded_by || 'System');

            const recordedAt = data.death_log
                ? fmtDateTime(data.death_log.created_at)
                : { date: 'N/A', time: '' };

            // ---------- names ----------
            const fatherName  = [data.father_fname,  data.father_mname,  data.father_lname ].filter(Boolean).join(' ');
            const motherName  = [data.mother_fname,  data.mother_mname,  data.mother_lname ].filter(Boolean).join(' ');
            const spouseName  = [data.spouse_fname,  data.spouse_mname,  data.spouse_lname ].filter(Boolean).join(' ');
            const child1Name  = [data.child1_fname,  data.child1_mname,  data.child1_lname ].filter(Boolean).join(' ');
            const child2Name  = [data.child2_fname,  data.child2_mname,  data.child2_lname ].filter(Boolean).join(' ');
            const child3Name  = [data.child3_fname,  data.child3_mname,  data.child3_lname ].filter(Boolean).join(' ');
            const child4Name  = [data.child4_fname,  data.child4_mname,  data.child4_lname ].filter(Boolean).join(' ');

            const fullAddress = [data.street, data.barangay ? 'Brgy. ' + data.barangay : '', data.city, data.province].filter(Boolean).join(', ');

            let content = `
                <div class="application-form">
                    <div class="logo-section">
                        <img src="../assets/images/harana-logo.png" alt="Harana Logo" onerror="this.style.display='none'; document.getElementById('view-logo-placeholder').style.display='flex';">
                        <div id="view-logo-placeholder" class="logo-placeholder" style="display: none;">
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

                    <!-- DECEASED BANNER -->
                    <div class="alert alert-secondary mb-3">
                        <div class="d-flex align-items-start">
                            <i class="fas fa-cross fa-2x me-3 mt-1"></i>
                            <div class="flex-grow-1">
                                <h5 class="mb-2"><strong>MEMBER IS DECEASED</strong></h5>
                                <table class="table table-sm table-borderless mb-0" style="background:transparent;">
                                    <tr>
                                        <td class="ps-0" style="width:200px;"><strong>Date of Death:</strong></td>
                                        <td><i class="fas fa-cross text-dark me-1"></i>${deathDateStr}</td>
                                    </tr>
                                    <tr>
                                        <td class="ps-0"><strong>Cause of Death:</strong></td>
                                        <td>${dd.cause_of_death || '—'}</td>
                                    </tr>
                                    <tr>
                                        <td class="ps-0"><strong>Death Certificate No.:</strong></td>
                                        <td>${dd.death_certificate || '—'}</td>
                                    </tr>
                                    <tr>
                                        <td class="ps-0"><strong>Place of Death:</strong></td>
                                        <td>${dd.place_of_death || '—'}</td>
                                    </tr>
                                    <tr>
                                        <td class="ps-0"><strong>Burial Place:</strong></td>
                                        <td>${dd.burial_place || '—'}</td>
                                    </tr>
                                    <tr>
                                        <td class="ps-0"><strong>Burial Date:</strong></td>
                                        <td>${burialDateStr}</td>
                                    </tr>
                                    ${dd.notes ? `
                                    <tr>
                                        <td class="ps-0"><strong>Notes:</strong></td>
                                        <td>${dd.notes}</td>
                                    </tr>` : ''}
                                    <tr>
                                        <td class="ps-0"><strong>Recorded By:</strong></td>
                                        <td><i class="fas fa-user-shield text-secondary me-1"></i>${recordedBy}</td>
                                    </tr>
                                    <tr>
                                        <td class="ps-0"><strong>Recorded On:</strong></td>
                                        <td><i class="fas fa-calendar-alt text-secondary me-1"></i>${recordedAt.date}${recordedAt.time ? ' at ' + recordedAt.time : ''}</td>
                                    </tr>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div class="section-title">I. PERSONAL INFORMATION</div>
                    <table class="info-table">
                        <tr>
                            <td class="label">Member Code:</td>
                            <td>${data.member_code || ''}</td>
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
                            <td colspan="5">${data.present_address || data.address || fullAddress}</td>
                        </tr>
                        <tr>
                            <td class="label">Permanent Address:</td>
                            <td colspan="5">${data.permanent_address || data.address || fullAddress}</td>
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
                            <td colspan="2">${fatherName || '—'}</td>
                            <td class="label">Mother's Name:</td>
                            <td colspan="2">${motherName || '—'}</td>
                        </tr>
                        <tr>
                            <td class="label">Spouse's Name:</td>
                            <td colspan="3">${spouseName || '—'}</td>
                            <td class="label">Age:</td>
                            <td>${data.spouse_age || ''}</td>
                        </tr>
                    </table>

                    <div class="section-title">V. CHILDREN</div>
                    <table class="info-table">
                        <tr><td class="label">1.</td><td colspan="3">${child1Name || '—'}</td><td class="label">Age:</td><td>${data.child1_age || ''}</td></tr>
                        <tr><td class="label">2.</td><td colspan="3">${child2Name || '—'}</td><td class="label">Age:</td><td>${data.child2_age || ''}</td></tr>
                        <tr><td class="label">3.</td><td colspan="3">${child3Name || '—'}</td><td class="label">Age:</td><td>${data.child3_age || ''}</td></tr>
                        <tr><td class="label">4.</td><td colspan="3">${child4Name || '—'}</td><td class="label">Age:</td><td>${data.child4_age || ''}</td></tr>
                    </table>

                    <div class="section-title">VI. CHARACTER REFERENCES</div>
                    <table class="info-table">
                        <tr><td class="label">1.</td><td>${data.ref1_name || '—'}</td><td class="label">Contact:</td><td colspan="3">${data.ref1_contact || ''}</td></tr>
                        <tr><td class="label">2.</td><td>${data.ref2_name || '—'}</td><td class="label">Contact:</td><td colspan="3">${data.ref2_contact || ''}</td></tr>
                    </table>

                    <div class="section-title">VII. BENEFICIARY INFORMATION</div>
                    <table class="info-table">
                        <tr><td class="label">Name:</td><td colspan="5">${data.beneficiary_name || '—'}</td></tr>
                        <tr><td class="label">Address:</td><td colspan="5">${data.beneficiary_address || '—'}</td></tr>
                        <tr><td class="label">Relationship:</td><td>${data.beneficiary_relation || '—'}</td><td class="label">Age:</td><td>${data.beneficiary_age || ''}</td><td class="label">Contact:</td><td>${data.beneficiary_contact || ''}</td></tr>
                    </table>

                    <div class="section-title">VIII. CHAPTER INFORMATION</div>
                    <table class="info-table">
                        <tr><td class="label">Chapter:</td><td>${data.chapter || '—'}</td><td class="label">Group Name:</td><td colspan="3">${data.group_name || '—'}</td></tr>
                        <tr><td class="label">Leader:</td><td>${data.leader || '—'}</td><td class="label">Coordinator:</td><td colspan="3">${data.coordinator || '—'}</td></tr>
                        <tr><td class="label">Chairman:</td><td>${data.chairman || '—'}</td><td class="label">Screening Officer:</td><td colspan="3">${data.screening_officer || '—'}</td></tr>
                        <tr><td class="label">Screening Date:</td><td>${screeningDate}</td><td class="label">Approved By:</td><td colspan="3">${data.approved_by || '—'}</td></tr>
                        <tr><td class="label">Date Registered:</td><td>${dateRegistered}</td><td class="label">Status:</td><td colspan="3"><span class="badge bg-secondary">DECEASED</span></td></tr>
                    </table>

                    <div class="section-title">IX. ACCOUNT INFORMATION</div>
                    <table class="info-table">
                        <tr><td class="label">Username:</td><td colspan="5">${data.username || 'Not set'}</td></tr>
                    </table>
                </div>
            `;

            contentEl.innerHTML = content;
        })
        .catch(error => {
            contentEl.innerHTML = `
                <div class="alert alert-danger m-3">
                    <i class="fas fa-exclamation-circle me-2"></i>
                    Error loading deceased member details. Please try again.
                </div>
            `;
        });
}
        // ============================================================
        // DOCUMENT READY
        // ============================================================
        $(document).ready(function() {
            // Toast setup
            const urlParams = new URLSearchParams(window.location.search);
            const success = urlParams.get('success');
            
            const successToastEl = document.getElementById('successToast');
            const errorToastEl = document.getElementById('errorToast');
            const warningToastEl = document.getElementById('warningToast');
            
            if (successToastEl) window.successToast = new bootstrap.Toast(successToastEl);
            if (errorToastEl) window.errorToast = new bootstrap.Toast(errorToastEl);
            if (warningToastEl) window.warningToast = new bootstrap.Toast(warningToastEl);
            
            if (success === 'added' && window.successToast) {
                document.getElementById('successMessage').textContent = 'Member added successfully!';
                window.successToast.show();
            } else if (success === 'updated' && window.successToast) {
                document.getElementById('successMessage').textContent = 'Member updated successfully!';
                window.successToast.show();
            } else if (success === 'deleted' && window.warningToast) {
                document.getElementById('warningMessage').textContent = 'Member moved to deleted history.';
                window.warningToast.show();
            } else if (success === 'deleted_multiple' && window.warningToast) {
                document.getElementById('warningMessage').textContent = 'Selected members moved to deleted history.';
                window.warningToast.show();
            } else if (success === 'inactive' && window.warningToast) {
                document.getElementById('warningMessage').textContent = 'Member set as inactive successfully.';
                window.warningToast.show();
            } else if (success === 'reactivated' && window.successToast) {
                document.getElementById('successMessage').textContent = 'Member reactivated successfully!';
                window.successToast.show();
            } else if (success === 'recovered' && window.successToast) {
                document.getElementById('successMessage').textContent = 'Member recovered successfully!';
                window.successToast.show();
            } else if (success === 'reverted' && window.successToast) {
                document.getElementById('successMessage').textContent = 'Member data reverted successfully!';
                window.successToast.show();
            } else if (success === 'undeath' && window.successToast) {
                document.getElementById('successMessage').textContent = 'Death record undone. Member reactivated!';
                window.successToast.show();
            
            } else if (success === 'deceased' && window.warningToast) {
                document.getElementById('warningMessage').textContent = 'Member marked as deceased.';
                window.warningToast.show();
            }
            
            if (window.errorMessage && window.errorToast) {
                document.getElementById('errorMessage').textContent = window.errorMessage;
                window.errorToast.show();
            }
                
            // Initialize
            updateCities('add');
            updateCities('edit');
            
           // Clickable rows — branch by view mode
$('.clickable-row').on('click', function(e) {
    // Ignore clicks on buttons / forms / dropdowns inside the row
    if ($(e.target).closest('button, a, form, .dropdown-menu').length) return;

    const memberCode = $(this).data('member-code');
    if (!memberCode) return;

    const isInactive = String($(this).attr('data-inactive')) === '1';
    const isDeceased = String($(this).attr('data-deceased')) === '1';

    if (isInactive) {
        viewInactiveDetails(memberCode);
    } else if (isDeceased) {
        viewDeceasedDetails(memberCode);
    } else {
        viewMemberDetails(memberCode);
    }
});
            // Select all checkbox
            $('#selectAllCheckbox').on('change', function() {
                $('.member-checkbox').prop('checked', $(this).is(':checked'));
                updateSelectedCount();
            });
            
            // Member checkbox change
            $('.member-checkbox').on('change', function() {
                updateSelectedCount();
            });
            
            function updateSelectedCount() {
                const selectedCount = $('.member-checkbox:checked').length;
                $('#selectedCount').text(selectedCount);
                
                if (selectedCount > 0) {
                    $('#deleteSelectedDiv').show();
                } else {
                    $('#deleteSelectedDiv').hide();
                }
                
                const totalCheckboxes = $('.member-checkbox').length;
                const checkedCheckboxes = $('.member-checkbox:checked').length;
                
                if (totalCheckboxes > 0 && totalCheckboxes === checkedCheckboxes) {
                    $('#selectAllCheckbox').prop('checked', true);
                } else {
                    $('#selectAllCheckbox').prop('checked', false);
                }
            }
            
            updateSelectedCount();
            
            // Inactive reason change
            $('#inactive_reason').on('change', function() {
                if ($(this).val() === 'Other') {
                    $('#other_reason_container').show();
                    $('#inactive_other_reason').prop('required', true);
                } else {
                    $('#other_reason_container').hide();
                    $('#inactive_other_reason').prop('required', false);
                }
            });
            
            // Form validation
            $('#addMemberForm').on('submit', function(e) {
                const memberCode = this.querySelector('[name="member_code"]').value;
                const firstName = this.querySelector('[name="first_name"]').value;
                const lastName = this.querySelector('[name="last_name"]').value;
                const contact = this.querySelector('[name="contact_number"]').value;
                const username = this.querySelector('[name="username"]').value;
                const password = this.querySelector('[name="password"]').value;
                const confirmPassword = this.querySelector('[name="confirm_password"]').value;
                
                if (!memberCode || !firstName || !lastName || !contact) {
                    e.preventDefault();
                    document.getElementById('errorMessage').textContent = 'Please fill in all required fields.';
                    if (window.errorToast) window.errorToast.show();
                    return;
                }
                
                if (username && password && password !== confirmPassword) {
                    e.preventDefault();
                    document.getElementById('errorMessage').textContent = 'Passwords do not match.';
                    if (window.errorToast) window.errorToast.show();
                    return;
                }
                
                if (username && password && password.length < 6) {
                    e.preventDefault();
                    document.getElementById('errorMessage').textContent = 'Password must be at least 6 characters.';
                    if (window.errorToast) window.errorToast.show();
                    return;
                }
            });
            
            $('#editMemberForm').on('submit', function(e) {
                const firstName = this.querySelector('[name="first_name"]').value;
                const lastName = this.querySelector('[name="last_name"]').value;
                const contact = this.querySelector('[name="contact_number"]').value;
                
                if (!firstName || !lastName || !contact) {
                    e.preventDefault();
                    document.getElementById('errorMessage').textContent = 'Please fill in all required fields.';
                    if (window.errorToast) window.errorToast.show();
                }
            });
            
            $('#deceasedMemberForm').on('submit', function(e) {
                const deathDate = this.querySelector('[name="death_date"]').value;
                if (!deathDate) {
                    e.preventDefault();
                    document.getElementById('errorMessage').textContent = 'Please enter the date of death.';
                    if (window.errorToast) window.errorToast.show();
                }
            });
        });

        // ============================================================
        // DOCUMENT VIEWER — secure admin-only passthrough
        // ============================================================
        window.openDocumentViewer = function(memberCode, docType, title) {
            if (!memberCode || !docType) {
                alert('Missing document reference.');
                return;
            }

            const modalEl   = document.getElementById('documentViewerModal');
            const titleEl   = document.getElementById('docViewerTitle');
            const bodyEl    = document.getElementById('docViewerBody');
            const dlBtn     = document.getElementById('docViewerDownload');
            const tabBtn    = document.getElementById('docViewerOpenTab');

            // Build the secure URL to the passthrough script
            const url = 'view_document.php'
                      + '?member_code=' + encodeURIComponent(memberCode)
                      + '&doc_type='    + encodeURIComponent(docType);

            // Reset UI
            titleEl.textContent = title || 'Document';
            bodyEl.innerHTML = `
                <div class="text-center p-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-3 text-muted">Loading document...</p>
                </div>
            `;

            dlBtn.href  = url;
            dlBtn.setAttribute('download', (title || 'document'));
            tabBtn.href = url;

            const modal = new bootstrap.Modal(modalEl);
            modal.show();

            // Probe the file via fetch so we can show a friendly error
            // if the document is missing on the server.
            fetch(url, { method: 'GET', credentials: 'same-origin' })
                .then(response => {
                    if (!response.ok) {
                        return response.text().then(msg => {
                            throw new Error(msg || ('HTTP ' + response.status));
                        });
                    }
                    const ct = (response.headers.get('Content-Type') || '').toLowerCase();

                    // PDF → iframe, image → img, anything else → link
                    if (ct.indexOf('application/pdf') !== -1) {
                        bodyEl.innerHTML = `
                            <iframe src="${url}"
                                    style="width:100%; height:78vh; border:0; display:block;"
                                    title="${title}"></iframe>
                        `;
                    } else if (ct.indexOf('image/') === 0) {
                        bodyEl.innerHTML = `
                            <div style="padding:20px;">
                                <img src="${url}" alt="${title}"
                                     style="max-width:100%; max-height:75vh; border-radius:8px;
                                            box-shadow:0 2px 12px rgba(0,0,0,0.1); background:#fff;">
                            </div>
                        `;
                    } else {
                        bodyEl.innerHTML = `
                            <div class="alert alert-info m-4">
                                <i class="fas fa-info-circle me-2"></i>
                                This file type can't be previewed inline.
                                Use <strong>Download</strong> or <strong>Open in New Tab</strong> below.
                            </div>
                        `;
                    }
                })
                .catch(err => {
                    const msg = (err && err.message) ? err.message : 'Unable to load document.';
                    bodyEl.innerHTML = `
                        <div class="alert alert-danger m-4 text-start">
                            <i class="fas fa-exclamation-circle me-2"></i>
                            <strong>Document unavailable.</strong><br>
                            <small class="text-muted">${msg}</small>
                        </div>
                    `;
                });
        };
    </script>
    <script>
    // Prevent stuck modal backdrops
    document.addEventListener('DOMContentLoaded', function() {
        // When any modal is hidden, force clean up the body and backdrop
        document.querySelectorAll('.modal').forEach(function(modalEl) {
            modalEl.addEventListener('hidden.bs.modal', function () {
                // If no other modals are open, remove the class and wipe the backdrop
                if (document.querySelectorAll('.modal.show').length === 0) {
                    document.body.classList.remove('modal-open');
                    document.body.style.overflow = '';
                    document.body.style.paddingRight = '';
                    
                    const backdrops = document.querySelectorAll('.modal-backdrop');
                    backdrops.forEach(function(backdrop) {
                        backdrop.remove();
                    });
                }
            });
        });
    });
</script>
</body>
</html>