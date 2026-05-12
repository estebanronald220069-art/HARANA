<?php
// register.php
require_once 'includes/config.php';
require_once 'includes/db_connection.php';
require_once 'includes/security.php';

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$error = '';
$success = '';
$form_data = [];

// Chapter options
$chapters = [
    'GUIMBA', 'ALIAGA', 'BONGABON', 'CARRANGLAN', 'GABALDON',
    'GEN. NATIVIDAD', 'LAUR', 'LICAB', 'LLANERA', 'LUPAO',
    'PALAYAN', 'PANTABANGAN', 'QUEZON', 'RIZAL', 'STO. DOMINGO',
    'TALAVERA', 'TALUGTUG', 'UMINGAN'
];

// City data for JavaScript
$cities_data = [
    'Occidental Mindoro' => ['San Jose', 'Mamburao', 'Sablayan', 'Calintaan', 'Rizal', 'Abra de Ilog', 'Paluan', 'Santa Cruz'],
    'Nueva Ecija' => ['Palayan City', 'Cabanatuan', 'Gapan', 'San Jose', 'Science City of Muñoz', 'Guimba', 'Talavera', 'San Leonardo', 'Santa Rosa', 'General Tinio'],
    'Pampanga' => ['San Fernando', 'Angeles City', 'Mabalacat', 'Mexico', 'Arayat', 'Candaba', 'Lubao', 'Porac', 'Floridablanca', 'Guagua'],
    'Bulacan' => ['Malolos', 'Meycauayan', 'San Jose del Monte', 'Baliuag', 'Marilao', 'Bocaue', 'Santa Maria', 'Pulilan', 'Plaridel', 'Norzagaray'],
    'Tarlac' => ['Tarlac City', 'Concepcion', 'Capas', 'Paniqui', 'Gerona', 'Camiling', 'Moncada', 'Victoria', 'San Jose', 'La Paz']
];

// Handle registration form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Debug POST data
    error_log("=== REGISTRATION POST DATA ===");
    error_log(print_r($_POST, true));
    
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token';
    } else {
        $db = Database::getInstance();
        
        // Get all form data
        $data = [];
        
        // Personal Information
        $data['first_name'] = Security::sanitize($_POST['first_name'] ?? '');
        $data['last_name'] = Security::sanitize($_POST['last_name'] ?? '');
        $data['middle_name'] = Security::sanitize($_POST['middle_name'] ?? '');
        
        // Address components
        $data['province'] = Security::sanitize($_POST['province'] ?? 'Occidental Mindoro');
        $data['city'] = Security::sanitize($_POST['city'] ?? 'San Jose');
        $data['barangay'] = Security::sanitize($_POST['barangay'] ?? '');
        $data['street'] = Security::sanitize($_POST['street'] ?? '');
        
        // Combine address
        $address_parts = [];
        if (!empty($data['street'])) $address_parts[] = $data['street'];
        if (!empty($data['barangay'])) $address_parts[] = 'Brgy. ' . $data['barangay'];
        if (!empty($data['city'])) $address_parts[] = $data['city'];
        if (!empty($data['province'])) $address_parts[] = $data['province'];
        
        $data['address'] = implode(', ', $address_parts);
        $data['present_address'] = Security::sanitize($_POST['present_address'] ?? $data['address']);
        $data['permanent_address'] = Security::sanitize($_POST['permanent_address'] ?? $data['address']);
        
        // Contact Information
        $data['contact_number'] = Security::sanitize($_POST['contact_number'] ?? '');
        $data['alternate_number'] = Security::sanitize($_POST['alternate_number'] ?? '');
        $data['email'] = Security::sanitize($_POST['email'] ?? '');
        
        // Personal Details
        $data['birth_date'] = !empty($_POST['birth_date']) ? $_POST['birth_date'] : null;
        $data['place_of_birth'] = Security::sanitize($_POST['place_of_birth'] ?? '');
        $data['age'] = !empty($_POST['age']) ? intval($_POST['age']) : null;
        $data['gender'] = Security::sanitize($_POST['gender'] ?? '');
        $data['civil_status'] = Security::sanitize($_POST['civil_status'] ?? '');
        $data['religion'] = Security::sanitize($_POST['religion'] ?? '');
        
        // Family Information - Father
        $data['father_fname'] = Security::sanitize($_POST['father_fname'] ?? '');
        $data['father_mname'] = Security::sanitize($_POST['father_mname'] ?? '');
        $data['father_lname'] = Security::sanitize($_POST['father_lname'] ?? '');
        
        // Family Information - Mother
        $data['mother_fname'] = Security::sanitize($_POST['mother_fname'] ?? '');
        $data['mother_mname'] = Security::sanitize($_POST['mother_mname'] ?? '');
        $data['mother_lname'] = Security::sanitize($_POST['mother_lname'] ?? '');
        
        // Spouse
        $data['spouse_fname'] = Security::sanitize($_POST['spouse_fname'] ?? '');
        $data['spouse_mname'] = Security::sanitize($_POST['spouse_mname'] ?? '');
        $data['spouse_lname'] = Security::sanitize($_POST['spouse_lname'] ?? '');
        $data['spouse_age'] = !empty($_POST['spouse_age']) ? intval($_POST['spouse_age']) : null;
        
        // Children - Individual fields and combined names
        for ($i = 1; $i <= 4; $i++) {
            $data["child{$i}_fname"] = Security::sanitize($_POST["child{$i}_fname"] ?? '');
            $data["child{$i}_mname"] = Security::sanitize($_POST["child{$i}_mname"] ?? '');
            $data["child{$i}_lname"] = Security::sanitize($_POST["child{$i}_lname"] ?? '');
            $data["child{$i}_age"] = !empty($_POST["child{$i}_age"]) ? intval($_POST["child{$i}_age"]) : null;
            
            // Generate combined child name
            $child_name = trim(
                ($data["child{$i}_fname"] ?? '') . ' ' . 
                ($data["child{$i}_mname"] ?? '') . ' ' . 
                ($data["child{$i}_lname"] ?? '')
            );
            $data["child{$i}_name"] = !empty($child_name) ? $child_name : null;
        }
        
        // References
        $data['ref1_name'] = Security::sanitize($_POST['ref1_name'] ?? '');
        $data['ref1_contact'] = Security::sanitize($_POST['ref1_contact'] ?? '');
        $data['ref2_name'] = Security::sanitize($_POST['ref2_name'] ?? '');
        $data['ref2_contact'] = Security::sanitize($_POST['ref2_contact'] ?? '');
        
        // Chapter Information
        $data['chapter'] = Security::sanitize($_POST['chapter'] ?? '');
        $data['group_name'] = Security::sanitize($_POST['group_name'] ?? '');
        $data['leader'] = Security::sanitize($_POST['leader'] ?? '');
        $data['coordinator'] = Security::sanitize($_POST['coordinator'] ?? '');
        $data['chairman'] = Security::sanitize($_POST['chairman'] ?? '');
        $data['screening_officer'] = Security::sanitize($_POST['screening_officer'] ?? '');
        $data['screening_date'] = !empty($_POST['screening_date']) ? $_POST['screening_date'] : null;
        $data['approved_by'] = Security::sanitize($_POST['approved_by'] ?? '');
        $data['date_joined'] = !empty($_POST['date_joined']) ? $_POST['date_joined'] : date('Y-m-d');
        $data['date_registered'] = !empty($_POST['date_registered']) ? $_POST['date_registered'] : date('Y-m-d');
        $data['monthly_contribution'] = isset($_POST['monthly_contribution']) && $_POST['monthly_contribution'] !== '' ? floatval($_POST['monthly_contribution']) : 100.00;
        
        // Beneficiary
        $data['beneficiary_name'] = Security::sanitize($_POST['beneficiary_name'] ?? '');
        $data['beneficiary_address'] = Security::sanitize($_POST['beneficiary_address'] ?? '');
        $data['beneficiary_relation'] = Security::sanitize($_POST['beneficiary_relation'] ?? '');
        $data['beneficiary_age'] = !empty($_POST['beneficiary_age']) ? intval($_POST['beneficiary_age']) : null;
        $data['beneficiary_contact'] = Security::sanitize($_POST['beneficiary_contact'] ?? '');
        
        // Documents
        $data['medical_certificate'] = isset($_POST['medical_certificate']) ? 1 : 0;
        $data['birth_certificate'] = isset($_POST['birth_certificate']) ? 1 : 0;
        
        // Registration Photo - Get from session (uploaded via AJAX)
        if (isset($_SESSION['temp_registration_photo']) && !empty($_SESSION['temp_registration_photo'])) {
            $data['registration_photo'] = 'uploads/temp/' . $_SESSION['temp_registration_photo'];
        } else {
            $data['registration_photo'] = null;
        }
        
        // Account Information
        $data['username'] = Security::sanitize($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirm_password = $_POST['confirm_password'] ?? '';
        
        // Validate required fields
        $required_fields = ['first_name', 'last_name', 'contact_number', 'username', 'email', 'chapter', 'beneficiary_name', 'beneficiary_relation'];
        $missing_fields = [];
        
        foreach ($required_fields as $field) {
            if (empty($data[$field])) {
                $missing_fields[] = str_replace('_', ' ', ucfirst($field));
            }
        }
        
        if (!empty($missing_fields)) {
            $error = 'Please fill all required fields: ' . implode(', ', $missing_fields);
            $form_data = $_POST;
        } elseif (strlen($password) < 6) {
            $error = 'Password must be at least 6 characters';
            $form_data = $_POST;
        } elseif ($password !== $confirm_password) {
            $error = 'Passwords do not match';
            $form_data = $_POST;
        } else {
            // Check if username or email already exists
            $check_sql = "SELECT 'pending' as source, id FROM pending_users WHERE username = ? OR email = ? 
                          UNION 
                          SELECT 'users' as source, user_id as id FROM users WHERE username = ? OR email = ?";
            $existing = $db->getAll($check_sql, [$data['username'], $data['email'], $data['username'], $data['email']], 'ssss');
            
            if (count($existing) > 0) {
                $error = 'Username or email already exists';
                $form_data = $_POST;
            } else {
                // Generate unique member code - AUTO GENERATED
                $year = date('Y');
                $random = str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
                $member_code = $year . $random;
                
                // Hash password
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                
                // Build insert query - EXACT FIELDS matching members table
                $fields = [
                    'member_code', 'first_name', 'last_name', 'middle_name',
                    'address', 'present_address', 'permanent_address',
                    'barangay', 'city', 'province', 'street',
                    'contact_number', 'alternate_number', 'email',
                    'birth_date', 'place_of_birth', 'age', 'gender', 'civil_status', 'religion',
                    'father_fname', 'father_mname', 'father_lname',
                    'mother_fname', 'mother_mname', 'mother_lname',
                    'spouse_fname', 'spouse_mname', 'spouse_lname', 'spouse_age',
                    'child1_fname', 'child1_mname', 'child1_lname', 'child1_name', 'child1_age',
                    'child2_fname', 'child2_mname', 'child2_lname', 'child2_name', 'child2_age',
                    'child3_fname', 'child3_mname', 'child3_lname', 'child3_name', 'child3_age',
                    'child4_fname', 'child4_mname', 'child4_lname', 'child4_name', 'child4_age',
                    'ref1_name', 'ref1_contact', 'ref2_name', 'ref2_contact',
                    'chapter', 'group_name', 'leader', 'coordinator', 'chairman',
                    'screening_officer', 'screening_date', 'approved_by',
                    'date_joined', 'date_registered', 'monthly_contribution',
                    'beneficiary_name', 'beneficiary_address', 'beneficiary_relation',
                    'beneficiary_age', 'beneficiary_contact',
                    'medical_certificate', 'birth_certificate', 'registration_photo',
                    'username', 'password', 'role_requested', 'status'
                ];
                
                $placeholders = array_fill(0, count($fields), '?');
                
                // Prepare values in the same order as fields
                $insert_params = [
                    $member_code,
                    $data['first_name'],
                    $data['last_name'],
                    $data['middle_name'],
                    $data['address'],
                    $data['present_address'],
                    $data['permanent_address'],
                    $data['barangay'],
                    $data['city'],
                    $data['province'],
                    $data['street'],
                    $data['contact_number'],
                    $data['alternate_number'],
                    $data['email'],
                    $data['birth_date'],
                    $data['place_of_birth'],
                    $data['age'],
                    $data['gender'],
                    $data['civil_status'],
                    $data['religion'],
                    $data['father_fname'],
                    $data['father_mname'],
                    $data['father_lname'],
                    $data['mother_fname'],
                    $data['mother_mname'],
                    $data['mother_lname'],
                    $data['spouse_fname'],
                    $data['spouse_mname'],
                    $data['spouse_lname'],
                    $data['spouse_age'],
                    $data['child1_fname'],
                    $data['child1_mname'],
                    $data['child1_lname'],
                    $data['child1_name'],
                    $data['child1_age'],
                    $data['child2_fname'],
                    $data['child2_mname'],
                    $data['child2_lname'],
                    $data['child2_name'],
                    $data['child2_age'],
                    $data['child3_fname'],
                    $data['child3_mname'],
                    $data['child3_lname'],
                    $data['child3_name'],
                    $data['child3_age'],
                    $data['child4_fname'],
                    $data['child4_mname'],
                    $data['child4_lname'],
                    $data['child4_name'],
                    $data['child4_age'],
                    $data['ref1_name'],
                    $data['ref1_contact'],
                    $data['ref2_name'],
                    $data['ref2_contact'],
                    $data['chapter'],
                    $data['group_name'],
                    $data['leader'],
                    $data['coordinator'],
                    $data['chairman'],
                    $data['screening_officer'],
                    $data['screening_date'],
                    $data['approved_by'],
                    $data['date_joined'],
                    $data['date_registered'],
                    $data['monthly_contribution'],
                    $data['beneficiary_name'],
                    $data['beneficiary_address'],
                    $data['beneficiary_relation'],
                    $data['beneficiary_age'],
                    $data['beneficiary_contact'],
                    $data['medical_certificate'],
                    $data['birth_certificate'],
                    $data['registration_photo'],
                    $data['username'],
                    $hashed_password,
                    'viewer',
                    'pending'
                ];
                
                $insert_sql = "INSERT INTO pending_users (" . implode(', ', $fields) . ") 
                               VALUES (" . implode(', ', $placeholders) . ")";
                
                // Build type string
                $insert_types = '';
                foreach ($insert_params as $value) {
                    if (is_int($value)) {
                        $insert_types .= 'i';
                    } elseif (is_float($value)) {
                        $insert_types .= 'd';
                    } else {
                        $insert_types .= 's';
                    }
                }
                
                $result = $db->execute($insert_sql, $insert_params, $insert_types);
                
                if ($result) {
                    // Clear the temporary photo from session
                    if (isset($_SESSION['temp_registration_photo'])) {
                        unset($_SESSION['temp_registration_photo']);
                    }
                    
                    $success = 'Your membership application has been submitted successfully! Please wait for admin approval. You will be notified once your account is approved.';
                    $form_data = []; // Clear form
                    
                    // Log the registration
                    Security::logEvent('REGISTRATION_SUBMITTED', "New registration: " . $data['username']);
                } else {
                    $error = 'Registration failed. Please try again.';
                    $form_data = $_POST;
                }
            }
        }
    }
}

// Generate a member code for display
$generated_member_code = date('Y') . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);

$csrf_token = Security::generateCSRFToken();

// Debug endpoint
if (isset($_GET['debug'])) {
    echo "<h2>Debug Information</h2>";
    $db = Database::getInstance();
    $pending = $db->getAll("SELECT * FROM pending_users ORDER BY id DESC LIMIT 5");
    echo "<pre>";
    print_r($pending);
    echo "</pre>";
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Membership Application - Harana Financial System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            padding: 20px 0;
        }
        
        .application-container {
            max-width: 1200px;
            margin: 0 auto;
            background: white;
            border-radius: 15px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.2);
            overflow: hidden;
        }
        
        .header {
            background: linear-gradient(45deg, #4e73df, #224abe);
            color: white;
            padding: 30px;
            text-align: center;
        }
        
        .header h1 {
            font-size: 2rem;
            font-weight: bold;
            margin-bottom: 10px;
        }
        
        .form-section {
            padding: 30px;
        }
        
        .section-title {
            background: #e9ecef;
            padding: 10px 15px;
            margin: 20px 0 15px 0;
            border-left: 5px solid #4e73df;
            font-weight: bold;
            color: #495057;
        }
        
        .section-title:first-of-type {
            margin-top: 0;
        }
        
        .required-field::after {
            content: " *";
            color: red;
        }
        
        .form-control, .form-select {
            padding: 10px;
            border: 1px solid #dee2e6;
            border-radius: 5px;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: #4e73df;
            box-shadow: 0 0 0 0.2rem rgba(78, 115, 223, 0.25);
        }
        
        .btn-submit {
            background: linear-gradient(45deg, #4e73df, #224abe);
            color: white;
            padding: 15px 40px;
            font-size: 1.2rem;
            font-weight: bold;
            border: none;
            border-radius: 50px;
            cursor: pointer;
            transition: all 0.3s;
            width: 100%;
        }
        
        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.2);
        }
        
        .error-message {
            background: #f8d7da;
            color: #721c24;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .success-message {
            background: #d4edda;
            color: #155724;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .footer {
            background: #f8f9fa;
            padding: 15px;
            text-align: center;
            color: #6c757d;
            font-size: 0.9rem;
            border-top: 1px solid #dee2e6;
        }
        
        .privacy-badge {
            background: #28a745;
            color: white;
            padding: 5px 15px;
            border-radius: 20px;
            font-size: 0.85rem;
            display: inline-block;
            margin-bottom: 15px;
        }
        
        @media (max-width: 768px) {
            .application-container {
                margin: 10px;
            }
            
            .header h1 {
                font-size: 1.5rem;
            }
        }
        
        .city-select {
            width: 100%;
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
        
        .small-text {
            font-size: 0.85rem;
            color: #6c757d;
        }
        
        .logo-section {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 30px;
            padding: 20px;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            border-radius: 10px;
            margin-bottom: 20px;
        }
        
        .logo-left {
            display: flex;
            align-items: center;
            gap: 20px;
            flex: 1;
        }
        
        .logo-section img {
            width: 100px;
            height: 100px;
            object-fit: contain;
        }
        
        .logo-placeholder {
            width: 100px;
            height: 100px;
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
            font-size: 20px;
        }
        
        .logo-section h5 {
            color: #34495e;
            margin-bottom: 3px;
            font-size: 14px;
        }
        
        .logo-section p {
            margin-bottom: 2px;
            color: #7f8c8d;
            font-size: 11px;
        }
        
        .form-title {
            text-align: center;
            margin: 20px 0;
            padding: 10px;
            background: #2c3e50;
            color: white;
            border-radius: 5px;
            font-size: 18px;
        }
        
        .documents-section {
            background: #e8f4f8;
            padding: 10px 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        
        .documents-section h4 {
            margin: 0 0 10px 0;
            color: #2c3e50;
            font-size: 14px;
        }
        
        .btn-fill {
            background-color: #6c757d;
            color: white;
            border: none;
            padding: 5px 15px;
            border-radius: 5px;
            font-size: 13px;
            cursor: pointer;
            margin-left: 10px;
        }
        .btn-fill:hover {
            background-color: #5a6268;
        }
        
        /* Photo Upload Section - Clean design without white wrapper */
        .photo-upload-section {
            text-align: center;
            min-width: 160px;
        }
        
        .registration-photo {
            width: 130px;
            height: 130px;
            background: #f8f9fa;
            border: 2px dashed #dee2e6;
            border-radius: 12px;
            overflow: hidden;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .registration-photo:hover {
            border-color: #4e73df;
            background: #f8f9fc;
        }
        
        .registration-photo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .photo-placeholder {
            text-align: center;
            color: #adb5bd;
        }
        
        .photo-placeholder i {
            font-size: 2.5rem;
            margin-bottom: 5px;
            display: block;
        }
        
        .photo-placeholder p {
            font-size: 0.65rem;
            margin: 0;
        }
        
        .photo-upload-btn {
            margin-top: 8px;
            padding: 4px 10px;
            background: #4e73df;
            color: white;
            border: none;
            border-radius: 4px;
            font-size: 0.7rem;
            cursor: pointer;
            transition: all 0.3s;
            width: 100%;
        }
        
        .photo-upload-btn:hover {
            background: #224abe;
        }
        
        .photo-requirements {
            font-size: 0.6rem;
            color: #6c757d;
            margin-top: 6px;
            text-align: center;
            line-height: 1.3;
        }
        
        .photo-requirements i {
            margin-right: 2px;
        }
        
        .photo-requirements .valid {
            color: #28a745;
        }
        
        .photo-requirements .invalid {
            color: #dc3545;
        }
        
        #photoUploadProgress {
            margin-top: 8px;
            display: none;
        }
        
        #photoUploadProgress .progress {
            height: 3px;
        }
        
        @media (max-width: 768px) {
            .logo-section {
                flex-direction: column;
                align-items: stretch;
            }
            
            .photo-upload-section {
                margin-top: 15px;
            }
            
            .registration-photo {
                width: 120px;
                height: 120px;
            }
        }
    </style>
</head>
<body>
    <div class="application-container">
        <div class="header">
            <h1><i class="fas fa-hand-holding-heart me-2"></i>Harana Financial System</h1>
            <p class="mb-0">Membership Application Form</p>
        </div>
        
        <div class="form-section">
            <div class="privacy-badge">
                <i class="fas fa-shield-alt me-2"></i>Your information is secure and confidential
            </div>
            
            <!-- Logo and Photo Section -->
            <div class="logo-section">
                <div class="logo-left">
                    <img src="assets/images/harana-logo.png" alt="Harana Logo" onerror="this.style.display='none'; this.parentNode.querySelector('.logo-placeholder').style.display='flex';">
                    <div class="logo-placeholder" style="display: none;">
                        <i class="fas fa-hand-holding-heart fa-3x"></i>
                    </div>
                    <div class="text-content">
                        <h2>NAGKAISANG HARANISTA</h2>
                        <h5>SA GINTONG LUZON, PHILS. INC. (NHGL, INC.)</h5>
                        <p class="small">(Formerly Nagkaisang Haranista Sa Gintong Luzon, Inc.)</p>
                        <p class="small">(Sec. REG No. CN 700172104)</p>
                        <p class="small">MF 2024<br>Bryg. Singalat, Palayan City<br>Province of Nueva Ecija<br>Tel. No. (044)940-6708</p>
                    </div>
                </div>
                
                <!-- Photo Upload Section - Clean design -->
                <div class="photo-upload-section">
                    <div class="registration-photo" id="registrationPhoto" onclick="document.getElementById('photoInput').click()">
                        <div class="photo-placeholder" id="photoPlaceholder">
                            <i class="fas fa-camera"></i>
                            <p>2x2 ID Picture</p>
                        </div>
                        <img id="photoPreview" style="display: none;">
                    </div>
                    <input type="file" id="photoInput" accept="image/jpeg,image/png,image/gif,image/webp" style="display: none;">
                    <button type="button" class="photo-upload-btn" id="uploadPhotoBtn">
                        <i class="fas fa-upload me-1"></i> Upload
                    </button>
                    <div id="photoUploadProgress" style="display: none;">
                        <div class="progress">
                            <div class="progress-bar progress-bar-striped progress-bar-animated" style="width: 0%"></div>
                        </div>
                    </div>
                    <div class="photo-requirements">
                        <i class="fas fa-info-circle"></i> 2x2 ID • Min 300x300px • Square • JPG/PNG
                    </div>
                    <input type="hidden" name="registration_photo" id="registrationPhotoPath" value="">
                </div>
            </div>

            <h4 class="form-title">APPLICATION FOR MEMBERSHIP</h4>
            
            <?php if ($error): ?>
                <div class="error-message">
                    <i class="fas fa-exclamation-circle me-2"></i><?php echo $error; ?>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="success-message">
                    <i class="fas fa-check-circle me-2"></i><?php echo $success; ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="" id="registrationForm" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                
                <!-- Documents Section - TOP as requested -->
                <div class="documents-section">
                    <h4>Documents Attached:</h4>
                    <div class="checkbox-group">
                        <div class="checkbox-item">
                            <input type="checkbox" name="medical_certificate" id="medical_certificate" value="1"
                                   <?php echo !empty($form_data['medical_certificate']) ? 'checked' : ''; ?>>
                            <label for="medical_certificate">Medical Certificate</label>
                        </div>
                        <div class="checkbox-item">
                            <input type="checkbox" name="birth_certificate" id="birth_certificate" value="1"
                                   <?php echo !empty($form_data['birth_certificate']) ? 'checked' : ''; ?>>
                            <label for="birth_certificate">Birth Certificate</label>
                        </div>
                    </div>
                </div>
                
                <!-- Personal Information -->
                <div class="section-title">I. PERSONAL INFORMATION</div>
                
                <div class="row mb-3">
                    <div class="col-md-3">
                        <label class="form-label">Member Code</label>
                        <input type="text" class="form-control" value="<?php echo $generated_member_code; ?>" readonly disabled>
                        <input type="hidden" name="member_code" value="<?php echo $generated_member_code; ?>">
                        <small class="text-muted">Auto-generated</small>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Date Joined *</label>
                        <input type="date" name="date_joined" class="form-control" value="<?php echo htmlspecialchars($form_data['date_joined'] ?? date('Y-m-d')); ?>" required>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Monthly Contribution *</label>
                        <input type="number" name="monthly_contribution" class="form-control" value="<?php echo htmlspecialchars($form_data['monthly_contribution'] ?? '100.00'); ?>" step="0.01" required>
                    </div>
                    <div class="col-md-3">
                        <!-- Removed Status field -->
                    </div>
                </div>

                <div class="row mb-3">
                    <div class="col-md-4">
                        <label class="form-label required-field">Last Name</label>
                        <input type="text" name="last_name" class="form-control" 
                               value="<?php echo htmlspecialchars($form_data['last_name'] ?? ''); ?>" 
                               maxlength="50" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label required-field">First Name</label>
                        <input type="text" name="first_name" class="form-control" 
                               value="<?php echo htmlspecialchars($form_data['first_name'] ?? ''); ?>"
                               maxlength="50" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Middle Name</label>
                        <input type="text" name="middle_name" class="form-control" 
                               value="<?php echo htmlspecialchars($form_data['middle_name'] ?? ''); ?>"
                               maxlength="50">
                    </div>
                </div>

                <div class="row mb-3">
                    <div class="col-md-3">
                        <label class="form-label">Date of Birth</label>
                        <input type="date" name="birth_date" id="birth_date" class="form-control" 
                               value="<?php echo htmlspecialchars($form_data['birth_date'] ?? ''); ?>"
                               onchange="calculateAge()">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Place of Birth</label>
                        <input type="text" name="place_of_birth" class="form-control" 
                               value="<?php echo htmlspecialchars($form_data['place_of_birth'] ?? ''); ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Age</label>
                        <input type="number" name="age" id="age" class="form-control" 
                               value="<?php echo htmlspecialchars($form_data['age'] ?? ''); ?>" readonly>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Gender</label>
                        <select name="gender" class="form-select">
                            <option value="">Select</option>
                            <option value="Male" <?php echo ($form_data['gender'] ?? '') == 'Male' ? 'selected' : ''; ?>>Male</option>
                            <option value="Female" <?php echo ($form_data['gender'] ?? '') == 'Female' ? 'selected' : ''; ?>>Female</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Civil Status</label>
                        <select name="civil_status" class="form-select">
                            <option value="">Select</option>
                            <option value="Single" <?php echo ($form_data['civil_status'] ?? '') == 'Single' ? 'selected' : ''; ?>>Single</option>
                            <option value="Married" <?php echo ($form_data['civil_status'] ?? '') == 'Married' ? 'selected' : ''; ?>>Married</option>
                            <option value="Widowed" <?php echo ($form_data['civil_status'] ?? '') == 'Widowed' ? 'selected' : ''; ?>>Widowed</option>
                            <option value="Separated" <?php echo ($form_data['civil_status'] ?? '') == 'Separated' ? 'selected' : ''; ?>>Separated</option>
                        </select>
                    </div>
                </div>

                <div class="row mb-3">
                    <div class="col-md-4">
                        <label class="form-label">Religion</label>
                        <input type="text" name="religion" class="form-control" 
                               value="<?php echo htmlspecialchars($form_data['religion'] ?? ''); ?>">
                    </div>
                </div>

                <!-- Address Information -->
                <div class="section-title">II. ADDRESS INFORMATION</div>
                
                <div class="row mb-3">
                    <div class="col-md-3">
                        <label class="form-label">Province</label>
                        <select name="province" id="province" class="form-select" onchange="updateCities()">
                            <option value="Occidental Mindoro" <?php echo ($form_data['province'] ?? '') == 'Occidental Mindoro' ? 'selected' : ''; ?>>Occidental Mindoro</option>
                            <option value="Nueva Ecija" <?php echo ($form_data['province'] ?? '') == 'Nueva Ecija' ? 'selected' : ''; ?>>Nueva Ecija</option>
                            <option value="Pampanga" <?php echo ($form_data['province'] ?? '') == 'Pampanga' ? 'selected' : ''; ?>>Pampanga</option>
                            <option value="Bulacan" <?php echo ($form_data['province'] ?? '') == 'Bulacan' ? 'selected' : ''; ?>>Bulacan</option>
                            <option value="Tarlac" <?php echo ($form_data['province'] ?? '') == 'Tarlac' ? 'selected' : ''; ?>>Tarlac</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">City/Municipality</label>
                        <select name="city" id="city" class="form-select">
                            <option value="">Select City</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Barangay</label>
                        <input type="text" name="barangay" class="form-control" 
                               value="<?php echo htmlspecialchars($form_data['barangay'] ?? ''); ?>"
                               placeholder="Enter Barangay">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Street/Purok/Sitio</label>
                        <input type="text" name="street" class="form-control" 
                               value="<?php echo htmlspecialchars($form_data['street'] ?? ''); ?>"
                               placeholder="Street/Purok/Sitio">
                    </div>
                </div>

                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Present Address (if different from above)</label>
                        <input type="text" name="present_address" class="form-control" 
                               value="<?php echo htmlspecialchars($form_data['present_address'] ?? ''); ?>"
                               placeholder="Complete present address">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Permanent Address</label>
                        <input type="text" name="permanent_address" class="form-control" 
                               value="<?php echo htmlspecialchars($form_data['permanent_address'] ?? ''); ?>"
                               placeholder="Complete permanent address">
                    </div>
                </div>

                <!-- Contact Information -->
                <div class="section-title">III. CONTACT INFORMATION</div>
                
                <div class="row mb-3">
                    <div class="col-md-4">
                        <label class="form-label required-field">Contact Number</label>
                        <input type="text" name="contact_number" class="form-control" 
                               value="<?php echo htmlspecialchars($form_data['contact_number'] ?? ''); ?>"
                               maxlength="20" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Alternate Number</label>
                        <input type="text" name="alternate_number" class="form-control" 
                               value="<?php echo htmlspecialchars($form_data['alternate_number'] ?? ''); ?>"
                               maxlength="20">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label required-field">Email Address</label>
                        <input type="email" name="email" class="form-control" 
                               value="<?php echo htmlspecialchars($form_data['email'] ?? ''); ?>"
                               maxlength="100" required>
                    </div>
                </div>

                <!-- Family Information -->
                <div class="section-title">IV. FAMILY BACKGROUND</div>
                
                <div class="row mb-3">
                    <div class="col-md-12">
                        <label class="form-label">Father's Full Name:</label>
                    </div>
                    <div class="col-md-4">
                        <input type="text" name="father_fname" class="form-control" 
                               placeholder="First Name"
                               value="<?php echo htmlspecialchars($form_data['father_fname'] ?? ''); ?>">
                    </div>
                    <div class="col-md-4">
                        <input type="text" name="father_mname" class="form-control" 
                               placeholder="Middle Name"
                               value="<?php echo htmlspecialchars($form_data['father_mname'] ?? ''); ?>">
                    </div>
                    <div class="col-md-4">
                        <input type="text" name="father_lname" class="form-control" 
                               placeholder="Last Name"
                               value="<?php echo htmlspecialchars($form_data['father_lname'] ?? ''); ?>">
                    </div>
                </div>

                <div class="row mb-3">
                    <div class="col-md-12">
                        <label class="form-label">Mother's Full Name (Maiden Name):</label>
                    </div>
                    <div class="col-md-4">
                        <input type="text" name="mother_fname" class="form-control" 
                               placeholder="First Name"
                               value="<?php echo htmlspecialchars($form_data['mother_fname'] ?? ''); ?>">
                    </div>
                    <div class="col-md-4">
                        <input type="text" name="mother_mname" class="form-control" 
                               placeholder="Middle Name"
                               value="<?php echo htmlspecialchars($form_data['mother_mname'] ?? ''); ?>">
                    </div>
                    <div class="col-md-4">
                        <input type="text" name="mother_lname" class="form-control" 
                               placeholder="Last Name"
                               value="<?php echo htmlspecialchars($form_data['mother_lname'] ?? ''); ?>">
                    </div>
                </div>

                <div class="row mb-3">
                    <div class="col-md-12">
                        <label class="form-label">Spouse's Full Name (If Married):</label>
                    </div>
                    <div class="col-md-3">
                        <input type="text" name="spouse_fname" class="form-control" 
                               placeholder="First Name"
                               value="<?php echo htmlspecialchars($form_data['spouse_fname'] ?? ''); ?>">
                    </div>
                    <div class="col-md-3">
                        <input type="text" name="spouse_mname" class="form-control" 
                               placeholder="Middle Name"
                               value="<?php echo htmlspecialchars($form_data['spouse_mname'] ?? ''); ?>">
                    </div>
                    <div class="col-md-3">
                        <input type="text" name="spouse_lname" class="form-control" 
                               placeholder="Last Name"
                               value="<?php echo htmlspecialchars($form_data['spouse_lname'] ?? ''); ?>">
                    </div>
                    <div class="col-md-3">
                        <input type="number" name="spouse_age" class="form-control" 
                               placeholder="Age"
                               value="<?php echo htmlspecialchars($form_data['spouse_age'] ?? ''); ?>">
                    </div>
                </div>

                <!-- Children -->
                <div class="section-title">V. CHILDREN (List all children below 18 years old)</div>
                <p class="small-text mb-2">List all children (including those below 18 years old)</p>
                
                <?php for ($i = 1; $i <= 4; $i++): ?>
                <div class="row mb-2">
                    <div class="col-md-1"><label class="form-label"><?php echo $i; ?>.</label></div>
                    <div class="col-md-3">
                        <input type="text" name="child<?php echo $i; ?>_fname" class="form-control" 
                               placeholder="First Name"
                               value="<?php echo htmlspecialchars($form_data["child{$i}_fname"] ?? ''); ?>">
                    </div>
                    <div class="col-md-3">
                        <input type="text" name="child<?php echo $i; ?>_mname" class="form-control" 
                               placeholder="Middle Name"
                               value="<?php echo htmlspecialchars($form_data["child{$i}_mname"] ?? ''); ?>">
                    </div>
                    <div class="col-md-3">
                        <input type="text" name="child<?php echo $i; ?>_lname" class="form-control" 
                               placeholder="Last Name"
                               value="<?php echo htmlspecialchars($form_data["child{$i}_lname"] ?? ''); ?>">
                    </div>
                    <div class="col-md-2">
                        <input type="number" name="child<?php echo $i; ?>_age" class="form-control" 
                               placeholder="Age"
                               value="<?php echo htmlspecialchars($form_data["child{$i}_age"] ?? ''); ?>">
                    </div>
                </div>
                <?php endfor; ?>

                <!-- References -->
                <div class="section-title">VI. CHARACTER REFERENCES</div>
                
                <div class="row mb-2">
                    <div class="col-md-5">
                        <label class="form-label">Name of Reference 1</label>
                        <input type="text" name="ref1_name" class="form-control" 
                               value="<?php echo htmlspecialchars($form_data['ref1_name'] ?? ''); ?>">
                    </div>
                    <div class="col-md-7">
                        <label class="form-label">Contact Number</label>
                        <input type="text" name="ref1_contact" class="form-control" 
                               value="<?php echo htmlspecialchars($form_data['ref1_contact'] ?? ''); ?>">
                    </div>
                </div>

                <div class="row mb-3">
                    <div class="col-md-5">
                        <label class="form-label">Name of Reference 2</label>
                        <input type="text" name="ref2_name" class="form-control" 
                               value="<?php echo htmlspecialchars($form_data['ref2_name'] ?? ''); ?>">
                    </div>
                    <div class="col-md-7">
                        <label class="form-label">Contact Number</label>
                        <input type="text" name="ref2_contact" class="form-control" 
                               value="<?php echo htmlspecialchars($form_data['ref2_contact'] ?? ''); ?>">
                    </div>
                </div>

                <!-- Beneficiary -->
                <div class="section-title">VII. BENEFICIARY INFORMATION</div>
                
                <div class="row mb-2">
                    <div class="col-md-4">
                        <label class="form-label required-field">Full Name of Beneficiary</label>
                        <input type="text" name="beneficiary_name" class="form-control" 
                               value="<?php echo htmlspecialchars($form_data['beneficiary_name'] ?? ''); ?>" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label required-field">Complete Address</label>
                        <input type="text" name="beneficiary_address" class="form-control" 
                               value="<?php echo htmlspecialchars($form_data['beneficiary_address'] ?? ''); ?>" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label required-field">Relationship</label>
                        <input type="text" name="beneficiary_relation" class="form-control" 
                               value="<?php echo htmlspecialchars($form_data['beneficiary_relation'] ?? ''); ?>" required>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Age</label>
                        <input type="number" name="beneficiary_age" class="form-control" 
                               value="<?php echo htmlspecialchars($form_data['beneficiary_age'] ?? ''); ?>">
                    </div>
                </div>

                <div class="row mb-3">
                    <div class="col-md-4">
                        <label class="form-label">Contact Number of Beneficiary</label>
                        <input type="text" name="beneficiary_contact" class="form-control" 
                               value="<?php echo htmlspecialchars($form_data['beneficiary_contact'] ?? ''); ?>">
                    </div>
                </div>

                <!-- Chapter Information -->
                <div class="section-title">VIII. CHAPTER INFORMATION</div>
                
                <div class="row mb-2">
                    <div class="col-md-3">
                        <label class="form-label required-field">Chapter</label>
                        <select name="chapter" class="form-select" required>
                            <option value="">Select Chapter</option>
                            <?php foreach ($chapters as $chapter): ?>
                                <option value="<?php echo htmlspecialchars($chapter); ?>" 
                                    <?php echo ($form_data['chapter'] ?? '') == $chapter ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($chapter); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Group Name</label>
                        <input type="text" name="group_name" class="form-control" 
                               value="<?php echo htmlspecialchars($form_data['group_name'] ?? ''); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Leader</label>
                        <input type="text" name="leader" class="form-control" 
                               value="<?php echo htmlspecialchars($form_data['leader'] ?? ''); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Coordinator</label>
                        <input type="text" name="coordinator" class="form-control" 
                               value="<?php echo htmlspecialchars($form_data['coordinator'] ?? ''); ?>">
                    </div>
                </div>

                <div class="row mb-2">
                    <div class="col-md-3">
                        <label class="form-label">Chairman</label>
                        <input type="text" name="chairman" class="form-control" 
                               value="<?php echo htmlspecialchars($form_data['chairman'] ?? ''); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Screening Officer</label>
                        <input type="text" name="screening_officer" class="form-control" 
                               value="<?php echo htmlspecialchars($form_data['screening_officer'] ?? ''); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Screening Date</label>
                        <input type="date" name="screening_date" class="form-control" 
                               value="<?php echo htmlspecialchars($form_data['screening_date'] ?? ''); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Approved By</label>
                        <input type="text" name="approved_by" class="form-control" 
                               value="<?php echo htmlspecialchars($form_data['approved_by'] ?? ''); ?>">
                    </div>
                </div>

                <div class="row mb-2">
                    <div class="col-md-3">
                        <label class="form-label">Date Registered</label>
                        <input type="date" name="date_registered" class="form-control" 
                               value="<?php echo htmlspecialchars($form_data['date_registered'] ?? date('Y-m-d')); ?>">
                    </div>
                </div>

                <!-- Account Information -->
                <div class="section-title">IX. ACCOUNT INFORMATION</div>

                <div class="row mb-3">
                    <div class="col-md-4">
                        <label class="form-label required-field">Username</label>
                        <input type="text" name="username" class="form-control" 
                               value="<?php echo htmlspecialchars($form_data['username'] ?? ''); ?>"
                               maxlength="50" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label required-field">Password</label>
                        <input type="password" name="password" class="form-control" 
                               minlength="6" required>
                        <small class="text-muted">Minimum 6 characters</small>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label required-field">Confirm Password</label>
                        <input type="password" name="confirm_password" class="form-control" required>
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

                <!-- Submit Button -->
                <div class="mt-4">
                    <button type="submit" class="btn-submit">
                        <i class="fas fa-paper-plane me-2"></i>Submit Application
                    </button>
                </div>

                <div class="text-center mt-3">
                    <p>Already have an account? <a href="index.php" style="color: #667eea;">Login here</a></p>
                </div>
            </form>
        </div>

        <div class="footer">
            <p class="mb-0">© <?php echo date('Y'); ?> Harana Financial System. All rights reserved.</p>
        </div>
    </div>

    <script>
        // City data
        const citiesByProvince = <?php echo json_encode($cities_data); ?>;

        // Update cities based on selected province
        function updateCities() {
            const province = document.getElementById('province').value;
            const citySelect = document.getElementById('city');
            
            citySelect.innerHTML = '<option value="">Select City</option>';
            
            if (province && citiesByProvince[province]) {
                citiesByProvince[province].forEach(city => {
                    const option = document.createElement('option');
                    option.value = city;
                    option.textContent = city;
                    citySelect.appendChild(option);
                });
            }
            
            <?php if (!empty($form_data['city'])): ?>
            citySelect.value = '<?php echo htmlspecialchars($form_data['city']); ?>';
            <?php endif; ?>
        }

        // Calculate age from birth date
        function calculateAge() {
            const birthDate = document.getElementById('birth_date').value;
            if (birthDate) {
                const today = new Date();
                const birth = new Date(birthDate);
                let age = today.getFullYear() - birth.getFullYear();
                const monthDiff = today.getMonth() - birth.getMonth();
                
                if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < birth.getDate())) {
                    age--;
                }
                
                document.getElementById('age').value = age >= 0 ? age : '';
            }
        }

        // Fill sample data for testing
        function fillSampleData() {
            // Set form fields
            document.querySelector('[name="first_name"]').value = 'Juan';
            document.querySelector('[name="last_name"]').value = 'Dela Cruz';
            document.querySelector('[name="middle_name"]').value = 'Santos';
            
            // Set province and trigger city update
            const provinceSelect = document.querySelector('[name="province"]');
            provinceSelect.value = 'Nueva Ecija';
            updateCities();
            
            // Wait a bit for cities to populate then set city
            setTimeout(() => {
                const citySelect = document.querySelector('[name="city"]');
                citySelect.value = 'Palayan City';
            }, 100);
            
            document.querySelector('[name="barangay"]').value = 'Singalat';
            document.querySelector('[name="street"]').value = 'Purok 3';
            
            document.querySelector('[name="contact_number"]').value = '09123456789';
            document.querySelector('[name="alternate_number"]').value = '09987654321';
            document.querySelector('[name="email"]').value = 'juan.delacruz@example.com';
            
            document.querySelector('[name="birth_date"]').value = '1990-01-15';
            calculateAge();
            
            document.querySelector('[name="place_of_birth"]').value = 'Manila';
            
            document.querySelector('[name="gender"]').value = 'Male';
            document.querySelector('[name="civil_status"]').value = 'Married';
            document.querySelector('[name="religion"]').value = 'Roman Catholic';
            
            // Family
            document.querySelector('[name="father_fname"]').value = 'Pedro';
            document.querySelector('[name="father_mname"]').value = 'D';
            document.querySelector('[name="father_lname"]').value = 'Dela Cruz';
            
            document.querySelector('[name="mother_fname"]').value = 'Maria';
            document.querySelector('[name="mother_mname"]').value = 'S';
            document.querySelector('[name="mother_lname"]').value = 'Santos';
            
            document.querySelector('[name="spouse_fname"]').value = 'Juana';
            document.querySelector('[name="spouse_mname"]').value = 'R';
            document.querySelector('[name="spouse_lname"]').value = 'Dela Cruz';
            document.querySelector('[name="spouse_age"]').value = '30';
            
            // Children
            document.querySelector('[name="child1_fname"]').value = 'Jose';
            document.querySelector('[name="child1_mname"]').value = 'J';
            document.querySelector('[name="child1_lname"]').value = 'Dela Cruz';
            document.querySelector('[name="child1_age"]').value = '5';
            
            document.querySelector('[name="child2_fname"]').value = 'Maria';
            document.querySelector('[name="child2_mname"]').value = 'J';
            document.querySelector('[name="child2_lname"]').value = 'Dela Cruz';
            document.querySelector('[name="child2_age"]').value = '3';
            
            // References
            document.querySelector('[name="ref1_name"]').value = 'Jose Rizal';
            document.querySelector('[name="ref1_contact"]').value = '09221112233';
            document.querySelector('[name="ref2_name"]').value = 'Andres Bonifacio';
            document.querySelector('[name="ref2_contact"]').value = '09332223344';
            
            // Beneficiary
            document.querySelector('[name="beneficiary_name"]').value = 'Juana Dela Cruz';
            document.querySelector('[name="beneficiary_address"]').value = 'Palayan City';
            document.querySelector('[name="beneficiary_relation"]').value = 'Spouse';
            document.querySelector('[name="beneficiary_age"]').value = '30';
            document.querySelector('[name="beneficiary_contact"]').value = '09123456789';
            
            // Chapter
            document.querySelector('[name="chapter"]').value = 'GUIMBA';
            document.querySelector('[name="group_name"]').value = 'Group A';
            document.querySelector('[name="leader"]').value = 'John Doe';
            document.querySelector('[name="coordinator"]').value = 'Jane Smith';
            document.querySelector('[name="chairman"]').value = 'Mike Johnson';
            document.querySelector('[name="screening_officer"]').value = 'Sarah Wilson';
            document.querySelector('[name="screening_date"]').value = '2026-02-28';
            document.querySelector('[name="approved_by"]').value = 'Admin';
            document.querySelector('[name="date_joined"]').value = '2026-02-28';
            
            // Account Info
            document.querySelector('[name="username"]').value = 'juan_delacruz';
            document.querySelector('[name="password"]').value = 'password123';
            document.querySelector('[name="confirm_password"]').value = 'password123';
            
            // Documents
            document.querySelector('[name="medical_certificate"]').checked = true;
            document.querySelector('[name="birth_certificate"]').checked = true;
            
            alert('Sample data filled!');
        }

        // Initialize on page load
        document.addEventListener('DOMContentLoaded', function() {
            updateCities();
            
            <?php if (!empty($form_data['birth_date'])): ?>
            calculateAge();
            <?php endif; ?>
            
            // Handle logo placeholder
            const logo = document.querySelector('.logo-section img');
            if (logo && logo.complete && logo.naturalWidth === 0) {
                logo.style.display = 'none';
                const placeholder = logo.parentNode.querySelector('.logo-placeholder');
                if (placeholder) placeholder.style.display = 'flex';
            }
        });
        
        // Photo upload functionality
        const photoInput = document.getElementById('photoInput');
        const registrationPhoto = document.getElementById('registrationPhoto');
        const photoPreview = document.getElementById('photoPreview');
        const photoPlaceholder = document.getElementById('photoPlaceholder');
        const uploadPhotoBtn = document.getElementById('uploadPhotoBtn');
        const photoUploadProgress = document.getElementById('photoUploadProgress');
        const registrationPhotoPath = document.getElementById('registrationPhotoPath');

        let selectedFile = null;

        // Preview image when selected
        photoInput.addEventListener('change', function() {
            const file = this.files[0];
            if (file) {
                // Validate on client side first
                const validTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                if (!validTypes.includes(file.type)) {
                    alert('Invalid file type. Please select a JPG, PNG, GIF, or WEBP image.');
                    this.value = '';
                    return;
                }
                
                // Check file size (10MB)
                if (file.size > 10 * 1024 * 1024) {
                    alert('File size must be less than 10MB.');
                    this.value = '';
                    return;
                }
                
                // Preview image
                const reader = new FileReader();
                reader.onload = function(e) {
                    // Create temporary image to check dimensions
                    const img = new Image();
                    img.onload = function() {
                        const width = this.width;
                        const height = this.height;
                        
                        // Check minimum resolution
                        if (width < 300 || height < 300) {
                            alert('Image resolution too low. Minimum 300x300 pixels required.');
                            photoInput.value = '';
                            return;
                        }
                        
                        // Check square ratio (allow 0.9 to 1.1 tolerance)
                        const ratio = width / height;
                        if (ratio < 0.9 || ratio > 1.1) {
                            alert('Image must be square (1:1 ratio). Please upload a 2x2 ID picture.');
                            photoInput.value = '';
                            return;
                        }
                        
                        // All validations passed
                        photoPreview.src = e.target.result;
                        photoPreview.style.display = 'block';
                        photoPlaceholder.style.display = 'none';
                        selectedFile = file;
                    };
                    img.src = e.target.result;
                };
                reader.readAsDataURL(file);
            }
        });

        // Upload photo button click
        uploadPhotoBtn.addEventListener('click', function() {
            if (!selectedFile) {
                alert('Please select a photo first.');
                return;
            }
            
            const formData = new FormData();
            formData.append('csrf_token', document.querySelector('[name="csrf_token"]').value);
            formData.append('registration_photo', selectedFile);
            
            photoUploadProgress.style.display = 'block';
            let progress = 0;
            const interval = setInterval(function() {
                progress += 10;
                photoUploadProgress.querySelector('.progress-bar').style.width = progress + '%';
                if (progress >= 100) clearInterval(interval);
            }, 100);
            
            fetch('upload_registration_photo.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                clearInterval(interval);
                photoUploadProgress.querySelector('.progress-bar').style.width = '100%';
                
                setTimeout(() => {
                    photoUploadProgress.style.display = 'none';
                    
                    if (data.success) {
                        registrationPhotoPath.value = data.photo_preview;
                        alert('Photo uploaded successfully!');
                    } else {
                        alert(data.message);
                        // Reset if upload failed
                        photoPreview.style.display = 'none';
                        photoPlaceholder.style.display = 'block';
                        selectedFile = null;
                        registrationPhotoPath.value = '';
                    }
                }, 500);
            })
            .catch(error => {
                clearInterval(interval);
                photoUploadProgress.style.display = 'none';
                alert('Upload failed: ' + error.message);
            });
        });
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>