<?php
// admin/member_export.php
require_once '../includes/config.php';
require_once '../includes/db_connection.php';
require_once '../includes/security.php';
require_once '../includes/auth.php';
require_once 'includes/functions.php';

$auth->requireLogin();
$current_user = $auth->getCurrentUser();

// Only admins can export
if ($current_user['role'] !== 'admin') {
    header('Location: dashboard.php');
    exit();
}

$db = Database::getInstance();
$error = '';
$success = '';

// ===== AVAILABLE FIELDS =====
$available_fields = [
    'member_code' => ['label' => 'Member Code', 'category' => 'Personal', 'default' => true],
    'first_name' => ['label' => 'First Name', 'category' => 'Personal', 'default' => true],
    'last_name' => ['label' => 'Last Name', 'category' => 'Personal', 'default' => true],
    'middle_name' => ['label' => 'Middle Name', 'category' => 'Personal', 'default' => false],
    'email' => ['label' => 'Email', 'category' => 'Contact', 'default' => true],
    'contact_number' => ['label' => 'Contact Number', 'category' => 'Contact', 'default' => true],
    'alternate_number' => ['label' => 'Alternate Number', 'category' => 'Contact', 'default' => false],
    'province' => ['label' => 'Province', 'category' => 'Address', 'default' => true],
    'city' => ['label' => 'City', 'category' => 'Address', 'default' => true],
    'barangay' => ['label' => 'Barangay', 'category' => 'Address', 'default' => true],
    'street' => ['label' => 'Street', 'category' => 'Address', 'default' => false],
    'birth_date' => ['label' => 'Birth Date', 'category' => 'Personal Details', 'default' => false],
    'place_of_birth' => ['label' => 'Place of Birth', 'category' => 'Personal Details', 'default' => false],
    'gender' => ['label' => 'Gender', 'category' => 'Personal Details', 'default' => false],
    'civil_status' => ['label' => 'Civil Status', 'category' => 'Personal Details', 'default' => false],
    'religion' => ['label' => 'Religion', 'category' => 'Personal Details', 'default' => false],
    'chapter' => ['label' => 'Chapter', 'category' => 'Chapter', 'default' => true],
    'group_name' => ['label' => 'Group Name', 'category' => 'Chapter', 'default' => false],
    'leader' => ['label' => 'Leader', 'category' => 'Chapter', 'default' => false],
    'coordinator' => ['label' => 'Coordinator', 'category' => 'Chapter', 'default' => false],
    'chairman' => ['label' => 'Chairman', 'category' => 'Chapter', 'default' => false],
    'father_name' => ['label' => "Father's Full Name", 'category' => 'Family', 'default' => false],
    'mother_name' => ['label' => "Mother's Full Name", 'category' => 'Family', 'default' => false],
    'spouse_name' => ['label' => "Spouse's Full Name", 'category' => 'Family', 'default' => false],
    'spouse_age' => ['label' => "Spouse's Age", 'category' => 'Family', 'default' => false],
    'child1_name' => ['label' => "Child 1 Name", 'category' => 'Family', 'default' => false],
    'child1_age' => ['label' => "Child 1 Age", 'category' => 'Family', 'default' => false],
    'child2_name' => ['label' => "Child 2 Name", 'category' => 'Family', 'default' => false],
    'child2_age' => ['label' => "Child 2 Age", 'category' => 'Family', 'default' => false],
    'child3_name' => ['label' => "Child 3 Name", 'category' => 'Family', 'default' => false],
    'child3_age' => ['label' => "Child 3 Age", 'category' => 'Family', 'default' => false],
    'child4_name' => ['label' => "Child 4 Name", 'category' => 'Family', 'default' => false],
    'child4_age' => ['label' => "Child 4 Age", 'category' => 'Family', 'default' => false],
    'beneficiary_name' => ['label' => 'Beneficiary Name', 'category' => 'Beneficiary', 'default' => true],
    'beneficiary_relation' => ['label' => 'Beneficiary Relation', 'category' => 'Beneficiary', 'default' => true],
    'beneficiary_address' => ['label' => 'Beneficiary Address', 'category' => 'Beneficiary', 'default' => false],
    'beneficiary_contact' => ['label' => 'Beneficiary Contact', 'category' => 'Beneficiary', 'default' => false],
    'monthly_contribution' => ['label' => 'Monthly Contribution', 'category' => 'Payment', 'default' => true],
    'payment_status' => ['label' => 'Payment Status', 'category' => 'Payment', 'default' => true],
    'date_joined' => ['label' => 'Date Joined', 'category' => 'Dates', 'default' => true],
    'date_registered' => ['label' => 'Date Registered', 'category' => 'Dates', 'default' => false],
    'status' => ['label' => 'Member Status', 'category' => 'Dates', 'default' => true],
];

// ===== GET SAVED PREFERENCES =====
$saved_fields = $_SESSION['export_fields'] ?? array_keys(array_filter($available_fields, function($f) {
    return $f['default'] === true;
}));

// ===== HANDLE FORM SUBMISSION =====
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token';
    } else {
        $action = $_POST['action'] ?? '';
        
        if ($action === 'preview') {
            $selected_fields = $_POST['fields'] ?? [];
            $_SESSION['export_fields'] = $selected_fields;
            $saved_fields = $selected_fields;
            $success = 'Preview updated. Scroll down to see sample data.';
            
        } elseif ($action === 'export') {
            $selected_fields = $_POST['fields'] ?? [];
            $_SESSION['export_fields'] = $selected_fields;
            $saved_fields = $selected_fields;
            
            $members = $db->getAll("
                SELECT 
                    m.member_code,
                    m.first_name,
                    m.last_name,
                    m.middle_name,
                    m.email,
                    m.contact_number,
                    m.alternate_number,
                    m.province,
                    m.city,
                    m.barangay,
                    m.street,
                    m.birth_date,
                    m.place_of_birth,
                    m.gender,
                    m.civil_status,
                    m.religion,
                    m.chapter,
                    m.group_name,
                    m.leader,
                    m.coordinator,
                    m.chairman,
                    m.father_name,
                    m.mother_name,
                    m.spouse_name,
                    m.spouse_age,
                    m.child1_name,
                    m.child1_age,
                    m.child2_name,
                    m.child2_age,
                    m.child3_name,
                    m.child3_age,
                    m.child4_name,
                    m.child4_age,
                    m.beneficiary_name,
                    m.beneficiary_relation,
                    m.beneficiary_address,
                    m.beneficiary_contact,
                    m.monthly_contribution,
                    m.status,
                    m.date_joined,
                    m.date_registered
                FROM members m
                WHERE m.status = 'active'
                ORDER BY m.last_name ASC
            ");
            
            foreach ($members as &$member) {
                $payment_info = getMemberPaymentStatus($member['member_code'], $db);
                $member['payment_status'] = $payment_info['label'] ?? 'Unpaid';
            }
            
            if (empty($members)) {
                $error = 'No active members found to export.';
            } else {
                $csv_data = [];
                $headers = [];
                foreach ($selected_fields as $field) {
                    if (isset($available_fields[$field])) {
                        $headers[] = $available_fields[$field]['label'];
                    }
                }
                $csv_data[] = $headers;
                
                foreach ($members as $member) {
                    $row = [];
                    foreach ($selected_fields as $field) {
                        $value = $member[$field] ?? '';
                        $value = html_entity_decode(strip_tags($value));
                        $value = str_replace('"', '""', $value);
                        $row[] = $value;
                    }
                    $csv_data[] = $row;
                }
                
                $output = fopen('php://temp', 'r+');
                foreach ($csv_data as $row) {
                    fputcsv($output, $row);
                }
                rewind($output);
                $csv_content = stream_get_contents($output);
                fclose($output);
                
                $filename = 'members_export_' . date('Y-m-d') . '.csv';
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                header('Content-Length: ' . strlen($csv_content));
                header('Cache-Control: private, max-age=0, must-revalidate');
                
                echo "\xEF\xBB\xBF";
                echo $csv_content;
                exit();
            }
        }
    }
}

// ===== GET PREVIEW DATA =====
$preview_members = [];
if (!empty($saved_fields)) {
    $preview_members = $db->getAll("
        SELECT 
            m.member_code,
            m.first_name,
            m.last_name,
            m.middle_name,
            m.email,
            m.contact_number,
            m.alternate_number,
            m.province,
            m.city,
            m.barangay,
            m.street,
            m.birth_date,
            m.place_of_birth,
            m.gender,
            m.civil_status,
            m.religion,
            m.chapter,
            m.group_name,
            m.leader,
            m.coordinator,
            m.chairman,
            m.father_name,
            m.mother_name,
            m.spouse_name,
            m.spouse_age,
            m.child1_name,
            m.child1_age,
            m.child2_name,
            m.child2_age,
            m.child3_name,
            m.child3_age,
            m.child4_name,
            m.child4_age,
            m.beneficiary_name,
            m.beneficiary_relation,
            m.beneficiary_address,
            m.beneficiary_contact,
            m.monthly_contribution,
            m.status,
            m.date_joined,
            m.date_registered
        FROM members m
        WHERE m.status = 'active'
        ORDER BY m.last_name ASC
        LIMIT 5
    ");
    
    foreach ($preview_members as &$member) {
        $payment_info = getMemberPaymentStatus($member['member_code'], $db);
        $member['payment_status'] = $payment_info['label'] ?? 'Unpaid';
    }
}

$csrf_token = Security::generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Export Members - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { box-sizing: border-box; }
        body {
            background: #f0f2f5;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            padding: 16px;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .export-container {
            max-width: 1100px;
            width: 100%;
            margin: 0 auto;
            background: white;
            border-radius: 16px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.07);
            overflow: hidden;
        }
        
        /* ===== HEADER ===== */
        .export-header {
            background: linear-gradient(135deg, #375a7f 0%, #4a7a9c 100%);
            color: white;
            padding: 18px 24px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 12px;
        }
        
        .export-header .header-left {
            display: flex;
            align-items: center;
            gap: 14px;
        }
        
        .export-header .header-left .icon-circle {
            width: 42px;
            height: 42px;
            background: rgba(255,255,255,0.18);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
        }
        
        .export-header h2 {
            margin: 0;
            font-weight: 600;
            font-size: 1.25rem;
        }
        
        .export-header .subtitle {
            font-size: 0.8rem;
            opacity: 0.8;
            margin: 2px 0 0 0;
        }
        
        .export-header .btn-back {
            color: white;
            text-decoration: none;
            background: rgba(255,255,255,0.15);
            padding: 6px 16px;
            border-radius: 8px;
            font-size: 0.85rem;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .export-header .btn-back:hover {
            background: rgba(255,255,255,0.25);
            color: white;
        }
        
        /* ===== BODY ===== */
        .export-body {
            padding: 20px 24px 24px 24px;
        }
        
        /* ===== TOOLBAR ===== */
        .toolbar-row {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 16px;
            padding-bottom: 14px;
            border-bottom: 1px solid #e9ecef;
        }
        
        .toolbar-left {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            align-items: center;
        }
        
        .toolbar-left .btn-sm {
            font-size: 0.78rem;
            padding: 4px 12px;
            border-radius: 6px;
            border: 1px solid #dee2e6;
            background: white;
            color: #495057;
            transition: all 0.2s;
            cursor: pointer;
        }
        
        .toolbar-left .btn-sm:hover {
            background: #f8f9fa;
            border-color: #adb5bd;
        }
        
        .toolbar-left .btn-sm i {
            margin-right: 4px;
        }
        
        .toolbar-left .selected-count {
            font-size: 0.8rem;
            color: #6c757d;
            margin-left: 4px;
        }
        
        .toolbar-left .selected-count strong {
            color: #375a7f;
        }
        
        .toolbar-right {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        
        .btn-preview {
            background: #6c757d;
            color: white;
            padding: 6px 18px;
            font-size: 0.85rem;
            font-weight: 500;
            border: none;
            border-radius: 8px;
            transition: all 0.25s;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .btn-preview:hover {
            background: #5a6268;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(108,117,125,0.3);
        }
        
        .btn-export {
            background: linear-gradient(135deg, #28a745, #1e7e34);
            color: white;
            padding: 6px 22px;
            font-size: 0.85rem;
            font-weight: 600;
            border: none;
            border-radius: 8px;
            transition: all 0.25s;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .btn-export:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 16px rgba(40,167,69,0.35);
        }
        
        /* ===== FIELD CATEGORIES ===== */
        .field-category {
            margin-bottom: 14px;
        }
        
        .field-category:last-child {
            margin-bottom: 0;
        }
        
        .field-category .cat-header {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
            color: #375a7f;
            font-size: 0.85rem;
            padding: 6px 0 4px 0;
            border-bottom: 2px solid #e9ecef;
            margin-bottom: 8px;
        }
        
        .field-category .cat-header i {
            font-size: 0.75rem;
            color: #4a7a9c;
        }
        
        .field-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 2px 16px;
        }
        
        .field-grid .form-check {
            padding-left: 26px;
            margin-bottom: 2px;
            min-height: 28px;
            display: flex;
            align-items: center;
        }
        
        .field-grid .form-check-input {
            margin-left: -26px;
            margin-top: 0;
            width: 15px;
            height: 15px;
            cursor: pointer;
            border-radius: 3px;
            border: 1.5px solid #ced4da;
            flex-shrink: 0;
            transition: all 0.15s;
        }
        
        .field-grid .form-check-input:checked {
            background-color: #375a7f;
            border-color: #375a7f;
        }
        
        .field-grid .form-check-input:focus {
            box-shadow: 0 0 0 2px rgba(55,90,127,0.15);
            border-color: #375a7f;
        }
        
        .field-grid .form-check-label {
            font-size: 0.82rem;
            cursor: pointer;
            color: #2c3e50;
            padding-top: 1px;
            user-select: none;
        }
        
        .field-grid .form-check-label .field-db {
            font-size: 0.6rem;
            color: #adb5bd;
            font-weight: 400;
            margin-left: 4px;
        }
        
        /* ===== PREVIEW ===== */
        .preview-section {
            margin-top: 18px;
            padding-top: 16px;
            border-top: 1px solid #e9ecef;
        }
        
        .preview-section .preview-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 10px;
        }
        
        .preview-section .preview-header h6 {
            margin: 0;
            font-weight: 600;
            color: #2c3e50;
            font-size: 0.9rem;
        }
        
        .preview-section .preview-header .badge-info {
            background: #e9ecef;
            color: #6c757d;
            font-size: 0.7rem;
            padding: 2px 10px;
            border-radius: 12px;
            font-weight: 500;
        }
        
        .preview-table-wrap {
            max-height: 260px;
            overflow-y: auto;
            border-radius: 10px;
            border: 1px solid #e9ecef;
        }
        
        .preview-table-wrap::-webkit-scrollbar {
            width: 6px;
        }
        
        .preview-table-wrap::-webkit-scrollbar-track {
            background: #f1f1f1;
            border-radius: 4px;
        }
        
        .preview-table-wrap::-webkit-scrollbar-thumb {
            background: #d1d5db;
            border-radius: 4px;
        }
        
        .preview-table-wrap::-webkit-scrollbar-thumb:hover {
            background: #b0b5ba;
        }
        
        .preview-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.78rem;
            min-width: 500px;
        }
        
        .preview-table thead th {
            background: #f8f9fa;
            padding: 8px 12px;
            text-align: left;
            font-weight: 600;
            color: #495057;
            border-bottom: 2px solid #dee2e6;
            font-size: 0.68rem;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            white-space: nowrap;
            position: sticky;
            top: 0;
            z-index: 5;
        }
        
        .preview-table tbody td {
            padding: 7px 12px;
            border-bottom: 1px solid #f1f3f5;
            color: #2c3e50;
            vertical-align: middle;
            white-space: nowrap;
            max-width: 160px;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .preview-table tbody tr:hover td {
            background: #f8f9fa;
        }
        
        .preview-table .empty-cell {
            color: #adb5bd;
            font-style: italic;
        }
        
        .preview-note {
            font-size: 0.72rem;
            color: #6c757d;
            margin-top: 8px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .preview-note i {
            font-size: 0.7rem;
        }
        
        /* ===== ALERTS ===== */
        .alert {
            border-radius: 10px;
            padding: 10px 16px;
            font-size: 0.85rem;
            margin-bottom: 14px;
            border: none;
        }
        
        .alert-danger {
            background: #fde8e8;
            color: #842029;
        }
        
        .alert-success {
            background: #d4edda;
            color: #0f5132;
        }
        
        .alert .btn-close {
            padding: 6px;
            font-size: 0.7rem;
        }
        
        /* ===== RESPONSIVE ===== */
        @media (max-width: 768px) {
            .export-body {
                padding: 14px 16px 18px 16px;
            }
            
            .export-header {
                padding: 14px 16px;
            }
            
            .export-header h2 {
                font-size: 1.05rem;
            }
            
            .export-header .subtitle {
                font-size: 0.7rem;
            }
            
            .toolbar-row {
                flex-direction: column;
                align-items: stretch;
                gap: 10px;
            }
            
            .toolbar-left {
                justify-content: center;
            }
            
            .toolbar-right {
                justify-content: center;
            }
            
            .btn-preview, .btn-export {
                flex: 1;
                justify-content: center;
                padding: 6px 14px;
                font-size: 0.8rem;
            }
            
            .field-grid {
                grid-template-columns: 1fr 1fr;
                gap: 2px 12px;
            }
            
            .field-grid .form-check-label {
                font-size: 0.78rem;
            }
            
            .preview-table-wrap {
                max-height: 200px;
            }
            
            .preview-table {
                font-size: 0.7rem;
                min-width: 400px;
            }
            
            .preview-table thead th,
            .preview-table tbody td {
                padding: 5px 8px;
            }
        }
        
        @media (max-width: 480px) {
            body {
                padding: 10px;
            }
            
            .export-body {
                padding: 10px 12px 14px 12px;
            }
            
            .field-grid {
                grid-template-columns: 1fr;
            }
            
            .toolbar-left {
                flex-wrap: wrap;
                justify-content: center;
            }
            
            .toolbar-left .selected-count {
                width: 100%;
                text-align: center;
                margin-top: 2px;
            }
            
            .preview-table {
                min-width: 320px;
                font-size: 0.65rem;
            }
            
            .preview-table thead th,
            .preview-table tbody td {
                padding: 4px 6px;
            }
        }
    </style>
</head>
<body>
    <div class="export-container">
        <!-- ===== HEADER ===== -->
        <div class="export-header">
            <div class="header-left">
                <div class="icon-circle">
                    <i class="fas fa-file-export"></i>
                </div>
                <div>
                    <h2>Export Members</h2>
                    <p class="subtitle">Select fields and download CSV file</p>
                </div>
            </div>
            <a href="dashboard.php" class="btn-back">
                <i class="fas fa-arrow-left"></i> Back
            </a>
        </div>

        <!-- ===== BODY ===== -->
        <div class="export-body">
            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-circle me-2"></i><?php echo $error; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="fas fa-check-circle me-2"></i><?php echo $success; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <form method="POST" action="" id="exportForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" id="formAction" value="preview">

                <!-- ===== TOOLBAR ===== -->
                <div class="toolbar-row">
                    <div class="toolbar-left">
                        <button type="button" class="btn-sm" onclick="selectAll()">
                            <i class="fas fa-check-double"></i> All
                        </button>
                        <button type="button" class="btn-sm" onclick="deselectAll()">
                            <i class="fas fa-times"></i> None
                        </button>
                        <span class="selected-count">
                            <strong id="countDisplay">0</strong> fields selected
                        </span>
                    </div>
                    <div class="toolbar-right">
                        <button type="submit" class="btn-preview" id="previewBtn">
                            <i class="fas fa-eye"></i> Preview
                        </button>
                        <button type="submit" class="btn-export" id="exportBtn">
                            <i class="fas fa-download"></i> Export CSV
                        </button>
                    </div>
                </div>

                <!-- ===== FIELD CATEGORIES ===== -->
                <?php
                $categories = [];
                foreach ($available_fields as $key => $field) {
                    $categories[$field['category']][] = $key;
                }
                ?>

                <?php foreach ($categories as $category => $fields): ?>
                <div class="field-category">
                    <div class="cat-header">
                        <i class="fas fa-chevron-right"></i>
                        <?php echo $category; ?>
                    </div>
                    <div class="field-grid">
                        <?php foreach ($fields as $field_key): ?>
                            <?php $field = $available_fields[$field_key]; ?>
                            <div class="form-check">
                                <input type="checkbox" 
                                       class="form-check-input field-checkbox" 
                                       name="fields[]" 
                                       value="<?php echo $field_key; ?>"
                                       id="field_<?php echo $field_key; ?>"
                                       <?php echo in_array($field_key, $saved_fields) ? 'checked' : ''; ?>
                                       onchange="updateCount()">
                                <label class="form-check-label" for="field_<?php echo $field_key; ?>">
                                    <?php echo $field['label']; ?>
                                    <span class="field-db"><?php echo $field_key; ?></span>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </form>

            <!-- ===== PREVIEW ===== -->
            <?php if (!empty($preview_members) && !empty($saved_fields)): ?>
            <div class="preview-section">
                <div class="preview-header">
                    <h6><i class="fas fa-eye me-2"></i>Preview</h6>
                    <span class="badge-info">
                        <?php echo count($preview_members); ?> members · <?php echo count($saved_fields); ?> fields
                    </span>
                </div>
                <div class="preview-table-wrap">
                    <table class="preview-table">
                        <thead>
                            <tr>
                                <?php foreach ($saved_fields as $field): ?>
                                    <?php if (isset($available_fields[$field])): ?>
                                        <th><?php echo $available_fields[$field]['label']; ?></th>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($preview_members as $member): ?>
                                <tr>
                                    <?php foreach ($saved_fields as $field): ?>
                                        <?php if (isset($available_fields[$field])): ?>
                                            <td>
                                                <?php 
                                                    $value = $member[$field] ?? '';
                                                    if ($field === 'payment_status') {
                                                        $payment_info = getMemberPaymentStatus($member['member_code'], $db);
                                                        $value = $payment_info['label'] ?? 'Unpaid';
                                                    }
                                                    echo $value ? htmlspecialchars($value) : '<span class="empty-cell">—</span>';
                                                ?>
                                            </td>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="preview-note">
                    <i class="fas fa-info-circle"></i>
                    Showing first <?php echo count($preview_members); ?> members. Export includes all active members.
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // ========================================
        // FIELD SELECTION
        // ========================================
        function updateCount() {
            const checked = document.querySelectorAll('.field-checkbox:checked').length;
            document.getElementById('countDisplay').textContent = checked;
        }

        function selectAll() {
            document.querySelectorAll('.field-checkbox').forEach(cb => cb.checked = true);
            updateCount();
        }

        function deselectAll() {
            document.querySelectorAll('.field-checkbox').forEach(cb => cb.checked = false);
            updateCount();
        }

        // ========================================
        // FORM SUBMISSION
        // ========================================
        document.getElementById('previewBtn').addEventListener('click', function(e) {
            e.preventDefault();
            document.getElementById('formAction').value = 'preview';
            document.getElementById('exportForm').submit();
        });

        document.getElementById('exportBtn').addEventListener('click', function(e) {
            e.preventDefault();
            const checked = document.querySelectorAll('.field-checkbox:checked').length;
            if (checked === 0) {
                alert('Please select at least one field to export.');
                return;
            }
            if (confirm('Export ' + checked + ' fields for all active members?')) {
                document.getElementById('formAction').value = 'export';
                document.getElementById('exportForm').submit();
            }
        });

        // ========================================
        // KEYBOARD SHORTCUT
        // ========================================
        document.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                e.preventDefault();
                document.getElementById('exportBtn').click();
            }
        });

        // ========================================
        // INIT
        // ========================================
        updateCount();
        console.log('✅ Export page loaded');
    </script>
</body>
</html>