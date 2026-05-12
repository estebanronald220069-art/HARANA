<?php
// user/beneficiary.php
require_once '../includes/config.php';
require_once '../includes/db_connection.php';
require_once '../includes/security.php';
require_once '../includes/auth.php';
require_once '../includes/user_functions.php';

$auth->requireLogin();
$current_user = $auth->getCurrentUser();

$db = Database::getInstance();
$member = getUserMemberData($db, $current_user);

if (!$member || empty($member['member_code'])) {
    header('Location: dashboard.php?error=member_not_found');
    exit();
}

$member_code = $member['member_code'];
$message = '';
$error = '';

// Calculate membership duration in years and months
$date_joined = $member['date_joined'] ?? date('Y-m-d');
$join_date = new DateTime($date_joined);
$today = new DateTime();
$years_as_member = $join_date->diff($today)->y;
$months_as_member = ($years_as_member * 12) + $join_date->diff($today)->m;

// Calculate death benefits based on years as member
function calculateDeathBenefit($years) {
    $benefit_table = [
        1 => 80000, 2 => 80000,
        3 => 90000, 4 => 90000,
        5 => 100000, 6 => 100000,
        7 => 110000, 8 => 110000,
        9 => 115000,
        10 => 120000, 11 => 125000, 12 => 130000,
        13 => 135000, 14 => 140000, 15 => 145000,
        16 => 150000
    ];
    
    if ($years >= 16) return 150000;
    if ($years >= 1 && $years <= 16) return $benefit_table[$years] ?? 80000;
    return 0;
}

$base_benefit = calculateDeathBenefit($years_as_member);
$accident_bonus = 10000;  // Additional ₱10,000 for accidental death
$good_payer_bonus = 5000; // Additional ₱5,000 for good payer (5+ years no missed payments)
$total_benefit = $base_benefit + $accident_bonus + $good_payer_bonus;

// Get pending beneficiary request
$pending_request = $db->getSingle(
    "SELECT * FROM beneficiary_requests 
     WHERE member_code = ? AND status = 'pending' 
     ORDER BY created_at DESC LIMIT 1",
    [$member_code], 's'
);

// Get beneficiary history
$beneficiary_history = $db->getAll(
    "SELECT * FROM beneficiary_history 
     WHERE member_code = ? 
     ORDER BY changed_at DESC LIMIT 10",
    [$member_code], 's'
);

// Handle beneficiary update request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_beneficiary'])) {
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token';
    } else {
        $beneficiary_name = Security::sanitize($_POST['beneficiary_name'] ?? '');
        $beneficiary_address = Security::sanitize($_POST['beneficiary_address'] ?? '');
        $beneficiary_relation = Security::sanitize($_POST['beneficiary_relation'] ?? '');
        $beneficiary_age = !empty($_POST['beneficiary_age']) ? intval($_POST['beneficiary_age']) : null;
        $beneficiary_contact = Security::sanitize($_POST['beneficiary_contact'] ?? '');
        
        if (empty($beneficiary_name) || empty($beneficiary_address) || empty($beneficiary_relation)) {
            $error = 'Please fill in all required fields (Name, Address, Relationship)';
        } else {
            // Check if there's already a pending request
            if ($pending_request) {
                $error = 'You already have a pending beneficiary update request. Please wait for approval.';
            } else {
                // Handle file uploads
                $uploaded_files = [];
                $upload_dir = '../uploads/beneficiary/';
                if (!file_exists($upload_dir)) {
                    mkdir($upload_dir, 0777, true);
                }
                
                if (!empty($_FILES['documents']['name'][0])) {
                    $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf'];
                    $max_size = 5 * 1024 * 1024; // 5MB
                    
                    foreach ($_FILES['documents']['tmp_name'] as $key => $tmp_name) {
                        if ($_FILES['documents']['error'][$key] === UPLOAD_ERR_OK) {
                            $file_name = $_FILES['documents']['name'][$key];
                            $file_type = $_FILES['documents']['type'][$key];
                            $file_size = $_FILES['documents']['size'][$key];
                            
                            if (!in_array($file_type, $allowed_types)) {
                                $error = 'Invalid file type. Allowed: JPG, PNG, GIF, PDF';
                                break;
                            }
                            
                            if ($file_size > $max_size) {
                                $error = 'File size must be less than 5MB';
                                break;
                            }
                            
                            $new_filename = $member_code . '_' . time() . '_' . $key . '_' . preg_replace('/[^a-zA-Z0-9\.]/', '_', $file_name);
                            $file_path = $upload_dir . $new_filename;
                            
                            if (move_uploaded_file($tmp_name, $file_path)) {
                                $uploaded_files[] = $new_filename;
                            }
                        }
                    }
                }
                
                if (empty($error)) {
                    $documents_json = !empty($uploaded_files) ? json_encode($uploaded_files) : null;
                    
                    $result = $db->execute(
                        "INSERT INTO beneficiary_requests 
                         (member_code, beneficiary_name, beneficiary_address, beneficiary_relation, 
                          beneficiary_age, beneficiary_contact, documents, status, created_at) 
                         VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', NOW())",
                        [$member_code, $beneficiary_name, $beneficiary_address, $beneficiary_relation,
                         $beneficiary_age, $beneficiary_contact, $documents_json],
                        'sssssss'
                    );
                    
                    if ($result) {
                        $message = 'Beneficiary update request submitted successfully! It will be reviewed within 3-5 business days.';
                        $pending_request = $db->getSingle(
                            "SELECT * FROM beneficiary_requests WHERE member_code = ? AND status = 'pending' ORDER BY created_at DESC LIMIT 1",
                            [$member_code], 's'
                        );
                    } else {
                        $error = 'Failed to submit request. Please try again.';
                    }
                }
            }
        }
    }
}

$csrf_token = Security::generateCSRFToken();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Beneficiary - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #375a7f;
            --secondary-color: #2c4a6b;
            --success-color: #28a745;
            --warning-color: #ffc107;
            --danger-color: #dc3545;
            --info-color: #17a2b8;
        }

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
            transition: all 0.2s;
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
            transition: all 0.3s ease;
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
            transition: all 0.3s ease;
        }

        #sidebar-wrapper.collapsed .list-group-item i {
            margin-right: 0;
            width: 100%;
            font-size: 1.2rem;
        }

        #page-content-wrapper {
            flex: 1;
            background: #f4f7fc;
            height: 100vh;
            overflow-y: auto;
            padding: 0;
        }

        /* Navbar */
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

        /* Container */
        .beneficiary-container {
            padding: 20px;
            max-width: 1400px;
            margin: 0 auto;
        }

        /* Grid Layout */
        .beneficiary-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
        }

        @media (max-width: 992px) {
            .beneficiary-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Cards */
        .beneficiary-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            overflow: hidden;
            margin-bottom: 20px;
        }

        .beneficiary-card-header {
            padding: 15px 20px;
            background: #f8f9fa;
            border-bottom: 1px solid #e9ecef;
        }

        .beneficiary-card-header h5 {
            margin: 0;
            font-weight: 600;
            color: #2c3e50;
            font-size: 1rem;
        }

        .beneficiary-card-header i {
            margin-right: 8px;
            color: #375a7f;
        }

        .beneficiary-card-body {
            padding: 20px;
        }

        /* Current Beneficiary Display */
        .beneficiary-display {
            background: linear-gradient(135deg, #dc3545, #c82333);
            border-radius: 12px;
            padding: 25px;
            color: white;
            text-align: center;
        }

        .beneficiary-avatar {
            width: 100px;
            height: 100px;
            background: rgba(255,255,255,0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            font-size: 3rem;
        }

        .beneficiary-name {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .beneficiary-detail {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
            flex-wrap: wrap;
            margin-top: 15px;
            font-size: 0.85rem;
        }

        .beneficiary-detail span {
            background: rgba(255,255,255,0.2);
            padding: 5px 12px;
            border-radius: 20px;
        }

        .beneficiary-detail i {
            margin-right: 5px;
        }

        /* Benefit Calculator */
        .benefit-calculator {
            background: linear-gradient(135deg, #28a745, #20c997);
            border-radius: 12px;
            padding: 20px;
            color: white;
            margin-bottom: 20px;
        }

        .benefit-amount {
            font-size: 2rem;
            font-weight: 700;
            margin: 10px 0;
        }

        .benefit-breakdown {
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid rgba(255,255,255,0.2);
        }

        .benefit-breakdown-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 0.85rem;
        }

        /* Rules & Requirements */
        .rules-list {
            list-style: none;
            padding: 0;
        }

        .rules-list li {
            padding: 8px 0;
            border-bottom: 1px solid #e9ecef;
            display: flex;
            align-items: flex-start;
            gap: 10px;
        }

        .rules-list li i {
            color: #dc3545;
            margin-top: 3px;
        }

        /* Status Badge */
        .status-pending {
            background: #fff3cd;
            color: #856404;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .status-approved {
            background: #d4edda;
            color: #155724;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .status-rejected {
            background: #f8d7da;
            color: #721c24;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }

        /* History Timeline */
        .history-timeline {
            position: relative;
            padding-left: 20px;
        }

        .history-item {
            position: relative;
            padding-bottom: 20px;
            border-left: 2px solid #e9ecef;
            padding-left: 20px;
            margin-left: 10px;
        }

        .history-item::before {
            content: '';
            position: absolute;
            left: -7px;
            top: 0;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: #375a7f;
        }

        .history-date {
            font-size: 0.7rem;
            color: #6c757d;
            margin-bottom: 5px;
        }

        .history-text {
            font-size: 0.85rem;
        }

        /* Form Styles */
        .form-control, .form-select {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 10px 12px;
            font-size: 0.9rem;
        }

        .form-control:focus, .form-select:focus {
            border-color: #375a7f;
            box-shadow: 0 0 0 0.2rem rgba(55,90,127,0.1);
        }

        .btn-submit {
            background: #375a7f;
            color: white;
            border: none;
            padding: 12px;
            border-radius: 8px;
            font-weight: 600;
            width: 100%;
            transition: all 0.3s;
        }

        .btn-submit:hover {
            background: #2c4a6b;
            transform: translateY(-2px);
        }

        .btn-submit:disabled {
            background: #6c757d;
            cursor: not-allowed;
        }

        /* Responsive */
        @media (max-width: 768px) {
            .beneficiary-detail {
                flex-direction: column;
                gap: 8px;
            }
            
            .benefit-amount {
                font-size: 1.5rem;
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
                <a href="dashboard.php" class="list-group-item"><i class="fas fa-tachometer-alt"></i><span>Dashboard</span></a>
                <a href="profile.php" class="list-group-item"><i class="fas fa-user"></i><span>My Profile</span></a>
                <a href="payments.php" class="list-group-item"><i class="fas fa-credit-card"></i><span>Payment History</span></a>
                <a href="beneficiary.php" class="list-group-item active"><i class="fas fa-heart"></i><span>Beneficiary</span></a>
                <a href="notifications.php" class="list-group-item"><i class="fas fa-bell"></i><span>Notifications</span></a>
                <a href="organization.php" class="list-group-item"><i class="fas fa-building"></i><span>Organization</span></a>
                <a href="support.php" class="list-group-item"><i class="fas fa-life-ring"></i><span>Support</span></a>
                <a href="../logout.php" class="list-group-item" onclick="return confirm('Are you sure?');"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a>
            </div>
        </div>

        <!-- Main Content -->
        <div id="page-content-wrapper">
            <?php 
            $page_title = 'Beneficiary';
            include '../includes/header.php'; 
            ?>

            <div class="beneficiary-container">
                <?php if ($message): ?>
                    <div class="alert alert-success alert-dismissible fade show mb-3">
                        <i class="fas fa-check-circle me-2"></i><?php echo $message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show mb-3">
                        <i class="fas fa-exclamation-circle me-2"></i><?php echo $error; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <div class="beneficiary-grid">
                    <!-- Left Column -->
                    <div>
                        <!-- Current Beneficiary Card -->
                        <div class="beneficiary-card">
                            <div class="beneficiary-card-header">
                                <h5><i class="fas fa-heart"></i> Current Beneficiary</h5>
                            </div>
                            <div class="beneficiary-card-body">
                                <?php if (empty($member['beneficiary_name'])): ?>
                                    <div class="text-center py-4">
                                        <i class="fas fa-heart-broken fa-4x text-muted mb-3"></i>
                                        <p class="text-muted">No beneficiary designated yet.</p>
                                        <p class="small text-muted">Please add a beneficiary using the form below.</p>
                                    </div>
                                <?php else: ?>
                                    <div class="beneficiary-display">
                                        <div class="beneficiary-avatar">
                                            <i class="fas fa-user-circle"></i>
                                        </div>
                                        <div class="beneficiary-name">
                                            <?php echo htmlspecialchars($member['beneficiary_name']); ?>
                                        </div>
                                        <div class="beneficiary-detail">
                                            <span><i class="fas fa-users"></i> <?php echo htmlspecialchars($member['beneficiary_relation'] ?? 'Not specified'); ?></span>
                                            <?php if (!empty($member['beneficiary_age'])): ?>
                                            <span><i class="fas fa-birthday-cake"></i> Age: <?php echo $member['beneficiary_age']; ?></span>
                                            <?php endif; ?>
                                            <?php if (!empty($member['beneficiary_contact'])): ?>
                                            <span><i class="fas fa-phone"></i> <?php echo htmlspecialchars($member['beneficiary_contact']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <?php if (!empty($member['beneficiary_address'])): ?>
                                        <div class="mt-2">
                                            <i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($member['beneficiary_address']); ?>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <!-- Death Benefit Calculator -->
                        <div class="beneficiary-card">
                            <div class="beneficiary-card-header">
                                <h5><i class="fas fa-calculator"></i> Death Benefit Calculator</h5>
                            </div>
                            <div class="beneficiary-card-body">
                                <div class="benefit-calculator">
                                    <div class="text-center">
                                        <div class="small opacity-75">Estimated Death Benefit</div>
                                        <div class="benefit-amount">₱<?php echo number_format($total_benefit, 2); ?></div>
                                        <div class="small">Based on <?php echo $years_as_member; ?> year(s) of membership</div>
                                    </div>
                                    <div class="benefit-breakdown">
                                        <div class="benefit-breakdown-item">
                                            <span>Base Benefit (<?php echo $years_as_member; ?> years)</span>
                                            <span>₱<?php echo number_format($base_benefit, 2); ?></span>
                                        </div>
                                        <div class="benefit-breakdown-item">
                                            <span>Accidental Death Bonus</span>
                                            <span>₱<?php echo number_format($accident_bonus, 2); ?></span>
                                        </div>
                                        <div class="benefit-breakdown-item">
                                            <span>Good Payer Bonus (5+ years)</span>
                                            <span>₱<?php echo number_format($good_payer_bonus, 2); ?></span>
                                        </div>
                                        <div class="benefit-breakdown-item fw-bold">
                                            <span>Total Estimated Benefit</span>
                                            <span>₱<?php echo number_format($total_benefit, 2); ?></span>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="mt-3">
                                    <small class="text-muted">
                                        <i class="fas fa-info-circle me-1"></i>
                                        <strong>Note:</strong> Benefits may be adjusted based on payment history and other factors. Final benefit amount subject to admin approval.
                                    </small>
                                </div>
                            </div>
                        </div>

                        <!-- Beneficiary Requirements -->
                        <div class="beneficiary-card">
                            <div class="beneficiary-card-header">
                                <h5><i class="fas fa-file-alt"></i> Beneficiary Requirements</h5>
                            </div>
                            <div class="beneficiary-card-body">
                                <ul class="rules-list">
                                    <li><i class="fas fa-check-circle text-success"></i> <strong>Beneficiary must be a legitimate family member</strong> - spouse, child, parent, or legal dependent</li>
                                    <li><i class="fas fa-check-circle text-success"></i> <strong>Age requirement:</strong> 10-60 years old for regular beneficiary</li>
                                    <li><i class="fas fa-check-circle text-success"></i> <strong>Valid government ID</strong> of beneficiary</li>
                                    <li><i class="fas fa-check-circle text-success"></i> <strong>Proof of relationship</strong> (birth certificate, marriage certificate, etc.)</li>
                                    <li><i class="fas fa-check-circle text-success"></i> <strong>Notarized affidavit</strong> (for non-immediate family members)</li>
                                </ul>
                                
                                <div class="alert alert-warning mt-3 small">
                                    <i class="fas fa-exclamation-triangle me-2"></i>
                                    <strong>Important:</strong> Only ONE beneficiary can be designated at a time. Update requests require admin approval and take 3-5 business days to process.
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Right Column -->
                    <div>
                        <!-- Update Beneficiary Form -->
                        <div class="beneficiary-card">
                            <div class="beneficiary-card-header">
                                <h5><i class="fas fa-edit"></i> 
                                    <?php echo empty($member['beneficiary_name']) ? 'Add Beneficiary' : 'Update Beneficiary'; ?>
                                </h5>
                            </div>
                            <div class="beneficiary-card-body">
                                <?php if ($pending_request): ?>
                                    <div class="alert alert-info">
                                        <i class="fas fa-clock me-2"></i>
                                        <strong>Pending Request</strong><br>
                                        You have a pending beneficiary update request submitted on 
                                        <?php echo date('F j, Y', strtotime($pending_request['created_at'])); ?>.
                                        <div class="mt-2">
                                            <span class="status-pending">Pending Approval</span>
                                        </div>
                                        <div class="mt-2 small">
                                            <strong>Requested Beneficiary:</strong> <?php echo htmlspecialchars($pending_request['beneficiary_name']); ?>
                                            (<?php echo htmlspecialchars($pending_request['beneficiary_relation']); ?>)
                                        </div>
                                    </div>
                                <?php endif; ?>
                                
                                <form method="POST" enctype="multipart/form-data" id="beneficiaryForm">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Beneficiary Full Name <span class="text-danger">*</span></label>
                                        <input type="text" class="form-control" name="beneficiary_name" 
                                               value="<?php echo htmlspecialchars($pending_request['beneficiary_name'] ?? $member['beneficiary_name'] ?? ''); ?>"
                                               <?php echo $pending_request ? 'disabled' : ''; ?> required>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Relationship <span class="text-danger">*</span></label>
                                        <select class="form-select" name="beneficiary_relation" <?php echo $pending_request ? 'disabled' : ''; ?> required>
                                            <option value="">Select Relationship</option>
                                            <option value="Spouse" <?php echo (($pending_request['beneficiary_relation'] ?? $member['beneficiary_relation'] ?? '') == 'Spouse') ? 'selected' : ''; ?>>Spouse</option>
                                            <option value="Child" <?php echo (($pending_request['beneficiary_relation'] ?? $member['beneficiary_relation'] ?? '') == 'Child') ? 'selected' : ''; ?>>Child</option>
                                            <option value="Parent" <?php echo (($pending_request['beneficiary_relation'] ?? $member['beneficiary_relation'] ?? '') == 'Parent') ? 'selected' : ''; ?>>Parent</option>
                                            <option value="Sibling" <?php echo (($pending_request['beneficiary_relation'] ?? $member['beneficiary_relation'] ?? '') == 'Sibling') ? 'selected' : ''; ?>>Sibling</option>
                                            <option value="Legal Guardian" <?php echo (($pending_request['beneficiary_relation'] ?? $member['beneficiary_relation'] ?? '') == 'Legal Guardian') ? 'selected' : ''; ?>>Legal Guardian</option>
                                            <option value="Other" <?php echo (($pending_request['beneficiary_relation'] ?? $member['beneficiary_relation'] ?? '') == 'Other') ? 'selected' : ''; ?>>Other</option>
                                        </select>
                                    </div>
                                    
                                    <div class="row">
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Age</label>
                                            <input type="number" class="form-control" name="beneficiary_age" 
                                                   value="<?php echo htmlspecialchars($pending_request['beneficiary_age'] ?? $member['beneficiary_age'] ?? ''); ?>"
                                                   <?php echo $pending_request ? 'disabled' : ''; ?>>
                                        </div>
                                        <div class="col-md-6 mb-3">
                                            <label class="form-label">Contact Number</label>
                                            <input type="text" class="form-control" name="beneficiary_contact" 
                                                   value="<?php echo htmlspecialchars($pending_request['beneficiary_contact'] ?? $member['beneficiary_contact'] ?? ''); ?>"
                                                   <?php echo $pending_request ? 'disabled' : ''; ?>>
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Complete Address <span class="text-danger">*</span></label>
                                        <textarea class="form-control" name="beneficiary_address" rows="2" 
                                                  <?php echo $pending_request ? 'disabled' : ''; ?> required><?php echo htmlspecialchars($pending_request['beneficiary_address'] ?? $member['beneficiary_address'] ?? ''); ?></textarea>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Supporting Documents</label>
                                        <input type="file" class="form-control" name="documents[]" multiple accept=".jpg,.jpeg,.png,.gif,.pdf" <?php echo $pending_request ? 'disabled' : ''; ?>>
                                        <small class="text-muted">
                                            <i class="fas fa-info-circle me-1"></i>
                                            Accepted: JPG, PNG, GIF, PDF (Max 5MB each). Upload birth certificate, marriage certificate, valid IDs, etc.
                                        </small>
                                    </div>
                                    
                                    <?php if (!$pending_request): ?>
                                    <button type="submit" name="update_beneficiary" class="btn-submit">
                                        <i class="fas fa-paper-plane me-2"></i>
                                        <?php echo empty($member['beneficiary_name']) ? 'Submit Beneficiary' : 'Submit Update Request'; ?>
                                    </button>
                                    <?php else: ?>
                                    <div class="alert alert-secondary text-center mb-0">
                                        <i class="fas fa-hourglass-half me-2"></i>
                                        Your request is pending approval. You cannot submit another request until this is resolved.
                                    </div>
                                    <?php endif; ?>
                                </form>
                            </div>
                        </div>

                        <!-- Beneficiary Change History -->
                        <?php if (!empty($beneficiary_history)): ?>
                        <div class="beneficiary-card">
                            <div class="beneficiary-card-header">
                                <h5><i class="fas fa-history"></i> Beneficiary Change History</h5>
                            </div>
                            <div class="beneficiary-card-body">
                                <div class="history-timeline">
                                    <?php foreach ($beneficiary_history as $history): ?>
                                    <div class="history-item">
                                        <div class="history-date">
                                            <i class="fas fa-calendar-alt me-1"></i>
                                            <?php echo date('F j, Y', strtotime($history['changed_at'])); ?>
                                        </div>
                                        <div class="history-text">
                                            <?php if ($history['old_beneficiary_name']): ?>
                                                Changed from <strong><?php echo htmlspecialchars($history['old_beneficiary_name']); ?></strong> 
                                                to <strong><?php echo htmlspecialchars($history['new_beneficiary_name']); ?></strong>
                                                <br><small class="text-muted">(<?php echo htmlspecialchars($history['new_beneficiary_relation']); ?>)</small>
                                            <?php else: ?>
                                                Added beneficiary: <strong><?php echo htmlspecialchars($history['new_beneficiary_name']); ?></strong>
                                                <br><small class="text-muted">(<?php echo htmlspecialchars($history['new_beneficiary_relation']); ?>)</small>
                                            <?php endif; ?>
                                            <div class="small text-muted mt-1">
                                                <i class="fas fa-user-check"></i> Changed by: <?php echo htmlspecialchars($history['changed_by']); ?>
                                            </div>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Organization Rules Card (Full Width) -->
                <div class="beneficiary-card mt-3">
                    <div class="beneficiary-card-header">
                        <h5><i class="fas fa-gavel"></i> NHGL-Philippines Inc. Rules & Regulations (Revised 2022)</h5>
                    </div>
                    <div class="beneficiary-card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h6 class="fw-bold">Membership Requirements:</h6>
                                <ul class="rules-list">
                                    <li><i class="fas fa-user-check"></i> Personal appearance required</li>
                                    <li><i class="fas fa-file-alt"></i> Application form with data and signature</li>
                                    <li><i class="fas fa-stethoscope"></i> Medical certificate (original copy)</li>
                                    <li><i class="fas fa-birthday-cake"></i> Birth certificate copy (45 y/o and above)</li>
                                    <li><i class="fas fa-money-bill"></i> Membership fee: ₱100.00 + ID fee: ₱100.00</li>
                                    <li><i class="fas fa-id-card"></i> ID and S.A.C.E. fee: ₱60.00</li>
                                </ul>
                                
                                <h6 class="fw-bold mt-3">Death Benefit Scale:</h6>
                                <ul class="rules-list">
                                    <li><strong>1-2 years:</strong> ₱80,000.00</li>
                                    <li><strong>3-4 years:</strong> ₱90,000.00</li>
                                    <li><strong>5-6 years:</strong> ₱100,000.00</li>
                                    <li><strong>7-8 years:</strong> ₱110,000.00</li>
                                    <li><strong>9 years:</strong> ₱115,000.00</li>
                                    <li><strong>10 years:</strong> ₱120,000.00</li>
                                    <li><strong>11-16 years:</strong> ₱125,000 - ₱150,000</li>
                                </ul>
                            </div>
                            <div class="col-md-6">
                                <h6 class="fw-bold">Additional Benefits:</h6>
                                <ul class="rules-list">
                                    <li><i class="fas fa-car-crash"></i> <strong>Accidental Death:</strong> +₱10,000.00</li>
                                    <li><i class="fas fa-star"></i> <strong>Good Payer (5 years no missed payments):</strong> +₱5,000.00</li>
                                </ul>
                                
                                <h6 class="fw-bold mt-3">Deductions & Adjustments:</h6>
                                <ul class="rules-list">
                                    <li><strong>1-10 missed payments:</strong> Deduct ₱2,500.00</li>
                                    <li><strong>11-25 missed payments:</strong> Deduct ₱5,000.00</li>
                                    <li><strong>26-50 missed payments:</strong> Deduct ₱10,000.00</li>
                                    <li><strong>51-70 missed payments:</strong> Half of death benefit</li>
                                    <li><strong>71+ missed payments:</strong> Donation based on membership duration</li>
                                </ul>
                                
                                <div class="alert alert-info mt-3">
                                    <i class="fas fa-info-circle me-2"></i>
                                    <strong>Note:</strong> Dual members (members of other organizations) are not eligible for full benefits. Benefits are subject to approval by the organization.
                                </div>
                            </div>
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
        
        // Form validation
        document.getElementById('beneficiaryForm')?.addEventListener('submit', function(e) {
            const name = this.querySelector('[name="beneficiary_name"]').value;
            const relation = this.querySelector('[name="beneficiary_relation"]').value;
            const address = this.querySelector('[name="beneficiary_address"]').value;
            
            if (!name || !relation || !address) {
                e.preventDefault();
                alert('Please fill in all required fields (Name, Relationship, Address)');
            }
        });
    </script>
</body>
</html>