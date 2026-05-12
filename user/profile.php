<?php
// user/profile.php - Complete Redesigned Version
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

// Get pending photo request status
$pending_photo = $db->getSingle(
    "SELECT * FROM member_photo_requests 
     WHERE member_code = ? AND status = 'pending' 
     ORDER BY requested_at DESC LIMIT 1",
    [$member_code], 's'
);

// Get current profile photo
$current_photo = $member['profile_photo'] ?? null;
$has_pending_photo = $pending_photo ? true : false;
$pending_photo_path = $pending_photo ? '../' . $pending_photo['photo_path'] : null;

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    if (!Security::validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token';
    } else {
        $contact_number = Security::sanitize($_POST['contact_number'] ?? '');
        $alternate_number = Security::sanitize($_POST['alternate_number'] ?? '');
        $email = Security::sanitize($_POST['email'] ?? '');
        $present_address = Security::sanitize($_POST['present_address'] ?? '');
        $permanent_address = Security::sanitize($_POST['permanent_address'] ?? '');
        
        // Update using member_code
        $result = $db->execute(
            "UPDATE members SET 
                contact_number = ?,
                alternate_number = ?,
                email = ?,
                present_address = ?,
                permanent_address = ?,
                updated_at = NOW()
             WHERE member_code = ?",
            [$contact_number, $alternate_number, $email, $present_address, $permanent_address, $member_code],
            'ssssss'
        );
        
        if ($result !== false) {
            $message = 'Profile updated successfully!';
            // Refresh member data
            $member = getUserMemberData($db, $current_user);
            
            // Add audit log
            Security::logEvent('PROFILE_UPDATE', "Member $member_code updated contact information");
        } else {
            $error = 'Failed to update profile. Please try again.';
        }
    }
}

// Get city data for display (from config)
$cities_data = [
    'Occidental Mindoro' => ['San Jose', 'Mamburao', 'Sablayan', 'Calintaan', 'Rizal', 'Abra de Ilog', 'Paluan', 'Santa Cruz'],
    'Nueva Ecija' => ['Palayan City', 'Cabanatuan', 'Gapan', 'San Jose', 'Science City of Muñoz', 'Guimba', 'Talavera', 'San Leonardo', 'Santa Rosa', 'General Tinio'],
    'Pampanga' => ['San Fernando', 'Angeles City', 'Mabalacat', 'Mexico', 'Arayat', 'Candaba', 'Lubao', 'Porac', 'Floridablanca', 'Guagua'],
    'Bulacan' => ['Malolos', 'Meycauayan', 'San Jose del Monte', 'Baliuag', 'Marilao', 'Bocaue', 'Santa Maria', 'Pulilan', 'Plaridel', 'Norzagaray'],
    'Tarlac' => ['Tarlac City', 'Concepcion', 'Capas', 'Paniqui', 'Gerona', 'Camiling', 'Moncada', 'Victoria', 'San Jose', 'La Paz']
];

$csrf_token = Security::generateCSRFToken();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Profile - <?php echo APP_NAME; ?></title>
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

        .navbar-left { display: flex; align-items: center; }
        .navbar-brand { font-size: 1.2rem; font-weight: 500; color: #375a7f !important; }
        .navbar-brand i { color: #375a7f; }
        .navbar-right { display: flex; align-items: center; gap: 20px; }

        /* Profile Container */
        .profile-container {
            padding: 20px;
            max-width: 1200px;
            margin: 0 auto;
        }

        /* Logo and Photo Section */
        .profile-header-section {
            background: white;
            border-radius: 12px;
            margin-bottom: 20px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }

        .logo-section {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 20px;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
        }

        .logo-left {
            display: flex;
            align-items: center;
            gap: 20px;
        }

        .logo-left img {
            width: 80px;
            height: 80px;
            object-fit: contain;
        }

        .logo-placeholder {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #375a7f, #2c4a6b);
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
        }

        .logo-left .text-content h2 {
            color: #2c3e50;
            margin-bottom: 5px;
            font-size: 18px;
        }

        .logo-left .text-content h5 {
            color: #34495e;
            margin-bottom: 3px;
            font-size: 13px;
        }

        .logo-left .text-content p {
            margin-bottom: 2px;
            color: #7f8c8d;
            font-size: 10px;
        }

        /* Profile Photo Section */
        .photo-section {
            text-align: center;
            padding: 15px;
            background: white;
            border-radius: 12px;
            min-width: 180px;
        }

        .profile-photo {
            width: 150px;
            height: 150px;
            background: #f0f0f0;
            border: 3px solid #dee2e6;
            border-radius: 12px;
            overflow: hidden;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .profile-photo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .profile-photo-placeholder {
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            color: #adb5bd;
            background: #f8f9fa;
        }

        .photo-upload-btn {
            margin-top: 12px;
            padding: 6px 15px;
            background: #375a7f;
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 0.8rem;
            cursor: pointer;
            transition: all 0.3s;
            width: 100%;
        }

        .photo-upload-btn:hover {
            background: #2c4a6b;
            transform: translateY(-2px);
        }

        .photo-status {
            margin-top: 8px;
            font-size: 0.7rem;
            padding: 4px 8px;
            border-radius: 4px;
        }

        .photo-status.pending {
            background: #fff3cd;
            color: #856404;
        }

        .photo-status.approved {
            background: #d4edda;
            color: #155724;
        }

        /* Form Sections */
        .form-section {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            margin-bottom: 20px;
            overflow: hidden;
        }

        .section-title {
            background: #e9ecef;
            padding: 10px 15px;
            font-weight: bold;
            color: #495057;
            border-left: 4px solid #375a7f;
            margin: 0;
        }

        .section-body {
            padding: 20px;
        }

        .info-row {
            margin-bottom: 15px;
        }

        .info-label {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #6c757d;
            font-weight: 600;
            margin-bottom: 5px;
        }

        .info-value {
            font-size: 0.95rem;
            color: #2c3e50;
            padding: 8px 12px;
            background: #f8f9fa;
            border-radius: 6px;
            word-break: break-word;
        }

        .info-value.editable {
            background: white;
            border: 1px solid #dee2e6;
        }

        .info-value.editable:focus {
            border-color: #375a7f;
            outline: none;
            box-shadow: 0 0 0 2px rgba(55,90,127,0.1);
        }

        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 10px;
            justify-content: flex-end;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #e9ecef;
        }

        .btn-save {
            background: #28a745;
            color: white;
            border: none;
            padding: 10px 25px;
            border-radius: 6px;
            font-weight: 500;
            transition: all 0.3s;
        }

        .btn-save:hover {
            background: #218838;
            transform: translateY(-2px);
        }

        .btn-print {
            background: #6c757d;
            color: white;
            border: none;
            padding: 10px 25px;
            border-radius: 6px;
            font-weight: 500;
            transition: all 0.3s;
        }

        .btn-print:hover {
            background: #5a6268;
            transform: translateY(-2px);
        }

        /* Read-only indicator */
        .readonly-badge {
            display: inline-block;
            font-size: 0.6rem;
            background: #e9ecef;
            color: #6c757d;
            padding: 2px 6px;
            border-radius: 10px;
            margin-left: 8px;
        }

        @media (max-width: 768px) {
            .logo-section {
                flex-direction: column;
                gap: 20px;
            }
            
            .logo-left {
                flex-direction: column;
                text-align: center;
            }
            
            .action-buttons {
                flex-direction: column;
            }
        }
        
        /* Print Styles */
        @media print {
            #sidebar-wrapper, .navbar, .action-buttons, .photo-upload-btn, .menu-toggle {
                display: none !important;
            }
            #page-content-wrapper {
                margin: 0;
                padding: 0;
            }
            .profile-container {
                padding: 0;
            }
            .photo-section {
                page-break-inside: avoid;
            }
            .form-section {
                page-break-inside: avoid;
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
                <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
            </div>
            <div class="list-group list-group-flush mt-2">
                <a href="dashboard.php" class="list-group-item"><i class="fas fa-tachometer-alt"></i><span>Dashboard</span></a>
                <a href="profile.php" class="list-group-item active"><i class="fas fa-user"></i><span>My Profile</span></a>
                <a href="payments.php" class="list-group-item"><i class="fas fa-credit-card"></i><span>Payment History</span></a>
                <a href="beneficiary.php" class="list-group-item"><i class="fas fa-heart"></i><span>Beneficiary</span></a>
                <a href="notifications.php" class="list-group-item"><i class="fas fa-bell"></i><span>Notifications</span></a>
                <a href="organization.php" class="list-group-item"><i class="fas fa-building"></i><span>Organization</span></a>
                <a href="support.php" class="list-group-item"><i class="fas fa-life-ring"></i><span>Support</span></a>
                <a href="../logout.php" class="list-group-item" onclick="return confirm('Are you sure?');"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a>
            </div>
        </div>

        <!-- Main Content -->
        <div id="page-content-wrapper">
            <?php 
            $page_title = 'My Profile';
            include '../includes/header.php'; 
            ?>

            <div class="profile-container">
                <?php if ($message): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="fas fa-check-circle me-2"></i><?php echo $message; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="fas fa-exclamation-circle me-2"></i><?php echo $error; ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>

                <!-- Header with Logo and Profile Photo -->
                <div class="profile-header-section">
                    <div class="logo-section">
                        <div class="logo-left">
                            <img src="../assets/images/harana-logo.png" alt="Harana Logo" onerror="this.style.display='none'; this.parentNode.querySelector('.logo-placeholder').style.display='flex';">
                            <div class="logo-placeholder" style="display: none;">
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
                        
                        <div class="photo-section">
                            <div class="profile-photo" id="profilePhotoContainer">
                                <?php 
                                $photo_to_show = null;
                                if ($has_pending_photo && $pending_photo_path) {
                                    $photo_to_show = $pending_photo_path;
                                } elseif ($current_photo) {
                                    $photo_to_show = '../' . $current_photo;
                                }
                                ?>
                                <?php if ($photo_to_show): ?>
                                    <img src="<?php echo $photo_to_show; ?>" alt="Profile Photo" id="profilePhoto">
                                <?php else: ?>
                                    <div class="profile-photo-placeholder">
                                        <i class="fas fa-user-circle"></i>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <button class="photo-upload-btn" id="uploadPhotoBtn">
                                <i class="fas fa-camera me-1"></i> Change Photo
                            </button>
                            <?php if ($has_pending_photo): ?>
                                <div class="photo-status pending">
                                    <i class="fas fa-clock me-1"></i> Pending Approval
                                </div>
                            <?php endif; ?>
                            <input type="file" id="photoInput" accept="image/*" style="display: none;">
                            <div id="uploadProgress" style="display: none; margin-top: 10px;">
                                <div class="progress" style="height: 5px;">
                                    <div class="progress-bar progress-bar-striped progress-bar-animated" style="width: 0%"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Application Form -->
                <h4 class="form-title text-center mb-3" style="color: #2c3e50;">APPLICATION FOR MEMBERSHIP</h4>

                <form method="POST" id="profileForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="update_profile" value="1">
                    
                    <!-- I. PERSONAL INFORMATION -->
                    <div class="form-section">
                        <div class="section-title">I. PERSONAL INFORMATION</div>
                        <div class="section-body">
                            <div class="row">
                                <div class="col-md-3 mb-3">
                                    <div class="info-label">Member Code</div>
                                    <div class="info-value"><?php echo htmlspecialchars($member['member_code'] ?? 'N/A'); ?></div>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <div class="info-label">Date Joined</div>
                                    <div class="info-value"><?php echo !empty($member['date_joined']) ? date('F j, Y', strtotime($member['date_joined'])) : 'N/A'; ?></div>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <div class="info-label">Monthly Contribution</div>
                                    <div class="info-value">₱<?php echo number_format($member['monthly_contribution'] ?? 100, 2); ?></div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <div class="info-label">Last Name</div>
                                    <div class="info-value"><?php echo htmlspecialchars($member['last_name'] ?? ''); ?></div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <div class="info-label">First Name</div>
                                    <div class="info-value"><?php echo htmlspecialchars($member['first_name'] ?? ''); ?></div>
                                </div>
                                <div class="col-md-4 mb-3">
                                    <div class="info-label">Middle Name</div>
                                    <div class="info-value"><?php echo htmlspecialchars($member['middle_name'] ?? ''); ?></div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-3 mb-3">
                                    <div class="info-label">Date of Birth</div>
                                    <div class="info-value"><?php echo !empty($member['birth_date']) && $member['birth_date'] != '0000-00-00' ? date('F j, Y', strtotime($member['birth_date'])) : 'Not specified'; ?></div>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <div class="info-label">Place of Birth</div>
                                    <div class="info-value"><?php echo htmlspecialchars($member['place_of_birth'] ?? 'Not specified'); ?></div>
                                </div>
                                <div class="col-md-2 mb-3">
                                    <div class="info-label">Age</div>
                                    <div class="info-value"><?php echo $member['age'] ?? 'N/A'; ?></div>
                                </div>
                                <div class="col-md-2 mb-3">
                                    <div class="info-label">Gender</div>
                                    <div class="info-value"><?php echo htmlspecialchars($member['gender'] ?? 'Not specified'); ?></div>
                                </div>
                                <div class="col-md-2 mb-3">
                                    <div class="info-label">Civil Status</div>
                                    <div class="info-value"><?php echo htmlspecialchars($member['civil_status'] ?? 'Not specified'); ?></div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <div class="info-label">Religion</div>
                                    <div class="info-value"><?php echo htmlspecialchars($member['religion'] ?? 'Not specified'); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- II. ADDRESS INFORMATION -->
                    <div class="form-section">
                        <div class="section-title">II. ADDRESS INFORMATION</div>
                        <div class="section-body">
                            <div class="row">
                                <div class="col-md-3 mb-3">
                                    <div class="info-label">Province</div>
                                    <div class="info-value"><?php echo htmlspecialchars($member['province'] ?? 'Not specified'); ?></div>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <div class="info-label">City/Municipality</div>
                                    <div class="info-value"><?php echo htmlspecialchars($member['city'] ?? 'Not specified'); ?></div>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <div class="info-label">Barangay</div>
                                    <div class="info-value"><?php echo htmlspecialchars($member['barangay'] ?? 'Not specified'); ?></div>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <div class="info-label">Street/Purok/Sitio</div>
                                    <div class="info-value"><?php echo htmlspecialchars($member['street'] ?? 'Not specified'); ?></div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <div class="info-label">Present Address</div>
                                    <input type="text" name="present_address" class="info-value editable w-100" value="<?php echo htmlspecialchars($member['present_address'] ?? ''); ?>">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <div class="info-label">Permanent Address</div>
                                    <input type="text" name="permanent_address" class="info-value editable w-100" value="<?php echo htmlspecialchars($member['permanent_address'] ?? ''); ?>">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- III. CONTACT INFORMATION -->
                    <div class="form-section">
                        <div class="section-title">III. CONTACT INFORMATION</div>
                        <div class="section-body">
                            <div class="row">
                                <div class="col-md-4 mb-3">
                                    <div class="info-label">Contact Number</div>
                                    <input type="text" name="contact_number" class="info-value editable w-100" value="<?php echo htmlspecialchars($member['contact_number'] ?? ''); ?>">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <div class="info-label">Alternate Number</div>
                                    <input type="text" name="alternate_number" class="info-value editable w-100" value="<?php echo htmlspecialchars($member['alternate_number'] ?? ''); ?>">
                                </div>
                                <div class="col-md-4 mb-3">
                                    <div class="info-label">Email Address</div>
                                    <input type="email" name="email" class="info-value editable w-100" value="<?php echo htmlspecialchars($member['email'] ?? ''); ?>">
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- IV. FAMILY BACKGROUND -->
                    <div class="form-section">
                        <div class="section-title">IV. FAMILY BACKGROUND</div>
                        <div class="section-body">
                            <div class="row mb-3">
                                <div class="col-md-12"><div class="info-label">Father's Full Name:</div></div>
                                <div class="col-md-4">
                                    <div class="info-value"><?php echo htmlspecialchars($member['father_fname'] ?? ''); ?></div>
                                </div>
                                <div class="col-md-4">
                                    <div class="info-value"><?php echo htmlspecialchars($member['father_mname'] ?? ''); ?></div>
                                </div>
                                <div class="col-md-4">
                                    <div class="info-value"><?php echo htmlspecialchars($member['father_lname'] ?? ''); ?></div>
                                </div>
                            </div>
                            <div class="row mb-3">
                                <div class="col-md-12"><div class="info-label">Mother's Full Name (Maiden Name):</div></div>
                                <div class="col-md-4">
                                    <div class="info-value"><?php echo htmlspecialchars($member['mother_fname'] ?? ''); ?></div>
                                </div>
                                <div class="col-md-4">
                                    <div class="info-value"><?php echo htmlspecialchars($member['mother_mname'] ?? ''); ?></div>
                                </div>
                                <div class="col-md-4">
                                    <div class="info-value"><?php echo htmlspecialchars($member['mother_lname'] ?? ''); ?></div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-12"><div class="info-label">Spouse's Full Name (If Married):</div></div>
                                <div class="col-md-3">
                                    <div class="info-value"><?php echo htmlspecialchars($member['spouse_fname'] ?? ''); ?></div>
                                </div>
                                <div class="col-md-3">
                                    <div class="info-value"><?php echo htmlspecialchars($member['spouse_mname'] ?? ''); ?></div>
                                </div>
                                <div class="col-md-3">
                                    <div class="info-value"><?php echo htmlspecialchars($member['spouse_lname'] ?? ''); ?></div>
                                </div>
                                <div class="col-md-3">
                                    <div class="info-value"><?php echo $member['spouse_age'] ?? ''; ?></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- V. CHILDREN -->
                    <div class="form-section">
                        <div class="section-title">V. CHILDREN</div>
                        <div class="section-body">
                            <?php for ($i = 1; $i <= 4; $i++): ?>
                            <div class="row mb-2">
                                <div class="col-md-1"><div class="info-label"><?php echo $i; ?>.</div></div>
                                <div class="col-md-3"><div class="info-value"><?php echo htmlspecialchars($member["child{$i}_fname"] ?? ''); ?></div></div>
                                <div class="col-md-3"><div class="info-value"><?php echo htmlspecialchars($member["child{$i}_mname"] ?? ''); ?></div></div>
                                <div class="col-md-3"><div class="info-value"><?php echo htmlspecialchars($member["child{$i}_lname"] ?? ''); ?></div></div>
                                <div class="col-md-2"><div class="info-value"><?php echo $member["child{$i}_age"] ?? ''; ?></div></div>
                            </div>
                            <?php endfor; ?>
                        </div>
                    </div>

                    <!-- VI. CHARACTER REFERENCES -->
                    <div class="form-section">
                        <div class="section-title">VI. CHARACTER REFERENCES</div>
                        <div class="section-body">
                            <div class="row mb-2">
                                <div class="col-md-5">
                                    <div class="info-label">Name of Reference 1</div>
                                    <div class="info-value"><?php echo htmlspecialchars($member['ref1_name'] ?? ''); ?></div>
                                </div>
                                <div class="col-md-7">
                                    <div class="info-label">Contact Number</div>
                                    <div class="info-value"><?php echo htmlspecialchars($member['ref1_contact'] ?? ''); ?></div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-5">
                                    <div class="info-label">Name of Reference 2</div>
                                    <div class="info-value"><?php echo htmlspecialchars($member['ref2_name'] ?? ''); ?></div>
                                </div>
                                <div class="col-md-7">
                                    <div class="info-label">Contact Number</div>
                                    <div class="info-value"><?php echo htmlspecialchars($member['ref2_contact'] ?? ''); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- VII. BENEFICIARY INFORMATION -->
                    <div class="form-section">
                        <div class="section-title">VII. BENEFICIARY INFORMATION</div>
                        <div class="section-body">
                            <div class="row mb-2">
                                <div class="col-md-4">
                                    <div class="info-label">Full Name of Beneficiary</div>
                                    <div class="info-value"><?php echo htmlspecialchars($member['beneficiary_name'] ?? ''); ?></div>
                                </div>
                                <div class="col-md-4">
                                    <div class="info-label">Complete Address</div>
                                    <div class="info-value"><?php echo htmlspecialchars($member['beneficiary_address'] ?? ''); ?></div>
                                </div>
                                <div class="col-md-2">
                                    <div class="info-label">Relationship</div>
                                    <div class="info-value"><?php echo htmlspecialchars($member['beneficiary_relation'] ?? ''); ?></div>
                                </div>
                                <div class="col-md-2">
                                    <div class="info-label">Age</div>
                                    <div class="info-value"><?php echo $member['beneficiary_age'] ?? ''; ?></div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="info-label">Contact Number of Beneficiary</div>
                                    <div class="info-value"><?php echo htmlspecialchars($member['beneficiary_contact'] ?? ''); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- VIII. CHAPTER INFORMATION -->
                    <div class="form-section">
                        <div class="section-title">VIII. CHAPTER INFORMATION</div>
                        <div class="section-body">
                            <div class="row mb-2">
                                <div class="col-md-3">
                                    <div class="info-label">Chapter</div>
                                    <div class="info-value"><?php echo htmlspecialchars($member['chapter'] ?? 'Not assigned'); ?></div>
                                </div>
                                <div class="col-md-3">
                                    <div class="info-label">Group Name</div>
                                    <div class="info-value"><?php echo htmlspecialchars($member['group_name'] ?? 'Not assigned'); ?></div>
                                </div>
                                <div class="col-md-3">
                                    <div class="info-label">Leader</div>
                                    <div class="info-value"><?php echo htmlspecialchars($member['leader'] ?? 'Not assigned'); ?></div>
                                </div>
                                <div class="col-md-3">
                                    <div class="info-label">Coordinator</div>
                                    <div class="info-value"><?php echo htmlspecialchars($member['coordinator'] ?? 'Not assigned'); ?></div>
                                </div>
                            </div>
                            <div class="row mb-2">
                                <div class="col-md-3">
                                    <div class="info-label">Chairman</div>
                                    <div class="info-value"><?php echo htmlspecialchars($member['chairman'] ?? 'Not assigned'); ?></div>
                                </div>
                                <div class="col-md-3">
                                    <div class="info-label">Screening Officer</div>
                                    <div class="info-value"><?php echo htmlspecialchars($member['screening_officer'] ?? 'Not assigned'); ?></div>
                                </div>
                                <div class="col-md-3">
                                    <div class="info-label">Screening Date</div>
                                    <div class="info-value"><?php echo !empty($member['screening_date']) ? date('F j, Y', strtotime($member['screening_date'])) : 'Not set'; ?></div>
                                </div>
                                <div class="col-md-3">
                                    <div class="info-label">Approved By</div>
                                    <div class="info-value"><?php echo htmlspecialchars($member['approved_by'] ?? 'Not assigned'); ?></div>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-3">
                                    <div class="info-label">Date Registered</div>
                                    <div class="info-value"><?php echo !empty($member['date_registered']) ? date('F j, Y', strtotime($member['date_registered'])) : 'Not set'; ?></div>
                                </div>
                                <div class="col-md-3">
                                    <div class="info-label">Status</div>
                                    <div class="info-value"><span class="badge bg-<?php echo ($member['status'] ?? 'active') == 'active' ? 'success' : 'warning'; ?>"><?php echo ucfirst($member['status'] ?? 'active'); ?></span></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- IX. ACCOUNT INFORMATION -->
                    <div class="form-section">
                        <div class="section-title">IX. ACCOUNT INFORMATION</div>
                        <div class="section-body">
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="info-label">Username</div>
                                    <div class="info-value"><?php echo htmlspecialchars($member['username'] ?? 'Not set'); ?></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="action-buttons">
                        <button type="submit" class="btn-save">
                            <i class="fas fa-save me-2"></i> Save Changes
                        </button>
                        <button type="button" class="btn-print" id="printBtn">
                            <i class="fas fa-print me-2"></i> Print / Export PDF
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        // Sidebar Toggle
        const menuToggle = document.getElementById('menuToggle');
        const sidebar = document.getElementById('sidebar-wrapper');
        const headerLogo = document.getElementById('headerLogo');
        
        const sidebarCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
        if (sidebarCollapsed) sidebar.classList.add('collapsed');
        
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

        // Photo Upload Functionality
        const uploadBtn = document.getElementById('uploadPhotoBtn');
        const photoInput = document.getElementById('photoInput');
        const profilePhotoContainer = document.getElementById('profilePhotoContainer');
        const uploadProgress = document.getElementById('uploadProgress');
        
        uploadBtn.addEventListener('click', function() {
            photoInput.click();
        });
        
        photoInput.addEventListener('change', function() {
            const file = this.files[0];
            if (!file) return;
            
            // Validate file type
            const validTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            if (!validTypes.includes(file.type)) {
                alert('Invalid file type. Please select a JPG, PNG, GIF, or WEBP image.');
                return;
            }
            
            // Validate file size (5MB)
            if (file.size > 5 * 1024 * 1024) {
                alert('File size must be less than 5MB.');
                return;
            }
            
            // Show preview
            const reader = new FileReader();
            reader.onload = function(e) {
                profilePhotoContainer.innerHTML = `<img src="${e.target.result}" alt="Profile Photo" id="profilePhoto">`;
            };
            reader.readAsDataURL(file);
            
            // Upload to server
            const formData = new FormData();
            formData.append('csrf_token', '<?php echo $csrf_token; ?>');
            formData.append('photo', file);
            
            uploadProgress.style.display = 'block';
            let progress = 0;
            const interval = setInterval(function() {
                progress += 10;
                uploadProgress.querySelector('.progress-bar').style.width = progress + '%';
                if (progress >= 100) clearInterval(interval);
            }, 100);
            
            $.ajax({
                url: 'upload_member_photo.php',
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    clearInterval(interval);
                    uploadProgress.querySelector('.progress-bar').style.width = '100%';
                    
                    setTimeout(function() {
                        uploadProgress.style.display = 'none';
                        
                        if (response.success) {
                            // Show success message
                            const alertHtml = `<div class="alert alert-info alert-dismissible fade show">${response.message}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>`;
                            document.querySelector('.profile-container').insertAdjacentHTML('afterbegin', alertHtml);
                            
                            // Add pending status
                            if (!document.querySelector('.photo-status')) {
                                const statusHtml = `<div class="photo-status pending mt-2"><i class="fas fa-clock me-1"></i> Pending Approval</div>`;
                                document.querySelector('.photo-section').insertAdjacentHTML('beforeend', statusHtml);
                            }
                            
                            // Reload page after 2 seconds to show pending status
                            setTimeout(function() {
                                location.reload();
                            }, 2000);
                        } else {
                            alert(response.message);
                            // Reload original photo
                            location.reload();
                        }
                    }, 500);
                },
                error: function() {
                    clearInterval(interval);
                    uploadProgress.style.display = 'none';
                    alert('An error occurred while uploading the photo. Please try again.');
                    location.reload();
                }
            });
        });
        
        // Print / Export PDF Function
        document.getElementById('printBtn').addEventListener('click', function() {
            window.print();
        });
        
        // Auto-dismiss alerts after 5 seconds
        setTimeout(function() {
            document.querySelectorAll('.alert').forEach(function(alert) {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            });
        }, 5000);
    </script>
</body>
</html>